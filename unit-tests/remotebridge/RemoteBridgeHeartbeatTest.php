<?php

/*
 * Unit tests for the protocol 1.1+ PING/PONG heartbeat in RemoteBridge Connection.
 *
 * See docs/protocols/remote-bridge.md#heartbeat-protocol-11 for the spec this
 * implements: each side pings periodically once authenticated on a 1.1+
 * connection, replies PONG to a received PING, and any line at all resets an
 * idle watermark — silence past the timeout closes the connection as dead.
 * A 1.0-negotiated connection is entirely exempt: no ping timer, no idle
 * enforcement, matching how a 1.0 peer is exempt from ACK.
 */

include_once(__DIR__ . '/../../src/include/system.inc.php');
include_once(__DIR__ . '/MockTcpConnection.php');

use PHPUnit\Framework\TestCase;
use Monolog\Logger;
use Monolog\Handler\NullHandler;
use HomeLan\FileStore\RemoteBridge\Connection;
use HomeLan\FileStore\RemoteBridge\BridgePacket;
use HomeLan\FileStore\RemoteBridge\Map;
use React\EventLoop\LoopInterface;
use React\EventLoop\TimerInterface;

class RemoteBridgeHeartbeatTest extends TestCase
{
    private Logger $oLogger;

    protected function setUp(): void
    {
        $this->oLogger = new Logger('test');
        $this->oLogger->pushHandler(new NullHandler());
        Map::reset();
    }

    // -----------------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------------

    /**
     * Drives a server Connection through the full auth handshake against a
     * directly-driven client Connection, optionally pinning both sides to a
     * specific negotiated version by restricting the advertised list, and
     * optionally supplying a (mock) event loop to the server side under test.
     *
     * Returns [server Connection, server MockTcpConnection].
     */
    private function authenticatedServerWithLoop(
        ?LoopInterface $oLoop,
        array $aSupportedVersions = ['1.0', '1.1']
    ): array {
        $oServerTcp = new MockTcpConnection();
        $oClientTcp = new MockTcpConnection();

        $oServer = new Connection(
            $this->oLogger, $oServerTcp, 'server', 'secret', [1],
            static function (BridgePacket $p) {},
            static function (BridgePacket $p) {},
            $aSupportedVersions,
            $oLoop,
        );
        $oClient = new Connection(
            $this->oLogger, $oClientTcp, 'client', 'secret', [2],
            static function (BridgePacket $p) {},
            static function (BridgePacket $p) {},
            $aSupportedVersions,
        );

        $oServer->onData($oClientTcp->allWritten()); $oClientTcp->aWritten = [];
        $oClient->onData($oServerTcp->allWritten()); $oServerTcp->aWritten = [];
        $oServer->onData($oClientTcp->allWritten()); $oClientTcp->aWritten = [];
        $oClient->onData($oServerTcp->allWritten()); $oServerTcp->aWritten = [];

        $this->assertSame('AUTHENTICATED', $oServer->getState());
        return [$oServer, $oServerTcp];
    }

    // -----------------------------------------------------------------------
    // Receiving PING / PONG
    // -----------------------------------------------------------------------

    public function testReceivingPingRepliesPong(): void
    {
        [$oServer, $oServerTcp] = $this->authenticatedServerWithLoop(null);

        $oServer->onData("PING\n");

        $this->assertSame(['PONG'], $oServerTcp->writtenLines());
    }

    public function testReceivingPongProducesNoReply(): void
    {
        [$oServer, $oServerTcp] = $this->authenticatedServerWithLoop(null);

        $oServer->onData("PONG\n");

        $this->assertSame([], $oServerTcp->writtenLines());
        $this->assertFalse($oServerTcp->bClosed);
    }

    public function testMalformedPingLineIsTolerated(): void
    {
        [$oServer, $oServerTcp] = $this->authenticatedServerWithLoop(null);

        // Extra trailing field — still replied to, connection stays open.
        $oServer->onData("PING unexpected extra data\n");

        $this->assertSame(['PONG'], $oServerTcp->writtenLines());
        $this->assertFalse($oServerTcp->bClosed);
    }

    public function testMalformedPongLineIsTolerated(): void
    {
        [$oServer, $oServerTcp] = $this->authenticatedServerWithLoop(null);

        $oServer->onData("PONG unexpected extra data\n");

        $this->assertSame([], $oServerTcp->writtenLines());
        $this->assertFalse($oServerTcp->bClosed);
    }

    // -----------------------------------------------------------------------
    // Heartbeat scheduling on authentication
    // -----------------------------------------------------------------------

    public function testHeartbeatStartsTwoPeriodicTimersOnAuthenticationAt11(): void
    {
        $oLoop = $this->createMock(LoopInterface::class);
        $aIntervals = [];
        $oLoop->expects($this->exactly(2))
            ->method('addPeriodicTimer')
            ->willReturnCallback(function ($fInterval, $fCallable) use (&$aIntervals) {
                $aIntervals[] = $fInterval;
                return $this->createMock(TimerInterface::class);
            });

        $this->authenticatedServerWithLoop($oLoop, ['1.0', '1.1']);

        sort($aIntervals);
        $this->assertSame([1, 3], $aIntervals);
    }

