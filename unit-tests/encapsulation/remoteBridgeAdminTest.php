<?php

/*
 * @group unit-tests
 *
 * Tests for HomeLan\FileStore\RemoteBridge\Admin.
 *
 * Uses RemoteBridge\Map (reset via Map::reset() between tests) to feed data.
 */

include_once(__DIR__ . '/../../src/include/system.inc.php');

use PHPUnit\Framework\TestCase;
use Monolog\Logger;
use Monolog\Handler\NullHandler;
use HomeLan\FileStore\RemoteBridge\Admin as RemoteBridgeAdmin;
use HomeLan\FileStore\RemoteBridge\Map;
use HomeLan\FileStore\RemoteBridge\Connection;
use HomeLan\FileStore\Encapsulation\EncapsulationAdminInterface;
use HomeLan\FileStore\Services\Provider\AdminEntity;

class RemoteBridgeAdminTest extends TestCase
{
    private RemoteBridgeAdmin $oAdmin;
    private Logger $oLogger;

    protected function setUp(): void
    {
        $this->oLogger = new Logger('test');
        $this->oLogger->pushHandler(new NullHandler());

        Map::reset();
        $this->oAdmin = new RemoteBridgeAdmin();
    }

    protected function tearDown(): void
    {
        Map::reset();
    }

    private function initMap(string $sContent): void
    {
        Map::init($this->oLogger, $sContent);
    }

    // -----------------------------------------------------------------------
    // Interface
    // -----------------------------------------------------------------------

    public function testImplementsEncapsulationAdminInterface(): void
    {
        $this->assertInstanceOf(EncapsulationAdminInterface::class, $this->oAdmin);
    }

    // -----------------------------------------------------------------------
    // getId / getName / getDescription
    // -----------------------------------------------------------------------

    public function testGetIdReturnsRemotebridge(): void
    {
        $this->assertSame('remotebridge', $this->oAdmin->getId());
    }

    public function testGetNameIsNonEmpty(): void
    {
        $this->assertNotEmpty($this->oAdmin->getName());
    }

    public function testGetDescriptionIsNonEmpty(): void
    {
        $this->assertNotEmpty($this->oAdmin->getDescription());
    }

    // -----------------------------------------------------------------------
    // getStatus
    // -----------------------------------------------------------------------

    public function testGetStatusWithNoLiveConnectionsShowsZero(): void
    {
        $sStatus = $this->oAdmin->getStatus();
        $this->assertStringContainsString('0', $sStatus);
        $this->assertStringContainsString('network', $sStatus);
    }

    public function testGetStatusReflectsLiveConnectionCount(): void
    {
        $oConn = $this->createStub(Connection::class);
        Map::registerPeerNetworks($oConn, [5, 6]);
        $sStatus = $this->oAdmin->getStatus();
        $this->assertStringContainsString('2', $sStatus);
    }

    // -----------------------------------------------------------------------
    // getEntityTypes
    // -----------------------------------------------------------------------

    public function testGetEntityTypesHasConnectionServerClientKeys(): void
    {
        $aTypes = $this->oAdmin->getEntityTypes();
        $this->assertArrayHasKey('connection', $aTypes);
        $this->assertArrayHasKey('server', $aTypes);
        $this->assertArrayHasKey('client', $aTypes);
    }

    // -----------------------------------------------------------------------
    // getEntityFields
    // -----------------------------------------------------------------------

    public function testGetEntityFieldsConnectionHasNetworkKey(): void
    {
        $aFields = $this->oAdmin->getEntityFields('connection');
        $this->assertArrayHasKey('network', $aFields);
    }

    public function testGetEntityFieldsServerHasPortAndNetworks(): void
    {
        $aFields = $this->oAdmin->getEntityFields('server');
        $this->assertArrayHasKey('port', $aFields);
        $this->assertArrayHasKey('networks', $aFields);
    }

    public function testGetEntityFieldsClientHasHostPortAndNetworks(): void
    {
        $aFields = $this->oAdmin->getEntityFields('client');
        $this->assertArrayHasKey('host', $aFields);
        $this->assertArrayHasKey('port', $aFields);
        $this->assertArrayHasKey('networks', $aFields);
    }

    public function testGetEntityFieldsClientDoesNotExposeSecret(): void
    {
        $this->assertArrayNotHasKey('secret', $this->oAdmin->getEntityFields('client'));
    }

    public function testGetEntityFieldsServerDoesNotExposeSecret(): void
    {
        $this->assertArrayNotHasKey('secret', $this->oAdmin->getEntityFields('server'));
    }

    public function testGetEntityFieldsUnknownReturnsEmpty(): void
    {
        $this->assertSame([], $this->oAdmin->getEntityFields('unknown'));
    }

    // -----------------------------------------------------------------------
    // getEntities — connection
    // -----------------------------------------------------------------------

    public function testGetEntitiesConnectionReturnsEmptyWhenNoLiveConnections(): void
    {
        $this->assertSame([], $this->oAdmin->getEntities('connection'));
    }

    public function testGetEntitiesConnectionReturnsOneEntityPerKnownNetwork(): void
    {
        $oConn = $this->createStub(Connection::class);
        Map::registerPeerNetworks($oConn, [5, 6, 7]);
        $aEntities = $this->oAdmin->getEntities('connection');
        $this->assertCount(3, $aEntities);
        $this->assertInstanceOf(AdminEntity::class, $aEntities[0]);
    }

