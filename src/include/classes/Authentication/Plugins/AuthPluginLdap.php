<?php
/**
 * File containing the AuthPluginLdap class
 *
 * @package coreauth
*/
namespace HomeLan\FileStore\Authentication\Plugins;

use HomeLan\FileStore\Authentication\User;
use config;
use Exception;

/**
 * This class is a plugin for the auth system, it provides an auth backend
 * that authenticates Econet users against an LDAP directory.
 *
 * Econet accounts use their own private, AUXILIARY objectClass
 * (`econetAccount`) so it can be layered onto existing directory entries
 * (e.g. `inetOrgPerson`) rather than requiring separate econet-only
 * entries. The full schema definition and OpenLDAP install commands are in
 * docs/authentication.md.
 *
 * The standard `uid` attribute is reused for the username. Everything else
 * is a new private attribute:
 *  - `econetPasswordHash` - `<hashtype>-<hash>`, exactly the same format
 *    AuthPluginFile uses (plain/md5/sha1/bcrypt, see _verifyPassword()/
 *    _encodePassword()). This plugin never binds as the user being
 *    authenticated and never touches `userPassword` - it binds once as a
 *    single configured service account (security_plugin_ldap_bind_dn) and
 *    verifies passwords itself, locally, against this attribute. Storing
 *    the hash separately from `userPassword` matters because Econet sends
 *    passwords in the clear over the wire: a hash derived from one must
 *    not sit in the same attribute other services trust for the same
 *    identity.
 *  - `econetHomeDirectory` - must be unique across the directory. This is
 *    enforced by the OpenLDAP `unique` overlay (authoritative - see the
 *    docs) and defensively re-checked in createUser().
 *  - `econetPriv` (S|U), `econetBootOpt` (0-3), `econetQuota` (bytes).
 *
 * Caching: unlike the file backed plugins, this plugin does not load every
 * user at init() - LDAP is a live, shared, remote service, so init() only
 * binds the service account. Every lookup goes through _lookup(), which
 * consults a per-username, TTL based in-memory cache
 * (security_plugin_ldap_cache_ttl) before ever performing an LDAP search;
 * a short negative cache (security_plugin_ldap_negative_cache_ttl) does the
 * same for lookups of usernames that don't exist. getAllUsers() performs a
 * single directory wide search (also TTL gated) and warms the per-user
 * cache for everything it finds, rather than one search per user. Every
 * mutating method writes to LDAP first and then updates the cache directly
 * (write-through), so a change is visible immediately without waiting for
 * the TTL to expire.
 *
 * @package coreauth
 * @author John Brown <john@home-lan.co.uk>
 *
 * @phpstan-type LdapUserRecord array{dn:string,username:string,passwordHash:string,homedir:string,priv:string,bootOpt:int,quota:int}
 * @phpstan-type LdapCacheEntry array{expires:int,data:LdapUserRecord}
*/
class AuthPluginLdap implements AuthPluginInterface {

	protected const OBJECT_CLASS = 'econetAccount';

	protected static \Psr\Log\LoggerInterface $oLogger;

	protected static ?LdapClientContract $oClient = NULL;

	/**
	 * @var array<string, LdapCacheEntry>
	*/
	protected static array $aCache = [];

	/**
	 * Username => unix timestamp the negative cache entry expires at
	 *
	 * @var array<string, int>
	*/
	protected static array $aNegativeCache = [];

	/**
	 * Usernames found by the last successful getAllUsers() bulk search
	 *
	 * @var array<int, string>
	*/
	protected static array $aAllUsernames = [];

	protected static int $iAllUsersCacheExpires = 0;

	/**
	 * Injects a client for testing; also usable to force a specific client in production
	*/
	static public function setLdapClient(LdapClientContract $oClient): void
	{
		self::$oClient = $oClient;
	}

	static protected function _getClient(): LdapClientContract
	{
		if(self::$oClient===NULL){
			self::$oClient = new LdapClient();
		}
		return self::$oClient;
	}

