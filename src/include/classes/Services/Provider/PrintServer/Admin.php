<?php

/**
 * This file contains the printserver admin clas
 *
 * @author John Brown <john@home-lan.co.uk>
 * @package core
*/
namespace HomeLan\FileStore\Services\Provider\PrintServer; 


use HomeLan\FileStore\Services\Provider\AdminInterface;
use HomeLan\FileStore\Services\Provider\AdminEntity;
use HomeLan\FileStore\Services\ProviderInterface;
use HomeLan\FileStore\Services\ServiceDispatcher;
use HomeLan\FileStore\Services\Provider\PrintServer;

class Admin implements AdminInterface 
{

	private bool $bEnabled = true;

	public function __construct(private readonly PrintServer $oProvider)
 	{
	 }

	/**
	 * Gets the human readable name of the service provider
	 *
	*/
	public function getName(): string
	{
		return "Print Server";
	}

	/**
	 * Gets the human readable description of the service provider
	 *
	*/
	public function getDescription(): string
	{
		return "Provides a print service for BBC/Acorn workstations.\nCurrently it just captures the print data sent to a file and stores in a spool directory per user.\nThis server requires the user to have logged into the file service.";
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
		return ['jobs'=>'Print Jobs'];
	}

	/**
	 * Gets a list of all the fields for an entity type
	 *
	*/ 
	public function getEntityFields(string $sType): array
 {
     return match ($sType) {
         'jobs' => ['network'=>'int', 'station'=>'int', 'began'=>'datatime', 'size'=>'int'],
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
			case 'jobs':
				$aJobs = $this->oProvider->getJobs();
				$aReturn = AdminEntity::createCollection($sType,$this->getEntityFields($sType),$aJobs,fn($aRow) => $aRow['network'].'_'.$aRow['station']);
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
