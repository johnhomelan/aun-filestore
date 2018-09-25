<?php
/**
 * File containing the security class
 *
 * @package coreauth
*/
namespace HomeLan\FileStore\Authentication; 

use config;
use Exception;
/**
 * This class controls security with in the fileserver, perform login/out and
 * all other security functions
 * 
 * @package coreauth
 * @author John Brown <john@home-lan.co.uk>
*/

class Security {

	protected static $aSessions = array();

	protected static $oLogger;

	public static function init(\Psr\Log\LoggerInterface $oLogger)
	{
		self::$oLogger = $oLogger;
		$aPlugins = Security::_getAuthPlugins();

	}

	/**
	 * Cleans up any sessions that have been idle for too long
	*/
	public static function houseKeeping()
	{
		$iTime = time();
		foreach(Security::$aSessions as $iNetwork=>$aStations){
			foreach($aStations as $iStation=>$aData){
				if($aData['idle']<time()-config::getValue('security_max_session_idle')){
					Security::logout($iNetwork,$iStation);
				}
			}
		}	
	}


	/**
	 * Get a list of all the authplugs we should be using 
	 *
	 * It also calls the init method of each one
	 *
	**/
	protected static function _getAuthPlugins()
	{
		$aReturn = array();
		$aAuthPlugis = explode(',',config::getValue('security_auth_plugins'));
		foreach($aAuthPlugis as $sPlugin){
			$sClassname = "\HomeLan\FileStore\Authentication\Plugins\AuthPlugin".ucfirst($sPlugin);
			if(!class_exists($sClassname,FALSE)){
				try{
					$sClassname::init(self::$oLogger);
					$aReturn[]=$sClassname;
				}catch(Exception $oException){
					self::$oLogger->info("Security: Unable to load authplugin ".$sClassname);
				}
			}else{
				$aReturn[]=$sClassname;
			}
		}
		return $aReturn;
	}

	/**
	 * Updates the last idle time for a connection
	*/
	public static function updateIdleTimer($iNetwork,$iStation)
	{
		if(array_key_exists($iNetwork,Security::$aSessions) AND array_key_exists($iStation,Security::$aSessions[$iNetwork])){
			Security::$aSessions[$iNetwork][$iStation]['idle']=time();
		}
	
	}

	/**
	 * Gets the last idle time for a connection
	*/
	public static function getIdleTimer($iNetwork,$iStation)
	{
		if(array_key_exists($iNetwork,Security::$aSessions) AND array_key_exists($iStation,Security::$aSessions[$iNetwork])){
			return Security::$aSessions[$iNetwork][$iStation]['idle'];
		}
	}

	/**
	 * Performs the login operation for a give user on a network and station
	 *
	 * @param int $iNetwork
	 * @param int $iStation
	 * @param string $sUser
	 * @param string $sPass
	*/
	public static function login($iNetwork,$iStation,$sUser,$sPass)
	{
		$aPlugins = Security::_getAuthPlugins();
		foreach($aPlugins as $sPlugin){
			try {
				if($sPlugin::login($sUser,$sPass,$iNetwork,$iStation)){
					self::$oLogger->info("Security: Login for ".$sUser." using authplugin ".$sPlugin);
					if(!array_key_exists($iNetwork,Security::$aSessions)){
						Security::$aSessions[$iNetwork]=array();
					}
					Security::$aSessions[$iNetwork][$iStation]=array('idle'=>time(),'datetime'=>time(),'provider'=>$sPlugin,'user'=>$sPlugin::buildUserObject($sUser));
					return TRUE;
				}else{
					self::$oLogger->info("Security: Login failed for ".$sUser." using authplugin ".$sPlugin);
				}
			}catch(Exception $oException){
				self::$oLogger->info("Security: Exception thrown during login attempt by authplugin ".$sPlugin." (".$oException->getMessage().").");
			}
		}
		return FALSE;
	}

	public static function logout($iNetwork,$iStation)
	{
		if(Security::isLoggedIn($iNetwork,$iStation)){
			$oUser = Security::getUser($iNetwork,$iStation);
			self::$oLogger->info("Security: Logout for ".$oUser->getUsername()." on ".$iNetwork.".".$iStation."");
			//Drop the login from the session array
			unset(Security::$aSessions[$iNetwork][$iStation]);
		}else{
			throw new Exception("Security: No user logged in on ".$iNetwork.".".$iStation);
		}
		
	}

	/**
	 * Get the user object for the user logged in at the given network/station
	 *
	 * @param int $iNetwork
	 * @param int $iStation
	 * @return object user
	*/
	public static function getUser($iNetwork,$iStation)
	{
		if(array_key_exists($iNetwork,Security::$aSessions) AND array_key_exists($iStation,Security::$aSessions[$iNetwork])){
			return Security::$aSessions[$iNetwork][$iStation]['user'];
		}
	}

	/**
	 * Tests if auser is logged in on a give network/station
	 * 
	 * @param int $iNetwork
	 * @param int $iStation
	 * @return boolean
	*/
	public static function isLoggedIn($iNetwork,$iStation)
	{
		if(array_key_exists($iNetwork,Security::$aSessions) AND array_key_exists($iStation,Security::$aSessions[$iNetwork])){
			return TRUE;
		}
		return FALSE;
	}

