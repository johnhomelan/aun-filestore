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
use HomeLan\FileStore\Encapsulation\EncapsulationInterface;
use HomeLan\FileStore\Piconet\Handler as PiconetHandler;
use HomeLan\FileStore\RemoteBridge\Map as RemoteBridgeMap;
use HomeLan\FileStore\RemoteProvider\AckRelayMap;
use HomeLan\FileStore\RemoteProvider\Messages\RelayedAck;
use config;

/**
 * This class deals with passing packets into all regisitered service 
 *
 * @package core
*/
class ServiceDispatcher {

	static private ?\HomeLan\FileStore\Services\ServiceDispatcher $oSingleton = null;
	private ?\HomeLan\FileStore\Encapsulation\EncapsulationTypeMap $oEncapsulationTypeMap = null;
	private ?\React\EventLoop\LoopInterface $oLoop = null;
	/** @var array<int,ProviderInterface> */
	private array $aProviders = [];
	/** @var array<int,ProviderInterface> */
	private array $aPorts = [];
	/** @var array<int,EconetPacket> */
	private array $aReplies = [];
	private int $iStreamPortStart=20;
	/** @var array<int,int> */
	private array $aPortTimeLimits = [];
	/** @var array<int,callable> */
	private array $aHouseKeepingTasks = [];
	/** @var array<int,array<int,array{callable:callable,seq:int,expires:int}>> */
	private array $aAckEvents = [];

	const MAX_STREAMS = 20;

	/**
	 * Default number of seconds an addAckEvent() callback is allowed to wait for its ack
	 * before houseKeeping() gives up on it. AUN has its own retry queue (Aun\Handler) that
	 * gives up and calls clearAckEvent() itself; this timeout is the only safety net for
	 * encapsulations that send fire-and-forget (e.g. WebSocket, Piconet) — without it a lost
	 * reply leaves the callback (and whatever it's holding onto, e.g. an open file handle)
	 * registered forever.
	*/
	const DEFAULT_ACK_EVENT_TIMEOUT = 30;
	/**
	 * Keeping this class as a singleton, this is static method should be used to get references to this object
	 *
	 * @param array<int,ProviderInterface> $aServices
	*/
	public static function create(?\Psr\Log\LoggerInterface $oLogger = null, ?array $aServices = null): self
	{
		if(!is_object(ServiceDispatcher::$oSingleton)){
			if($oLogger === null OR $aServices === null){
				throw new \Exception("ServiceDispatcher::create() must first be called with a logger and services array to create the singleton");
			}
			$oLogger->debug("Creating the singleton of ServiceDispatcher");
			ServiceDispatcher::$oSingleton = new ServiceDispatcher($oLogger, $aServices);
		}
		return ServiceDispatcher::$oSingleton;
	}

	/**
	 * Constructor registers the Logger and all the services
	 *
	 * @param array<int,ProviderInterface> $aServices
	*/
	public function __construct(private readonly \Psr\Log\LoggerInterface $oLogger, array $aServices)
	{
		//Takes and array of serivce providers and adds them the the ServiceDispatcher so they get packets 
		foreach($aServices as $oService){
			$this->addService($oService);
		}
	}

	/**
	 * Called when the application is just about to start the main loop
	 *
	 * It passes the loop in so providers can register events with the loop
	*/
	public function start(EncapsulationTypeMap $oEncapsulationTypeMap, \React\EventLoop\LoopInterface $oLoop):void
	{
		$this->oEncapsulationTypeMap = $oEncapsulationTypeMap;
		$this->oLoop = $oLoop;
	}

	/**
	 * Gets a reference to the main event loop
	 *
	 * @TODO Fileserver needs updating so this is nolonger needed
	*/
	public function getLoop(): ?\React\EventLoop\LoopInterface
	{
		return $this->oLoop;
	}

	/**
	  * Adds a single service to the service dispatcher
	 */
	public function addService(ProviderInterface $oService): void
	{
		if(in_array($oService, $this->aProviders)){
			throw new Exception("Service has already been added.");
		}

		$this->aProviders[] = $oService;

		//Start dealing with the ports needed for a service
		$aPorts = $oService->getServicePorts();

		//Check the service is not regisitered
		//Check if any of the ports the service uses are in use
		foreach($aPorts as $iPort){
			if(array_key_exists($iPort,$this->aPorts)){
				throw new Exception("Port already in use.");
			}
		}

		//Add the service for all the ports it provides service via
		$oService->registerService($this);
		$this->enableService($oService);
	}

