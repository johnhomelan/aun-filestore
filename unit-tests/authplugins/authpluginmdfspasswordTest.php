<?php

/*
 * @group unit-tests
*/

use PHPUnit\Framework\TestCase;
use Monolog\Logger;
use Monolog\Handler\NullHandler;
use HomeLan\FileStore\Authentication\Plugins\AuthPluginMdfsPassword;
use HomeLan\FileStore\Authentication\User as user;

include_once('include/system.inc.php');

/**
 * Test double that extends AuthPluginMdfsPassword and replaces
 * _writeOutUserFile() with a no-op spy so that no file is ever written
 * during tests. It captures what _buildFileContents() produced so tests
 * can inspect the header/records that would have been written.
*/
class TestAuthPluginMdfsPassword extends AuthPluginMdfsPassword
{
	public static int $iWriteCallCount = 0;
	public static string $sLastBuilt = '';
	public static int $iAccountsWriteCallCount = 0;
	public static string $sLastAccountsBuilt = '';

	public static function reset(): void
	{
		self::$iWriteCallCount = 0;
		self::$sLastBuilt = '';
		self::$iAccountsWriteCallCount = 0;
		self::$sLastAccountsBuilt = '';
	}

	protected static function _writeOutUserFile(): void
	{
		self::$iWriteCallCount++;
		self::$sLastBuilt = static::_buildFileContents();
		// Intentional no-op — no file I/O in tests
	}

	protected static function _writeOutAccountsFile(): void
	{
		self::$iAccountsWriteCallCount++;
		self::$sLastAccountsBuilt = static::_buildAccountsFileContents();
		// Intentional no-op — no file I/O in tests
	}

	/**
	 * Test helper: exposes the decoded internal user records for inspection
	 *
	 * @return array<string, array{username:string,password:string,opt:int,flags:int,urd:?string,lib:?string,accountNo:int,accountNoRaw:string,reservedRaw:string,accountBitmapRaw:string}>
	*/
	public static function getRawUsers(): array
	{
		return self::$aUsers;
	}

	/**
	 * Test helper: exposes the decoded account balances (KB, keyed by account number) for inspection
	 *
	 * @return array<int, int>
	*/
	public static function getRawAccountBalances(): array
	{
		return self::$aAccountBalances;
	}

	/**
	 * Test helper: build a raw 64 byte on disk user record, independent of the
	 * plugin's own encoder.
	*/
	public static function buildRawRecord(string $sUsername, string $sPassword, int $iOpt, int $iFlags, int $iAccountNo = 0): string
	{
		$sUsernameField = substr($sUsername, 0, 9);
		$sUsernameField .= strlen($sUsernameField)<9 ? "\r" : "";
		$sUsernameField = str_pad($sUsernameField, 9, "\0");

		$sPasswordField = substr($sPassword, 0, 10);
		$sPasswordField .= strlen($sPasswordField)<10 ? "\r" : "";
		$sPasswordField = str_pad($sPasswordField, 10, "\0");

		return $sUsernameField.$sPasswordField.chr($iOpt & 0xFF).chr($iFlags & 0xFF)
			."\0\0\0" //URD offset
			."\0\0\0" //LIB offset
			.substr(pack('v', $iAccountNo), 0, 2) //Personal account number
			."\0\0\0" //Reserved
			.str_repeat("\0", 32); //Account ownership bitmap
	}

	/**
	 * Test helper: build a raw 512 byte accounts file (256 x uint16-LE KB balances)
	 *
	 * @param array<int, int> $aBalances Sparse map of account number => balance (KB), unset accounts default to 0
	*/
	public static function buildRawAccountsFile(array $aBalances = []): string
	{
		$aValues = [];
		for($i=0; $i<256; $i++){
			$aValues[] = $aBalances[$i] ?? 0;
		}
		return pack('v*', ...$aValues);
	}

	/**
	 * Test helper: build a raw 64 byte header with all buckets/default user pointing at entry 0
	*/
	public static function buildRawHeader(): string
	{
		return str_repeat("\0", 64);
	}
}

