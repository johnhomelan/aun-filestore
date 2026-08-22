<?php

/*
 * @group unit-tests
*/

use PHPUnit\Framework\TestCase;
use Monolog\Logger;
use Monolog\Handler\NullHandler;
use HomeLan\FileStore\Authentication\Plugins\AuthPluginLdap;
use HomeLan\FileStore\Authentication\Plugins\LdapClientContract;
use HomeLan\FileStore\Authentication\User as user;

include_once('include/system.inc.php');

/**
 * Minimal in-memory LDAP client stub — covers the subset of LdapClientContract
 * that AuthPluginLdap calls, with call-count/argument spies so tests can assert
 * on caching behaviour (how many searches actually happened) and on the bind
 * DN used (it must always be the service account, never the user logging in).
 * No real LDAP server is involved. Filter matching only understands the
 * simple (attr=value) and (&(...)(...)) shapes AuthPluginLdap actually issues.
*/
class StubLdapClient implements LdapClientContract
{
	/** @var array<string, array<string, mixed>> DN => normalized entry */
	public array $aEntries = [];

	public int $iBindCallCount = 0;
	/** @var array<int, array{uri:string,dn:string,password:string}> */
	public array $aBindCalls = [];
	public bool $bBindShouldFail = false;

	public int $iSearchCallCount = 0;
	/** @var array<int, array{baseDn:string,filter:string}> */
	public array $aSearchLog = [];

	public int $iAddCallCount = 0;
	public int $iModifyReplaceCallCount = 0;
	public int $iModifyAddCallCount = 0;
	public int $iModifyDeleteCallCount = 0;
	public int $iDeleteCallCount = 0;

	public function seedEntry(string $sDn, array $aAttrs): void
	{
		$aNormalized = ['dn'=>$sDn];
		foreach($aAttrs as $sKey=>$mValue){
			$aNormalized[strtolower($sKey)] = is_array($mValue) ? array_values($mValue) : [(string) $mValue];
		}
		$this->aEntries[$sDn] = $aNormalized;
	}

	public function bind(string $sUri, string $sBindDn, string $sBindPassword, bool $bStartTls, int $iTimeoutSeconds): bool
	{
		$this->iBindCallCount++;
		$this->aBindCalls[] = ['uri'=>$sUri, 'dn'=>$sBindDn, 'password'=>$sBindPassword];
		return !$this->bBindShouldFail;
	}

	public function search(string $sBaseDn, string $sFilter): array
	{
		$this->iSearchCallCount++;
		$this->aSearchLog[] = ['baseDn'=>$sBaseDn, 'filter'=>$sFilter];

		$aReturn = [];
		foreach($this->aEntries as $aEntry){
			if($this->_matches($aEntry, $sFilter)){
				$aReturn[] = $aEntry;
			}
		}
		return $aReturn;
	}

	/** @param array<string, mixed> $aEntry */
	private function _matches(array $aEntry, string $sFilter): bool
	{
		$sFilter = trim($sFilter);
		if(str_starts_with($sFilter, '(&')){
			$sInner = substr($sFilter, 2, -1);
			foreach($this->_splitClauses($sInner) as $sClause){
				if(!$this->_matches($aEntry, $sClause)){
					return false;
				}
			}
			return true;
		}
		$sInner = trim($sFilter, '()');
		[$sAttr, $sValue] = array_pad(explode('=', $sInner, 2), 2, '');
		$aValues = $aEntry[strtolower($sAttr)] ?? [];
		return is_array($aValues) && in_array($sValue, $aValues, true);
	}

	/** @return array<int, string> */
	private function _splitClauses(string $sClauses): array
	{
		$aResult = [];
		$iDepth = 0;
		$sCurrent = '';
		for($i=0; $i<strlen($sClauses); $i++){
			$sChar = $sClauses[$i];
			if($sChar==='('){ $iDepth++; }
			if($sChar===')'){ $iDepth--; }
			$sCurrent .= $sChar;
			if($iDepth===0 && trim($sCurrent)!==''){
				$aResult[] = $sCurrent;
				$sCurrent = '';
			}
		}
		return $aResult;
	}

