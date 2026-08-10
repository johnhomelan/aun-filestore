<?php

/*
 * @group unit-tests
 *
 * Tests for HomeLan\FileStore\WebSocket\Admin.
 *
 * Uses WebSocket\Map static state (reset between tests) to feed data.
 */

include_once(__DIR__ . '/../../src/include/system.inc.php');

use PHPUnit\Framework\TestCase;
use Monolog\Logger;
use Monolog\Handler\NullHandler;
use HomeLan\FileStore\WebSocket\Admin as WebSocketAdmin;
use HomeLan\FileStore\WebSocket\Map;
use HomeLan\FileStore\Encapsulation\EncapsulationAdminInterface;
use HomeLan\FileStore\Services\Provider\AdminEntity;
use Ratchet\ConnectionInterface;

class WebSocketAdminTest extends TestCase
{
    private WebSocketAdmin $oAdmin;

    protected function setUp(): void
    {
        $rp = new \ReflectionProperty(Map::class, 'aDynamicNetworks');
        $rp->setAccessible(true);
        $rp->setValue(null, []);

        $rp = new \ReflectionProperty(Map::class, 'aSocketList');
        $rp->setAccessible(true);
        $rp->setValue(null, []);

        $this->oAdmin = new WebSocketAdmin();
    }

    protected function tearDown(): void
    {
        $rp = new \ReflectionProperty(Map::class, 'aDynamicNetworks');
        $rp->setAccessible(true);
        $rp->setValue(null, []);

        $rp = new \ReflectionProperty(Map::class, 'aSocketList');
        $rp->setAccessible(true);
        $rp->setValue(null, []);
    }

