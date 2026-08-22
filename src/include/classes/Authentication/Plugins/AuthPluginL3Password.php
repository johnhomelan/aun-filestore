<?php
/**
 * File containing the AuthPluginL3Password class
 *
 * @package coreauth
*/
namespace HomeLan\FileStore\Authentication\Plugins;

use HomeLan\FileStore\Authentication\User;
use config;
use Exception;

/**
 * This class is a plugin for the auth system, it provides an auth backend
 * that reads/writes the binary `PASSWORD` file format used by the Acorn
 * Level 3 fileserver, FileStore and awServer.
 *
 * The format is documented at https://heyrick.eu/econet/fs/pass.html :
 *  - Each user occupies a fixed 31 byte record, the file is padded out to a
 *    whole 256 byte sector.
 *  - Bytes 0-19  Username, null padded/terminated. A null (empty) username
 *                marks a deleted/unused slot.
 *  - Bytes 20-25 Password, null padded/terminated, max 6 characters.
 *  - Bytes 26-29 Free space quota, an unsigned 32bit little endian integer.
 *  - Byte  30    Boot option (low nibble) and privilege (high nibble).
 *
 * What the above documentation does not mention is that every character of
 * the on disk password has bit 7 (0x80) forced high, turning it into what
 * looks like a run of high-bit-set "garbage" bytes. Masking each stored
 * byte with 0x7F recovers the original ASCII character, and bit 7 must be
 * set again on every character before the password is written back out.
 *
 * @package coreauth
 * @author John Brown <john@home-lan.co.uk>
 *
 * @phpstan-type L3PasswordUser array{username:string,password:string,opt:int,priv:string,locked:bool,quota:int}
*/
class AuthPluginL3Password implements AuthPluginInterface {

	protected const RECORD_LENGTH = 31;
	protected const USERNAME_LENGTH = 20;
	protected const PASSWORD_LENGTH = 6;
	protected const QUOTA_LENGTH = 4;
	protected const SECTOR_SIZE = 256;

	//Privilege is stored in the high nibble of byte 30, boot option in the low nibble
	protected const PRIV_SYSTEM = 0xC0;
	protected const PRIV_NORMAL = 0x80;
	protected const PRIV_LOCKED = 0xA0;

	/**
 	 * @var array<string, L3PasswordUser>
 	*/
	protected static array $aUsers = [];
	protected static \Psr\Log\LoggerInterface $oLogger;

	/**
	 * Encodes a single user record back to its 31 byte on disk representation
	 *
	 * @param L3PasswordUser $aUserInfo
	*/
	static protected function _encodeRecord(array $aUserInfo): string
	{
		$sUsernameField = str_pad(substr($aUserInfo['username'], 0, self::USERNAME_LENGTH), self::USERNAME_LENGTH, "\0");

		//Every password character has bit 7 set high on disk
		$sPasswordField = "";
		foreach(str_split(substr($aUserInfo['password'], 0, self::PASSWORD_LENGTH)) as $sChar){
			$sPasswordField .= chr(ord($sChar) | 0x80);
		}
		$sPasswordField = str_pad($sPasswordField, self::PASSWORD_LENGTH, "\0");

		$sQuotaField = pack('V', $aUserInfo['quota']);

		$iPrivByte = match(TRUE){
			$aUserInfo['priv']==='S' => self::PRIV_SYSTEM,
			$aUserInfo['locked'] => self::PRIV_LOCKED,
			default => self::PRIV_NORMAL,
		};
		$sByte30 = chr($iPrivByte | ($aUserInfo['opt'] & 0x0F));

		return $sUsernameField.$sPasswordField.$sQuotaField.$sByte30;
	}

