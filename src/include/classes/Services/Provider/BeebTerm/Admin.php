<?php

/**
 * This file contains the beebterm admin class
 *
 * @author John Brown <john@home-lan.co.uk>
 * @package core
*/
namespace HomeLan\FileStore\Services\Provider\BeebTerm;


use HomeLan\FileStore\Services\Provider\BeebTerm;
use HomeLan\FileStore\Services\Provider\AdminInterface;
use HomeLan\FileStore\Services\Provider\AdminEntity;
use HomeLan\FileStore\Services\ProviderInterface;
use HomeLan\FileStore\Services\ServiceDispatcher;

class Admin implements AdminInterface 
{

	private bool $bEnabled = true;

	public function __construct(private readonly BeebTerm $oProvider)
 	{
	 }

	/**
	 * Gets the human readable name of the service provider
	 *
	*/
	public function getName(): string
	{
		return "Beeb Term";
	}

	/**
	 * Gets the human readable description of the service provider
	 *
	*/
	public function getDescription(): string
	{
		return "Provides a service that allows the Beeb Term client to establish a session with a process running on the server.\nIts a immplementation of the server side part of the system originally created by Andrew Gordon of SJ Research, which ran at Felsted.";
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
		return ['session'=>'Session Table','service'=>'Available Session Type'];
	}

	/**
	 * Gets a list of all the fields for an entity type
	 *
	*/ 
	public function getEntityFields(string $sType): array
 	{
     		return match ($sType) {
         		'session' => ['network'=>'int', 'station'=>'int', 'command'=>'string','pid'=>'int'],
			'service'=>['name'=>'string', 'command'=>'string'],
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
			case 'session':
				$aSessions = $this->oProvider->getSessions();
				$aReturn = AdminEntity::createCollection($sType,$this->getEntityFields($sType),$aSessions,null,'session');
				return $aReturn;
			case 'service':
				$aServices = $this->oProvider->getServices();
				$aReturn = AdminEntity::createCollection($sType,$this->getEntityFields($sType),$aServices,null,'service');
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
