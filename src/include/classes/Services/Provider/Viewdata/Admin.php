<?php

/**
 * This file contains the viewdata admin class
 *
 * @author John Brown <john@home-lan.co.uk>
 * @package core
*/
namespace HomeLan\FileStore\Services\Provider\Viewdata;


use HomeLan\FileStore\Services\Provider\Viewdata;
use HomeLan\FileStore\Services\Provider\AdminInterface;
use HomeLan\FileStore\Services\Provider\AdminEntity;
use HomeLan\FileStore\Services\ServiceDispatcher;

class Admin implements AdminInterface
{

	private bool $bEnabled = true;

	public function __construct(private readonly Viewdata $oProvider)
 	{
	 }

	/**
	 * Gets the human readable name of the service provider
	 *
	*/
	public function getName(): string
	{
		return "Viewdata";
	}

	/**
	 * Gets the human readable description of the service provider
	 *
	*/
	public function getDescription(): string
	{
		return "Bridges Econet stations to a remote viewdata/videotex server (e.g. Telstar at glasstty.com) over a plain TCP connection, one session per station.";
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
	 * @return array<string,string>
	*/
	public function getEntityTypes(): array
	{
		return ['session'=>'Session Table'];
	}

	/**
	 * Gets a list of all the fields for an entity type
	 *
	 * @return array<string,string>
	*/
	public function getEntityFields(string $sType): array
 	{
     		return match ($sType) {
         		'session' => ['network'=>'int', 'station'=>'int', 'connected'=>'int'],
         	default => [],
     		};
 	}

	/**
	 * Gets the entity instances of a given type for this service
	 *
	 * @return array<int,AdminEntity>
	*/
	public function getEntities(string $sType): array
	{
		switch($sType){
			case 'session':
				$aSessions = $this->oProvider->getSessions();
				$aReturn = AdminEntity::createCollection($sType,$this->getEntityFields($sType),$aSessions,null,'session');
				return $aReturn;
		}
		return [];
	}

	/**
	 * Gets all the commands that can be run
	 *
	 * @return array<int,array{label:string,url:string}>
	*/
	public function getCommands(): array
	{
		return [];
	}


}
