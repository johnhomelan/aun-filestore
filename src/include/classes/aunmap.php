<?
/**
 * This file contains the aunmap class 
 *
 * @author John Brown <john@home-lan.co.uk>
 * @package corenet
*/

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
class aunmap {

	static $aHostMap = array();

	static $aSubnetMap = array();

	//Cache of the reverse ip to network.station lookup
	static $aIPLookupCache = array();

	public static function lookUpByIp($sIP)
	{
		if(array_key_exists($sIP,aunmap::$aIPLookupCache)){
			return aunmap::$aIPLookupCache[$sIP];
		}

		if(in_array($sIP,aunmap::$aHostMap)){
			$sIndex = array_search($sIP,aunmap::$aHostMap);
			aunmap::$aIPLookupCache[$sIP]=$sIndex;
			return $sIndex;
		}

		//No host match try for a subnet match
		$aIPParts = explode('.',$sIP);

		foreach(aunmap::$aSubnetMap as $iNetworkNumber=>$sSubnet){
			$aSubnetParts = explode('/',$sSubnet);
			$aSubnetIPParts = explode('.',$aSubnetParts[0]);
			if($aSubnetIPParts[0]==$aIPParts[0] AND $aSubnetIPParts[1]==$aIPParts[1] AND $aSubnetIPParts[2]==$aIPParts[2]){
				aunmap::$aIPLookupCache[$sIP]=$iNetworkNumber.'.'.$aIPParts[3];
				return aunmap::$aIPLookupCache[$sIP];
			}
		}
	}

	/**
	 * Adds an entry to the host mapping table
	 *
	 * @param string $sIP The ip addr of the host to map
	 * @param int $iNetworkNumber The network number
	 * @param int $iStationNumber The station number
	*/
	public static function addHostMapping($sIP,$iNetworkNumber,$iStationNumber)
	{
		if(preg_match('/[0-9]*\.[0-9]*\.[0-9]*\.[0-9]*/',$sIP)){
			aunmap::$aHostMap[$iNetworkNumber.'.'.$iStationNumber]=$sIP;
			aunmap::$aIPLookupCache[$sIP]=$iNetworkNumber.'.'.$iStationNumber;
		}else{
			logger::log("aunmapper: An invaild ip was tried to be used as a aunmap entry (".$sIP.").",LOG_INFO);
		}
	}

	/**
	 * Adds an entry to the subnet mapping table 
	 *
	 * @param string $sSubnet The subnet to add the map (in the form 192.168.0.0/24)
	 * @param int $iNetworkNumber The network number
	*/
	public static function addSubnetMapping($sSubnet,$iNetworkNumber)
	{
		if(preg_match('/[0-9]*\.[0-9]*\.[0-9]*\.[0-9]*\/[0-9]*/',$sSubnet)>0){
			//Blank the reverse mapping cache 
			aunmap::$aIPLookupCache=array();
			aunmap::$aSubnetMap[$iNetworkNumber]=$sSubnet;
		}else{
			logger::log("aunmapper: An invaild subnet was tried to be used as a aunmap entry (".$sSubnet.").",LOG_INFO);
		}
	}

	/**
	 * Loads the aun map from the configured aun map file
	 *
	 * @param string $sMap The text for the map file can be supplied as a string, this is intended largley for unit testing this function
	*/
	public static function loadMap($sMap=NULL)
	{
		if(is_null($sMap)){
			if(!file_exists(config::getValue('aunmap_file'))){
				logger::log("aunmapper: The configure aunmap files does not exist.",LOG_INFO);
				return;
			}
			$sMap = file_get_contents(config::getValue('aunmap_file'));
		}
		$aLines = explode("\n",$sMap);
		foreach($aLines as $sLine){
			if(preg_match('/([0-9]*\.[0-9]*\.[0-9]*\.[0-9]*\/[0-9]*) ([0-9]*)/',$sLine,$aMatches)>0){
				aunmap::addSubnetMapping($aMatches[1],$aMatches[2]);
			}
			if(preg_match('/([0-9]*\.[0-9]*\.[0-9]*\.[0-9]*) ([0-9]*)\.([0-9]*)/',$sLine,$aMatches)>0){
				aunmap::addHostMapping($aMatches[1],$aMatches[2],$aMatches[3]);
			}
		}
	}
}

?>