	public function add(string $sDn, array $aAttributes): bool
	{
		$this->iAddCallCount++;
		$aNormalized = ['dn'=>$sDn];
		foreach($aAttributes as $sKey=>$mValue){
			$aNormalized[strtolower($sKey)] = is_array($mValue) ? array_values($mValue) : [(string) $mValue];
		}
		$this->aEntries[$sDn] = $aNormalized;
		return true;
	}

	public function modifyReplace(string $sDn, array $aAttributes): bool
	{
		$this->iModifyReplaceCallCount++;
		if(!array_key_exists($sDn, $this->aEntries)){
			return false;
		}
		foreach($aAttributes as $sKey=>$mValue){
			$this->aEntries[$sDn][strtolower($sKey)] = is_array($mValue) ? array_values($mValue) : [(string) $mValue];
		}
		return true;
	}

	public function modifyAdd(string $sDn, array $aAttributes): bool
	{
		$this->iModifyAddCallCount++;
		if(!array_key_exists($sDn, $this->aEntries)){
			return false;
		}
		foreach($aAttributes as $sKey=>$mValue){
			$sKey = strtolower($sKey);
			$aValues = is_array($mValue) ? array_values($mValue) : [(string) $mValue];
			if($sKey==='objectclass'){
				$aExisting = $this->aEntries[$sDn]['objectclass'] ?? [];
				$this->aEntries[$sDn]['objectclass'] = array_values(array_unique(array_merge($aExisting, $aValues)));
			}else{
				$this->aEntries[$sDn][$sKey] = $aValues;
			}
		}
		return true;
	}

	public function modifyDelete(string $sDn, array $aAttributes): bool
	{
		$this->iModifyDeleteCallCount++;
		if(!array_key_exists($sDn, $this->aEntries)){
			return false;
		}
		foreach($aAttributes as $sKey=>$mValue){
			$sKey = strtolower($sKey);
			if($sKey==='objectclass' && (is_string($mValue) || (is_array($mValue) && count($mValue)>0))){
				$aRemove = is_array($mValue) ? array_values($mValue) : [(string) $mValue];
				$aExisting = $this->aEntries[$sDn]['objectclass'] ?? [];
				$this->aEntries[$sDn]['objectclass'] = array_values(array_diff($aExisting, $aRemove));
			}else{
				unset($this->aEntries[$sDn][$sKey]);
			}
		}
		return true;
	}

	public function delete(string $sDn): bool
	{
		$this->iDeleteCallCount++;
		unset($this->aEntries[$sDn]);
		return true;
	}
}

class authpluginldapTest extends TestCase {

	protected StubLdapClient $oClient;

	protected function setup(): void
	{
		AuthPluginLdap::reset();
		config::overrideValue('security_plugin_ldap_uri', 'ldaps://ldap.example.test:636');
		config::overrideValue('security_plugin_ldap_bind_dn', 'cn=filestore,ou=services,dc=example,dc=test');
		config::overrideValue('security_plugin_ldap_bind_password', 'servicepw');
		config::overrideValue('security_plugin_ldap_base_dn', 'ou=people,dc=example,dc=test');
		config::overrideValue('security_plugin_ldap_user_filter', '(&(objectClass=econetAccount)(uid=%s))');
		config::overrideValue('security_plugin_ldap_create_dn_template', 'uid=%s,ou=people,dc=example,dc=test');
		config::overrideValue('security_plugin_ldap_cache_ttl', 300);
		config::overrideValue('security_plugin_ldap_negative_cache_ttl', 30);
		config::overrideValue('security_plugin_ldap_default_crypt', 'bcrypt');

		$this->oClient = new StubLdapClient();
		$this->oClient->seedEntry('uid=TEST,ou=people,dc=example,dc=test', [
			'uid'=>'TEST',
			'objectClass'=>['top', 'person', 'econetAccount'],
			'econetPasswordHash'=>'md5-'.md5('testpw'),
			'econetHomeDirectory'=>'$.HOME.TEST',
			'econetPriv'=>'S',
			'econetBootOpt'=>'3',
			'econetQuota'=>'204800',
		]);
		$this->oClient->seedEntry('uid=TEST2,ou=people,dc=example,dc=test', [
			'uid'=>'TEST2',
			'objectClass'=>['top', 'person', 'econetAccount'],
			'econetPasswordHash'=>'bcrypt-'.password_hash('testpw2', PASSWORD_BCRYPT),
			'econetHomeDirectory'=>'$.HOME.TEST2',
			'econetPriv'=>'U',
			'econetBootOpt'=>'0',
			'econetQuota'=>'0',
		]);

		AuthPluginLdap::setLdapClient($this->oClient);
		$oLogger = new Logger('filestored-unittests');
		$oLogger->pushHandler(new NullHandler());
		AuthPluginLdap::init($oLogger);
	}

