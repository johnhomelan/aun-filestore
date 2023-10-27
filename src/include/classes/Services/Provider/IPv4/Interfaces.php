<?php

/**
 * This file contains the class the implements the IPv4 host interface logic
 *
 * @author John Brown <john@home-lan.co.uk>
 * @package core
*/
namespace HomeLan\FileStore\Services\Provider\IPv4; 


use HomeLan\FileStore\Services\ProviderInterface;
use HomeLan\FileStore\Services\Provider\IPv4\Exceptions\InterfaceNotFound as NotFoundException;
use config;

class Interfaces
{

	private array $aInterfaces = [];

	public function __construct(private readonly ProviderInterface $oProvider,private readonly \Psr\Log\LoggerInterface $oLogger, ?string $sInterfaces=null)
 	{
		if(is_null($sInterfaces)){
			if(!file_exists(config::getValue('ipv4_interfaces_file'))){
				return;
			}
			$sInterfaces = file_get_contents(config::getValue('ipv4_interfaces_file'));
		}
		$aLines = explode("\n",$sInterfaces);
		foreach($aLines as $sLine){
			//Matchs the form "network station ip mask" e.g. "1 4 192.168.0.4 255.255.255.0"
			if(preg_match('/^([0-9]{1,3})\s+([0-9]{1,3})\s+([0-9]{1,3}\.[0-9]{1,3}\.[0-9]{1,3}\.[0-9]{1,3})\s+([0-9]{1,3}\.[0-9]{1,3}\.[0-9]{1,3}\.[0-9]{1,3})/',$sLine,$aMatches)>0){
				$this->addInterface((int) $aMatches[1],(int) $aMatches[2],$aMatches[3],$aMatches[4]);
			}
		}
	 }

	/**
	 * Adds a new IP interface to the system
	 *
	*/  	
	public function addInterface(int $iNetwork, int $iStation, string $sIP, string $sSubnetMask)
	{
		$this->aInterfaces[$sIP] = ['network'=>$iNetwork,'station'=>$iStation,'ipaddr'=>$sIP,'ipint'=>ip2long($sIP),'mask'=>$sSubnetMask,'cidr'=>$this->subnetToCidr($sSubnetMask)];
	}

	/**
	 * Dumps out a list of the interfaces
	 *
	 * Used by the admin system to display the available interfaces
	*/ 	 
	public function dumpInterfaceTable()
	{
		$aReturn=[];
		foreach($this->aInterfaces as $aInt){
			$aReturn[] = ['network'=>$aInt['network'],'station'=>$aInt['station'],'ipaddr'=>$aInt['ipaddr'],'mask'=>$aInt['mask']];
		}
		return $aReturn;
	}


	public function getInterfaceFor($sIP):array
	{
		$iIP =  ip2long($sIP);
		foreach($this->aInterfaces as $aInterface){
			if($this->networkContains($iIP,$aInterface['ipint'],$aInterface['mask'],$aInterface['cidr'])){
				return $aInterface;
			}
		}
		throw new NotFoundException("No interface has a subnet that can directly reach ".$sIP);
	}

	public function isInterfaceIP($sIP):bool
	{
		return array_key_exists($sIP,$this->aInterfaces);
	}

	/**
 	 * Check if an IP is contained in a subnet
 	 *
 	 * @param int $iIPAddr Ip address as a 32bit int, as provided by ip2long
 	 * @param int $iIfIPaddr The interfaces address as a 32bit int, as provided by ip2long
 	 * @param string $sMask
 	 * @param int $iMask The cidr as a number betweeen 0-32
 	*/	 
	private function networkContains(int $iIPAddr,int $iIfIPaddr,string $sMask,int $iMask):bool
	{
		$iSubnet = ip2long($sMask);
		$iIpv4Network = $iIfIPaddr & ip2long($sMask);
		// All IP are matched by the default route 0.0.0.0/0
		if($iMask == 0 AND $iIPAddr == 0){
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
	 * Get the provider using this instance of the interfaces class
	 *
	*/ 	
	public function getProvider():ProviderInterface
	{
		return $this->oProvider;
	}
	
	
}
