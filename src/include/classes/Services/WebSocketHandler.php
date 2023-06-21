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
use HomeLan\FileStore\Services\ServiceDispatcher;

use config;

/**
 * This class deals with taking data submitted via websocket and passing it to the services
 *
 * @package core
*/
class WebSocketHandler implements MessageComponentInterface {

	private int $iConnectionSequence = 0;
	private readonly \SplObjectStorage $oConnections;


	public function __construct(private readonly \Psr\Log\LoggerInterface $oLogger,  private readonly ServiceDispatcher $oServices) 
	{
		$this->oLogger->debug("Starting websocket handler");
		$this->oConnections = new \SplObjectStorage;
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