class authpluginmdfspasswordTest extends TestCase {

	protected function setup(): void
	{
		TestAuthPluginMdfsPassword::reset();
		$sData = TestAuthPluginMdfsPassword::buildRawHeader();
		$sData .= TestAuthPluginMdfsPassword::buildRawRecord('TEST', 'testpw', 0, 0x03);
		$sData .= TestAuthPluginMdfsPassword::buildRawRecord('TEST2', 'week', 3, 0x01);
		$sData .= TestAuthPluginMdfsPassword::buildRawRecord('TEST3', '', 0, 0x01);
		$sData .= TestAuthPluginMdfsPassword::buildRawRecord('LOCKED', 'lockpw', 0, 0x00);
		$sData .= str_repeat("\xFF", 64);

		$oLogger = new Logger("filestored-unittests");
		$oLogger->pushHandler(new NullHandler());
		TestAuthPluginMdfsPassword::init($oLogger, $sData);
	}

	protected function tearDown(): void
	{
		config::resetValue('security_plugin_mdfspassword_homedir_prefix');
	}

	// =========================================================================
	// Binary decoding
	// =========================================================================

	public function testLoginSucceedsWithCorrectPassword(): void
	{
		$this->assertTrue(TestAuthPluginMdfsPassword::login('TEST', 'testpw'));
	}

	public function testLoginFailsWithWrongPassword(): void
	{
		$this->assertFalse(TestAuthPluginMdfsPassword::login('TEST', 'wrongpw'));
	}

	public function testLoginFailsWithNullPassword(): void
	{
		$this->assertFalse(TestAuthPluginMdfsPassword::login('TEST', NULL));
	}

	public function testLoginIsCaseInsensitiveForUsername(): void
	{
		$this->assertTrue(TestAuthPluginMdfsPassword::login('test', 'testpw'));
	}

	public function testEmptyStoredPasswordAllowsBlankLogin(): void
	{
		$this->assertTrue(TestAuthPluginMdfsPassword::login('TEST3', ''));
	}

	public function testLockedUserCannotLogin(): void
	{
		//Flag byte 0x00 has bit0 (password unlocked) clear, so the account is locked
		$this->assertFalse(TestAuthPluginMdfsPassword::login('LOCKED', 'lockpw'));
	}

	public function testUnknownUserFailsLogin(): void
	{
		$this->assertFalse(TestAuthPluginMdfsPassword::login('NOSUCHUSER', 'x'));
	}

	public function testTerminatingRecordStopsDecoding(): void
	{
		//Only the 4 real users before the &FF terminator should have been loaded
		$this->assertCount(4, TestAuthPluginMdfsPassword::getAllUsers());
	}

	// =========================================================================
	// buildUserObject() / decoded fields
	// =========================================================================

	public function testBuildUserObjectDecodesFields(): void
	{
		$oUser = TestAuthPluginMdfsPassword::buildUserObject('TEST2');

		$this->assertInstanceOf(user::class, $oUser);
		$this->assertEquals('TEST2', $oUser->getUsername());
		$this->assertEquals('TEST2', $oUser->getHomedir());
		$this->assertEquals(3, $oUser->getBootOpt());
		$this->assertEquals('U', $oUser->getPriv());
	}

	public function testSystemPrivilegeFlagBitDecodesToS(): void
	{
		//Flag byte 0x03 has bit1 (system privileged) set
		$oUser = TestAuthPluginMdfsPassword::buildUserObject('TEST');
		$this->assertEquals('S', $oUser->getPriv());
	}

	public function testLockedUserDecodesToPrivU(): void
	{
		$oUser = TestAuthPluginMdfsPassword::buildUserObject('LOCKED');
		$this->assertEquals('U', $oUser->getPriv());
	}

	public function testHomedirHasNoPrefixByDefault(): void
	{
		$oUser = TestAuthPluginMdfsPassword::buildUserObject('TEST2');
		$this->assertEquals('$.TEST2', $oUser->getHomedirPath());
	}

