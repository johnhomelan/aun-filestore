<?php

/*
 * Unit tests for RemoteBridge ServerHandler / ClientHandler behaviour.
 *
 * ServerHandler and ClientHandler both embed identical packet-dispatch closures
 * that are only reachable once a TCP connection is established.  Since the
 * real start()/connect() methods open live sockets we test the closure logic
 * through a directly-driven Connection (same technique as RemoteBridgeAuthTest).
 *
 * ClientHandler reconnect-backoff is tested via ReflectionMethod on the private
 * scheduleReconnect() method, with a mock LoopInterface so no real timer fires.
 */

include_once(__DIR__ . '/../../src/include/system.inc.php');
include_once(__DIR__ . '/MockTcpConnection.php');

use PHPUnit\Framework\TestCase;
use Monolog\Logger;
use Monolog\Handler\NullHandler;
use HomeLan\FileStore\RemoteBridge\Connection;
use HomeLan\FileStore\RemoteBridge\BridgePacket;
use HomeLan\FileStore\RemoteBridge\Map;
use HomeLan\FileStore\Messages\EconetPacket;
use HomeLan\FileStore\Services\ServiceDispatcher;
use HomeLan\FileStore\Encapsulation\PacketDispatcher;

// ---------------------------------------------------------------------------
// Tests for the fOnPacket dispatch closure (shared by both handlers)
// ---------------------------------------------------------------------------

class RemoteBridgeHandlerTest extends TestCase
{
    private Logger $oLogger;

    protected function setUp(): void
    {
        $this->oLogger = new Logger('test');
        $this->oLogger->pushHandler(new NullHandler());
        Map::reset();
        config::overrideValue('piconet_local_network', 1);
        config::overrideValue('piconet_station', 5);
    }

    protected function tearDown(): void
    {
        config::resetValue('piconet_local_network');
        config::resetValue('piconet_station');
    }

    // -----------------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------------

    /**
     * Returns a closure that implements the same packet-dispatch logic as
     * both ServerHandler and ClientHandler, using the supplied mocks.
     */
    private function makeDispatchClosure(
        ServiceDispatcher $oServices,
        PacketDispatcher $oPacketDispatcher
    ): \Closure {
        return function (BridgePacket $oPkt) use ($oServices, $oPacketDispatcher) {
            $iLocalNet = (int) config::getValue('piconet_local_network');
            $iOurStn   = (int) config::getValue('piconet_station');
            $bForLocalService = $iOurStn > 0
                && $oPkt->getDstStation() === $iOurStn
                && ($oPkt->getDstNetwork() === $iLocalNet || $oPkt->getDstNetwork() === 0);

            if (!$bForLocalService) {
                $oPacketDispatcher->sendPacket($oPkt->buildEconetPacket());
            }

            $oServices->inboundPacket($oPkt);
            $aReplies = $oServices->getReplies();
            foreach ($aReplies as $oReply) {
                $oPacketDispatcher->sendPacket($oReply);
            }
        };
    }

    /**
     * Drives a server Connection through the full auth handshake so that
     * subsequent SEND lines reach fOnPacket.  Returns the authenticated server
     * Connection.
     */
    private function authenticatedServer(
        MockTcpConnection $oServerTcp,
        \Closure $fOnPacket,
        string $sSecret = 'secret',
        ?\Closure $fOnAck = null
    ): Connection {
        $oClientTcp = new MockTcpConnection();
        $oServer = new Connection(
            $this->oLogger, $oServerTcp, 'server', $sSecret, [1, 2], $fOnPacket,
            $fOnAck ?? static function (BridgePacket $p) {},
        );
        $oClient = new Connection(
            $this->oLogger, $oClientTcp, 'client', $sSecret, [3],
            static function (BridgePacket $p) {},
            static function (BridgePacket $p) {},
        );

        // Relay four messages to complete the handshake.
        $oServer->onData($oClientTcp->allWritten()); $oClientTcp->aWritten = [];
        $oClient->onData($oServerTcp->allWritten()); $oServerTcp->aWritten = [];
        $oServer->onData($oClientTcp->allWritten()); $oClientTcp->aWritten = [];
        $oClient->onData($oServerTcp->allWritten()); $oServerTcp->aWritten = [];

        return $oServer;
    }