	/**
	 * Resets all static state (used in tests)
	*/
	static public function reset(): void
	{
		self::$oClient = NULL;
		self::$aCache = [];
		self::$aNegativeCache = [];
		self::$aAllUsernames = [];
		self::$iAllUsersCacheExpires = 0;
	}

	/**
	 * Reads the first value of an attribute out of a normalized LdapClientContract::search() entry
	 *
	 * @param array<string, mixed> $aEntry
	*/
	static protected function _firstValue(array $aEntry, string $sAttribute, string $sDefault=''): string
	{
		$mValues = $aEntry[$sAttribute] ?? NULL;
		if(is_array($mValues) && count($mValues)>0 && is_string($mValues[0])){
			return $mValues[0];
		}
		return $sDefault;
	}

	/**
	 * @param array<string, mixed> $aEntry
	 * @return LdapUserRecord
	*/
	static protected function _entryToRecord(array $aEntry): array
	{
		return [
			'dn'=>is_string($aEntry['dn'] ?? NULL) ? $aEntry['dn'] : '',
			'username'=>strtoupper(static::_firstValue($aEntry, 'uid')),
			'passwordHash'=>static::_firstValue($aEntry, 'econetpasswordhash'),
			'homedir'=>static::_firstValue($aEntry, 'econethomedirectory'),
			'priv'=>static::_firstValue($aEntry, 'econetpriv', 'U'),
			'bootOpt'=>(int) static::_firstValue($aEntry, 'econetbootopt', '0'),
			'quota'=>(int) static::_firstValue($aEntry, 'econetquota', '0'),
		];
	}

	/**
	 * Escapes a value for safe interpolation into an LDAP filter
	*/
	static protected function _escapeFilterValue(string $sValue): string
	{
		return ldap_escape($sValue, '', LDAP_ESCAPE_FILTER);
	}

	/**
	 * The single choke point every read goes through: serves a fresh cache entry if one
	 * exists (positive or negative), otherwise performs one LDAP search and caches the result.
	 *
	 * @return ?LdapUserRecord
	*/
	static protected function _lookup(string $sUsername): ?array
	{
		$sUsername = strtoupper($sUsername);
		$iNow = time();

		if(array_key_exists($sUsername, self::$aCache) && self::$aCache[$sUsername]['expires']>$iNow){
			return self::$aCache[$sUsername]['data'];
		}
		if(array_key_exists($sUsername, self::$aNegativeCache) && self::$aNegativeCache[$sUsername]>$iNow){
			return NULL;
		}

		$sFilter = sprintf(config::getValueAsString('security_plugin_ldap_user_filter'), static::_escapeFilterValue($sUsername));
		$aEntries = static::_getClient()->search(config::getValueAsString('security_plugin_ldap_base_dn'), $sFilter);

		if(count($aEntries)===0){
			self::$aNegativeCache[$sUsername] = $iNow + config::getValueAsInt('security_plugin_ldap_negative_cache_ttl');
			return NULL;
		}

		$aRecord = static::_entryToRecord($aEntries[0]);
		self::$aCache[$sUsername] = ['expires'=>$iNow + config::getValueAsInt('security_plugin_ldap_cache_ttl'), 'data'=>$aRecord];
		unset(self::$aNegativeCache[$sUsername]);
		return $aRecord;
	}

	/**
	 * Updates one field of a cached user's record in place, and refreshes its TTL - used
	 * by the mutating methods so a change is visible immediately (write-through)
	*/
	static protected function _updateCachedField(string $sUsername, string $sField, string|int $mValue): void
	{
		$sUsername = strtoupper($sUsername);
		if(!array_key_exists($sUsername, self::$aCache)){
			return;
		}
		$aData = self::$aCache[$sUsername]['data'];
		switch($sField){
			case 'passwordHash':
				$aData['passwordHash'] = (string) $mValue;
				break;
			case 'priv':
				$aData['priv'] = (string) $mValue;
				break;
			case 'bootOpt':
				$aData['bootOpt'] = (int) $mValue;
				break;
			case 'quota':
				$aData['quota'] = (int) $mValue;
				break;
		}
		self::$aCache[$sUsername] = ['expires'=>time() + config::getValueAsInt('security_plugin_ldap_cache_ttl'), 'data'=>$aData];
	}

