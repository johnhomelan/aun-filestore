<?php

/**
 * File containing the user class
 *
 * @package coreauth
*/
namespace HomeLan\FileStore\Authentication; 

use config; 

/**
 * This class represents the user 
 * 
 * @package coreauth
 * @author John Brown <john@home-lan.co.uk>
*/
class User {

	protected ?string $sUsername = NULL;
	
	protected ?int $iUnixUid = NULL;

	protected ?string $sHomedir = NULL;

	protected string $sRoot = "$";

	protected int $iOpt = 0;

	protected bool $bIsAdmin = FALSE;

	protected ?string $sCsd = NULL;

	protected ?string $sLib = NULL;

	public function setUsername(string $sUsername): void
	{
		$this->sUsername=strtoupper((string) $sUsername);
	}

	public function getUsername():?string
	{
		return $this->sUsername;
	}

	public function setUnixUid(int $iUid): void
	{
		$this->iUnixUid = $iUid;
	}

	public function getUnixUid():?int
	{
		return $this->iUnixUid;
	}

	public function setHomedir(string $sDir): void
	{
		$this->sHomedir = $sDir;
	}

	public function getHomedir():?string
	{
		return $this->sHomedir;
	}

	public function setBootOpt(int $iOpt): void
	{
		$this->iOpt = $iOpt;
	}

	public function getBootOpt():int
	{
		return $this->iOpt;
	}

	public function setPriv(string $sPriv): void
	{
		$this->bIsAdmin = match (strtoupper((string) $sPriv)) {
      'S' => TRUE,
      default => FALSE,
  };
	}

	public function getPriv(): string
	{
		if($this->bIsAdmin){
			return 'S';
		}
		return 'U';
	}

	/**
	 * Get if this user is an admin user or not
	 *
	 * @return boolean
	*/
	public function isAdmin(): bool
	{
		return $this->bIsAdmin;
	}

	public function setCsd(string $sCsd): void
	{
		$this->sCsd = $sCsd;
	}

	public function getCsd():?string
	{
		if(is_null($this->sCsd)){
			$this->sCsd = $this->getHomedir();
		}
		return $this->sCsd;
	}

	public function setLib(string $sLib): void
	{
		$this->sLib = $sLib;
	}

	public function getLib():?string
	{
		if(is_null($this->sLib)){
			$this->sLib = config::getValue('library_path');
		}
		return $this->sLib;
	}

	public function getRoot():string
	{
		return $this->sRoot;
	}

	public function setRoot(string $sRoot): void
	{
		$this->sRoot = $sRoot;
	}
}
