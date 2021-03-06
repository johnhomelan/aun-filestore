<?php
/**
 * This file contains the aunmap class 
 *
 * @author John Brown <john@home-lan.co.uk>
 * @package corenet
*/
namespace HomeLan\FileStore\Aun; 

use config;

/**
 * This class maps ip address for the AUN protocol to econet network and station numbers.
 *
 * There are two kind of mapping, econet network number to subnet mapping and a host mapping.  The host mapping always wins
 * over the subnet mapping.
 *
 * The subnet mapping maps a econet network number to a whole class c, with the station number being the last byte of the 
 * ip address (e.g. if network num 138 has mapped to 192.168.0.0/24 the 138.40 would have the ip 192.168.0.40). 
 *
 * The host mapping is an simple network number and station number maps to a give ip
 *
 * @package corenet
*/
class Map {

	static $aHostMap = array();

	static $aSubnetMap = array();

	//Cache of the reverse ip to network.station lookup
	static $aIPLookupCache = array();

	static $aIpCounter = array();

	static $oLogger;

	/**
	 * Loads the aun map from the configured aun map file
	 *
	 * @param \Psr\Log\LoggerInterface $oLogger
	 * @param string $sMap The text for the map file can be supplied as a string, this is intended largley for unit testing this function
	*/
	public static function init(\Psr\Log\LoggerInterface $oLogger, string $sMap=NULL): void
	{
		self::$oLogger = $oLogger;
		if(is_null($sMap)){
			if(!file_exists(config::getValue('aunmap_file'))){
				self::$oLogger->info("aunmapper: The configure aunmap files does not exist.");
				return;
			}
			$sMap = file_get_contents(config::getValue('aunmap_file'));
		}
		$aLines = explode("\n",$sMap);
		foreach($aLines as $sLine){
			if(preg_match('/([0-9]*\.[0-9]*\.[0-9]*\.[0-9]*\/[0-9]*) ([0-9]*)/',$sLine,$aMatches)>0){
				Map::addSubnetMapping($aMatches[1],$aMatches[2]);
			}
			if(preg_match('/([0-9]*\.[0-9]*\.[0-9]*\.[0-9]*) ([0-9]*)\.([0-9]*)/',$sLine,$aMatches)>0){
				Map::addHostMapping($aMatches[1],$aMatches[2],$aMatches[3]);
			}
		}
	}

	/**
	 * Converts an ip address to a econet address
	 *
	 * @param string $sIP The ip address to get the econet addr for (in the form xxx.xxx.xxx.xxx)
	 * @param int $sPort We can support mapping mulitple econet address to a single host however each econet address is bound to a udp port
	 * @return string Econet address in the form network.station 
	*/
	public static function ipAddrToEcoAddr(string $sIP,int $sPort=NULL):string 
	{
		if(array_key_exists($sIP,Map::$aIPLookupCache)){
			return Map::$aIPLookupCache[$sIP];
		}

		if(in_array($sIP,Map::$aHostMap)){
			$sIndex = array_search($sIP,Map::$aHostMap);
			Map::$aIPLookupCache[$sIP]=$sIndex;
			return $sIndex;
		}

		//No host match try for a subnet match
		$aIPParts = explode('.',$sIP);

		foreach(Map::$aSubnetMap as $iNetworkNumber=>$sSubnet){
			$aSubnetParts = explode('/',$sSubnet);
			$aSubnetIPParts = explode('.',$aSubnetParts[0]);
			if($aSubnetIPParts[0]==$aIPParts[0] AND $aSubnetIPParts[1]==$aIPParts[1] AND $aSubnetIPParts[2]==$aIPParts[2]){
				Map::$aIPLookupCache[$sIP]=$iNetworkNumber.'.'.$aIPParts[3];
				return Map::$aIPLookupCache[$sIP];
			}
		}

		//No matches at all create a dynamic entry
		if(!is_null($sPort)){
			$sIP=$sIP.':'.$sPort;
		}
		Map::$aIPLookupCache[$sIP]=config::getValue('aunmap_autonet').'.'.$aIPParts[3];
		return Map::$aIPLookupCache[$sIP];
	}