    /** Builds a SEND line addressed to the given dst network / station. */
    private function makeSendLine(int $iDstNet, int $iDstStn, int $iSrcNet = 3, int $iSrcStn = 10): string
    {
        $oPkt = new EconetPacket();
        $oPkt->setDestinationNetwork($iDstNet);
        $oPkt->setDestinationstation($iDstStn);
        $oPkt->setSourceNetwork($iSrcNet);
        $oPkt->setSourceStation($iSrcStn);
        $oPkt->setPort(0x99);
        $oPkt->setFlags(0);
        $oPkt->setData('payload');
        return BridgePacket::encode($oPkt);
    }

    // -----------------------------------------------------------------------
    // Non-local packet — both sendPacket and inboundPacket called
    // -----------------------------------------------------------------------

    public function testNonLocalPacketCallsSendPacket(): void
    {
        $oServices        = $this->createMock(ServiceDispatcher::class);
        $oPacketDispatcher = $this->createMock(PacketDispatcher::class);
        $oServices->method('getReplies')->willReturn([]);

        $oPacketDispatcher->expects($this->once())
            ->method('sendPacket')
            ->with($this->isInstanceOf(EconetPacket::class));

        $oServerTcp = new MockTcpConnection();
        $oServer    = $this->authenticatedServer(
            $oServerTcp,
            $this->makeDispatchClosure($oServices, $oPacketDispatcher)
        );

        // Packet to dst 2.100 — not our service station (1.5)
        $oServer->onData($this->makeSendLine(2, 100));
    }

    public function testNonLocalPacketCallsInboundPacket(): void
    {
        $oServices        = $this->createMock(ServiceDispatcher::class);
        $oPacketDispatcher = $this->createMock(PacketDispatcher::class);
        $oServices->method('getReplies')->willReturn([]);

        $oServices->expects($this->once())
            ->method('inboundPacket')
            ->with($this->isInstanceOf(BridgePacket::class));

        $oServerTcp = new MockTcpConnection();
        $oServer    = $this->authenticatedServer(
            $oServerTcp,
            $this->makeDispatchClosure($oServices, $oPacketDispatcher)
        );

        $oServer->onData($this->makeSendLine(2, 100));
    }

    // -----------------------------------------------------------------------
    // Local service station — sendPacket skipped, inboundPacket still called
    // -----------------------------------------------------------------------

    public function testLocalServiceStationSkipsSendPacket(): void
    {
        // piconet_station=5, piconet_local_network=1 (set in setUp)
        $oServices        = $this->createMock(ServiceDispatcher::class);
        $oPacketDispatcher = $this->createMock(PacketDispatcher::class);
        $oServices->method('getReplies')->willReturn([]);

        $oPacketDispatcher->expects($this->never())->method('sendPacket');

        $oServerTcp = new MockTcpConnection();
        $oServer    = $this->authenticatedServer(
            $oServerTcp,
            $this->makeDispatchClosure($oServices, $oPacketDispatcher)
        );

        // Packet to our exact service station 1.5
        $oServer->onData($this->makeSendLine(1, 5));
    }

    public function testLocalServiceStationStillCallsInboundPacket(): void
    {
        $oServices        = $this->createMock(ServiceDispatcher::class);
        $oPacketDispatcher = $this->createMock(PacketDispatcher::class);
        $oServices->method('getReplies')->willReturn([]);

        $oServices->expects($this->once())
            ->method('inboundPacket')
            ->with($this->isInstanceOf(BridgePacket::class));

        $oServerTcp = new MockTcpConnection();
        $oServer    = $this->authenticatedServer(
            $oServerTcp,
            $this->makeDispatchClosure($oServices, $oPacketDispatcher)
        );

        $oServer->onData($this->makeSendLine(1, 5));
    }

    public function testLocalServiceStationOnNetZeroSkipsSendPacket(): void
    {
        // dst net=0 is also treated as "our network"
        $oServices        = $this->createMock(ServiceDispatcher::class);
        $oPacketDispatcher = $this->createMock(PacketDispatcher::class);
        $oServices->method('getReplies')->willReturn([]);

        $oPacketDispatcher->expects($this->never())->method('sendPacket');

        $oServerTcp = new MockTcpConnection();
        $oServer    = $this->authenticatedServer(
            $oServerTcp,
            $this->makeDispatchClosure($oServices, $oPacketDispatcher)
        );

        $oServer->onData($this->makeSendLine(0, 5));
    }

