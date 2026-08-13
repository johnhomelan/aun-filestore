<?php

/*
 * @group unit-tests
 *
 * End-to-end test proving a real Piconet ack — a synthesized ack built from
 * a "TX_RESULT OK" firmware message, exactly as Handler::decodeMessage()
 * produces it in production — reaches ServiceDispatcher::ackEvents() and
 * drives a FileServer-style block-by-block ack chain (via the shared
 * AckChainMockProvider) all the way to completion.
 *
 * The initial "request" that kicks off the chain is delivered directly via
 * ServiceDispatcher::inboundPacket() — Piconet's own inbound wire handling
 * is already covered by PiconetHandlerTest. What's under test here is
 * specifically the ack path.
 */

include_once(__DIR__ . '/../../src/include/system.inc.php');
include_once(__DIR__ . '/../support/AckChainMockProvider.php');
include_once(__DIR__ . '/../support/AckChainKickoffPacket.php');
include_once(__DIR__ . '/MockPiconetConnection.php');

use PHPUnit\Framework\TestCase;
use Monolog\Logger;
use Monolog\Handler\NullHandler;
use HomeLan\FileStore\Piconet\Handler;
use HomeLan\FileStore\Services\ServiceDispatcher;
use HomeLan\FileStore\Encapsulation\PacketDispatcher;
use HomeLan\FileStore\Messages\EconetPacket;

class PiconetAckChainIntegrationTest extends TestCase
{
    private const CLIENT_NET = 1;
    private const CLIENT_STN = 5;

    private Logger $oLogger;

    protected function setUp(): void
    {
        $this->oLogger = new Logger('piconet-ackchain-test');
        $this->oLogger->pushHandler(new NullHandler());

        config::overrideValue('piconet_station', 254);
        config::overrideValue('piconet_local_network', self::CLIENT_NET);
        config::overrideValue('remote_bridge_enabled', 0);
    }

    protected function tearDown(): void
    {
        config::resetValue('piconet_station');
        config::resetValue('piconet_local_network');
        config::resetValue('remote_bridge_enabled');
    }

    private function makePacket(int $iDstStation, int $iDstNetwork, int $iPort = 0x99, string $sData = 'block'): EconetPacket
    {
        $oPacket = new EconetPacket();
        $oPacket->setDestinationStation($iDstStation);
        $oPacket->setDestinationNetwork($iDstNetwork);
        $oPacket->setPort($iPort);
        $oPacket->setFlags(0);
        $oPacket->setData($sData);
        return $oPacket;
    }

    public function testRealAckChainOverPiconetDrivesProviderToCompletion(): void
    {
        $oProvider = new AckChainMockProvider(0x99, 3);
        $oServices = new ServiceDispatcher($this->oLogger, [$oProvider]);

        $oPacketDispatcher = $this->createMock(PacketDispatcher::class);
        $oHandler = new Handler($this->oLogger, $oServices, $oPacketDispatcher);
        $oConn = new MockPiconetConnection();
        $oHandler->onOpen($oConn);

        // Kick off the chain: block 1 is sent and an addAckEvent registered
        // for the client.
        $oServices->inboundPacket(new AckChainKickoffPacket(0x99, self::CLIENT_NET, self::CLIENT_STN));
        $this->assertSame(1, $oProvider->getBlocksSent());
        $this->assertFalse($oProvider->isComplete());
        $oProvider->getReplies(); // drain block 1 — not under test here

        // Handler has a real outbound packet in flight, awaiting a TX_RESULT.
        $oHandler->send($this->makePacket(self::CLIENT_STN, self::CLIENT_NET));
        $oHandler->decodeMessage('TX_RESULT OK');

        $this->assertSame(2, $oProvider->getBlocksSent(), 'first real ack must have driven block 2');
        $this->assertFalse($oProvider->isComplete());

        // Second real ack — completes the chain.
        $oHandler->send($this->makePacket(self::CLIENT_STN, self::CLIENT_NET));
        $oHandler->decodeMessage('TX_RESULT OK');

        $this->assertSame(3, $oProvider->getBlocksSent());
        $this->assertTrue($oProvider->isComplete(), 'chain must be complete after the final real ack');
    }
}