	/**
	 * Set the password for the user logged in using  network/station 
	 *
	 * @param int $iNetwork
	 * @param int $iStation
	 * @param string $sOldPassword
	 * @param string $sPassword
	*/	 
	public static function setConnectedUsersPassword($iNetwork,$iStation,$sOldPassword,$sPassword)
	{
		if(array_key_exists($iNetwork,Security::$aSessions) AND array_key_exists($iStation,Security::$aSessions[$iNetwork])){
			self::$oLogger->info("Security: Changing password for ".Security::$aSessions[$iNetwork][$iStation]['user']->getUsername()." using authplugin ".Security::$aSessions[$iNetwork][$iStation]['provider']);
			$sPlugin = Security::$aSessions[$iNetwork][$iStation]['provider'];
			$sPlugin::setPassword(Security::$aSessions[$iNetwork][$iStation]['user']->getUsername(),$sOldPassword,$sPassword);
		}
	}

	/**
	 * Get an error of the users logged in
	 *
	 * @return array
	*/
	public static function getUsersOnline()
	{
		return  Security::$aSessions;
	}

	public static function getUsersStation($sUsername)
	{
		foreach(Security::$aSessions as $iNetwork=>$aStationUsers){
			foreach($aStationUsers as $iStation=>$aData){
				$oUser = $aData['user'];
				if(strtoupper($oUser->getUsername())==trim(strtoupper($sUsername))){
					return array('station'=>$iStation,'network'=>$iNetwork);	
				}
			}
		}
		return array();	
	}

	/**
	 * Creates a new user (assuming the user logged in on the given network/station has admin rights)
	 *
	 * @param int $iNetwork
	 * @param int $iStation
	 * @param object user $oUser
	*/
	public static function createUser($iNetwork,$iStation,$oUser)
	{
		if(!is_object($oUser) OR get_class($oUser)!='user'){
			throw new Exception("Security: Invaild user supplied to createUser.\n");
		}

		if(!Security::isLoggedIn($iNetwork,$iStation)){
			throw new Exception("Security:  Unable to createUser, no user is logged in on ".$iNetwork.".".$iStation);
		}

		$oLoggedInUser = Security::getUser($iNetwork,$iStation);
		if(!$oLoggedInUser->isAdmin()){
			throw new Exception("Security:  Unable to createUser, the user logged in on ".$iNetwork.".".$iStation." (".$oUser->getUsername().") does not have admin rights.");
		}

		$aPlugins = Security::_getAuthPlugins();
		self::$oLogger->info("Security: Creating new user ".$oUser->getUsername());
		foreach($aPlugins as $sPlugin){
			try {
				$sPlugin::createUser($oUser);
				break;
			}catch(Exception $oException){
				self::$oLogger->debug("Security: Exception thrown by plugin ".$sPlugin." when attempting to create user ".$oUser->getUsername()." (".$oException->getMessage().")");
			}
		}
	
	}

	/**
	 * Removes a user (assuming the user logged in on the given network/station has admin rights)
	 *
	 * @param int $iNetwork
	 * @param int $iStation
	 * @param string $sUsername
	*/
	public static function removeUser($iNetwork,$iStation,$sUsername)
	{
		if(!Security::isLoggedIn($iNetwork,$iStation)){
			throw new Exception("Security:  Unable to remove the user, no user is logged in on ".$iNetwork.".".$iStation);
		}

		$oLoggedInUser = Security::getUser($iNetwork,$iStation);
		if(!$oLoggedInUser->isAdmin()){
			throw new Exception("Security:  Unable to remove a user, the user logged in on ".$iNetwork.".".$iStation." does not have admin rights.");
		}

		$aPlugins = Security::_getAuthPlugins();
		self::$oLogger->info("Security: Removing user ".$sUsername);
		foreach($aPlugins as $sPlugin){
			try {
				return $sPlugin::removeUser($sUsername);
				break;
			}catch(Exception $oException){
				self::$oLogger->debug("Security: Exception thrown by plugin ".$sPlugin." when attempting to remove user ".$sUsername." (".$oException->getMessage().")");
			}
		}
	
	}

	/**
	 * Creates a new user (assuming the user logged in on the given network/station has admin rights)
	 *
	 * @param int $iNetwork
	 * @param int $iStation
	 * @param string $sUsername
	 * @param string $sPriv (S|U)
	*/
	public static function setPriv($iNetwork,$iStation,$sUsername,$sPriv)
	{
		if(!Security::isLoggedIn($iNetwork,$iStation)){
			throw new Exception("Security:  Unable to setPriv, no user is logged in on ".$iNetwork.".".$iStation);
		}

		$oLoggedInUser = Security::getUser($iNetwork,$iStation);
		if(!$oLoggedInUser->isAdmin()){
			throw new Exception("Security:  Unable to setPriv, the user logged in on ".$iNetwork.".".$iStation." (".$oLoggedInUser->getUsername().") does not have admin rights.");
		}

		if($sPriv!='S' AND $sPriv!='U'){
			throw new Exception("Security:  ".$sPriv." is an invalid priv setting.");
		}

		$aPlugins = Security::_getAuthPlugins();
		self::$oLogger->info("Security: Setting priv for ".$sUsername." to ".$sPriv);
		foreach($aPlugins as $sPlugin){
			try {
				$sPlugin::setPriv($sUsername,$sPriv);
				break;
			}catch(Exception $oException){
				self::$oLogger->debug("Security: Exception thrown by plugin ".$sPlugin." when attempting to create user ".$oLoggedInUser->getUsername()." (".$oException->getMessage().")");
			}
		}
	
	}

}
