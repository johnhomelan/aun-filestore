<?php
/**
 * File containing the AuthPluginMdfsPassword class
 *
 * @package coreauth
*/
namespace HomeLan\FileStore\Authentication\Plugins;

use HomeLan\FileStore\Authentication\User;
use config;
use Exception;

/**
 * This class is a plugin for the auth system, it provides an auth backend
 * that reads/writes the binary `%PASSWORDS` file format used by the SJ
 * Research MDFS fileserver.
 *
 * The format is documented in the MDFS manual, section 10.22 "Password
 * File Format" :-
 *  - The file starts with a 64 byte header, an alphabetic index used by
 *    real hardware to jump straight to the right part of the (sorted)
 *    user list; it is not needed for lookups here (a hashmap by username
 *    is used instead) but is rebuilt correctly on every write so a real
 *    MDFS fileserver could still read the file back.
 *  - Each user then occupies a fixed 64 byte record, terminated by a
 *    single all `&FF` 64 byte record.
 *  - Bytes 0-8  User identifier, terminated by a <CR> if less than 9
 *               characters.
 *  - Bytes 9-18 Password, terminated by a <CR> if less than 10
 *               characters.
 *  - Byte  19   Boot option.
 *  - Byte  20   Flag byte: bit0=password unlocked, bit1=system
 *               privileged, bit2=no short SAVEs, bit3=permanent *ENABLE,
 *               bit4=no library, bit5=run only user.
 *  - Bytes 21-23 Offset from the start of the file to the user's URD
 *                name string (0 = normal/default URD).
 *  - Bytes 24-26 Offset from the start of the file to the user's LIB
 *                name string (0 = normal/default LIB).
 *  - Bytes 27-28 Personal account number.
 *  - Bytes 29-31 Reserved.
 *  - Bytes 32-63 Bit map of account ownership (not available on Acorn
 *                File Servers).
 *  - Following the terminating record is a region of <CR> terminated
 *    URD/LIB override name strings, pointed to by the offsets above.
 *
 * This plugin decodes/encodes username, password, boot option and the
 * priv/locked flag bits as first class fields (the same fidelity level
 * as AuthPluginL3Password). The URD/LIB override strings are read in and
 * re-written verbatim but not otherwise interpreted; the reserved bytes
 * and account ownership bitmap are kept as opaque per user byte blobs and
 * written back unchanged.
 *
 * There is no per-user quota field in this format; disc space credit is
 * tracked per *account* (not per user) in a separate on disc account
 * balance table, indexed by each user's personal account number. This
 * plugin optionally loads/saves that table as a second standalone file
 * (`security_plugin_mdfspassword_accounts_file`): 256 unsigned 16 bit
 * little endian balances, each in units of 1 kilobyte (matching the
 * manual's own units for the *CREDIT/*DEBIT commands), covering account
 * numbers 0-255 - the same range as the 256 bit account ownership bitmap
 * above. A later "FS 0.AA" release note describes an optional extension
 * to up to 2048 accounts with a variable sized, sharable balance table;
 * that extension's on disk byte layout isn't documented anywhere
 * available and is not supported here. A user whose personal account
 * number is >=256, or when no accounts file is configured, simply has no
 * tracked balance and getQuota() falls back to the global default (0),
 * same as before this feature existed. Because quota is per-account,
 * two users who share a personal account number will always report the
 * same quota - that's the real MDFS accounting model, not a bug.
 *
 * Two details the manual does not spell out and that have not been
 * validated against a real captured %PASSWORDS file:
 *  - Whether the header's "entry number" is a 0 based record index
 *    (assumed here, i.e. file offset = 64 + 64*entryNumber) or something
 *    else.
 *  - The polarity of flag bit0 ("password unlocked"); this plugin
 *    assumes 0=locked, 1=unlocked.
 *
 * @package coreauth
 * @author John Brown <john@home-lan.co.uk>
 *
 * @phpstan-type MdfsPasswordUser array{username:string,password:string,opt:int,flags:int,urd:?string,lib:?string,accountNo:int,accountNoRaw:string,reservedRaw:string,accountBitmapRaw:string}
*/
class AuthPluginMdfsPassword implements AuthPluginInterface {

	protected const HEADER_LENGTH = 64;
	protected const RECORD_LENGTH = 64;
	protected const USERNAME_LENGTH = 9;
	protected const PASSWORD_LENGTH = 10;
	protected const BUCKET_COUNT = 15;