	public function testHomedirPrefixFromConfigIsApplied(): void
	{
		config::overrideValue('security_plugin_mdfspassword_homedir_prefix', '$.homes');
		$oUser = TestAuthPluginMdfsPassword::buildUserObject('TEST2');
		$this->assertEquals('$.homes.TEST2', $oUser->getHomedirPath());
	}

	public function testHomedirPrefixTrailingDotIsNotDoubled(): void
	{
		config::overrideValue('security_plugin_mdfspassword_homedir_prefix', '$.homes.');
		$oUser = TestAuthPluginMdfsPassword::buildUserObject('TEST2');
		$this->assertEquals('$.homes.TEST2', $oUser->getHomedirPath());
	}

	// =========================================================================
	// getAllUsers()
	// =========================================================================

	public function testGetAllUsersReturnsAllLoadedUsers(): void
	{
		$this->assertCount(4, TestAuthPluginMdfsPassword::getAllUsers());
	}

	public function testGetAllUsersReturnsUserObjects(): void
	{
		foreach(TestAuthPluginMdfsPassword::getAllUsers() as $oUser){
			$this->assertInstanceOf(user::class, $oUser);
		}
	}

	// =========================================================================
	// setPassword()
	// =========================================================================

	public function testChangePassword(): void
	{
		TestAuthPluginMdfsPassword::setPassword('TEST', 'testpw', 'chnged');
		$this->assertTrue(TestAuthPluginMdfsPassword::login('TEST', 'chnged'));
		$this->assertFalse(TestAuthPluginMdfsPassword::login('TEST', 'testpw'));
	}

	public function testChangePasswordWithWrongOldPasswordThrows(): void
	{
		$this->expectException(Exception::class);
		TestAuthPluginMdfsPassword::setPassword('TEST', 'wrongold', 'chnged');
	}

	public function testChangePasswordTriggersWrite(): void
	{
		TestAuthPluginMdfsPassword::setPassword('TEST', 'testpw', 'chnged');
		$this->assertSame(1, TestAuthPluginMdfsPassword::$iWriteCallCount);
	}

	public function testChangePasswordTooLongThrows(): void
	{
		$this->expectException(Exception::class);
		TestAuthPluginMdfsPassword::setPassword('TEST', 'testpw', 'toolong10ch');
	}

	// =========================================================================
	// setPasswordAdmin()
	// =========================================================================

	public function testSetPasswordAdminChangesPassword(): void
	{
		TestAuthPluginMdfsPassword::setPasswordAdmin('TEST', 'newpass1');
		$this->assertTrue(TestAuthPluginMdfsPassword::login('TEST', 'newpass1'));
	}

	public function testSetPasswordAdminDoesNotRequireOldPassword(): void
	{
		TestAuthPluginMdfsPassword::setPasswordAdmin('TEST', 'anewpw');
		$this->assertTrue(TestAuthPluginMdfsPassword::login('TEST', 'anewpw'));
	}

	public function testSetPasswordAdminThrowsForUnknownUser(): void
	{
		$this->expectException(Exception::class);
		TestAuthPluginMdfsPassword::setPasswordAdmin('NOBODY', 'pw');
	}

	public function testSetPasswordAdminTooLongThrows(): void
	{
		$this->expectException(Exception::class);
		TestAuthPluginMdfsPassword::setPasswordAdmin('TEST', 'toolong10ch');
	}

	// =========================================================================
	// createUser()
	// =========================================================================

	public function testCreateUser(): void
	{
		$oUser = new user();
		$oUser->setUsername('creatst');
		$oUser->setBootOpt(2);
		$oUser->setPriv('U');
		TestAuthPluginMdfsPassword::createUser($oUser);

		$this->assertTrue(TestAuthPluginMdfsPassword::login('creatst', ''));
		TestAuthPluginMdfsPassword::setPassword('creatst', '', 'haspwd');
		$this->assertTrue(TestAuthPluginMdfsPassword::login('creatst', 'haspwd'));
	}

