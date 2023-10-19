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

	static $aHostMap = [];

	static $aSubnetMap = [];

	//Cache of the reverse ip to network.station lookup
	static $aIPLookupCache = [];

	static $aIpCounter = [];

	static $oLogger;

	/**
	  * Loads the aun map from the configured aun map file
	  *
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
				Map::addSubnetMapping($aMatches[1],(int) $aMatches[2]);
			}
			if(preg_match('/([0-9]*\.[0-9]*\.[0-9]*\.[0-9]*) ([0-9]*)\.([0-9]*)/',$sLine,$aMatches)>0){
				Map::addHostMapping($aMatches[1],(int) $aMatches[2],(int) $aMatches[3]);
			}
			if(preg_match('/([0-9]*\.[0-9]*\.[0-9]*\.[0-9]*):([0-9]*) ([0-9]*)\.([0-9]*)/',$sLine,$aMatches)>0){
				Map::addHostMapping($aMatches[1],(int) $aMatches[3],(int) $aMatches[4],(int) $aMatches[2]);
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
		//Check if there is a map in the fast cache 
		if(array_key_exists($sIP.':'.$sPort, Map::$aIPLookupCache)){
			return Map::$aIPLookupCache[$sIP.':'.$sPort];
		}
		if(array_key_exists($sIP,Map::$aIPLookupCache)){
			return Map::$aIPLookupCache[$sIP];
		}

		//Search the map see if there is a host:port mapping
		if(in_array($sIP.':'.$sPort,Map::$aHostMap)){
			$sIndex = array_search($sIP,Map::$aHostMap,true);
			Map::$aIPLookupCache[$sIP.':'.$sPort]=$sIndex;
			return $sIndex;
		}

		//Search the map see if there is a host mapping
		if(in_array($sIP,Map::$aHostMap)){
			$sIndex = array_search($sIP,Map::$aHostMap,true);
			Map::$aIPLookupCache[$sIP]=$sIndex;
			return $sIndex;
		}

		//No host match try for a subnet match
		$aIPParts = explode('.',$sIP);

		foreach(Map::$aSubnetMap as $iNetworkNumber=>$sSubnet){
			$aSubnetParts = explode('/',(string) $sSubnet);
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
	  * @return string ip address
	 */
	public static function ecoAddrToIpAddr(int $iNetworkNumber,int $iStationNumber):string
	{
		//Test to see if we are in the cached index
		$sIndex = array_search($iNetworkNumber.'.'.$iStationNumber,Map::$aIPLookupCache,true);
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
			[$sIP, $sMask] = explode("/",(string) Map::$aSubnetMap[$iNetworkNumber]);
			$aIPParts = explode('.',$sIP);
			//Update the cache
			Map::$aIPLookupCache[$aIPParts[0].'.'.$aIPParts[1].'.'.$aIPParts[2].'.'.$iStationNumber]=$iNetworkNumber.'.'.$iStationNumber;
			//Return the IP Address
			return $aIPParts[0].'.'.$aIPParts[1].'.'.$aIPParts[2].'.'.$iStationNumber;
		}
		return '';
	}

	/**
	  * Tests if a econet network is know to the aunmap
	  *
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
			[$iNetNumber, $iStationNumber] = explode('.',(string) $sKey);
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
	public static function addHostMapping(string $sIP,int $iNetworkNumber,int $iStationNumber, ?int $iPort=null ): void
	{
		if(preg_match('/[0-9]*\.[0-9]*\.[0-9]*\.[0-9]*/',$sIP)){
			if(is_null($iPort)){
				Map::$aHostMap[$iNetworkNumber.'.'.$iStationNumber]=$sIP;
				Map::$aIPLookupCache[$sIP]=$iNetworkNumber.'.'.$iStationNumber;
			}else{
				Map::$aHostMap[$iNetworkNumber.'.'.$iStationNumber]=$sIP.":".$iPort;
				Map::$aIPLookupCache[$sIP.':'.$iPort]=$iNetworkNumber.'.'.$iStationNumber;
			}
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
			Map::$aIPLookupCache=[];
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