	protected const FLAG_UNLOCKED = 0x01;
	protected const FLAG_SYSTEM = 0x02;
	protected const FLAG_NO_SHORT_SAVES = 0x04;
	protected const FLAG_PERM_ENABLE = 0x08;
	protected const FLAG_NO_LIBRARY = 0x10;
	protected const FLAG_RUN_ONLY = 0x20;

	protected const ACCOUNT_COUNT = 256;

	/**
	 * @var array<string, MdfsPasswordUser>
	*/
	protected static array $aUsers = [];

	/**
	 * The username of the default user as read from the header on load, preserved across
	 * rewrites where possible (falls back to the first sorted user if it no longer exists)
	*/
	protected static ?string $sDefaultUsername = NULL;

	/**
	 * Account balances (in KB), keyed by account number 0-255. Empty when no accounts
	 * file is configured/loaded.
	 *
	 * @var array<int, int>
	*/
	protected static array $aAccountBalances = [];

	protected static \Psr\Log\LoggerInterface $oLogger;

	/**
	 * Works out which of the 15 header buckets a username's first character belongs to
	 *
	 * Bucket 0 is "< 'A'", buckets 1-13 are the letter pairs A/B .. Y/Z, bucket 14 is "> 'Z'"
	*/
	static protected function _bucketForChar(string $sChar): int
	{
		$iOrd = ord(strtoupper($sChar));
		if($iOrd < ord('A')){
			return 0;
		}
		if($iOrd > ord('Z')){
			return self::BUCKET_COUNT - 1;
		}
		return 1 + intdiv($iOrd - ord('A'), 2);
	}

	/**
	 * Reads a <CR> or NUL terminated string out of a fixed length field
	*/
	static protected function _readUntilTerminator(string $sField): string
	{
		$sReturn = "";
		for($i=0; $i<strlen($sField); $i++){
			$iByte = ord($sField[$i]);
			if($iByte===0x0D || $iByte===0x00){
				break;
			}
			$sReturn .= chr($iByte);
		}
		return $sReturn;
	}

	/**
	 * Encodes a value into a fixed length <CR> terminated field, matching the on disk format
	 * (the terminator is only written if it fits, i.e. the content is shorter than the field)
	*/
	static protected function _encodeCrField(string $sValue, int $iFieldLength): string
	{
		$sContent = substr($sValue, 0, $iFieldLength);
		if(strlen($sContent)<$iFieldLength){
			$sContent .= "\r";
		}
		return str_pad($sContent, $iFieldLength, "\0");
	}

	/**
	 * Encodes an integer as a 3 byte little endian value
	*/
	static protected function _pack24Le(int $iValue): string
	{
		return substr(pack('V', $iValue), 0, 3);
	}

	/**
	 * Decodes a 3 byte little endian value
	*/
	static protected function _unpack24Le(string $sBytes): int
	{
		return ord($sBytes[0]) | (ord($sBytes[1])<<8) | (ord($sBytes[2])<<16);
	}

	/**
	 * Decodes a 2 byte little endian value
	*/
	static protected function _unpack16Le(string $sBytes): int
	{
		return ord($sBytes[0]) | (ord($sBytes[1])<<8);
	}

	/**
	 * Builds a user's home directory path
	 *
	 * The user's URD is their username, optionally placed under a configured subdirectory
	 * (e.g. a 'security_plugin_mdfspassword_homedir_prefix' of "$.homes" gives
	 * "$.homes.username")
	*/
	static protected function _buildHomedir(string $sUsername): string
	{
		$sPrefix = rtrim(config::getValueAsString('security_plugin_mdfspassword_homedir_prefix'), '.');
		if(strlen($sPrefix)===0){
			return $sUsername;
		}
		return $sPrefix.'.'.$sUsername;
	}

	/**
	 * Resolves a URD/LIB pointer field to the string it points at, or NULL if the pointer is 0
	*/
	static protected function _resolveNameString(string $sFileData, int $iOffset): ?string
	{
		if($iOffset===0 || $iOffset>=strlen($sFileData)){
			return NULL;
		}
		return static::_readUntilTerminator(substr($sFileData, $iOffset, 80));
	}

