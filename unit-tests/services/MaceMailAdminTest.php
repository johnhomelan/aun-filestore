<?php

/*
 * @group unit-tests
 *
 * Tests for HomeLan\FileStore\Services\Provider\MaceMail\Admin.
 *
 * FakeMaceMail extends MaceMail, keeps the real constructor, and overrides
 * the admin-facing data methods to return controlled stubs — mirroring
 * printServerAdminTest.php's FakePrintServer pattern.
 */

include_once(__DIR__ . '/../../src/include/system.inc.php');

use PHPUnit\Framework\TestCase;
use Monolog\Logger;
use Monolog\Handler\NullHandler;
use HomeLan\FileStore\Services\Provider\MaceMail;
use HomeLan\FileStore\Services\Provider\MaceMail\Admin as MaceMailAdmin;
use HomeLan\FileStore\Services\Provider\AdminEntity;
use HomeLan\FileStore\Services\ServiceDispatcher;

// ---------------------------------------------------------------------------
// Fake provider
// ---------------------------------------------------------------------------
class FakeMaceMail extends MaceMail
{
    public array $aStubSlots  = [];
    public array $aStubOnline = [];

    public function getRegisteredSlots(): array { return $this->aStubSlots; }
    public function getOnlineMailUsers(): array { return $this->aStubOnline; }
}

// ---------------------------------------------------------------------------
// Test class
// ---------------------------------------------------------------------------
class MaceMailAdminTest extends TestCase
{
    private FakeMaceMail  $oProvider;
    private MaceMailAdmin $oAdmin;

    protected function setUp(): void
    {
        $oLogger = new Logger('test');
        $oLogger->pushHandler(new NullHandler());

        $this->oProvider = new FakeMaceMail($oLogger, Mockery::mock(\HomeLan\FileStore\Services\Provider\MaceMail\Storage::class));
        $this->oAdmin     = new MaceMailAdmin($this->oProvider);

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
        Mockery::close();
    }

    // -----------------------------------------------------------------------
    // getName / getDescription
    // -----------------------------------------------------------------------

    public function testGetNameReturnsMaceMail(): void
    {
        $this->assertSame('MaceMail', $this->oAdmin->getName());
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
    // getEntityTypes / getEntityFields
    // -----------------------------------------------------------------------

    public function testGetEntityTypesHasSlotsAndOnlineKeys(): void
    {
        $aTypes = $this->oAdmin->getEntityTypes();
        $this->assertArrayHasKey('slots', $aTypes);
        $this->assertArrayHasKey('online', $aTypes);
    }

    public function testGetEntityFieldsSlotsHasExpectedKeys(): void
    {
        $aFields = $this->oAdmin->getEntityFields('slots');
        $this->assertArrayHasKey('slot', $aFields);
        $this->assertArrayHasKey('username', $aFields);
        $this->assertArrayHasKey('online', $aFields);
        $this->assertArrayHasKey('last_used', $aFields);
        $this->assertArrayHasKey('store_mask', $aFields);
    }

    public function testGetEntityFieldsSlotsOnlineIsBool(): void
    {
        $this->assertSame('bool', $this->oAdmin->getEntityFields('slots')['online']);
    }

    public function testGetEntityFieldsOnlineHasExpectedKeys(): void
    {
        $aFields = $this->oAdmin->getEntityFields('online');
        $this->assertArrayHasKey('username', $aFields);
        $this->assertArrayHasKey('network', $aFields);
        $this->assertArrayHasKey('station', $aFields);
    }

    public function testGetEntityFieldsUnknownReturnsEmpty(): void
    {
        $this->assertSame([], $this->oAdmin->getEntityFields('unknown'));
    }

    // -----------------------------------------------------------------------
    // getEntities — slots
    // -----------------------------------------------------------------------

    public function testGetEntitiesSlotsReturnsEmptyByDefault(): void
    {
        $this->assertSame([], $this->oAdmin->getEntities('slots'));
    }

    public function testGetEntitiesSlotsCallsProviderGetRegisteredSlots(): void
    {
        $this->oProvider->aStubSlots = [
            ['slot' => 3, 'username' => 'JSMITH', 'online' => true, 'last_used' => '15/06/26', 'store_mask' => 0],
        ];
        $aEntities = $this->oAdmin->getEntities('slots');
        $this->assertCount(1, $aEntities);
        $this->assertInstanceOf(AdminEntity::class, $aEntities[0]);
    }

    public function testGetEntitiesSlotsEntityHasCorrectValues(): void
    {
        $this->oProvider->aStubSlots = [
            ['slot' => 3, 'username' => 'JSMITH', 'online' => true, 'last_used' => '15/06/26', 'store_mask' => 5],
        ];
        $oEntity = $this->oAdmin->getEntities('slots')[0];
        $this->assertSame(3, $oEntity->getValue('slot'));
        $this->assertSame('JSMITH', $oEntity->getValue('username'));
        $this->assertTrue($oEntity->getValue('online'));
        $this->assertSame(5, $oEntity->getValue('store_mask'));
    }

    public function testGetEntitiesSlotsUsesSlotNumberAsId(): void
    {
        $this->oProvider->aStubSlots = [
            ['slot' => 7, 'username' => 'AWILSON', 'online' => false, 'last_used' => '01/01/24', 'store_mask' => 0],
        ];
        $this->assertSame(7, $this->oAdmin->getEntities('slots')[0]->getId());
    }

    // -----------------------------------------------------------------------
    // getEntities — online
    // -----------------------------------------------------------------------

    public function testGetEntitiesOnlineReturnsEmptyByDefault(): void
    {
        $this->assertSame([], $this->oAdmin->getEntities('online'));
    }

    public function testGetEntitiesOnlineCallsProviderGetOnlineMailUsers(): void
    {
        $this->oProvider->aStubOnline = [
            ['username' => 'JSMITH', 'network' => 0, 'station' => 201],
        ];
        $aEntities = $this->oAdmin->getEntities('online');
        $this->assertCount(1, $aEntities);
        $this->assertSame('JSMITH', $aEntities[0]->getValue('username'));
        $this->assertSame('JSMITH', $aEntities[0]->getId());
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

    public function testGetCommandsReturnsFourActions(): void
    {
        $this->assertCount(4, $this->oAdmin->getCommands());
    }

    public function testGetCommandsIncludesAssignSlot(): void
    {
        $aUrls = array_column($this->oAdmin->getCommands(), 'url');
        $this->assertContains('/service/macemail/slots/assign', $aUrls);
    }
}
