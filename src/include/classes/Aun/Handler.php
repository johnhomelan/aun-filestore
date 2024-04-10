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
*/
class Handler Implements HandleInterface {

	private array $aQueue = [];
	private array $aLastChance = [];
	private Socket $oAunServer;


	/**
	 * Constructor registers the Logger
	 *  
	*/
	public function __construct(private readonly \Psr\Log\LoggerInterface $oLogger,private readonly ServiceDispatcher $oServices,private readonly PacketDispatcher $oPacketDispatcher)
	{		
	}

	public function setSocket(Socket $oAunServer)
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
		$oAunPacket->setDestinationIP(config::getValue('local_ip'));
		$oAunPacket->decode($sMessage);
		switch($oAunPacket->getPacketType()){
			case 'Ack':
				//Got an Ack use 
				$this->oLogger->debug("Aun Handler: Ack");
				$this->_unQueue($oAunPacket);
				break;
			default:
				//Send an ack for the AUN packet if needed
				$sAck = $oAunPacket->buildAck();
				if(strlen($sAck)>0){
					$this->oLogger->debug("Aun Handler: ".$oAunPacket->getPacketType()." Sending Ack packet");
					$this->oAunServer->send($sAck,$sSrcAddress);
				}
				break;
		}
		//Dispatch packet to all the services so the relivent one can deal with it 
		$this->oServices->inboundPacket($oAunPacket);
		
		//Send any messages for the services
		$aReplies = $this->oServices->getReplies();
		foreach($aReplies as $oReply){
			//In theroy there can be packets queued for other abstractions (e.g. created via a timer that triggered) 
			$this->oPacketDispatcher->sendPacket($oReply);
		}
	}

	public function timer():void
	{
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

	private function _runQueue()
	{
		foreach($this->aQueue as $sHost=>$aHostQueue){
			$this->_runHostQueue($sHost);
		}
	}

	private function _runHostQueue(string $sHost):void
	{	
		if(is_array($this->aQueue[$sHost]) AND count($this->aQueue[$sHost])>0){
			var_dump($this->aQueue);
			$aQueueEntry = array_shift($this->aQueue[$sHost]);
			if($aQueueEntry['backoff']>1){
				//Each attempt increase the time between attempts
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
	private function _unQueue(AunPacket $oAck):void
	{
		$sHost = $oAck->getSourceIP().":".$oAck->getSourceUdpPort();
		$this->_unHostQueue($sHost, $oAck);
	}

	private function _unHostQueue(string $sHost, AunPacket $oAck):void
	{
		$this->oLogger->debug("Aun Handler: Dequeuing packet due to scout ack");
		if(is_array($this->aQueue[$sHost]) AND count($this->aQueue[$sHost])>0){
			$aQueueEntry = array_shift($this->aQueue[$sHost]);
			if($oAck->getSequence() == $aQueueEntry['packet']->getSequence()){
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
				$this->oServices->clearAckEvent($oPacket->getSourceNetwork(),$oPacket->getSourceStation());
				$this->oLogger->debug("Aun Handler: Cleared ack event for ".$oPacket->getSourceNetwork().".".$oPacket->getSourceStation());
			}
			//Clear the waiting final ack
			unset($this->aLastChance[$sHost]);
		}
	}

	private function _writeOutPkt(EconetPacket $oPacket)
	{
		$this->oLogger->debug("Aun Handler: Transmitting packet");
		$sHost = $this->_getIpPortFromNetworkStation($oPacket->getDestinationNetwork(),$oPacket->getDestinationStation());
		$sAunFrame = $oPacket->getAunFrame();
		if(strlen($sAunFrame)>0){
			$this->oAunServer->send($sAunFrame,$sHost);
		}
	}

	/**
	 * Get the ip:port combination for a given network and station 
	*/ 	
	private function _getIpPortFromNetworkStation(int $iNetwork, int $iStation):string
	{
		$sIP = Map::ecoAddrToIpAddr($iNetwork,$iStation);
		if(!str_contains($sIP,':')){
			$sHost=$sIP.':'.config::getValue('aun_default_port');
		}else{
			$sHost=$sIP;
		}
		return $sHost;
	}

}
