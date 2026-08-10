<?php

/*
 * @group unit-tests
 *
 * Tests for HomeLan\FileStore\Services\Provider\BeebTerm\Admin.
 *
 * FakeBeebTerm extends BeebTerm, bypasses the real constructor, and returns
 * controlled stub data so we can verify Admin delegates correctly.
 */

include_once(__DIR__ . '/../../src/include/system.inc.php');

use PHPUnit\Framework\TestCase;
use Monolog\Logger;
use Monolog\Handler\NullHandler;
use HomeLan\FileStore\Services\Provider\BeebTerm;
use HomeLan\FileStore\Services\Provider\BeebTerm\Admin as BeebTermAdmin;
use HomeLan\FileStore\Services\Provider\AdminEntity;
use HomeLan\FileStore\Services\ServiceDispatcher;

// ---------------------------------------------------------------------------
// Fake provider — overrides constructor and data methods
// ---------------------------------------------------------------------------
class FakeBeebTerm extends BeebTerm
{
    public array $aStubSessions = [];
    public array $aStubServices = [];

    public function __construct()
    {
        // bypass real constructor (reads services file)
    }

    public function getSessions(): array { return $this->aStubSessions; }
    public function getServices(): array { return $this->aStubServices; }
    public function getServicePorts(): array { return [0x9C]; }
}

// ---------------------------------------------------------------------------
// Test class
// ---------------------------------------------------------------------------
class BeebTermAdminTest extends TestCase
{
    private FakeBeebTerm $oProvider;
    private BeebTermAdmin $oAdmin;

