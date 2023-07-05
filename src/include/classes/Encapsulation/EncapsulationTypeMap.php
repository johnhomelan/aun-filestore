<?php
/**
 * This file contains the EncapsulationTypeMap class
 *
 *
 * @author John Brown <john@home-lan.co.uk>
 * @package core
*/
namespace HomeLan\FileStore\Encapsulation; 

use HomeLan\FileStore\Messages\EconetPacket; 
use HomeLan\FileStore\WebSocket\Map as WebSocketMap; 
use HomeLan\FileStore\Piconet\Map as PiconetMap; 
use HomeLan\FileStore\Aun\Map as AunMap; 
use config;

/**
 * This class deals with passing packets into all regisitered service 
 *
 * @package core
*/
class EncapsulationTypeMap {

	static private ?\HomeLan\FileStore\Encapsulation\EncapsulationTypeMap $oSingleton = null;

	/**
	 * Keeping this class as a singleton, this is static method should be used to get references to this object
	 *
	*/
	public static function create()
	{
		if(!is_object(EncapsulationTypeMap::$oSingleton)){
			EncapsulationTypeMap::$oSingleton = new EncapsulationTypeMap();
		}
		return EncapsulationTypeMap::$oSingleton;	
	}

	/**
	 *  
	*/
	public function __construct()
	{		
	}

	public function getType(EconetPacket $oPacket) :string
	{
		$iDstStation = $oPacket->getDestinationStation();
		$iDstNetwork = $oPacket->getDestinationNetwork();
		if(is_object(WebSocketMap::ecoAddrToSocket($iDstNetwork, $iDstStation))){
			return 'WebSocket';
		}
		if(PiconetMap::networkKnown($iDstNetwork)){
			return 'Piconet';
		}
		return 'AUN';
	}

} 