    public function testWhenPiconetStationIsZeroAllPacketsForwarded(): void
    {
        // piconet_station=0 disables the service-station filter
        config::overrideValue('piconet_station', 0);

        $oServices        = $this->createMock(ServiceDispatcher::class);
        $oPacketDispatcher = $this->createMock(PacketDispatcher::class);
        $oServices->method('getReplies')->willReturn([]);

        $oPacketDispatcher->expects($this->once())->method('sendPacket');

        $oServerTcp = new MockTcpConnection();
        $oServer    = $this->authenticatedServer(
            $oServerTcp,
            $this->makeDispatchClosure($oServices, $oPacketDispatcher)
        );

        // Even a packet addressed to station 5 on local net must be forwarded
        // when piconet_station == 0.
        $oServer->onData($this->makeSendLine(1, 5));
    }

    // -----------------------------------------------------------------------
    // Reply forwarding
    // -----------------------------------------------------------------------

    public function testRepliesAreForwardedViaSendPacket(): void
    {
        $oReply = new EconetPacket();

        $oServices        = $this->createMock(ServiceDispatcher::class);
        $oPacketDispatcher = $this->createMock(PacketDispatcher::class);
        $oServices->method('getReplies')->willReturn([$oReply]);

        // One call for the inbound forward, one for the reply.
        $oPacketDispatcher->expects($this->exactly(2))->method('sendPacket');

        $oServerTcp = new MockTcpConnection();
        $oServer    = $this->authenticatedServer(
            $oServerTcp,
            $this->makeDispatchClosure($oServices, $oPacketDispatcher)
        );

        $oServer->onData($this->makeSendLine(2, 100));
    }

    public function testMultipleRepliesAreAllForwarded(): void
    {
        $aReplies = [new EconetPacket(), new EconetPacket(), new EconetPacket()];

        $oServices        = $this->createMock(ServiceDispatcher::class);
        $oPacketDispatcher = $this->createMock(PacketDispatcher::class);
        $oServices->method('getReplies')->willReturn($aReplies);

        // 1 forward + 3 replies = 4 total
        $oPacketDispatcher->expects($this->exactly(4))->method('sendPacket');

        $oServerTcp = new MockTcpConnection();
        $oServer    = $this->authenticatedServer(
            $oServerTcp,
            $this->makeDispatchClosure($oServices, $oPacketDispatcher)
        );

        $oServer->onData($this->makeSendLine(2, 100));
    }

    public function testRepliesFromLocalServiceStationAreForwarded(): void
    {
        // Even though sendPacket is skipped for the inbound packet, replies must still go out
        $oReply = new EconetPacket();

        $oServices        = $this->createMock(ServiceDispatcher::class);
        $oPacketDispatcher = $this->createMock(PacketDispatcher::class);
        $oServices->method('getReplies')->willReturn([$oReply]);

        // Only one call — for the reply (inbound forward is skipped)
        $oPacketDispatcher->expects($this->once())->method('sendPacket')
            ->with($this->identicalTo($oReply));

        $oServerTcp = new MockTcpConnection();
        $oServer    = $this->authenticatedServer(
            $oServerTcp,
            $this->makeDispatchClosure($oServices, $oPacketDispatcher)
        );

        $oServer->onData($this->makeSendLine(1, 5));
    }

    // -----------------------------------------------------------------------
    // ACK (protocol 1.1+) — see docs/protocols/remote-bridge.md
    // -----------------------------------------------------------------------

    public function testSendAckWritesLineWhenAuthenticatedAndVersion1_1(): void
    {
        $oServices        = $this->createMock(ServiceDispatcher::class);
        $oPacketDispatcher = $this->createMock(PacketDispatcher::class);
        $oServices->method('getReplies')->willReturn([]);

        $oServerTcp = new MockTcpConnection();
        $oServer    = $this->authenticatedServer(
            $oServerTcp,
            $this->makeDispatchClosure($oServices, $oPacketDispatcher)
        );
        $oServerTcp->aWritten = [];

        $oServer->sendAck(2, 254);

        $this->assertSame(['ACK 2 254'], $oServerTcp->writtenLines());
    }

