<?php

/*
 * @group unit-tests
 *
 * End-to-end test proving a real AUN ack, arriving via Aun\Handler::receive(),
 * reaches ServiceDispatcher::ackEvents() and drives a FileServer-style
 * block-by-block ack chain (via the shared AckChainMockProvider) all the way
 * to completion — not just that a single ack fires a single callback.
 *
 * The initial "request" that kicks off the chain is delivered directly via
 * ServiceDispatcher::inboundPacket() — AUN's own inbound-Unicast wire
 * handling is already covered by AunHandlerTest. What's under test here is
 * specifically the ack path: Handler::receive() → ackEvents() → provider
 * callback → next block → re-registered addAckEvent, repeated to completion.
 */

include_once(__DIR__ . '/../../src/include/system.inc.php');
include_once(__DIR__ . '/../support/AckChainMockProvider.php');
include_once(__DIR__ . '/../support/AckChainKickoffPacket.php');

use PHPUnit\Framework\TestCase;
use Monolog\Logger;
use Monolog\Handler\NullHandler;
use HomeLan\FileStore\Aun\Handler;
use HomeLan\FileStore\Aun\Map as AunMap;
use HomeLan\FileStore\Services\ServiceDispatcher;
use HomeLan\FileStore\Encapsulation\PacketDispatcher;
use HomeLan\FileStore\Messages\EconetPacket;
use React\Datagram\Socket as DatagramSocket;

class AunAckChainIntegrationTest extends TestCase
{
    private const MAP = "192.168.0.0/24 127\n192.168.0.40 127.254\n";
    private const CLIENT_NET  = 127;
    private const CLIENT_STN  = 1;
    private const CLIENT_IP   = '192.168.0.1';
    private const CLIENT_HOST = '192.168.0.1:32768';

    private Logger $oLogger;

    protected function setUp(): void
    {
        $this->oLogger = new Logger('aun-ackchain-test');
        $this->oLogger->pushHandler(new NullHandler());

        $this->resetAunMapState();
        $oMapHandler = $this->createStub(Handler::class);
        AunMap::init($this->oLogger, $oMapHandler, self::MAP);
        AunMap::setAunCounter(self::CLIENT_IP, 0);

        config::overrideValue('local_ip', '127.0.0.1');
        config::overrideValue('aun_default_port', '32768');
    }

    protected function tearDown(): void
    {
        config::resetValue('local_ip');
        config::resetValue('aun_default_port');
        $this->resetAunMapState();
    }

    private function resetAunMapState(): void
    {
        foreach (['aHostMap', 'aSubnetMap', 'aIPLookupCache', 'aIpCounter'] as $sProp) {
            $rp = new \ReflectionProperty(AunMap::class, $sProp);
            $rp->setAccessible(true);
            $rp->setValue(null, []);
        }
    }

    private function makeAunWire(int $iType, int $iPort, int $iCb, int $iSeq, string $sData): string
    {
        return pack('CCCCV', $iType, $iPort, $iCb, 0, $iSeq) . $sData;
    }

    private function makeEconetPacket(int $iDstNet, int $iDstStn, int $iPort, string $sData = 'block'): EconetPacket
    {
        $oPacket = new EconetPacket();
        $oPacket->setDestinationNetwork($iDstNet);
        $oPacket->setDestinationStation($iDstStn);
        $oPacket->setPort($iPort);
        $oPacket->setFlags(0);
        $oPacket->setData($sData);
        return $oPacket;
    }

