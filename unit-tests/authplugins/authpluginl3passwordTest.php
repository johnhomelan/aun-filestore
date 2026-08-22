<?php

/*
 * @group unit-tests
*/

use PHPUnit\Framework\TestCase;
use Monolog\Logger;
use Monolog\Handler\NullHandler;
use HomeLan\FileStore\Authentication\Plugins\AuthPluginL3Password;
use HomeLan\FileStore\Authentication\User as user;

include_once('include/system.inc.php');

/**
 * Test double that extends AuthPluginL3Password and replaces
 * _writeOutUserFile() with a no-op spy so that no file is ever written
 * during tests.
*/
class TestAuthPluginL3Password extends AuthPluginL3Password
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

	/**
	 * Test helper: build a raw 31 byte on disk record for a user, with bit 7
	 * set on every password character, exactly as the real format requires.
	*/
	public static function buildRawRecord(string $sUsername, string $sPassword, int $iQuota, int $iOpt, int $iPrivByte): string
	{
		$sUsernameField = str_pad(substr($sUsername, 0, 20), 20, "\0");

		$sPasswordField = "";
		foreach(str_split(substr($sPassword, 0, 6)) as $sChar){
			$sPasswordField .= chr(ord($sChar) | 0x80);
		}
		$sPasswordField = str_pad($sPasswordField, 6, "\0");

		$sQuotaField = pack('V', $iQuota);
		$sByte30 = chr($iPrivByte | ($iOpt & 0x0F));

		return $sUsernameField.$sPasswordField.$sQuotaField.$sByte30;
	}
}

class authpluginl3passwordTest extends TestCase {

	protected function setup(): void
	{
		TestAuthPluginL3Password::reset();
		$sData = TestAuthPluginL3Password::buildRawRecord('TEST', 'testpw', 0, 0, 0xC0);
		$sData .= TestAuthPluginL3Password::buildRawRecord('TEST2', 'week', 204800, 3, 0x80);
		$sData .= TestAuthPluginL3Password::buildRawRecord('TEST3', '', 0, 0, 0x80);
		$sData .= TestAuthPluginL3Password::buildRawRecord('LOCKED', 'lockpw', 0, 0, 0xA0);

		$oLogger = new Logger("filestored-unittests");
		$oLogger->pushHandler(new NullHandler());
		TestAuthPluginL3Password::init($oLogger, $sData);
	}

	// =========================================================================
	// Binary decoding
	// =========================================================================

	public function testBit7IsStrippedFromStoredPasswordOnLogin(): void
	{
		//The raw record has every password byte with bit 7 set; login must still succeed with the plain ASCII password
		$this->assertTrue(TestAuthPluginL3Password::login('TEST', 'testpw'));
	}

	public function testLoginFailsWithWrongPassword(): void
	{
		$this->assertFalse(TestAuthPluginL3Password::login('TEST', 'wrongpw'));
	}

	public function testLoginFailsWithNullPassword(): void
	{
		$this->assertFalse(TestAuthPluginL3Password::login('TEST', NULL));
	}

	public function testLoginIsCaseInsensitiveForUsername(): void
	{
		$this->assertTrue(TestAuthPluginL3Password::login('test', 'testpw'));
	}

	public function testEmptyStoredPasswordAllowsBlankLogin(): void
	{
		$this->assertTrue(TestAuthPluginL3Password::login('TEST3', ''));
	}

	public function testLockedUserCannotLogin(): void
	{
		$this->assertFalse(TestAuthPluginL3Password::login('LOCKED', 'lockpw'));
	}

	public function testUnknownUserFailsLogin(): void
	{
		$this->assertFalse(TestAuthPluginL3Password::login('NOSUCHUSER', 'x'));
	}

	// =========================================================================
	// buildUserObject() / decoded fields
	// =========================================================================

	public function testBuildUserObjectDecodesFields(): void
	{
		$oUser = TestAuthPluginL3Password::buildUserObject('TEST2');

		$this->assertInstanceOf(user::class, $oUser);
		$this->assertEquals('TEST2', $oUser->getUsername());
		$this->assertEquals('TEST2', $oUser->getHomedir());
		$this->assertEquals(3, $oUser->getBootOpt());
		$this->assertEquals('U', $oUser->getPriv());
		$this->assertEquals(204800, $oUser->getQuota());
	}

