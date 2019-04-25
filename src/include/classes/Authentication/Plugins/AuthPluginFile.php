<?php
/**
 * File containing the AuthPluginFile class
 *
 * @package coreauth
*/
namespace HomeLan\FileStore\Authentication\Plugins; 

use HomeLan\FileStore\Authentication\User;
use config;
use Exception;

/**
 * This class is a plugin for the auth system, it provides an auth backend
 * based on using a simple plain text user file.
 * 
 * @package coreauth
 * @author John Brown <john@home-lan.co.uk>
*/

class AuthPluginFile implements AuthPluginInterface {

	protected static $aUsers = array();
	protected static $oLogger;

	static protected function _writeOutUserFile(): void
	{
		$sUserFileContents = "";
		if(strlen(config::getValue('security_plugin_file_user_file'))>0){
			foreach(AuthPluginFile::$aUsers as $aUserInfo){
				$sUserFileContents = $sUserFileContents . $aUserInfo['username'].':'.$aUserInfo['password'].':'.$aUserInfo['homedir'].':'.$aUserInfo['unixuid'].':'.$aUserInfo['opt'].":".$aUserInfo['priv']."\n";
			}
			file_put_contents(config::getValue('security_plugin_file_user_file'),$sUserFileContents);
		}
	}

	/**
	 * Intiailizes this plugins data structures
	 *
	 * Load the user list from disk
	 * @param string $sUser The contents of the userfile can be supplied as an arg, this should be mainly used for testing
	*/
	static public function init(\Psr\Log\LoggerInterface $oLogger, $sUsers=NULL): void
	{
		self::$oLogger = $oLogger;

		AuthPluginFile::$aUsers = array();
		if(is_null($sUsers)){
			if(!file_exists(config::getValue('security_plugin_file_user_file'))){
				self::$oLogger->error("AuthPluginFile: The user file (".config::getValue('security_plugin_file_user_file').") does not exist.");
				return;
			}
			$sUsers = file_get_contents(config::getValue('security_plugin_file_user_file'));
		}
		$aLines = explode("\n",$sUsers);
		foreach($aLines as $sLine){
			$aMatches = array();
			//The file format is username:pwhashtype-hash:homedir:unixuid:opt
			if(preg_match('/([a-zA-Z0-9]+):([a-z0-9]+-[a-zA-Z0-9]+):([$a-z0-9A-Z\-._]+):([0-9]+):([0-9]):([A-Za-z])/',$sLine,$aMatches)>0){
				AuthPluginFile::$aUsers[strtoupper($aMatches[1])]=array('username'=>strtoupper($aMatches[1]),'password'=>$aMatches[2],'homedir'=>$aMatches[3],'unixuid'=>$aMatches[4],'opt'=>$aMatches[5],'priv'=>$aMatches[6]);
			}
			//Match with no password set
			$aMatches=array();
			if(preg_match('/([a-zA-Z0-9]+)::([$a-z0-9A-Z\-._]+):([0-9]+):([0-9]):([A-Za-z])/',$sLine,$aMatches)>0){
				AuthPluginFile::$aUsers[strtoupper($aMatches[1])]=array('username'=>strtoupper($aMatches[1]),'password'=>'','homedir'=>$aMatches[2],'unixuid'=>$aMatches[3],'opt'=>$aMatches[4],'priv'=>$aMatches[5]);
			}
		}
	}


	/**
	 * Checks the username and password credentials supplied against the auth file loaded from disk
	 *
	 * @param string $sUsername
	 * @param string $sPassword 
	 * @param int $iNetwork As the file auth plugin can't restrict by network this param is not used but is here so we implement the interface correctly.
	 * @param int $iStation As the file auth plugin can't restrict by station  this param is not used but is here so we implement the interface correctly.
	 * @return boolean
	*/
	static public function login(string $sUsername, string $sPassword, ?int $iNetwork=NULL, ?int $iStation=NULL): bool
	{	
		if(!array_key_exists(strtoupper($sUsername),AuthPluginFile::$aUsers)){
			return FALSE;
		}
		if(strpos(AuthPluginFile::$aUsers[strtoupper($sUsername)]['password'],'-')!==FALSE){
			list($sHashType,$sHash) = explode('-',AuthPluginFile::$aUsers[strtoupper($sUsername)]['password']);
		}else{
			$sHashType='plain';
			$sHash = AuthPluginFile::$aUsers[strtoupper($sUsername)]['password'];
		}
		switch($sHashType){
			case 'plain':
				if($sPassword==$sHash){
					return TRUE;
				}
			case 'sha1':
				if(sha1($sPassword)==$sHash){
					return TRUE;
				}
			case 'md5':
			default:
				if(md5($sPassword)==$sHash){
					return TRUE;
				}
				break;
		}
		return FALSE;
	}

