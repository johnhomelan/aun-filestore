<?php

/*
 * @group unit-tests
 *
 * Tests for HomeLan\FileStore\Encapsulation\PacketDispatcher.
 *
 * Each test injects a mock EncapsulationTypeMap so that the exact dispatch
 * branch can be exercised, then sets up the appropriate static map state so
 * the underlying lookup (WebSocketMap, PiconetMap, AunMap) can succeed.
 */

include_once(__DIR__ . '/../../src/include/system.inc.php');

use PHPUnit\Framework\TestCase;
use Monolog\Logger;
use Monolog\Handler\NullHandler;
use HomeLan\FileStore\Encapsulation\PacketDispatcher;
use HomeLan\FileStore\Encapsulation\EncapsulationTypeMap;
use HomeLan\FileStore\Messages\EconetPacket;
use HomeLan\FileStore\WebSocket\Map as WebSocketMap;
use HomeLan\FileStore\Piconet\Map as PiconetMap;
use HomeLan\FileStore\Aun\Map as AunMap;
use HomeLan\FileStore\Aun\HandleInterface;
use HomeLan\FileStore\RemoteBridge\Map as RemoteBridgeMap;
use Ratchet\ConnectionInterface;
use React\EventLoop\LoopInterface;

class PacketDispatcherTest extends TestCase
{
    private Logger $oLogger;

    protected function setUp(): void
    {
        $this->oLogger = new Logger('test');
        $this->oLogger->pushHandler(new NullHandler());

        // Reset static maps
        foreach (['aDynamicNetworks', 'aSocketList'] as $sProp) {
            $rp = new \ReflectionProperty(WebSocketMap::class, $sProp);
            $rp->setAccessible(true);
            $rp->setValue(null, []);
        }
        foreach (['aHostMap', 'aSubnetMap', 'aIPLookupCache', 'aIpCounter'] as $sProp) {
            $rp = new \ReflectionProperty(AunMap::class, $sProp);
            $rp->setAccessible(true);
            $rp->setValue(null, []);
        }
        $rp = new \ReflectionProperty(PiconetMap::class, 'aNetworks');
        $rp->setAccessible(true);
        $rp->setValue(null, []);

        RemoteBridgeMap::reset();
    }

    protected function tearDown(): void
    {
        foreach (['aDynamicNetworks', 'aSocketList'] as $sProp) {
            $rp = new \ReflectionProperty(WebSocketMap::class, $sProp);
            $rp->setAccessible(true);
            $rp->setValue(null, []);
        }
        foreach (['aHostMap', 'aSubnetMap', 'aIPLookupCache', 'aIpCounter'] as $sProp) {
            $rp = new \ReflectionProperty(AunMap::class, $sProp);
            $rp->setAccessible(true);
            $rp->setValue(null, []);
        }
        $rp = new \ReflectionProperty(PiconetMap::class, 'aNetworks');
        $rp->setAccessible(true);
        $rp->setValue(null, []);

        RemoteBridgeMap::reset();
    }

    // -----------------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------------

    private function makeDispatcher(string $sType): array
    {
        $oTypeMap = $this->createMock(EncapsulationTypeMap::class);
        $oTypeMap->method('getType')->willReturn($sType);

        $oLoop = $this->createMock(LoopInterface::class);

        $oDispatcher = new PacketDispatcher($oTypeMap, $oLoop);
        return [$oDispatcher, $oLoop];
    }

    private function makePacket(int $iDstNet, int $iDstStn): EconetPacket
    {
        $oPkt = new EconetPacket();
        $oPkt->setFlags(0x02);
        $oPkt->setPort(0x01);
        $oPkt->setSourceNetwork(1);
        $oPkt->setSourceStation(1);
        $oPkt->setDestinationNetwork($iDstNet);
        $oPkt->setDestinationStation($iDstStn);
        $oPkt->setData('hello');
        return $oPkt;
    }

    // -----------------------------------------------------------------------
    // getLoop()
    // -----------------------------------------------------------------------

    public function testGetLoopReturnsInjectedEventLoop(): void
    {
        [, $oLoop] = $this->makeDispatcher('AUN');
        [$oDispatcher] = $this->makeDispatcher('AUN');

        // Build a dedicated dispatcher to assert getLoop() returns the exact instance
        $oTypeMap = $this->createMock(EncapsulationTypeMap::class);
        $oLoop    = $this->createMock(LoopInterface::class);
        $oDisp    = new PacketDispatcher($oTypeMap, $oLoop);

        $this->assertSame($oLoop, $oDisp->getLoop());
    }

