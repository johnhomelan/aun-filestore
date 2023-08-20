<?php

/**
 * This file contains the class the implements the arpcache
 *
 * @author John Brown <john@home-lan.co.uk>
 * @package core
*/
namespace HomeLan\FileStore\Services\Provider\IPv4; 

use HomeLan\FileStore\Services\Provider\IPv4\Exceptions\ArpEntryNotFound as NotFoundException;

use HomeLan\FileStore\Services\ProviderInterface;

use config;

class Arpcache 
{
	private array $aCache = [];

	const DEFAULT_ENTRY_TIMEOUT = 3600;

	public function __construct(private readonly ProviderInterface $oProvider)
 	{
	}

	/**
	 * Adds and arp entry to the cache 
	 *
	 * This should get called on arp responses, but also each time we get a IP packet from a host, as this will renew the timeout.
	 * Doing this stops entries that are not stale from getting timed out, and triggering additional arp who has requests.
	*/  	
	public function addEntry(int $iNetwork, int $iStation, string $sIP, int $iTimeOut=self::DEFAULT_ENTRY_TIMEOUT):void
	{
		$this->aCache[$sIP] = ['network'=>$iNetwork, 'station'=>$iStation, 'ip'=>$sIP, 'timeout'=>time()+$iTimeOut];
	}

	/** 
 	 * Get the station for an IP in the apr cache
 	*/ 	
	public function getStation(string $sIP):int
	{
		if(array_key_exists($sIP,$this->aCache)){
			return $this->aCache[$sIP]['station'];
		}
		throw new NotFoundException("No entry for ".$sIP);
	}

	/** 
 	 * Get the network for an IP in the apr cache
 	*/
 	public function getNetwork(string $sIP):int
	{
		if(array_key_exists($sIP,$this->aCache)){
			return $this->aCache[$sIP]['network'];
		}
		throw new NotFoundException("No entry for ".$sIP);

	}

	/**
 	 * Get the network, station number for an IP in the arp cache, as an assoc array
 	 *
 	*/  	
	public function getNetworkAndStation(string $sIP):array
	{
		if(array_key_exists($sIP,$this->aCache)){
			return ['network'=>$this->aCache[$sIP]['network'],'station'=>$this->aCache[$sIP]['station']];
		}
		throw new NotFoundException("No entry for ".$sIP);
	}

	/**
	 * Dumps the arp cache as an array
	 * 
	 * Used by the admin system to display the arp cache
	*/	
	public function dumpArpCache():array
	{
		$aReturn = [];
		foreach($this->aCache as $aArp){
			$aReturn[] = ['network'=>$aArp['network'], 'station'=>$aArp['station'], 'ipv4'=>$aArp['ip'], 'timeout'=>$aArp['timeout']-time()];
		}
		return $aReturn;
	}

	/**
	 * Called to perform all the house keeping tasks for the arp cache
	 *
	 * This just removed stale timed out entries from the apr cache
	*/ 	 
	public function houseKeeping():void
	{
		//Builds a new version of the arp cache with only entries 
		//that jhave not timed out.
		$aNewCache = [];
		$iTime = time(); //Current time 

		foreach($this->aCache as $sIP=>$aEntry){
			if($aEntry['timeout']>$iTime){ 
				//Place the entry in the new cache 
				$aNewCache[$sIP] = $aEntry;
			}
		}

		$this->aCache = $aNewCache;
	}

	/**
	 * Get the provider using this instance of the arp cache
	 *
	*/ 	
	public function getProvider():ProviderInterface
	{
		return $this->oProvider;
	}
	

}