	/**
	 * Gets an array of all the regisitered services
	 *
	 * @return array<int,ProviderInterface>
	*/
	public function getServices(): array
	{
		return $this->aProviders;
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
	  * @param int $iTimeOut If no packets are recived after this timeout the port is free'd 
	  * @return int The port allocated for streaming by the service handler 
	*/
	public function claimStreamPort(ProviderInterface $oService, int $iTimeOut=60): int
	{
		for($i=$this->iStreamPortStart;$i<($this->iStreamPortStart+self::MAX_STREAMS);$i++){
			if(!array_key_exists($i,$this->aPorts)){
				$this->bindStreamPort($i, $oService, $iTimeOut);
				return $i;
			}
		}
		throw new Exception("Unable to allocte a stream port as there where none free");
	}

	/**
	 * Binds a specific port number to a service, with the same timeout-based expiry
	 * claimStreamPort() gives the port it picks itself - used by
	 * Services\Provider\ProxyProvider to bind, on a remote provider host's own
	 * ServiceDispatcher instance, the exact port number filestored's ServiceDispatcher already
	 * chose for it via claimStreamPort() (see docs/protocols/remote-provider.md § Stream
	 * Claims) - the two processes' independent port counters would otherwise have no reason to
	 * agree on the same number.
	*/
	public function bindStreamPort(int $iPort, ProviderInterface $oService, int $iTimeOut=60): void
	{
		$this->aPorts[$iPort] = $oService;
		$this->aPortTimeLimits[$iPort] = time()+$iTimeOut;
	}

	/**
	 * Handles an inbound packet $oService
	*/
	public function inboundPacket(EncapsulationInterface $oPacket): void
	{
		$this->oLogger->debug("Packet type ".$oPacket->getPacketType());
		switch($oPacket->getPacketType()){
			case 'Immediate':
			case 'Unicast':
				if(array_key_exists($oPacket->getPort(),$this->aPorts)){
					$this->oLogger->debug("Unicast Packet in:  ".$oPacket->toString());
					$this->aPorts[$oPacket->getPort()]->unicastPacketIn($oPacket->buildEconetPacket());
					$aReplies = $this->aPorts[$oPacket->getPort()]->getReplies();
					foreach($aReplies as $oReply){
						$this->queueReply($oReply);	
					}	
				}
				break;
			case 'Ack':
				$this->ackEvents($oPacket);
				break;
			case 'Broadcast':
				if(array_key_exists($oPacket->getPort(),$this->aPorts)){
					$this->oLogger->debug("Broadcast Packet in:  ".$oPacket->toString());
					$this->aPorts[$oPacket->getPort()]->broadcastPacketIn($oPacket->buildEconetPacket());
					$aReplies = $this->aPorts[$oPacket->getPort()]->getReplies();
					foreach($aReplies as $oReply){
						$this->queueReply($oReply);	
					}
				}
				break;
			default:
				$this->oLogger->error("Unicast Packet in of unknown type: ".$oPacket->toString());
				break;
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
		$this->aReplies[]=$oPacket;
	}

	/**
	 * Gets all the replies for all the services
	 *
	 * @return array<int,EconetPacket>
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
		if($this->oEncapsulationTypeMap === null OR $this->oLoop === null){
			throw new \Exception("ServiceDispatcher::sendPackets() called before start()");
		}
		$oPacketDispatcher = PacketDispatcher::create($this->oEncapsulationTypeMap, $this->oLoop);
		$aReplys = $oService->getReplies();
		foreach($aReplys as $oPacket){
			$oPacketDispatcher->sendPacket($oPacket);
		}
	}

	/**
	 * Adds an event for the this ack packet the a network/station
	 *
	 * @param int $iSeq The EconetPacket::getSequence() of the specific packet this callback is
	 *                   waiting to see acked. An inbound ack from an encapsulation that carries
	 *                   its own sequence number (AUN, WebSocket, a 1.2+ remote bridge relay —
	 *                   see EncapsulationInterface::getSequence()) only fires this callback if
	 *                   its sequence matches; one from an encapsulation with no such concept
	 *                   (real hardware Econet, a pre-1.2 bridge relay) always does, exactly as
	 *                   before — see ackEvents().
	 * @param int $iTimeout Seconds houseKeeping() will wait for the ack before giving up on
	 *                       this callback and clearing it (see DEFAULT_ACK_EVENT_TIMEOUT).
	*/
	public function addAckEvent(int $iNetwork, int $iStation, int $iSeq, callable $fCallable, int $iTimeout = self::DEFAULT_ACK_EVENT_TIMEOUT): void
	{
		if(!array_key_exists($iNetwork,$this->aAckEvents)){
			$this->aAckEvents[$iNetwork]=[];
		}
		$this->aAckEvents[$iNetwork][$iStation] = ['callable'=>$fCallable,'seq'=>$iSeq,'expires'=>time()+$iTimeout];
	}

	/**
	 * Checks to see if an Ack should tirgger an event, and if so tirgger it
	 *
	 * A registration only fires for the ack of the specific packet it was registered for
	 * (matched by sequence number — see addAckEvent()); an ack for anything else found
	 * registered for this (network,station) is a stray or duplicate and is ignored, leaving
	 * the registration in place for the real one (or its timeout) to resolve it. Encapsulations
	 * with no sequence concept of their own (getSequence() returns null — real hardware Econet,
	 * a pre-1.2 bridge relay) can't be matched this way and fall back to firing on any ack for
	 * the station, exactly as before this distinction existed.
	*/
	public function ackEvents(EncapsulationInterface $oPacket): void
	{
		$oEconetPacket = $oPacket->buildEconetPacket();
		$iNetwork = $oEconetPacket->getSourceNetwork();
		$iStation = $oEconetPacket->getSourceStation();

		if($iNetwork === null OR $iStation === null){
			return;
		}

		if(array_key_exists($iNetwork,$this->aAckEvents) AND array_key_exists($iStation,$this->aAckEvents[$iNetwork])){
			$aEvent = $this->aAckEvents[$iNetwork][$iStation];
			$iIncomingSeq = $oPacket->getSequence();
			if($iIncomingSeq !== null AND $iIncomingSeq !== $aEvent['seq']){
				$this->oLogger->debug("ServiceDispatcher: ignoring stray ack seq {$iIncomingSeq} for {$iNetwork}.{$iStation}, waiting on seq {$aEvent['seq']}");
			}else{
				unset($this->aAckEvents[$iNetwork][$iStation]);
				($aEvent['callable'])($oPacket);
			}
		}

		//Also relay this ack to a remote bridge peer, if this station's network
		//is one we know is reachable only via a bridge connection — that peer
		//may have its own pending addAckEvent() for a transfer it originated
		//through us (see docs/protocols/remote-bridge.md). A network is either
		//local to us or bridge-relayed, never both, so this and the local match
		//above are not expected to both apply to the same ack.
		RemoteBridgeMap::relayAckIfKnown($iNetwork, $iStation);

		//Also relay this ack to a Remote Provider Protocol connection, if this (network,
		//station) pair is one a remotely-hosted provider most recently sent a stream block to -
		//see docs/protocols/remote-provider.md § Ack Relay and AckRelayMap::rememberAckRelay(),
		//called by RemoteProvider\RelayServer on every such send. This is what lets that
		//provider's own addAckEvent() callback - registered on its own, separate
		//ServiceDispatcher instance in another process - ever actually fire; see fireAckEvent().
		AckRelayMap::relayAckIfKnown($iNetwork, $iStation, $oPacket->getSequence());
	}

	/**
	 * Fires a registered addAckEvent() callback purely from (network, station[, sequence]) -
	 * without a real EncapsulationInterface instance to pass it. Used by RemoteProvider\Host
	 * when an ack relayed from filestored (see AckRelayMap/docs/protocols/remote-provider.md §
	 * Ack Relay) arrives for a provider hosted in this process; the genuine ack packet never
	 * reaches this process; the callback receives a synthetic RelayedAck instead of a real
	 * encapsulation. Every current callback (see FileServer's GETBYTES/PUTBYTES streaming)
	 * ignores its EncapsulationInterface argument entirely, so this is safe, but a callback
	 * should not rely on decoding real data from it.
	 *
	 * Deliberately independent of ackEvents() rather than sharing its body via delegation -
	 * that would mean a real, local ack's callback also receiving a synthetic object instead of
	 * the genuine encapsulation, changing behaviour for the path this codebase actually depends
	 * on today.
	*/
	public function fireAckEvent(int $iNetwork, int $iStation, ?int $iSeq): void
	{
		if(!array_key_exists($iNetwork,$this->aAckEvents) OR !array_key_exists($iStation,$this->aAckEvents[$iNetwork])){
			return;
		}

		$aEvent = $this->aAckEvents[$iNetwork][$iStation];
		if($iSeq !== null AND $iSeq !== $aEvent['seq']){
			$this->oLogger->debug("ServiceDispatcher: ignoring stray relayed ack seq {$iSeq} for {$iNetwork}.{$iStation}, waiting on seq {$aEvent['seq']}");
			return;
		}

		unset($this->aAckEvents[$iNetwork][$iStation]);
		($aEvent['callable'])(new RelayedAck($iNetwork, $iStation, $iSeq));
	}

	public function clearAckEvent(int $iNetwork, int $iStation):void
	{
		if(array_key_exists($iNetwork,$this->aAckEvents) AND array_key_exists( $iStation,$this->aAckEvents[$iNetwork])){
			unset($this->aAckEvents[$iNetwork][$iStation]);
		}
	}

	/** 
	 * Disables a service from receiving packets on thier service ports
	 * 
	*/ 	
	public function disableService(ProviderInterface $oService):void
	{
		$aPorts = $oService->getServicePorts();

		foreach($aPorts as $iPort){
			unset($this->aPorts[$iPort]);
		}

	}

	/** 
	 * Enables a service letting it receive packets on thier service ports
	 * 
	*/ 	
	public function enableService(ProviderInterface $oService):void
	{
		if(!in_array($oService, $this->aProviders)){
			return;
		}

		$aPorts = $oService->getServicePorts();

		foreach($aPorts as $iPort){
			$this->aPorts[$iPort]=$oService;
		}

	}

	/**
	 * Run the housekeeping tasks for all services
	*/ 
	public function houseKeeping(): void
	{
		//Run registred house keeping tasks
		foreach($this->aHouseKeepingTasks as $fTask){
			($fTask)();
		}

		//Free up timed out streaming ports by building a new list without timed out ports
		//
		//Only ports actually allocated via claimStreamPort() have an aPortTimeLimits
		//entry. A port in this numeric range that a service registered the normal way
		//(via addService()/registerService(), not claimStreamPort()) has no such entry —
		//it must be left alone here, not treated as an expired stream and unregistered.
		$aPortTimeLimits = [];
		for($i=$this->iStreamPortStart;$i<($this->iStreamPortStart+self::MAX_STREAMS);$i++){
			if(array_key_exists($i,$this->aPorts) AND array_key_exists($i,$this->aPortTimeLimits)){
				if($this->aPortTimeLimits[$i]>=time()){
					//The stream port has NOT timed out
					$aPortTimeLimits[$i]=$this->aPortTimeLimits[$i];
				}else{
					//Timed out clear the port
					unset($this->aPorts[$i]);
				}
			}
		}
		$this->aPortTimeLimits = $aPortTimeLimits;

		//Give up on ack events nothing ever replied to (e.g. a WebSocket/Piconet block that
		//was sent fire-and-forget and never acked) — without this a lost reply leaves the
		//callback, and anything it's holding open (e.g. a file handle), registered forever.
		//AUN's own retry queue (Aun\Handler) already calls clearAckEvent() itself once its
		//retries are exhausted, so this sweep is mostly a backstop for the other encapsulations.
		$iNow = time();
		foreach($this->aAckEvents as $iNetwork=>$aStations){
			foreach($aStations as $iStation=>$aAckEvent){
				if($aAckEvent['expires']<$iNow){
					$this->oLogger->warning("ServiceDispatcher: Ack event for ".$iNetwork.".".$iStation." timed out with no reply, clearing it.");
					unset($this->aAckEvents[$iNetwork][$iStation]);
				}
			}
		}

	}
} 