	/**
	 * Decodes a single 31 byte on disk record
	 *
	 * @return ?L3PasswordUser Null if the record is an empty/deleted slot
	*/
	static protected function _decodeRecord(string $sRecord): ?array
	{
		if(strlen($sRecord)<self::RECORD_LENGTH){
			return NULL;
		}

		$sUsernameRaw = substr($sRecord, 0, self::USERNAME_LENGTH);
		$iNull = strpos($sUsernameRaw, "\0");
		$sUsername = $iNull===FALSE ? $sUsernameRaw : substr($sUsernameRaw, 0, $iNull);
		if($sUsername===''){
			//Null username, a deleted/unused slot
			return NULL;
		}

		//The on disk password has bit 7 set on every char, mask it back off to recover ASCII
		$sPasswordRaw = substr($sRecord, self::USERNAME_LENGTH, self::PASSWORD_LENGTH);
		$sPassword = "";
		for($i=0; $i<strlen($sPasswordRaw); $i++){
			$iByte = ord($sPasswordRaw[$i]) & 0x7F;
			if($iByte===0){
				break;
			}
			$sPassword .= chr($iByte);
		}

		//Unsigned 32bit little endian integer
		$sQuotaRaw = substr($sRecord, self::USERNAME_LENGTH+self::PASSWORD_LENGTH, self::QUOTA_LENGTH);
		$iQuota = ord($sQuotaRaw[0]) | (ord($sQuotaRaw[1])<<8) | (ord($sQuotaRaw[2])<<16) | (ord($sQuotaRaw[3])<<24);

		$iByte30 = ord($sRecord[self::USERNAME_LENGTH+self::PASSWORD_LENGTH+self::QUOTA_LENGTH]);
		$iOpt = $iByte30 & 0x0F;

		$sPriv = 'U';
		$bLocked = FALSE;
		if(($iByte30 & 0xF0)===self::PRIV_LOCKED){
			$bLocked = TRUE;
		}elseif(($iByte30 & 0xF0)===self::PRIV_SYSTEM || ($iByte30 & 0xF0)===0x00){
			$sPriv = 'S';
		}

		return ['username'=>strtoupper($sUsername), 'password'=>$sPassword, 'opt'=>$iOpt, 'priv'=>$sPriv, 'locked'=>$bLocked, 'quota'=>$iQuota];
	}

	static protected function _writeOutUserFile(): void
	{
		if(strlen(config::getValueAsString('security_plugin_l3password_file'))===0){
			return;
		}
		$sData = "";
		foreach(AuthPluginL3Password::$aUsers as $aUserInfo){
			$sData = $sData.static::_encodeRecord($aUserInfo);
		}
		$iPad = (self::SECTOR_SIZE - (strlen($sData) % self::SECTOR_SIZE)) % self::SECTOR_SIZE;
		$sData = $sData.str_repeat("\0", $iPad);
		file_put_contents(config::getValueAsString('security_plugin_l3password_file'), $sData);
	}

	/**
	 * Intiailizes this plugins data structures
	 *
	 * Loads the binary password file from disk
	 * @param string $sUsers The raw contents of the password file can be supplied as an arg, this should be mainly used for testing
	*/
	static public function init(\Psr\Log\LoggerInterface $oLogger, $sUsers=NULL): void
	{
		self::$oLogger = $oLogger;

		AuthPluginL3Password::$aUsers = [];
		if(is_null($sUsers)){
			$sPath = config::getValueAsString('security_plugin_l3password_file');
			if(strlen($sPath)===0 || !file_exists($sPath)){
				self::$oLogger->error("AuthPluginL3Password: The password file (".$sPath.") does not exist.");
				return;
			}
			$mUsers = file_get_contents($sPath);
			$sUsers = $mUsers===FALSE ? '' : $mUsers;
		}

		$sUsers = (string) $sUsers;
		$iLength = strlen($sUsers);
		for($iOffset=0; $iOffset+self::RECORD_LENGTH<=$iLength; $iOffset+=self::RECORD_LENGTH){
			$aRecord = static::_decodeRecord(substr($sUsers, $iOffset, self::RECORD_LENGTH));
			if($aRecord!==NULL){
				AuthPluginL3Password::$aUsers[$aRecord['username']] = $aRecord;
			}
		}
	}