    public function testRealAckChainOverAunDrivesProviderToCompletion(): void
    {
        $oProvider = new AckChainMockProvider(0x99, 3);
        $oServices = new ServiceDispatcher($this->oLogger, [$oProvider]);

        $oPacketDispatcher = $this->createMock(PacketDispatcher::class);
        $oHandler = new Handler($this->oLogger, $oServices, $oPacketDispatcher);
        $oSocket = $this->createMock(DatagramSocket::class);
        $oSocket->method('send');
        $oHandler->setSocket($oSocket);

        // Kick off the chain: block 1 is sent and an addAckEvent registered
        // for the client, keyed on block 1's own real sequence number.
        $oServices->inboundPacket(new AckChainKickoffPacket(0x99, self::CLIENT_NET, self::CLIENT_STN));
        $this->assertSame(1, $oProvider->getBlocksSent());
        $this->assertFalse($oProvider->isComplete());

        // Actually dispatch block 1 through Handler, so its own retry-queue tracks the same
        // real outstanding sequence AckChainMockProvider registered addAckEvent() against —
        // an ack has to match both Handler::_unQueue()'s gate and ServiceDispatcher's now.
        // ServiceDispatcher::inboundPacket() already drained the provider's own reply buffer
        // into its own queue (see queueReply()), so pull the actual block-1 packet from there
        // — not a freshly-fabricated one, which wouldn't share block 1's real sequence number.
        foreach ($oServices->getReplies() as $oReply) {
            $oHandler->send($oReply);
        }
        $oHandler->timer();
        $iSeq = $oProvider->getLastSentSeq();
        $this->assertNotNull($iSeq);
        $oHandler->receive($this->makeAunWire(3, 0, 0, $iSeq, ''), self::CLIENT_HOST, '127.0.0.1:32768');

        $this->assertSame(2, $oProvider->getBlocksSent(), 'first real ack must have driven block 2');
        $this->assertFalse($oProvider->isComplete());

        // Real ack #2 — completes the chain. Block 2 was queued by the ack-driven continuation
        // (ackEvents() -> the registered callback -> sendNextBlock()), which never goes through
        // ServiceDispatcher::queueReply() the way the initial inboundPacket() dispatch did — it
        // only ever lands in the provider's own buffer, so drain that instead here.
        foreach ($oProvider->getReplies() as $oReply) {
            $oHandler->send($oReply);
        }
        $oHandler->timer();
        $iSeq2 = $oProvider->getLastSentSeq();
        $oHandler->receive($this->makeAunWire(3, 0, 0, $iSeq2, ''), self::CLIENT_HOST, '127.0.0.1:32768');

        $this->assertSame(3, $oProvider->getBlocksSent());
        $this->assertTrue($oProvider->isComplete(), 'chain must be complete after the final real ack');
    }

    /**
     * A stray ack for the same station but the wrong sequence number — e.g. a duplicate/delayed
     * ack from an earlier, unrelated exchange — must not advance the chain; only the ack whose
     * sequence matches the block actually in flight may.
     */
    public function testStrayAckWithWrongSequenceDoesNotAdvanceChain(): void
    {
        $oProvider = new AckChainMockProvider(0x99, 3);
        $oServices = new ServiceDispatcher($this->oLogger, [$oProvider]);

        $oPacketDispatcher = $this->createMock(PacketDispatcher::class);
        $oHandler = new Handler($this->oLogger, $oServices, $oPacketDispatcher);
        $oSocket = $this->createMock(DatagramSocket::class);
        $oSocket->method('send');
        $oHandler->setSocket($oSocket);

        $oServices->inboundPacket(new AckChainKickoffPacket(0x99, self::CLIENT_NET, self::CLIENT_STN));

        // The block actually in flight — dispatched through Handler so its own retry-queue
        // tracks the same real sequence AckChainMockProvider registered addAckEvent() against.
        // (ServiceDispatcher::inboundPacket() already drained the provider's own reply buffer
        // into its own queue — see queueReply() — so pull it from there, not the provider.)
        foreach ($oServices->getReplies() as $oReply) {
            $oHandler->send($oReply);
        }
        $oHandler->timer();
        $iRealSeq = $oProvider->getLastSentSeq();
        $this->assertNotNull($iRealSeq);

        // A stray ack for an unrelated sequence number, from the same station.
        $oHandler->receive($this->makeAunWire(3, 0, 0, $iRealSeq + 1000, ''), self::CLIENT_HOST, '127.0.0.1:32768');
        $this->assertSame(1, $oProvider->getBlocksSent(), 'a mismatched-sequence ack must not advance the chain');
        $this->assertFalse($oProvider->isComplete());

        // The real ack, arriving after the stray one, must still drive the chain.
        $oHandler->receive($this->makeAunWire(3, 0, 0, $iRealSeq, ''), self::CLIENT_HOST, '127.0.0.1:32768');
        $this->assertSame(2, $oProvider->getBlocksSent(), 'the correct-sequence ack must still advance the chain');
    }
}