	protected function tearDown(): void
	{
		foreach([
			'security_plugin_ldap_uri','security_plugin_ldap_bind_dn','security_plugin_ldap_bind_password',
			'security_plugin_ldap_base_dn','security_plugin_ldap_user_filter','security_plugin_ldap_create_dn_template',
			'security_plugin_ldap_cache_ttl','security_plugin_ldap_negative_cache_ttl','security_plugin_ldap_default_crypt',
		] as $sKey){
			config::resetValue($sKey);
		}
	}

	// =========================================================================
	// init() / bind
	// =========================================================================

	public function testInitBindsAsTheServiceAccountOnly(): void
	{
		$this->assertSame(1, $this->oClient->iBindCallCount);
		$this->assertSame('cn=filestore,ou=services,dc=example,dc=test', $this->oClient->aBindCalls[0]['dn']);
	}

	public function testInitThrowsWhenBindFails(): void
	{
		$oClient = new StubLdapClient();
		$oClient->bBindShouldFail = true;
		AuthPluginLdap::setLdapClient($oClient);

		$this->expectException(Exception::class);
		AuthPluginLdap::init(new Logger('test'));
	}

	// =========================================================================
	// login() — never binds as the user
	// =========================================================================

	public function testLoginSucceedsWithCorrectPassword(): void
	{
		$this->assertTrue(AuthPluginLdap::login('TEST', 'testpw'));
	}

	public function testLoginSucceedsWithBcryptStoredHash(): void
	{
		$this->assertTrue(AuthPluginLdap::login('TEST2', 'testpw2'));
	}

	public function testLoginFailsWithWrongPassword(): void
	{
		$this->assertFalse(AuthPluginLdap::login('TEST', 'wrongpw'));
	}

	public function testLoginFailsWithNullPassword(): void
	{
		$this->assertFalse(AuthPluginLdap::login('TEST', NULL));
	}

	public function testLoginIsCaseInsensitiveForUsername(): void
	{
		$this->assertTrue(AuthPluginLdap::login('test', 'testpw'));
	}

	public function testUnknownUserFailsLogin(): void
	{
		$this->assertFalse(AuthPluginLdap::login('NOSUCHUSER', 'x'));
	}

	public function testLoginNeverBindsAsAnyDnOtherThanTheServiceAccount(): void
	{
		AuthPluginLdap::login('TEST', 'testpw');
		AuthPluginLdap::login('TEST2', 'testpw2');
		AuthPluginLdap::login('NOSUCHUSER', 'x');

		//Only the one bind() call made during init() ever happened
		$this->assertSame(1, $this->oClient->iBindCallCount);
		$this->assertSame('cn=filestore,ou=services,dc=example,dc=test', $this->oClient->aBindCalls[0]['dn']);
	}

	// =========================================================================
	// buildUserObject()
	// =========================================================================

	public function testBuildUserObjectDecodesFields(): void
	{
		$oUser = AuthPluginLdap::buildUserObject('TEST');

		$this->assertInstanceOf(user::class, $oUser);
		$this->assertEquals('TEST', $oUser->getUsername());
		$this->assertEquals('$.HOME.TEST', $oUser->getHomedir());
		$this->assertEquals(3, $oUser->getBootOpt());
		$this->assertEquals('S', $oUser->getPriv());
		$this->assertEquals(204800, $oUser->getQuota());
	}

	public function testBuildUserObjectForUnknownUserReturnsEmptyUser(): void
	{
		$oUser = AuthPluginLdap::buildUserObject('NOSUCHUSER');
		$this->assertNull($oUser->getUsername());
	}

	// =========================================================================
	// Caching — the core of this plugin
	// =========================================================================

