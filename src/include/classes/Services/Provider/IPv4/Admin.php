<?php

/**
 * This file contains the printserver admin clas
 *
 * @author John Brown <john@home-lan.co.uk>
 * @package core
*/
namespace HomeLan\FileStore\Services\Provider\IPv4; 


use HomeLan\FileStore\Services\Provider\IPv4;
use HomeLan\FileStore\Services\Provider\AdminInterface;
use HomeLan\FileStore\Services\Provider\AdminEntity;
use HomeLan\FileStore\Services\ProviderInterface;
use HomeLan\FileStore\Services\ServiceDispatcher;

class Admin implements AdminInterface 
{

	private bool $bEnabled = true;

	public function __construct(private readonly IPv4 $oProvider)
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
		return !$this->bEnabled;
	}

	/**
	 * Sets the service disabled 
	 *
	*/ 
	public function setDisabled(): void
	{
		$oServices = ServiceDispatcher::create();
		$oServices->disableService($this->oProvider);
		$this->bEnabled = false;
	}

	/**
	 * Enables the service 
	 *
	*/
	public function setEnabled(): void
	{
		$oServices = ServiceDispatcher::create();
		$oServices->enableService($this->oProvider);
		$this->bEnabled = true;
	}

	/**
	 * Gets a human readable status string for the services
	 *
	*/ 
	public function getStatus(): string
	{
		if($this->bEnabled){
			return "On-line";
		}else{
			return "Disabled";
		}
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
			'interfaces'=>['network'=>'int', 'station'=>'int', 'ipaddr'=>'string', 'mask'=>'string'],
			'routes'=>['network'=>'string','subnet'=>'string','gw'=>'string','metric'=>'int'],
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
				$aArpEntries = $this->oProvider->getArpEntries();
				$aReturn = AdminEntity::createCollection($sType,$this->getEntityFields($sType),$aArpEntries,null,'ipv4');
				return $aReturn;
			case 'interfaces':
				$aInterfaces = $this->oProvider->getInterfaces();
				$aReturn = AdminEntity::createCollection($sType,$this->getEntityFields($sType),$aInterfaces,null,'ipaddr');
				return $aReturn;
			case 'routes':
				$aRoutes = $this->oProvider->getRoutes();
				$aReturn = AdminEntity::createCollection($sType,$this->getEntityFields($sType),$aRoutes,null,'network');
				return $aReturn;
	
	
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
