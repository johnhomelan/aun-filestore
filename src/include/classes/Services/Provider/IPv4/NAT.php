<?php

/**
 * This file contains the class the implements NAT (well more like reverse proxy for TCP connections).
 *
 * @author John Brown <john@home-lan.co.uk>
 * @package core
*/
namespace HomeLan\FileStore\Services\Provider\IPv4; 


use HomeLan\FileStore\Services\ProviderInterface;
use HomeLan\FileStore\Services\ServiceDispatcher;
use HomeLan\FileStore\Messages\IPv4Request;
use HomeLan\FileStore\Messages\TCPRequest;
use HomeLan\FileStore\Messages\TcpIPReply;
use HomeLan\FileStore\Services\Provider\IPv4\Conntrack\Exception as ConntrackException;
use HomeLan\FileStore\Services\Provider\IPv4\Conntrack\NotReadyException as NotReadyConntrackException;

use React\SocketClient\TcpConnector;
use React\Promise\PromiseInterface as Promise;

use config;


class NAT
{

	private ?React\EventLoop\LoopInterface $oLoop = null;

	private array $aConnTrack=[];

	private array $aNatTable=[];

	/**
 	 * Constructor 
 	 *
	 * Will load all the routes from a string (this is mostly used for unit testing), or from the routes config file
	 */
	public function __construct(private readonly ProviderInterface $oProvider, ?string $sNATEntries=null)
 	{
		if(is_null($sNATEntries)){
			if(!file_exists(config::getValue('ipv4_nat_file'))){
				return;
			}
			$sNATEntries = file_get_contents(config::getValue('ipv4_nat_file'));
		}
		$aLines = explode("\n",$sNATEntries);
		foreach($aLines as $sLine){
			//Match <ip-to-rewrite-from> <ip-addr-to> <port-to-rewrite-from> <port-to>
			if(preg_match('/^([0-9]{1,3}\.[0-9]{1,3}\.[0-9]{1,3}\.[0-9]{1,3})\s+([0-9]{1,3}\.[0-9]{1,3}\.[0-9]{1,3}\.[0-9]{1,3})\s+([0-9]{1,4})\s+([0-9]{1,4})/',$sLine,$aMatches)>0){
				$this->addNatEntry($aMatches[1],$aMatches[2],(int) $aMatches[3],(int) $aMatches[4]);
			}
		}
	}

	public function registerService(ServiceDispatcher $oServiceDispatcher): void
	{
		$_this = $this;
		$oServiceDispatcher->addHousingKeepingTask(function() use ($_this){
			$_this->houseKeeping();
		});

		//Need keep a reference the service dispatcher so wecan gain access to the event loop, so we can add socket handles 
		$this->oLoop = $oServiceDispatcher->getLoop();
	}

	public function houseKeeping()
	{

	}

	/**
 	 * Adds an entry to the NAT table 
 	 *
 	*/ 	
	public function addNatEntry(string $sIPv4From, string $sIPv4To, int $iPortFrom, int $iPortTo):void
	{
		$this->aNatTable[] = ['ip_from'=>$sIPv4From, 'ip_to'=>$sIPv4To, 'port_from'=>$iPortFrom, 'port_to'=>$iPortTo];
	}

	public function isNatTarget(string $sIP):bool
	{
		
		foreach($this->aNatTable as $aEntry){
			if($aEntry['ip_from']==$sIP){
				return true;
			}
		}
		return false;
	}

	public function dumpNatTable():array
	{
		return $this->aNatTable;
	}
	

	public function dumpConnTrack():array
	{
		return $this->aConnTrack;
	}

	/**
	 * Get the provider using this instance of NAT
	 *
	*/ 	
	public function getProvider():ProviderInterface
	{
		return $this->oProvider;
	}

	/**
 	 * Processes in IPv4 Packet from the econet side of things
 	*/ 
	public function processNatPacket(IPv4Request $oIPv4, TCPRequest $oTcp):void
	{
		//Check if the packet is destined for a IP address we do snat for 
		if(!$this->isNatTarget($oIPv4->getDstIP())){
			return;
		}

		try {
			$aConnTrack = $this->_findConntrackEntry($oIPv4->getSrcIP(),$oIPv4->getDstIP(),$oTcp->getSrcPort(),$oTcp->getDstPort());
			
			$this->_sendDataViaSocket($aConnTrack,$oTcp->getData());
		}catch(ConntrackException $oException){
			//Unknown to conntrack
			if($oTcp->getSynFlag()){
				$this->_createConntrackEntry($oIPv4,$oTcp);
			}
		}
		
	}

