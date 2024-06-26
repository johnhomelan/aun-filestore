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

	/**
 	 * @var array<int, array<int, array<mixed>>>
 	*/  	
	protected static array $aSessions = [];

	protected static \Psr\Log\LoggerInterface $oLogger;

	public static function init(\Psr\Log\LoggerInterface $oLogger): void
	{
		self::$oLogger = $oLogger;
		$aPlugins = Security::_getAuthPlugins();

	}

	/**
	 * Cleans up any sessions that have been idle for too long
	*/
	public static function houseKeeping(): void
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
	 * @return array<int, string>
	**/
	protected static function _getAuthPlugins(): array
	{
		$aReturn = [];
		$aAuthPlugis = explode(',',(string) config::getValue('security_auth_plugins'));
		foreach($aAuthPlugis as $sPlugin){
			$sClassname = "\HomeLan\FileStore\Authentication\Plugins\AuthPlugin".ucfirst($sPlugin);
			if(!class_exists($sClassname,FALSE)){
				try{
					$sClassname::init(self::$oLogger);
					$aReturn[]=$sClassname;
				}catch(Exception){
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
	public static function updateIdleTimer(int $iNetwork,int $iStation): void
	{
		if(array_key_exists($iNetwork,Security::$aSessions) AND array_key_exists($iStation,Security::$aSessions[$iNetwork])){
			Security::$aSessions[$iNetwork][$iStation]['idle']=time();
		}
	
	}

	/**
	 * Gets the last idle time for a connection
	*/
	public static function getIdleTimer(int $iNetwork, int $iStation):int
	{
		if(array_key_exists($iNetwork,Security::$aSessions) AND array_key_exists($iStation,Security::$aSessions[$iNetwork])){
			return Security::$aSessions[$iNetwork][$iStation]['idle'];
		}
		return 0;
	}

	/**
	  * Performs the login operation for a give user on a network and station
	*/
	public static function login(int $iNetwork,int $iStation,string $sUser,string $sPass): bool
	{
		$aPlugins = Security::_getAuthPlugins();
		foreach($aPlugins as $sPlugin){
			try {
				if($sPlugin::login($sUser,$sPass,$iNetwork,$iStation)){
					self::$oLogger->info("Security: Login for ".$sUser." using authplugin ".$sPlugin);
					if(!array_key_exists($iNetwork,Security::$aSessions)){
						Security::$aSessions[$iNetwork]=[];
					}
					Security::$aSessions[$iNetwork][$iStation]=['idle'=>time(), 'datetime'=>time(), 'provider'=>$sPlugin, 'user'=>$sPlugin::buildUserObject($sUser)];
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

	public static function logout(int $iNetwork, int $iStation): void
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
	  * @return null|\HomeLan\FileStore\Authentication\User
	*/
	public static function getUser(int $iNetwork,int $iStation): ?\HomeLan\FileStore\Authentication\User
	{
		if(array_key_exists($iNetwork,Security::$aSessions) AND array_key_exists($iStation,Security::$aSessions[$iNetwork])){
			return Security::$aSessions[$iNetwork][$iStation]['user'];
		}
		return null;
	}

	/**
	  * Tests if auser is logged in on a give network/station
	  *
	  * @return boolean
	*/
	public static function isLoggedIn(int $iNetwork,int $iStation): bool
	{
		if(array_key_exists($iNetwork,Security::$aSessions) AND array_key_exists($iStation,Security::$aSessions[$iNetwork])){
			return TRUE;
		}
		return FALSE;
	}

	/**
	  * Set the password for the user logged in using  network/station 
	  *
	*/
	public static function setConnectedUsersPassword(int $iNetwork,int $iStation,?string $sOldPassword,?string $sPassword): void
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
	 * @return array<int, array<int, array<mixed>>>
	*/
	public static function getUsersOnline(): array
	{
		return  Security::$aSessions;
	}

	/**
 	  * Gets the list of network/stations a using is logged on via
 	  *
 	  * @return array<string, int>
 	*/  	
	public static function getUsersStation(string $sUsername): array
	{
		foreach(Security::$aSessions as $iNetwork=>$aStationUsers){
			foreach($aStationUsers as $iStation=>$aData){
				$oUser = $aData['user'];
				if(strtoupper((string) $oUser->getUsername())==trim(strtoupper((string) $sUsername))){
					return ['station'=>$iStation, 'network'=>$iNetwork];	
				}
			}
		}
		return [];	
	}

	/**
 	  * Gets the list of all the users logged in and the plugin providing that user
 	  *
 	  * @return array<int, array{'plugin':string, 'user':mixed}>
 	*/
	public static function getAllUsers(): array
	{
		$aReturn = [];
		$aPlugins = Security::_getAuthPlugins();
		foreach($aPlugins as $sPlugin){
			$aUsers = $sPlugin::getAllUsers();
			foreach($aUsers as $oUser){
				$aReturn[] = ['plugin'=>substr((string) $sPlugin, strrpos((string) $sPlugin, '\\') + 1), 'user'=>$oUser];
			}
		}
		return $aReturn;
	}

	/**
	  * Creates a new user (assuming the user logged in on the given network/station has admin rights)
	  *
	  * @param null|\HomeLan\FileStore\Authentication\User $oUser
	*/
	public static function createUser(int $iNetwork,int $iStation,$oUser): void
	{
		if(!is_object($oUser) OR $oUser::class!=\HomeLan\FileStore\Authentication\User::class){
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
		$bCreated = false;
		foreach($aPlugins as $sPlugin){
			try {
				$sPlugin::createUser($oUser);
				$bCreated = true;
				break;
			}catch(Exception $oException){
				self::$oLogger->debug("Security: Exception thrown by plugin ".$sPlugin." when attempting to create user ".$oUser->getUsername()." (".$oException->getMessage().")");
			}
		}
		if(!$bCreated){
			//No user was added
			self::$oLogger->debug("Security: None of the authplugins would handle creating the user ".$oUser->getUsername());
			throw new Exception("Security: None of the authplugins would handle creating the user ".$oUser->getUsername());
		}
	
	}

	/**
	  * Removes a user (assuming the user logged in on the given network/station has admin rights)
	*/
	public static function removeUser(int $iNetwork,int $iStation,string $sUsername):bool
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
			}catch(Exception $oException){
				self::$oLogger->debug("Security: Exception thrown by plugin ".$sPlugin." when attempting to remove user ".$sUsername." (".$oException->getMessage().")");
			}
		}
		return false;
	
	}

	/**
	  * Sets the privilage flag for a user (assuming the user logged in on the given network/station has admin rights)
	  *
	  * @param string $sPriv (S|U)
	*/
	public static function setPriv(int $iNetwork,int $iStation,string $sUsername,string $sPriv): void
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
				self::$oLogger->debug("Security: Exception thrown by plugin ".$sPlugin." when attempting to set priv for ".$sUsername." by  ".$oLoggedInUser->getUsername()." to ".$sPriv." (".$oException->getMessage().")");
			}
		}
	
	}

	/**
	  * Sets the boot option for a user 
	  *
	  * @param string $sOpt
	*/
	public static function setOpt(int $iNetwork,int $iStation,string $sOpt): void
	{
		if(!Security::isLoggedIn($iNetwork,$iStation)){
			throw new Exception("Security:  Unable to setPriv, no user is logged in on ".$iNetwork.".".$iStation);
		}

		$oLoggedInUser = Security::getUser($iNetwork,$iStation);


		$aPlugins = Security::_getAuthPlugins();
		self::$oLogger->info("Security: Setting opt for ".$oLoggedInUser->getUsername()." to ".$sOpt);
		foreach($aPlugins as $sPlugin){
			try {
				$sPlugin::setOpt($oLoggedInUser->getUsername(),$sOpt);
				break;
			}catch(Exception $oException){
				self::$oLogger->debug("Security: Exception thrown by plugin ".$sPlugin." when attempting to set opt for ".$oLoggedInUser->getUsername()." to ".$sOpt." (".$oException->getMessage().")");
			}
		}
	
	}

}
