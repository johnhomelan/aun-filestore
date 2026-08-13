<?php

/*
 * @group unit-tests
 *
 * Tests for HomeLan\FileStore\Services\Provider\Teletext\Admin.
 *
 * FakeTeletext extends Teletext, keeps the real constructor, and overrides
 * the admin-facing data methods to return controlled stubs — mirroring
 * printServerAdminTest.php's FakePrintServer pattern.
 */

include_once(__DIR__ . '/../../src/include/system.inc.php');

use PHPUnit\Framework\TestCase;
use Monolog\Logger;
use Monolog\Handler\NullHandler;
use HomeLan\FileStore\Services\Provider\Teletext;
use HomeLan\FileStore\Services\Provider\Teletext\Admin as TeletextAdmin;
use HomeLan\FileStore\Services\Provider\AdminEntity;
use HomeLan\FileStore\Services\ServiceDispatcher;

// ---------------------------------------------------------------------------
// Fake provider
// ---------------------------------------------------------------------------
class FakeTeletext extends Teletext
{
    public array $aStubChannels = [];
    public bool  $bStubServiceActive = true;

    public function getChannelSummaries(): array { return $this->aStubChannels; }
    public function isServiceActive(): bool { return $this->bStubServiceActive; }
}

// ---------------------------------------------------------------------------
// Test class
// ---------------------------------------------------------------------------
class TeletextAdminTest extends TestCase
{
    private FakeTeletext  $oProvider;
    private TeletextAdmin $oAdmin;

    protected function setUp(): void
    {
        $oLogger = new Logger('test');
        $oLogger->pushHandler(new NullHandler());

        $this->oProvider = new FakeTeletext($oLogger, Mockery::mock(\HomeLan\FileStore\Services\Provider\Teletext\Storage::class));
        $this->oAdmin     = new TeletextAdmin($this->oProvider);

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

    public function testGetNameReturnsTeletext(): void
    {
        $this->assertSame('Teletext', $this->oAdmin->getName());
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

    public function testGetStatusReportsSuspendedWhenServiceToggledOff(): void
    {
        $this->oProvider->bStubServiceActive = false;
        $this->assertStringContainsString('Suspended', $this->oAdmin->getStatus());
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

    public function testGetEntityTypesHasChannelsKey(): void
    {
        $this->assertArrayHasKey('channels', $this->oAdmin->getEntityTypes());
    }

    public function testGetEntityFieldsChannelsHasExpectedKeys(): void
    {
        $aFields = $this->oAdmin->getEntityFields('channels');
        $this->assertArrayHasKey('channel', $aFields);
        $this->assertArrayHasKey('page_count', $aFields);
    }

    public function testGetEntityFieldsUnknownReturnsEmpty(): void
    {
        $this->assertSame([], $this->oAdmin->getEntityFields('unknown'));
    }

    // -----------------------------------------------------------------------
    // getEntities — channels
    // -----------------------------------------------------------------------

    public function testGetEntitiesChannelsReturnsEmptyByDefault(): void
    {
        $this->assertSame([], $this->oAdmin->getEntities('channels'));
    }

    public function testGetEntitiesChannelsCallsProviderGetChannelSummaries(): void
    {
        $this->oProvider->aStubChannels = [
            ['channel' => '1', 'page_count' => 3],
        ];
        $aEntities = $this->oAdmin->getEntities('channels');
        $this->assertCount(1, $aEntities);
        $this->assertInstanceOf(AdminEntity::class, $aEntities[0]);
    }

    public function testGetEntitiesChannelsEntityHasCorrectValues(): void
    {
        $this->oProvider->aStubChannels = [
            ['channel' => '2', 'page_count' => 7],
        ];
        $oEntity = $this->oAdmin->getEntities('channels')[0];
        $this->assertSame('2', $oEntity->getValue('channel'));
        $this->assertSame(7, $oEntity->getValue('page_count'));
    }

    public function testGetEntitiesChannelsUsesChannelAsId(): void
    {
        $this->oProvider->aStubChannels = [
            ['channel' => '5', 'page_count' => 1],
        ];
        $this->assertSame('5', $this->oAdmin->getEntities('channels')[0]->getId());
    }

    public function testGetEntitiesUnknownTypeReturnsEmpty(): void
    {
        $this->assertSame([], $this->oAdmin->getEntities('unknown'));
    }

    // -----------------------------------------------------------------------
    // getCommands
    // -----------------------------------------------------------------------

    public function testGetCommandsIncludesRefreshTeefaxNow(): void
    {
        $aUrls = array_column($this->oAdmin->getCommands(), 'url');
        $this->assertContains('/service/teletext/teefax-refresh', $aUrls);
    }
}
