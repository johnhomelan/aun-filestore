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
 * Passwords are stored as `<hashtype>-<hash>`, e.g. `md5-5f4dcc3b5aa765d61d8327deb882cf99`.
 * Supported hash types are `plain`, `md5`, `sha1` (all unsalted) and
 * `bcrypt` (salted, via PHP's password_hash()/password_verify(), which embeds
 * a random per-password salt and the cost in the stored hash itself).
 * `security_plugin_file_default_crypt` controls what setPassword()/
 * setPasswordAdmin() write out for new passwords; it defaults to `bcrypt`.
 *
 * @package coreauth
 * @author John Brown <john@home-lan.co.uk>
 *
 * @phpstan-type AuthFileUser array{username:string,password:?string,homedir:string,unixuid:int,opt:string,priv:string,quota:int}
*/

class AuthPluginFile implements AuthPluginInterface {

	/**
 	 * @var array<string, AuthFileUser>
 	*/
	protected static array $aUsers = [];
	protected static \Psr\Log\LoggerInterface $oLogger;

	/**
	 * Thin wrapper around file_exists(), overridden by tests to mock the filesystem
	*/
	static protected function _fileExists(string $sPath): bool
	{
		return file_exists($sPath);
	}

	/**
	 * Thin wrapper around file_get_contents(), overridden by tests to mock the filesystem
	*/
	static protected function _readFile(string $sPath): string
	{
		$mContents = file_get_contents($sPath);
		return $mContents===FALSE ? '' : $mContents;
	}

	/**
	 * Thin wrapper around file_put_contents(), overridden by tests to mock the filesystem
	*/
	static protected function _writeFile(string $sPath, string $sContents): void
	{
		file_put_contents($sPath, $sContents);
	}

	/**
	 * Builds the flat file representation of the currently loaded users
	*/
	static protected function _buildUserFileContents(): string
	{
		$sUserFileContents = "";
		foreach(AuthPluginFile::$aUsers as $aUserInfo){
			$sUserFileContents = $sUserFileContents . $aUserInfo['username'].':'.$aUserInfo['password'].':'.$aUserInfo['homedir'].':'.$aUserInfo['unixuid'].':'.$aUserInfo['opt'].":".$aUserInfo['priv'].":".$aUserInfo['quota']."\n";
		}
		return $sUserFileContents;
	}

	static protected function _writeOutUserFile(): void
	{
		$sPath = config::getValueAsString('security_plugin_file_user_file');
		if(strlen($sPath)>0){
			static::_writeFile($sPath, static::_buildUserFileContents());
		}
	}