	public function testRepeatedLoginForSameUserOnlySearchesOnce(): void
	{
		AuthPluginLdap::login('TEST', 'testpw');
		$iAfterFirst = $this->oClient->iSearchCallCount;

		AuthPluginLdap::login('TEST', 'wrongpw');
		AuthPluginLdap::login('TEST', 'testpw');
		AuthPluginLdap::buildUserObject('TEST');

		$this->assertSame($iAfterFirst, $this->oClient->iSearchCallCount);
	}

	public function testCacheExpiryTriggersAFreshSearch(): void
	{
		config::overrideValue('security_plugin_ldap_cache_ttl', 0);
		AuthPluginLdap::login('TEST', 'testpw');
		$iAfterFirst = $this->oClient->iSearchCallCount;

		AuthPluginLdap::login('TEST', 'testpw');

		$this->assertGreaterThan($iAfterFirst, $this->oClient->iSearchCallCount);
	}

	public function testUnknownUserIsNegativelyCached(): void
	{
		AuthPluginLdap::login('NOSUCHUSER', 'x');
		$iAfterFirst = $this->oClient->iSearchCallCount;

		AuthPluginLdap::login('NOSUCHUSER', 'x');
		AuthPluginLdap::buildUserObject('NOSUCHUSER');

		$this->assertSame($iAfterFirst, $this->oClient->iSearchCallCount);
	}

	public function testNegativeCacheExpiryTriggersAFreshSearch(): void
	{
		config::overrideValue('security_plugin_ldap_negative_cache_ttl', 0);
		AuthPluginLdap::login('NOSUCHUSER', 'x');
		$iAfterFirst = $this->oClient->iSearchCallCount;

		AuthPluginLdap::login('NOSUCHUSER', 'x');

		$this->assertGreaterThan($iAfterFirst, $this->oClient->iSearchCallCount);
	}

	public function testGetAllUsersDoesOneBulkSearchAndWarmsThePerUserCache(): void
	{
		$aUsers = AuthPluginLdap::getAllUsers();
		$this->assertCount(2, $aUsers);
		$iAfterBulk = $this->oClient->iSearchCallCount;

		//A subsequent single-user lookup should be served from the cache the bulk search warmed
		AuthPluginLdap::buildUserObject('TEST');
		AuthPluginLdap::login('TEST2', 'testpw2');

		$this->assertSame($iAfterBulk, $this->oClient->iSearchCallCount);
	}

	public function testGetAllUsersIsItselfCachedUntilTtlExpires(): void
	{
		AuthPluginLdap::getAllUsers();
		$iAfterFirst = $this->oClient->iSearchCallCount;

		AuthPluginLdap::getAllUsers();

		$this->assertSame($iAfterFirst, $this->oClient->iSearchCallCount);
	}

	// =========================================================================
	// Write-through: mutators write to LDAP then update the cache immediately
	// =========================================================================

	public function testChangePasswordWritesThroughToLdapAndCache(): void
	{
		AuthPluginLdap::setPassword('TEST', 'testpw', 'newpassword');

		$this->assertSame(1, $this->oClient->iModifyReplaceCallCount);
		//The very next login is served from the cache we just updated, no extra search
		$iSearches = $this->oClient->iSearchCallCount;
		$this->assertTrue(AuthPluginLdap::login('TEST', 'newpassword'));
		$this->assertSame($iSearches, $this->oClient->iSearchCallCount);
	}

	public function testChangePasswordWithWrongOldPasswordThrows(): void
	{
		$this->expectException(Exception::class);
		AuthPluginLdap::setPassword('TEST', 'wrongold', 'newpassword');
	}

	public function testSetPasswordAdminChangesPasswordWithoutOldPassword(): void
	{
		AuthPluginLdap::setPasswordAdmin('TEST', 'resetpw');
		$this->assertTrue(AuthPluginLdap::login('TEST', 'resetpw'));
	}

	public function testSetPasswordAdminThrowsForUnknownUser(): void
	{
		$this->expectException(Exception::class);
		AuthPluginLdap::setPasswordAdmin('NOBODY', 'pw');
	}

