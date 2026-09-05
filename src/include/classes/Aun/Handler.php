<?php
/**
 * This file contains the aun handler class
 *
 * @author John Brown <john@home-lan.co.uk>
 * @package corenet
*/
namespace HomeLan\FileStore\Aun;

use HomeLan\FileStore\Messages\EconetPacket;
use HomeLan\FileStore\Services\ProviderInterface;
use HomeLan\FileStore\Services\ServiceDispatcher;
use HomeLan\FileStore\Encapsulation\PacketDispatcher;
use HomeLan\FileStore\Aun\Map;
use React\Datagram\Socket;

use HomeLan\FileStore\Aun\AunPacket;
use config;


/**
 * This class handles all AUN packets recieved and dispatched by the system
 * @package corenet
 *
 * @phpstan-type AunQueueEntry array{packet:EconetPacket,retries:int,attempts:int,backoff:int}
*/
class Handler Implements HandleInterface {

	/**
 	 * @var array<string, array<int, AunQueueEntry>>
 	*/
	private array $aQueue = [];

	/**
 	 * @var array<string, int>
 	*/
	private array $aLastChance = [];

	/**
	 * Sliding-window dedup: last SEQ_WINDOW_SIZE sequence numbers per source address.
	 * Each entry: ['seqs' => int[], 'last_seen' => int (unix timestamp)]
	 * @var array<string, array{seqs: int[], last_seen: int}>
	 */
	private array $aSeqWindows = [];

	private const int SEQ_WINDOW_SIZE = 8;
	private const int SEQ_PRUNE_TTL   = 300; // seconds before an idle source entry is evicted

	private Socket $oAunServer;


	/**
	 * Constructor registers the Logger
	 *
	*/
	public function __construct(private readonly \Psr\Log\LoggerInterface $oLogger,private readonly ServiceDispatcher $oServices,private readonly PacketDispatcher $oPacketDispatcher)
	{
	}

	public function setSocket(Socket $oAunServer):void
	{
		$this->oAunServer = $oAunServer;
	}

	public function onClose():void
	{
		$this->oLogger->debug("Aun handler: Closing");
	}

	public function receive(string $sMessage, string $sSrcAddress, string $sDstAddress):void
	{
		$this->oLogger->debug("Aun Handler: Received packet from ".$sSrcAddress);
		$oAunPacket = new AunPacket();

		$oAunPacket->setSourceIP($sSrcAddress);
		$oAunPacket->setDestinationIP(config::getValueAsString('local_ip'));

		try {
			$oAunPacket->decode($sMessage);
		} catch (\Exception $oEx) {
			$this->oLogger->warning("Aun Handler: Discarding malformed packet from {$sSrcAddress}: " . $oEx->getMessage());
			return;
		}

		if ($oAunPacket->getPacketType() === 'Unknown') {
			$this->oLogger->warning("Aun Handler: Discarding packet with unknown type from {$sSrcAddress}");
			return;
		}

		switch($oAunPacket->getPacketType()){
			case 'Ack':
				//Got an Ack use
				$this->oLogger->debug("Aun Handler: Ack");
				if ($this->_unQueue($oAunPacket)) {
					//This was the ack a service was actually waiting on (matched
					//the head of this host's retry queue, or the pending "last
					//chance" sequence) — dispatch it so ServiceDispatcher::ackEvents()
					//can fire any addAckEvent() callback registered for it (e.g.
					//FileServer's block-by-block load/save continuation). A stray
					//or unmatched ack (_unQueue() returning false) is intentionally
					//not dispatched.
					$this->oServices->inboundPacket($oAunPacket);
					$aReplies = $this->oServices->getReplies();
					foreach($aReplies as $oReply){
						$this->oPacketDispatcher->sendPacket($oReply);
					}
				}
				break;
			default:
				// Drop duplicate packets (retransmissions where our ACK was lost in transit)
				$iSeq = $oAunPacket->getSequence();
				if ($this->isDuplicate($sSrcAddress, $iSeq)) {
					$this->oLogger->debug("Aun Handler: Duplicate packet from ".$sSrcAddress." seq ".$iSeq.", re-ACKing and dropping");
					$sAck = $oAunPacket->buildAck();
					if(!is_null($sAck) && strlen($sAck)>0){
						$this->oAunServer->send($sAck,$sSrcAddress);
					}
					return;
				}
				$this->recordSeq($sSrcAddress, $iSeq);

				//Send an ack for the AUN packet if needed
				$sAck = $oAunPacket->buildAck();
				if(!is_null($sAck) && strlen($sAck)>0){
					$this->oLogger->debug("Aun Handler: ".$oAunPacket->getPacketType()." Sending Ack packet");
					$this->oAunServer->send($sAck,$sSrcAddress);
				} elseif ($oAunPacket->getPacketType() === 'Immediate') {
					$iCb = $oAunPacket->getCb();
					$this->oLogger->debug("Aun Handler: Immediate packet with unhandled cb=0x" . dechex($iCb) . " from {$sSrcAddress}, no reply sent");
				}

				//Dispatch packet to all the services so the relevant one can deal with it
				$this->oServices->inboundPacket($oAunPacket);

				//Send any messages from the services
				$aReplies = $this->oServices->getReplies();
				foreach($aReplies as $oReply){
					//In theory there can be packets queued for other abstractions (e.g. created via a timer that triggered)
					$this->oPacketDispatcher->sendPacket($oReply);
				}
				break;
		}
	}