	public function testCreateUserDuplicateThrows(): void
	{
		$oUser = new user();
		$oUser->setUsername('TEST');
		$this->expectException(Exception::class);
		TestAuthPluginMdfsPassword::createUser($oUser);
	}

	public function testCreateUserUsernameTooLongThrows(): void
	{
		$oUser = new user();
		$oUser->setUsername('THISUSERNAMEISTOOLONG');
		$this->expectException(Exception::class);
		TestAuthPluginMdfsPassword::createUser($oUser);
	}

	public function testCreateUserTriggersWrite(): void
	{
		$oUser = new user();
		$oUser->setUsername('writetst');
		TestAuthPluginMdfsPassword::createUser($oUser);
		$this->assertSame(1, TestAuthPluginMdfsPassword::$iWriteCallCount);
	}

	// =========================================================================
	// removeUser()
	// =========================================================================

	public function testRemoveUserDeletesExistingUser(): void
	{
		$this->assertTrue(TestAuthPluginMdfsPassword::removeUser('TEST'));
		$this->assertFalse(TestAuthPluginMdfsPassword::login('TEST', 'testpw'));
	}

	public function testRemoveUserThrowsForNonExistentUser(): void
	{
		$this->expectException(Exception::class);
		TestAuthPluginMdfsPassword::removeUser('NOSUCHUSER');
	}

	// =========================================================================
	// setPriv() / setOpt() / setQuota()
	// =========================================================================

	public function testSetPrivChangesUserPrivFlag(): void
	{
		TestAuthPluginMdfsPassword::setPriv('TEST', 'U');
		$this->assertSame('U', TestAuthPluginMdfsPassword::buildUserObject('TEST')->getPriv());
	}

	public function testSetPrivClearsLockedState(): void
	{
		TestAuthPluginMdfsPassword::setPriv('LOCKED', 'U');
		$this->assertTrue(TestAuthPluginMdfsPassword::login('LOCKED', 'lockpw'));
	}

	public function testSetOptChangesBootOption(): void
	{
		TestAuthPluginMdfsPassword::setOpt('TEST', '3');
		$this->assertSame(3, TestAuthPluginMdfsPassword::buildUserObject('TEST')->getBootOpt());
	}

	public function testSetQuotaIsANoOpWithoutAccountsDataLoaded(): void
	{
		//No accounts file/data was loaded by setup(), so there's nothing to persist a quota into
		TestAuthPluginMdfsPassword::setQuota('TEST', 65536);
		$this->assertSame(0, TestAuthPluginMdfsPassword::buildUserObject('TEST')->getQuota());
		$this->assertSame(0, TestAuthPluginMdfsPassword::$iAccountsWriteCallCount);
	}

	// =========================================================================
	// Accounts file (getQuota()/setQuota() via the account balance table)
	// =========================================================================

	public function testBuildUserObjectReadsQuotaFromAccountBalance(): void
	{
		//All 4 users in the fixture share personal account 0 (buildRawRecord()'s default)
		$oLogger = new Logger('test');
		$oLogger->pushHandler(new NullHandler());
		$sData = TestAuthPluginMdfsPassword::buildRawHeader()
			.TestAuthPluginMdfsPassword::buildRawRecord('TEST', 'testpw', 0, 0x03)
			.str_repeat("\xFF", 64);
		TestAuthPluginMdfsPassword::init($oLogger, $sData, TestAuthPluginMdfsPassword::buildRawAccountsFile([0=>500]));

		//500K balance -> 512000 bytes
		$this->assertSame(512000, TestAuthPluginMdfsPassword::buildUserObject('TEST')->getQuota());
	}

	public function testBuildUserObjectQuotaIsZeroForAccountNotInTable(): void
	{
		$oLogger = new Logger('test');
		$oLogger->pushHandler(new NullHandler());
		$sData = TestAuthPluginMdfsPassword::buildRawHeader()
			.TestAuthPluginMdfsPassword::buildRawRecord('TEST', 'testpw', 0, 0x03, 250)
			.str_repeat("\xFF", 64);
		//Accounts file loaded, but account 250 has a zero balance (never credited)
		TestAuthPluginMdfsPassword::init($oLogger, $sData, TestAuthPluginMdfsPassword::buildRawAccountsFile([0=>500]));

		$this->assertSame(0, TestAuthPluginMdfsPassword::buildUserObject('TEST')->getQuota());
	}

