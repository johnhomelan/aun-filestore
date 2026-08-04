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
		// 0x9C: bridge-to-bridge (EC_BR_QUERY / EC_BR_QUERY2)
		// 0x9D: station-to-bridge (EC_BR_LOCALNET / EC_BR_NETKNOWN)
		return [0x9C, 0x9D];
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
			case 'EC_BR_QUERY2':
				$this->queryBridge($oBridgeRequest);
				break;
			//Station to bridge protocol
			case 'EC_BR_LOCALNET':
				$this->queryLocalNet($oBridgeRequest);
				break;
			case 'EC_BR_NETKNOWN':
				$this->queryNetKnown($oBridgeRequest);
				break;
			default:
				$this->oLogger->warning("Bridge: unrecognised function code, ignoring");
		}
	}


	/**
	 * Handle a bridge-to-bridge query (EC_BR_QUERY / EC_BR_QUERY2)
	 *
	 * Records the networks advertised by the peer bridge, then replies with
	 * our own local network number so the peer can update its routing table.
	 *
	 * @param BridgeRequest $oBridgeRequest
	*/
	protected function queryBridge(BridgeRequest $oBridgeRequest): void
	{
		$sPeerKey = $oBridgeRequest->getSourceNetwork().'.'.$oBridgeRequest->getSourceStation();
		foreach($oBridgeRequest->getNetworkList() as $iNet){
			$this->aRemoteNetworks[$iNet] = $sPeerKey;
			$this->oLogger->debug("Bridge: learned network ".$iNet." via peer ".$sPeerKey);
		}

		$oReply = $oBridgeRequest->buildReply();
		$oReply->appendByte(config::getValue('bridge_local_network_number'));
		$this->_addReplyToBuffer($oReply);
	}

	/**
	 * Handle the request to identify the local network
	 *
	 * @param BridgeRequest $oBridgeRequest
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
	 * Handle the request to determine if the bridge knows about a given network
	 *
	 * @param BridgeRequest $oBridgeRequest
	*/
	protected function queryNetKnown(BridgeRequest $oBridgeRequest): void
	{
		$iNetworkNumber = $oBridgeRequest->getNetwork();

		if(Map::networkKnown($iNetworkNumber) || array_key_exists($iNetworkNumber, $this->aRemoteNetworks)){
			//Network known — reply once (the reply itself signals "yes I know this network")
			$this->_addReplyToBuffer($oBridgeRequest->buildReply());
		}
	}

	public function getJobs(): array
	{
		return [];
	}
}
