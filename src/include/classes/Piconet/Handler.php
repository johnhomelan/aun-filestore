<?php

/**
 * This file contains the WebSocketHandler class
 *
 *
 * @author John Brown <john@home-lan.co.uk>
 * @package core
*/
namespace HomeLan\FileStore\Piconet;

use React\Socket\ConnectionInterface;
use React\EventLoop\LoopInterface;

use HomeLan\FileStore\Messages\EconetPacket;
use HomeLan\FileStore\Services\ProviderInterface;
use HomeLan\FileStore\Services\ServiceDispatcher;
use HomeLan\FileStore\Encapsulation\PacketDispatcher;

use HomeLan\FileStore\Piconet\PiconetPacket;
use config;

/**
 * This class deals with taking to the piconet device
 *
 * @package core
*/
class Handler {

	const RECONNECT_DELAY_MIN = 5;
	const RECONNECT_DELAY_MAX = 300;

	private ?ConnectionInterface $oConnection = null;

	private bool $bConnected = false;

	private ?LoopInterface $oLoop = null;

	private ?\Closure $fReconnect = null;

	private int $iReconnectDelay = self::RECONNECT_DELAY_MIN;

	private array $aQueue = [];

	private array $aAwaitingAck = [];

	public function __construct(private readonly \Psr\Log\LoggerInterface $oLogger,  private readonly ServiceDispatcher $oServices, private readonly PacketDispatcher $oPacketDispatcher)
	{
		$this->oLogger->debug("Starting piconet handler");
	}

	public function setLoop(LoopInterface $oLoop): void
	{
		$this->oLoop = $oLoop;
	}

	public function setReconnectCallback(\Closure $fReconnect): void
	{
		$this->fReconnect = $fReconnect;
	}

	public function onOpen(ConnectionInterface $oConnection):void
	{
		$this->oConnection = $oConnection;
		$this->bConnected = true;
		$this->iReconnectDelay = self::RECONNECT_DELAY_MIN;
	}

	public function scheduleReconnect(): void
	{
		if ($this->oLoop === null || $this->fReconnect === null) {
			return;
		}
		$this->oLogger->info("Piconet: will attempt to reconnect in ".$this->iReconnectDelay." seconds");
		$this->oLoop->addTimer($this->iReconnectDelay, $this->fReconnect);
		$this->iReconnectDelay = min($this->iReconnectDelay * 2, self::RECONNECT_DELAY_MAX);
	}

	public function onConnect(){
		$this->oLogger->debug("Piconet handler: Connected");
		//stream_set_blocking($this->oConnection->stream,true);
		stream_set_write_buffer($this->oConnection->stream, 0); //Turn off the write buffer  @phpstan-ignore-line

		fwrite($this->oConnection->stream,"STATUS\r\r"); //@phpstan-ignore-line
		fflush($this->oConnection->stream); //@phpstan-ignore-line

		$this->bringupInterface();
	}

	public function bringupInterface()
	{
		$this->oLogger->debug("Piconet handler: Bringing up the interface");
		$this->oLogger->debug("Piconet handler: Set station to ".config::getValue('piconet_station'));
		fwrite($this->oConnection->stream,"SET_STATION ".config::getValue('piconet_station')."\r\r"); //@phpstan-ignore-line
		fflush($this->oConnection->stream); //@phpstan-ignore-line

		if (config::getValue('remote_bridge_enabled')) {
			$this->oLogger->debug("Piconet handler: Set to monitor mode (remote bridge enabled)");
			fwrite($this->oConnection->stream,"SET_MODE MONITOR\r\r"); //@phpstan-ignore-line
		} else {
			$this->oLogger->debug("Piconet handler: Set to listen mode");
			fwrite($this->oConnection->stream,"SET_MODE LISTEN\r\r"); //@phpstan-ignore-line
		}
		fflush($this->oConnection->stream); //@phpstan-ignore-line

		$this->oLogger->debug("Piconet handler: Interface setup and ready");
	}


