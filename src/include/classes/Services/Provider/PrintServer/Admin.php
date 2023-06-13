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
		return ['jobs'=>'Print Jobs'];
	}

	/**
	 * Gets a list of all the fields for an entity type
	 *
	*/ 
	public function getEntityFields(string $sType): array
	{
		switch($sType){
			case 'jobs':
				return ['network'=>'int', 'station'=>'int', 'began'=>'datatime', 'size'=>'int'];	
				break;
		}
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
				break;
		}
	}

	/**
	 * Gets all the commands that can be run
	*/
	public function getCommands(): array
	{
			
	}


}