	public function testSystemPrivilegeByteDecodesToS(): void
	{
		$oUser = TestAuthPluginL3Password::buildUserObject('TEST');
		$this->assertEquals('S', $oUser->getPriv());
	}

	public function testLegacySystemPrivilegeByteZeroDecodesToS(): void
	{
		TestAuthPluginL3Password::reset();
		$sData = TestAuthPluginL3Password::buildRawRecord('LEGACYSYS', 'pw', 0, 0, 0x00);
		$oLogger = new Logger('test');
		$oLogger->pushHandler(new NullHandler());
		TestAuthPluginL3Password::init($oLogger, $sData);

		$oUser = TestAuthPluginL3Password::buildUserObject('LEGACYSYS');
		$this->assertEquals('S', $oUser->getPriv());
	}

	public function testLockedUserDecodesToPrivU(): void
	{
		$oUser = TestAuthPluginL3Password::buildUserObject('LOCKED');
		$this->assertEquals('U', $oUser->getPriv());
	}

	public function testDeletedSlotWithNullUsernameIsSkipped(): void
	{
		TestAuthPluginL3Password::reset();
		//A record of all zero bytes represents an unused/deleted slot and a padded sector
		$sData = str_repeat("\0", 256);
		$oLogger = new Logger('test');
		$oLogger->pushHandler(new NullHandler());
		TestAuthPluginL3Password::init($oLogger, $sData);

		$this->assertSame([], TestAuthPluginL3Password::getAllUsers());
	}

	// =========================================================================
	// getAllUsers()
	// =========================================================================

	public function testGetAllUsersReturnsAllLoadedUsers(): void
	{
		$this->assertCount(4, TestAuthPluginL3Password::getAllUsers());
	}

	public function testGetAllUsersReturnsUserObjects(): void
	{
		foreach(TestAuthPluginL3Password::getAllUsers() as $oUser){
			$this->assertInstanceOf(user::class, $oUser);
		}
	}

	// =========================================================================
	// setPassword()
	// =========================================================================

	public function testChangePassword(): void
	{
		TestAuthPluginL3Password::setPassword('TEST', 'testpw', 'chnged');
		$this->assertTrue(TestAuthPluginL3Password::login('TEST', 'chnged'));
		$this->assertFalse(TestAuthPluginL3Password::login('TEST', 'testpw'));
	}

	public function testChangePasswordWithWrongOldPasswordThrows(): void
	{
		$this->expectException(Exception::class);
		TestAuthPluginL3Password::setPassword('TEST', 'wrongold', 'chnged');
	}

	public function testChangePasswordTriggersWrite(): void
	{
		TestAuthPluginL3Password::setPassword('TEST', 'testpw', 'chnged');
		$this->assertSame(1, TestAuthPluginL3Password::$iWriteCallCount);
	}

	public function testChangePasswordTooLongThrows(): void
	{
		$this->expectException(Exception::class);
		TestAuthPluginL3Password::setPassword('TEST', 'testpw', 'toolongpassword');
	}

	public function testChangedPasswordRoundTripsThroughEncodeDecode(): void
	{
		//Ensure the bit-7-set-on-write / mask-on-read cycle round trips correctly
		TestAuthPluginL3Password::setPassword('TEST', 'testpw', 'abc123');

		$oLogger = new Logger('test');
		$oLogger->pushHandler(new NullHandler());
		$sEncoded = TestAuthPluginL3Password::buildRawRecord('TEST', 'abc123', 0, 0, 0xC0);

		//Every non-null byte in the password field must have bit 7 set on disk
		$sPasswordField = substr($sEncoded, 20, 6);
		for($i=0; $i<6; $i++){
			$iByte = ord($sPasswordField[$i]);
			if($iByte!==0){
				$this->assertTrue(($iByte & 0x80)===0x80);
			}
		}

		TestAuthPluginL3Password::init($oLogger, $sEncoded);
		$this->assertTrue(TestAuthPluginL3Password::login('TEST', 'abc123'));
	}

