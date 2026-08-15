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
use HomeLan\FileStore\Messages\Dci4ArpRequest;
use HomeLan\FileStore\Messages\IPv4Request;
use HomeLan\FileStore\Messages\ArpIsAt;
use HomeLan\FileStore\Messages\ArpWhoHas; 
use HomeLan\FileStore\Messages\TCPRequest;
use HomeLan\FileStore\Messages\IcmpRequest;
use HomeLan\FileStore\Messages\IcmpEchoReply;
use HomeLan\FileStore\Messages\IcmpUnreachable;
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
 *
 * @phpstan-type IPv4PacketQueueEntry array{packets:array<int,EconetPacket>,timeout:int}
*/
class IPv4 implements ProviderInterface {

	/** @var array<int,EconetPacket> */
	protected array $aReplyBuffer = [];

	protected \Psr\Log\LoggerInterface $oLogger;

	/** @var array<string,IPv4PacketQueueEntry> */
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
		$this->oArpTable = $this->createArpCache();
		$this->oInterfaceTable = $this->createInterfaces();
		$this->oRoutingTable = $this->createRoutes();
		$this->oNat = $this->createNat();
	}

	protected function createArpCache(): Arpcache
	{
		return new Arpcache($this, $this->oLogger);
	}

	protected function createInterfaces(?string $sConfig = null): Interfaces
	{
		return new Interfaces($this, $this->oLogger, $sConfig);
	}

	protected function createRoutes(?string $sConfig = null): Routes
	{
		return new Routes($this, $this->oLogger, $sConfig);
	}

	protected function createNat(?string $sConfig = null): NAT
	{
		return new NAT($this, $this->oLogger, $sConfig);
	}

	private function addReplyToBuffer(EconetPacket $oReply): void
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
	 * @return array<int,int>
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
		//Deal with arp requests (0x21 = DCI-2/AUN, 0xA1 = DCI-4 native Econet)
		if($oPacket->getFlags()==0x21 || $oPacket->getFlags()==0xA1){
			$oArpReqeust = $oPacket->getFlags()==0xA1
				? new Dci4ArpRequest($oPacket,$this->oLogger)
				: new ArpRequest($oPacket,$this->oLogger);
			$this->oArpTable->addEntry($oArpReqeust->getSourceNetwork(),$oArpReqeust->getSourceStation(),$oArpReqeust->getSourceIP());  //Store the requesting station's ip details in the arp cache.
			$this->oLogger->debug("Arp reqeuest recevived via broadcast for ".$oArpReqeust->getRequestedIP());

			if($this->oInterfaceTable->isInterfaceIP($oArpReqeust->getRequestedIP())){  //Only reply if the arp request is for an interface IP addr

				//Get the details for the relevant interface
				$aIf = $this->oInterfaceTable->getInterfaceFor($oArpReqeust->getRequestedIP());

				//Build the reply in the same dialect (DCI-2 or DCI-4) as the request
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
			case 0x01:
			case 0x81: //Also accept native Econet DCI-4 flag value
				//Regular IPv4 Frame
				$this->oLogger->debug("IPv4 packet received.");

				if($oPacket->getSourceNetwork() === null || $oPacket->getSourceStation() === null){
					$this->oLogger->debug("IPv4: dropping packet with no resolvable source network/station");
					return;
				}

				try {
					$oIPv4 = new IPv4Request($oPacket,$this->oLogger);
				}catch(\Exception $oException){
					//If the IPv4 packet is invalid log an perform no more processing on it (effetively dropping the packet)
					$this->oLogger->debug($oException->getMessage());
					return;
				}
				$this->oArpTable->addEntry($oPacket->getSourceNetwork(),$oPacket->getSourceStation(),$oIPv4->getSrcIP()); //Adds an entry to the arp cache

				//If the IP is for this machine respond
				if($this->oInterfaceTable->isInterfaceIP($oIPv4->getDstIP())){
					if($oIPv4->getProtocol() === 'ICMP'){
						$this->handleIcmpForInterface($oIPv4, $oPacket);
					}
					break;
				};
				//Deal with NAT
				try {
					if($this->oNat->isNatTarget($oIPv4->getDstIP())){
						$this->oNat->processNatPacket($oIPv4, new TCPRequest($oPacket, $this->getLogger()));
						break; // NAT handled it; do not also forward via the normal IP path
					}

				}catch(\Exception $oException){
					$this->oLogger->debug($oException->getMessage());
					return;
				}

				$this->processUnicastIPv4Pkt($oIPv4,$oPacket);


				break;
			case 0x22: //ECOTYPE_ARP_REPLY (DCI-2/AUN value)
			case 0xA2: //ECOTYPE_ARP_REPLY (DCI-4 native Econet value)

				$this->oLogger->debug("Arp response packet received");
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


	public function processUnicastIPv4Pkt(IPv4Request $oIPv4,EconetPacket $oPacket): void
	{
		//Forward the IP packet
		try {
			//First see if we have an interface thats on correct subnet
			$aInterface = $this->oInterfaceTable->getInterfaceFor($oIPv4->getDstIP());
			
			try{
				$aEconetDst = $this->oArpTable->getNetworkAndStation($oIPv4->getDstIP());
				$oEconetPacket = $oIPv4->forward($aEconetDst['network'],$aEconetDst['station'],$aInterface['network'],$aInterface['station']);
				$this->oLogger->debug("IPv4: Adding packet to output buffer for ".$oIPv4->getDstIP()." ".$aEconetDst['network'].".".$aEconetDst['station']);
				$this->addReplyToBuffer($oEconetPacket);
			}catch(ArpEntryNotFound $oNotfound){
				//The address is not in the arp cache send the arp request, and queue the packet after setting 
				//its source l2 address as the interface that will send the packet once the arp response arrives 

				$oPacket->setSourceNetwork($aInterface['network']);
				$oPacket->setSourceStation($aInterface['station']);
				
				$oArpWhoHas = new ArpWhoHas($aInterface['ipaddr'],$oIPv4->getDstIP(),$aInterface['network'],$aInterface['station']);
				$this->addReplyToBuffer($oArpWhoHas->buildEconetpacket());
				$this->oLogger->debug("IPv4: Adding packet to queue waiting for ARP ".$oIPv4->getDstIP());
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
						$this->oLogger->debug("IPv4: Adding packet to output buffer for ".$oIPv4->getDstIP());
						$this->addReplyToBuffer($oEconetPacket);
					}catch(ArpEntryNotFound $oNotfound){
						//The l2 address of the router is not in the apr cache, send the  arp request, and queue the packet after setting
						//its source l2 address as the interface that will send the packet once the arp response arrives
						//
						$oPacket->setSourceNetwork($aInterface['network']);
						$oPacket->setSourceStation($aInterface['station']);
						$oArpWhoHas = new ArpWhoHas($aInterface['ipaddr'],$aRoute['via'],$aInterface['network'],$aInterface['station']);
						$this->addReplyToBuffer($oArpWhoHas->buildEconetpacket());
						$this->oLogger->debug("IPv4: Adding packet to queue waiting for ARP ".$aRoute['via']);
						$this->queuePacketWaitingOnArp($aRoute['via'],$oPacket);
					}
				}catch(InterfaceNotFound $oNotfound){
					//The route specifies a gateway we have no interface for; send network unreachable.
					$this->oLogger->debug("IPv4: No interface for route gateway, sending ICMP net unreachable");
					$this->sendNetworkUnreachable($oIPv4, $oPacket);
				}
			}else{
				//No route to the destination; send network unreachable.
				$this->oLogger->debug("IPv4: No route to ".$oIPv4->getDstIP().", sending ICMP net unreachable");
				$this->sendNetworkUnreachable($oIPv4, $oPacket);
			}
		}

	}

	private function handleIcmpForInterface(IPv4Request $oIPv4, EconetPacket $oPacket): void
	{
		if($oPacket->getSourceNetwork() === null || $oPacket->getSourceStation() === null){
			return;
		}

		$oIcmp = new IcmpRequest($oPacket, $this->oLogger);
		if (!$oIcmp->isEchoRequest()) {
			return;
		}
		$aIface = $this->oInterfaceTable->getInterfaceFor($oIPv4->getDstIP());

		$oReply = new IcmpEchoReply();
		$oReply->setSrcIP($oIPv4->getDstIP());
		$oReply->setDstIP($oIPv4->getSrcIP());
		$oReply->setSrcStation($aIface['station']);
		$oReply->setSrcNetwork($aIface['network']);
		$oReply->setDstStation($oPacket->getSourceStation());
		$oReply->setDstNetwork($oPacket->getSourceNetwork());
		$oReply->setId($oIcmp->getId());
		$oReply->setSequence($oIcmp->getSequence());
		$oReply->setData($oIcmp->getEchoData());
		$this->oLogger->debug("IPv4: Sending ICMP echo reply to ".$oIPv4->getSrcIP());
		$this->addReplyToBuffer($oReply->buildEconetpacket());
	}

	private function sendNetworkUnreachable(IPv4Request $oIPv4, EconetPacket $oPacket): void
	{
		if($oPacket->getSourceNetwork() === null || $oPacket->getSourceStation() === null){
			return;
		}

		try {
			$aIface = $this->oInterfaceTable->getInterfaceFor($oIPv4->getSrcIP());
		} catch (InterfaceNotFound $e) {
			return; // can't determine which interface to reply from
		}

		$oReply = new IcmpUnreachable(IcmpUnreachable::NET_UNREACHABLE);
		$oReply->setSrcIP($aIface['ipaddr']);
		$oReply->setDstIP($oIPv4->getSrcIP());
		$oReply->setSrcStation($aIface['station']);
		$oReply->setSrcNetwork($aIface['network']);
		$oReply->setDstStation($oPacket->getSourceStation());
		$oReply->setDstNetwork($oPacket->getSourceNetwork());
		$oReply->setOriginalPacket($oPacket->getData() ?? '');
		$this->addReplyToBuffer($oReply->buildEconetpacket());
	}

	public function registerService(ServiceDispatcher $oServiceDispatcher): void
	{
		$_this = $this;
		$oServiceDispatcher->addHousingKeepingTask(function() use ($_this){
			$_this->houseKeeping();
		});

		//Need to reference the service dispatcher so NAT can gain access to the event loop, to add its own socket handles 
		$this->oNat->registerService($oServiceDispatcher);
		$this->oLogger->debug("Registering the IPv4 service.");
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
				$oPacket->setDestinationStation($aAddress['station']);
				$oPacket->setDestinationNetwork($aAddress['network']);
				$this->addReplyToBuffer($oPacket);
			}
			unset($this->aPacketQueue[$sIP]);
		}
	}

	public function queuePacketWaitingOnArp(string $sIP, EconetPacket $oPacket):void
	{
		if(array_key_exists($sIP,$this->aPacketQueue)){
			$aEntry = $this->aPacketQueue[$sIP];
			$aEntry['packets'][] = $oPacket;
		}else{
			$aEntry = ['packets'=>[$oPacket],'timeout'=>0];
		}
		$aEntry['timeout'] = time()+ self::DEFAULT_ARP_WAIT_TIMEOUT;
		$this->aPacketQueue[$sIP] = $aEntry;
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
		$aReplies = $this->aReplyBuffer;
		$this->aReplyBuffer = [];
		return $aReplies;
	}

	/**
	 * @return array<int,array<string,mixed>>
	*/
	public function getJobs():array
	{
		return [];
	}

	/**
	 * @return array<int,array{network:int,station:int,ipv4:string,timeout:int}>
	*/
	public function getArpEntries(): array
	{
		return $this->oArpTable->dumpArpCache();
	}

	/**
	 * @return array<int,array{network:int,station:int,ipaddr:string,mask:string}>
	*/
	public function getInterfaces(): array
	{
		return $this->oInterfaceTable->dumpInterfaceTable();
	}

	/**
	 * @return array<int,array{network:string,subnet:string,gw:string,metric:int}>
	*/
	public function getRoutes(): array
	{
		return $this->oRoutingTable->dumpRoutingTable();
	}

	/**
	 * @return array<int,array{ip_from:string,ip_to:string,port_from:int,port_to:int}>
	*/
	public function getNatEntries(): array
	{
		return $this->oNat->dumpNatTable();
	}

	/**
	 * @return array<string,array<string,mixed>>
	*/
	public function getConnTrack(): array
	{
		return $this->oNat->dumpConnTrack();
	}
}
