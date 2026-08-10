<?php

/*
 * @group unit-tests
 *
 * Tests for HomeLan\FileStore\Encapsulation\EncapsulationTypeMap::getType().
 *
 * Each test resets static state on all three maps (WebSocket, Piconet,
 * RemoteBridge) in setUp/tearDown so tests are fully isolated.
 */

include_once(__DIR__ . '/../../src/include/system.inc.php');

use PHPUnit\Framework\TestCase;
use Monolog\Logger;
use Monolog\Handler\NullHandler;
use HomeLan\FileStore\Encapsulation\EncapsulationTypeMap;
use HomeLan\FileStore\Messages\EconetPacket;
use HomeLan\FileStore\WebSocket\Map as WebSocketMap;
use HomeLan\FileStore\Piconet\Map as PiconetMap;
use HomeLan\FileStore\RemoteBridge\Map as RemoteBridgeMap;
use Ratchet\ConnectionInterface;

class EncapsulationTypeMapTest extends TestCase
{
    private EncapsulationTypeMap $oTypeMap;
    private Logger $oLogger;

    protected function setUp(): void
    {
        $this->oLogger = new Logger('test');
        $this->oLogger->pushHandler(new NullHandler());

        // Reset WebSocketMap
        foreach (['aDynamicNetworks', 'aSocketList'] as $sProp) {
            $rp = new \ReflectionProperty(WebSocketMap::class, $sProp);
            $rp->setAccessible(true);
            $rp->setValue(null, []);
        }

        // Reset PiconetMap
        $rp = new \ReflectionProperty(PiconetMap::class, 'aNetworks');
        $rp->setAccessible(true);
        $rp->setValue(null, []);

        // Reset RemoteBridgeMap
        RemoteBridgeMap::reset();

        // Reset remote_bridge_enabled config override if any
        config::resetValue('remote_bridge_enabled');

        $this->oTypeMap = new EncapsulationTypeMap();
    }

    protected function tearDown(): void
    {
        // Reset WebSocketMap
        foreach (['aDynamicNetworks', 'aSocketList'] as $sProp) {
            $rp = new \ReflectionProperty(WebSocketMap::class, $sProp);
            $rp->setAccessible(true);
            $rp->setValue(null, []);
        }

        // Reset PiconetMap
        $rp = new \ReflectionProperty(PiconetMap::class, 'aNetworks');
        $rp->setAccessible(true);
        $rp->setValue(null, []);

        RemoteBridgeMap::reset();
        config::resetValue('remote_bridge_enabled');
    }

    // -----------------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------------

    private function makePacket(int $iDstNet, int $iDstStn): EconetPacket
    {
        $oPkt = new EconetPacket();
        $oPkt->setFlags(0x02);
        $oPkt->setPort(0x99);
        $oPkt->setSourceNetwork(1);
        $oPkt->setSourceStation(1);
        $oPkt->setDestinationNetwork($iDstNet);
        $oPkt->setDestinationStation($iDstStn);
        $oPkt->setData('test');
        return $oPkt;
    }

    // -----------------------------------------------------------------------
    // AUN (default when no map has the destination)
    // -----------------------------------------------------------------------

    public function testGetTypeReturnsAunWhenNoMapKnowsTheDestination(): void
    {
        $oPkt = $this->makePacket(5, 10);
        $this->assertSame('AUN', $this->oTypeMap->getType($oPkt));
    }

    // -----------------------------------------------------------------------
    // WebSocket
    // -----------------------------------------------------------------------

    public function testGetTypeReturnsWebSocketWhenSocketAllocated(): void
    {
        WebSocketMap::addDynamicRangeNetwork(128);
        $oConn = $this->createStub(ConnectionInterface::class);
        $sAddr = WebSocketMap::allocateAddress($oConn);
        [$iNet, $iStn] = array_map('intval', explode('.', $sAddr));

        $oPkt = $this->makePacket($iNet, $iStn);
        $this->assertSame('WebSocket', $this->oTypeMap->getType($oPkt));
    }

    public function testGetTypeReturnsAunAfterWebSocketAddressFreed(): void
    {
        WebSocketMap::addDynamicRangeNetwork(128);
        $oConn = $this->createStub(ConnectionInterface::class);
        $sAddr = WebSocketMap::allocateAddress($oConn);
        [$iNet, $iStn] = array_map('intval', explode('.', $sAddr));
        WebSocketMap::freeAddress($oConn);

        $oPkt = $this->makePacket($iNet, $iStn);
        $this->assertSame('AUN', $this->oTypeMap->getType($oPkt));
    }

    // -----------------------------------------------------------------------
    // Piconet
    // -----------------------------------------------------------------------

    public function testGetTypeReturnsPiconetWhenNetworkRegistered(): void
    {
        PiconetMap::addNetwork(5);
        $oPkt = $this->makePacket(5, 10);
        $this->assertSame('Piconet', $this->oTypeMap->getType($oPkt));
    }

    public function testGetTypeReturnsAunForStationOnUnregisteredPiconetNetwork(): void
    {
        PiconetMap::addNetwork(5);
        $oPkt = $this->makePacket(6, 10); // network 6 not added
        $this->assertSame('AUN', $this->oTypeMap->getType($oPkt));
    }

    // -----------------------------------------------------------------------
    // RemoteBridge
    // -----------------------------------------------------------------------

    public function testGetTypeReturnsRemoteBridgeWhenEnabledAndNetworkKnown(): void
    {
        config::overrideValue('remote_bridge_enabled', 1);

        $oConn = $this->createMock(\HomeLan\FileStore\RemoteBridge\Connection::class);
        RemoteBridgeMap::registerPeerNetworks($oConn, [7]);

        $oPkt = $this->makePacket(7, 10);
        $this->assertSame('RemoteBridge', $this->oTypeMap->getType($oPkt));
    }

    public function testGetTypeReturnsAunWhenRemoteBridgeDisabledEvenIfNetworkKnown(): void
    {
        config::overrideValue('remote_bridge_enabled', 0);

        $oConn = $this->createMock(\HomeLan\FileStore\RemoteBridge\Connection::class);
        RemoteBridgeMap::registerPeerNetworks($oConn, [7]);

        $oPkt = $this->makePacket(7, 10);
        $this->assertSame('AUN', $this->oTypeMap->getType($oPkt));
    }

    // -----------------------------------------------------------------------
    // Priority: WebSocket > Piconet > RemoteBridge > AUN
    // -----------------------------------------------------------------------

    public function testWebSocketTakesPriorityOverPiconet(): void
    {
        WebSocketMap::addDynamicRangeNetwork(128);
        $oConn = $this->createStub(ConnectionInterface::class);
        $sAddr = WebSocketMap::allocateAddress($oConn);
        [$iNet, $iStn] = array_map('intval', explode('.', $sAddr));

        PiconetMap::addNetwork($iNet); // same network in piconet too

        $oPkt = $this->makePacket($iNet, $iStn);
        $this->assertSame('WebSocket', $this->oTypeMap->getType($oPkt));
    }
}
