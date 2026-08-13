<?php

/*
 * @group unit-tests
 *
 * End-to-end test proving a real WebSocket ack — a JSON 'pkt' message
 * tunnelling an AUN-type-3 (Ack) payload addressed to our own
 * websocket_network_address/websocket_station_address, exactly as a real
 * client sends one — reaches ServiceDispatcher::ackEvents() and drives a
 * FileServer-style block-by-block ack chain (via the shared
 * AckChainMockProvider) all the way to completion.
 *
 * The initial "request" that kicks off the chain is delivered directly via
 * ServiceDispatcher::inboundPacket() — WebSocket's own inbound wire handling
 * for an initial Unicast request is already covered by
 * WebSocketHandlerTest. What's under test here is specifically the ack
 * path.
 */

include_once(__DIR__ . '/../../src/include/system.inc.php');
include_once(__DIR__ . '/../support/AckChainMockProvider.php');
include_once(__DIR__ . '/../support/AckChainKickoffPacket.php');

use PHPUnit\Framework\TestCase;
use Monolog\Logger;
use Monolog\Handler\NullHandler;
use HomeLan\FileStore\WebSocket\Handler;
use HomeLan\FileStore\WebSocket\Map as WebSocketMap;
use HomeLan\FileStore\Aun\Map as AunMap;
use HomeLan\FileStore\Services\ServiceDispatcher;
use HomeLan\FileStore\Encapsulation\PacketDispatcher;
use Ratchet\ConnectionInterface;

class WebSocketAckChainIntegrationTest extends TestCase
{
    // Matches config.inc.php defaults for websocket_network_address / websocket_station_address
    private const WS_NET = 128;
    private const WS_STN = 254;
    private const CLIENT_NET = 1;
    private const CLIENT_STN = 1;

    private Logger $oLogger;
    private ConnectionInterface $oConnection;

    protected function setUp(): void
    {
        $this->oLogger = new Logger('ws-ackchain-test');
        $this->oLogger->pushHandler(new NullHandler());

        $this->resetWsMapState();
        $this->resetAunMapState();

        $this->oConnection = $this->createMock(ConnectionInterface::class);
        $this->oConnection->method('send');
    }

    protected function tearDown(): void
    {
        $this->resetWsMapState();
        $this->resetAunMapState();
    }

    private function resetWsMapState(): void
    {
        foreach (['aDynamicNetworks', 'aSocketList'] as $sProp) {
            $rp = new \ReflectionProperty(WebSocketMap::class, $sProp);
            $rp->setAccessible(true);
            $rp->setValue(null, []);
        }
    }

    private function resetAunMapState(): void
    {
        foreach (['aHostMap', 'aSubnetMap', 'aIPLookupCache', 'aIpCounter'] as $sProp) {
            $rp = new \ReflectionProperty(AunMap::class, $sProp);
            $rp->setAccessible(true);
            $rp->setValue(null, []);
        }
    }

    /** Encode a JSON 'pkt' message the way a websocket client would. */
    private function makePktJson(
        int    $iAunType = 3,      // 3=Ack
        int    $iPort    = 0x01,
        int    $iSeq     = 42,
        string $sData    = ''
    ): string {
        $sPayload = pack('CCCCV', $iAunType, $iPort, 0, 0, $iSeq) . $sData;
        return json_encode([
            'type'    => 'pkt',
            'src'     => ['station' => self::CLIENT_STN, 'network' => self::CLIENT_NET],
            'dst'     => ['station' => self::WS_STN, 'network' => self::WS_NET],
            'payload' => $sPayload,
        ], JSON_THROW_ON_ERROR);
    }

    public function testRealAckChainOverWebSocketDrivesProviderToCompletion(): void
    {
        $oProvider = new AckChainMockProvider(0x01, 3);
        $oServices = new ServiceDispatcher($this->oLogger, [$oProvider]);

        $oPacketDispatcher = $this->createMock(PacketDispatcher::class);
        $oHandler = new Handler($this->oLogger, $oServices, $oPacketDispatcher);
        $oHandler->onOpen($this->oConnection);

        // Kick off the chain: block 1 is sent and an addAckEvent registered
        // for the client.
        $oServices->inboundPacket(new AckChainKickoffPacket(0x01, self::CLIENT_NET, self::CLIENT_STN));
        $this->assertSame(1, $oProvider->getBlocksSent());
        $this->assertFalse($oProvider->isComplete());
        $oProvider->getReplies(); // drain block 1 — not under test here

        // Real ack #1, over the wire.
        $oHandler->onMessage($this->oConnection, $this->makePktJson(iSeq: 1));

        $this->assertSame(2, $oProvider->getBlocksSent(), 'first real ack must have driven block 2');
        $this->assertFalse($oProvider->isComplete());

        // Real ack #2 — completes the chain.
        $oHandler->onMessage($this->oConnection, $this->makePktJson(iSeq: 2));

        $this->assertSame(3, $oProvider->getBlocksSent());
        $this->assertTrue($oProvider->isComplete(), 'chain must be complete after the final real ack');
    }
}
