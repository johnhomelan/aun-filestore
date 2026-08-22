<?php

/*
 * @group unit-tests
*/

use PHPUnit\Framework\TestCase;
use Monolog\Logger;
use Monolog\Handler\NullHandler;
use HomeLan\FileStore\Authentication\Plugins\AuthPluginFile;
use HomeLan\FileStore\Authentication\User as user;

if(!defined('CONFIG_security_plugin_file_default_crypt')){
	define('CONFIG_security_plugin_file_default_crypt','md5');
}
include_once('include/system.inc.php');

/**
 * Test double that extends AuthPluginFile and replaces its filesystem primitives
 * (_fileExists/_readFile/_writeFile) with in-memory fakes, so init()'s "load from
 * disk" path and _writeOutUserFile()'s "save to disk" path can both be exercised
 * without ever touching the real filesystem. The real _buildUserFileContents()/
 * _writeOutUserFile()/init() logic still runs unmodified; only the three I/O
 * primitives are faked.
 *
 * This works because AuthPluginFile now calls static::_fileExists()/_readFile()/
 * _writeFile() (late static binding), so when any inherited method is invoked as
 * TestAuthPluginFile::method(), the overridden versions are called instead.
 */
class TestAuthPluginFile extends AuthPluginFile
{
	public static int $iWriteCallCount = 0;
	public static int $iFileExistsCallCount = 0;
	public static int $iReadFileCallCount = 0;
	public static string $sLastWrittenPath = '';
	public static string $sLastWrittenContents = '';

	/**
	 * The content init() should see when it "reads" the user file. NULL simulates
	 * the file not existing at all.
	*/
	public static ?string $sMockFileContents = NULL;

	public static function reset(): void
	{
		self::$iWriteCallCount = 0;
		self::$iFileExistsCallCount = 0;
		self::$iReadFileCallCount = 0;
		self::$sLastWrittenPath = '';
		self::$sLastWrittenContents = '';
		self::$sMockFileContents = NULL;
	}

	protected static function _fileExists(string $sPath): bool
	{
		self::$iFileExistsCallCount++;
		return self::$sMockFileContents !== NULL;
	}

	protected static function _readFile(string $sPath): string
	{
		self::$iReadFileCallCount++;
		return self::$sMockFileContents ?? '';
	}

	protected static function _writeFile(string $sPath, string $sContents): void
	{
		self::$iWriteCallCount++;
		self::$sLastWrittenPath = $sPath;
		self::$sLastWrittenContents = $sContents;
		// Intentional no-op — no file I/O in tests
	}

	/**
	 * Test helper: exposes the raw stored password field (e.g. "bcrypt-$2y$...") for a user
	*/
	public static function getStoredPassword(string $sUsername): ?string
	{
		return self::$aUsers[strtoupper($sUsername)]['password'] ?? NULL;
	}
}

class authpluginfileTest extends TestCase {

	protected function setup(): void
	{
		TestAuthPluginFile::reset();
		$sUser = "test:md5-".md5('testpw').":home.test:5000:0:S\ntest2:sha1-".sha1('testpw').":home.test:5000:0:U\ntest3:plain-week:home.test3:5000:3:U\ntest4::home.test3:5000:3:s";
		$oLogger = new Logger("filestored-unittests");
		$oLogger->pushHandler(new NullHandler());
		TestAuthPluginFile::init($oLogger, $sUser);
	}

	protected function tearDown(): void
	{
		config::resetValue('security_plugin_file_default_crypt');
	}

	public function testLogin()
	{
		//Should work
		$this->assertTrue(TestAuthPluginFile::login('TEST','testpw'));
		$this->assertTrue(TestAuthPluginFile::login('TEST2','testpw'));
		$this->assertTrue(TestAuthPluginFile::login('test','testpw'));
		$this->assertTrue(TestAuthPluginFile::login('test2','testpw'));
		$this->assertTrue(TestAuthPluginFile::login('test3','week'));

		//Null password test
		$this->assertTrue(TestAuthPluginFile::login('test4',''));

		//Should fail
		$this->assertFalse(TestAuthPluginFile::login('TEST','testpwrong'));
	}

	// =========================================================================
	// Filesystem mocking (init()'s load path / _writeOutUserFile()'s save path)
	// =========================================================================

