<?php

/*
 * @group unit-tests
 *
 * Tests for HomeLan\FileStore\Services\Provider\PrintServer\Admin.
 *
 * FakePrintServer extends PrintServer, keeps the real constructor (it's trivial:
 * just sets the logger), and overrides data methods to return controlled stubs.
 */

include_once(__DIR__ . '/../../src/include/system.inc.php');

use PHPUnit\Framework\TestCase;
use Monolog\Logger;
use Monolog\Handler\NullHandler;
use HomeLan\FileStore\Services\Provider\PrintServer;
use HomeLan\FileStore\Services\Provider\PrintServer\Admin as PrintServerAdmin;
use HomeLan\FileStore\Services\Provider\AdminEntity;
use HomeLan\FileStore\Services\ServiceDispatcher;

// ---------------------------------------------------------------------------
// Fake provider
// ---------------------------------------------------------------------------
class FakePrintServer extends PrintServer
{
    public array $aStubJobs         = [];
    public array $aStubSpooledFiles = [];
    public int   $iStubPort         = 0x9C;

    public function getJobs(): array         { return $this->aStubJobs; }
    public function getSpooledFiles(): array { return $this->aStubSpooledFiles; }
    public function getServicePorts(): array { return [$this->iStubPort]; }
}

// ---------------------------------------------------------------------------
// Test class
// ---------------------------------------------------------------------------
class PrintServerAdminTest extends TestCase
{
    private FakePrintServer $oProvider;
    private PrintServerAdmin $oAdmin;

    protected function setUp(): void
    {
        $oLogger = new Logger('test');
        $oLogger->pushHandler(new NullHandler());

        $this->oProvider = new FakePrintServer($oLogger);
        $this->oAdmin    = new PrintServerAdmin($this->oProvider);

        $oStub = $this->createStub(ServiceDispatcher::class);
        $rp    = new \ReflectionProperty(ServiceDispatcher::class, 'oSingleton');
        $rp->setAccessible(true);
        $rp->setValue(null, $oStub);
    }

    protected function tearDown(): void
    {
        $rp = new \ReflectionProperty(ServiceDispatcher::class, 'oSingleton');
        $rp->setAccessible(true);
        $rp->setValue(null, null);
    }

    // -----------------------------------------------------------------------
    // getName / getDescription
    // -----------------------------------------------------------------------

    public function testGetNameReturnsPrintServer(): void
    {
        $this->assertSame('Print Server', $this->oAdmin->getName());
    }

    public function testGetDescriptionIsNonEmpty(): void
    {
        $this->assertNotEmpty($this->oAdmin->getDescription());
    }

    // -----------------------------------------------------------------------
    // isDisabled / getStatus
    // -----------------------------------------------------------------------

    public function testIsDisabledDefaultsFalse(): void
    {
        $this->assertFalse($this->oAdmin->isDisabled());
    }

    public function testGetStatusDefaultsOnline(): void
    {
        $this->assertSame('On-line', $this->oAdmin->getStatus());
    }

    public function testSetDisabledMakesAdminDisabled(): void
    {
        $this->oAdmin->setDisabled();
        $this->assertTrue($this->oAdmin->isDisabled());
        $this->assertSame('Disabled', $this->oAdmin->getStatus());
    }

    public function testSetEnabledRestoresOnlineStatus(): void
    {
        $this->oAdmin->setDisabled();
        $this->oAdmin->setEnabled();
        $this->assertFalse($this->oAdmin->isDisabled());
        $this->assertSame('On-line', $this->oAdmin->getStatus());
    }

    // -----------------------------------------------------------------------
    // getEntityTypes
    // -----------------------------------------------------------------------

    public function testGetEntityTypesHasJobsAndSpooledKeys(): void
    {
        $aTypes = $this->oAdmin->getEntityTypes();
        $this->assertArrayHasKey('jobs', $aTypes);
        $this->assertArrayHasKey('spooled', $aTypes);
    }

    // -----------------------------------------------------------------------
    // getEntityFields
    // -----------------------------------------------------------------------

    public function testGetEntityFieldsJobsHasExpectedKeys(): void
    {
        $aFields = $this->oAdmin->getEntityFields('jobs');
        $this->assertArrayHasKey('network', $aFields);
        $this->assertArrayHasKey('station', $aFields);
        $this->assertArrayHasKey('began', $aFields);
        $this->assertArrayHasKey('size', $aFields);
    }

    public function testGetEntityFieldsSpooledHasExpectedKeys(): void
    {
        $aFields = $this->oAdmin->getEntityFields('spooled');
        $this->assertArrayHasKey('user', $aFields);
        $this->assertArrayHasKey('filename', $aFields);
        $this->assertArrayHasKey('size', $aFields);
        $this->assertArrayHasKey('download', $aFields);
    }

    public function testGetEntityFieldsUnknownReturnsEmpty(): void
    {
        $this->assertSame([], $this->oAdmin->getEntityFields('unknown'));
    }

    // -----------------------------------------------------------------------
    // getEntities — jobs
    // -----------------------------------------------------------------------

    public function testGetEntitiesJobsReturnsEmptyByDefault(): void
    {
        $this->assertSame([], $this->oAdmin->getEntities('jobs'));
    }

