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
use HomeLan\FileStore\Encapsulation\PacketDispatcher;
use HomeLan\FileStore\Encapsulation\EncapsulationTypeMap;

use config;

/**
 * This class deals with passing packets into all regisitered service 
 *
 * @package core
*/
class ServiceDispatcher {

	static private $oSingleton;
	private $oEncapsulationTypeMap;
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
	 * Keeping this class as a singleton, this is static method should be used to get references to this object
	 *
	*/
	public static function create(\Psr\Log\LoggerInterface $oLogger = null, array $aServices = null)
	{
		if(!is_object(ServiceDispatcher::$oSingleton)){
			ServiceDispatcher::$oSingleton = new ServiceDispatcher($oLogger, $aServices);
		}
		return ServiceDispatcher::$oSingleton;	
	}

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
	public function start(EncapsulationTypeMap $oEncapsulationTypeMap, \React\EventLoop\LoopInterface $oLoop, \React\Datagram\Socket $oAunServer): void
	{
		$this->oEncapsulationTypeMap = $oEncapsulationTypeMap;
		$this->oLoop = $oLoop;
		$this->oAunServer = $oAunServer;
	}

	/**
	 * Gets a reference to the main event loop
	 *
	 * @TODO Fileserver needs updating so this is nolonger needed 
	*/
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
	 * Gets an array of all the regisitered services 
	 *
	*/
	public function getServices(): array
	{
		$aReturn=[];
		foreach($this->aPorts as $oService){
			if(!in_array($oService,$aReturn)){
				$aReturn[] = $oService;
			}
		}
		return $aReturn;
	}

	public function getServiceByPort(int $iPort): ?ProviderInterface
	{
		if(array_key_exists($iPort,$this->aPorts)){
			return $this->aPorts[$iPort];
		}
		return null;
	}	


	/**
	 * Allows a service to register a housekeeping task to get called periodically 
	 *
	 * @param callable $fTask The function to run the house keeping task for 
	*/
	public function addHousingKeepingTask(callable $fTask): void
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
					$this->aPorts[$oPacket->getPort()]->unicastPacketIn($oPacket->buildEconetPacket());
					break;
				case 'Ack':
					$this->ackEvents($oPacket);
					break;
				case 'Broadcast':
					$this->oLogger->debug("Broadcast Packet in:  ".$oPacket->toString());
					$this->aPorts[$oPacket->getPort()]->broadcastPacketIn($oPacket->buildEconetPacket());
					break;
			}
			$aReplies = $this->aPorts[$oPacket->getPort()]->getReplies();
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
		$this->aReplies[]=$oPacket;
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
	public function sendPackets(ProviderInterface $oService): void
	{
		$oPacketDispatcher = PacketDispatcher::create($this->oEncapsulationTypeMap, $this->oLoop, $this->oAunServer);
		$aReplys = $oService->getReplies();
		foreach($aReplys as $oPacket){
			$oPacketDispatcher->sendPacket($oPacket);
		}
	}

	/**
	 * Adds an event for the this ack packet the a network/station
	 *
	*/
	public function addAckEvent($iNetwork, $iStation, $fCallable): void
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
	public function ackEvents(AunPacket $oPacket): void
	{
		$oEconetPacket = $oPacket->buildEconetPacket();
		if(array_key_exists($oEconetPacket->getSourceNetwork(),$this->aAckEvents) AND array_key_exists($oEconetPacket->getSourceStation(),$this->aAckEvents[$oEconetPacket->getSourceNetwork()])){
			$fCallable = $this->aAckEvents[$oEconetPacket->getSourceNetwork()][$oEconetPacket->getSourceStation()];
			unset($this->aAckEvents[$oEconetPacket->getSourceNetwork()][$oEconetPacket->getSourceStation()]);
			($fCallable)($oPacket);
		}
	}

	/**
	 * Run the housekeeping tasks for all services
	*/ 
	public function houseKeeping(): void
	{
		foreach($this->aHouseKeepingTasks as $fTask){
			($fTask)();
		}
	}
} 
