<?php

/**
 * This file contains the class the implements the routing table.
 *
 * @author John Brown <john@home-lan.co.uk>
 * @package core
*/
namespace HomeLan\FileStore\Services\Provider\IPv4; 


use HomeLan\FileStore\Services\ProviderInterface;

use config;

class Routes
{

	const DEFAULT_ENTRY_METRIC=20;

	private array $aRoutes=[];

	/**
 	 * Constructor 
 	 *
	 * Will load all the routes from a string (this is mostly used for unit testing), or from the routes config file
	 */
	public function __construct(private readonly ProviderInterface $oProvider,?string $sRoutes=null)
 	{
		if(is_null($sRoutes)){
			if(!file_exists(config::getValue('ipv4_routes_file'))){
				return;
			}
			$sRoutes = file_get_contents(config::getValue('ipv4_routes_file'));
		}
		$aLines = explode("\n",$sRoutes);
		foreach($aLines as $sLine){
			//Matchs the form "192.168.4.0/255.255.255.0 192.168.0.1", also matches "192.168.4.0/255.255.255.0 192.168.0.1 50" to allow the metric to be optional.
			if(preg_match('/^([0-9]{1,3}\.[0-9]{1,3}\.[0-9]{1,3}\.[0-9]{1,3})\/([0-9]{1,3}\.[0-9]{1,3}\.[0-9]{1,3}\.[0-9]{1,3})\s+([0-9]{1,3}\.[0-9]{1,3}\.[0-9]{1,3}\.[0-9]{1,3})\s?+([0-9]+)?/',$sLine,$aMatches)>0){
				if(array_key_exists(4,$aMatches)){
					$this->addRoute($aMatches[1],$aMatches[2],$aMatches[3],(int) $aMatches[4]);
				}else{
					$this->addRoute($aMatches[1],$aMatches[2],$aMatches[3],self::DEFAULT_ENTRY_METRIC);
				}
			}
		}
	 }

	/**
	 * Adds a route to the routing table
	 * 	
	 * All ip addresses and subnets must be supplied as a dotted quad. (e.g 10.50.1.4 or 255.255.255.224) 
	*/
	public function addRoute(string $sIPv4NetworkAddr, string $sIPv4NetworkSubnet, string $sIPv4Via, int $iMetric):void
	{
		$this->aRoutes[] = ['network'=>$sIPv4NetworkAddr,'networkint'=>ip2long($sIPv4NetworkAddr),'subnet'=>$sIPv4NetworkAddr,'via'=>$sIPv4Via,'cidr'=>$this->subnetToCidr($sIPv4NetworkSubnet),'metric'=>$iMetric];
	}

	/**
	 * Get the route that should be used to an IP  
	 *
	 * Only a single route is returned which is the closest match (small subnet win, then metric is used is multple routes have the same subnet size).
	*/ 	
	public function getRoute($sIPAddr):?array
	{		
		$iIP = ip2long($sIPAddr);
		$aOrderedRoutes= [];
		foreach($this->aRoutes as $aRoute){
			if($this->networkContains($iIP,$aRoute['networkint'],$aRoute['cidr'])){
				$aOrderedRoutes[]=$aRoute;
			}
		}
		//Sort the routes (smallest cidr, followed by lowest metric for identical cidrs), if there are less than 2 routes this wont do anything
		usort($aOrderedRoutes,function($aRouteA, $aRouteB){
			if($aRouteA['cidr']!=$aRouteB['cidr']){
				return ($aRouteA['cidr'] < $aRouteB['cidr']) ? 1 : -1;
			}
			return ($aRouteB['metric'] < $aRouteA['metric']) ? 1 : -1;
		});
		return (count($aOrderedRoutes)>0) ? array_shift($aOrderedRoutes) : null;
			
	}

	/**
	 * Dumps the routing table into an array for display 
	 *
	 * Used by the admin interface to display the routing table
	*/	
	public function dumpRoutingTable():array
	{
		$aReturn = [];

		foreach($this->aRoutes as $aRoute){
			$aReturn[] = ['network'=>$aRoute['network'],'subnet'=>$aRoute['subnet'],'gw'=>$aRoute['via'],'metric'=>$aRoute['metric']];
		}
		return $aReturn;
	}

	/**
 	 * Check if an IP is contained in a subnet
 	 *
 	 * @param int $iIPAddr Ip address as a 32bit int, as provided by ip2long
 	 * @param int $iIpv4Network The network address as a 32bit int, as provided by ip2long
 	 * @param int $iMask The cidr as a number betweeen 0-32
 	*/	 
	private function networkContains(int $iIPAddr,int $iIpv4Network,int $iMask):bool
	{
		// All IP are matched by the default route 0.0.0.0/0
		if($iMask == 0 AND $iIpv4Network == 0){
			return TRUE;
		}


		if ($iMask > 0 AND $iMask < 32) {
			$iBitmask = -1 << (32 - $iMask);
			$iIpv4Network &= $iBitmask; # nb: in case the supplied subnet wasn't correctly aligned
			return ($iIPAddr & $iBitmask) == $iIpv4Network;
		}
		return false;
	}

	/**
	 * Converts a dotted quad subnet to cidr form
	 * 
	 * e.g. Converts 255.255.255.0 to 24
	*/  	
	private function subnetToCidr(string $sSubnetMask):int
	{
		if($sSubnetMask[0]==0){
			return 0;
		}
		$iSubnet = ip2long($sSubnetMask);
		$base = ip2long('255.255.255.255');
  		return 32- (int) log(($iSubnet ^ 4294967295)+1,2);
	}

	/**
	 * Get the provider using this instance of routes
	 *
	*/ 	
	public function getProvider():ProviderInterface
	{
		return $this->oProvider;
	}
	
}
