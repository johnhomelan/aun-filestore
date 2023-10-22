<?php
/**
 * This file contains the websocket map class 
 *
 * @author John Brown <john@home-lan.co.uk>
 * @package corenet
*/
namespace HomeLan\FileStore\WebSocket; 

use config;
use Exception;
use Ratchet\ConnectionInterface;
use \Psr\Log\LoggerInterface;
/**
 * This class maps a websocket to an econet network/station number
 *
 * There are two kind of mapping, econet network number to websocket, and the network/station mapping always wins
 * over the network mapping.
 *
 * @package corenet
*/
class Map {


	/**
 	 * @var array<int, mixed[]>>
 	*/	
	static array $aDynamicNetworks = [];

	
	/**
 	 * @var array<int, mixed[]>>
 	*/
	static array $aSocketList= [];

	static LoggerInterface $oLogger;

	/**
	  * Loads the websocket map from the configured websocket map file
	  *
	  * @param string $sMap The text for the map file can be supplied as a string, this is intended largley for unit testing this function
	*/
	public static function init(LoggerInterface $oLogger, string $sMap=NULL): void
	{
		self::$oLogger = $oLogger;
		if(is_null($sMap)){
			if(!file_exists(config::getValue('websocketmap_file'))){
				self::$oLogger->info("websocketmapper: The configuration files for the websocket map does not exist.");
				return;
			}
			$sMap = file_get_contents(config::getValue('websocketmap_dynamic_network_range_file'));
		}
		$aLines = explode("\n",$sMap);
		foreach($aLines as $sLine){
			if(preg_match('/([0-9]{1,3})/',$sLine,$aMatches)>0){
				self::addDynamicRangeNetwork((int) $aMatches[1]);
			}
		}
	}

	public static function addDynamicRangeNetwork(int $iNetwork):void
	{
		if(!array_key_exists($iNetwork,self::$aDynamicNetworks)){
			self::$aDynamicNetworks[$iNetwork] = [];
		}
	}

	/**
	 * Converts an websocket handle  to a econet address
	 *
	 * @return string Econet address in the form network.station 
	*/
	public static function webSocketToEconetAddress(ConnectionInterface $oSocket):?string 
	{
		if(array_key_exists(spl_object_id($oSocket),self::$aSocketList)){
			return self::$aSocketList[spl_object_id($oSocket)]['network'].".".self::$aSocketList[spl_object_id($oSocket)]['station'];
		}
		return null;
	}

	/**
	  * Converts a econet network and station number to a ip address and port 
	  *
	  */
 	public static function ecoAddrToSocket(int $iNetworkNumber,int $iStationNumber):?ConnectionInterface
	{
		if(array_key_exists($iNetworkNumber,self::$aDynamicNetworks) AND 
			array_key_exists($iStationNumber,self::$aDynamicNetworks[$iNetworkNumber]) AND
			is_object(self::$aDynamicNetworks[$iNetworkNumber][$iStationNumber])){
			return self::$aDynamicNetworks[$iNetworkNumber][$iStationNumber];
		}
		return null;
	}

	/**
	  * Tests if a econet network is know to the map
	  *
	  * @return boolean
	  */
 	public static function networkKnown(int $iNetworkNumber):bool
	{
		//Check if it an dymanic range
		if(array_key_exists($iNetworkNumber,Map::$aDynamicNetworks)){
			return TRUE;
		}

		return FALSE;
	}


	/**
	 * Allocates to free network/station to a websocket requesting dynamicallocation
	 *
	 * @return string Econet address in the form network.station 
	*/
	public static function allocateAddress(ConnectionInterface $oSocket): string
	{
		
		if(count(self::$aDynamicNetworks)>0){
			foreach(self::$aDynamicNetworks as $iNetwork=>$aStations){
				if(count($aStations)<253){
					//There is free space 
					for($i=1;$i<254;$i++){
						if(!array_key_exists($i,$aStations)){
							self::$aDynamicNetworks[$iNetwork][$i]=$oSocket;
							self::$aSocketList[spl_object_id($oSocket)] = ['network'=>$iNetwork, 'station'=>$i, 'socket'=>$oSocket];
							return $iNetwork.".".$i;
						}
					}
				}
			}
		}
		throw new Exception("No free network/station addresses free.");
	}

	/**
 	 * Frees a dynamic network/station that was in use by a websocket
 	 * 
 	*/
	public static function freeAddress(ConnectionInterface $oSocket):bool
	{
		foreach(self::$aDynamicNetworks as $iNetwork=>$aStations){
			foreach($aStations as $iStation=>$oStationSocket){
				if(spl_object_id($oSocket) == spl_object_id($oStationSocket)){
					unset(self::$aSocketList[spl_object_id($oSocket)]);
					unset(self::$aDynamicNetworks[$iNetwork][$iStation]);
					return TRUE;
				}
			}
		}
		return FALSE;
	}

}
