<?php

/**
 * This file contains the printserver admin clas
 *
 * @author John Brown <john@home-lan.co.uk>
 * @package core
*/
namespace HomeLan\FileStore\Services\Provider\FileServer; 


use HomeLan\FileStore\Services\Provider\AdminInterface;
use HomeLan\FileStore\Services\Provider\AdminEntity;
use HomeLan\FileStore\Services\ProviderInterface;
use HomeLan\FileStore\Authentication\Security; 

class Admin implements AdminInterface 
{
	public function __construct(private readonly ProviderInterface $oProvider)
 {
 }


	public function getProvider(): ProviderInterface
	{
		return $this->oProvider;
	}

	/**
	 * Gets the human readable name of the service provider
	 *
	*/
	public function getName(): string
	{
		return "File Server";
	}

	/**
	 * Gets the human readable description of the service provider
	 *
	*/
	public function getDescription(): string
	{
		return "Provides a file service for BBC/Acorn workstations.\n";
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
		return ['session'=>'Logged in users', 'stream'=>'File Streams', 'user'=>'Users'];
	}

	/**
	 * Gets a list of all the fields for an entity type
	 *
	*/ 
	public function getEntityFields(string $sType): array
	{
		switch($sType){
			case 'session':
				return ['network'=>'int', 'station'=>'int', 'user'=>'string'];	
			case 'stream':
				return ['network'=>'int', 'station'=>'int', 'user'=>'string', 'path'=>'string'];
			case 'user':
				return ['plugin'=>'string', 'username'=>'string', 'priv'=>'string' , 'homedir'=>'string', 'bootopt'=>'int'];
		}
		return [];
	}

	/**
	 * Gets the entity instances of a given type for this service 
	 *
	*/
	public function getEntities(string $sType): array
	{
		switch($sType){
			case 'session':
				$aUsers = Security::getUsersOnline();
				$aUserData=[];
				foreach($aUsers as $iNetwork=>$aStationData){
					foreach($aStationData as $iStation=>$aData){
						$aUserData[] = ['network'=>$iNetwork, 'station'=>$iStation, 'user'=>$aData['user']->getUsername()];
					}
				}
				$aReturn = AdminEntity::createCollection($sType,$this->getEntityFields($sType),$aUserData,null,'user');
				return $aReturn;
			case 'user':
				$aUsers = Security::getAllUsers();
				$aUserData = [];
				foreach($aUsers as $aUser){
					$aUserData[] = ['plugin'=>$aUser['plugin'], 'username'=>$aUser['user']->getUsername(), 'priv'=>$aUser['user']->getPriv(), 'homedir'=>$aUser['user']->getHomedir(),'bootopt'=>$aUser['user']->getBootOpt()];
				}
				$aReturn = AdminEntity::createCollection($sType,$this->getEntityFields($sType),$aUserData,null,'username');
				return $aReturn;
			case 'stream':
				$aStreams=$this->oProvider->getStreams();
				$aSteamData = [];
				foreach($aStreams as $aStream){
					$aSteamData[] = ['network'=>$aStream['network'], 'station'=>$aStream['station'], 'user'=>$aStream['stream']->getUser(), 'path'=>$aStream['stream']->getPath()];
				}
				$aReturn = AdminEntity::createCollection($sType,$this->getEntityFields($sType),$aSteamData,null,'path');
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