	public function testInitReportsMissingFileWithoutTouchingRealFilesystem(): void
	{
		TestAuthPluginFile::reset();
		//Leaving $sMockFileContents as NULL simulates the user file not existing
		$oLogger = new Logger('test');
		$oLogger->pushHandler(new NullHandler());

		TestAuthPluginFile::init($oLogger);

		$this->assertSame(1, TestAuthPluginFile::$iFileExistsCallCount);
		$this->assertSame(0, TestAuthPluginFile::$iReadFileCallCount);
		$this->assertSame([], TestAuthPluginFile::getAllUsers());
	}

	public function testInitLoadsUsersThroughTheFilesystemWrapperWhenFilePresent(): void
	{
		TestAuthPluginFile::reset();
		TestAuthPluginFile::$sMockFileContents = "mockuser:md5-".md5('mockpw').":home.mockuser:5000:0:U";
		$oLogger = new Logger('test');
		$oLogger->pushHandler(new NullHandler());

		TestAuthPluginFile::init($oLogger);

		$this->assertSame(1, TestAuthPluginFile::$iFileExistsCallCount);
		$this->assertSame(1, TestAuthPluginFile::$iReadFileCallCount);
		$this->assertTrue(TestAuthPluginFile::login('mockuser', 'mockpw'));
	}

	public function testWriteOutUserFileWritesBuiltContentsThroughTheFilesystemWrapper(): void
	{
		TestAuthPluginFile::setPassword('TEST', 'testpw', 'newpassword');

		//Proves the real serialization logic ran (not just a call-count spy) and that
		//only the mocked low level write primitive saw it, never the real filesystem
		$this->assertSame(config::getValueAsString('security_plugin_file_user_file'), TestAuthPluginFile::$sLastWrittenPath);
		$this->assertStringContainsString('TEST:', TestAuthPluginFile::$sLastWrittenContents);
		$this->assertStringContainsString(':home.test:5000:0:S', TestAuthPluginFile::$sLastWrittenContents);
	}

	// =========================================================================
	// Salted (bcrypt) hashes
	// =========================================================================

	public function testLoginWorksWithBcryptStoredHash(): void
	{
		$sLine = "bcuser:bcrypt-".password_hash('testpw', PASSWORD_BCRYPT).":home.bcuser:5000:0:U";
		$oLogger = new Logger('test');
		$oLogger->pushHandler(new NullHandler());
		TestAuthPluginFile::init($oLogger, $sLine);

		$this->assertTrue(TestAuthPluginFile::login('bcuser', 'testpw'));
		$this->assertFalse(TestAuthPluginFile::login('bcuser', 'wrongpw'));
	}

	public function testSetPasswordDefaultsToBcrypt(): void
	{
		config::overrideValue('security_plugin_file_default_crypt', 'bcrypt');
		TestAuthPluginFile::setPassword('TEST', 'testpw', 'newpassword');

		$this->assertTrue(TestAuthPluginFile::login('TEST', 'newpassword'));
		$this->assertFalse(TestAuthPluginFile::login('TEST', 'testpw'));
	}

	public function testSetPasswordAdminDefaultsToBcrypt(): void
	{
		config::overrideValue('security_plugin_file_default_crypt', 'bcrypt');
		TestAuthPluginFile::setPasswordAdmin('TEST', 'anewpass');

		$this->assertTrue(TestAuthPluginFile::login('TEST', 'anewpass'));
	}

	public function testSamePasswordProducesDifferentBcryptHashesWhenSetTwice(): void
	{
		//This is the whole point of salting: identical passwords must not produce identical hashes
		config::overrideValue('security_plugin_file_default_crypt', 'bcrypt');

		TestAuthPluginFile::setPasswordAdmin('TEST', 'samepassword');
		TestAuthPluginFile::setPasswordAdmin('TEST2', 'samepassword');

		$sStored1 = TestAuthPluginFile::getStoredPassword('TEST');
		$sStored2 = TestAuthPluginFile::getStoredPassword('TEST2');

		$this->assertStringStartsWith('bcrypt-', (string) $sStored1);
		$this->assertNotSame($sStored1, $sStored2);
		$this->assertTrue(TestAuthPluginFile::login('TEST', 'samepassword'));
		$this->assertTrue(TestAuthPluginFile::login('TEST2', 'samepassword'));
	}

	public function testChangePassword()
	{
		TestAuthPluginFile::setPassword('TEST','testpw','testpwchanged');

		//Should now work
		$this->assertTrue(TestAuthPluginFile::login('TEST','testpwchanged'));
		$this->assertTrue(TestAuthPluginFile::login('test','testpwchanged'));
		//Should fail
		$this->assertFalse(TestAuthPluginFile::login('TEST','testpw'));
	}

