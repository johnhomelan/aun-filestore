<?php

/**
 * This file contains the printserver admin clas
 *
 * @author John Brown <john@home-lan.co.uk>
 * @package core
*/
namespace HomeLan\FileStore\Services\Provider\IPv4; 


use HomeLan\FileStore\Services\Provider\AdminInterface;
use HomeLan\FileStore\Services\Provider\AdminEntity;
use HomeLan\FileStore\Services\ProviderInterface;

class Admin implements AdminInterface 
{


	public function __construct(private readonly ProviderInterface $oProvider)
 	{
	 }

	/**
	 * Gets the human readable name of the service provider
	 *
	*/
	public function getName(): string
	{
		return "IPv4";
	}

	/**
	 * Gets the human readable description of the service provider
	 *
	*/
	public function getDescription(): string
	{
		return "Provides a IPv4 forwarding service.\nIt implements the EconetA version of IPv4 over econet, and forwards packets between econet networks.\nIt also implements ARP, to map layer 2 (econet) addressing to layer 3 (ip) addressing.\nSome ICMP messages types are implemented to allow remote host to be made aware of error conditions.\n";
	}

	/**
	  * Tests if the service provider is disabled 
	  *
	 */  
	public function isDisabled(): bool
	{
		return false;
	}

	/**
	 * Sets the service disabled 
	 *
	*/ 
	public function setDisabled(): void
	{
	}

	/**
	 * Enables the service 
	 *
	*/
	public function setEnabled(): void
	{
	}

	/**
	 * Gets a human readable status string for the services
	 *
	*/ 
	public function getStatus(): string
	{
		return "On-line";
	}

	/**
	 * Gets a list of all the entity type for this service provider
	 *
	*/ 
	public function getEntityTypes(): array
	{
		return ['arp'=>'Arp Table','interfaces'=>'IPv4 Interfaces','routes'=>'Routing Table'];
	}

	/**
	 * Gets a list of all the fields for an entity type
	 *
	*/ 
	public function getEntityFields(string $sType): array
 	{
     		return match ($sType) {
         		'arp' => ['network'=>'int', 'station'=>'int', 'ipv4'=>'string', 'timeout'=>'int'],
			'interfaces'=>['network'=>'int', 'station'=>'int', 'ipv4'=>'string', 'subnet'=>'string'],
			'routes'=>['network'=>'int','ipv4'=>'string', 'subnet'=>'string','metric'=>'int'],
         	default => [],
     		};
 	}

	/**
	 * Gets the entity instances of a given type for this service 
	 *
	*/
	public function getEntities(string $sType): array
	{
		switch($sType){
			case 'arp':
				//$aJobs = $this->oProvider->getJobs();
				//$aReturn = AdminEntity::createCollection($sType,$this->getEntityFields($sType),$aJobs,fn($aRow) => $aRow['network'].'_'.$aRow['station']);
				//return $aReturn;
				break;
		}
		return [];
	}

	/**
	 * Gets all the commands that can be run
	*/
	public function getCommands(): array
	{
		return [];
	}


}