	/**
	 * Converts a econet network and station number to a ip address and port 
	 *
	 * @param int $iNetworkNumber
	 * @param int $iStationNumber
	 * @return string ip address
	*/
	public static function ecoAddrToIpAddr(int $iNetworkNumber,int $iStationNumber):string
	{
		//Test to see if we are in the cached index
		$sIndex = array_search($iNetworkNumber.'.'.$iStationNumber,Map::$aIPLookupCache);
		if($sIndex !==FALSE){
			return $sIndex;
		}

		//Check the host map
		if(array_key_exists($iNetworkNumber.'.'.$iStationNumber,Map::$aHostMap)){
			//Update the cache
			Map::$aIPLookupCache[Map::$aHostMap[$iNetworkNumber.'.'.$iStationNumber]]=$iNetworkNumber.'.'.$iStationNumber;
			//Return the IP address
			return Map::$aHostMap[$iNetworkNumber.'.'.$iStationNumber];	
		}
		//Check the subnet map
		if(array_key_exists($iNetworkNumber,Map::$aSubnetMap)){
			list($sIP,$sMask) = explode("/",Map::$aSubnetMap[$iNetworkNumber]);
			$aIPParts = explode('.',$sIP);
			//Update the cache
			Map::$aIPLookupCache[$aIPParts[0].'.'.$aIPParts[1].'.'.$aIPParts[2].'.'.$iStationNumber]=$iNetworkNumber.'.'.$iStationNumber;
			//Return the IP Address
			return $aIPParts[0].'.'.$aIPParts[1].'.'.$aIPParts[2].'.'.$iStationNumber;
		}
	}

	/**
	 * Tests if a econet network is know to the aunmap
	 *
	 * @param int $iNetworkNumber
	 * @return boolean
	*/
	public static function networkKnown(int $iNetworkNumber):bool
	{
		//Check subnet map
		if(array_key_exists($iNetworkNumber,Map::$aSubnetMap)){
			return TRUE;
		}

		//Check the station map
		foreach(Map::$aHostMap as $sKey=>$sIP){
			list($iNetNumber,$iStationNumber) = explode('.',$sKey);
			if($iNetworkNumber==$iNetNumber){
				return TRUE;
			}
		}
		return FALSE;
	}

	/**
	 * Adds an entry to the host mapping table
	 *
	 * @param string $sIP The ip addr of the host to map
	 * @param int $iNetworkNumber The network number
	 * @param int $iStationNumber The station number
	*/
	public static function addHostMapping(string $sIP,int $iNetworkNumber,int $iStationNumber): void
	{
		if(preg_match('/[0-9]*\.[0-9]*\.[0-9]*\.[0-9]*/',$sIP)){
			Map::$aHostMap[$iNetworkNumber.'.'.$iStationNumber]=$sIP;
			Map::$aIPLookupCache[$sIP]=$iNetworkNumber.'.'.$iStationNumber;
		}else{
			self::$oLogger->info("aunmapper: An invaild ip was tried to be used as a aunmap entry (".$sIP.").");
		}
	}

	/**
	 * Adds an entry to the subnet mapping table 
	 *
	 * @param string $sSubnet The subnet to add the map (in the form 192.168.0.0/24)
	 * @param int $iNetworkNumber The network number
	*/
	public static function addSubnetMapping(string $sSubnet,int $iNetworkNumber): void
	{
		if(preg_match('/[0-9]*\.[0-9]*\.[0-9]*\.[0-9]*\/[0-9]*/',$sSubnet)>0){
			//Blank the reverse mapping cache 
			Map::$aIPLookupCache=array();
			Map::$aSubnetMap[$iNetworkNumber]=$sSubnet;
		}else{
			self::$oLogger->info("aunmapper: An invaild subnet was tried to be used as a aunmap entry (".$sSubnet.").");
		}
	}


	public static function setAunCounter(string $sIP,int $iCounter): void
	{
		Map::$aIpCounter[$sIP]=$iCounter;
	}

	public static function incAunCounter(string $sIP):int
	{
		if(!array_key_exists($sIP,Map::$aIpCounter)){
			Map::$aIpCounter[$sIP]=0;
		}
		Map::$aIpCounter[$sIP]=Map::$aIpCounter[$sIP]+4;
		return Map::$aIpCounter[$sIP];
	}
}