	public function testTwoUsersSharingAnAccountNumberShareAQuota(): void
	{
		//TEST and TEST2 both default to personal account 0 in the fixture built by setup()
		TestAuthPluginMdfsPassword::init(
			new Logger('test'),
			TestAuthPluginMdfsPassword::buildRawHeader()
				.TestAuthPluginMdfsPassword::buildRawRecord('TEST', 'testpw', 0, 0x03, 7)
				.TestAuthPluginMdfsPassword::buildRawRecord('TEST2', 'week', 3, 0x01, 7)
				.str_repeat("\xFF", 64),
			TestAuthPluginMdfsPassword::buildRawAccountsFile([7=>100])
		);

		$this->assertSame(102400, TestAuthPluginMdfsPassword::buildUserObject('TEST')->getQuota());
		$this->assertSame(102400, TestAuthPluginMdfsPassword::buildUserObject('TEST2')->getQuota());

		TestAuthPluginMdfsPassword::setQuota('TEST', 204800);

		$this->assertSame(204800, TestAuthPluginMdfsPassword::buildUserObject('TEST')->getQuota());
		$this->assertSame(204800, TestAuthPluginMdfsPassword::buildUserObject('TEST2')->getQuota());
	}

	public function testSetQuotaUpdatesTheAccountBalanceAndTriggersOneWrite(): void
	{
		TestAuthPluginMdfsPassword::init(
			new Logger('test'),
			TestAuthPluginMdfsPassword::buildRawHeader()
				.TestAuthPluginMdfsPassword::buildRawRecord('TEST', 'testpw', 0, 0x03, 12)
				.str_repeat("\xFF", 64),
			TestAuthPluginMdfsPassword::buildRawAccountsFile()
		);

		TestAuthPluginMdfsPassword::setQuota('TEST', 51200); //50 KB

		$this->assertSame(1, TestAuthPluginMdfsPassword::$iAccountsWriteCallCount);
		$this->assertSame(50, TestAuthPluginMdfsPassword::getRawAccountBalances()[12]);
		$this->assertSame(51200, TestAuthPluginMdfsPassword::buildUserObject('TEST')->getQuota());
	}

	public function testSetQuotaRoundsBytesToNearestKb(): void
	{
		TestAuthPluginMdfsPassword::init(
			new Logger('test'),
			TestAuthPluginMdfsPassword::buildRawHeader()
				.TestAuthPluginMdfsPassword::buildRawRecord('TEST', 'testpw', 0, 0x03, 3)
				.str_repeat("\xFF", 64),
			TestAuthPluginMdfsPassword::buildRawAccountsFile()
		);

		TestAuthPluginMdfsPassword::setQuota('TEST', 1600); //1600/1024 = 1.5625 -> rounds to 2 KB

		$this->assertSame(2, TestAuthPluginMdfsPassword::getRawAccountBalances()[3]);
	}

	public function testSetQuotaClampsToUint16Range(): void
	{
		TestAuthPluginMdfsPassword::init(
			new Logger('test'),
			TestAuthPluginMdfsPassword::buildRawHeader()
				.TestAuthPluginMdfsPassword::buildRawRecord('TEST', 'testpw', 0, 0x03, 3)
				.str_repeat("\xFF", 64),
			TestAuthPluginMdfsPassword::buildRawAccountsFile()
		);

		TestAuthPluginMdfsPassword::setQuota('TEST', 999999999);

		$this->assertSame(65535, TestAuthPluginMdfsPassword::getRawAccountBalances()[3]);
	}

