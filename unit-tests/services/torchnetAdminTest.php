<?php

/*
 * @group unit-tests
 *
 * Tests for HomeLan\FileStore\Services\Provider\Torchnet\Admin.
 *
 * FakeTorchnet extends Torchnet (trivial constructor: just promotes oLogger),
 * and overrides data methods to return controlled stubs.
 */

include_once(__DIR__ . '/../../src/include/system.inc.php');

use PHPUnit\Framework\TestCase;
use Monolog\Logger;
use Monolog\Handler\NullHandler;
use HomeLan\FileStore\Services\Provider\Torchnet;
use HomeLan\FileStore\Services\Provider\Torchnet\Admin as TorchnetAdmin;
use HomeLan\FileStore\Services\Provider\AdminEntity;
use HomeLan\FileStore\Services\ServiceDispatcher;

// ---------------------------------------------------------------------------
// Fake provider — trivial constructor, overridden data methods
// ---------------------------------------------------------------------------
class FakeTorchnet extends Torchnet
{
    public array $aStubStations    = [];
    public array $aStubFileHandles = [];
    public array $aStubPorts       = [0x90, 0x91];

    public function getConnectedStations(): array { return $this->aStubStations; }
    public function getOpenFileHandles(): array   { return $this->aStubFileHandles; }
    public function getServicePorts(): array      { return $this->aStubPorts; }
}

// ---------------------------------------------------------------------------
// Test class
// ---------------------------------------------------------------------------
class TorchnetAdminTest extends TestCase
{
    private FakeTorchnet $oProvider;
    private TorchnetAdmin $oAdmin;