	public function onClose():void
	{
		$this->oLogger->error("Piconet: device disconnected");
		$this->bConnected = false;

		// Tell the device to stop accepting Econet frames before we lose the connection.
		// write() is a no-op on an already-closed connection so this is safe in both
		// the normal-shutdown and unexpected-disconnect cases.
		if ($this->oConnection !== null) {
			$this->oConnection->write("SET_MODE STOP\r\r");
		}
		$this->oConnection = null;

		// Unblock any services waiting for an ack from a packet already in flight
		foreach ($this->aAwaitingAck as $aAck) {
			$this->oServices->clearAckEvent($aAck['dst_network'], $aAck['dst_station']);
		}
		// Unblock any services waiting for an ack from packets still queued
		foreach ($this->aQueue as $aEntry) {
			$oPacket = $aEntry['packet'];
			$this->oServices->clearAckEvent($oPacket->getDestinationNetwork(), $oPacket->getDestinationStation());
		}
		$this->aAwaitingAck = [];
		$this->aQueue = [];

		$this->scheduleReconnect();
	}

	public function onMessage($sMessage):void
	{
		$aLines = explode ("\n",$sMessage);
		foreach($aLines as $sLine){
			$this->decodeMessage($sLine);
		}
	}


	public function decodeMessage($sMessage):void
	{
		$aMessageParts = explode(" ",$sMessage);
		switch(trim($aMessageParts[0])){
			case 'STATUS':
				$this->oLogger->debug("Piconet Handler: Status is ".$sMessage);
				break;
			case 'ERROR':
				$this->oLogger->error("Piconet Handler: An error occured (".$sMessage.")");
				break;
			case 'MONITOR':
				break;
			case 'RX_BROADCAST':
			case 'RX_IMMEDIATE':
			case 'RX_TRANSMIT':
				$oPacket = new PiconetPacket();
				$oPacket->decode($sMessage);
				$this->oLogger->debug("Piconet Handler: RX Packet ".$oPacket->toString());

				// In monitor mode (when remote bridge is enabled) filter local-network unicast
				// packets not addressed to our station, and forward inter-network packets via bridge.
				if (config::getValue('remote_bridge_enabled') && trim($aMessageParts[0]) === 'RX_TRANSMIT') {
					$iDstNet = (int) $oPacket->getDstNetwork();
					$iDstStn = (int) $oPacket->getDstStation();
					$iOurStn = (int) config::getValue('piconet_station');

					if ($iDstNet === 0) {
						// Local network — ignore unicasts not addressed to our station
						if ($iDstStn !== $iOurStn && $iDstStn !== 255) {
							$this->oLogger->debug("Piconet: monitor — ignoring local unicast for station {$iDstStn}");
							break;
						}
					} else {
						// Non-local network — forward via remote bridge
						$oRemoteConn = \HomeLan\FileStore\RemoteBridge\Map::networkToConnection($iDstNet);
						if ($oRemoteConn !== null) {
							$this->oLogger->debug("Piconet: monitor — forwarding network {$iDstNet} via remote bridge");
							$oRemoteConn->send($oPacket->buildEconetPacket());
						} else {
							$this->oLogger->debug("Piconet: monitor — no remote bridge for network {$iDstNet}, dropping");
						}
						break;
					}
				}

				//Dispatch packet to all the services so the relevant one can deal with it
				$this->oServices->inboundPacket($oPacket);

				//Send any messages for the services
				$aReplies = $this->oServices->getReplies();
				foreach($aReplies as $oReply){
					$this->oPacketDispatcher->sendPacket($oReply);
				}
				break;
			case 'TX_RESULT':
				switch(trim($aMessageParts[1])){
					case 'OK':
						$this->oLogger->debug("Piconet Handler: TX OK");
						$aAck = array_shift($this->aAwaitingAck);
						$this->_unQueue();
						$oPacket = new PiconetPacket();
						if(is_array($aAck)){
							$oPacket->makeAck($aAck['dst_network'],$aAck['dst_station'],$aAck['port'],$aAck['flags']);
							$this->oServices->inboundPacket($oPacket);
							$aReplies = $this->oServices->getReplies();
							foreach($aReplies as $oReply){
								$this->oPacketDispatcher->sendPacket($oReply);
							}
						}
						break;
					case 'UNINITIALISED':
					case 'OVERFLOW':
					case 'UNDERRUN':
					case 'LINE_JAMMED':
					case 'NO_SCOUT_ACK':
					case 'NO_DATA_ACK':
					case 'TIMEOUT':
					case 'MISC':
						$aAck = array_shift($this->aAwaitingAck);
						$this->oLogger->info("Piconet Handler: TX failed the error ".trim($aMessageParts[1]));
						$this->_runQueue();
						// If _runQueue did not push a new TX, all retries are exhausted — clear any
						// service-level ack event so the service does not wait indefinitely.
						if(is_array($aAck) && count($this->aAwaitingAck)==0){
							$this->oServices->clearAckEvent($aAck['dst_network'],$aAck['dst_station']);
						}
						break;
					case 'UNEXPECTED':
					default:
						$aAck = array_shift($this->aAwaitingAck);
						$this->oLogger->error("Piconet Handler: Encountered an internal error with the interface while transmitting (this should never happen), with the message ".trim($aMessageParts[1]));
						// Clear ack event immediately on internal error — no further TX will happen.
						if(is_array($aAck)){
							$this->oServices->clearAckEvent($aAck['dst_network'],$aAck['dst_station']);
						}
						break;
				}
				break;
		}
	}		