	/**
	 * Creates a user object based of the auth data stored in the plugin 
	 *
	 * @param string $sUsername
	 * @return object user
	*/
	static public function buildUserObject(string $sUsername): \HomeLan\FileStore\Authentication\User
	{
		$oUser = new User();
		if(array_key_exists(strtoupper($sUsername),AuthPluginFile::$aUsers)){
			$oUser->setUsername(AuthPluginFile::$aUsers[strtoupper($sUsername)]['username']);
			$oUser->setUnixUid(AuthPluginFile::$aUsers[strtoupper($sUsername)]['unixuid']);
			$oUser->setHomedir(AuthPluginFile::$aUsers[strtoupper($sUsername)]['homedir']);
			$oUser->setBootOpt(AuthPluginFile::$aUsers[strtoupper($sUsername)]['opt']);
			$oUser->setPriv(AuthPluginFile::$aUsers[strtoupper($sUsername)]['priv']);
		}
		return $oUser;
	}

	/**
	 * Gets all the user objects know to the plugin
	 *
	*/
	static public function getAllUsers(): array
	{
		$aReturn = [];
		foreach(AuthPluginFile::$aUsers as $sUserName => $aUserData){
			$aReturn[] = AuthPluginFile::buildUserObject($sUserName);
		}
		return $aReturn;
	}

	/**
	 * Set the password for a given user
	 *
	 * This causes the on disk password file to be updated
	 * @param string $sUsername
	 * @param string $sOldPassword Can be null if the old password is blank
	 * @param string $sPassword
	*/
	static public function setPassword(string $sUsername,string $sOldPassword,string $sPassword): void
	{
		//Test old password
		if(!AuthPluginFile::login($sUsername,$sOldPassword,NULL,NULL)){
			throw new Exception("Old password was incorrect.");
		}	
		//Set new password 
		if(array_key_exists(strtoupper($sUsername),AuthPluginFile::$aUsers)){
			if(is_null($sPassword)){
				AuthPluginFile::$aUsers[strtoupper($sUsername)]['password']=NULL;
			}else{
				switch(config::getValue('security_plugin_file_default_crypt')){
					case 'plain':
						AuthPluginFile::$aUsers[strtoupper($sUsername)]['password']='plain-'.$sPassword;
						break;
					case 'sha1':
						AuthPluginFile::$aUsers[strtoupper($sUsername)]['password']='sha1-'.sha1($sPassword);
						break;
					case 'md5':
					default:
						AuthPluginFile::$aUsers[strtoupper($sUsername)]['password']='md5-'.md5($sPassword);
						break;
				}
			}
		}
		AuthPluginFile::_writeOutUserFile();
	}

	/**
	 * Creates a new user in the backend
	 * 
	 * This method should not dertain if a user can create another security does that
	 *
	 * @param object user $oUser The user object that should be added to the backend
	*/
	static public function createUser($oUser): void
	{
		if(!array_key_exists(strtoupper($oUser->getUsername()),AuthPluginFile::$aUsers)){
			AuthPluginFile::$aUsers[strtoupper($oUser->getUsername())]=array('username'=>$oUser->getUsername(),'password'=>'','homedir'=>$oUser->getHomedir(),'unixuid'=>$oUser->getUnixUid(),'opt'=>$oUser->getBootOpt(),'priv'=>$oUser->getPriv());
			AuthPluginFile::_writeOutUserFile();
		}else{
			throw new Exception("User exists");
		}
	}

	/**
	 * Removes a user from the backend
	 * 
	 * This method should not dertain if a user can remove another security does that
	 *
	 * @param string username
	*/
	static public function removeUser(string $sUsername): bool
	{
		if(array_key_exists(strtoupper($sUsername),AuthPluginFile::$aUsers)){
			unset(AuthPluginFile::$aUsers[strtoupper($sUsername)]);
			AuthPluginFile::_writeOutUserFile();
			return TRUE;
		}else{
			throw new Exception("User does not exists");
		}
	}

	static public function setPriv(string $sUsername,string $sPriv): void
	{
		if(array_key_exists(strtoupper($sUsername),AuthPluginFile::$aUsers)){
			AuthPluginFile::$aUsers[strtoupper($sUsername)]['priv']=$sPriv;
			AuthPluginFile::_writeOutUserFile();
		}
	}

}
