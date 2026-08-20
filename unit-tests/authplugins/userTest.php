<?php

/*
 * @group unit-tests
*/

include_once('include/system.inc.php');
use PHPUnit\Framework\TestCase;
use HomeLan\FileStore\Authentication\User as user;

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

	// =========================================================================
	// getCsd()
	// =========================================================================

	public function testGetCsdReturnsExplicitlySetCsd(): void
	{
		$oUser = new user();
		$oUser->setCsd('$.work');
		$this->assertSame('$.work', $oUser->getCsd());
	}

	public function testGetCsdFallsBackToHomedirWhenNotSet(): void
	{
		$oUser = new user();
		$oUser->setHomedir('home.test');
		$this->assertSame('$.home.test', $oUser->getCsd());
	}

	public function testGetCsdReturnNullWhenNeitherCsdNorHomedirIsSet(): void
	{
		$oUser = new user();
		$this->assertNull($oUser->getCsd());
	}

	public function testGetCsdDoesNotReturnHomedirAfterCsdIsExplicitlySet(): void
	{
		$oUser = new user();
		$oUser->setHomedir('home.test');
		$oUser->setCsd('$.projects');
		$this->assertSame('$.projects', $oUser->getCsd());
	}

	public function testGetCsdAfterHomedirFallbackRemembersTheResult(): void
	{
		// First call triggers the homedir fallback and caches it in $sCsd.
		// A subsequent setCsd should then override the cached value.
		$oUser = new user();
		$oUser->setHomedir('home.test');
		$oUser->getCsd(); // primes the lazy default
		$oUser->setCsd('$.other');
		$this->assertSame('$.other', $oUser->getCsd());
	}

	// =========================================================================
	// setLib() / getLib()
	// =========================================================================

	public function testGetLibReturnsExplicitlySetLib(): void
	{
		$oUser = new user();
		$oUser->setLib('$.mylib');
		$this->assertSame('$.mylib', $oUser->getLib());
	}

	public function testGetLibFallsBackToConfigLibraryPathWhenNotSet(): void
	{
		$oUser = new user();
		// CONFIG_library_path is defined as '$.LIBRARY' in the test config
		$this->assertSame(config::getValue('library_path'), $oUser->getLib());
	}

	public function testGetLibFallbackUsesConfigValue(): void
	{
		$oUser = new user();
		$this->assertSame('$.LIBRARY', $oUser->getLib());
	}

	public function testSetLibOverridesConfigFallback(): void
	{
		$oUser = new user();
		$oUser->setLib('$.customlib');
		$this->assertSame('$.customlib', $oUser->getLib());
		$this->assertNotSame(config::getValue('library_path'), $oUser->getLib());
	}

	public function testGetLibAfterConfigFallbackCanBeOverriddenBySetLib(): void
	{
		// Trigger the lazy config fallback first, then override it.
		$oUser = new user();
		$oUser->getLib(); // primes the default
		$oUser->setLib('$.newlib');
		$this->assertSame('$.newlib', $oUser->getLib());
	}

	public function testSetLibAndSetCsdAreIndependent(): void
	{
		$oUser = new user();
		$oUser->setCsd('$.mydir');
		$oUser->setLib('$.mylib');
		$this->assertSame('$.mydir', $oUser->getCsd());
		$this->assertSame('$.mylib', $oUser->getLib());
	}

	// =========================================================================
	// getQuota() / setQuota()
	// =========================================================================

	public function testGetQuotaDefaultsToZero(): void
	{
		$oUser = new user();
		$this->assertSame(0, $oUser->getQuota());
	}

	public function testSetQuotaStoresValue(): void
	{
		$oUser = new user();
		$oUser->setQuota(524288);
		$this->assertSame(524288, $oUser->getQuota());
	}

	public function testSetQuotaZeroIsAccepted(): void
	{
		$oUser = new user();
		$oUser->setQuota(1000);
		$oUser->setQuota(0);
		$this->assertSame(0, $oUser->getQuota());
	}

}

