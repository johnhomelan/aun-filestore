<?php
/**
 * File containing the security class
 *
 * @package coreauth
*/

/**
 * This class controls security with in the fileserver, perform login/out and
 * all other security functions
 * 
 * @package coreauth
 * @author John Brown <john@home-lan.co.uk>
*/

class security {

	protected static $aSessions = array();


	public static function init()
	{
		$aPlugins = security::_getAuthPlugins();

	}

	/**
	 * Cleans up any sessions that have been idle for too long
	*/
	public static function houseKeeping()
	{
		$iTime = time();
		foreach(security::$aSessions as $iNetwork=>$aStations){
			foreach($aStations as $iStation=>$aData){
				if($aData['idle']<time()-config::getValue('security_max_session_idle')){
					security::logout($iNetwork,$iStation);
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
			$sClassname = "authplugin".$sPlugin;
			if(!class_exists($sClassname,FALSE)){
				try{
					$sClassname::init();
					$aReturn[]=$sClassname;
				}catch(Exception $oException){
					logger::log("Security: Unable to load authplugin ".$sClassname,LOG_INFO);
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
		if(array_key_exists($iNetwork,security::$aSessions) AND array_key_exists($iStation,security::$aSessions[$iNetwork])){
			security::$aSessions[$iNetwork][$iStation]['idle']=time();
		}
	
	}

	/**
	 * Gets the last idle time for a connection
	*/
	public static function getIdleTimer($iNetwork,$iStation)
	{
		if(array_key_exists($iNetwork,security::$aSessions) AND array_key_exists($iStation,security::$aSessions[$iNetwork])){
			return security::$aSessions[$iNetwork][$iStation]['idle'];
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
		$aPlugins = security::_getAuthPlugins();
		foreach($aPlugins as $sPlugin){
			try {
				if($sPlugin::login($sUser,$sPass,$iNetwork,$iStation)){
					logger::log("Security: Login for ".$sUser." using authplugin ".$sPlugin,LOG_INFO);
					if(!array_key_exists($iNetwork,security::$aSessions)){
						security::$aSessions[$iNetwork]=array();
					}
					security::$aSessions[$iNetwork][$iStation]=array('idle'=>time(),'datetime'=>time(),'provider'=>$sPlugin,'user'=>$sPlugin::buildUserObject($sUser));
					return TRUE;
				}else{
					logger::log("Security: Login failed for ".$sUser." using authplugin ".$sPlugin,LOG_INFO);
				}
			}catch(Exception $oException){
				logger::log("Security: Exception thrown during login attempt by authplugin ".$sPlugin." (".$oException->getMessage().").",LOG_INFO);
			}
		}
		return FALSE;
	}

	public static function logout($iNetwork,$iStation)
	{
		if(security::isLoggedIn($iNetwork,$iStation)){
			$oUser = security::getUser($iNetwork,$iStation);
			logger::log("Security: Logout for ".$oUser->getUsername()." on ".$iNetwork.".".$iStation."",LOG_INFO);
			//Drop the login from the session array
			unset(security::$aSessions[$iNetwork][$iStation]);
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
		if(array_key_exists($iNetwork,security::$aSessions) AND array_key_exists($iStation,security::$aSessions[$iNetwork])){
			return security::$aSessions[$iNetwork][$iStation]['user'];
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
		if(array_key_exists($iNetwork,security::$aSessions) AND array_key_exists($iStation,security::$aSessions[$iNetwork])){
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
		if(array_key_exists($iNetwork,security::$aSessions) AND array_key_exists($iStation,security::$aSessions[$iNetwork])){
			logger::log("Security: Changing password for ".security::$aSessions[$iNetwork][$iStation]['user']->getUsername()." using authplugin ".security::$aSessions[$iNetwork][$iStation]['provider'],LOG_INFO);
			$sPlugin = security::$aSessions[$iNetwork][$iStation]['provider'];
			$sPlugin::setPassword(security::$aSessions[$iNetwork][$iStation]['user']->getUsername(),$sOldPassword,$sPassword);
		}
	}

	/**
	 * Get an error of the users logged in
	 *
	 * @return array
	*/
	public static function getUsersOnline()
	{
		return  security::$aSessions;
	}

	public static function getUsersStation($sUsername)
	{
		foreach(security::$aSessions as $iNetwork=>$aStationUsers){
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

		if(!security::isLoggedIn($iNetwork,$iStation)){
			throw new Exception("Security:  Unable to createUser, no user is logged in on ".$iNetwork.".".$iStation);
		}

		$oLoggedInUser = security::getUser($iNetwork,$iStation);
		if(!$oLoggedInUser->isAdmin()){
			throw new Exception("Security:  Unable to createUser, the user logged in on ".$iNetwork.".".$iStation." (".$oUser->getUsername().") does not have admin rights.");
		}

		$aPlugins = security::_getAuthPlugins();
		logger::log("Security: Creating new user ".$oUser->getUsername(),LOG_INFO);
		foreach($aPlugins as $sPlugin){
			try {
				$sPlugin::createUser($oUser);
				break;
			}catch(Exception $oException){
				logger::log("Security: Exception thrown by plugin ".$sPlugin." when attempting to create user ".$oUser->getUsername()." (".$oException->getMessage().")",LOG_DEBUG);
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
		if(!security::isLoggedIn($iNetwork,$iStation)){
			throw new Exception("Security:  Unable to remove the user, no user is logged in on ".$iNetwork.".".$iStation);
		}

		$oLoggedInUser = security::getUser($iNetwork,$iStation);
		if(!$oLoggedInUser->isAdmin()){
			throw new Exception("Security:  Unable to remove a user, the user logged in on ".$iNetwork.".".$iStation." does not have admin rights.");
		}

		$aPlugins = security::_getAuthPlugins();
		logger::log("Security: Removing user ".$sUsername,LOG_INFO);
		foreach($aPlugins as $sPlugin){
			try {
				return $sPlugin::removeUser($sUsername);
				break;
			}catch(Exception $oException){
				logger::log("Security: Exception thrown by plugin ".$sPlugin." when attempting to remove user ".$sUsername." (".$oException->getMessage().")",LOG_DEBUG);
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
		if(!security::isLoggedIn($iNetwork,$iStation)){
			throw new Exception("Security:  Unable to setPriv, no user is logged in on ".$iNetwork.".".$iStation);
		}

		$oLoggedInUser = security::getUser($iNetwork,$iStation);
		if(!$oLoggedInUser->isAdmin()){
			throw new Exception("Security:  Unable to setPriv, the user logged in on ".$iNetwork.".".$iStation." (".$oUser->getUsername().") does not have admin rights.");
		}

		if($sPriv!='S' AND $sPriv!='U'){
			throw new Exception("Security:  ".$sPriv." is an invalid priv setting.");
		}

		$aPlugins = security::_getAuthPlugins();
		logger::log("Security: Setting priv for ".$sUsername." to ".$sPriv,LOG_INFO);
		foreach($aPlugins as $sPlugin){
			try {
				$sPlugin::setPriv($sUsername,$sPriv);
				break;
			}catch(Exception $oException){
				logger::log("Security: Exception thrown by plugin ".$sPlugin." when attempting to create user ".$oUser->getUsername()." (".$oException->getMessage().")",LOG_DEBUG);
			}
		}
	
	}

}
