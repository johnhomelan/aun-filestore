<?php
/**
 * This file contains the bridge class
 *
 * @author John Brown <john@home-lan.co.uk>
 * @package core
*/
namespace HomeLan\FileStore\Services; 

use HomeLan\FileStore\Messages\EconetPacket; 
use HomeLan\FileStore\Aun\AunPacket; 
use HomeLan\FileStore\Aun\Map; 

use config;

/**
 * This class is the interface all services must provide 
 *
 * @package core
*/
class ServiceDispatcher {


	private $aPorts = [];
	private $oLogger;
	private $aReplies = [];


	/**
	 * Constructor registers the Logger and all the services 
	 *  
	*/
	public function __construct(\Psr\Log\LoggerInterface $oLogger, array $aServices)
	{		
		$this->oLogger = $oLogger;	

		//Takes and array of serivce providers and adds them the the ServiceDispatcher so they get packets 
		foreach($aServices as $oService){
			if(in_array('\HomeLan\FileStore\Services\ServiceInterface',class_implements(get_class($oService)))){
				$oService->registerService($this);
				$aPorts = $oService->getPorts();
				foreach($aPorts as $iPort){
					$this->aPorts[$iPort]=$oService;
				}
			}
		}
	}

	/**
	 * Handles an inbound packet
	*/
	public function inboundPacket(AunPacket $oPacket)
	{
		if(array_key_exists($oPacket->getPort(),$this->aPorts)){
			switch($oPacket->getPacketType()){
				case 'Immediate':
				case 'Unicast':
					$this->oLogger->debug("Unicast Packet in:  ".$oPacket->toString());
					$this->aPorts[$$oPacket->getPort()]->unicastPacketIn($oPacket->buildEconetPacket());
					break;
				case 'Broadcast':
					$this->oLogger->debug("Broadcast Packet in:  ".$oPacket->toString());
					$this->aPorts[$$oPacket->getPort()]->broadcastPacketIn($oPacket->buildEconetPacket());
					break;
			}
			$aReplies = $this->aPorts[$$oPacket->getPort()]->getReplies();
			foreach($aReplies as $oReply){
				$oReplyEconetPacket = $oReply->buildEconetpacket();
				$this->dispatchReply($oReplyEconetPacket);	
			}
		}

	}

	private function dispatchReply(EconetPacket $oPacket)
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

	public function getReplies()
	{
		$aReplies = $this->aReplies;
		$this->aReplies = [];
		return $aReplies;
	}
} 
