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
use HomeLan\FileStore\Piconet\Map as PiconetMap;
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
	public static function create(EncapsulationTypeMap $oEncapsulationTypeMap, \React\EventLoop\LoopInterface $oLoop):PacketDispatcher
	{
		if(!is_object(PacketDispatcher::$oSingleton)){
			PacketDispatcher::$oSingleton = new PacketDispatcher($oEncapsulationTypeMap, $oLoop);
		}
		return PacketDispatcher::$oSingleton;	
	}

	/**
	 * Constructor registers the Logger and all the services 
	 *  
	*/
	public function __construct(private readonly EncapsulationTypeMap $oEncapsulationTypeMap, private readonly \React\EventLoop\LoopInterface $oLoop)
 	{
		
 	}

	/**
	 * Gets a reference to the main event loop
	 *
	 * @TODO Fileserver needs updating so this is nolonger needed 
	*/
	public function getLoop():\React\EventLoop\LoopInterface
	{
		return $this->oLoop;
	}


	/**
	 * Sends all the packets a Service has queued up
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
				$oPiconet = PiconetMap::ecoAddrToHandler($oPacket->getDestinationNetwork(),$oPacket->getDestinationStation());
				if(!is_null($oPiconet)){
					$oPiconet->send($oPacket);
				}
				break;
			case 'AUN':
			default:
				$sAunFrame = $oPacket->getAunFrame();
				if(strlen($sAunFrame)>0){
					//Use a timer to delay the aun packet, this is allows all server I/O to be async, where as usleep would break the model.
					$oAunServer = AunMap::getHandler();
					$oAunServer->send($oPacket);
				}
				break;
		}
		
	}

} 
