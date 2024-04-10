<?php

/**
 * This file contains the class the implements NAT (well more like reverse proxy for TCP connections).
 *
 * @author John Brown <john@home-lan.co.uk>
 * @package core
*/
namespace HomeLan\FileStore\Services\Provider\IPv4; 


use HomeLan\FileStore\Services\Provider\IPv4 as ProviderInterface;
use HomeLan\FileStore\Services\ServiceDispatcher;
use HomeLan\FileStore\Messages\IPv4Request;
use HomeLan\FileStore\Messages\TCPRequest;
use HomeLan\FileStore\Messages\TcpIPReply;
use HomeLan\FileStore\Services\Provider\IPv4\Conntrack\Exception as ConntrackException;
use HomeLan\FileStore\Services\Provider\IPv4\Conntrack\NotReadyException as NotReadyConntrackException;
use HomeLan\FileStore\Services\Provider\IPv4\Exceptions\NatException;
use React\Socket\TcpConnector;
use React\Socket\ConnectionInterface;
use React\Promise\PromiseInterface as Promise;

use config;


class NAT
{

	private ServiceDispatcher $oServiceDispatcher;

	private array $aConnTrack=[];

	private array $aNatTable=[];

	private array $aConnTrackPending=[];

	const DEFAULT_TIMEOUT = 120;

