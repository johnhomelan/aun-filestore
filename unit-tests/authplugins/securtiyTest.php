<?php

/*
 * @group unit-tests
*/
use HomeLan\FileStore\Authentication\Security; 
use PHPUnit\Framework\TestCase;

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

	protected function setup()
	{
		$sUser = "test:md5-".md5('testpw').":home.test:5000:0:S\ntest2:sha1-".sha1('testpw').":home.test:5000:0:U\ntest3:plain-week:home.test3:5000:3:s\ntest4::home.test3:5000:3:u";
		authpluginfile::init($sUser);
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
		$this->assertFalse(authpluginfile::login(127,1,'TEST','testpwrong'));

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
		$this->assertFalse(authpluginfile::login(127,5,'TEST','testpw'));
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
		$this->assertNull(Security::getIdleTimer(123,12));

	}

}