	/**
	 * Decodes a single 64 byte on disk user record
	 *
	 * @return ?MdfsPasswordUser Null if this is the &FF terminating record
	*/
	static protected function _decodeRecord(string $sRecord, string $sFileData): ?array
	{
		if(strlen($sRecord)<self::RECORD_LENGTH || ord($sRecord[0])===0xFF){
			return NULL;
		}

		$sUsername = strtoupper(static::_readUntilTerminator(substr($sRecord, 0, self::USERNAME_LENGTH)));
		$sPassword = static::_readUntilTerminator(substr($sRecord, self::USERNAME_LENGTH, self::PASSWORD_LENGTH));
		$iOpt = ord($sRecord[19]);
		$iFlags = ord($sRecord[20]);
		$iUrdOffset = static::_unpack24Le(substr($sRecord, 21, 3));
		$iLibOffset = static::_unpack24Le(substr($sRecord, 24, 3));
		$sAccountNoRaw = substr($sRecord, 27, 2);
		$sReservedRaw = substr($sRecord, 29, 3);
		$sAccountBitmapRaw = substr($sRecord, 32, 32);

		return [
			'username'=>$sUsername,
			'password'=>$sPassword,
			'opt'=>$iOpt,
			'flags'=>$iFlags,
			'urd'=>static::_resolveNameString($sFileData, $iUrdOffset),
			'lib'=>static::_resolveNameString($sFileData, $iLibOffset),
			'accountNo'=>static::_unpack16Le($sAccountNoRaw),
			'accountNoRaw'=>$sAccountNoRaw,
			'reservedRaw'=>$sReservedRaw,
			'accountBitmapRaw'=>$sAccountBitmapRaw,
		];
	}

	/**
	 * Encodes a single user record back to its 64 byte on disk representation
	 *
	 * @param MdfsPasswordUser $aUserInfo
	 * @param int $iUrdPointer Absolute file offset of the URD name string, or 0
	 * @param int $iLibPointer Absolute file offset of the LIB name string, or 0
	*/
	static protected function _encodeRecord(array $aUserInfo, int $iUrdPointer, int $iLibPointer): string
	{
		$sUsernameField = static::_encodeCrField($aUserInfo['username'], self::USERNAME_LENGTH);
		$sPasswordField = static::_encodeCrField($aUserInfo['password'], self::PASSWORD_LENGTH);
		$sOptByte = chr($aUserInfo['opt'] & 0xFF);
		$sFlagByte = chr($aUserInfo['flags'] & 0xFF);
		$sUrdField = static::_pack24Le($iUrdPointer);
		$sLibField = static::_pack24Le($iLibPointer);

		return $sUsernameField.$sPasswordField.$sOptByte.$sFlagByte.$sUrdField.$sLibField
			.str_pad($aUserInfo['accountNoRaw'], 2, "\0")
			.str_pad($aUserInfo['reservedRaw'], 3, "\0")
			.str_pad($aUserInfo['accountBitmapRaw'], 32, "\0");
	}

	/**
	 * Builds a name string region entry (<CR> terminated, max 80 characters, no terminator if
	 * the content fills the field), appending it and returning where it was placed
	*/
	static protected function _appendNameString(string &$sRegion, int $iRegionBaseOffset, ?string $sValue): int
	{
		if($sValue===NULL){
			return 0;
		}
		$iPointer = $iRegionBaseOffset + strlen($sRegion);
		$sRegion .= static::_encodeCrField($sValue, 80);
		return $iPointer;
	}

