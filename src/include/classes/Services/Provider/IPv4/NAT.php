<?php

/**
 * This file contains the class the implements NAT (well more like reverse proxy for TCP connections).
 *
 * @author John Brown <john@home-lan.co.uk>
 * @package core
*/
namespace HomeLan\FileStore\Services\Provider\IPv4; 


use HomeLan\FileStore\Services\ProviderInterface;
use HomeLan\FileStore\Messages\IPv4Request;
use config;


class NAT
{


	private array $aConnTrack=[];

	private array $aNatTable=[];
	/**
 	 * Constructor 
 	 *
	 * Will load all the routes from a string (this is mostly used for unit testing), or from the routes config file
	 */
	public function __construct(private readonly ProviderInterface $oProvider, ?string $sNATEntries=null)
 	{
		if(is_null($sNATEntries)){
			if(!file_exists(config::getValue('ipv4_nat_file'))){
				return;
			}
			$sNATEntries = file_get_contents(config::getValue('ipv4_nat_file'));
		}
		$aLines = explode("\n",$sNATEntries);
		foreach($aLines as $sLine){
			//Match <ip-to-rewrite-from> <ip-addr-to> <port-to-rewrite-from> <port-to>
			if(preg_match('/^([0-9]{1,3}\.[0-9]{1,3}\.[0-9]{1,3}\.[0-9]{1,3})\s+([0-9]{1,3}\.[0-9]{1,3}\.[0-9]{1,3}\.[0-9]{1,3})\s+([0-9]{1,4})\s+([0-9]{1,4})/',$sLine,$aMatches)>0){
				$this->addNatEntry($aMatches[1],$aMatches[2],(int) $aMatches[3],(int) $aMatches[4]);
			}
		}
	 }

	/**
 	 * Adds an entry to the NAT table 
 	 *
 	*/ 	
	public function addNatEntry(string $sIPv4From, string $sIPv4To, int $iPortFrom, int $iPortTo):void
	{
		$this->aNatTable[] = ['ip_from'=>$sIPv4From, 'ip_to'=>$sIPv4To, 'port_from'=>$iPortFrom, 'port_to'=>$iPortTo];
	}

	public function isNatTarget(string $sIP):bool
	{
		
		foreach($this->aNatTable as $aEntry){
			if($aEntry['ip_from']==$sIP){
				return true;
			}
		}
		return false;
	}

	public function dumpNatTable():array
	{
		return $this->aNatTable;
	}
		
	/**
	 * Get the provider using this instance of NAT
	 *
	*/ 	
	public function getProvider():ProviderInterface
	{
		return $this->oProvider;
	}

	/**
 	 * Processes in IPv4 Packet from the econet side of things
 	*/ 
	public function processNatPacket(IPv4Request $oIPv4):void
	{
		//If its not a TCP packet return (as we only do TCP)
		if($oIPv4->getProtocol()!='TCP'){
			return;
		}

		
	}
	
}
