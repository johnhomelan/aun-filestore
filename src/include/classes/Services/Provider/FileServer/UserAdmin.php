<?php
/**
 * This file contains the fileserver user account admin handler
 *
 * @author John Brown <john@home-lan.co.uk>
 * @package core
*/
namespace HomeLan\FileStore\Services\Provider\FileServer;

use HomeLan\FileStore\Services\Provider\FileServer;
use HomeLan\FileStore\Messages\FsRequest;

use config;
use Exception;

/**
 * Handles the user-account-settings side of the file server protocol: a
 * user's own password, their disc quota, their admin/non-admin privilege,
 * and their boot option. Reachable both via raw FS function codes
 * (getUserDiscFree/setUserDiscFree/setOpt) and via "*" CLI commands
 * dispatched from Cli::runCli() (setPassword/privUser/cliOpt).
 *
 * @package core
*/
class UserAdmin {

	public function __construct(private readonly FileServer $oProvider)
	{
	}

	/**
	 * Set the current users password
	 *
	 * This method is invoked by the *PASS command
	*/
	public function setPassword(FsRequest $oFsRequest,string $sOptions): void
	{
		$aOptions = explode(' ',$sOptions);
		$oReply = $oFsRequest->buildReply();
		if(count($aOptions)!=2){
			$oReply->setError(0xff,"Syntax");
		}else{
			//Filter out the string added by the use of *pass :
			if(substr_count($aOptions[0],"\r")>0){
				[$aOptions[0]] = explode("\r",$aOptions[0]);
			}
			if(substr_count($aOptions[1],"\r")>0){
				[$aOptions[1]] = explode("\r",$aOptions[1]);
			}


			//Get the old password
			if($aOptions[0]=='""'){
				$sOldPassword = NULL;
			}else{
				$sOldPassword = $aOptions[0];
			}

			//Get the new password
			if($aOptions[1]=='""'){
				$sPassword = NULL;
			}else{
				$sPassword = $aOptions[1];
			}

			try {
				//Change the password
				$this->oProvider->secSetPassword($oFsRequest->getSourceNetwork(),$oFsRequest->getSourceStation(),$sOldPassword,$sPassword);
				$oReply->DoneOk();
			}catch(Exception $oException){
				$oReply->setError(0xff,$oException->getMessage());
			}
		}
		$this->oProvider->addReplyToBuffer($oReply->buildEconetpacket());
	}

	/**
	 * Set the privalage of a given user
	 *
	*/
	public function privUser(FsRequest $oFsRequest, ?string $sOptions): void
	{
		$aOptions = explode(' ',(string) $sOptions);
		$oReply = $oFsRequest->buildReply();
		if(count($aOptions)!=2){
			$oReply->setError(0xff,"Syntax");
		}else{
			$oMyUser = $this->oProvider->secGetUser($oFsRequest->getSourceNetwork(),$oFsRequest->getSourceStation());
			if($oMyUser !== null AND $oMyUser->isAdmin()){
				if($aOptions[1]!='S' AND $aOptions[1]!='U'){
					$oReply->setError(0xff,"The only valid priv is S or U");
				}else{
					$this->oProvider->secSetPriv($oFsRequest->getSourceNetwork(),$oFsRequest->getSourceStation(),$aOptions[0],$aOptions[1]);
					$oReply->DoneOk();
				}
			}else{
				$oReply->setError(0xff,"Only user with priv S can use *PRIV");
			}

		}
		$this->oProvider->addReplyToBuffer($oReply->buildEconetpacket());
	}

	/**
	 * Gets the disk space free value for a user
	 *
	 * Given we can't map the scale of Linux storage sizes to bbc storage sizes, the amount of free space is just a constant.
	 * Maybe at some point this could be mapped to a unix users quota if the system has quotas setup
	*/
	public function getUserDiscFree(FsRequest $oFsRequest): void
	{
		$sUsername = $oFsRequest->getString(1);
		$oReply = $oFsRequest->buildReply();
		$oReply->DoneOk();

		$oUser = $this->oProvider->secGetUserByName($sUsername);
		$iQuota = (is_object($oUser) && $oUser->getQuota() > 0)
			? $oUser->getQuota()
			: config::getValueAsInt('vfs_default_disc_free');

		$oReply->append24bitIntLittleEndian($iQuota);
		$this->oProvider->addReplyToBuffer($oReply->buildEconetpacket());
	}