	/**
	 * Builds the full on disk representation of the password file (header, records,
	 * terminator and name string region) without touching the filesystem
	*/
	static protected function _buildFileContents(): string
	{
		$aSortedUsers = AuthPluginMdfsPassword::$aUsers;
		ksort($aSortedUsers, SORT_STRING);
		$aUsernames = array_keys($aSortedUsers);
		$iTotal = count($aUsernames);

		//Build the alphabetic index buckets, entryNumber[bucket] = index of the first
		//sorted username whose first character falls in that bucket (or later)
		$aBucketStart = [];
		$iPtr = 0;
		for($iBucket=0; $iBucket<self::BUCKET_COUNT; $iBucket++){
			while($iPtr<$iTotal && static::_bucketForChar($aUsernames[$iPtr][0])<$iBucket){
				$iPtr++;
			}
			$aBucketStart[] = $iPtr;
		}

		$iDefaultEntry = 0;
		if(AuthPluginMdfsPassword::$sDefaultUsername!==NULL){
			$iFound = array_search(AuthPluginMdfsPassword::$sDefaultUsername, $aUsernames, TRUE);
			if($iFound!==FALSE){
				$iDefaultEntry = $iFound;
			}
		}

		$aHeaderValues = array_merge($aBucketStart, [$iDefaultEntry]);
		$sHeader = str_pad(pack('v*', ...$aHeaderValues), self::HEADER_LENGTH, "\0");

		$iNameRegionBaseOffset = self::HEADER_LENGTH + ($iTotal+1)*self::RECORD_LENGTH;
		$sNameRegion = "";
		$sRecords = "";
		foreach($aUsernames as $sUsername){
			$aUserInfo = $aSortedUsers[$sUsername];
			$iUrdPointer = static::_appendNameString($sNameRegion, $iNameRegionBaseOffset, $aUserInfo['urd']);
			$iLibPointer = static::_appendNameString($sNameRegion, $iNameRegionBaseOffset, $aUserInfo['lib']);
			$sRecords .= static::_encodeRecord($aUserInfo, $iUrdPointer, $iLibPointer);
		}
		$sRecords .= str_repeat("\xFF", self::RECORD_LENGTH);

		return $sHeader.$sRecords.$sNameRegion;
	}

	static protected function _writeOutUserFile(): void
	{
		if(strlen(config::getValueAsString('security_plugin_mdfspassword_file'))===0){
			return;
		}
		file_put_contents(config::getValueAsString('security_plugin_mdfspassword_file'), static::_buildFileContents());
	}

	/**
	 * Builds the full on disk representation of the accounts file (256 unsigned 16 bit little
	 * endian KB balances) without touching the filesystem. Any account not present in
	 * $aAccountBalances (e.g. never loaded because no accounts file was configured) is written
	 * out as a zero balance.
	*/
	static protected function _buildAccountsFileContents(): string
	{
		$aValues = [];
		for($i=0; $i<self::ACCOUNT_COUNT; $i++){
			$aValues[] = AuthPluginMdfsPassword::$aAccountBalances[$i] ?? 0;
		}
		return pack('v*', ...$aValues);
	}

	static protected function _writeOutAccountsFile(): void
	{
		$sPath = config::getValueAsString('security_plugin_mdfspassword_accounts_file');
		if(strlen($sPath)===0){
			return;
		}
		file_put_contents($sPath, static::_buildAccountsFileContents());
	}