    private function addFakeClient(int $iNetwork, int $iStation): void
    {
        $oConn = $this->createStub(ConnectionInterface::class);
        $rp    = new \ReflectionProperty(Map::class, 'aDynamicNetworks');
        $rp->setAccessible(true);
        $aNetworks = $rp->getValue(null);
        if (!isset($aNetworks[$iNetwork])) {
            $aNetworks[$iNetwork] = [];
        }
        $aNetworks[$iNetwork][$iStation] = $oConn;
        $rp->setValue(null, $aNetworks);

        $rp2 = new \ReflectionProperty(Map::class, 'aSocketList');
        $rp2->setAccessible(true);
        $aList = $rp2->getValue(null);
        $aList[spl_object_id($oConn)] = ['network' => $iNetwork, 'station' => $iStation, 'socket' => $oConn];
        $rp2->setValue(null, $aList);
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

    public function testGetIdReturnsWebsocket(): void
    {
        $this->assertSame('websocket', $this->oAdmin->getId());
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

    public function testGetStatusWithNoClientsAndNoRanges(): void
    {
        $sStatus = $this->oAdmin->getStatus();
        $this->assertStringContainsString('0', $sStatus);
    }

    public function testGetStatusReflectsConnectedClientCount(): void
    {
        $this->addFakeClient(128, 1);
        $this->addFakeClient(128, 2);
        $sStatus = $this->oAdmin->getStatus();
        $this->assertStringContainsString('2', $sStatus);
        $this->assertStringContainsString('client', $sStatus);
    }

    public function testGetStatusReflectsRangeCount(): void
    {
        Map::addDynamicRangeNetwork(128);
        $sStatus = $this->oAdmin->getStatus();
        $this->assertStringContainsString('1', $sStatus);
        $this->assertStringContainsString('range', $sStatus);
    }

    // -----------------------------------------------------------------------
    // getEntityTypes
    // -----------------------------------------------------------------------

    public function testGetEntityTypesHasConnectionAndRangeKeys(): void
    {
        $aTypes = $this->oAdmin->getEntityTypes();
        $this->assertArrayHasKey('connection', $aTypes);
        $this->assertArrayHasKey('range', $aTypes);
    }

    // -----------------------------------------------------------------------
    // getEntityFields
    // -----------------------------------------------------------------------

    public function testGetEntityFieldsConnectionHasNetworkAndStation(): void
    {
        $aFields = $this->oAdmin->getEntityFields('connection');
        $this->assertArrayHasKey('network', $aFields);
        $this->assertArrayHasKey('station', $aFields);
    }

    public function testGetEntityFieldsRangeHasNetwork(): void
    {
        $aFields = $this->oAdmin->getEntityFields('range');
        $this->assertArrayHasKey('network', $aFields);
    }

    public function testGetEntityFieldsUnknownReturnsEmpty(): void
    {
        $this->assertSame([], $this->oAdmin->getEntityFields('unknown'));
    }

    // -----------------------------------------------------------------------
    // getEntities — connection
    // -----------------------------------------------------------------------

    public function testGetEntitiesConnectionReturnsEmptyWhenNoClients(): void
    {
        $this->assertSame([], $this->oAdmin->getEntities('connection'));
    }

    public function testGetEntitiesConnectionReturnsOnePerConnectedClient(): void
    {
        $this->addFakeClient(128, 1);
        $this->addFakeClient(128, 2);
        $aEntities = $this->oAdmin->getEntities('connection');
        $this->assertCount(2, $aEntities);
        $this->assertInstanceOf(AdminEntity::class, $aEntities[0]);
    }

    public function testGetEntitiesConnectionEntityHasCorrectValues(): void
    {
        $this->addFakeClient(200, 5);
        $oEntity = $this->oAdmin->getEntities('connection')[0];
        $this->assertSame(200, $oEntity->getValue('network'));
        $this->assertSame(5, $oEntity->getValue('station'));
    }

    public function testGetEntitiesConnectionUsesCallableIdNetworkDotStation(): void
    {
        $this->addFakeClient(128, 10);
        $oEntity = $this->oAdmin->getEntities('connection')[0];
        $this->assertSame('128.10', $oEntity->getId());
    }

    // -----------------------------------------------------------------------
    // getEntities — range
    // -----------------------------------------------------------------------

    public function testGetEntitiesRangeReturnsEmptyWhenNoRangesConfigured(): void
    {
        $this->assertSame([], $this->oAdmin->getEntities('range'));
    }

    public function testGetEntitiesRangeReturnsOnePerDynamicRange(): void
    {
        Map::addDynamicRangeNetwork(128);
        Map::addDynamicRangeNetwork(129);
        $aEntities = $this->oAdmin->getEntities('range');
        $this->assertCount(2, $aEntities);
    }

    public function testGetEntitiesRangeEntityHasCorrectNetwork(): void
    {
        Map::addDynamicRangeNetwork(200);
        $oEntity = $this->oAdmin->getEntities('range')[0];
        $this->assertSame(200, $oEntity->getValue('network'));
    }

    public function testGetEntitiesRangeUsesNetworkAsIdField(): void
    {
        Map::addDynamicRangeNetwork(150);
        $oEntity = $this->oAdmin->getEntities('range')[0];
        $this->assertSame(150, $oEntity->getId());
    }

    // -----------------------------------------------------------------------
    // getEntities — unknown type
    // -----------------------------------------------------------------------

    public function testGetEntitiesUnknownTypeReturnsEmpty(): void
    {
        $this->assertSame([], $this->oAdmin->getEntities('unknown'));
    }

    // -----------------------------------------------------------------------
    // Map::getConnectedClients / getDynamicNetworkRanges directly
    // -----------------------------------------------------------------------

    public function testGetConnectedClientsReturnsEmptyByDefault(): void
    {
        $this->assertSame([], Map::getConnectedClients());
    }

    public function testGetDynamicNetworkRangesReturnsEmptyByDefault(): void
    {
        $this->assertSame([], Map::getDynamicNetworkRanges());
    }

    public function testGetConnectedClientsReturnsCorrectStructure(): void
    {
        $this->addFakeClient(128, 3);
        $aClients = Map::getConnectedClients();
        $this->assertCount(1, $aClients);
        $this->assertSame(128, $aClients[0]['network']);
        $this->assertSame(3, $aClients[0]['station']);
    }

    public function testGetDynamicNetworkRangesReturnsConfiguredNetworks(): void
    {
        Map::addDynamicRangeNetwork(130);
        $aRanges = Map::getDynamicNetworkRanges();
        $this->assertCount(1, $aRanges);
        $this->assertSame(130, $aRanges[0]['network']);
    }
}
