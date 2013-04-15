<?

/*
 * @group unit-tests
*/

//Need to define this to stop the password file being written to
define('CONFIG_security_plugin_file_user_file','');
//Other settings
define('CONFIG_security_plugin_file_default_crypt','md5');
define('CONFIG_security_auth_plugins','file');

include_once('include/system.inc.php');

class securityTest extends PHPUnit_Framework_TestCase {

	public function setup()
	{
		$sUser = "test:md5-".md5('testpw').":home.test:5000:0\ntest2:sha1-".sha1('testpw').":home.test:5000:0";
		authpluginfile::init($sUser);
	}

	public function testLogin()
	{
		//Should work 
		$this->assertTrue(security::login(127,1,'TEST','testpw'));
		$this->assertTrue(security::login(127,1,'TEST2','testpw'));
		$this->assertTrue(security::login(127,1,'test','testpw'));
		$this->assertTrue(security::login(127,1,'test2','testpw'));
		//Should fail	
		$this->assertFalse(authpluginfile::login(127,1,'TEST','testpwrong'));

	}

	public function testGetUser()
	{
		security::login(127,1,'TEST','testpw');
		$oUser = security::getUser(127,1);
		$this->assertTrue(is_object($oUser));
		$this->assertEquals($oUser->getUsername(),'TEST');
	}

	public function testisLoggedIn()
	{
		security::login(127,1,'TEST','testpw');
		security::login(127,2,'TEST2','testpw');
		//Should pass
		$this->assertTrue(security::isLoggedIn(127,1));
		$this->assertTrue(security::isLoggedIn(127,2));

		//Should Fail
		$this->assertFalse(security::isLoggedIn(128,50));
	}

	public function testsetConnectedUsersPassword()
	{

		security::login(127,1,'TEST','testpw');
		security::setConnectedUsersPassword(127,1,'testpwchanged');
		$this->assertTrue(security::login(127,4,'TEST','testpwchanged'));
		$this->assertFalse(authpluginfile::login(127,5,'TEST','testpw'));
	}


}
?>
