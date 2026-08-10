<?php

/*
 * @group unit-tests
 *
 * Tests for HomeLan\FileStore\Services\Provider\Bridge\Admin.
 *
 * FakeBridge extends Bridge, keeps the real constructor (only sets logger),
 * and overrides getRemoteNetworks() / getLocalKnownNetworks() to return
 * controlled stub data so the admin tests are independent of Map state.
 */

include_once(__DIR__ . '/../../src/include/system.inc.php');

use PHPUnit\Framework\TestCase;
use Monolog\Logger;
use Monolog\Handler\NullHandler;
use HomeLan\FileStore\Services\Provider\Bridge;
use HomeLan\FileStore\Services\Provider\Bridge\Admin as BridgeAdmin;
use HomeLan\FileStore\Services\Provider\AdminEntity;
use HomeLan\FileStore\Services\ServiceDispatcher;

// ---------------------------------------------------------------------------
// Fake provider — trivial real constructor, overridden data methods
// ---------------------------------------------------------------------------
class FakeBridge extends Bridge
{
    public array $aStubRemoteNetworks      = [];
    public array $aStubLocalNetworks       = [];

    public function getRemoteNetworks(): array      { return $this->aStubRemoteNetworks; }
    public function getLocalKnownNetworks(): array  { return $this->aStubLocalNetworks; }
}

// ---------------------------------------------------------------------------
// Test class
// ---------------------------------------------------------------------------
class BridgeAdminTest extends TestCase
{
    private FakeBridge $oProvider;
    private BridgeAdmin $oAdmin;