    // -----------------------------------------------------------------------
    // sendPacket — WebSocket path
    // -----------------------------------------------------------------------

    public function testSendPacketCallsWebSocketConnectionSend(): void
    {
        WebSocketMap::addDynamicRangeNetwork(128);

        $oConn = $this->createMock(ConnectionInterface::class);
        $oConn->expects($this->once())->method('send');

        $sAddr = WebSocketMap::allocateAddress($oConn);
        [$iNet, $iStn] = array_map('intval', explode('.', $sAddr));

        [$oDispatcher] = $this->makeDispatcher('WebSocket');
        $oDispatcher->sendPacket($this->makePacket($iNet, $iStn));
    }

    // -----------------------------------------------------------------------
    // sendPacket — Piconet path
    // -----------------------------------------------------------------------

    public function testSendPacketCallsPiconetHandlerSend(): void
    {
        $oHandler = $this->createMock(\HomeLan\FileStore\Piconet\Handler::class);
        $oHandler->expects($this->once())->method('send');

        PiconetMap::init($this->oLogger, $oHandler, "5\n");

        [$oDispatcher] = $this->makeDispatcher('Piconet');
        $oDispatcher->sendPacket($this->makePacket(5, 10));
    }

    public function testSendPacketWithNullPiconetHandlerDoesNotThrow(): void
    {
        // Network 5 not added to PiconetMap — ecoAddrToHandler returns null
        [$oDispatcher] = $this->makeDispatcher('Piconet');

        $this->expectNotToPerformAssertions();
        $oDispatcher->sendPacket($this->makePacket(5, 10));
    }

    // -----------------------------------------------------------------------
    // sendPacket — RemoteBridge path
    // -----------------------------------------------------------------------

    public function testSendPacketCallsRemoteBridgeConnectionSend(): void
    {
        $oConn = $this->createMock(\HomeLan\FileStore\RemoteBridge\Connection::class);
        $oConn->expects($this->once())->method('send');
        RemoteBridgeMap::registerPeerNetworks($oConn, [9]);

        [$oDispatcher] = $this->makeDispatcher('RemoteBridge');
        $oDispatcher->sendPacket($this->makePacket(9, 10));
    }

    public function testSendPacketBuffersInsteadOfDroppingWhenConnectionDown(): void
    {
        // Network 9 was served by a connection that has since been unregistered (closed),
        // but is still within its grace window so getType() still says 'RemoteBridge'.
        $oOldConn = $this->createMock(\HomeLan\FileStore\RemoteBridge\Connection::class);
        RemoteBridgeMap::registerPeerNetworks($oOldConn, [9]);
        RemoteBridgeMap::unregisterConnection($oOldConn);

        [$oDispatcher] = $this->makeDispatcher('RemoteBridge');
        $oDispatcher->sendPacket($this->makePacket(9, 10));

        // A fresh connection re-announces network 9 — the buffered packet must be flushed to it.
        $oNewConn = $this->createMock(\HomeLan\FileStore\RemoteBridge\Connection::class);
        $oNewConn->expects($this->once())->method('send');
        RemoteBridgeMap::registerPeerNetworks($oNewConn, [9]);
    }

    // -----------------------------------------------------------------------
    // sendPacket — AUN path
    // -----------------------------------------------------------------------

    public function testSendPacketCallsAunHandlerSendWhenFrameNonEmpty(): void
    {
        $oAunHandler = $this->createMock(HandleInterface::class);
        $oAunHandler->expects($this->once())->method('send');

        // Map econet address 1.100 → 192.168.0.100 so getAunFrame() returns non-empty
        AunMap::init($this->oLogger, $oAunHandler, "192.168.0.100 1.100\n");

        [$oDispatcher] = $this->makeDispatcher('AUN');
        $oDispatcher->sendPacket($this->makePacket(1, 100));
    }

    public function testSendPacketSkipsAunSendWhenFrameIsEmpty(): void
    {
        $oAunHandler = $this->createMock(HandleInterface::class);
        $oAunHandler->expects($this->never())->method('send');

        // No AunMap entry for net=2, stn=50 — ecoAddrToIpAddr returns '' → getAunFrame() returns ''
        AunMap::init($this->oLogger, $oAunHandler, '');

        [$oDispatcher] = $this->makeDispatcher('AUN');
        $oDispatcher->sendPacket($this->makePacket(2, 50));
    }
}
