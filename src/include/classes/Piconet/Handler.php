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
		$this->oConnection->write('RESTART');
		$this->oConnection->write('SET_STATION '.config::getValue('piconet_station'));
		$this->oConnection->write('SET_MODE 1');
		
	}

	public function onClose():void
	{
		$this->oConnection->write("STOP");
	}

	public function onMessage($sMessage):void
	{
		$aMessageParts = explode($sMessage," ");
		switch($aMessageParts[0]){
			case 'STATUS':
				break;
			case 'ERROR':
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
						break;
					case 'UNINITIALISED':
						break;
					case 'OVERFLOW':
						break;
					case 'UNDERRUN':
						break;
					case 'LINE_JAMMED':
						break;
					case 'NO_SCOUT_ACK':
						break;
					case 'NO_DATA_ACK':
						break;
					case 'TIMEOUT':
						break;
					case 'MISC':
						break;
					case 'UNEXPECTED':
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
