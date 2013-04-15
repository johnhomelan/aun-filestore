<?

/*
 * @group unit-tests
*/

//Need to define this to stop the password file being written to
define('CONFIG_security_plugin_file_user_file','');
define('CONFIG_security_plugin_file_default_crypt','md5');
include_once('include/system.inc.php');

class authpluginfileTest extends PHPUnit_Framework_TestCase {

	public function setup()
	{
		$sUser = "test:md5-".md5('testpw').":home.test:5000:0\ntest2:sha1-".sha1('testpw').":home.test:5000:0";
		authpluginfile::init($sUser);
	}

	public function testLogin()
	{
		//Should work 
		$this->assertTrue(authpluginfile::login('TEST','testpw'));
		$this->assertTrue(authpluginfile::login('TEST2','testpw'));
		$this->assertTrue(authpluginfile::login('test','testpw'));
		$this->assertTrue(authpluginfile::login('test2','testpw'));
		//Should fail	
	//Should fail	
		$this->assertFalse(authpluginfile::login('TEST','testpwrong'));

	}

	public function testChangePassword()
	{
		authpluginfile::setPassword('TEST','testpwchanged');

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
		$this->assertEquals(get_class($oUser),'user');

		$this->assertEquals($oUser->getUsername(),'TEST');
		$this->assertEquals($oUser->getHomedir(),'home.test');
		$this->assertEquals($oUser->getUnixUid(),5000);
		$this->assertEquals($oUser->getBootOpt(),0);
	}

}
?>