    protected function setUp(): void
    {
        $oLogger = new Logger('test');
        $oLogger->pushHandler(new NullHandler());

        $this->oProvider = new FakeTorchnet($oLogger);
        $this->oAdmin    = new TorchnetAdmin($this->oProvider);

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

    public function testGetNameReturnsTorchNetFileServer(): void
    {
        $this->assertSame('TorchNet File Server', $this->oAdmin->getName());
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

    public function testGetEntityTypesHasStationAndHandleKeys(): void
    {
        $aTypes = $this->oAdmin->getEntityTypes();
        $this->assertArrayHasKey('station', $aTypes);
        $this->assertArrayHasKey('handle', $aTypes);
    }

    // -----------------------------------------------------------------------
    // getEntityFields
    // -----------------------------------------------------------------------

    public function testGetEntityFieldsStationHasExpectedKeys(): void
    {
        $aFields = $this->oAdmin->getEntityFields('station');
        $this->assertArrayHasKey('network', $aFields);
        $this->assertArrayHasKey('station', $aFields);
        $this->assertArrayHasKey('open_handles', $aFields);
    }

    public function testGetEntityFieldsHandleHasExpectedKeys(): void
    {
        $aFields = $this->oAdmin->getEntityFields('handle');
        $this->assertArrayHasKey('network', $aFields);
        $this->assertArrayHasKey('station', $aFields);
        $this->assertArrayHasKey('handle', $aFields);
        $this->assertArrayHasKey('path', $aFields);
    }

    public function testGetEntityFieldsUnknownReturnsEmpty(): void
    {
        $this->assertSame([], $this->oAdmin->getEntityFields('unknown'));
    }

    // -----------------------------------------------------------------------
    // getEntities — station
    // -----------------------------------------------------------------------

    public function testGetEntitiesStationReturnsEmptyByDefault(): void
    {
        $this->assertSame([], $this->oAdmin->getEntities('station'));
    }

    public function testGetEntitiesStationCallsProviderGetConnectedStations(): void
    {
        $this->oProvider->aStubStations = [
            ['network' => 1, 'station' => 5, 'open_handles' => 2],
        ];
        $aEntities = $this->oAdmin->getEntities('station');
        $this->assertCount(1, $aEntities);
        $this->assertInstanceOf(AdminEntity::class, $aEntities[0]);
    }

    public function testGetEntitiesStationEntityHasCorrectValues(): void
    {
        $this->oProvider->aStubStations = [
            ['network' => 3, 'station' => 7, 'open_handles' => 4],
        ];
        $oEntity = $this->oAdmin->getEntities('station')[0];
        $this->assertSame(3, $oEntity->getValue('network'));
        $this->assertSame(7, $oEntity->getValue('station'));
        $this->assertSame(4, $oEntity->getValue('open_handles'));
    }

    public function testGetEntitiesStationUsesCallableIdCombiningNetworkAndStation(): void
    {
        $this->oProvider->aStubStations = [
            ['network' => 2, 'station' => 10, 'open_handles' => 0],
        ];
        $oEntity = $this->oAdmin->getEntities('station')[0];
        $this->assertSame('2_10', $oEntity->getId());
    }

    public function testGetEntitiesStationReturnsMultipleRows(): void
    {
        $this->oProvider->aStubStations = [
            ['network' => 1, 'station' => 1, 'open_handles' => 1],
            ['network' => 1, 'station' => 2, 'open_handles' => 0],
        ];
        $this->assertCount(2, $this->oAdmin->getEntities('station'));
    }

    // -----------------------------------------------------------------------
    // getEntities — handle
    // -----------------------------------------------------------------------

    public function testGetEntitiesHandleReturnsEmptyByDefault(): void
    {
        $this->assertSame([], $this->oAdmin->getEntities('handle'));
    }

    public function testGetEntitiesHandleCallsProviderGetOpenFileHandles(): void
    {
        $this->oProvider->aStubFileHandles = [
            ['network' => 1, 'station' => 5, 'handle' => 3, 'path' => '$.TorchDrives.E.FILE\TXT'],
        ];
        $aEntities = $this->oAdmin->getEntities('handle');
        $this->assertCount(1, $aEntities);
    }

    public function testGetEntitiesHandleEntityHasCorrectValues(): void
    {
        $this->oProvider->aStubFileHandles = [
            ['network' => 2, 'station' => 8, 'handle' => 5, 'path' => '$.TorchDrives.E.MYPROG\COM'],
        ];
        $oEntity = $this->oAdmin->getEntities('handle')[0];
        $this->assertSame(2, $oEntity->getValue('network'));
        $this->assertSame(8, $oEntity->getValue('station'));
        $this->assertSame(5, $oEntity->getValue('handle'));
        $this->assertSame('$.TorchDrives.E.MYPROG\COM', $oEntity->getValue('path'));
    }

    public function testGetEntitiesHandleUsesCallableIdCombiningNetworkStationHandle(): void
    {
        $this->oProvider->aStubFileHandles = [
            ['network' => 3, 'station' => 12, 'handle' => 7, 'path' => '$.x'],
        ];
        $oEntity = $this->oAdmin->getEntities('handle')[0];
        $this->assertSame('3_12_7', $oEntity->getId());
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

    public function testGetCommandsReturnsOneCommand(): void
    {
        $aCommands = $this->oAdmin->getCommands();
        $this->assertCount(1, $aCommands);
    }

    public function testGetCommandsCommandHasLabelKey(): void
    {
        $aCommands = $this->oAdmin->getCommands();
        $this->assertArrayHasKey('label', $aCommands[0]);
        $this->assertNotEmpty($aCommands[0]['label']);
    }

    public function testGetCommandsCommandUrlContainsPort(): void
    {
        $this->oProvider->aStubPorts = [0x90, 0x91];
        $aCommands = $this->oAdmin->getCommands();
        $this->assertStringContainsString('port=' . 0x90, $aCommands[0]['url']);
    }

    public function testGetCommandsCommandUrlStartsWithTorchnetBrowse(): void
    {
        $aCommands = $this->oAdmin->getCommands();
        $this->assertStringStartsWith('/service/torchnet/browse', $aCommands[0]['url']);
    }
}