	// =========================================================================
	// setPasswordAdmin()
	// =========================================================================

	public function testSetPasswordAdminChangesPassword(): void
	{
		TestAuthPluginL3Password::setPasswordAdmin('TEST', 'newpas');
		$this->assertTrue(TestAuthPluginL3Password::login('TEST', 'newpas'));
	}

	public function testSetPasswordAdminDoesNotRequireOldPassword(): void
	{
		TestAuthPluginL3Password::setPasswordAdmin('TEST', 'anewpw');
		$this->assertTrue(TestAuthPluginL3Password::login('TEST', 'anewpw'));
	}

	public function testSetPasswordAdminThrowsForUnknownUser(): void
	{
		$this->expectException(Exception::class);
		TestAuthPluginL3Password::setPasswordAdmin('NOBODY', 'pw');
	}

	public function testSetPasswordAdminTooLongThrows(): void
	{
		$this->expectException(Exception::class);
		TestAuthPluginL3Password::setPasswordAdmin('TEST', 'toolongpassword');
	}

	// =========================================================================
	// createUser()
	// =========================================================================

	public function testCreateUser(): void
	{
		$oUser = new user();
		$oUser->setUsername('createtest');
		$oUser->setBootOpt(2);
		$oUser->setPriv('U');
		TestAuthPluginL3Password::createUser($oUser);

		$this->assertTrue(TestAuthPluginL3Password::login('createtest', ''));
		TestAuthPluginL3Password::setPassword('createtest', '', 'haspwd');
		$this->assertTrue(TestAuthPluginL3Password::login('createtest', 'haspwd'));
	}

	public function testCreateUserDuplicateThrows(): void
	{
		$oUser = new user();
		$oUser->setUsername('TEST');
		$this->expectException(Exception::class);
		TestAuthPluginL3Password::createUser($oUser);
	}

	public function testCreateUserUsernameTooLongThrows(): void
	{
		$oUser = new user();
		$oUser->setUsername('THISUSERNAMEISDEFINITELYTOOLONG');
		$this->expectException(Exception::class);
		TestAuthPluginL3Password::createUser($oUser);
	}

	public function testCreateUserTriggersWrite(): void
	{
		$oUser = new user();
		$oUser->setUsername('writetest');
		TestAuthPluginL3Password::createUser($oUser);
		$this->assertSame(1, TestAuthPluginL3Password::$iWriteCallCount);
	}

	// =========================================================================
	// removeUser()
	// =========================================================================

	public function testRemoveUserDeletesExistingUser(): void
	{
		$this->assertTrue(TestAuthPluginL3Password::removeUser('TEST'));
		$this->assertFalse(TestAuthPluginL3Password::login('TEST', 'testpw'));
	}

	public function testRemoveUserThrowsForNonExistentUser(): void
	{
		$this->expectException(Exception::class);
		TestAuthPluginL3Password::removeUser('NOSUCHUSER');
	}

	// =========================================================================
	// setPriv() / setOpt() / setQuota()
	// =========================================================================

	public function testSetPrivChangesUserPrivFlag(): void
	{
		TestAuthPluginL3Password::setPriv('TEST', 'U');
		$this->assertSame('U', TestAuthPluginL3Password::buildUserObject('TEST')->getPriv());
	}

	public function testSetPrivClearsLockedState(): void
	{
		TestAuthPluginL3Password::setPriv('LOCKED', 'U');
		$this->assertTrue(TestAuthPluginL3Password::login('LOCKED', 'lockpw'));
	}

	public function testSetOptChangesBootOption(): void
	{
		TestAuthPluginL3Password::setOpt('TEST', '3');
		$this->assertSame(3, TestAuthPluginL3Password::buildUserObject('TEST')->getBootOpt());
	}

	public function testSetQuotaUpdatesUserObject(): void
	{
		TestAuthPluginL3Password::setQuota('TEST', 65536);
		$this->assertSame(65536, TestAuthPluginL3Password::buildUserObject('TEST')->getQuota());
	}

}
