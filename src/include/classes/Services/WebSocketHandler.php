<?php

/**
 * This file contains the WebSocketHandler class
 *
 *
 * @author John Brown <john@home-lan.co.uk>
 * @package core
*/
namespace HomeLan\FileStore\Services; 

use Ratchet\MessageComponentInterface;
use Ratchet\ConnectionInterface;

use HomeLan\FileStore\Messages\EconetPacket; 
use HomeLan\FileStore\Aun\AunPacket; 
use HomeLan\FileStore\Aun\Map; 
use HomeLan\FileStore\Services\ProviderInterface;

use config;

/**
 * This class deals with taking date submitted via websocket and passing it to the services
 *
 * @package core
*/
class WebSocketHandler implements MessageComponentInterface {

	private $iConnectionSequence = 0;
	private $oConnections;


	public function __construct(\Psr\Log\LoggerInterface $oLogger, array $aServices) 
	{
		$this->oConnections = new \SplObjectStorage;
		$this->oLogger = $oLogger;	

		//Takes and array of serivce providers and adds them the the ServiceDispatcher so they get packets 
		foreach($aServices as $oService){
			$this->addService($oService);
		}
	}

	public function onOpen(ConnectionInterface $oConnection)
	{
		$this->iConnectionSequence++;
		$this->oConnections->attach($oConnection,$this->iConnectionSequence);
	}

	public function onClose(ConnectionInterface $oConnection)
	{
		$this->oConnections->detach($oConnection);
	}

	public function onMessage(ConnectionInterface $oConnection, $sMessage)
	{

	}

	public function onError(ConnectionInterface $oConnection, \Exception $oError)
	{
	}
}	
