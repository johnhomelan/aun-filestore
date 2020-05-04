<?php
/**
 * This file contains the PacketDispatcher class
 *
 *
 * @author John Brown <john@home-lan.co.uk>
 * @package core
*/
namespace HomeLan\FileStore\Encapsulation; 

use HomeLan\FileStore\Encapsulation\EncapsulationTypeMap;
use HomeLan\FileStore\Messages\EconetPacket; 
use HomeLan\FileStore\Aun\AunPacket; 
use HomeLan\FileStore\Aun\Map; 

use config;

/**
 * This class deals with passing packets into all regisitered service 
 *
 * @package core
*/
class PacketDispatcher {

	static private $oSingleton;
	private $oEncapsulationTypeMap;
	private $oLoop;
	private $oAunServer;

	/**
	 * Keeping this class as a singleton, this is static method should be used to get references to this object
	 *
	*/
	public static function create(EncapsulationTypeMap $oEncapsulationTypeMap, \React\EventLoop\LoopInterface $oLoop, \React\Datagram\Socket $oAunServer)
	{
		if(!is_object(PacketDispatcher::$oSingleton)){
			PacketDispatcher::$oSingleton = new PacketDispatcher($oEncapsulationTypeMap, $oLoop, $oAunServer);
		}
		return PacketDispatcher::$oSingleton;	
	}

	/**
	 * Constructor registers the Logger and all the services 
	 *  
	*/
	public function __construct(EncapsulationTypeMap $oEncapsulationTypeMap, \React\EventLoop\LoopInterface $oLoop, \React\Datagram\Socket $oAunServer)
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
	 * Sends all the packets a Service has queues up
	 *
	*/
	public function sendPacket(EconetPacket $oPacket): void
	{
		//Get the packets destination encapsulation
		
		switch($this->oEncapsulationTypeMap->getType($oPacket)){
			case 'WEBSOCKET':
				//@TODO
				break;
			case 'AUN':
			default:
				$sIP = Map::ecoAddrToIpAddr($oPacket->getDestinationNetwork(),$oPacket->getDestinationStation());
				if(strpos($sIP,':')===FALSE){
					$sHost=$sIP.':'.config::getValue('aun_default_port');
				}else{
					$sHost=$sIP;
				}
				$this->oAunServer->send($oPacket->getAunFrame(),$sHost);
				break;
		}
		
	}

} 