	/**
	 * Checks the username and password credentials supplied against the password file loaded from disk
	 *
	 * @param string $sUsername
	 * @param string $sPassword
	 * @param int $iNetwork As this plugin can't restrict by network this param is not used but is here so we implement the interface correctly.
	 * @param int $iStation As this plugin can't restrict by station this param is not used but is here so we implement the interface correctly.
	 * @return boolean
	*/
	static public function login(string $sUsername, ?string $sPassword, ?int $iNetwork=NULL, ?int $iStation=NULL): bool
	{
		if($sPassword===NULL){
			return FALSE;
		}
		$sUsername = strtoupper($sUsername);
		if(!array_key_exists($sUsername, AuthPluginL3Password::$aUsers)){
			return FALSE;
		}
		if(AuthPluginL3Password::$aUsers[$sUsername]['locked']){
			//Locked accounts may not log in
			return FALSE;
		}
		return $sPassword===AuthPluginL3Password::$aUsers[$sUsername]['password'];
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
		$sUsername = strtoupper($sUsername);
		if(array_key_exists($sUsername, AuthPluginL3Password::$aUsers)){
			$aData = AuthPluginL3Password::$aUsers[$sUsername];
			$oUser->setUsername($aData['username']);
			//The L3 password file format has no home directory field, a user's URD is their username
			$oUser->setHomedir($aData['username']);
			$oUser->setBootOpt($aData['opt']);
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
		foreach(AuthPluginL3Password::$aUsers as $sUsername => $aUserData){
			$aReturn[] = AuthPluginL3Password::buildUserObject($sUsername);
		}
		return $aReturn;
	}

	/**
	 * Set the password for a given user
	 *
	 * This causes the on disk password file to be updated. Bit 7 is set high
	 * on every stored character to match the on disk format.
	 *
	 * @param string $sUsername
	 * @param string $sOldPassword Can be empty if the old password is blank
	 * @param string $sPassword
	*/
	static public function setPassword(string $sUsername, ?string $sOldPassword, ?string $sPassword): void
	{
		if(!AuthPluginL3Password::login($sUsername, $sOldPassword)){
			throw new Exception("Old password was incorrect.");
		}
		$sUsername = strtoupper($sUsername);
		if(!array_key_exists($sUsername, AuthPluginL3Password::$aUsers)){
			throw new Exception("User does not exist");
		}
		$sPassword = $sPassword ?? '';
		if(strlen($sPassword)>self::PASSWORD_LENGTH){
			throw new Exception("Password is too long, the L3 password file format supports a maximum of ".self::PASSWORD_LENGTH." characters.");
		}
		AuthPluginL3Password::$aUsers[$sUsername]['password'] = $sPassword;
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
		$sUsername = strtoupper((string) $oUser->getUsername());
		if($sUsername===''){
			throw new Exception("Username cannot be empty");
		}
		if(strlen($sUsername)>self::USERNAME_LENGTH){
			throw new Exception("Username is too long, the L3 password file format supports a maximum of ".self::USERNAME_LENGTH." characters.");
		}
		if(array_key_exists($sUsername, AuthPluginL3Password::$aUsers)){
			throw new Exception("User exists");
		}
		AuthPluginL3Password::$aUsers[$sUsername] = ['username'=>$sUsername, 'password'=>'', 'opt'=>$oUser->getBootOpt(), 'priv'=>$oUser->getPriv(), 'locked'=>FALSE, 'quota'=>$oUser->getQuota()];
		static::_writeOutUserFile();
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
		$sUsername = strtoupper($sUsername);
		if(array_key_exists($sUsername, AuthPluginL3Password::$aUsers)){
			unset(AuthPluginL3Password::$aUsers[$sUsername]);
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
	static public function setPriv(string $sUsername, string $sPriv): void
	{
		$sUsername = strtoupper($sUsername);
		if(array_key_exists($sUsername, AuthPluginL3Password::$aUsers)){
			AuthPluginL3Password::$aUsers[$sUsername]['priv'] = $sPriv;
			//An explicit priv change always clears any Locked state read from disk
			AuthPluginL3Password::$aUsers[$sUsername]['locked'] = FALSE;
			static::_writeOutUserFile();
		}
	}

	/**
	 * Sets the boot option for a given user
	 *
	*/
	static public function setOpt(string $sUsername, string $sOpt): void
	{
		$sUsername = strtoupper($sUsername);
		if(array_key_exists($sUsername, AuthPluginL3Password::$aUsers)){
			AuthPluginL3Password::$aUsers[$sUsername]['opt'] = (int) $sOpt;
			static::_writeOutUserFile();
		}
	}

	/**
	 * Sets the disc quota for a given user (0 = use global default)
	 *
	*/
	static public function setQuota(string $sUsername, int $iQuota): void
	{
		$sUsername = strtoupper($sUsername);
		if(array_key_exists($sUsername, AuthPluginL3Password::$aUsers)){
			AuthPluginL3Password::$aUsers[$sUsername]['quota'] = $iQuota;
			static::_writeOutUserFile();
		}
	}

	/**
	 * Sets the password for a user without requiring the old password (sysop use only)
	 *
	*/
	static public function setPasswordAdmin(string $sUsername, string $sPassword): void
	{
		$sUsername = strtoupper($sUsername);
		if(!array_key_exists($sUsername, AuthPluginL3Password::$aUsers)){
			throw new Exception("User does not exist");
		}
		if(strlen($sPassword)>self::PASSWORD_LENGTH){
			throw new Exception("Password is too long, the L3 password file format supports a maximum of ".self::PASSWORD_LENGTH." characters.");
		}
		AuthPluginL3Password::$aUsers[$sUsername]['password'] = $sPassword;
		static::_writeOutUserFile();
	}

}
