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
		//$this->oConnection->write("RESTART\r");
		$this->oConnection->write("STATUS\r");
		$this->oConnection->write('SET_STATION '.config::getValue('piconet_station')."\r");
		$this->oConnection->write("SET_MODE LISTEN\r");
		
	}

	public function onClose():void
	{
		$this->oLogger->debug("Piconet handler: Closing");
		$this->oConnection->write("STOP");
	}

	public function onMessage($sMessage):void
	{
		$this->oLogger->debug("Piconet handler: Message ".$sMessage);
		
		$aMessageParts = explode($sMessage," ");
		switch($aMessageParts[0]){
			case 'STATUS':
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
				switch($aMessageParts[1]){
					case 'OK':
					case 'UNINITIALISED':
					case 'OVERFLOW':
					case 'UNDERRUN':
					case 'LINE_JAMMED':
					case 'NO_SCOUT_ACK':
					case 'NO_DATA_ACK':
					case 'TIMEOUT':
					case 'MISC':
						$this->oLogger->info("Piconet Handler: TX failed the error ".$aMessageParts[1]);
						break;
					case 'UNEXPECTED':
					default:
						$this->oLogger->error("Piconet Handler: Encountered an internal error with the interface while transmitting (this should never happen), with the message ".$aMessageParts[1]);
						break;
				}
				break;
		}
	}		

	public function onError(\Exception $oError):void
	{
	}

	public function send(EconetPacket $oPacket):void
	{
		$iDstNetwork = $oPacket->getDestinationNetwork();
		//The local network is 
		if($iDstNetwork == config::getValue('piconet_local_network')){
			$iDstNetwork = 0;
		}
		switch($oPacket->getDestinationStation()){
			case 255:
				$this->oConnection->write("BCAST ".base64_encode($oPacket->getData()));
				break;
			default:
				$this->oConnection->write("TX ".$oPacket->getDestinationStation()." ".$iDstNetwork." ".$oPacket->getFlags()." ".$oPacket->getPort()." ".base64_encode($oPacket->getData()));
				break;
		}
	}

}	