	/**
 	 * Constructor 
 	 *
	 * Will load all the routes from a string (this is mostly used for unit testing), or from the routes config file
	 */
	public function __construct(private readonly ProviderInterface $oProvider, private readonly \Psr\Log\LoggerInterface $oLogger, ?string $sNATEntries=null)
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
		$this->oServiceDispatcher =  $oServiceDispatcher;
	}

	public function houseKeeping()
	{
		foreach($this->aConnTrack as $sKey=>$aEntry){
			$iAge = time()-$aEntry['last_activity'];
			if($iAge>self::DEFAULT_TIMEOUT){
				$this->oLogger->notice("NAT: Housing keeping timed out conntrack entry ".$sKey);
				$aEntry['socket']->close();
			}
		}
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

	public function getNatEntry(string $sIP, int $iPort):array
	{
		foreach($this->aNatTable as $aEntry){
			if($aEntry['ip_from']==$sIP AND $aEntry['port_from']==$iPort){
				return  $aEntry;
			}
		}
		throw new NatException("No NAT entry for ip/port combination");
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
			$sKey = $this->_findConntrackEntry($oIPv4->getSrcIP(),$oIPv4->getDstIP(),$oTcp->getSrcPort(),$oTcp->getDstPort());
			
			$this->_sendDataViaSocket($sKey, $oIPv4, $oTcp);
			$this->oLogger->debug("NAT: Sending data to exisiting stream ".$sKey);
		}catch(ConntrackException $oException){
			//Unknown to conntrack
			if($oTcp->getSynFlag()){
				$this->_createConntrackEntry($oIPv4,$oTcp);
			}
		}
		
	}

	private function _findConntrackEntry(string $sSrcIP, string $sDstIP, int $iSrcPort, int $iDstPort):string
	{
		if(array_key_exists($sSrcIP.'_'.$sDstIP.'_'.$iSrcPort.'_'.$iDstPort, $this->aConnTrack)){
			return $sSrcIP.'_'.$sDstIP.'_'.$iSrcPort.'_'.$iDstPort;
		}
		throw new ConntrackException("Connection unknown");
	}

	private function _createConntrackEntry(IPv4Request $oIPv4, TCPRequest $oTcp)
	{
		$sKey = $oIPv4->getSrcIP().'_'.$oIPv4->getDstIP().'_'.$oTcp->getSrcPort().'_'.$oTcp->getDstPort();

		//Deal with the fact that the connection getting established is async, so the connection may form
		//after the client retransmitts the sync packet. 
		if(in_array($sKey,$this->aConnTrackPending)){
				$this->oLogger->debug("NAT: Already waiting for this connection ".$sKey);
				return;
		}
		$this->aConnTrackPending[] = $sKey;


		$aNatEntry = $this->getNatEntry($oIPv4->getDstIP(),$oTcp->getDstPort());

		$oPromise = $this->_openConnection($aNatEntry['ip_to'],$aNatEntry['port_to']);
		$this->oLogger->debug("NAT: Creating new connection from ".$oIPv4->getSrcIP().":".$oTcp->getSrcPort()." to ".$aNatEntry['ip_to'].":".$aNatEntry['port_to']);
		$_this = $this;
		$oPromise->then(
			function (ConnectionInterface $oSocket) use ($_this, $oIPv4, $oTcp){
				$sKey = $oIPv4->getSrcIP().'_'.$oIPv4->getDstIP().'_'.$oTcp->getSrcPort().'_'.$oTcp->getDstPort();
				$aConnTrack = 
					['srcip'=>$oIPv4->getSrcIP(),
					'dstip'=>$oIPv4->getDstIP(),
					'srcport'=>$oTcp->getSrcPort(),
					'dstport'=>$oTcp->getDstPort(),
					'pktid'=>$oIPv4->getId(),
					'window_to'=>$oTcp->getWindow(),
					'sequence'=>$oTcp->getSequence(),
					'ack'=>$oTcp->getAck(),
					'state'=>'connecting',
					'last_activity'=>time(),
					'sequence_sock'=>rand(0,2000),
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
			},
			function(\Exception $e) use ($_this, $oIPv4, $oTcp){
				$sKey = $oIPv4->getSrcIP().'_'.$oIPv4->getDstIP().'_'.$oTcp->getSrcPort().'_'.$oTcp->getDstPort();
				$_this->_remoteConnectionFailed($e, $sKey,$oIPv4, $oTcp);
			}
		);
	}

	/**
	 * Sends data reviced via econet tcp, to the external host 
	 * 
	 * It also updates the last activity timer
	*/ 	 
	private function _sendDataViaSocket(string $sKey, IPv4Request $oIPv4, TCPRequest $oTcp):void
	{

		$this->oLogger->debug("NAT: Recived data, sending to remote sock ".$oTcp->toString());
		if($oTcp->getSequence()==$this->aConnTrack[$sKey]['sequence']){
			$this->oLogger->debug("NAT: Duplicate packet seq : ".$oTcp->getSequence()." dropping.");
			return;
		}

		if($oTcp->getSequence()==($this->aConnTrack[$sKey]['sequence']+1)){
			$this->oLogger->debug("NAT: Empty ack packet, just accept (dont reply)");
			return;
		}

		$this->aConnTrack[$sKey]['socket']->write($oTcp->getData());
		$this->aConnTrack[$sKey]['last_activity']=time();
		$this->aConnTrack[$sKey]['pktid'] = $oIPv4->getId();
		$this->aConnTrack[$sKey]['sequence'] = $oTcp->getSequence();
		$this->aConnTrack[$sKey]['ack'] = $oTcp->getAck();
		$this->aConnTrack[$sKey]['window_to'] = $oTcp->getWindow();

		$this->aConnTrack[$sKey]['socket']->write($oTcp->getData());

		//Update the state tracking field
		switch($this->aConnTrack[$sKey]['state']){
			case 'connected':
				if($oTcp->getResetFlag()){
					$this->aConnTrack[$sKey]['state']='Error';
					$this->aConnTrack[$sKey]['socket']->close();
				}
				if($oTcp->getFinFlag()){
					$this->aConnTrack[$sKey]['state']='closing';
					$this->aConnTrack[$sKey]['socket']->close();
				}
				break;
			default:
				if($oTcp->getFinFlag()){
					$this->aConnTrack[$sKey]['state']='closing';
					$this->aConnTrack[$sKey]['socket']->close();
				}
				break;
				
		}

		//Build an ack reply (with not data contained but lets not keep the 8bit client having to keep this stuff in its buffer)
		$oTcpIpPkt = $this->_builtTcpIPReply($sKey);

		//Sort out seq/ack numbers 
		//Ack everything we have gotton so far
		$oTcpIpPkt->setAckNumber($this->aConnTrack[$sKey]['sequence']+1);

		//Inc the sequence number 
		$oTcpIpPkt->setSeqNumber($this->aConnTrack[$sKey]['sequence_sock']);

		//Set flags
		switch($this->aConnTrack[$sKey]['state']){
			case 'closing':
				$oTcpIpPkt->setFlagAck(true);
				$oTcpIpPkt->setFlagFin(true);
				unset($this->aConnTrack[$sKey]);
				break;
			case 'error':
				$oTcpIpPkt->setFlagAck(true);
				$oTcpIpPkt->setFlagReset(true);
				unset($this->aConnTrack[$sKey]);
				break;
			default:
				$oTcpIpPkt->setFlagAck(true);
				break;
		}
		//Add the data to the tcp stream 
		$oTcpIpPkt->setData("");
		$this->oLogger->debug("NAT: Replied with tcp pkt ".$oTcpIpPkt->toString() );
		//Build the econet packet, and dipatch it 
		$oEconetPacket = $oTcpIpPkt->buildEconetpacket();
		$oIP = new IPv4Request($oEconetPacket, $this->oProvider->getLogger());
		$this->oProvider->processUnicastIPv4Pkt($oIP, $oEconetPacket);

				
	}

	/**
 	 * Opens a connection to an external host 
 	 * 
 	 * As everything is async (none blocking) this method starts the process, and returns a promise 	
 	 * not the connection its self, the function attached via then will get called once the socket is established
 	*/
	private function _openConnection(string $sDstIP, int $iDstPort):Promise
	{
		$oLoop = $this->oServiceDispatcher->getLoop();
		if(is_null($oLoop)){
				throw new NotReadyConntrackException("Reference to loop is not ready yet, cant nat to ".$sDstIP.":".$iDstPort);
		}
		
		$oTcpConnector = new TcpConnector($oLoop);

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
		$this->oLogger->debug("NAT: Registering external connection ".$sKey."");
	
		//Clear out the pending key 
		foreach($this->aConnTrackPending as $iPendingKey=>$sPending){
			if($sKey==$sPending){
				unset($this->aConnTrackPending[$iPendingKey]);
				break;
			}
		}

		//Ack the the syn packet now we have a connection 
		$this->aConnTrack[$sKey] = $aConnectionData;
		//Create a TCP/IP packet with the basic addr/port fields filled in 
		$oTcp = $this->_builtTcpIPReply($sKey);
		//Send the syn/ack back
		$oTcp->setFlagSyn(true);
		$oTcp->setFlagAck(true);

		$this->aConnTrack[$sKey]['state']='connected';
		$this->oLogger->debug("NAT: Replied with tcp pkt ".$oTcp->toString() );
		$oEconetPacket = $oTcp->buildEconetpacket();
		$oIPv4 = new IPv4Request($oEconetPacket,$this->oLogger);
		$this->oProvider->processUnicastIPv4Pkt($oIPv4,$oEconetPacket);

	}

	/**
	 * This is called when we can't connect to the remote host
	 *
	 * This method should never be called from outsite the class
	 * Its only public becasuse is used by an async call back that is triggered
	 * once the socket to an external host has been established. 
	*/  
	private function _remoteConnectionFailed(\Exception $oException, string $sKey, IPv4Request $oIPv4, TCPRequest $oTcp)
	{
		$this->oLogger->debug("NAT: Could not connect to remote host (".$oException->getMessage().").");
		$oTcpIpPkt = new TcpIPReply();

		$oTcpIpPkt->setId($oIPv4->getId());
		$oTcpIpPkt->setAckNumber($oTcp->getSequence());
		$oTcpIpPkt->setSeqNumber(0);

		//Set the econet src station
		$oTcpIpPkt->setSrcStation(config::getValue('nat_default_station'));
		$oTcpIpPkt->setSrcNetwork(config::getValue('nat_default_network'));

		//Set addressing params
		$oTcpIpPkt->setDstIP($oIPv4->getSrcIP());
		$oTcpIpPkt->setSrcIP($oIPv4->getDstIP());
		$oTcpIpPkt->setDstPort($oTcp->getDstPort());
		$oTcpIpPkt->setSrcPort($oTcp->getSrcPort());
		$oTcpIpPkt->setWindow(65536);

		//Set the flags to show the connection has failed 
		$oTcpIpPkt->setFlagAck(true);
		$oTcpIpPkt->setFlagReset(true);

		$oEconetPacket = $oTcp->getEconetPacket();
		$oIPv4 = new IPv4Request($oEconetPacket,$this->oLogger);
		$this->oProvider->processUnicastIPv4Pkt($oIPv4,$oEconetPacket);
	}

	/**
	 * Handles data arriving on an external socket
	 *
	 * This method should never be called from outsite the class
	 * Its only public becasuse is used by an async call back that is triggered
	 * once the socket to an external host has been established. 
	 */  
	public function _socketDataIn(string $sKey, string $sData):void
	{
		$iLen = strlen($sData);
		$this->aConnTrack[$sKey]['last_activity']=time();

		$this->oLogger->debug("NAT: Data received from external socket (".$sKey."), data len was ".strlen($sData)." (".$sData.")");
		
		//Create a TCP/IP packet with the basic addr/port fields filled in 
		$oTcpIpPkt = $this->_builtTcpIPReply($sKey);

		//Sort out seq/ack  numbers 
		$this->aConnTrack[$sKey]['sequence_sock']++;
		$this->aConnTrack[$sKey]['sequence_sock'] = $this->aConnTrack[$sKey]['sequence_sock']+$iLen;
		$oTcpIpPkt->setSeqNumber($this->aConnTrack[$sKey]['sequence_sock']);


		//Set flags
		switch($this->aConnTrack[$sKey]['state']){
			case 'closing':
				$oTcpIpPkt->setFlagAck(true);
				$oTcpIpPkt->setFlagFin(true);
				unset($this->aConnTrack[$sKey]);
				break;
			case 'error':
				$oTcpIpPkt->setFlagAck(true);
				$oTcpIpPkt->setFlagReset(true);
				unset($this->aConnTrack[$sKey]);
				break;
		}
		//Add the data to the tcp stream 
		$oTcpIpPkt->setData($sData);
		$this->oLogger->debug("NAT: Replied with tcp pkt ".$oTcpIpPkt->toString() );
		//Build the econet packet, and dipatch it 
		$oEconetPacket = $oTcpIpPkt->buildEconetpacket();
		$oIP = new IPv4Request($oEconetPacket, $this->oProvider->getLogger());
		$this->oProvider->processUnicastIPv4Pkt($oIP, $oEconetPacket);
		
	}
	
	public function _socketEnd(string $sKey):void
	{
		if(array_key_exists($sKey,$this->aConnTrack)){
			$this->aConnTrack[$sKey]['state']='closing';
			$this->_socketDataIn($sKey, "");
		}
	}

	public function _socketError(string $sKey, \Exception $oException):void
	{
		$this->oLogger->debug("NAT: Error on socket for ".$sKey);
		if(array_key_exists($sKey,$this->aConnTrack)){
			$this->aConnTrack[$sKey]['state']='error';
			$this->_socketDataIn($sKey, "");
		}
	}

	public function _socketClose(string $sKey):void
	{
		if(array_key_exists($sKey,$this->aConnTrack)){
			$this->oLogger->debug("NAT: Closing socket for ".$sKey);
			$this->aConnTrack[$sKey]['state']='closing';
			$this->_socketDataIn($sKey, "");
		}
	}

	private function _builtTcpIPReply(string $sKey):TcpIPReply
	{
		$oTcpIpPkt = new TcpIPReply();

		//Set the econet src station
		$oTcpIpPkt->setSrcStation(config::getValue('nat_default_station'));
		$oTcpIpPkt->setSrcNetwork(config::getValue('nat_default_network'));
 
		//Set the econet src network
		//Set the packet id
		$oTcpIpPkt->setId($this->aConnTrack[$sKey]['pktid']++);

		$oTcpIpPkt->setAckNumber($this->aConnTrack[$sKey]['sequence']+1);
		$oTcpIpPkt->setSeqNumber(0);


		//Set addressing params
		$oTcpIpPkt->setDstIP($this->aConnTrack[$sKey]['srcip']);
		$oTcpIpPkt->setSrcIP($this->aConnTrack[$sKey]['dstip']);
		$oTcpIpPkt->setDstPort($this->aConnTrack[$sKey]['srcport']);
		$oTcpIpPkt->setSrcPort($this->aConnTrack[$sKey]['dstport']);
		$oTcpIpPkt->setWindow(65536);

		return $oTcpIpPkt;

	}
}
