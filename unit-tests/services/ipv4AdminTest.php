<?php

/*
 * @group unit-tests
 *
 * Tests for HomeLan\FileStore\Services\Provider\IPv4\Admin.
 *
 * FakeIPv4Admin extends IPv4, overrides factory methods to produce empty tables,
 * and overrides admin data methods to return controlled stub data.
 */

include_once(__DIR__ . '/../../src/include/system.inc.php');

use PHPUnit\Framework\TestCase;
use Monolog\Logger;
use Monolog\Handler\NullHandler;
use HomeLan\FileStore\Services\Provider\IPv4;
use HomeLan\FileStore\Services\Provider\IPv4\Admin as IPv4Admin;
use HomeLan\FileStore\Services\Provider\IPv4\Interfaces;
use HomeLan\FileStore\Services\Provider\IPv4\Routes;
use HomeLan\FileStore\Services\Provider\IPv4\NAT;
use HomeLan\FileStore\Services\Provider\AdminEntity;
use HomeLan\FileStore\Services\ServiceDispatcher;

// ---------------------------------------------------------------------------
// Fake provider — empty tables via factory-method override, stub data via
// admin accessor overrides.
// ---------------------------------------------------------------------------
class FakeIPv4Admin extends IPv4
{
    public array $aStubArpEntries  = [];
    public array $aStubInterfaces  = [];
    public array $aStubRoutes      = [];
    public array $aStubNatEntries  = [];
    public array $aStubConnTrack   = [];
    public array $aStubRelayRegistrations = [];

    protected function createInterfaces(?string $sConfig = null): Interfaces
    {
        return new Interfaces($this, $this->oLogger, '');
    }

    protected function createRoutes(?string $sConfig = null): Routes
    {
        return new Routes($this, $this->oLogger, '');
    }

    protected function createNat(?string $sConfig = null): NAT
    {
        return new NAT($this, $this->oLogger, '');
    }

    public function getArpEntries(): array  { return $this->aStubArpEntries; }
    public function getInterfaces(): array  { return $this->aStubInterfaces; }
    public function getRoutes(): array      { return $this->aStubRoutes; }
    public function getNatEntries(): array  { return $this->aStubNatEntries; }
    public function getConnTrack(): array   { return $this->aStubConnTrack; }
    public function getRelayRegistrations(): array { return $this->aStubRelayRegistrations; }
}

// ---------------------------------------------------------------------------
// Test class
// ---------------------------------------------------------------------------
class IPv4AdminTest extends TestCase
{
    private FakeIPv4Admin $oProvider;
    private IPv4Admin $oAdmin;

