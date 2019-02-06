<?php

/*
 * @group unit-tests
*/

include_once('include/system.inc.php');
use PHPUnit\Framework\TestCase;

class userTest extends TestCase {

	public function testUser()
	{
		$oUser = new user();
		$oUser->setUsername('createtest');
		$oUser->setHomedir('home.createtest');
		$oUser->setBootOpt(3);
		$oUser->setUnixUid(5000);
		$oUser->setPriv('u');

		$this->assertEquals($oUser->getUsername(),'CREATETEST');
		$this->assertEquals($oUser->getHomedir(),'home.createtest');
		$this->assertEquals($oUser->getBootOpt(),3);
		$this->assertEquals($oUser->getUnixUid(),5000);
		$this->assertEquals($oUser->getPriv(),'U');
		$this->assertFalse($oUser->isAdmin());

		$oUser->setPriv('s');
		$this->assertEquals($oUser->getPriv(),'S');
		$this->assertTrue($oUser->isAdmin());
	
	}

}
?>
