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
use HomeLan\FileStore\Aun\Map as AunMap; 
use HomeLan\FileStore\WebSocket\Map as WebSocketMap; 
use HomeLan\FileStore\Piconet\Handler as PiconetHandler;
use React\Datagram\Socket;

use config;

/**
 * This class deals with passing packets into all regisitered service 
 *
 * @package core
*/
class PacketDispatcher {

	static private ?\HomeLan\FileStore\Encapsulation\PacketDispatcher $oSingleton = null;

	/**
	 * Keeping this class as a singleton, this is static method should be used to get references to this object
	 *
	*/
	public static function create(EncapsulationTypeMap $oEncapsulationTypeMap, \React\EventLoop\LoopInterface $oLoop, Socket $oAunServer,PiconetHandler $oPiconet):PacketDispatcher
	{
		if(!is_object(PacketDispatcher::$oSingleton)){
			PacketDispatcher::$oSingleton = new PacketDispatcher($oEncapsulationTypeMap, $oLoop, $oAunServer, $oPiconet);
		}
		return PacketDispatcher::$oSingleton;	
	}

	/**
	 * Constructor registers the Logger and all the services 
	 *  
	*/
	public function __construct(private readonly EncapsulationTypeMap $oEncapsulationTypeMap, private readonly \React\EventLoop\LoopInterface $oLoop, private readonly Socket $oAunServer, private readonly PiconetHandler $oPiconet)
 	{
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
			case 'WebSocket':
				$oWebsocket = WebsocketMap::ecoAddrToSocket($oPacket->getDestinationNetwork(),$oPacket->getDestinationStation());
				$oWebsocket->send($oPacket->getWebSocketFrame());
				break;
			case 'Piconet':
				$this->oPiconet->send($oPacket);
				break;
			case 'AUN':
			default:
				$sIP = AunMap::ecoAddrToIpAddr($oPacket->getDestinationNetwork(),$oPacket->getDestinationStation());
				if(!str_contains($sIP,':')){
					$sHost=$sIP.':'.config::getValue('aun_default_port');
				}else{
					$sHost=$sIP;
				}
				$sAunFrame = $oPacket->getAunFrame();
				if(strlen($sAunFrame)>0){
					$this->oAunServer->send($oPacket->getAunFrame(),$sHost);
				}
				break;
		}
		
	}

} 
