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
use config;

/**
 * This class deals with passing packets into all regisitered service 
 *
 * @package core
*/
class EncapsulationTypeMap {

	static private $oSingleton;

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
		return 'AUN';
	}

} 
