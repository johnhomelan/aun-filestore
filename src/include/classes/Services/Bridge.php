<?php
/**
 * This file contains the bridge class
 *
 * @author John Brown <john@home-lan.co.uk>
 * @package core
*/
namespace HomeLan\FileStore\Services; 

use HomeLan\FileStore\Aun\Map; 
use config;
use Exception;

/**
 * This class implements the econet bridge
 *
 * @package core
*/
class Bridge {

	protected $oMainApp = NULL ;
	
	protected $aReplyBuffer = array();

	protected $oLogger;

	/**
	 * Holds a list of networks discovered that are reachable through other bridges 
	 *
	*/
	protected $aRemoteNetworks = array();

	/**
	 * Initializes the service
	 *
	*/
	public function __construct(\Psr\Log\LoggerInterface $oLogger)
	{
		$this->oLogger = $oLogger;
	}

	protected function _addReplyToBuffer($oReply)
	{
		$this->aReplyBuffer[]=$oReply;
	}

	/**
	 * Initilizes the bridge loading all the routing information for econet networks
	 *
	 * @TODO Load the routing data
	*/
	public function init(\HomeLan\FileStore\Command\Filestore $oMainApp)
	{
		$this->oMainApp = $oMainApp;
	}


	/**
	 * Retreives all the reply objects built by the bridge 
	 *
	 * This method removes the replies from the buffer 
	*/
	public function getReplies()
	{
		$aReplies = $this->aReplyBuffer;
		$this->aReplyBuffer = array();
		return $aReplies;
	}

	/**
	 * This is the main entry point to this class 
	 *
	 * The bridgerequest object contains the request the bridge must process 
	 * @param object bridgerequest $oBridgeRequest
	*/
	public function processRequest($oBridgeRequest)
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
	 * @param object bridgerequest $oBridgeRequest
	*/
	protected function queryLocalNet($oBridgeRequest)
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
	 * @param object bridgerequest $oBridgeRequest
	*/ 
	protected function queryNetKnown($oBridgeRequest)
	{
		//This first byte after the reply port is the network number the bridge is being queried about
		$iNetworNumber = $oBridgeRequest->getByte();
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
}