	public function testChangePasswordTriggersWrite(): void
	{
		TestAuthPluginFile::setPassword('TEST', 'testpw', 'newpassword');
		$this->assertSame(1, TestAuthPluginFile::$iWriteCallCount);
	}

	public function testBuildUSerObject()
	{
		$oUser = TestAuthPluginFile::buildUserObject('TEST');

		$this->assertTrue(is_object($oUser));
		$this->assertEquals(get_class($oUser),'HomeLan\FileStore\Authentication\User');

		$this->assertEquals($oUser->getUsername(),'TEST');
		$this->assertEquals($oUser->getHomedir(),'home.test');
		$this->assertEquals($oUser->getUnixUid(),5000);
		$this->assertEquals($oUser->getBootOpt(),0);
	}

	public function testCreateUser()
	{
		$oUser = new user();
		$oUser->setUsername('createtest');
		$oUser->setHomedir('home.createtest');
		$oUser->setBootOpt(3);
		$oUser->setUnixUid(5000);
		$oUser->setPriv('U');
		TestAuthPluginFile::createUser($oUser);
		$this->assertTrue(TestAuthPluginFile::login('createtest',''));

		TestAuthPluginFile::setPassword('createtest','','haspassnow');
		$this->assertTrue(TestAuthPluginFile::login('createtest','haspassnow'));
		$this->assertFalse(TestAuthPluginFile::login('createtest',''));
	}

	public function testCreateUserTriggersWrite(): void
	{
		$oUser = new user();
		$oUser->setUsername('writetest');
		$oUser->setHomedir('home.writetest');
		$oUser->setBootOpt(0);
		$oUser->setUnixUid(5001);
		$oUser->setPriv('U');
		TestAuthPluginFile::createUser($oUser);
		$this->assertSame(1, TestAuthPluginFile::$iWriteCallCount);
	}

	public function testCreateUserDuplicateDoesNotTriggerWrite(): void
	{
		$oUser = new user();
		$oUser->setUsername('TEST');
		$oUser->setHomedir('home.test');
		$oUser->setBootOpt(0);
		$oUser->setUnixUid(5000);
		$oUser->setPriv('U');

		try {
			TestAuthPluginFile::createUser($oUser);
		} catch (Exception) {}

		$this->assertSame(0, TestAuthPluginFile::$iWriteCallCount);
	}

	// =========================================================================
	// getAllUsers()
	// =========================================================================

	public function testGetAllUsersReturnsAllLoadedUsers(): void
	{
		// setUp loads: test, test2, test3, test4
		$aUsers = TestAuthPluginFile::getAllUsers();
		$this->assertCount(4, $aUsers);
	}

	public function testGetAllUsersReturnsUserObjects(): void
	{
		$aUsers = TestAuthPluginFile::getAllUsers();
		foreach ($aUsers as $oUser) {
			$this->assertInstanceOf(user::class, $oUser);
		}
	}

	public function testGetAllUsersIncludesExpectedUsernames(): void
	{
		$aUsers = TestAuthPluginFile::getAllUsers();
		$aUsernames = array_map(fn($u) => $u->getUsername(), $aUsers);
		$this->assertContains('TEST', $aUsernames);
		$this->assertContains('TEST2', $aUsernames);
	}

	public function testGetAllUsersReturnsEmptyAfterNoUsersLoaded(): void
	{
		$oLogger = new Logger("filestored-unittests");
		$oLogger->pushHandler(new NullHandler());
		TestAuthPluginFile::init($oLogger, '');

		$this->assertSame([], TestAuthPluginFile::getAllUsers());
	}

	// =========================================================================
	// removeUser()
	// =========================================================================

	public function testRemoveUserDeletesExistingUser(): void
	{
		$this->assertTrue(TestAuthPluginFile::removeUser('TEST'));
		$this->assertFalse(TestAuthPluginFile::login('TEST', 'testpw'));
	}

	public function testRemoveUserIsCaseInsensitive(): void
	{
		$this->assertTrue(TestAuthPluginFile::removeUser('test'));
		$this->assertFalse(TestAuthPluginFile::login('TEST', 'testpw'));
	}

	public function testRemoveUserThrowsForNonExistentUser(): void
	{
		$this->expectException(Exception::class);
		TestAuthPluginFile::removeUser('NOSUCHUSER');
	}