    public function testGetEntitiesJobsCallsProviderGetJobs(): void
    {
        $this->oProvider->aStubJobs = [
            ['network' => 1, 'station' => 5, 'began' => 1000, 'size' => 512],
        ];
        $aEntities = $this->oAdmin->getEntities('jobs');
        $this->assertCount(1, $aEntities);
        $this->assertInstanceOf(AdminEntity::class, $aEntities[0]);
    }

    public function testGetEntitiesJobsEntityHasCorrectValues(): void
    {
        $this->oProvider->aStubJobs = [
            ['network' => 2, 'station' => 8, 'began' => 2000, 'size' => 1024],
        ];
        $oEntity = $this->oAdmin->getEntities('jobs')[0];
        $this->assertSame(2, $oEntity->getValue('network'));
        $this->assertSame(8, $oEntity->getValue('station'));
        $this->assertSame(1024, $oEntity->getValue('size'));
    }

    public function testGetEntitiesJobsUsesCallableIdCombiningNetworkAndStation(): void
    {
        $this->oProvider->aStubJobs = [
            ['network' => 3, 'station' => 12, 'began' => 1, 'size' => 100],
        ];
        $oEntity = $this->oAdmin->getEntities('jobs')[0];
        $this->assertSame('3_12', $oEntity->getId());
    }

    // -----------------------------------------------------------------------
    // getEntities — spooled
    // -----------------------------------------------------------------------

    public function testGetEntitiesSpooledReturnsEmptyByDefault(): void
    {
        $this->assertSame([], $this->oAdmin->getEntities('spooled'));
    }

    public function testGetEntitiesSpooledCallsProviderGetSpooledFiles(): void
    {
        $this->oProvider->aStubSpooledFiles = [
            ['user' => 'ALICE', 'filename' => 'test.txt', 'size' => 200, 'modified' => 9999, 'path' => '/spool/ALICE/test.txt'],
        ];
        $aEntities = $this->oAdmin->getEntities('spooled');
        $this->assertCount(1, $aEntities);
    }

    public function testGetEntitiesSpooledEntityHasCorrectBaseValues(): void
    {
        $this->oProvider->aStubSpooledFiles = [
            ['user' => 'BOB', 'filename' => 'job.prn', 'size' => 512, 'modified' => 100, 'path' => '/spool/BOB/job.prn'],
        ];
        $oEntity = $this->oAdmin->getEntities('spooled')[0];
        $this->assertSame('BOB', $oEntity->getValue('user'));
        $this->assertSame('job.prn', $oEntity->getValue('filename'));
    }

    public function testGetEntitiesSpooledEntityDownloadUrlContainsPort(): void
    {
        $this->oProvider->iStubPort         = 0x9C;
        $this->oProvider->aStubSpooledFiles = [
            ['user' => 'ALICE', 'filename' => 'out.txt', 'size' => 100, 'modified' => 0, 'path' => '/spool/ALICE/out.txt'],
        ];
        $oEntity = $this->oAdmin->getEntities('spooled')[0];
        $sUrl    = $oEntity->getValue('download');
        $this->assertStringContainsString('port=' . 0x9C, $sUrl);
    }

    public function testGetEntitiesSpooledEntityDownloadUrlContainsEncodedPath(): void
    {
        $this->oProvider->aStubSpooledFiles = [
            ['user' => 'ALICE', 'filename' => 'out.txt', 'size' => 100, 'modified' => 0, 'path' => '/spool/ALICE/out.txt'],
        ];
        $oEntity = $this->oAdmin->getEntities('spooled')[0];
        $sUrl    = $oEntity->getValue('download');
        $this->assertStringContainsString(urlencode('/spool/ALICE/out.txt'), $sUrl);
    }

    public function testGetEntitiesSpooledEntityDownloadUrlStartsWithServiceDownload(): void
    {
        $this->oProvider->aStubSpooledFiles = [
            ['user' => 'CAROL', 'filename' => 'p.txt', 'size' => 50, 'modified' => 0, 'path' => '/x'],
        ];
        $oEntity = $this->oAdmin->getEntities('spooled')[0];
        $this->assertStringStartsWith('/service/download?', $oEntity->getValue('download'));
    }

    public function testGetEntitiesSpooledUsesPathAsCallableId(): void
    {
        $this->oProvider->aStubSpooledFiles = [
            ['user' => 'DAN', 'filename' => 'f.txt', 'size' => 10, 'modified' => 0, 'path' => '/spool/DAN/f.txt'],
        ];
        $oEntity = $this->oAdmin->getEntities('spooled')[0];
        $this->assertSame('/spool/DAN/f.txt', $oEntity->getId());
    }

    // -----------------------------------------------------------------------
    // getEntities — unknown type
    // -----------------------------------------------------------------------

    public function testGetEntitiesUnknownTypeReturnsEmpty(): void
    {
        $this->assertSame([], $this->oAdmin->getEntities('unknown'));
    }

    // -----------------------------------------------------------------------
    // getCommands
    // -----------------------------------------------------------------------

    public function testGetCommandsReturnsEmptyArray(): void
    {
        $this->assertSame([], $this->oAdmin->getCommands());
    }
}