	public function testSetPrivWritesThroughToLdapAndCache(): void
	{
		AuthPluginLdap::setPriv('TEST2', 'S');
		$this->assertSame(1, $this->oClient->iModifyReplaceCallCount);
		$iSearches = $this->oClient->iSearchCallCount;
		$this->assertSame('S', AuthPluginLdap::buildUserObject('TEST2')->getPriv());
		$this->assertSame($iSearches, $this->oClient->iSearchCallCount);
	}

	public function testSetOptWritesThroughToLdapAndCache(): void
	{
		AuthPluginLdap::setOpt('TEST', '1');
		$this->assertSame(1, AuthPluginLdap::buildUserObject('TEST')->getBootOpt());
	}

	public function testSetQuotaWritesThroughToLdapAndCache(): void
	{
		AuthPluginLdap::setQuota('TEST2', 65536);
		$this->assertSame(65536, AuthPluginLdap::buildUserObject('TEST2')->getQuota());
	}

	public function testRemoveUserStripsEconetAccountRatherThanDeletingTheEntry(): void
	{
		$this->assertTrue(AuthPluginLdap::removeUser('TEST'));

		$this->assertSame(0, $this->oClient->iDeleteCallCount);
		$this->assertSame(1, $this->oClient->iModifyDeleteCallCount);
		//The underlying directory entry (e.g. shared with other services) must survive
		$this->assertArrayHasKey('uid=TEST,ou=people,dc=example,dc=test', $this->oClient->aEntries);
		$this->assertFalse(AuthPluginLdap::login('TEST', 'testpw'));
	}

	public function testRemoveUserThrowsForUnknownUser(): void
	{
		$this->expectException(Exception::class);
		AuthPluginLdap::removeUser('NOSUCHUSER');
	}

	// =========================================================================
	// createUser()
	// =========================================================================

	public function testCreateUserLayersOntoAnExistingUidEntry(): void
	{
		$this->oClient->seedEntry('uid=EXISTING,ou=people,dc=example,dc=test', [
			'uid'=>'EXISTING',
			'objectClass'=>['top', 'person'],
		]);

		$oUser = new user();
		$oUser->setUsername('EXISTING');
		$oUser->setHomedir('$.HOME.EXISTING');
		$oUser->setBootOpt(2);
		$oUser->setPriv('U');
		$oUser->setQuota(1024);
		AuthPluginLdap::createUser($oUser);

		$this->assertSame(1, $this->oClient->iModifyAddCallCount);
		$this->assertSame(0, $this->oClient->iAddCallCount);
		$this->assertContains('econetAccount', $this->oClient->aEntries['uid=EXISTING,ou=people,dc=example,dc=test']['objectclass']);
	}

	public function testCreateUserCreatesAMinimalNewEntryWhenNoUidMatchExists(): void
	{
		$oUser = new user();
		$oUser->setUsername('BRANDNEW');
		$oUser->setHomedir('$.HOME.BRANDNEW');
		$oUser->setBootOpt(0);
		$oUser->setPriv('U');
		AuthPluginLdap::createUser($oUser);

		$this->assertSame(1, $this->oClient->iAddCallCount);
		$this->assertArrayHasKey('uid=BRANDNEW,ou=people,dc=example,dc=test', $this->oClient->aEntries);
		$this->assertTrue(AuthPluginLdap::login('BRANDNEW', ''));
	}

	public function testCreateUserDuplicateThrows(): void
	{
		$oUser = new user();
		$oUser->setUsername('TEST');
		$this->expectException(Exception::class);
		AuthPluginLdap::createUser($oUser);
	}

	public function testCreateUserThrowsWhenHomeDirectoryAlreadyInUse(): void
	{
		$oUser = new user();
		$oUser->setUsername('CLASHING');
		$oUser->setHomedir('$.HOME.TEST'); //Already used by TEST
		$this->expectException(Exception::class);
		AuthPluginLdap::createUser($oUser);
	}

	public function testCreateUserTriggersNoWriteWhenHomeDirectoryClashes(): void
	{
		$oUser = new user();
		$oUser->setUsername('CLASHING');
		$oUser->setHomedir('$.HOME.TEST');
		try {
			AuthPluginLdap::createUser($oUser);
		} catch (Exception) {}

		$this->assertSame(0, $this->oClient->iAddCallCount);
		$this->assertSame(0, $this->oClient->iModifyAddCallCount);
	}

}