	/**
	 * Encodes a plaintext password using the same <hashtype>-<hash> scheme as AuthPluginFile
	*/
	static protected function _encodePassword(string $sPassword): string
	{
		return match(config::getValueAsString('security_plugin_ldap_default_crypt')){
			'plain' => 'plain-'.$sPassword,
			'sha1' => 'sha1-'.sha1($sPassword),
			'md5' => 'md5-'.md5($sPassword),
			default => 'bcrypt-'.password_hash($sPassword, PASSWORD_BCRYPT),
		};
	}

	/**
	 * Verifies a plaintext password against a stored <hashtype>-<hash> value, using the
	 * same per-type logic as AuthPluginFile::login()
	*/
	static protected function _verifyPassword(string $sPassword, string $sStored): bool
	{
		if(str_contains($sStored, '-')){
			[$sHashType, $sHash] = explode('-', $sStored, 2);
		}else{
			$sHashType = 'plain';
			$sHash = $sStored;
		}
		return match($sHashType){
			'plain' => $sPassword===$sHash,
			'sha1' => sha1($sPassword)===$sHash,
			'bcrypt' => password_verify($sPassword, $sHash),
			default => md5($sPassword)===$sHash,
		};
	}

	/**
	 * Intiailizes this plugins data structures
	 *
	 * Binds to the LDAP server as the configured service account. Does not load any
	 * users - LDAP is a live, shared, remote directory, not a file to snapshot once.
	 *
	 * @param string $sUsers Unused, present only to satisfy AuthPluginInterface
	*/
	static public function init(\Psr\Log\LoggerInterface $oLogger, $sUsers=NULL): void
	{
		self::$oLogger = $oLogger;
		self::$aCache = [];
		self::$aNegativeCache = [];
		self::$aAllUsernames = [];
		self::$iAllUsersCacheExpires = 0;

		$bBound = static::_getClient()->bind(
			config::getValueAsString('security_plugin_ldap_uri'),
			config::getValueAsString('security_plugin_ldap_bind_dn'),
			config::getValueAsString('security_plugin_ldap_bind_password'),
			config::getValueAsBool('security_plugin_ldap_start_tls'),
			config::getValueAsInt('security_plugin_ldap_network_timeout')
		);
		if(!$bBound){
			throw new Exception("AuthPluginLdap: Unable to bind to the LDAP server as the service account.");
		}
	}

	/**
	 * Checks the username and password credentials against the LDAP directory
	 *
	 * Never binds as the user - the password is verified locally against the cached/looked
	 * up econetPasswordHash attribute, using the service account bind for everything.
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
		$aRecord = static::_lookup($sUsername);
		if($aRecord===NULL){
			return FALSE;
		}
		return static::_verifyPassword($sPassword, $aRecord['passwordHash']);
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
		$aRecord = static::_lookup($sUsername);
		if($aRecord!==NULL){
			$oUser->setUsername($aRecord['username']);
			$oUser->setHomedir($aRecord['homedir']);
			$oUser->setBootOpt($aRecord['bootOpt']);
			$oUser->setPriv($aRecord['priv']);
			$oUser->setQuota($aRecord['quota']);
		}
		return $oUser;
	}

	/**
	 * Gets all the user objects know to the plugin
	 *
	 * Performs a single directory wide search (TTL gated by security_plugin_ldap_cache_ttl)
	 * rather than one search per user, and warms the per-user cache as a side effect.
	 *
	 * @return array<int, \HomeLan\FileStore\Authentication\User>
	*/
	static public function getAllUsers(): array
	{
		$iNow = time();
		if(self::$iAllUsersCacheExpires<=$iNow){
			$aEntries = static::_getClient()->search(config::getValueAsString('security_plugin_ldap_base_dn'), '(objectClass='.self::OBJECT_CLASS.')');
			$aUsernames = [];
			foreach($aEntries as $aEntry){
				$aRecord = static::_entryToRecord($aEntry);
				self::$aCache[$aRecord['username']] = ['expires'=>$iNow + config::getValueAsInt('security_plugin_ldap_cache_ttl'), 'data'=>$aRecord];
				unset(self::$aNegativeCache[$aRecord['username']]);
				$aUsernames[] = $aRecord['username'];
			}
			self::$aAllUsernames = $aUsernames;
			self::$iAllUsersCacheExpires = $iNow + config::getValueAsInt('security_plugin_ldap_cache_ttl');
		}

		$aReturn = [];
		foreach(self::$aAllUsernames as $sUsername){
			$aReturn[] = static::buildUserObject($sUsername);
		}
		return $aReturn;
	}