	public function testRemoveUserDoesNotAffectOtherUsers(): void
	{
		TestAuthPluginFile::removeUser('TEST');
		$this->assertTrue(TestAuthPluginFile::login('TEST2', 'testpw'));
	}

	public function testRemoveUserReducesGetAllUsersCount(): void
	{
		$iCountBefore = count(TestAuthPluginFile::getAllUsers());
		TestAuthPluginFile::removeUser('TEST');
		$this->assertCount($iCountBefore - 1, TestAuthPluginFile::getAllUsers());
	}

	public function testRemoveUserTriggersWrite(): void
	{
		TestAuthPluginFile::removeUser('TEST');
		$this->assertSame(1, TestAuthPluginFile::$iWriteCallCount);
	}

	public function testRemoveUserThrowDoesNotTriggerWrite(): void
	{
		try {
			TestAuthPluginFile::removeUser('NOSUCHUSER');
		} catch (Exception) {}

		$this->assertSame(0, TestAuthPluginFile::$iWriteCallCount);
	}

	// =========================================================================
	// setPriv()
	// =========================================================================

	public function testSetPrivChangesUserPrivFlag(): void
	{
		// TEST starts as priv 'S'; change to 'U'
		TestAuthPluginFile::setPriv('TEST', 'U');
		$oUser = TestAuthPluginFile::buildUserObject('TEST');
		$this->assertSame('U', $oUser->getPriv());
	}

	public function testSetPrivFromUToS(): void
	{
		// TEST2 starts as priv 'U'; change to 'S'
		TestAuthPluginFile::setPriv('TEST2', 'S');
		$oUser = TestAuthPluginFile::buildUserObject('TEST2');
		$this->assertSame('S', $oUser->getPriv());
	}

	public function testSetPrivIsCaseInsensitiveForUsername(): void
	{
		TestAuthPluginFile::setPriv('test', 'U');
		$oUser = TestAuthPluginFile::buildUserObject('TEST');
		$this->assertSame('U', $oUser->getPriv());
	}

	public function testSetPrivDoesNothingForNonExistentUser(): void
	{
		TestAuthPluginFile::setPriv('NOSUCHUSER', 'U');
		$this->assertSame(0, TestAuthPluginFile::$iWriteCallCount);
	}

	public function testSetPrivTriggersWrite(): void
	{
		TestAuthPluginFile::setPriv('TEST', 'U');
		$this->assertSame(1, TestAuthPluginFile::$iWriteCallCount);
	}

	// =========================================================================
	// setOpt()
	// =========================================================================

	public function testSetOptChangesBootOption(): void
	{
		// TEST starts with opt=0; change to 3
		TestAuthPluginFile::setOpt('TEST', '3');
		$oUser = TestAuthPluginFile::buildUserObject('TEST');
		$this->assertSame(3, $oUser->getBootOpt());
	}

	public function testSetOptIsCaseInsensitiveForUsername(): void
	{
		TestAuthPluginFile::setOpt('test', '2');
		$oUser = TestAuthPluginFile::buildUserObject('TEST');
		$this->assertSame(2, $oUser->getBootOpt());
	}

	public function testSetOptDoesNothingForNonExistentUser(): void
	{
		TestAuthPluginFile::setOpt('NOSUCHUSER', '3');
		$this->assertSame(0, TestAuthPluginFile::$iWriteCallCount);
	}

	public function testSetOptChangesAreReflectedInGetAllUsers(): void
	{
		TestAuthPluginFile::setOpt('TEST', '3');
		$aUsers = TestAuthPluginFile::getAllUsers();
		$aMatch = array_filter($aUsers, fn($u) => $u->getUsername() === 'TEST');
		$oUser = array_values($aMatch)[0];
		$this->assertSame(3, $oUser->getBootOpt());
	}

	public function testSetOptTriggersWrite(): void
	{
		TestAuthPluginFile::setOpt('TEST', '3');
		$this->assertSame(1, TestAuthPluginFile::$iWriteCallCount);
	}

	// =========================================================================
	// setQuota() / quota persistence via buildUserObject()
	// =========================================================================

	public function testSetQuotaUpdatesUserObject(): void
	{
		TestAuthPluginFile::setQuota('TEST', 204800);
		$oUser = TestAuthPluginFile::buildUserObject('TEST');
		$this->assertSame(204800, $oUser->getQuota());
	}

