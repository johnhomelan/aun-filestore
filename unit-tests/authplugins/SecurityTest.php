<?php

/*
 * @group unit-tests
*/

require_once __DIR__ . '/MockAuthPlugin.php';

use PHPUnit\Framework\TestCase;
use Monolog\Logger;
use Monolog\Handler\NullHandler;
use HomeLan\FileStore\Authentication\Security;
use HomeLan\FileStore\Authentication\Plugins\AuthPluginFile as authpluginfile;
use HomeLan\FileStore\Authentication\Plugins\AuthPluginMock;
use HomeLan\FileStore\Authentication\User as user;

//Need to define this to stop the password file being written to
if(!defined('CONFIG_security_plugin_file_user_file')){
	define('CONFIG_security_plugin_file_user_file','');
}
//Other settings
if(!defined('CONFIG_security_plugin_file_default_crypt')){
	define('CONFIG_security_plugin_file_default_crypt','md5');
}
if(!defined('CONFIG_security_auth_plugins')){
	define('CONFIG_security_auth_plugins','file');
}

include_once('include/system.inc.php');

class SecurityTest extends TestCase {

	protected function setup(): void
	{
		$sUser = "test:md5-".md5('testpw').":home.test:5000:0:S\ntest2:sha1-".sha1('testpw').":home.test:5000:0:U\ntest3:plain-week:home.test3:5000:3:s\ntest4::home.test3:5000:3:u";
		$oLogger = new Logger("filestored-unittests");
		$oLogger->pushHandler(new NullHandler());
		authpluginfile::init($oLogger,$sUser);
		Security::init($oLogger);
	}

	public function testLogin()
	{
		//Should work 
		$this->assertTrue(Security::login(127,1,'TEST','testpw'));
		$this->assertTrue(Security::login(127,1,'TEST2','testpw'));
		$this->assertTrue(Security::login(127,1,'test','testpw'));
		$this->assertTrue(Security::login(127,1,'test2','testpw'));
		$this->assertTrue(Security::login(127,1,'test3','week'));
		$this->assertTrue(Security::login(127,1,'test4',''));
		//Should fail	
		$this->assertFalse(authpluginfile::login('TEST','testpwrong',(int) 127, (int) 1));

	}

	public function testGetUser()
	{
		Security::login(127,1,'TEST','testpw');
		$oUser = Security::getUser(127,1);
		$this->assertTrue(is_object($oUser));
		$this->assertEquals('TEST',$oUser->getUsername());

		//Test getting the user from a station that is not logged in
		$this->assertNull(Security::getUser(1,200));
	}

	public function testisLoggedIn()
	{
		Security::login(127,1,'TEST','testpw');
		Security::login(127,2,'TEST2','testpw');
		//Should pass
		$this->assertTrue(Security::isLoggedIn(127,1));
		$this->assertTrue(Security::isLoggedIn(127,2));

		//Should Fail
		$this->assertFalse(Security::isLoggedIn(128,50));

	}

	public function testLogout()
	{
		Security::login(127,1,'TEST','testpw');
		$this->assertTrue(Security::isLoggedIn(127,1));

		Security::logout(127,1);
		$this->assertFalse(Security::isLoggedIn(127,1));

		//Try loggin out a user who is not logged in, we should get an exception
		$bError = FALSE;
		try {
			Security::logout(127,23);
		}catch(Exception $oException){
			$bError = TRUE;
		}
		$this->assertTrue($bError);
	}

	public function testGetsessions()
	{
		Security::login(127,1,'TEST','testpw');
		Security::login(127,2,'TEST2','testpw');

		//Sessions lister
		$aLoggedInUsers = Security::getUsersOnline();
		$this->assertEquals('TEST',$aLoggedInUsers[127][1]['user']->getUsername());
		$this->assertEquals('TEST2',$aLoggedInUsers[127][2]['user']->getUsername());
	}

	public function testGetUsersStation()
	{
		Security::login(127,1,'TEST','testpw');
		Security::login(127,2,'TEST2','testpw');

		$aStation = Security::getUsersStation('TEST');
		$this->assertEquals($aStation['network'],127);
		$this->assertEquals($aStation['station'],1);

		$aStation = Security::getUsersStation('TEST2');
		$this->assertEquals($aStation['network'],127);
		$this->assertEquals($aStation['station'],2);

		//Test we cope correctly with getting the station for a user who is not logged in
		$aStation = Security::getUsersStation('TEST3');
		$this->assertEquals(0,count($aStation));
	}

	public function testsetConnectedUsersPassword()
	{

		Security::login(127,1,'TEST','testpw');
		Security::setConnectedUsersPassword(127,1,'testpw','testpwchanged');
		$this->assertTrue(Security::login(127,4,'TEST','testpwchanged'));
		$this->assertFalse(authpluginfile::login('TEST','testpw', (int) 127, (int) 5));
	}