	public function onError(\Exception $oError):void
	{
		$this->oLogger->error("Piconet: device error - ".$oError->getMessage());
	}

	public function send(EconetPacket $oPacket, int $iRetries = 3):void
	{
		if (!$this->bConnected) {
			$this->oLogger->warning("Piconet: dropping packet to ".$oPacket->getDestinationNetwork().".".$oPacket->getDestinationStation()." — device not connected");
			return;
		}
		$this->oLogger->debug("Piconet Handler: Sending packet to queue");
		$this->aQueue[] = ['packet'=>$oPacket,'retries'=>$iRetries,'attempts'=>0];
		if (count($this->aQueue)==1){
			$this->_runQueue();
		}
	}
	private function _runQueue():void
	{
		$this->oLogger->debug("Piconet Handler: Running Queue");
		if(count($this->aQueue)>0){
			$aQueueEntry = array_shift($this->aQueue);
			if($aQueueEntry['retries']>0){
				//More re-tires left re-queue
				$aQueueEntry['retries'] = $aQueueEntry['retries']-1;
				$aQueueEntry['attempts'] = $aQueueEntry['attempts']+1;
				array_unshift($this->aQueue,$aQueueEntry);
				$this->oLogger->debug("Piconet Handler: ".$aQueueEntry['retries']." retires left, ".$aQueueEntry['attempts']." attempts made.");
			}
			$this->_writeOutPkt($aQueueEntry['packet']);
		}else{
			$this->oLogger->debug("Piconet Handler: No packets in Queue");
		}
	}

	private function _unQueue():void
	{
		$this->oLogger->debug("Piconet Handler: Dequeuing packet due to scout ack");
		array_shift($this->aQueue);
		$this->_runQueue();
	}
	private function _writeOutPkt(EconetPacket $oPacket)
	{
		$iDstNetwork = $oPacket->getDestinationNetwork();
		//The local network is 
		if($iDstNetwork == config::getValue('piconet_local_network')){
			$iDstNetwork = 0;
		}
		switch($oPacket->getDestinationStation()){
			case 255:
				$this->oLogger->debug("Piconet Handler: Sending broadcast packet (".base64_encode($oPacket->getData()).")");
				fwrite($this->oConnection->stream,"BCAST ".base64_encode($oPacket->getData())."\r\r"); //@phpstan-ignore-line
				fflush($this->oConnection->stream); //@phpstan-ignore-line
				break;
			default:
				$this->oLogger->debug("Piconet Handler: Sending unicast packet to station ".$oPacket->getDestinationStation()." network ".$iDstNetwork." port ".$oPacket->getPort()." packet ".base64_encode($oPacket->getData()));
				$this->aAwaitingAck[] = ['dst_station'=>$oPacket->getDestinationStation(),'dst_network'=>$oPacket->getDestinationNetwork(),'port'=>$oPacket->getPort(),'flags'=>$oPacket->getFlags()];
				fwrite($this->oConnection->stream,"TX ".$oPacket->getDestinationStation()." ".$iDstNetwork." ".$oPacket->getFlags()." ".$oPacket->getPort()." ".base64_encode($oPacket->getData())."\r\r"); //@phpstan-ignore-line
				fflush($this->oConnection->stream); //@phpstan-ignore-line
				
				break;
		}
	}

}	