    protected function setUp(): void
    {
        $this->oProvider = new FakeBeebTerm();
        $this->oAdmin    = new BeebTermAdmin($this->oProvider);

        // Pre-seed the ServiceDispatcher singleton so setDisabled/setEnabled don't fatal
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

    public function testGetNameReturnsExpectedString(): void
    {
        $this->assertSame('Beeb Term', $this->oAdmin->getName());
    }

    public function testGetDescriptionReturnsNonEmptyString(): void
    {
        $this->assertNotEmpty($this->oAdmin->getDescription());
    }

    // -----------------------------------------------------------------------
    // isDisabled / getStatus defaults
    // -----------------------------------------------------------------------

    public function testIsDisabledIsFalseByDefault(): void
    {
        $this->assertFalse($this->oAdmin->isDisabled());
    }

    public function testGetStatusIsOnlineByDefault(): void
    {
        $this->assertSame('On-line', $this->oAdmin->getStatus());
    }

    // -----------------------------------------------------------------------
    // setDisabled / setEnabled
    // -----------------------------------------------------------------------

    public function testSetDisabledMakesIsDisabledTrue(): void
    {
        $this->oAdmin->setDisabled();
        $this->assertTrue($this->oAdmin->isDisabled());
    }

    public function testSetDisabledChangesStatusToDisabled(): void
    {
        $this->oAdmin->setDisabled();
        $this->assertSame('Disabled', $this->oAdmin->getStatus());
    }

    public function testSetEnabledAfterDisableRestoresOnlineStatus(): void
    {
        $this->oAdmin->setDisabled();
        $this->oAdmin->setEnabled();
        $this->assertFalse($this->oAdmin->isDisabled());
        $this->assertSame('On-line', $this->oAdmin->getStatus());
    }

    // -----------------------------------------------------------------------
    // getEntityTypes
    // -----------------------------------------------------------------------

    public function testGetEntityTypesContainsSessionKey(): void
    {
        $this->assertArrayHasKey('session', $this->oAdmin->getEntityTypes());
    }

    public function testGetEntityTypesContainsServiceKey(): void
    {
        $this->assertArrayHasKey('service', $this->oAdmin->getEntityTypes());
    }

    // -----------------------------------------------------------------------
    // getEntityFields
    // -----------------------------------------------------------------------

    public function testGetEntityFieldsForSessionContainsExpectedKeys(): void
    {
        $aFields = $this->oAdmin->getEntityFields('session');
        $this->assertArrayHasKey('network', $aFields);
        $this->assertArrayHasKey('station', $aFields);
        $this->assertArrayHasKey('command', $aFields);
        $this->assertArrayHasKey('pid', $aFields);
    }

    public function testGetEntityFieldsForServiceContainsExpectedKeys(): void
    {
        $aFields = $this->oAdmin->getEntityFields('service');
        $this->assertArrayHasKey('name', $aFields);
        $this->assertArrayHasKey('command', $aFields);
    }

    public function testGetEntityFieldsForUnknownTypeReturnsEmptyArray(): void
    {
        $this->assertSame([], $this->oAdmin->getEntityFields('unknown'));
    }

    // -----------------------------------------------------------------------
    // getEntities — session
    // -----------------------------------------------------------------------

    public function testGetEntitiesSessionReturnsEmptyArrayWhenNoSessions(): void
    {
        $this->assertSame([], $this->oAdmin->getEntities('session'));
    }

    public function testGetEntitiesSessionCallsProviderGetSessions(): void
    {
        $this->oProvider->aStubSessions = [
            ['network' => 1, 'station' => 5, 'command' => 'bash', 'pid' => 1234, 'session' => 'sess1'],
        ];
        $aEntities = $this->oAdmin->getEntities('session');
        $this->assertCount(1, $aEntities);
        $this->assertInstanceOf(AdminEntity::class, $aEntities[0]);
    }

    public function testGetEntitiesSessionEntityHasCorrectValues(): void
    {
        $this->oProvider->aStubSessions = [
            ['network' => 2, 'station' => 10, 'command' => 'login', 'pid' => 999, 'session' => 'mysession'],
        ];
        $aEntities = $this->oAdmin->getEntities('session');
        $oEntity   = $aEntities[0];
        $this->assertSame('session', $oEntity->getType());
        $this->assertSame(2, $oEntity->getValue('network'));
        $this->assertSame(10, $oEntity->getValue('station'));
        $this->assertSame('login', $oEntity->getValue('command'));
        $this->assertSame(999, $oEntity->getValue('pid'));
    }

    public function testGetEntitiesSessionUsesSessionIdField(): void
    {
        $this->oProvider->aStubSessions = [
            ['network' => 1, 'station' => 3, 'command' => 'sh', 'pid' => 42, 'session' => 'the-id'],
        ];
        $aEntities = $this->oAdmin->getEntities('session');
        $this->assertSame('the-id', $aEntities[0]->getId());
    }

    public function testGetEntitiesSessionReturnsMultipleRows(): void
    {
        $this->oProvider->aStubSessions = [
            ['network' => 1, 'station' => 1, 'command' => 'sh', 'pid' => 1, 'session' => 's1'],
            ['network' => 1, 'station' => 2, 'command' => 'sh', 'pid' => 2, 'session' => 's2'],
        ];
        $this->assertCount(2, $this->oAdmin->getEntities('session'));
    }

    // -----------------------------------------------------------------------
    // getEntities — service
    // -----------------------------------------------------------------------

    public function testGetEntitiesServiceReturnsEmptyWhenNoServices(): void
    {
        $this->assertSame([], $this->oAdmin->getEntities('service'));
    }

    public function testGetEntitiesServiceCallsProviderGetServices(): void
    {
        $this->oProvider->aStubServices = [
            ['name' => 'telnet', 'command' => '/usr/bin/telnetd', 'service' => 'svc1'],
        ];
        $aEntities = $this->oAdmin->getEntities('service');
        $this->assertCount(1, $aEntities);
    }

    public function testGetEntitiesServiceEntityHasCorrectValues(): void
    {
        $this->oProvider->aStubServices = [
            ['name' => 'ftp', 'command' => '/usr/bin/ftpd', 'service' => 'svc2'],
        ];
        $oEntity = $this->oAdmin->getEntities('service')[0];
        $this->assertSame('service', $oEntity->getType());
        $this->assertSame('ftp', $oEntity->getValue('name'));
        $this->assertSame('/usr/bin/ftpd', $oEntity->getValue('command'));
    }

    // -----------------------------------------------------------------------
    // getEntities — unknown type
    // -----------------------------------------------------------------------

    public function testGetEntitiesForUnknownTypeReturnsEmptyArray(): void
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