	/**
	 * Intiailizes this plugins data structures
	 *
	 * Loads the binary password file from disk, and optionally the accounts file
	 * @param string $sUsers The raw contents of the password file can be supplied as an arg, this should be mainly used for testing
	 * @param string $sAccounts The raw contents of the accounts file can be supplied as an arg, this should be mainly used for testing. When NULL (the default) it is loaded from 'security_plugin_mdfspassword_accounts_file' if that's configured and the file exists; if not, no account balances are available and getQuota()/setQuota() behave as if this feature is disabled.
	*/
	static public function init(\Psr\Log\LoggerInterface $oLogger, $sUsers=NULL, $sAccounts=NULL): void
	{
		self::$oLogger = $oLogger;

		AuthPluginMdfsPassword::$aUsers = [];
		AuthPluginMdfsPassword::$sDefaultUsername = NULL;
		AuthPluginMdfsPassword::$aAccountBalances = [];
		if(is_null($sUsers)){
			$sPath = config::getValueAsString('security_plugin_mdfspassword_file');
			if(strlen($sPath)===0 || !file_exists($sPath)){
				self::$oLogger->error("AuthPluginMdfsPassword: The password file (".$sPath.") does not exist.");
				return;
			}
			$mUsers = file_get_contents($sPath);
			$sUsers = $mUsers===FALSE ? '' : $mUsers;
		}

		$sData = (string) $sUsers;
		if(strlen($sData)<self::HEADER_LENGTH){
			return;
		}

		$aBucketsAndDefault = array_values(unpack('v*', substr($sData, 0, 2*(self::BUCKET_COUNT+1))) ?: []);
		$iDefaultEntry = 0;
		if(array_key_exists(self::BUCKET_COUNT, $aBucketsAndDefault) && is_int($aBucketsAndDefault[self::BUCKET_COUNT])){
			$iDefaultEntry = $aBucketsAndDefault[self::BUCKET_COUNT];
		}

		$aOrderedUsernames = [];
		for($iOffset=self::HEADER_LENGTH; $iOffset+self::RECORD_LENGTH<=strlen($sData); $iOffset+=self::RECORD_LENGTH){
			$aRecord = static::_decodeRecord(substr($sData, $iOffset, self::RECORD_LENGTH), $sData);
			if($aRecord===NULL){
				//The &FF terminating record marks the end of the user list
				break;
			}
			AuthPluginMdfsPassword::$aUsers[$aRecord['username']] = $aRecord;
			$aOrderedUsernames[] = $aRecord['username'];
		}

		if(array_key_exists($iDefaultEntry, $aOrderedUsernames)){
			AuthPluginMdfsPassword::$sDefaultUsername = $aOrderedUsernames[$iDefaultEntry];
		}

		if(is_null($sAccounts)){
			$sAccountsPath = config::getValueAsString('security_plugin_mdfspassword_accounts_file');
			if(strlen($sAccountsPath)>0 && file_exists($sAccountsPath)){
				$mAccounts = file_get_contents($sAccountsPath);
				$sAccounts = $mAccounts===FALSE ? '' : $mAccounts;
			}
		}
		if(is_string($sAccounts) && strlen($sAccounts)>0){
			$sPadded = str_pad(substr($sAccounts, 0, 2*self::ACCOUNT_COUNT), 2*self::ACCOUNT_COUNT, "\0");
			$aBalances = array_values(unpack('v*', $sPadded) ?: []);
			foreach($aBalances as $iAccountNo => $mBalance){
				AuthPluginMdfsPassword::$aAccountBalances[$iAccountNo] = is_int($mBalance) ? $mBalance : 0;
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
		if(!array_key_exists($sUsername, AuthPluginMdfsPassword::$aUsers)){
			return FALSE;
		}
		if((AuthPluginMdfsPassword::$aUsers[$sUsername]['flags'] & self::FLAG_UNLOCKED)===0){
			//Locked accounts may not log in
			return FALSE;
		}
		return $sPassword===AuthPluginMdfsPassword::$aUsers[$sUsername]['password'];
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
		if(array_key_exists($sUsername, AuthPluginMdfsPassword::$aUsers)){
			$aData = AuthPluginMdfsPassword::$aUsers[$sUsername];
			$oUser->setUsername($aData['username']);
			//URD/LIB overrides are preserved on disk but not yet interpreted, the user's URD is their username
			$oUser->setHomedir(static::_buildHomedir($aData['username']));
			$oUser->setBootOpt($aData['opt']);
			$oUser->setPriv(($aData['flags'] & self::FLAG_SYSTEM)!==0 ? 'S' : 'U');
			//Quota is per-account, not per-user; users sharing an account number share a quota.
			//Accounts >=256, or no accounts file configured, leave the quota at its 0 (use global default) default.
			if(array_key_exists($aData['accountNo'], AuthPluginMdfsPassword::$aAccountBalances)){
				$oUser->setQuota(AuthPluginMdfsPassword::$aAccountBalances[$aData['accountNo']] * 1024);
			}
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
		foreach(AuthPluginMdfsPassword::$aUsers as $sUsername => $aUserData){
			$aReturn[] = AuthPluginMdfsPassword::buildUserObject($sUsername);
		}
		return $aReturn;
	}

	/**
	 * Set the password for a given user
	 *
	 * This causes the on disk password file to be updated.
	 *
	 * @param string $sUsername
	 * @param string $sOldPassword Can be empty if the old password is blank
	 * @param string $sPassword
	*/
	static public function setPassword(string $sUsername, ?string $sOldPassword, ?string $sPassword): void
	{
		if(!AuthPluginMdfsPassword::login($sUsername, $sOldPassword)){
			throw new Exception("Old password was incorrect.");
		}
		$sUsername = strtoupper($sUsername);
		if(!array_key_exists($sUsername, AuthPluginMdfsPassword::$aUsers)){
			throw new Exception("User does not exist");
		}
		$sPassword = $sPassword ?? '';
		if(strlen($sPassword)>self::PASSWORD_LENGTH){
			throw new Exception("Password is too long, the MDFS password file format supports a maximum of ".self::PASSWORD_LENGTH." characters.");
		}
		AuthPluginMdfsPassword::$aUsers[$sUsername]['password'] = $sPassword;
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
			throw new Exception("Username is too long, the MDFS password file format supports a maximum of ".self::USERNAME_LENGTH." characters.");
		}
		if(array_key_exists($sUsername, AuthPluginMdfsPassword::$aUsers)){
			throw new Exception("User exists");
		}
		$iFlags = self::FLAG_UNLOCKED | ($oUser->getPriv()==='S' ? self::FLAG_SYSTEM : 0);
		AuthPluginMdfsPassword::$aUsers[$sUsername] = [
			'username'=>$sUsername,
			'password'=>'',
			'opt'=>$oUser->getBootOpt(),
			'flags'=>$iFlags,
			'urd'=>NULL,
			'lib'=>NULL,
			'accountNo'=>0,
			'accountNoRaw'=>"\0\0",
			'reservedRaw'=>"\0\0\0",
			'accountBitmapRaw'=>str_repeat("\0", 32),
		];
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
		if(array_key_exists($sUsername, AuthPluginMdfsPassword::$aUsers)){
			unset(AuthPluginMdfsPassword::$aUsers[$sUsername]);
			static::_writeOutUserFile();
			return TRUE;
		}else{
			throw new Exception("User does not exists");
		}
	}

	/**
	 * Sets the priv flag for a given user
	 *
	 * An explicit priv change always clears any Locked state read from disk
	*/
	static public function setPriv(string $sUsername, string $sPriv): void
	{
		$sUsername = strtoupper($sUsername);
		if(array_key_exists($sUsername, AuthPluginMdfsPassword::$aUsers)){
			$iFlags = AuthPluginMdfsPassword::$aUsers[$sUsername]['flags'];
			$iFlags = $sPriv==='S' ? ($iFlags | self::FLAG_SYSTEM) : ($iFlags & ~self::FLAG_SYSTEM);
			$iFlags |= self::FLAG_UNLOCKED;
			AuthPluginMdfsPassword::$aUsers[$sUsername]['flags'] = $iFlags;
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
		if(array_key_exists($sUsername, AuthPluginMdfsPassword::$aUsers)){
			AuthPluginMdfsPassword::$aUsers[$sUsername]['opt'] = (int) $sOpt;
			static::_writeOutUserFile();
		}
	}

	/**
	 * Sets the credit balance (in bytes, converted to whole KB) of the given user's personal
	 * account. Quota is per-account, not per-user: this changes the quota seen by every user
	 * who shares the same personal account number.
	 *
	 * A no-op for an unknown user, an account number outside the supported 0-255 range, or
	 * when no accounts data has been loaded (no accounts file configured) - there is nothing
	 * to persist in those cases.
	*/
	static public function setQuota(string $sUsername, int $iQuota): void
	{
		$sUsername = strtoupper($sUsername);
		if(!array_key_exists($sUsername, AuthPluginMdfsPassword::$aUsers)){
			return;
		}
		if(count(AuthPluginMdfsPassword::$aAccountBalances)===0){
			//No accounts data has been loaded (no accounts file configured), nothing to persist
			return;
		}
		$iAccountNo = AuthPluginMdfsPassword::$aUsers[$sUsername]['accountNo'];
		if($iAccountNo<0 || $iAccountNo>=self::ACCOUNT_COUNT){
			return;
		}
		$iKb = max(0, min(65535, (int) round($iQuota / 1024)));
		AuthPluginMdfsPassword::$aAccountBalances[$iAccountNo] = $iKb;
		static::_writeOutAccountsFile();
	}

	/**
	 * Sets the password for a user without requiring the old password (sysop use only)
	 *
	*/
	static public function setPasswordAdmin(string $sUsername, string $sPassword): void
	{
		$sUsername = strtoupper($sUsername);
		if(!array_key_exists($sUsername, AuthPluginMdfsPassword::$aUsers)){
			throw new Exception("User does not exist");
		}
		if(strlen($sPassword)>self::PASSWORD_LENGTH){
			throw new Exception("Password is too long, the MDFS password file format supports a maximum of ".self::PASSWORD_LENGTH." characters.");
		}
		AuthPluginMdfsPassword::$aUsers[$sUsername]['password'] = $sPassword;
		static::_writeOutUserFile();
	}

}
