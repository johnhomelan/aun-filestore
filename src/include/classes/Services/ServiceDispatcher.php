<?php
/**
 * This file contains the ServiceDispatcher class
 *
 *
 * @author John Brown <john@home-lan.co.uk>
 * @package core
*/
namespace HomeLan\FileStore\Services; 

use HomeLan\FileStore\Messages\EconetPacket; 
use HomeLan\FileStore\Aun\AunPacket; 
use HomeLan\FileStore\Aun\Map; 
use HomeLan\FileStore\Services\ProviderInterface;

use config;

/**
 * This class deals with passing packets into all regisitered service 
 *
 * @package core
*/
class ServiceDispatcher {


	private $oLoop;
	private $oAunServer;
	private $aPorts = [];
	private $oLogger;
	private $aReplies = [];
	private $iStreamPortStart=20;
	private $aPortTimeLimits = [];
	private $aHouseKeepingTasks = [];
	private $aAckEvents = [];


	/**
	 * Constructor registers the Logger and all the services 
	 *  
	*/
	public function __construct(\Psr\Log\LoggerInterface $oLogger, array $aServices)
	{		
		$this->oLogger = $oLogger;	

		//Takes and array of serivce providers and adds them the the ServiceDispatcher so they get packets 
		foreach($aServices as $oService){
			$this->addService($oService);
		}
	}

	/**
	 * Called when the application is jusp about to start the main loop
	 *
	 * It passes the loop in so providers can register events with the loop
	*/
	public function start(\React\EventLoop\LoopInterface $oLoop, \React\Datagram\Socket $oAunServer)
	{
		$this->oLoop = $oLoop;
		$this->oAunServer = $oAunServer;
	}

	public function getLoop()
	{
		return $this->oLoop;
	}

	/**
	 * Adds a single service to the service dispatcher
	 *
	 * @param object ServicesInterface $oService
	*/
	public function addService(ProviderInterface $oService): void
	{
		$aPorts = $oService->getServicePorts();

		//Check if any of the ports the service uses are in use
		foreach($aPorts as $iPort){
			if(array_key_exists($iPort,$this->aPorts)){
				throw new Exception("Port already in use.");
			}
		}

		//Add the service for all the ports it provides service via
		$oService->registerService($this);
		foreach($aPorts as $iPort){
			$this->aPorts[$iPort]=$oService;
		}
	}

	/**
	 * Allows a service to register a housekeeping task to get called periodically 
	 *
	 * @param callable $fTask The function to run the house keeping task for 
	*/
	public function addHousingKeepingTask(callable $fTask)
	{
		$this->aHouseKeepingTasks[] = $fTask;
	}

	/**
	 * Allows a service to claim port temp bais for directly streaming data with a client
	 *
	 * @param object ServicesInterface $oService 
	 * @param int $iTimeOut If no packets are recived after this timeout the port is free'd 
	 * @return int The port allocated for streaming by the service handler 
	*/
	public function claimStreamPort(ProviderInterface $oService, int $iTimeOut=60): int
	{
		for($i=$this->iStreamPortStart;$i<($this->iStreamPortStart+20);$i++){
			if(!array_key_exists($i,$this->aPorts)){
				$this->aPorts[$i] = $oService;
				$this->aPortTimeLimits[$i] = time ()+$iTimeOut;
				return $i;
			}
		}
		throw new Exception("Unable to allocte a stream port as there where none free");
	}

	/**
	 * Handles an inbound packet $oService
	*/
	public function inboundPacket(AunPacket $oPacket): void
	{
		if(array_key_exists($oPacket->getPort(),$this->aPorts)){
			switch($oPacket->getPacketType()){
				case 'Immediate':
				case 'Unicast':
					$this->oLogger->debug("Unicast Packet in:  ".$oPacket->toString());
					$this->aPorts[$$oPacket->getPort()]->unicastPacketIn($oPacket->buildEconetPacket());
					break;
				case 'Ack':
					$this->ackEvents($oPacket);
					break;
				case 'Broadcast':
					$this->oLogger->debug("Broadcast Packet in:  ".$oPacket->toString());
					$this->aPorts[$$oPacket->getPort()]->broadcastPacketIn($oPacket->buildEconetPacket());
					break;
			}
			$aReplies = $this->aPorts[$$oPacket->getPort()]->getReplies();
			foreach($aReplies as $oReply){
				$this->queueReply($oReply);	
			}
		}

	}

	/**
	 * Queues a packet from a service for dispatch 
	 *
	 * It also converts all packets to an AunPacket 
	 * @TODO refactor this once AUN is not the only supported abstraction of Econet packets
	 *
	*/
	private function queueReply(EconetPacket $oPacket): void
	{
		usleep(config::getValue('bbc_default_pkg_sleep'));
		$sIP = Map::ecoAddrToIpAddr($oPacket->getDestinationNetwork(),$oPacket->getDestinationStation());
		if(strlen($sIP)>0){
			$sPacket = $oPacket->getAunFrame();
			$this->oLogger->debug("Packet out to  ".$sIP." (".implode(':',unpack('C*',$sPacket)).")");
			if(strlen($sPacket)>0){
				if(strpos($sIP,':')===FALSE){
					$sHost=$sIP.':'.config::getValue('aun_default_port');
				}else{
					$sHost=$sIP;
				}

				$this->aReplies[$sHost]=$sPacket;
			}
		}
	}

	/**
	 * Gets all the replies for all the services
	 *
	 * @return array of EconetPacket
	*/
	public function getReplies(): array
	{
		$aReplies = $this->aReplies;
		$this->aReplies = [];
		return $aReplies;
	}

	/**
	 * Sends all the packets a Service has queues up
	 *
	*/
	public function sendPackets(ProviderInterface $oService)
	{
		$aReplys = $oService->getReplies();
		foreach($aReplys as $oPacket){
			$sIP = Map::ecoAddrToIpAddr($oPacket->getDestinationNetwork(),$oPacket->getDestinationStation());
			$this->oAunServer->send($oPacket->getAunFrame(),$sIP);
		}
		
	}

	/**
	 * Adds an event for the this ack packet the a network/station
	 *
	*/
	public function addAckEvent($iNetwork, $iStation, $fCallable)
	{
		if(!is_array($this->aAckEvents[$iStation])){
			$this->aAckEvents[$iStation]=[];
		}
		$this->aAckEvents[$iStation][$iStation] = $fCallable;
	}

	/**
	 * Checks to see if an Ack should tirgger an event, and if so tirgger it
	 *
	*/ 
	public function ackEvents(AunPacket $oPacket)
	{
		$oEconetPacket = $oPacket->buildEconetPacket();
		if(array_key_exists($oEconetPacket->getSourceNetwork(),$this->aAckEvents) AND array_key_exists($oEconetPacket->getSourceStation(),$this->aAckEvents[$oEconetPacket->getSourceNetwork()])){
			$fCallable = $this->aAckEvents[$oEconetPacket->getSourceNetwork()][$oEconetPacket->getSourceStation()];
			unset($this->aAckEvents[$oEconetPacket->getSourceNetwork()][$oEconetPacket->getSourceStation()]);
			($fCallable)();
		}
	}

	/**
	 * Run the housekeeping tasks for all services
	*/ 
	public function houseKeeping()
	{
		foreach($this->aHouseKeepingTasks as $fTask){
			($fTask)();
		}
	}
} 