	public function testSetQuotaIsCaseInsensitive(): void
	{
		TestAuthPluginFile::setQuota('test', 10240);
		$oUser = TestAuthPluginFile::buildUserObject('TEST');
		$this->assertSame(10240, $oUser->getQuota());
	}

	public function testSetQuotaDoesNothingForNonExistentUser(): void
	{
		TestAuthPluginFile::setQuota('NOBODY', 1234);
		$this->assertSame(0, TestAuthPluginFile::$iWriteCallCount);
	}

	public function testSetQuotaTriggersWrite(): void
	{
		TestAuthPluginFile::setQuota('TEST', 512);
		$this->assertSame(1, TestAuthPluginFile::$iWriteCallCount);
	}

	public function testBuildUserObjectReturnsZeroQuotaByDefault(): void
	{
		$oUser = TestAuthPluginFile::buildUserObject('TEST');
		$this->assertSame(0, $oUser->getQuota());
	}

	public function testGetAllUsersReflectsUpdatedQuota(): void
	{
		TestAuthPluginFile::setQuota('TEST', 999);
		$aUsers = TestAuthPluginFile::getAllUsers();
		$aMatch = array_filter($aUsers, fn($u) => $u->getUsername() === 'TEST');
		$oUser = array_values($aMatch)[0];
		$this->assertSame(999, $oUser->getQuota());
	}

	// =========================================================================
	// Quota field in user file format (legacy 6-field lines still parse)
	// =========================================================================

	public function testLegacySixFieldLinesParsedWithZeroQuota(): void
	{
		// 6-field lines (no quota column) must load without error and default quota to 0
		$sLegacy = "LEGACY:md5-".md5('pw').":home.legacy:5001:0:U";
		$oLogger = new \Monolog\Logger('test');
		$oLogger->pushHandler(new \Monolog\Handler\NullHandler());
		TestAuthPluginFile::init($oLogger, $sLegacy);
		$oUser = TestAuthPluginFile::buildUserObject('LEGACY');
		$this->assertSame(0, $oUser->getQuota());
	}

	public function testSevenFieldLinesParseWithQuota(): void
	{
		$sLine = "QUOTAUSER:md5-".md5('pw').":home.qu:5002:0:U:65536";
		$oLogger = new \Monolog\Logger('test');
		$oLogger->pushHandler(new \Monolog\Handler\NullHandler());
		TestAuthPluginFile::init($oLogger, $sLine);
		$oUser = TestAuthPluginFile::buildUserObject('QUOTAUSER');
		$this->assertSame(65536, $oUser->getQuota());
	}

	public function testCreateUserPersistsQuota(): void
	{
		$oUser = new user();
		$oUser->setUsername('NEWQUSER');
		$oUser->setHomedir('$.NEWQUSER');
		$oUser->setUnixUid(5100);
		$oUser->setPriv('U');
		$oUser->setQuota(32768);
		TestAuthPluginFile::createUser($oUser);
		$oLoaded = TestAuthPluginFile::buildUserObject('NEWQUSER');
		$this->assertSame(32768, $oLoaded->getQuota());
	}

	// =========================================================================
	// setPasswordAdmin()
	// =========================================================================

	public function testSetPasswordAdminChangesPassword(): void
	{
		TestAuthPluginFile::setPasswordAdmin('TEST', 'newpass');
		$this->assertTrue(TestAuthPluginFile::login('TEST', 'newpass'));
	}

	public function testSetPasswordAdminDoesNotRequireOldPassword(): void
	{
		// Should succeed even though we don't supply the current password
		TestAuthPluginFile::setPasswordAdmin('TEST', 'anothernew');
		$this->assertTrue(TestAuthPluginFile::login('TEST', 'anothernew'));
	}

	public function testSetPasswordAdminThrowsForUnknownUser(): void
	{
		$this->expectException(\Exception::class);
		TestAuthPluginFile::setPasswordAdmin('NOBODY', 'pw');
	}

	public function testSetPasswordAdminTriggersWrite(): void
	{
		TestAuthPluginFile::setPasswordAdmin('TEST', 'writtenpass');
		$this->assertSame(1, TestAuthPluginFile::$iWriteCallCount);
	}

	public function testSetPasswordAdminIsCaseInsensitive(): void
	{
		TestAuthPluginFile::setPasswordAdmin('test', 'casepass');
		$this->assertTrue(TestAuthPluginFile::login('TEST', 'casepass'));
	}

}
