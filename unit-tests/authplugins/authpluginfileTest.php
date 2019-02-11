<?php

/*
 * @group unit-tests
*/
use HomeLan\FileStore\Authentication\Security; 
use PHPUnit\Framework\TestCase;
use Monolog\Logger;
use HomeLan\FileStore\Authentication\Plugins\AuthPluginFile as authpluginfile;
use HomeLan\FileStore\Authentication\User as user;


//Need to define this to stop the password file being written to
if(!defined('CONFIG_security_plugin_file_user_file')){
	define('CONFIG_security_plugin_file_user_file','');
}
if(!defined('CONFIG_security_plugin_file_default_crypt')){
	define('CONFIG_security_plugin_file_default_crypt','md5');
}
include_once('include/system.inc.php');

class authpluginfileTest extends TestCase {

	protected function setup(): void
	{
		$sUser = "test:md5-".md5('testpw').":home.test:5000:0:S\ntest2:sha1-".sha1('testpw').":home.test:5000:0:U\ntest3:plain-week:home.test3:5000:3:U\ntest4::home.test3:5000:3:s";
		$oLogger = new Logger("filestored-unittests");
		authpluginfile::init($oLogger,$sUser);
	}

	public function testLogin()
	{
		//Should work 
		$this->assertTrue(authpluginfile::login('TEST','testpw'));
		$this->assertTrue(authpluginfile::login('TEST2','testpw'));
		$this->assertTrue(authpluginfile::login('test','testpw'));
		$this->assertTrue(authpluginfile::login('test2','testpw'));
		$this->assertTrue(authpluginfile::login('test3','week'));

		//Null password test
		$this->assertTrue(authpluginfile::login('test4',''));

		//Should fail	
		$this->assertFalse(authpluginfile::login('TEST','testpwrong'));

	}

	public function testChangePassword()
	{
		authpluginfile::setPassword('TEST','testpw','testpwchanged');

		//Should now work
		$this->assertTrue(authpluginfile::login('TEST','testpwchanged'));
		$this->assertTrue(authpluginfile::login('test','testpwchanged'));
		//Should fail
		$this->assertFalse(authpluginfile::login('TEST','testpw'));
	}

	public function testBuildUSerObject()
	{
		$oUser = authpluginfile::buildUserObject('TEST');

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
		authpluginfile::createUser($oUser);
		$this->assertTrue(authpluginfile::login('createtest',''));

		authpluginfile::setPassword('createtest','','haspassnow');
		$this->assertTrue(authpluginfile::login('createtest','haspassnow'));
		$this->assertFalse(authpluginfile::login('createtest',''));
	}

}
