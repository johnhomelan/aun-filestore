<?php

/**
 * File containing the user class
 *
 * @package coreauth
*/

/**
 * This class represents the user 
 * 
 * @package coreauth
 * @author John Brown <john@home-lan.co.uk>
*/
class user {

	protected $sUsername = NULL;
	
	protected $iUnixUid = NULL;

	protected $sHomedir = NULL;

	protected $iOpt = 0;

	protected $bIsAdmin = FALSE;

	protected $sCsd = NULL;

	protected $sLib = NULL;

	public function setUsername($sUsername)
	{
		$this->sUsername=strtoupper($sUsername);
	}

	public function getUsername()
	{
		return $this->sUsername;
	}

	public function setUnixUid($iUid)
	{
		$this->iUnixUid = $iUid;
	}

	public function getUnixUid()
	{
		return $this->iUnixUid;
	}

	public function setHomedir($sDir)
	{
		$this->sHomedir = $sDir;
	}

	public function getHomedir()
	{
		return $this->sHomedir;
	}

	public function setBootOpt($iOpt)
	{
		$this->iOpt = $iOpt;
	}

	public function getBootOpt()
	{
		return $this->iOpt;
	}

	public function setPriv($sPriv)
	{
		switch(strtoupper($sPriv)){
			case 'S':
				$this->bIsAdmin = TRUE;
				break;
			default:
				$this->bIsAdmin = FALSE;
				break;
		}
	}

	public function getPriv()
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
	public function isAdmin()
	{
		return $this->bIsAdmin;
	}

	public function setCsd($sCsd)
	{
		$this->sCsd = $sCsd;
	}

	public function getCsd()
	{
		if(is_null($this->sCsd)){
			$this->sCsd = $this->getHomedir();
		}
		return $this->sCsd;
	}

	public function setLib($sLib)
	{
		$this->sLib = $sLib;
	}

	public function getLib()
	{
		if(is_null($this->sLib)){
			$this->sLib = config::getValue('library_path');
		}
		return $this->sLib;
	}
}