    protected function setUp(): void
    {
        $oLogger = new Logger('test');
        $oLogger->pushHandler(new NullHandler());

        $this->oProvider = new FakeBridge($oLogger);
        $this->oAdmin    = new BridgeAdmin($this->oProvider);

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

    public function testGetNameReturnsEconetBridge(): void
    {
        $this->assertSame('Econet Bridge', $this->oAdmin->getName());
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

    public function testGetEntityTypesHasRemoteKey(): void
    {
        $this->assertArrayHasKey('remote', $this->oAdmin->getEntityTypes());
    }

    public function testGetEntityTypesHasLocalKey(): void
    {
        $this->assertArrayHasKey('local', $this->oAdmin->getEntityTypes());
    }

    // -----------------------------------------------------------------------
    // getEntityFields
    // -----------------------------------------------------------------------

    public function testGetEntityFieldsRemoteHasNetworkAndViaKeys(): void
    {
        $aFields = $this->oAdmin->getEntityFields('remote');
        $this->assertArrayHasKey('network', $aFields);
        $this->assertArrayHasKey('via', $aFields);
    }

    public function testGetEntityFieldsLocalHasNetworkAndViaKeys(): void
    {
        $aFields = $this->oAdmin->getEntityFields('local');
        $this->assertArrayHasKey('network', $aFields);
        $this->assertArrayHasKey('via', $aFields);
    }

    public function testGetEntityFieldsUnknownReturnsEmpty(): void
    {
        $this->assertSame([], $this->oAdmin->getEntityFields('unknown'));
    }

    // -----------------------------------------------------------------------
    // getEntities — remote
    // -----------------------------------------------------------------------

    public function testGetEntitiesRemoteReturnsEmptyWhenNoPeerNetworksLearned(): void
    {
        $this->assertSame([], $this->oAdmin->getEntities('remote'));
    }

    public function testGetEntitiesRemoteCallsProviderGetRemoteNetworks(): void
    {
        $this->oProvider->aStubRemoteNetworks = [
            ['network' => 5, 'via' => '1.254'],
        ];
        $aEntities = $this->oAdmin->getEntities('remote');
        $this->assertCount(1, $aEntities);
        $this->assertInstanceOf(AdminEntity::class, $aEntities[0]);
    }

    public function testGetEntitiesRemoteEntityHasCorrectValues(): void
    {
        $this->oProvider->aStubRemoteNetworks = [
            ['network' => 7, 'via' => '2.100'],
        ];
        $oEntity = $this->oAdmin->getEntities('remote')[0];
        $this->assertSame(7,       $oEntity->getValue('network'));
        $this->assertSame('2.100', $oEntity->getValue('via'));
    }

    public function testGetEntitiesRemoteUsesNetworkFieldAsId(): void
    {
        $this->oProvider->aStubRemoteNetworks = [
            ['network' => 9, 'via' => '3.1'],
        ];
        $this->assertSame(9, $this->oAdmin->getEntities('remote')[0]->getId());
    }

    public function testGetEntitiesRemoteReturnsMultipleNetworks(): void
    {
        $this->oProvider->aStubRemoteNetworks = [
            ['network' => 5, 'via' => '1.254'],
            ['network' => 6, 'via' => '1.254'],
            ['network' => 7, 'via' => '2.1'],
        ];
        $this->assertCount(3, $this->oAdmin->getEntities('remote'));
    }

    // -----------------------------------------------------------------------
    // getEntities — local
    // -----------------------------------------------------------------------

    public function testGetEntitiesLocalReturnsEmptyWhenNoLocalNetworksConfigured(): void
    {
        $this->assertSame([], $this->oAdmin->getEntities('local'));
    }

    public function testGetEntitiesLocalCallsProviderGetLocalKnownNetworks(): void
    {
        $this->oProvider->aStubLocalNetworks = [
            ['network' => 10, 'via' => 'AUN'],
        ];
        $aEntities = $this->oAdmin->getEntities('local');
        $this->assertCount(1, $aEntities);
        $this->assertInstanceOf(AdminEntity::class, $aEntities[0]);
    }

    public function testGetEntitiesLocalEntityHasCorrectValues(): void
    {
        $this->oProvider->aStubLocalNetworks = [
            ['network' => 128, 'via' => 'WebSocket'],
        ];
        $oEntity = $this->oAdmin->getEntities('local')[0];
        $this->assertSame(128,         $oEntity->getValue('network'));
        $this->assertSame('WebSocket', $oEntity->getValue('via'));
    }

    public function testGetEntitiesLocalUsesNetworkFieldAsId(): void
    {
        $this->oProvider->aStubLocalNetworks = [
            ['network' => 3, 'via' => 'Piconet'],
        ];
        $this->assertSame(3, $this->oAdmin->getEntities('local')[0]->getId());
    }

    public function testGetEntitiesLocalReturnsMultipleTransportSources(): void
    {
        $this->oProvider->aStubLocalNetworks = [
            ['network' => 10, 'via' => 'AUN'],
            ['network' => 11, 'via' => 'Piconet'],
            ['network' => 12, 'via' => 'WebSocket'],
            ['network' => 13, 'via' => 'RemoteBridge'],
        ];
        $this->assertCount(4, $this->oAdmin->getEntities('local'));
    }

    public function testGetEntitiesLocalViaLabelReflectsTransport(): void
    {
        $this->oProvider->aStubLocalNetworks = [
            ['network' => 1, 'via' => 'local'],
        ];
        $this->assertSame('local', $this->oAdmin->getEntities('local')[0]->getValue('via'));
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

    // -----------------------------------------------------------------------
    // Bridge::getAdminInterface() returns the Admin object
    // -----------------------------------------------------------------------

    public function testBridgeGetAdminInterfaceReturnsAdminInstance(): void
    {
        $oLogger = new Logger('test');
        $oLogger->pushHandler(new NullHandler());
        $oBridge = new Bridge($oLogger);
        $this->assertInstanceOf(BridgeAdmin::class, $oBridge->getAdminInterface());
    }

    // -----------------------------------------------------------------------
    // Bridge::getRemoteNetworks() integration (real Bridge, empty state)
    // -----------------------------------------------------------------------

    public function testBridgeGetRemoteNetworksReturnsEmptyWithNoQueriesProcessed(): void
    {
        $oLogger = new Logger('test');
        $oLogger->pushHandler(new NullHandler());
        $oBridge = new Bridge($oLogger);
        $this->assertSame([], $oBridge->getRemoteNetworks());
    }
}