	/**
	 * Set the password for a given user
	 *
	 * @param string $sUsername
	 * @param string $sOldPassword Can be empty if the old password is blank
	 * @param string $sPassword
	*/
	static public function setPassword(string $sUsername, ?string $sOldPassword, ?string $sPassword): void
	{
		if(!static::login($sUsername, $sOldPassword)){
			throw new Exception("Old password was incorrect.");
		}
		$aRecord = static::_lookup($sUsername);
		if($aRecord===NULL){
			throw new Exception("User does not exist");
		}
		$sHash = static::_encodePassword($sPassword ?? '');
		static::_getClient()->modifyReplace($aRecord['dn'], ['econetPasswordHash'=>$sHash]);
		static::_updateCachedField($aRecord['username'], 'passwordHash', $sHash);
	}

	/**
	 * Creates a new user in the backend
	 *
	 * If a directory entry with a matching uid already exists, the econetAccount objectClass
	 * and attributes are layered onto it. Otherwise a new minimal entry is created at
	 * security_plugin_ldap_create_dn_template.
	 *
	 * @param \HomeLan\FileStore\Authentication\User $oUser The user object that should be added to the backend
	*/
	static public function createUser(\HomeLan\FileStore\Authentication\User $oUser): void
	{
		$sUsername = strtoupper((string) $oUser->getUsername());
		if($sUsername===''){
			throw new Exception("Username cannot be empty");
		}
		if(static::_lookup($sUsername)!==NULL){
			throw new Exception("User exists");
		}

		$sHomedir = (string) $oUser->getHomedir();
		if($sHomedir!==''){
			$aExisting = static::_getClient()->search(
				config::getValueAsString('security_plugin_ldap_base_dn'),
				'(econetHomeDirectory='.static::_escapeFilterValue($sHomedir).')'
			);
			if(count($aExisting)>0){
				throw new Exception("Home directory ".$sHomedir." is already in use");
			}
		}

		$aAttributes = [
			'econetPasswordHash'=>'',
			'econetHomeDirectory'=>$sHomedir,
			'econetPriv'=>$oUser->getPriv(),
			'econetBootOpt'=>(string) $oUser->getBootOpt(),
			'econetQuota'=>(string) $oUser->getQuota(),
		];

		$aExistingUid = static::_getClient()->search(
			config::getValueAsString('security_plugin_ldap_base_dn'),
			'(uid='.static::_escapeFilterValue($sUsername).')'
		);

		if(count($aExistingUid)>0 && is_string($aExistingUid[0]['dn'] ?? NULL)){
			//Layer the econet attributes onto the existing directory entry
			$sDn = $aExistingUid[0]['dn'];
			static::_getClient()->modifyAdd($sDn, array_merge(['objectClass'=>self::OBJECT_CLASS], $aAttributes));
		}else{
			//No existing entry for this uid - create a new, minimal one
			$sTemplate = config::getValueAsString('security_plugin_ldap_create_dn_template');
			if(strlen($sTemplate)===0){
				$sTemplate = 'uid=%s,'.config::getValueAsString('security_plugin_ldap_base_dn');
			}
			$sDn = sprintf($sTemplate, $sUsername);
			static::_getClient()->add($sDn, array_merge([
				'objectClass'=>['top', 'person', self::OBJECT_CLASS],
				'uid'=>$sUsername,
				'cn'=>$sUsername,
				'sn'=>$sUsername,
			], $aAttributes));
		}

		self::$aCache[$sUsername] = ['expires'=>time() + config::getValueAsInt('security_plugin_ldap_cache_ttl'), 'data'=>[
			'dn'=>$sDn,
			'username'=>$sUsername,
			'passwordHash'=>'',
			'homedir'=>$sHomedir,
			'priv'=>$oUser->getPriv(),
			'bootOpt'=>$oUser->getBootOpt(),
			'quota'=>$oUser->getQuota(),
		]];
		unset(self::$aNegativeCache[$sUsername]);
	}