	public function timer():void
	{
		$this->pruneSeqWindows();
		$this->_runQueue();
	}

	public function send(EconetPacket $oPacket, int $iRetries = 3):void
	{
		$this->oLogger->debug("Aun Handler: Sending packet to queue");
		$sHost = $this->_getIpPortFromNetworkStation($oPacket->getDestinationNetwork(),$oPacket->getDestinationStation());
		if(!array_key_exists($sHost,$this->aQueue)){
			$this->aQueue[$sHost] = [];
		}

		$this->aQueue[$sHost][] = ['packet'=>$oPacket,'retries'=>$iRetries,'attempts'=>0,'backoff'=>0];
	}

	private function _runQueue():void
	{
		foreach($this->aQueue as $sHost=>$aHostQueue){
			$this->_runHostQueue($sHost);
		}
	}

	private function _runHostQueue(string $sHost):void
	{
		if(count($this->aQueue[$sHost])>0){
			$aQueueEntry = array_shift($this->aQueue[$sHost]);
			if($aQueueEntry['backoff']>1){
				//Linear backoff: wait (attempts × 400) timer ticks before the next retry
				$aQueueEntry['backoff']=$aQueueEntry['backoff']-400;
				array_unshift($this->aQueue[$sHost],$aQueueEntry);
				return;
			}
			if($aQueueEntry['retries']>0){
				//More re-tires left re-queue
				$aQueueEntry['retries'] = $aQueueEntry['retries']-1;
				$aQueueEntry['attempts'] = $aQueueEntry['attempts']+1;
				$aQueueEntry['backoff']=$aQueueEntry['attempts']*400;
				array_unshift($this->aQueue[$sHost],$aQueueEntry);
				$this->oLogger->debug("Aun Handler: ".$aQueueEntry['retries']." retires left, ".$aQueueEntry['attempts']." attempts made.");
			}else{
				//No more tries left we need to set up if the next ack does not match the sequence clear any service events waiting on the ack that will never come
				$sHost = $this->_getIpPortFromNetworkStation($aQueueEntry['packet']->getDestinationNetwork(),$aQueueEntry['packet']->getDestinationStation());
				$this->aLastChance[$sHost]=$aQueueEntry['packet']->getSequence();
			}
			$this->_writeOutPkt($aQueueEntry['packet']);
		}else{
			//$this->oLogger->debug("Aun Handler: No packets in Queue");
		}
	}
	/**
	 * @return bool True if $oAck was the ack a service is actually waiting
	 *              on — either it matched the head of this host's retry
	 *              queue, or it matched the pending "last chance" sequence.
	 *              False for a stray/unmatched ack, or one for a host with
	 *              nothing tracked at all.
	*/
	private function _unQueue(AunPacket $oAck):bool
	{
		$sHost = $oAck->getSourceIP().":".$oAck->getSourceUdpPort();
		return $this->_unHostQueue($sHost, $oAck);
	}

