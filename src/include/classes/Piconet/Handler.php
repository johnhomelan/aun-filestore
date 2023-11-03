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

	private ConnectionInterface $oConnection;

	private array $aQueue = [];

	private array $aAwaitingAck = [];

	public function __construct(private readonly \Psr\Log\LoggerInterface $oLogger,  private readonly ServiceDispatcher $oServices, private readonly PacketDispatcher $oPacketDispatcher) 
	{
		$this->oLogger->debug("Starting piconet handler");
	}

	public function onOpen(ConnectionInterface $oConnection):void
	{
		$this->oConnection = $oConnection;
	}

	public function onConnect(){
		$this->oLogger->debug("Piconet handler: Connected");
		//stream_set_blocking($this->oConnection->stream,true); 
		stream_set_write_buffer($this->oConnection->stream, 0); //Turn off the write buffer 

		fwrite($this->oConnection->stream,"STATUS\r\r");
		fflush($this->oConnection->stream);

		$this->bringupInterface();
	}

	public function bringupInterface()
	{
		$this->oLogger->debug("Piconet handler: Bringing up the interface");
		$this->oLogger->debug("Piconet handler: Set station to ".config::getValue('piconet_station'));
		fwrite($this->oConnection->stream,"SET_STATION ".config::getValue('piconet_station')."\r\r");
		fflush($this->oConnection->stream);

		$this->oLogger->debug("Piconet handler: Set to listen mode");
		fwrite($this->oConnection->stream,"SET_MODE LISTEN\r\r");
		fflush($this->oConnection->stream);

		$this->oLogger->debug("Piconet handler: Interface setup and ready");
	}


	public function onClose():void
	{
		$this->oLogger->debug("Piconet handler: Closing");
		$this->oConnection->write("SET_MODE STOP\r\r");
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

				//Dispatch it to be processed
				//Dispatch packet to all the services so the relivent one can deal with it 
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
						$this->_unQueue();
						$oPacket = new PiconetPacket();
						$aAck = array_shift($this->aAwaitingAck);
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
						break;
					case 'UNEXPECTED':
					default:
						$aAck = array_shift($this->aAwaitingAck);
						$this->oLogger->error("Piconet Handler: Encountered an internal error with the interface while transmitting (this should never happen), with the message ".trim($aMessageParts[1]));
						break;
				}
				break;
		}
	}		

	public function onError(\Exception $oError):void
	{
		$this->oLogger->debug("Piconet Handler: An eccor occured with the device ".$oError->getMessage());
	}

	public function send(EconetPacket $oPacket, int $iRetries = 3):void
	{
		$this->oLogger->debug("Piconet Handler: Sending packet to queue");
		$this->aQueue[] = ['packet'=>$oPacket,'retries'=>$iRetries,'attempts'=>0];
		if (count($this->aQueue)==1){
			$this->_runQueue();
		}
	}
	private function _runQueue():void
	{	
		$this->oLogger->debug("Piconet Handler: Running Queue");
		var_dump($this->aQueue);
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
		$aQueueEntry = array_shift($this->aQueue);
		if($aQueueEntry['attempts']==0){
			array_unshift($this->aQueue,$aQueueEntry);
		}
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
				fwrite($this->oConnection->stream,"BCAST ".base64_encode($oPacket->getData()."\r\r"));
				fflush($this->oConnection->stream);
				break;
			default:
				$this->oLogger->debug("Piconet Handler: Sending unicast packet to station ".$oPacket->getDestinationStation()." network ".$iDstNetwork." port ".$oPacket->getPort()." packet ".base64_encode($oPacket->getData()));
				$this->aAwaitingAck[] = ['dst_station'=>$oPacket->getDestinationStation(),'dst_network'=>$oPacket->getDestinationNetwork(),'port'=>$oPacket->getPort(),'flags'=>$oPacket->getFlags()];
				fwrite($this->oConnection->stream,"TX ".$oPacket->getDestinationStation()." ".$iDstNetwork." ".$oPacket->getFlags()." ".$oPacket->getPort()." ".base64_encode($oPacket->getData())."\r\r");
				fflush($this->oConnection->stream);
				
				break;
		}
	}

}	
