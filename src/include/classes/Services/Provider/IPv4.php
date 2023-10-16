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
use HomeLan\FileStore\Messages\ArpRequest; 
use HomeLan\FileStore\Messages\IPv4Request; 
use HomeLan\FileStore\Messages\ArpIsAt; 
use HomeLan\FileStore\Services\Provider\IPv4\Arpcache;
use HomeLan\FileStore\Services\Provider\IPv4\Interfaces;
use HomeLan\FileStore\Services\Provider\IPv4\Routes;
use HomeLan\FileStore\Services\Provider\IPv4\NAT;
use HomeLan\FileStore\Services\Provider\IPv4\Exceptions\InterfaceNotFound;
use HomeLan\FileStore\Services\Provider\IPv4\Exceptions\ArpEntryNotFound;
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

	private array $aPacketQueue = [];
	private Arpcache $oArpTable;
	private Interfaces $oInterfaceTable;
	private Routes $oRoutingTable;
	private NAT $oNat;

	//Default time to hold IPv4 packets, waiting for an apr response in seconds 
	const DEFAULT_ARP_WAIT_TIMEOUT = 30;

	/**
	 * Initializes the service
	 *
	*/
	public function __construct(\Psr\Log\LoggerInterface $oLogger)
	{
		$this->oLogger = $oLogger;
		$this->oArpTable = new Arpcache($this);
		$this->oInterfaceTable = new Interfaces($this);
		$this->oRoutingTable = new Routes($this);
		$this->oNat = new NAT($this);
	}

	private function addReplyToBuffer($oReply): void
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

	public function getLogger():\Psr\Log\LoggerInterface
	{
		return $this->oLogger;
	}


	/** 
	 * Arp who has is the only messsage we deal with via broadcast (the 8byte limit for data means their is not much else we can do with it).
	 *
	*/
	public function broadcastPacketIn(EconetPacket $oPacket): void
	{
		//Deal with arp requests
		if($oPacket->getFlags()==0xA1){ //In EconetA IPv4, the flag is used to indicate the type of packet 0xA1 is arp request

			$oArpReqeust = new ArpRequest($oPacket,$this->oLogger);
			$this->oArpTable->addEntry($oArpReqeust->getSourceNetwork(),$oArpReqeust->getSourceStation(),$oArpReqeust->getSourceIP());  //Store the requestion stations ip details in the arp cache.

			if($this->oInterfaceTable->isInterfaceIP($oArpReqeust->getRequestedIP())){  //Only reply if the arp request if for an interface IP addr 

				//Get the detials for the relivent interface 
				$aIf = $this->oInterfaceTable->getInterfaceFor($oArpReqeust->getRequestedIP());

				//Create the arp response (this automatically fills the ip details)
				$oReply = $oArpReqeust->buildReply();

				//Create the econet packet, and set the hw address for the source to be that of the correct interface 
				$oReplyPacket= $oReply->buildEconetpacket();
				$oReplyPacket->setSourceStation($aIf['station']);
				$oReplyPacket->setSourceNetwork($aIf['network']);

				//Add the packet to the buffer for dispatch  (this is the only example where we don't have to grab the targets hw address from the arpcache)
				$this->addReplyToBuffer($oReplyPacket);
			}
			
		}
		
	}

	/** 
	 * Most regular IP packets comes via unicast (given econet limits broadcast to 8 bytes)
	 *
	*/
	public function unicastPacketIn(EconetPacket $oPacket): void
	{
		switch($oPacket->getFlags()){
			case 0x1:
				//Regular IPv4 Frame
				try {
					$oIPv4 = new IPv4Request($oPacket,$this->oLogger);
				}catch(\Exception $oException){
					//If the IPv4 packet is invalid log an perform no more processing on it (effetively dropping the packet)
					$this->oLogger->debug($oException->getMessage());
					return;
				}

				//If the IP is for this machine respond
				if($this->oInterfaceTable->isInterfaceIP($oIPv4->getDstIP())){
					//@TODO Deal with ICMP echo request 
					//@TODO Send icmp host unreachable for all other message types 
					break;
				};
				//Deal with NAT
				try {
					if($this->oNat->isNatTarget($oIPv4->getDstIP())){
						$this->oNat->processNatPacket($oIPv4);
					}

				}catch(\Exception $oException){
					$this->oLogger->debug($oException->getMessage());
					return;
				}

				$this->processUnicastIPv4Pkt($oIPv4,$oPacket);


				break;
			case 0xA2: //ECOTYPE_ARP_REPLY
				//Arp: We never forward arp packets as they should not leave the layer 2 network they are on, so we only update the arp cache.
				//
				//     NB: We don't care if we made an arp request this is a response to, as promiscuous arp is a thing. 

				$oArpIsAt = new ArpIsAt($oPacket,$this->oLogger);
				$this->oArpTable->addEntry($oArpIsAt->getSourceNetwork(),$oArpIsAt->getSourceStation(),$oArpIsAt->getSourceIP());
				
				//Dispatch any IPv4 Packets waiting on the arp response for this IP
				$this->dequeueWaitingPackets($oArpIsAt->getSourceIP());
				break;	
		}
	}


	public function processUnicastIPv4Pkt(IPv4Request $oIPv4,EconetPacket $oPacket)
	{
		//Forward the IP packet
		try {
			//First see if we have an interface thats on correct subnet
			$aInterface = $this->oInterfaceTable->getInterfaceFor($oIPv4->getDstIP());
			
			try{
				$aEconetDst = $this->oArpTable->getNetworkAndStation($oIPv4->getDstIP());
				$oEconetPacket = $oIPv4->forward($aEconetDst['network'],$aEconetDst['station'],$aInterface['network'],$aInterface['station']);
				$this->addReplyToBuffer($oEconetPacket);
			}catch(ArpEntryNotFound $oNotfound){
				//The address is not in the arp cache send the arp request, and queue the packet after setting 
				//its source l2 address as the interface that will send the packet once the arp response arrives 

				$oPacket->setSourceNetwork($aInterface['network']);
				$oPacket->setSourceStation($aInterface['station']);
				$this->queuePacketWaitingOnArp($oIPv4->getDstIP(),$oPacket);

			}
		}catch (InterfaceNotFound $oNotfound){
			//See if we have a route to the subnet 
			$aRoute = $this->oRoutingTable->getRoute($oIPv4->getDstIP());
			if(!is_null($aRoute)){
				try {
					//Get the interface used to talk to the router
					$aInterface = $this->oInterfaceTable->getInterfaceFor($aRoute['via']);
					try {
						$aEconetDst = $this->oArpTable->getNetworkAndStation($aRoute['via']);
						$oEconetPacket = $oIPv4->forward($aEconetDst['network'],$aEconetDst['station'],$aInterface['network'],$aInterface['station']);
						$this->addReplyToBuffer($oEconetPacket);
					}catch(ArpEntryNotFound $oNotfound){
						//The l2 address of the router is not in the apr cache, send the  arp request, and queue the packet after setting
						//its source l2 address as the interface that will send the packet once the arp response arrives
						//
						$oPacket->setSourceNetwork($aInterface['network']);
						$oPacket->setSourceStation($aInterface['station']);
						$this->queuePacketWaitingOnArp($aRoute['via'],$oPacket);
					}
				}catch(InterfaceNotFound $oNotfound){
					//There is no interface with an ip in the same subnet as the router.
					//Therefor its a invaild route the packet can't be forwarded, so it will be dropped.
					//
					//@TODO It should sened ICMP network unreachable
				}
			}
		}

	}

	public function registerService(ServiceDispatcher $oServiceDispatcher): void
	{
		$_this = $this;
		$oServiceDispatcher->addHousingKeepingTask(function() use ($_this){
			$_this->houseKeeping();
		});

		//Need to reference the service dispatcher so NAT can gain access to the event loop, to add its own socket handles 
		$this->oNat->registerService($oServiceDispatcher);
	}

	public function houseKeeping():void
	{
		$aQueseToBeDropped = [];
		$iTime = time();
		//See which queses are over due
		foreach($this->aPacketQueue as $sIP => $aQueue){
			if($aQueue['timeout']<$iTime){
				$aQueseToBeDropped[] = $sIP;
			}
		}

		//Delete the overdue queses (assuming there are any)
		if(count($aQueseToBeDropped)>0){
			foreach($aQueseToBeDropped as $sIP){
				unset($this->aPacketQueue[$sIP]);
			}
		}
	}

	public function dequeueWaitingPackets(string $sIP):void
	{
		if(array_key_exists($sIP,$this->aPacketQueue)){
			$aAddress = $this->oArpTable->getNetworkAndStation($sIP);
			foreach($this->aPacketQueue[$sIP]['packets'] as $oPacket){
				$oPacket->setStation($aAddress['station']);
				$oPacket->setNetwork($aAddress['network']);
				$this->addReplyToBuffer($oPacket);
			}
			unset($this->aPacketQueue[$sIP]);
		}
	}

	public function queuePacketWaitingOnArp(string $sIP, EconetPacket $oPacket):void
	{
		if(array_key_exists($sIP,$this->aPacketQueue)){
			$this->aPacketQueue[$sIP]['packets'][] = $oPacket;
		}else{
			$this->aPacketQueue[$sIP] = [];
			$this->aPacketQueue[$sIP]['packets'] = [$oPacket];
		}
			
		$this->aPacketQueue[$sIP]['timeout'] = time()+ self::DEFAULT_ARP_WAIT_TIMEOUT;
	}

	/**
	 * Retreives all the reply objects built by the bridge 
	 *
	 * This method removes the replies from the buffer 
	*/
	public function getReplies(): array
	{
		return $this->aReplyBuffer;
	}

	public function getJobs():array
	{
		return [];
	}

	public function getArpEntries(): array
	{
		return $this->oArpTable->dumpArpCache();
	}

	public function getInterfaces(): array
	{
		return $this->oInterfaceTable->dumpInterfaceTable();
	}

	public function getRoutes(): array
	{
		return $this->oRoutingTable->dumpRoutingTable();
	}

	public function getNatEntries(): array
	{
		return $this->oNat->dumpNatTable();
	}

	public function getConnTrack(): array
	{
		return $this->oNat->dumpConnTrack();
	}
}
