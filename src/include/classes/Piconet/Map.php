<?php
/**
 * This file contains the piconet map class 
 *
 * @author John Brown <john@home-lan.co.uk>
 * @package corenet
*/
namespace HomeLan\FileStore\Piconet;

use config;
use Exception;
use Ratchet\ConnectionInterface;
use \Psr\Log\LoggerInterface;
/**
 * This class maps a which networks are accessed via the piconet interface (max 8 networks)
 *
 * @package corenet
*/
class Map {


	/**
 	 * @var array <int>
 	*/
	static array $aNetworks = [];

	static LoggerInterface $oLogger;

	/**
	  * Loads the aun map from the configured aun map file
	  *
	  * @param string $sMap The text for the map file can be supplied as a string, this is intended largley for unit testing this function
	*/
	public static function init(LoggerInterface $oLogger, string $sMap=NULL): void
	{
		self::$oLogger = $oLogger;
		if(is_null($sMap)){
			if(!file_exists(config::getValue('piconetmap_file'))){
				self::$oLogger->info("piconetmapper: The configuration files for the piconet map does not exist.");
				return;
			}
			$sMap = file_get_contents(config::getValue('piconetmap_file'));
		}
		$aLines = explode("\n",$sMap);
		foreach($aLines as $sLine){
			if(preg_match('/([0-9]{1-3})/',$sLine,$aMatches)>0){
				self::addNetwork((int) $aMatches[1]);
			}
		}
	}

	public static function addNetwork(int $iNetwork):void
	{
		if(!in_array($iNetwork,self::$aNetworks)){
			self::$aNetworks[]=$iNetwork;
		}
	}


	/**
	  * Tests if a econet network is know to the map
	  *
	  * @return boolean
	  */
 	public static function networkKnown(int $iNetworkNumber):bool
	{
		//Check if it an dymanic range
		if(in_array($iNetworkNumber,self::$aNetworks)){
			return TRUE;
		}
		return FALSE;
	}

}