    protected function setUp(): void
    {
        $oLogger = new Logger('test');
        $oLogger->pushHandler(new NullHandler());

        $this->oProvider = new FakeIPv4Admin($oLogger);
        $this->oAdmin    = new IPv4Admin($this->oProvider);

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

    public function testGetNameReturnsIPv4(): void
    {
        $this->assertSame('IPv4', $this->oAdmin->getName());
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

    public function testGetEntityTypesHasAllExpectedKeys(): void
    {
        $aTypes = $this->oAdmin->getEntityTypes();
        foreach (['arp', 'interfaces', 'routes', 'nat', 'conntrack', 'remotesocket'] as $sKey) {
            $this->assertArrayHasKey($sKey, $aTypes);
        }
    }

    // -----------------------------------------------------------------------
    // getEntityFields
    // -----------------------------------------------------------------------

    public function testGetEntityFieldsArpHasExpectedKeys(): void
    {
        $aFields = $this->oAdmin->getEntityFields('arp');
        $this->assertArrayHasKey('network', $aFields);
        $this->assertArrayHasKey('station', $aFields);
        $this->assertArrayHasKey('ipv4', $aFields);
        $this->assertArrayHasKey('timeout', $aFields);
    }

    public function testGetEntityFieldsInterfacesHasExpectedKeys(): void
    {
        $aFields = $this->oAdmin->getEntityFields('interfaces');
        $this->assertArrayHasKey('ipaddr', $aFields);
        $this->assertArrayHasKey('mask', $aFields);
    }

    public function testGetEntityFieldsRoutesHasExpectedKeys(): void
    {
        $aFields = $this->oAdmin->getEntityFields('routes');
        $this->assertArrayHasKey('network', $aFields);
        $this->assertArrayHasKey('subnet', $aFields);
        $this->assertArrayHasKey('gw', $aFields);
    }

    public function testGetEntityFieldsNatHasExpectedKeys(): void
    {
        $aFields = $this->oAdmin->getEntityFields('nat');
        $this->assertArrayHasKey('ip_from', $aFields);
        $this->assertArrayHasKey('ip_to', $aFields);
        $this->assertArrayHasKey('port_from', $aFields);
        $this->assertArrayHasKey('port_to', $aFields);
    }

    public function testGetEntityFieldsConntrackHasExpectedKeys(): void
    {
        $aFields = $this->oAdmin->getEntityFields('conntrack');
        $this->assertArrayHasKey('srcip', $aFields);
        $this->assertArrayHasKey('dstip', $aFields);
        $this->assertArrayHasKey('state', $aFields);
    }

    public function testGetEntityFieldsRemoteSocketHasExpectedKeys(): void
    {
        $aFields = $this->oAdmin->getEntityFields('remotesocket');
        $this->assertArrayHasKey('protocol', $aFields);
        $this->assertArrayHasKey('port', $aFields);
    }

    public function testGetEntityFieldsUnknownReturnsEmpty(): void
    {
        $this->assertSame([], $this->oAdmin->getEntityFields('unknown'));
    }

    // -----------------------------------------------------------------------
    // getEntities — arp
    // -----------------------------------------------------------------------

    public function testGetEntitiesArpReturnsEmptyByDefault(): void
    {
        $this->assertSame([], $this->oAdmin->getEntities('arp'));
    }

    public function testGetEntitiesArpCallsProviderGetArpEntries(): void
    {
        $this->oProvider->aStubArpEntries = [
            ['network' => 1, 'station' => 5, 'ipv4' => '192.168.0.5', 'timeout' => 60],
        ];
        $aEntities = $this->oAdmin->getEntities('arp');
        $this->assertCount(1, $aEntities);
        $this->assertInstanceOf(AdminEntity::class, $aEntities[0]);
    }

    public function testGetEntitiesArpEntityHasCorrectValuesAndId(): void
    {
        $this->oProvider->aStubArpEntries = [
            ['network' => 1, 'station' => 2, 'ipv4' => '10.0.0.2', 'timeout' => 30],
        ];
        $oEntity = $this->oAdmin->getEntities('arp')[0];
        $this->assertSame('10.0.0.2', $oEntity->getValue('ipv4'));
        $this->assertSame('10.0.0.2', $oEntity->getId()); // id field is 'ipv4'
    }

    // -----------------------------------------------------------------------
    // getEntities — interfaces
    // -----------------------------------------------------------------------

    public function testGetEntitiesInterfacesCallsProviderGetInterfaces(): void
    {
        $this->oProvider->aStubInterfaces = [
            ['network' => 1, 'station' => 1, 'ipaddr' => '192.168.1.1', 'mask' => '255.255.255.0'],
        ];
        $aEntities = $this->oAdmin->getEntities('interfaces');
        $this->assertCount(1, $aEntities);
        $oEntity = $aEntities[0];
        $this->assertSame('192.168.1.1', $oEntity->getValue('ipaddr'));
        $this->assertSame('192.168.1.1', $oEntity->getId()); // id field is 'ipaddr'
    }

    // -----------------------------------------------------------------------
    // getEntities — routes
    // -----------------------------------------------------------------------

    public function testGetEntitiesRoutesCallsProviderGetRoutes(): void
    {
        $this->oProvider->aStubRoutes = [
            ['network' => '10.0.0.0', 'subnet' => '255.0.0.0', 'gw' => '192.168.0.1', 'metric' => 1],
        ];
        $aEntities = $this->oAdmin->getEntities('routes');
        $this->assertCount(1, $aEntities);
        $oEntity = $aEntities[0];
        $this->assertSame('10.0.0.0', $oEntity->getValue('network'));
        $this->assertSame('10.0.0.0', $oEntity->getId()); // id field is 'network'
    }

    // -----------------------------------------------------------------------
    // getEntities — nat
    // -----------------------------------------------------------------------

    public function testGetEntitiesNatCallsProviderGetNatEntries(): void
    {
        $this->oProvider->aStubNatEntries = [
            ['ip_from' => '192.168.0.10', 'ip_to' => '10.0.0.10', 'port_from' => 80, 'port_to' => 8080, 'nat' => 'rule1'],
        ];
        $aEntities = $this->oAdmin->getEntities('nat');
        $this->assertCount(1, $aEntities);
        $oEntity = $aEntities[0];
        $this->assertSame('192.168.0.10', $oEntity->getValue('ip_from'));
        $this->assertSame('rule1', $oEntity->getId()); // id field is 'nat'
    }

    // -----------------------------------------------------------------------
    // getEntities — conntrack
    // -----------------------------------------------------------------------

    public function testGetEntitiesConntrackCallsProviderGetConnTrack(): void
    {
        $this->oProvider->aStubConnTrack = [
            ['srcip' => '192.168.0.1', 'dstip' => '8.8.8.8', 'srcport' => 12345, 'dstport' => 53, 'state' => 'ESTABLISHED', 'last_activity' => 100, 'conntrack' => 'ct1'],
        ];
        $aEntities = $this->oAdmin->getEntities('conntrack');
        $this->assertCount(1, $aEntities);
        $oEntity = $aEntities[0];
        $this->assertSame('8.8.8.8', $oEntity->getValue('dstip'));
        $this->assertSame('ct1', $oEntity->getId()); // id field is 'conntrack'
    }

    // -----------------------------------------------------------------------
    // getEntities — remotesocket
    // -----------------------------------------------------------------------

    public function testGetEntitiesRemoteSocketReturnsEmptyByDefault(): void
    {
        $this->assertSame([], $this->oAdmin->getEntities('remotesocket'));
    }

    public function testGetEntitiesRemoteSocketCallsProviderGetRelayRegistrations(): void
    {
        $this->oProvider->aStubRelayRegistrations = [
            ['protocol' => 'udp', 'port' => '32770'],
        ];
        $aEntities = $this->oAdmin->getEntities('remotesocket');
        $this->assertCount(1, $aEntities);
        $oEntity = $aEntities[0];
        $this->assertSame('udp', $oEntity->getValue('protocol'));
        $this->assertSame('32770', $oEntity->getId()); // id field is 'port'
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
