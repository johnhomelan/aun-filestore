<?php
/**
 * This file contains the bridge class
 *
 * @author John Brown <john@home-lan.co.uk>
 * @package core
*/
namespace HomeLan\FileStore\Services\Provider; 

use HomeLan\FileStore\Services\ProviderInterface;
use HomeLan\FileStore\Services\ServiceDispatcher;
use HomeLan\FileStore\Aun\Map; 
use HomeLan\FileStore\Messages\BridgeRequest; 
use HomeLan\FileStore\Messages\EconetPacket; 
use config;
use Exception;

/**
 * This class implements the econet bridge
 *
 * @package core
*/
class Bridge implements ProviderInterface {

	protected $aReplyBuffer = [];

	protected $oLogger;

	/**
	 * Holds a list of networks discovered that are reachable through other bridges 
	 *
	*/
	protected $aRemoteNetworks = [];

	/**
	 * Initializes the service
	 *
	*/
	public function __construct(\Psr\Log\LoggerInterface $oLogger)
	{
		$this->oLogger = $oLogger;
	}

	protected function _addReplyToBuffer($oReply): void
	{
		$this->aReplyBuffer[]=$oReply;
	}

	public function getName(): string
	{
		return "Bridge";
	}

	/** 
	 * Gets the admin interface Object for this serivce provider 
	 *
	*/
	public function getAdminInterface(): ?AdminInterface
	{
		return NULL;
	}

	/**
	 * Gets the ports this service uses 
	 * 
	 * @return array of int
	*/
	public function getServicePorts(): array
	{
		return [0x9D];
	}

	/** 
	 * All inbound bridge messages come in via broadcast 
	 *
	*/
	public function broadcastPacketIn(EconetPacket $oPacket): void
	{
		$this->processRequest(new BridgeRequest($oPacket,$this->oLogger));

	}

	/** 
	 * All inbound bridge messages come in via broadcast, so unicast should ignore them
	 *
	*/
	public function unicastPacketIn(EconetPacket $oPacket): void
	{
	}


	public function registerService(ServiceDispatcher $oServiceDispatcher): void
	{
	}

	/**
	 * Retreives all the reply objects built by the bridge 
	 *
	 * This method removes the replies from the buffer 
	*/
	public function getReplies(): array
	{
		$aReturn = [];
		foreach($this->aReplyBuffer as $oReply){
			$aReturn[] = $oReply->buildEconetpacket();
		}
		$this->aReplyBuffer = [];
		return $aReturn;
	}

	/**
	 * This is the main entry point to this class 
	 *
	 * The bridgerequest object contains the request the bridge must process 
	 * @param bridgerequest $oBridgeRequest
	*/
	public function processRequest(BridgeRequest $oBridgeRequest): void
	{
		$sFunction = $oBridgeRequest->getFunction();
		$this->oLogger->debug("Bridge function ".$sFunction);
		switch($oBridgeRequest->getFunction()){
			//Bridge to bridge protocol
			case 'EC_BR_QUERY':
				break;
			case 'EC_BR_QUERY2':
				break;
			//Station to bridge protocol
			case 'EC_BR_LOCALNET':
				$this->queryLocalNet($oBridgeRequest);
				break;
			case 'EC_BR_NETKNOWN':
				$this->queryNetKnown($oBridgeRequest);
				break;
			default:
				throw new Exception("Un-handled bridge request function");
		}
	}


	/**
	 * Handle the request to identify the local network
	 *
	 * @param bridgerequest $oBridgeRequest
	*/
	protected function queryLocalNet(BridgeRequest $oBridgeRequest): void
	{
		$oReply = $oBridgeRequest->buildReply();
		//The first byte of the reply is the local network number	
		$oReply->appendByte(config::getValue('bridge_local_network_number'));
		//The second byte is the version number of the bridge firmware 
		$oReply->appendByte(128);
		$this->_addReplyToBuffer($oReply);
	}

	/**
	 * Handle the request to determine if the birdge knows about a given network
	 *
	 * @param bridgerequest $oBridgeRequest
	*/ 
	protected function queryNetKnown(BridgeRequest $oBridgeRequest): void
	{
		//This first byte after the reply port is the network number the bridge is being queried about
		$iNetworNumber = $oBridgeRequest->getNetwork();
		$oReply = $oBridgeRequest->buildReply();

		//Check if the AUN Map knows about the network (as aun is currently our only econet emulation)
		if(Map::networkKnown($iNetworNumber)){
			//Network exists (we only reply if we know about a network)
			$this->_addReplyToBuffer($oReply);
		}

		//Check the list of networks other bridges can reach
		if(array_key_exists($iNetworNumber,$this->aRemoteNetworks)){
			//Network exists (we only reply if we know about a network)
			$this->_addReplyToBuffer($oReply);
		}
	}

	public function getJobs(): array
	{
		return [];
	}
}
