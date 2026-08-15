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
use HomeLan\FileStore\WebSocket\Map as WebSocketMap;
use HomeLan\FileStore\Piconet\Map as PiconetMap;
use HomeLan\FileStore\RemoteBridge\Map as RemoteBridgeMap;
use HomeLan\FileStore\Messages\BridgeRequest;
use HomeLan\FileStore\Messages\EconetPacket;
use HomeLan\FileStore\Messages\Reply;
use HomeLan\FileStore\Services\Provider\Bridge\Admin;
use config;
use Exception;

/**
 * This class implements the econet bridge
 *
 * @package core
*/
class Bridge implements ProviderInterface {

	/** @var array<int,Reply> */
	protected array $aReplyBuffer = [];

	protected \Psr\Log\LoggerInterface $oLogger;

	/**
	 * Holds a list of networks discovered that are reachable through other bridges
	 *
	 * @var array<int,string>
	*/
	protected array $aRemoteNetworks = [];

	/**
	 * Initializes the service
	 *
	*/
	public function __construct(\Psr\Log\LoggerInterface $oLogger)
	{
		$this->oLogger = $oLogger;
	}

	protected function _addReplyToBuffer(Reply $oReply): void
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
		return new Admin($this);
	}

	/**
	 * Returns the remote networks learned via bridge queries.
	 *
	 * Each entry is an array with keys 'network' (int) and 'via' (string),
	 * where 'via' is "<sourceNetwork>.<sourceStation>" of the peer bridge.
	 *
	 * @return array<int,array{network:int,via:string}>
	 */
	public function getRemoteNetworks(): array
	{
		$aResult = [];
		foreach ($this->aRemoteNetworks as $iNet => $sPeerKey) {
			$aResult[] = ['network' => $iNet, 'via' => $sPeerKey];
		}
		return $aResult;
	}

	/**
	 * Returns all networks served locally by the encapsulation layer, labelled by transport.
	 *
	 * Each entry has 'network' (int) and 'via' (string), where 'via' is the transport
	 * name ('AUN', 'WebSocket', 'Piconet', 'RemoteBridge', or 'local'). Each network
	 * number appears at most once; the first matching transport wins.
	 *
	 * @return array<int, array{network:int, via:string}>
	 */
	public function getLocalKnownNetworks(): array
	{
		$aSeen   = [];
		$aResult = [];

		foreach (RemoteBridgeMap::getKnownNetworks() as $iNet) {
			if (!isset($aSeen[$iNet])) {
				$aSeen[$iNet] = true;
				$aResult[]    = ['network' => $iNet, 'via' => 'RemoteBridge'];
			}
		}

		foreach (Map::getNetworkNumbers() as $iNet) {
			if (!isset($aSeen[$iNet])) {
				$aSeen[$iNet] = true;
				$aResult[]    = ['network' => $iNet, 'via' => 'AUN'];
			}
		}

		foreach (WebSocketMap::getNetworkNumbers() as $iNet) {
			if (!isset($aSeen[$iNet])) {
				$aSeen[$iNet] = true;
				$aResult[]    = ['network' => $iNet, 'via' => 'WebSocket'];
			}
		}

		foreach (PiconetMap::getNetworkNumbers() as $iNet) {
			if (!isset($aSeen[$iNet])) {
				$aSeen[$iNet] = true;
				$aResult[]    = ['network' => $iNet, 'via' => 'Piconet'];
			}
		}

		$iLocalNet = config::getValueAsInt('bridge_local_network_number');
		if ($iLocalNet > 0 && !isset($aSeen[$iLocalNet])) {
			$aResult[] = ['network' => $iLocalNet, 'via' => 'local'];
		}

		return $aResult;
	}

	/**
	 * Gets the ports this service uses
	 *
	 * @return array<int,int>
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
		if($oPacket->getSourceNetwork() === null || $oPacket->getSourceStation() === null){
			$this->oLogger->warning("Bridge: dropping broadcast packet with no resolvable source network/station");
			return;
		}
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
	 *
	 * @return array<int,EconetPacket>
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
	 * Returns every Econet network number known across all encapsulation maps,
	 * plus the configured local network and any networks learned from remote bridges,
	 * with $iExcludeNetwork removed.
	 *
	 * Used by queryBridge() so the reply contains the full picture of what this
	 * server can reach, minus the network the requesting station already belongs to.
	 *
	 * @param int $iExcludeNetwork Network number of the requesting station — omitted from the result
	 * @return int[]
	 */
	public function getAllKnownNetworkNumbers(int $iExcludeNetwork): array
	{
		$aNetworks = array_map('intval', array_merge(
			Map::getNetworkNumbers(),
			WebSocketMap::getNetworkNumbers(),
			PiconetMap::getNetworkNumbers(),
			RemoteBridgeMap::getKnownNetworks(),
			array_keys($this->aRemoteNetworks),
		));

		$iLocalNet = config::getValueAsInt('bridge_local_network_number');
		if ($iLocalNet > 0) {
			$aNetworks[] = $iLocalNet;
		}

		return array_values(array_filter(
			array_unique($aNetworks),
			fn(int $n) => $n !== $iExcludeNetwork,
		));
	}

	/**
	 * Handle a bridge-to-bridge query (EC_BR_QUERY / EC_BR_QUERY2)
	 *
	 * Records the networks advertised by the peer bridge, then replies with
	 * every network number this server knows about (excluding the peer's own network).
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
		foreach ($this->getAllKnownNetworkNumbers($oBridgeRequest->getSourceNetwork()) as $iNet) {
			$oReply->appendByte($iNet);
		}
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
		//The first byte is the client's own network number, derived from whichever
		//encapsulation (AUN/Piconet/WebSocket) translated the inbound packet.
		$oReply->appendByte($oBridgeRequest->getSourceNetwork());
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

		if(Map::networkKnown($iNetworkNumber) || WebSocketMap::networkKnown($iNetworkNumber) || PiconetMap::networkKnown($iNetworkNumber) || array_key_exists($iNetworkNumber, $this->aRemoteNetworks) || (config::getValueAsBool('remote_bridge_enabled') && RemoteBridgeMap::networkKnown($iNetworkNumber))){
			//Network known — reply once (the reply itself signals "yes I know this network")
			$this->_addReplyToBuffer($oBridgeRequest->buildReply());
		}
	}

	/**
	 * @return array<int,array<string,mixed>>
	*/
	public function getJobs(): array
	{
		return [];
	}
}
