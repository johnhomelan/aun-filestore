<?php
/**
 * This file contains the IPv4 class for implementing IPv4 forwarding over Econet 
 *
 * The implements the EconetA standard for IPv4 over Econet
 *
 * @author John Brown <john@home-lan.co.uk>
 * @package core
*/
namespace HomeLan\FileStore\Services\Provider; 

use HomeLan\FileStore\Services\ProviderInterface;
use HomeLan\FileStore\Services\ServiceDispatcher;
use HomeLan\FileStore\Services\Provider\IPv4\Admin;
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
class IPv4 implements ProviderInterface {

	protected $aReplyBuffer = [];

	protected $oLogger;

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
		return "IPv4";
	}

	/** 
	 * Gets the admin interface Object for this serivce provider 
	 *
	*/
	public function getAdminInterface(): ?AdminInterface
	{
		return new Admin($this);
	}

	/**
	 * Gets the ports this service uses 
	 * 
	 * @return array of int
	*/
	public function getServicePorts(): array
	{
		return [0xD2];
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
		switch($oPacket->getFlags()){
			case 0x1:
				//Regular IPv4 Frame
				break;
			case 0x0a: //ECOTYPE_ARP_SPECIALS
			case 0x09: //ECOTYPE_ARP_REQUEST
			case 0x20: //ECOTYPE_ARP_REPLY
			case 0x21: //ECOTYPE_ARP_REQUEST
			case 0x22: //ECOTYPE_ARP_REPLY 
			case 0x23: //ECOTYPE_REVARP_REQUEST
			case 0x24: //ECOTYPE_REVARP_REPLY
				//Arp
				break;	
		}
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