	/**
	 * Removes a user from the backend
	 *
	 * Because econetAccount is an AUXILIARY class meant to be layered onto pre-existing
	 * directory entries, this only strips the econetAccount objectClass and its attributes
	 * (an LDAP modify), it never deletes the whole entry - the underlying person/
	 * inetOrgPerson entry may be shared with other services.
	 *
	 * @param string $sUsername
	*/
	static public function removeUser(string $sUsername): bool
	{
		$aRecord = static::_lookup($sUsername);
		if($aRecord===NULL){
			throw new Exception("User does not exists");
		}
		static::_getClient()->modifyDelete($aRecord['dn'], [
			'objectClass'=>self::OBJECT_CLASS,
			'econetPasswordHash'=>[],
			'econetHomeDirectory'=>[],
			'econetPriv'=>[],
			'econetBootOpt'=>[],
			'econetQuota'=>[],
		]);

		$sUsername = strtoupper($sUsername);
		unset(self::$aCache[$sUsername]);
		self::$aAllUsernames = array_values(array_diff(self::$aAllUsernames, [$sUsername]));
		return TRUE;
	}

	/**
	 * Sets the priv flag for a given user
	 *
	*/
	static public function setPriv(string $sUsername, string $sPriv): void
	{
		$aRecord = static::_lookup($sUsername);
		if($aRecord===NULL){
			return;
		}
		static::_getClient()->modifyReplace($aRecord['dn'], ['econetPriv'=>$sPriv]);
		static::_updateCachedField($aRecord['username'], 'priv', $sPriv);
	}

	/**
	 * Sets the boot option for a given user
	 *
	*/
	static public function setOpt(string $sUsername, string $sOpt): void
	{
		$aRecord = static::_lookup($sUsername);
		if($aRecord===NULL){
			return;
		}
		static::_getClient()->modifyReplace($aRecord['dn'], ['econetBootOpt'=>$sOpt]);
		static::_updateCachedField($aRecord['username'], 'bootOpt', (int) $sOpt);
	}

	/**
	 * Sets the disc quota for a given user (0 = use global default)
	 *
	*/
	static public function setQuota(string $sUsername, int $iQuota): void
	{
		$aRecord = static::_lookup($sUsername);
		if($aRecord===NULL){
			return;
		}
		static::_getClient()->modifyReplace($aRecord['dn'], ['econetQuota'=>(string) $iQuota]);
		static::_updateCachedField($aRecord['username'], 'quota', $iQuota);
	}

	/**
	 * Sets the password for a user without requiring the old password (sysop use only)
	 *
	*/
	static public function setPasswordAdmin(string $sUsername, string $sPassword): void
	{
		$aRecord = static::_lookup($sUsername);
		if($aRecord===NULL){
			throw new Exception("User does not exist");
		}
		$sHash = static::_encodePassword($sPassword);
		static::_getClient()->modifyReplace($aRecord['dn'], ['econetPasswordHash'=>$sHash]);
		static::_updateCachedField($aRecord['username'], 'passwordHash', $sHash);
	}

}