	/**
	 * Intiailizes this plugins data structures
	 *
	 * Load the user list from disk
	 * @param string $sUsers The contents of the userfile can be supplied as an arg, this should be mainly used for testing
	*/
	static public function init(\Psr\Log\LoggerInterface $oLogger, $sUsers=NULL): void
	{
		self::$oLogger = $oLogger;

		AuthPluginFile::$aUsers = [];
		if(is_null($sUsers)){
			$sPath = config::getValueAsString('security_plugin_file_user_file');
			if(!static::_fileExists($sPath)){
				self::$oLogger->error("AuthPluginFile: The user file (".$sPath.") does not exist.");
				return;
			}
			$sUsers = static::_readFile($sPath);
		}
		$aLines = explode("\n",(string) $sUsers);
		foreach($aLines as $sLine){
			$aMatches = [];
			// Format with password hash (7-field: +quota; 6-field: legacy, quota defaults to 0)
			// The hash itself is matched as "anything but a colon" so salted formats
			// (e.g. bcrypt's $2y$10$... which contains '$' and '.' and '/') parse correctly.
			if(preg_match('/([a-zA-Z0-9]+):([a-z0-9]+-[^:]+):([$a-z0-9A-Z\-._]+):([0-9]+):([0-9]):([A-Za-z]):?([0-9]*)/',$sLine,$aMatches)>0){
				AuthPluginFile::$aUsers[strtoupper($aMatches[1])]=['username'=>strtoupper($aMatches[1]), 'password'=>$aMatches[2], 'homedir'=>$aMatches[3], 'unixuid'=>(int) $aMatches[4], 'opt'=>$aMatches[5], 'priv'=>$aMatches[6], 'quota'=>(int)$aMatches[7]];
			}
			// Format with no password set (7-field: +quota; 6-field: legacy, quota defaults to 0)
			$aMatches=[];
			if(preg_match('/([a-zA-Z0-9]+)::([$a-z0-9A-Z\-._]+):([0-9]+):([0-9]):([A-Za-z]):?([0-9]*)/',$sLine,$aMatches)>0){
				AuthPluginFile::$aUsers[strtoupper($aMatches[1])]=['username'=>strtoupper($aMatches[1]), 'password'=>'', 'homedir'=>$aMatches[2], 'unixuid'=>(int) $aMatches[3], 'opt'=>$aMatches[4], 'priv'=>$aMatches[5], 'quota'=>(int)$aMatches[6]];
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
	static public function login(string $sUsername, ?string $sPassword, ?int $iNetwork=NULL, ?int $iStation=NULL): bool
	{
		if($sPassword === NULL){
			return FALSE;
		}
		if(!array_key_exists(strtoupper($sUsername),AuthPluginFile::$aUsers)){
			return FALSE;
		}
		$sStored = (string) AuthPluginFile::$aUsers[strtoupper($sUsername)]['password'];
		if(str_contains($sStored,'-')){
			[$sHashType, $sHash] = explode('-',$sStored,2);
		}else{
			$sHashType='plain';
			$sHash = $sStored;
		}
		switch($sHashType){
			case 'bcrypt':
				//Salted, password_verify() does its own comparison so it doesn't fall through
				return password_verify($sPassword,$sHash);
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
	 * @param string $sUsername The username of the user to be built
	 * @return \HomeLan\FileStore\Authentication\User
	*/
	static public function buildUserObject(string $sUsername): \HomeLan\FileStore\Authentication\User
	{
		$oUser = new User();
		if(array_key_exists(strtoupper($sUsername),AuthPluginFile::$aUsers)){
			$aData = AuthPluginFile::$aUsers[strtoupper($sUsername)];
			$oUser->setUsername($aData['username']);
			$oUser->setUnixUid($aData['unixuid']);
			$oUser->setHomedir($aData['homedir']);
			$oUser->setBootOpt((int) $aData['opt']);
			$oUser->setPriv($aData['priv']);
			$oUser->setQuota($aData['quota']);
		}
		return $oUser;
	}

	/**
	 * Gets all the user objects know to the plugin
	 *
	 * @return array<int, \HomeLan\FileStore\Authentication\User>
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
	static public function setPassword(string $sUsername,?string $sOldPassword,?string $sPassword): void
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
				AuthPluginFile::$aUsers[strtoupper($sUsername)]['password'] = match (config::getValueAsString('security_plugin_file_default_crypt')) {
					'plain' => 'plain-'.$sPassword,
					'sha1' => 'sha1-'.sha1($sPassword),
					'md5' => 'md5-'.md5($sPassword),
					default => 'bcrypt-'.password_hash($sPassword, PASSWORD_BCRYPT),
				};
			}
		}
		static::_writeOutUserFile();
	}

	/**
	 * Creates a new user in the backend
	 * 
	 * This method should not dertain if a user can create another security does that
	 *
	 * @param \HomeLan\FileStore\Authentication\User $oUser The user object that should be added to the backend
	*/
	static public function createUser(\HomeLan\FileStore\Authentication\User $oUser): void
	{
		if(!array_key_exists(strtoupper((string) $oUser->getUsername()),AuthPluginFile::$aUsers)){
			AuthPluginFile::$aUsers[strtoupper((string) $oUser->getUsername())]=['username'=>$oUser->getUsername() ?? '', 'password'=>'', 'homedir'=>$oUser->getHomedir() ?? '', 'unixuid'=>$oUser->getUnixUid() ?? 0, 'opt'=>(string) $oUser->getBootOpt(), 'priv'=>$oUser->getPriv(), 'quota'=>$oUser->getQuota()];
			static::_writeOutUserFile();
		}else{
			throw new Exception("User exists");
		}
	}

	/**
	 * Removes a user from the backend
	 * 
	 * This method should not dertain if a user can remove another security does that
	 *
	 * @param string $sUsername
	*/
	static public function removeUser(string $sUsername): bool
	{
		if(array_key_exists(strtoupper($sUsername),AuthPluginFile::$aUsers)){
			unset(AuthPluginFile::$aUsers[strtoupper($sUsername)]);
			static::_writeOutUserFile();
			return TRUE;
		}else{
			throw new Exception("User does not exists");
		}
	}

	/**
	 * Sets the priv flag for a given user
	 *
	*/  	
	static public function setPriv(string $sUsername,string $sPriv): void
	{
		if(array_key_exists(strtoupper($sUsername),AuthPluginFile::$aUsers)){
			AuthPluginFile::$aUsers[strtoupper($sUsername)]['priv']=$sPriv;
			static::_writeOutUserFile();
		}
	}

	/**
	 * Sets the boot option for a given user
	 *
	*/
	static public function setOpt(string $sUsername,string $sOpt): void
	{
		if(array_key_exists(strtoupper($sUsername),AuthPluginFile::$aUsers)){
			AuthPluginFile::$aUsers[strtoupper($sUsername)]['opt']=$sOpt;
			static::_writeOutUserFile();
		}
	}

	/**
	 * Sets the disc quota for a given user (0 = use global default)
	 *
	*/
	static public function setQuota(string $sUsername, int $iQuota): void
	{
		if(array_key_exists(strtoupper($sUsername),AuthPluginFile::$aUsers)){
			AuthPluginFile::$aUsers[strtoupper($sUsername)]['quota']=$iQuota;
			static::_writeOutUserFile();
		}
	}

	/**
	 * Sets the password for a user without requiring the old password (sysop use only)
	 *
	*/
	static public function setPasswordAdmin(string $sUsername, string $sPassword): void
	{
		if(!array_key_exists(strtoupper($sUsername),AuthPluginFile::$aUsers)){
			throw new Exception("User does not exist");
		}
		AuthPluginFile::$aUsers[strtoupper($sUsername)]['password'] = match (config::getValueAsString('security_plugin_file_default_crypt')) {
			'plain' => 'plain-'.$sPassword,
			'sha1'  => 'sha1-'.sha1($sPassword),
			'md5'   => 'md5-'.md5($sPassword),
			default => 'bcrypt-'.password_hash($sPassword, PASSWORD_BCRYPT),
		};
		static::_writeOutUserFile();
	}


}
