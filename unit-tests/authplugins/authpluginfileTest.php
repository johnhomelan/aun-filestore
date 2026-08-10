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
 * Test double that extends AuthPluginFile and replaces _writeOutUserFile()
 * with a no-op spy so that no file is ever written during tests.
 *
 * This works because AuthPluginFile now calls static::_writeOutUserFile()
 * (late static binding), so when any inherited method is invoked as
 * TestAuthPluginFile::method(), the overridden version is called instead.
 */
class TestAuthPluginFile extends AuthPluginFile
{
	public static int $iWriteCallCount = 0;

	public static function reset(): void
	{
		self::$iWriteCallCount = 0;
	}

	protected static function _writeOutUserFile(): void
	{
		self::$iWriteCallCount++;
		// Intentional no-op — no file I/O in tests
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

}