	private function _findConntrackEntry(string $sSrcIP, string $sDstIP, int $iSrcPort, int $iDstPort):array
	{
		if(array_key_exists($sSrcIP.'_'.$sDstIP.'_'.$iSrcPort.'_'.$iDstPort, $this->aConnTrack)){
			return $this->aConnTrack[$sSrcIP.'_'.$sDstIP.'_'.$iSrcPort.'_'.$iDstPort];
		}
		throw new ConntrackException("Connection unknown");
	}

	private function _createConntrackEntry(IPv4Request $oIPv4, TCPRequest $oTcp)
	{
		$oPromise = $this->_openConnection($oIPv4->getDstIP(),$oTcp->getDstPort());
		
		$_this = $this;
		$oPromise->then(
			function (ConnectionInterface $oSocket) use ($_this, $oIPv4, $oTcp){
				$sKey = $oIPv4->getSrcIP().'_'.$oIPv4->getDstIP().'_'.$oTcp->getSrcPort().'_'.$oTcp->getDstPort();
				$aConnTrack = 
					['srcip'=>$oIPv4->getSrcIP(),
					'dstip'=>$oIPv4->getDstIP(),
					'srcport'=>$oTcp->getSrcPort(),
					'dstport'=>$oTcp->getDstPort(),
					'window_to'=>$oTcp->getWindow(),
					'sequence'=>$oTcp->getSequence(),
					'ack'=>$oTcp->getAck(),
					'state'=>'connecting',
					'last_activity'=>time(),
					'socket'=>$oSocket];
				$_this->_registerConnection($sKey,$aConnTrack);
				$oSocket->on("data",function($sData) use ($_this, $sKey){
					$_this->_socketDataIn($sKey,$sData);
				});
				$oSocket->on("end",function() use ($_this, $sKey){
					$_this->_socketEnd($sKey);
				});
				$oSocket->on("error",function(\Exception $oError) use ($_this, $sKey){
					$_this->_socketError($sKey,$oError);
				});
				$oSocket->on("close",function() use ($_this, $sKey){
					$_this->_socketClose($sKey);
				});
				//Write the pending data
				$sData = $oTcp->getData();
				if(strlen($sData)>0){
					$oSocket->write($sData);
				}
			});
	}

	/**
	 * Sends data reviced via econet tcp, to the external host 
	 * 
	 * It also updates the last activity timer
	*/ 	 
	private function _sendDataViaSocket(array $aConnTrack, string $sData):void
	{
		$aConnTrack['socket']->write($sData);
		$this->aConnTrack[$aConnTrack['srcip'].'_'.$aConnTrack['dstip'].'_'.$aConnTrack['srcport'].'_'.$aConnTrack['dstport']]['last_activity']=time();
	}

	/**
 	 * Opens a connection to an external host 
 	 * 
 	 * As everything is async (none blocking) this method starts the process, and returns a promise 	
 	 * not the connection its self, the function attached via then will get called once the socket is established
 	*/
	private function _openConnection(string $sDstIP, int $iDstPort):Promise
	{
		if(is_null($this->oLoop)){
			throw new ConntrackNotReadyException("Reference to loop is not ready yet, cant nat to ".$sDstIP.":".$iDstPort);
		}
		
		$oTcpConnector = new TcpConnector($this->oLoop);

		return $oTcpConnector->connect($sDstIP.':'.$iDstPort);
	}

	/**
	 * Registers an external connection with its conntrack details
	 *
	 * This method should never be called from outsite the class
	 * Its only public becasuse is used by an async call back that is triggered
	 * once the socket to an external host has been established. 
	 */  
	public function _registerConnection(string $sKey, array $aConnectionData):void
	{
		$this->aConnTrack[$sKey] = $aConnectionData;
		//Send the syn/ack back
		$oTcp = new TcpIPReply();
		$oEconetPacket = $oTcp->getEconetPacket();
		$oIPv4 = new IPv4Request($oEconetPacket,$this->oProvider->getLogger());
		$this->oProvider->processUnicastIPv4Pkt($oIPv4,$oEconetPacket)

	}

	public function _socketDataIn(string $sKey, string $sData):void
	{
		$iLen = strlen($sData);
		$this->aConnTrack[$sKey];
	}
	
	public function _socketEnd(string $sKey):void
	{
	}

	public function _socketError(\Exception $oException):void
	{
	}

	public function _socketClose(string $sKey):void
	{

	}
}