    public function testGetEntitiesConnectionEntityHasCorrectNetworkValue(): void
    {
        $oConn = $this->createStub(Connection::class);
        Map::registerPeerNetworks($oConn, [9]);
        $oEntity = $this->oAdmin->getEntities('connection')[0];
        $this->assertSame(9, $oEntity->getValue('network'));
    }

    public function testGetEntitiesConnectionUsesNetworkAsIdField(): void
    {
        $oConn = $this->createStub(Connection::class);
        Map::registerPeerNetworks($oConn, [12]);
        $oEntity = $this->oAdmin->getEntities('connection')[0];
        $this->assertSame(12, $oEntity->getId());
    }

    // -----------------------------------------------------------------------
    // getEntities — server
    // -----------------------------------------------------------------------

    public function testGetEntitiesServerReturnsEmptyWhenNoServerEntriesConfigured(): void
    {
        $this->assertSame([], $this->oAdmin->getEntities('server'));
    }

    public function testGetEntitiesServerReturnsOneEntityPerServerEntry(): void
    {
        $this->initMap("SERVER 9000 secretkey 1,2,3\n");
        $aEntities = $this->oAdmin->getEntities('server');
        $this->assertCount(1, $aEntities);
        $this->assertInstanceOf(AdminEntity::class, $aEntities[0]);
    }

    public function testGetEntitiesServerEntityHasCorrectPort(): void
    {
        $this->initMap("SERVER 9000 secretkey 1,2\n");
        $oEntity = $this->oAdmin->getEntities('server')[0];
        $this->assertSame(9000, $oEntity->getValue('port'));
    }

    public function testGetEntitiesServerEntityNetworksIsCommaSeparatedString(): void
    {
        $this->initMap("SERVER 9001 secret 4,5,6\n");
        $oEntity = $this->oAdmin->getEntities('server')[0];
        $sNetworks = $oEntity->getValue('networks');
        $this->assertStringContainsString('4', $sNetworks);
        $this->assertStringContainsString('5', $sNetworks);
        $this->assertStringContainsString('6', $sNetworks);
    }

    public function testGetEntitiesServerEntityDoesNotExposeSecret(): void
    {
        $this->initMap("SERVER 9000 mysecret 1\n");
        $oEntity = $this->oAdmin->getEntities('server')[0];
        $this->assertNull($oEntity->getValue('secret'));
    }

    public function testGetEntitiesServerUsesPortAsCallableId(): void
    {
        $this->initMap("SERVER 9000 secret 1\n");
        $oEntity = $this->oAdmin->getEntities('server')[0];
        $this->assertSame('9000', $oEntity->getId());
    }

    // -----------------------------------------------------------------------
    // getEntities — client
    // -----------------------------------------------------------------------

    public function testGetEntitiesClientReturnsEmptyWhenNoClientEntriesConfigured(): void
    {
        $this->assertSame([], $this->oAdmin->getEntities('client'));
    }

    public function testGetEntitiesClientReturnsOneEntityPerClientEntry(): void
    {
        $this->initMap("CLIENT 10.0.0.1:9000 secretkey 1,2\n");
        $aEntities = $this->oAdmin->getEntities('client');
        $this->assertCount(1, $aEntities);
        $this->assertInstanceOf(AdminEntity::class, $aEntities[0]);
    }

    public function testGetEntitiesClientEntityHasCorrectHostAndPort(): void
    {
        $this->initMap("CLIENT 10.0.0.2:8888 secret 3\n");
        $oEntity = $this->oAdmin->getEntities('client')[0];
        $this->assertSame('10.0.0.2', $oEntity->getValue('host'));
        $this->assertSame(8888, $oEntity->getValue('port'));
    }

    public function testGetEntitiesClientEntityNetworksIsCommaSeparatedString(): void
    {
        $this->initMap("CLIENT 10.0.0.1:9000 secret 7,8\n");
        $oEntity = $this->oAdmin->getEntities('client')[0];
        $sNetworks = $oEntity->getValue('networks');
        $this->assertStringContainsString('7', $sNetworks);
        $this->assertStringContainsString('8', $sNetworks);
    }

    public function testGetEntitiesClientEntityDoesNotExposeSecret(): void
    {
        $this->initMap("CLIENT 10.0.0.1:9000 mysecret 1\n");
        $oEntity = $this->oAdmin->getEntities('client')[0];
        $this->assertNull($oEntity->getValue('secret'));
    }

    public function testGetEntitiesClientUsesHostColonPortAsCallableId(): void
    {
        $this->initMap("CLIENT 10.0.0.1:9000 secret 1\n");
        $oEntity = $this->oAdmin->getEntities('client')[0];
        $this->assertSame('10.0.0.1:9000', $oEntity->getId());
    }

    public function testGetEntitiesServerAndClientCanCoexist(): void
    {
        $this->initMap("SERVER 9000 s1 1,2\nCLIENT 10.0.0.1:8000 s2 3,4\n");
        $this->assertCount(1, $this->oAdmin->getEntities('server'));
        $this->assertCount(1, $this->oAdmin->getEntities('client'));
    }

    // -----------------------------------------------------------------------
    // getEntities — unknown type
    // -----------------------------------------------------------------------

    public function testGetEntitiesUnknownTypeReturnsEmpty(): void
    {
        $this->assertSame([], $this->oAdmin->getEntities('unknown'));
    }
}