	public function testSetQuotaIsANoOpForUnknownUser(): void
	{
		TestAuthPluginMdfsPassword::init(
			new Logger('test'),
			TestAuthPluginMdfsPassword::buildRawHeader().TestAuthPluginMdfsPassword::buildRawRecord('TEST', 'testpw', 0, 0x03).str_repeat("\xFF", 64),
			TestAuthPluginMdfsPassword::buildRawAccountsFile()
		);

		TestAuthPluginMdfsPassword::setQuota('NOSUCHUSER', 51200);

		$this->assertSame(0, TestAuthPluginMdfsPassword::$iAccountsWriteCallCount);
	}

	public function testSetQuotaIsANoOpForAccountNumberOutOfRange(): void
	{
		//The raw field is 2 bytes so an account number >=256 is possible even though our
		//table only covers 0-255
		TestAuthPluginMdfsPassword::init(
			new Logger('test'),
			TestAuthPluginMdfsPassword::buildRawHeader().TestAuthPluginMdfsPassword::buildRawRecord('TEST', 'testpw', 0, 0x03, 300).str_repeat("\xFF", 64),
			TestAuthPluginMdfsPassword::buildRawAccountsFile()
		);

		TestAuthPluginMdfsPassword::setQuota('TEST', 51200);

		$this->assertSame(0, TestAuthPluginMdfsPassword::$iAccountsWriteCallCount);
	}

	public function testSetQuotaDoesNotDisturbOtherAccountBalances(): void
	{
		TestAuthPluginMdfsPassword::init(
			new Logger('test'),
			TestAuthPluginMdfsPassword::buildRawHeader()
				.TestAuthPluginMdfsPassword::buildRawRecord('TEST', 'testpw', 0, 0x03, 1)
				.str_repeat("\xFF", 64),
			TestAuthPluginMdfsPassword::buildRawAccountsFile([1=>10, 2=>20, 99=>99])
		);

		TestAuthPluginMdfsPassword::setQuota('TEST', 51200); //updates account 1 only

		$aBalances = TestAuthPluginMdfsPassword::getRawAccountBalances();
		$this->assertSame(20, $aBalances[2]);
		$this->assertSame(99, $aBalances[99]);
	}

	public function testAccountsFileRoundTripsThroughInit(): void
	{
		TestAuthPluginMdfsPassword::init(
			new Logger('test'),
			TestAuthPluginMdfsPassword::buildRawHeader()
				.TestAuthPluginMdfsPassword::buildRawRecord('TEST', 'testpw', 0, 0x03, 9)
				.str_repeat("\xFF", 64),
			TestAuthPluginMdfsPassword::buildRawAccountsFile()
		);
		TestAuthPluginMdfsPassword::setQuota('TEST', 30720); //30 KB

		$sBuiltAccounts = TestAuthPluginMdfsPassword::$sLastAccountsBuilt;
		$this->assertSame(512, strlen($sBuiltAccounts));

		$oLogger = new Logger('test');
		$oLogger->pushHandler(new NullHandler());
		TestAuthPluginMdfsPassword::init(
			$oLogger,
			TestAuthPluginMdfsPassword::buildRawHeader()
				.TestAuthPluginMdfsPassword::buildRawRecord('TEST', 'testpw', 0, 0x03, 9)
				.str_repeat("\xFF", 64),
			$sBuiltAccounts
		);

		$this->assertSame(30720, TestAuthPluginMdfsPassword::buildUserObject('TEST')->getQuota());
	}

	// =========================================================================
	// Header regeneration / round trip
	// =========================================================================

	public function testWrittenHeaderBucketsPointAtSortedIndexes(): void
	{
		TestAuthPluginMdfsPassword::setOpt('TEST', '0'); //Trigger a write, capturing the built file

		$sBuilt = TestAuthPluginMdfsPassword::$sLastBuilt;
		$this->assertNotSame('', $sBuilt);

		//Sorted usernames are: LOCKED, TEST, TEST2, TEST3 -> indexes 0,1,2,3
		$aValues = array_values(unpack('v16', substr($sBuilt, 0, 32)) ?: []);

		//Bucket 0 = "< 'A'" -> no such user, the lower-bound insertion point is index 0 (LOCKED)
		$this->assertSame(0, $aValues[0]);
		//K/L bucket (index 6) -> first username in or after that bucket is LOCKED at index 0
		$this->assertSame(0, $aValues[6]);
		//S/T bucket (index 10) -> first username in or after that bucket is TEST at index 1
		$this->assertSame(1, $aValues[10]);
	}

