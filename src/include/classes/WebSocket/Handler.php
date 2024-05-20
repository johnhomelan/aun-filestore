<?php

/**
 * This file contains the WebSocketHandler class
 *
 *
 * @author John Brown <john@home-lan.co.uk>
 * @package core
*/
namespace HomeLan\FileStore\WebSocket;

use Ratchet\MessageComponentInterface;
use Ratchet\ConnectionInterface;

use HomeLan\FileStore\Messages\EconetPacket; 
use HomeLan\FileStore\Aun\AunPacket; 
use HomeLan\FileStore\Aun\Map as AunMap; 
use HomeLan\FileStore\Services\ProviderInterface;
use HomeLan\FileStore\Services\ServiceDispatcher;
use HomeLan\FileStore\Encapsulation\PacketDispatcher;

use HomeLan\FileStore\WebSocket\Map as WebSocketMap;
use HomeLan\FileStore\WebSocket\JsonPacket;

use config;

/**
 * This class deals with taking data submitted via websocket and passing it to the services
 *
 * @package core
*/
class Handler implements MessageComponentInterface {

	private int $iConnectionSequence = 0;

	private readonly \SplObjectStorage $oConnections;


	public function __construct(private readonly \Psr\Log\LoggerInterface $oLogger,  private readonly ServiceDispatcher $oServices, private readonly PacketDispatcher $oPacketDispatcher) 
	{
		$this->oLogger->debug("Starting websocket handler");
		$this->oConnections = new \SplObjectStorage;
	}

	public function onOpen(ConnectionInterface $oConnection):void
	{
		$this->iConnectionSequence++;
		$this->oConnections->attach($oConnection,$this->iConnectionSequence);
	}

	public function onClose(ConnectionInterface $oConnection):void
	{
		//Logout of the filestore 
		//@TODO The logout needs implmenting, or security contexts will leak which is bad TM

		//Free the connection in the map
		WebSocketMap::freeAddress($oConnection);

		//Remove the connection from connections object store
		$this->oConnections->detach($oConnection);
	}

	public function onMessage(ConnectionInterface $oConnection, $sMessage):void
	{
		$oJsonMessage = new JsonPacket($oConnection);
		$oJsonMessage->decode($sMessage);
		$sAck = '';
		switch($oJsonMessage->getType()){
			case 'pkt':
				if (
					$oJsonMessage->getDstNetwork()==config::getValue('websocket_network_address') AND 
					$oJsonMessage->getDstStation()==config::getValue('websocket_station_address')
				){

					//We are the target of the packet so we to ack it 		
					$sAck = $oJsonMessage->buildAck();
					$this->oLogger->debug("websocket: Sending Ack packet for pkt message");
					$oConnection->send($sAck);

					//Dispatch it to be processed
					//Dispatch packet to all the services so the relivent one can deal with it 
					$this->oServices->inboundPacket($oJsonMessage);

					//Send any messages for the services
					$aReplies = $this->oServices->getReplies();
					foreach($aReplies as $oReply){
						$this->oPacketDispatcher->sendPacket($oReply);
					}

				}
				break;
			case 'ctrl':
				//Build the reposne to the control message
				$sAck = $oJsonMessage->buildAck();
				$this->oLogger->debug("websocket: Sending Ack packet for ctrl message");
				$oConnection->send($sAck);
				break;
			default: 
				throw new \Exception("Websock recived an invalid message");
		}

	
	}

	public function onError(ConnectionInterface $oConnection, \Exception $oError):void
	{
		//Remove the connection from connections object store 
		$this->oConnections->detach($oConnection);

		//Need to logout of the filestore
		//@TODO The logout needs implmenting, or security contexts will leak which is bad TM
		
		//Free the connection in the map
		WebSocketMap::freeAddress($oConnection);

		//Close the connection 
		$oConnection->close();
	}
}	