	private function _unHostQueue(string $sHost, AunPacket $oAck):bool
	{
		$this->oLogger->debug("Aun Handler: Dequeuing packet due to scout ack");
		$bExpected = false;
		if(array_key_exists($sHost,$this->aQueue) AND count($this->aQueue[$sHost])>0){
			$aQueueEntry = array_shift($this->aQueue[$sHost]);
			if($oAck->getSequence() == $aQueueEntry['packet']->getSequence()){
				$bExpected = true;
				//If the packet is nolonger in the queue (because the packet at the head of the queue has had no
				//atempts to ack, but it back at the head of the queue, and run the queue
				if($aQueueEntry['attempts']==0){
					array_unshift($this->aQueue[$sHost],$aQueueEntry);
				}
				$this->_runQueue();
			}else{
				//The head of the qeueue does not match the sequence number, so its not been ack'd so put it back in the queue
				array_unshift($this->aQueue[$sHost],$aQueueEntry);
			}
		}
		if(array_key_exists($sHost,$this->aLastChance)){
			if($oAck->getSequence()!=$this->aLastChance[$sHost]){
				//The last attempt for a packet happened and it was never acked and the this ack
				//is for a different frame, clear any ack service events waiting for this host
				//as this is the wrong ack.
				$oPacket = $oAck->buildEconetPacket();
				$iSourceNetwork = $oPacket->getSourceNetwork();
				$iSourceStation = $oPacket->getSourceStation();
				if ($iSourceNetwork !== null && $iSourceStation !== null) {
					$this->oServices->clearAckEvent($iSourceNetwork,$iSourceStation);
					$this->oLogger->debug("Aun Handler: Cleared ack event for ".$iSourceNetwork.".".$iSourceStation);
				}
			}else{
				//This was the final-retry ack a service was waiting on.
				$bExpected = true;
			}
			//Clear the waiting final ack
			unset($this->aLastChance[$sHost]);
		}
		return $bExpected;
	}

	private function _writeOutPkt(EconetPacket $oPacket):void
	{
		$this->oLogger->debug("Aun Handler: Transmitting packet");
		$sHost = $this->_getIpPortFromNetworkStation($oPacket->getDestinationNetwork(),$oPacket->getDestinationStation());
		$sAunFrame = $oPacket->getAunFrame();
		if(strlen($sAunFrame)>0){
			$this->oAunServer->send($sAunFrame,$sHost);
		}else{
			$this->oLogger->warning("Aun Handler: Dropping packet to ".$oPacket->getDestinationNetwork().".".$oPacket->getDestinationStation()." — no IP mapping found");
		}
	}

	/**
	 * Get the ip:port combination for a given network and station
	*/
	private function _getIpPortFromNetworkStation(int $iNetwork, int $iStation):string
	{
		$sIP = Map::ecoAddrToIpAddr($iNetwork,$iStation);
		if(!str_contains($sIP,':')){
			$sHost=$sIP.':'.config::getValueAsString('aun_default_port');
		}else{
			$sHost=$sIP;
		}
		return $sHost;
	}

	/**
	 * Returns true if $iSeq has been seen recently from $sSrcAddress (i.e. it is in the sliding window).
	 */
	private function isDuplicate(string $sSrcAddress, int $iSeq): bool
	{
		if (!isset($this->aSeqWindows[$sSrcAddress])) {
			return false;
		}
		return in_array($iSeq, $this->aSeqWindows[$sSrcAddress]['seqs'], true);
	}

	/**
	 * Adds $iSeq to the sliding window for $sSrcAddress, evicting the oldest entry when full.
	 */
	private function recordSeq(string $sSrcAddress, int $iSeq): void
	{
		if (!isset($this->aSeqWindows[$sSrcAddress])) {
			$this->aSeqWindows[$sSrcAddress] = ['seqs' => [], 'last_seen' => time()];
		}
		$aWindow = &$this->aSeqWindows[$sSrcAddress];
		$aWindow['seqs'][] = $iSeq;
		if (count($aWindow['seqs']) > self::SEQ_WINDOW_SIZE) {
			array_shift($aWindow['seqs']);
		}
		$aWindow['last_seen'] = time();
	}

	/**
	 * Removes window entries for sources not seen within SEQ_PRUNE_TTL seconds.
	 */
	private function pruneSeqWindows(): void
	{
		$iCutoff = time() - self::SEQ_PRUNE_TTL;
		foreach (array_keys($this->aSeqWindows) as $sKey) {
			if ($this->aSeqWindows[$sKey]['last_seen'] < $iCutoff) {
				unset($this->aSeqWindows[$sKey]);
			}
		}
	}

}