	public function testWrittenFileHasTerminatingRecord(): void
	{
		TestAuthPluginMdfsPassword::setOpt('TEST', '0');
		$sBuilt = TestAuthPluginMdfsPassword::$sLastBuilt;

		//4 users remain (64 header + 4*64 records), the next 64 bytes must be the &FF terminator
		$sTerminator = substr($sBuilt, 64 + 4*64, 64);
		$this->assertSame(str_repeat("\xFF", 64), $sTerminator);
	}

	public function testWrittenFileRoundTripsThroughInit(): void
	{
		TestAuthPluginMdfsPassword::setPassword('TEST', 'testpw', 'abc123');
		$sBuilt = TestAuthPluginMdfsPassword::$sLastBuilt;

		$oLogger = new Logger('test');
		$oLogger->pushHandler(new NullHandler());
		TestAuthPluginMdfsPassword::init($oLogger, $sBuilt);

		$this->assertTrue(TestAuthPluginMdfsPassword::login('TEST', 'abc123'));
		$this->assertCount(4, TestAuthPluginMdfsPassword::getAllUsers());
	}

	// =========================================================================
	// Opaque field preservation (URD/LIB overrides, account bitmap)
	// =========================================================================

	public function testUrdOverrideAndAccountBitmapArePreservedAcrossAnUnrelatedEdit(): void
	{
		//Build a fixture where TEST has a URD override string and a non-zero account bitmap
		$sUrdName = "URDNAME\r";
		$sUrdOffset = 64 + 2*64 + 64; //header + 2 records + terminator, name region starts here
		$sHeader = TestAuthPluginMdfsPassword::buildRawHeader();

		$sRecord = substr(TestAuthPluginMdfsPassword::buildRawRecord('TEST', 'testpw', 0, 0x03), 0, 21);
		$sRecord .= substr(pack('V', $sUrdOffset), 0, 3); //URD offset
		$sRecord .= "\0\0\0"; //LIB offset
		$sRecord .= "\x05\x00"; //Personal account number
		$sRecord .= "\0\0\0"; //Reserved
		$sRecord .= str_pad("\xFF\xFF", 32, "\0"); //Account ownership bitmap, first two bits set

		$sSecondRecord = TestAuthPluginMdfsPassword::buildRawRecord('OTHER', 'otherpw', 0, 0x01);

		$sData = $sHeader.$sRecord.$sSecondRecord.str_repeat("\xFF", 64).$sUrdName;

		$oLogger = new Logger('test');
		$oLogger->pushHandler(new NullHandler());
		TestAuthPluginMdfsPassword::init($oLogger, $sData);

		//Sanity check the fixture decoded as expected before mutating anything
		$aBefore = TestAuthPluginMdfsPassword::getRawUsers();
		$this->assertSame('URDNAME', $aBefore['TEST']['urd']);
		$this->assertSame(str_pad("\xFF\xFF", 32, "\0"), $aBefore['TEST']['accountBitmapRaw']);

		//An unrelated mutation shouldn't disturb the opaque fields
		TestAuthPluginMdfsPassword::setOpt('TEST', '5');
		$sBuilt = TestAuthPluginMdfsPassword::$sLastBuilt;

		TestAuthPluginMdfsPassword::init($oLogger, $sBuilt);
		$aAfter = TestAuthPluginMdfsPassword::getRawUsers();

		$this->assertSame(5, $aAfter['TEST']['opt']);
		$this->assertSame('URDNAME', $aAfter['TEST']['urd']);
		$this->assertSame(str_pad("\xFF\xFF", 32, "\0"), $aAfter['TEST']['accountBitmapRaw']);
	}

}