	public function testCreateUserShouldWork()
	{
		$oUser = new user();
		$oUser->setUsername('createtest');
		$oUser->setHomedir('home.createtest');
		$oUser->setBootOpt(3);
		$oUser->setUnixUid(5000);
		$oUser->setPriv('U');
		//Log in a user with admin rights
		Security::login(127,1,'TEST','testpw');

		//This should not throw an exception
		Security::createUser(127,1,$oUser);

		$this->assertTrue(Security::login(127,1,'createtest',''));

	}

	public function testCreateUserShouldFail()
	{
		$oUser = new user();
		$oUser->setUsername('createtest');
		$oUser->setHomedir('home.createtest');
		$oUser->setBootOpt(3);
		$oUser->setUnixUid(5000);
		$oUser->setPriv('U');
		//Log in a user without admin rights
		Security::login(127,1,'TEST2','testpw');

		//This should throw an exception
		$bException=FALSE;
		try {
			Security::createUser(127,1,$oUser);
		}catch(Exception $oException){
			$bException=TRUE;
		}
		$this->assertTrue($bException);

		$this->assertFalse(Security::login(127,1,'createtest',''));

		//login a user with admin rights
		Security::login(127,1,'TEST','testpw');

		//Try passing a invalid user (should throw an exeception)
		
		$bException = FALSE;	
		try{
			Security::createUser(127,1,array());
		}catch(Exception $oException){
			$bException = TRUE;
		}
		$this->assertTrue($bException);


		//Try passing a vaild user but a station that is not logged in (should throw an exeception)

		$bException = FALSE;
		try {
			Security::createUser(127,230,$oUser);
		}catch(Exception $oException){
			$bException = TRUE;
		}
		$this->assertTrue($bException);

		//Add a user then, trying adding the same user again (should throw an exeception the second time)
		$bException = FALSE;	
		Security::createUser(127,1,$oUser);
		try{
			Security::createUser(127,1,$oUser);		
		}catch(Exception $oException){
			$bException = TRUE;
		}
		$this->assertTrue($bException);
	}

	public function testIdleTimer()
	{
		Security::login(126,1,'TEST','testpw');
		Security::updateIdleTimer(126,1);
		$this->assertEquals(time(),Security::getIdleTimer(126,1));

		//Try with a station that is not logged in (should produce no error and fail silent)
		Security::updateIdleTimer(123,12);
		$this->assertEquals(0,Security::getIdleTimer(123,12));

	}

	// =========================================================================
	// Helpers for mock-plugin tests
	// =========================================================================

	protected function tearDown(): void
	{
		config::resetValue('security_auth_plugins');
		AuthPluginMock::reset();
	}

	/** Switch Security to use the mock plugin for the remainder of this test. */
	private function useMockPlugin(): void
	{
		config::overrideValue('security_auth_plugins', 'mock');
	}

	/** Log in TEST (admin, priv=S) at the given network/station. */
	private function loginAdmin(int $iNet = 127, int $iStn = 50): void
	{
		// Use the authpluginfile (loaded in setUp) while the config still says 'file'
		config::resetValue('security_auth_plugins');
		Security::login($iNet, $iStn, 'TEST', 'testpw');
	}

	/** Log in TEST2 (non-admin, priv=U) at the given network/station. */
	private function loginNonAdmin(int $iNet = 127, int $iStn = 51): void
	{
		config::resetValue('security_auth_plugins');
		Security::login($iNet, $iStn, 'TEST2', 'testpw');
	}

	// =========================================================================
	// getAllUsers()
	// =========================================================================

	public function testGetAllUsersReturnsMergedListFromPlugin(): void
	{
		$oUser1 = new user(); $oUser1->setUsername('ALICE');
		$oUser2 = new user(); $oUser2->setUsername('BOB');
		AuthPluginMock::$aUsersToReturn = [$oUser1, $oUser2];

		$this->useMockPlugin();
		$aResult = Security::getAllUsers();

		$this->assertCount(2, $aResult);
	}

	public function testGetAllUsersWrapsEachUserWithPluginShortName(): void
	{
		$oUser = new user(); $oUser->setUsername('ALICE');
		AuthPluginMock::$aUsersToReturn = [$oUser];

		$this->useMockPlugin();
		$aResult = Security::getAllUsers();

		$this->assertArrayHasKey('plugin', $aResult[0]);
		$this->assertSame('AuthPluginMock', $aResult[0]['plugin']);
	}

	public function testGetAllUsersReturnsUserObjectInEachEntry(): void
	{
		$oUser = new user(); $oUser->setUsername('ALICE');
		AuthPluginMock::$aUsersToReturn = [$oUser];

		$this->useMockPlugin();
		$aResult = Security::getAllUsers();

		$this->assertSame($oUser, $aResult[0]['user']);
	}

	public function testGetAllUsersReturnsEmptyArrayWhenPluginHasNoUsers(): void
	{
		AuthPluginMock::$aUsersToReturn = [];

		$this->useMockPlugin();
		$this->assertSame([], Security::getAllUsers());
	}

	// =========================================================================
	// removeUser()
	// =========================================================================

	public function testRemoveUserThrowsIfNoUserLoggedIn(): void
	{
		$this->expectException(Exception::class);
		Security::removeUser(127, 99, 'SOMEUSER');
	}