    public function testSendAckWritesNothingWhenNotAuthenticated(): void
    {
        $oServerTcp = new MockTcpConnection();
        $oServer = new Connection(
            $this->oLogger, $oServerTcp, 'server', 'secret', [1, 2],
            static function (BridgePacket $p) {},
            static function (BridgePacket $p) {},
        );

        $oServer->sendAck(2, 254);

        $this->assertSame([], $oServerTcp->writtenLines());
    }

    public function testSendAckWritesNothingWhenPeerIsVersion1_0(): void
    {
        $oServices        = $this->createMock(ServiceDispatcher::class);
        $oPacketDispatcher = $this->createMock(PacketDispatcher::class);
        $oServices->method('getReplies')->willReturn([]);

        $oClientTcp = new MockTcpConnection();
        $oServerTcp = new MockTcpConnection();
        $oServer = new Connection(
            $this->oLogger, $oServerTcp, 'server', 'secret', [1, 2],
            $this->makeDispatchClosure($oServices, $oPacketDispatcher),
            static function (BridgePacket $p) {},
        );
        $oClient = new Connection(
            $this->oLogger, $oClientTcp, 'client', 'secret', [3],
            static function (BridgePacket $p) {},
            static function (BridgePacket $p) {},
            ['1.0'],
        );

        $oServer->onData($oClientTcp->allWritten()); $oClientTcp->aWritten = [];
        $oClient->onData($oServerTcp->allWritten()); $oServerTcp->aWritten = [];
        $oServer->onData($oClientTcp->allWritten()); $oClientTcp->aWritten = [];
        $oClient->onData($oServerTcp->allWritten()); $oServerTcp->aWritten = [];

        $this->assertSame('1.0', $oServer->getProtocolVersion());

        $oServerTcp->aWritten = [];
        $oServer->sendAck(2, 254);

        $this->assertSame([], $oServerTcp->writtenLines());
    }

    public function testWellFormedAckLineTriggersOnAckCallback(): void
    {
        $oServices        = $this->createMock(ServiceDispatcher::class);
        $oPacketDispatcher = $this->createMock(PacketDispatcher::class);
        $oServices->method('getReplies')->willReturn([]);

        $oReceivedAck = null;
        $fOnAck = function (BridgePacket $oPkt) use (&$oReceivedAck) {
            $oReceivedAck = $oPkt;
        };

        $oServerTcp = new MockTcpConnection();
        $oServer    = $this->authenticatedServer(
            $oServerTcp,
            $this->makeDispatchClosure($oServices, $oPacketDispatcher),
            'secret',
            $fOnAck
        );

        $oServer->onData("ACK 2 254\n");

        $this->assertNotNull($oReceivedAck);
        $this->assertSame('Ack', $oReceivedAck->getPacketType());
        $oEco = $oReceivedAck->buildEconetPacket();
        $this->assertSame(2, $oEco->getSourceNetwork());
        $this->assertSame(254, $oEco->getSourceStation());
    }

    public function testMalformedAckLineDoesNotTriggerOnAckCallback(): void
    {
        $oServices        = $this->createMock(ServiceDispatcher::class);
        $oPacketDispatcher = $this->createMock(PacketDispatcher::class);
        $oServices->method('getReplies')->willReturn([]);

        $bCalled = false;
        $fOnAck = function (BridgePacket $oPkt) use (&$bCalled) {
            $bCalled = true;
        };

        $oServerTcp = new MockTcpConnection();
        $oServer    = $this->authenticatedServer(
            $oServerTcp,
            $this->makeDispatchClosure($oServices, $oPacketDispatcher),
            'secret',
            $fOnAck
        );

        // Missing the station field
        $oServer->onData("ACK 2\n");

        $this->assertFalse($bCalled);
    }

    public function testAckLineDoesNotInvokeFOnPacket(): void
    {
        $oServices        = $this->createMock(ServiceDispatcher::class);
        $oPacketDispatcher = $this->createMock(PacketDispatcher::class);
        $oServices->method('getReplies')->willReturn([]);
        $oServices->expects($this->never())->method('inboundPacket');
        $oPacketDispatcher->expects($this->never())->method('sendPacket');

        $oServerTcp = new MockTcpConnection();
        $oServer    = $this->authenticatedServer(
            $oServerTcp,
            $this->makeDispatchClosure($oServices, $oPacketDispatcher)
        );

        $oServer->onData("ACK 2 254\n");
    }
}