    public function testHeartbeatNotStartedWhenNegotiatedVersionIs10(): void
    {
        $oLoop = $this->createMock(LoopInterface::class);
        $oLoop->expects($this->never())->method('addPeriodicTimer');

        // Restrict both sides to 1.0 only, so negotiation settles on 1.0.
        $this->authenticatedServerWithLoop($oLoop, ['1.0']);
    }

    public function testNoHeartbeatCrashWhenConstructedWithoutLoop(): void
    {
        // All other tests in this suite implicitly cover this (oLoop defaults
        // to null), but assert it explicitly: authentication must not throw
        // or attempt to touch a loop that was never supplied.
        [$oServer, $oServerTcp] = $this->authenticatedServerWithLoop(null);
        $this->assertSame('AUTHENTICATED', $oServer->getState());
        $this->assertFalse($oServerTcp->bClosed);
    }

    // -----------------------------------------------------------------------
    // Ping-timer tick behaviour
    // -----------------------------------------------------------------------

    public function testPingTimerTickWritesPing(): void
    {
        $oLoop = $this->createMock(LoopInterface::class);
        $aCallbacks = [];
        $oLoop->method('addPeriodicTimer')
            ->willReturnCallback(function ($fInterval, $fCallable) use (&$aCallbacks) {
                $aCallbacks[$fInterval] = $fCallable;
                return $this->createMock(TimerInterface::class);
            });

        [, $oServerTcp] = $this->authenticatedServerWithLoop($oLoop, ['1.0', '1.1']);
        $oServerTcp->aWritten = []; // discard AUTH_OK/NETWORKS noise from the handshake

        ($aCallbacks[3])();

        $this->assertSame(['PING'], $oServerTcp->writtenLines());
    }

    // -----------------------------------------------------------------------
    // Idle-timeout tick behaviour
    // -----------------------------------------------------------------------

    public function testIdleTimeoutClosesConnectionAfterSilence(): void
    {
        $oLoop = $this->createMock(LoopInterface::class);
        $aCallbacks = [];
        $oLoop->method('addPeriodicTimer')
            ->willReturnCallback(function ($fInterval, $fCallable) use (&$aCallbacks) {
                $aCallbacks[$fInterval] = $fCallable;
                return $this->createMock(TimerInterface::class);
            });

        [$oServer, $oServerTcp] = $this->authenticatedServerWithLoop($oLoop, ['1.0', '1.1']);

        // Force the rx watermark into the past, beyond IDLE_TIMEOUT_SECONDS (10s).
        $rProp = new \ReflectionProperty(Connection::class, 'fLastRxTime');
        $rProp->setAccessible(true);
        $rProp->setValue($oServer, microtime(true) - 11);

        ($aCallbacks[1])();

        $this->assertTrue($oServerTcp->bClosed);
    }

    public function testIdleTimeoutDoesNotCloseWhenRecentTrafficSeen(): void
    {
        $oLoop = $this->createMock(LoopInterface::class);
        $aCallbacks = [];
        $oLoop->method('addPeriodicTimer')
            ->willReturnCallback(function ($fInterval, $fCallable) use (&$aCallbacks) {
                $aCallbacks[$fInterval] = $fCallable;
                return $this->createMock(TimerInterface::class);
            });

        [, $oServerTcp] = $this->authenticatedServerWithLoop($oLoop, ['1.0', '1.1']);

        // No manipulation of fLastRxTime — handshake traffic just happened.
        ($aCallbacks[1])();

        $this->assertFalse($oServerTcp->bClosed);
    }

    public function testAnyLineResetsIdleWatermarkPreventingClose(): void
    {
        $oLoop = $this->createMock(LoopInterface::class);
        $aCallbacks = [];
        $oLoop->method('addPeriodicTimer')
            ->willReturnCallback(function ($fInterval, $fCallable) use (&$aCallbacks) {
                $aCallbacks[$fInterval] = $fCallable;
                return $this->createMock(TimerInterface::class);
            });

        [$oServer, $oServerTcp] = $this->authenticatedServerWithLoop($oLoop, ['1.0', '1.1']);

        $rProp = new \ReflectionProperty(Connection::class, 'fLastRxTime');
        $rProp->setAccessible(true);
        $rProp->setValue($oServer, microtime(true) - 11);

        // A PONG (not just PING) counts as traffic and must reset the watermark.
        $oServer->onData("PONG\n");

        ($aCallbacks[1])();

        $this->assertFalse($oServerTcp->bClosed);
    }

    // -----------------------------------------------------------------------
    // Timer cleanup on close
    // -----------------------------------------------------------------------

    public function testOnCloseCancelsBothTimers(): void
    {
        $oPingTimer = $this->createMock(TimerInterface::class);
        $oIdleTimer = $this->createMock(TimerInterface::class);
        $aTimers = [$oPingTimer, $oIdleTimer];

        $oLoop = $this->createMock(LoopInterface::class);
        $oLoop->method('addPeriodicTimer')
            ->willReturnCallback(function () use (&$aTimers) {
                return array_shift($aTimers);
            });
        $oLoop->expects($this->exactly(2))
            ->method('cancelTimer')
            ->with($this->logicalOr($this->identicalTo($oPingTimer), $this->identicalTo($oIdleTimer)));

        [$oServer] = $this->authenticatedServerWithLoop($oLoop, ['1.0', '1.1']);
        $oServer->onClose();
    }
}