	public function testRemoveUserThrowsIfLoggedInUserIsNotAdmin(): void
	{
		$this->loginNonAdmin(127, 60);
		$this->useMockPlugin();

		$this->expectException(Exception::class);
		Security::removeUser(127, 60, 'SOMEUSER');
	}

	public function testRemoveUserDelegatesToPlugin(): void
	{
		$this->loginAdmin(127, 61);
		$this->useMockPlugin();

		Security::removeUser(127, 61, 'TARGETUSER');

		$aCalls = array_filter(AuthPluginMock::$aCallLog, fn($e) => $e['method'] === 'removeUser');
		$this->assertNotEmpty($aCalls);
		$this->assertSame('TARGETUSER', array_values($aCalls)[0]['username']);
	}

	public function testRemoveUserReturnsTrueWhenPluginSucceeds(): void
	{
		$this->loginAdmin(127, 62);
		AuthPluginMock::$bRemoveUserResult = true;
		$this->useMockPlugin();

		$this->assertTrue(Security::removeUser(127, 62, 'TARGETUSER'));
	}

	public function testRemoveUserReturnsFalseWhenPluginThrows(): void
	{
		$this->loginAdmin(127, 63);
		AuthPluginMock::$bRemoveUserThrow = true;
		$this->useMockPlugin();

		$this->assertFalse(Security::removeUser(127, 63, 'NONEXISTENT'));
	}

	// =========================================================================
	// setPriv()
	// =========================================================================

	public function testSetPrivThrowsIfNoUserLoggedIn(): void
	{
		$this->expectException(Exception::class);
		Security::setPriv(127, 99, 'SOMEUSER', 'S');
	}

	public function testSetPrivThrowsIfLoggedInUserIsNotAdmin(): void
	{
		$this->loginNonAdmin(127, 70);
		$this->useMockPlugin();

		$this->expectException(Exception::class);
		Security::setPriv(127, 70, 'SOMEUSER', 'S');
	}

	public function testSetPrivThrowsForInvalidPrivValue(): void
	{
		$this->loginAdmin(127, 71);
		$this->useMockPlugin();

		$this->expectException(Exception::class);
		Security::setPriv(127, 71, 'SOMEUSER', 'X');
	}

	public function testSetPrivAcceptsPrivS(): void
	{
		$this->loginAdmin(127, 72);
		$this->useMockPlugin();

		Security::setPriv(127, 72, 'TARGETUSER', 'S');

		$aCalls = array_filter(AuthPluginMock::$aCallLog, fn($e) => $e['method'] === 'setPriv');
		$this->assertNotEmpty($aCalls);
	}

	public function testSetPrivAcceptsPrivU(): void
	{
		$this->loginAdmin(127, 73);
		$this->useMockPlugin();

		// Should not throw
		Security::setPriv(127, 73, 'TARGETUSER', 'U');
		$this->assertTrue(true);
	}

	public function testSetPrivDelegatesToPluginWithCorrectArgs(): void
	{
		$this->loginAdmin(127, 74);
		$this->useMockPlugin();

		Security::setPriv(127, 74, 'TARGETUSER', 'S');

		$aCalls = array_filter(AuthPluginMock::$aCallLog, fn($e) => $e['method'] === 'setPriv');
		$oCall = array_values($aCalls)[0];
		$this->assertSame('TARGETUSER', $oCall['username']);
		$this->assertSame('S', $oCall['priv']);
	}

	// =========================================================================
	// setOpt()
	// =========================================================================

	public function testSetOptThrowsIfNoUserLoggedIn(): void
	{
		$this->expectException(Exception::class);
		Security::setOpt(127, 99, '3');
	}

	public function testSetOptDelegatesToPlugin(): void
	{
		$this->loginAdmin(127, 80);
		$this->useMockPlugin();

		Security::setOpt(127, 80, '3');

		$aCalls = array_filter(AuthPluginMock::$aCallLog, fn($e) => $e['method'] === 'setOpt');
		$this->assertNotEmpty($aCalls);
	}

	public function testSetOptUsesLoggedInUsernameNotASuppliedOne(): void
	{
		// setOpt takes (network, station, opt) — the username comes from the
		// session, not a caller-supplied parameter.
		$this->loginAdmin(127, 81);
		$this->useMockPlugin();

		Security::setOpt(127, 81, '2');

		$aCalls = array_filter(AuthPluginMock::$aCallLog, fn($e) => $e['method'] === 'setOpt');
		$oCall = array_values($aCalls)[0];
		$this->assertSame('TEST', $oCall['username']);
		$this->assertSame('2', $oCall['opt']);
	}

	public function testSetOptWorksForNonAdminUser(): void
	{
		// setOpt has no admin requirement — any logged-in user can set their own opt
		$this->loginNonAdmin(127, 82);
		$this->useMockPlugin();

		Security::setOpt(127, 82, '1');

		$aCalls = array_filter(AuthPluginMock::$aCallLog, fn($e) => $e['method'] === 'setOpt');
		$this->assertNotEmpty($aCalls);
	}

}