	/**
	 * EC_FS_FUNC_SET_USER_FREE (0x1F) — set per-user disc quota (sysop only)
	 *
	 * Request payload: username (CR-terminated) followed by quota (uint24 LE).
	*/
	public function setUserDiscFree(FsRequest $oFsRequest): void
	{
		$oReply = $oFsRequest->buildReply();
		$oMyUser = $this->oProvider->secGetUser($oFsRequest->getSourceNetwork(),$oFsRequest->getSourceStation());
		if(!is_object($oMyUser)){
			$oReply->setError(0xbf,"Who are you?");
			$this->oProvider->addReplyToBuffer($oReply->buildEconetpacket());
			return;
		}
		if(!$oMyUser->isAdmin()){
			$oReply->setError(0xbd,"Insufficient privilege");
			$this->oProvider->addReplyToBuffer($oReply->buildEconetpacket());
			return;
		}
		[$sUsername, $iAfterName] = $oFsRequest->getStringEndPos(1);
		$iQuota = $oFsRequest->get24bitIntLittleEndian($iAfterName);
		try {
			$this->oProvider->secSetUserQuota($oFsRequest->getSourceNetwork(),$oFsRequest->getSourceStation(),$sUsername,$iQuota);
			$oReply->DoneOk();
		}catch(Exception $oException){
			$oReply->setError(0xff,$oException->getMessage());
		}
		$this->oProvider->addReplyToBuffer($oReply->buildEconetpacket());
	}

	/**
	 * EC_FS_FUNC_SET_OPT4 — sets the caller's own boot option (0-3).
	*/
	public function setOpt(FsRequest $oFsRequest): void
	{
		$iOpt = $oFsRequest->getByte(1);
		$oReply = $oFsRequest->buildReply();
		if($iOpt < 0 || $iOpt > 3){
			$oReply->setError(0x8e,"Bad OPT value");
			$this->oProvider->addReplyToBuffer($oReply->buildEconetpacket());
			return;
		}
		$this->oProvider->secSetOpt($oFsRequest->getSourceNetwork(),$oFsRequest->getSourceStation(),(string) $iOpt);
		$oReply->DoneOk();
		$this->oProvider->addReplyToBuffer($oReply->buildEconetpacket());
	}

	/**
	 * Implements the *OPT CLI command.
	 * Only option 4 (boot option) is supported: *OPT 4,n where n is 0–3.
	*/
	public function cliOpt(FsRequest $oFsRequest, string $sOptions): void
	{
		$oReply = $oFsRequest->buildReply();
		if(!is_object($this->oProvider->secGetUser($oFsRequest->getSourceNetwork(),$oFsRequest->getSourceStation()))){
			$oReply->setError(0xbf,"Who are you?");
			$this->oProvider->addReplyToBuffer($oReply->buildEconetpacket());
			return;
		}
		$aParts = explode(',', $sOptions, 2);
		if(count($aParts) !== 2 || (int) trim($aParts[0]) !== 4){
			$oReply->setError(0xff,"Bad OPT");
			$this->oProvider->addReplyToBuffer($oReply->buildEconetpacket());
			return;
		}
		$iBootOpt = (int) trim($aParts[1]);
		if($iBootOpt < 0 || $iBootOpt > 3){
			$oReply->setError(0xff,"Bad OPT value");
			$this->oProvider->addReplyToBuffer($oReply->buildEconetpacket());
			return;
		}
		$this->oProvider->secSetOpt($oFsRequest->getSourceNetwork(),$oFsRequest->getSourceStation(),(string) $iBootOpt);
		$oReply->DoneOk();
		$this->oProvider->addReplyToBuffer($oReply->buildEconetpacket());
	}

}
