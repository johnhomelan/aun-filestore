<?php

/*
 * @group unit-tests
 *
 * Unit tests for HomeLan\FileStore\Aun\Handler.
 *
 * The React\Datagram\Socket is replaced by a PHPUnit mock so we can assert
 * which UDP datagrams the handler sends without binding a real socket.
 * Aun\Map is re-initialised before each test to provide a predictable
 * IP ↔ eco-address mapping and sequence-number counter.
 */

include_once(__DIR__ . '/../../src/include/system.inc.php');

use PHPUnit\Framework\TestCase;
use Monolog\Logger;
use Monolog\Handler\NullHandler;
use HomeLan\FileStore\Aun\Handler;
use HomeLan\FileStore\Aun\Map as AunMap;
use HomeLan\FileStore\Services\ServiceDispatcher;
use HomeLan\FileStore\Encapsulation\PacketDispatcher;
use HomeLan\FileStore\Messages\EconetPacket;
use React\Datagram\Socket as DatagramSocket;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;

class AunHandlerTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    // Test map: subnet 192.168.0.0/24 → network 127,  host 192.168.0.40 → 127.254
    private const MAP = "192.168.0.0/24 127\n192.168.0.40 127.254\n";

    // Destination used for most single-host tests
    private const DST_NET = 127;
    private const DST_STN = 1;
    private const DST_IP  = '192.168.0.1';
    private const DST_HOST = '192.168.0.1:32768';

    // Source address for inbound packets
    private const SRC_HOST = '192.168.0.2:32768';
    private const SRC_HOST2 = '192.168.0.3:32768';

    private Logger $oLogger;
    private ServiceDispatcher $oServices;
    private PacketDispatcher $oPacketDispatcher;
    private DatagramSocket $oSocket;
    private Handler $oHandler;

    protected function setUp(): void
    {
        $this->oLogger = new Logger('aun-test');
        $this->oLogger->pushHandler(new NullHandler());

        $this->resetAunMapState();

        // Provide a stub handler to AunMap::init() — it's stored as a static reference
        $oMapHandler = $this->createStub(Handler::class);
        AunMap::init($this->oLogger, $oMapHandler, self::MAP);

        // Reset the AUN sequence counter for the destination host so tests get
        // predictable sequence numbers (first incAunCounter → 4).
        AunMap::setAunCounter(self::DST_IP, 0);

        $this->oServices = $this->createMock(ServiceDispatcher::class);
        $this->oPacketDispatcher = $this->createMock(PacketDispatcher::class);
        $this->oSocket = $this->createMock(DatagramSocket::class);

        $this->oHandler = new Handler($this->oLogger, $this->oServices, $this->oPacketDispatcher);
        $this->oHandler->setSocket($this->oSocket);

        config::overrideValue('local_ip', '127.0.0.1');
        config::overrideValue('aun_default_port', '32768');
    }

    protected function tearDown(): void
    {
        config::resetValue('local_ip');
        config::resetValue('aun_default_port');
        $this->resetAunMapState();
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function resetAunMapState(): void
    {
        foreach (['aHostMap', 'aSubnetMap', 'aIPLookupCache', 'aIpCounter'] as $sProp) {
            $rp = new \ReflectionProperty(AunMap::class, $sProp);
            $rp->setAccessible(true);
            $rp->setValue(null, []);
        }
    }

    private function getProp(string $sProp): mixed
    {
        $rp = new \ReflectionProperty(Handler::class, $sProp);
        $rp->setAccessible(true);
        return $rp->getValue($this->oHandler);
    }

    private function setProp(string $sProp, mixed $value): void
    {
        $rp = new \ReflectionProperty(Handler::class, $sProp);
        $rp->setAccessible(true);
        $rp->setValue($this->oHandler, $value);
    }

    /**
     * Build a raw AUN wire packet.
     *
     * Wire layout: |type(1)|port(1)|cb(1)|pad(1)|seq(4LE)|data|
     * Types: 1=Broadcast, 2=Unicast, 3=Ack, 4=Reject, 5=Immediate
     */
    private function makeAunWire(
        int    $iType = 2,
        int    $iPort = 0x99,
        int    $iCb   = 0,
        int    $iSeq  = 4,
        string $sData = 'hello'
    ): string {
        return pack('CCCCV', $iType, $iPort, $iCb, 0, $iSeq) . $sData;
    }

    /** Return an EconetPacket destined for DST_NET.DST_STN. */
    private function makeEconetPacket(
        int    $iDstNet = self::DST_NET,
        int    $iDstStn = self::DST_STN,
        int    $iPort   = 0x99,
        string $sData   = 'test'
    ): EconetPacket {
        $oPacket = new EconetPacket();
        $oPacket->setDestinationNetwork($iDstNet);
        $oPacket->setDestinationStation($iDstStn);
        $oPacket->setPort($iPort);
        $oPacket->setFlags(0);
        $oPacket->setData($sData);
        return $oPacket;
    }

    // =========================================================================
    // Initial state
    // =========================================================================

    public function testQueueIsEmptyInitially(): void
    {
        $this->assertEmpty($this->getProp('aQueue'));
    }

    public function testSeqWindowsIsEmptyInitially(): void
    {
        $this->assertEmpty($this->getProp('aSeqWindows'));
    }

    // =========================================================================
    // receive() — Unicast (type 2)
    // =========================================================================

    public function testReceiveUnicastSendsAckToSource(): void
    {
        $this->oSocket->expects($this->once())
            ->method('send')
            ->with($this->anything(), self::SRC_HOST);
        $this->oServices->method('getReplies')->willReturn([]);

        $this->oHandler->receive($this->makeAunWire(2), self::SRC_HOST, self::DST_HOST);
    }

    public function testReceiveUnicastAckIsByteTypeThree(): void
    {
        $sSentAck = null;
        $this->oSocket->method('send')
            ->willReturnCallback(function ($sData, $sAddr) use (&$sSentAck) { $sSentAck = $sData; });
        $this->oServices->method('getReplies')->willReturn([]);

        $this->oHandler->receive($this->makeAunWire(2, 0x99, 0, 42), self::SRC_HOST, self::DST_HOST);

        $this->assertNotNull($sSentAck);
        $aHeader = unpack('C', $sSentAck);
        $this->assertSame(3, $aHeader[1], 'Ack packet must start with type byte 3');
    }

    public function testReceiveUnicastAckEchoesSequenceNumber(): void
    {
        $sSentAck = null;
        $this->oSocket->method('send')
            ->willReturnCallback(function ($sData, $_addr) use (&$sSentAck) { $sSentAck = $sData; });
        $this->oServices->method('getReplies')->willReturn([]);

        $this->oHandler->receive($this->makeAunWire(2, 0x99, 0, 99), self::SRC_HOST, self::DST_HOST);

        $aSeq = unpack('V', substr($sSentAck, 4, 4));
        $this->assertSame(99, (int) $aSeq[1], 'Ack must echo the original sequence number');
    }

    public function testReceiveUnicastDispatchesToServices(): void
    {
        $this->oServices->expects($this->once())->method('inboundPacket');
        $this->oServices->method('getReplies')->willReturn([]);
        $this->oSocket->method('send');

        $this->oHandler->receive($this->makeAunWire(2), self::SRC_HOST, self::DST_HOST);
    }

    public function testReceiveUnicastForwardsRepliesToPacketDispatcher(): void
    {
        $oReply = $this->makeEconetPacket();
        $this->oServices->method('getReplies')->willReturn([$oReply]);
        $this->oSocket->method('send');

        $this->oPacketDispatcher->expects($this->once())
            ->method('sendPacket')
            ->with($oReply);

        $this->oHandler->receive($this->makeAunWire(2), self::SRC_HOST, self::DST_HOST);
    }

    // =========================================================================
    // receive() — Broadcast (type 1)
    // =========================================================================

    public function testReceiveBroadcastSendsNoAck(): void
    {
        $this->oSocket->expects($this->never())->method('send');
        $this->oServices->method('getReplies')->willReturn([]);

        $this->oHandler->receive($this->makeAunWire(1), self::SRC_HOST, self::DST_HOST);
    }

    public function testReceiveBroadcastDispatchesToServices(): void
    {
        $this->oServices->expects($this->once())->method('inboundPacket');
        $this->oServices->method('getReplies')->willReturn([]);

        $this->oHandler->receive($this->makeAunWire(1), self::SRC_HOST, self::DST_HOST);
    }

    // =========================================================================
    // receive() — Ack (type 3)
    // =========================================================================

    public function testReceiveAckDoesNotDispatchToServices(): void
    {
        $this->oServices->expects($this->never())->method('inboundPacket');
        // No packets queued — _unQueue is a no-op
        $this->oHandler->receive($this->makeAunWire(3), self::SRC_HOST, self::DST_HOST);
    }

    public function testReceiveAckSendsNoAckToSource(): void
    {
        $this->oSocket->expects($this->never())->method('send');
        $this->oHandler->receive($this->makeAunWire(3), self::SRC_HOST, self::DST_HOST);
    }

    // =========================================================================
    // receive() — Duplicate detection
    // =========================================================================

    public function testReceiveDuplicatePacketNotDispatchedToServices(): void
    {
        $this->oServices->expects($this->once())->method('inboundPacket');
        $this->oServices->method('getReplies')->willReturn([]);
        $this->oSocket->method('send');

        $sWire = $this->makeAunWire(2, 0x99, 0, 77);
        $this->oHandler->receive($sWire, self::SRC_HOST, self::DST_HOST);
        // Second receive with same seq from same source — must NOT dispatch again
        $this->oHandler->receive($sWire, self::SRC_HOST, self::DST_HOST);
    }

    public function testReceiveDuplicateStillSendsAck(): void
    {
        $this->oServices->method('getReplies')->willReturn([]);
        $this->oSocket->expects($this->exactly(2))->method('send');

        $sWire = $this->makeAunWire(2, 0x99, 0, 77);
        $this->oHandler->receive($sWire, self::SRC_HOST, self::DST_HOST);
        $this->oHandler->receive($sWire, self::SRC_HOST, self::DST_HOST);
    }

    public function testReceiveSameSeqFromDifferentSourcesBothDispatched(): void
    {
        $this->oServices->expects($this->exactly(2))->method('inboundPacket');
        $this->oServices->method('getReplies')->willReturn([]);
        $this->oSocket->method('send');

        $sWire = $this->makeAunWire(2, 0x99, 0, 77);
        $this->oHandler->receive($sWire, self::SRC_HOST,  self::DST_HOST);
        $this->oHandler->receive($sWire, self::SRC_HOST2, self::DST_HOST);
    }

    public function testReceiveUpdatesSeqWindowPerSource(): void
    {
        $this->oServices->method('getReplies')->willReturn([]);
        $this->oSocket->method('send');

        $this->oHandler->receive($this->makeAunWire(2, 0x99, 0, 55), self::SRC_HOST, self::DST_HOST);

        $aWindows = $this->getProp('aSeqWindows');
        $this->assertArrayHasKey(self::SRC_HOST, $aWindows);
        $this->assertContains(55, $aWindows[self::SRC_HOST]['seqs']);
    }

    // =========================================================================
    // receive() — Immediate (type 5)
    // =========================================================================

    public function testReceiveImmediateMachineTypeQuerySendsImmediateReply(): void
    {
        $sSentData = null;
        $this->oSocket->method('send')
            ->willReturnCallback(function ($sData, $_) use (&$sSentData) { $sSentData = $sData; });
        $this->oServices->method('getReplies')->willReturn([]);

        // cb=0: machine type query
        $this->oHandler->receive($this->makeAunWire(5, 0, 0, 1), self::SRC_HOST, self::DST_HOST);

        $this->assertNotNull($sSentData);
        $aHeader = unpack('C', $sSentData);
        $this->assertSame(6, $aHeader[1], 'Response to machine type query must be ImmediateReply (type 6)');
        // Byte at offset 8 must be 0x40 (FS01 FileStore machine type)
        $iMachineType = unpack('C', substr($sSentData, 8, 1))[1];
        $this->assertSame(0x40, $iMachineType, 'Machine type byte must identify as FS01 FileStore');
    }

    public function testReceiveImmediateEchoRequestSendsReply(): void
    {
        $sSentData = null;
        $this->oSocket->method('send')
            ->willReturnCallback(function ($sData, $_) use (&$sSentData) { $sSentData = $sData; });
        $this->oServices->method('getReplies')->willReturn([]);

        // cb=8: echo request
        $this->oHandler->receive($this->makeAunWire(5, 0, 8, 1), self::SRC_HOST, self::DST_HOST);

        $this->assertNotNull($sSentData);
        $aHeader = unpack('C', $sSentData);
        $this->assertSame(6, $aHeader[1], 'Echo response must be ImmediateReply (type 6)');
    }

    // =========================================================================
    // send() + timer()
    // =========================================================================

    public function testSendQueuesPacketByDestinationHost(): void
    {
        $this->oHandler->send($this->makeEconetPacket());

        $aQueue = $this->getProp('aQueue');
        $this->assertArrayHasKey(self::DST_HOST, $aQueue);
        $this->assertCount(1, $aQueue[self::DST_HOST]);
    }

    public function testTimerTransmitsQueuedPacket(): void
    {
        $this->oSocket->expects($this->once())
            ->method('send')
            ->with($this->anything(), self::DST_HOST);

        $this->oHandler->send($this->makeEconetPacket());
        $this->oHandler->timer();
    }

    public function testTimerTransmitsAunFrameWithUnicastTypeByte(): void
    {
        $sSentFrame = null;
        $this->oSocket->method('send')
            ->willReturnCallback(function ($sData, $_) use (&$sSentFrame) { $sSentFrame = $sData; });

        $this->oHandler->send($this->makeEconetPacket());
        $this->oHandler->timer();

        $this->assertNotNull($sSentFrame);
        $aHdr = unpack('C', $sSentFrame);
        $this->assertSame(2, $aHdr[1], 'AUN frame for unicast packet must have type byte 2');
    }

    public function testTimerRetryDelayedByLinearBackoff(): void
    {
        // After the first send (attempts=1, backoff=400), the next timer() call
        // burns the backoff instead of retrying.
        $this->oSocket->expects($this->once())->method('send'); // only first send
        $this->oHandler->send($this->makeEconetPacket());
        $this->oHandler->timer(); // first transmit
        $this->oHandler->timer(); // burns backoff (400 → 0) — no TX
        // Socket::send() must only have been called once at this point
    }

    public function testTimerRetriesAfterBackoffClears(): void
    {
        // First timer = transmit (backoff=0)
        // Second timer = backoff burn (400→0)
        // Third timer = retry transmit
        $this->oSocket->expects($this->exactly(2))->method('send');
        $this->oHandler->send($this->makeEconetPacket());
        $this->oHandler->timer(); // TX #1
        $this->oHandler->timer(); // burn backoff
        $this->oHandler->timer(); // TX #2 (retry)
    }

    public function testSecondSendToSameHostQueuesSecondPacket(): void
    {
        $this->oHandler->send($this->makeEconetPacket(self::DST_NET, self::DST_STN));
        $this->oHandler->send($this->makeEconetPacket(self::DST_NET, self::DST_STN));

        $aQueue = $this->getProp('aQueue');
        // One entry re-queued (retry) + one genuinely new entry
        $this->assertGreaterThanOrEqual(2, count($aQueue[self::DST_HOST]));
    }

    // =========================================================================
    // Ack — dequeue
    // =========================================================================

    public function testAckWithMatchingSeqDequeuesPacket(): void
    {
        $oPacket = $this->makeEconetPacket();
        $this->oSocket->method('send');

        $this->oHandler->send($oPacket);
        $this->oHandler->timer(); // transmits, sets iSequence on $oPacket

        $iSeq = $oPacket->getSequence();

        // Build a raw AUN ack from the *source* address with the matching seq
        $sAck = $this->makeAunWire(3, 0, 0, $iSeq, '');
        $this->oHandler->receive($sAck, self::DST_HOST, '127.0.0.1:32768');

        $aQueue = $this->getProp('aQueue');
        $this->assertEmpty($aQueue[self::DST_HOST] ?? []);
    }

    public function testAckWithWrongSeqDoesNotDequeue(): void
    {
        $oPacket = $this->makeEconetPacket();
        $this->oSocket->method('send');

        $this->oHandler->send($oPacket);
        $this->oHandler->timer();

        $iSeq = $oPacket->getSequence();

        // Ack with a different sequence number — packet should stay in queue
        $sAck = $this->makeAunWire(3, 0, 0, $iSeq + 4, '');
        $this->oHandler->receive($sAck, self::DST_HOST, '127.0.0.1:32768');

        $aQueue = $this->getProp('aQueue');
        $this->assertNotEmpty($aQueue[self::DST_HOST]);
    }

    // =========================================================================
    // Ack — dispatch to services (bug 1 fix: a genuinely-expected ack must
    // reach ServiceDispatcher::inboundPacket() so addAckEvent() callbacks —
    // e.g. FileServer's block-by-block load/save continuation — actually fire)
    // =========================================================================

    public function testAckWithMatchingSeqDispatchesToServices(): void
    {
        $oPacket = $this->makeEconetPacket();
        $this->oSocket->method('send');
        $this->oServices->method('getReplies')->willReturn([]);

        $this->oHandler->send($oPacket);
        $this->oHandler->timer();
        $iSeq = $oPacket->getSequence();

        $this->oServices->expects($this->once())->method('inboundPacket');

        $sAck = $this->makeAunWire(3, 0, 0, $iSeq, '');
        $this->oHandler->receive($sAck, self::DST_HOST, '127.0.0.1:32768');
    }

    public function testAckWithMatchingSeqForwardsResultingReplies(): void
    {
        $oPacket = $this->makeEconetPacket();
        $this->oSocket->method('send');

        $oReplyPacket = $this->makeEconetPacket();
        $this->oServices->method('getReplies')->willReturn([$oReplyPacket]);
        $this->oPacketDispatcher->expects($this->once())->method('sendPacket')->with($oReplyPacket);

        $this->oHandler->send($oPacket);
        $this->oHandler->timer();
        $iSeq = $oPacket->getSequence();

        $sAck = $this->makeAunWire(3, 0, 0, $iSeq, '');
        $this->oHandler->receive($sAck, self::DST_HOST, '127.0.0.1:32768');
    }

    public function testAckWithWrongSeqDoesNotDispatchToServices(): void
    {
        $oPacket = $this->makeEconetPacket();
        $this->oSocket->method('send');

        $this->oHandler->send($oPacket);
        $this->oHandler->timer();
        $iSeq = $oPacket->getSequence();

        $this->oServices->expects($this->never())->method('inboundPacket');

        // Wrong sequence, retries still remain — a stray ack, not the one expected.
        $sAck = $this->makeAunWire(3, 0, 0, $iSeq + 4, '');
        $this->oHandler->receive($sAck, self::DST_HOST, '127.0.0.1:32768');
    }

    // Note: an ack for a completely untracked host not dispatching to
    // services is already covered by testReceiveAckDoesNotDispatchToServices
    // above (nothing was ever sent to that host, so _unQueue() has neither a
    // matching queue entry nor a last-chance entry to match against).

    // =========================================================================
    // Last-chance / clearAckEvent
    // =========================================================================

    public function testClearAckEventCalledWhenLastChanceAckSeqMismatches(): void
    {
        // With retries=0, the packet fires once then sits in aLastChance.
        // A subsequent ack with a different seq triggers clearAckEvent.
        $oPacket = $this->makeEconetPacket(self::DST_NET, self::DST_STN);
        $this->oSocket->method('send');

        $this->oHandler->send($oPacket, 0);
        $this->oHandler->timer(); // last chance registered (seq=4)

        // Receive an ack with the wrong seq from DST_HOST
        $sAck = $this->makeAunWire(3, 0, 0, 999, '');
        $this->oServices->expects($this->once())->method('clearAckEvent');

        $this->oHandler->receive($sAck, self::DST_HOST, '127.0.0.1:32768');
    }

    public function testClearAckEventNotCalledWhenLastChanceAckSeqMatches(): void
    {
        $oPacket = $this->makeEconetPacket(self::DST_NET, self::DST_STN);
        $this->oSocket->method('send');
        $this->oServices->method('getReplies')->willReturn([]);

        $this->oHandler->send($oPacket, 0);
        $this->oHandler->timer(); // seq=4 registered in lastChance

        $iSeq = $oPacket->getSequence(); // = 4

        // Receive the correct ack — no clearAckEvent
        $sAck = $this->makeAunWire(3, 0, 0, $iSeq, '');
        $this->oServices->expects($this->never())->method('clearAckEvent');

        $this->oHandler->receive($sAck, self::DST_HOST, '127.0.0.1:32768');
    }

    public function testLastChanceAckWithMatchingSeqDispatchesToServices(): void
    {
        // The final retry's own ack — arriving late, after retries were
        // exhausted and the packet moved into aLastChance — must still
        // notify the waiting service, not just be silently absorbed.
        $oPacket = $this->makeEconetPacket(self::DST_NET, self::DST_STN);
        $this->oSocket->method('send');
        $this->oServices->method('getReplies')->willReturn([]);

        $this->oHandler->send($oPacket, 0);
        $this->oHandler->timer(); // seq=4 registered in lastChance
        $iSeq = $oPacket->getSequence();

        $this->oServices->expects($this->once())->method('inboundPacket');

        $sAck = $this->makeAunWire(3, 0, 0, $iSeq, '');
        $this->oHandler->receive($sAck, self::DST_HOST, '127.0.0.1:32768');
    }

    public function testLastChanceAckWithMismatchedSeqDoesNotDispatchToServices(): void
    {
        // The wrong ack arrives after retries were exhausted — clearAckEvent
        // handles giving up on it (see testClearAckEventCalledWhenLastChance...
        // above); it must not *also* be treated as the expected ack.
        $oPacket = $this->makeEconetPacket(self::DST_NET, self::DST_STN);
        $this->oSocket->method('send');

        $this->oHandler->send($oPacket, 0);
        $this->oHandler->timer(); // last chance registered (seq=4)

        $this->oServices->expects($this->never())->method('inboundPacket');

        $sAck = $this->makeAunWire(3, 0, 0, 999, '');
        $this->oHandler->receive($sAck, self::DST_HOST, '127.0.0.1:32768');
    }

    // =========================================================================
    // receive() — malformed / unknown type rejection
    // =========================================================================

    public function testMalformedPacketTooShortIsDiscarded(): void
    {
        // 7 bytes — one byte short of the 8-byte minimum AUN header
        $this->oServices->expects($this->never())->method('inboundPacket');

        $this->oHandler->receive(str_repeat("\x00", 7), self::SRC_HOST, self::DST_HOST);
    }

    public function testMalformedPacketDoesNotSendAck(): void
    {
        $this->oSocket->expects($this->never())->method('send');

        $this->oHandler->receive(str_repeat("\x00", 4), self::SRC_HOST, self::DST_HOST);
    }

    public function testUnknownPacketTypeIsDiscarded(): void
    {
        // Type byte 7 is not in the AUN type map (valid types are 1–6)
        $this->oServices->expects($this->never())->method('inboundPacket');

        $this->oHandler->receive($this->makeAunWire(7), self::SRC_HOST, self::DST_HOST);
    }

    public function testUnknownPacketTypeDoesNotSendAck(): void
    {
        $this->oSocket->expects($this->never())->method('send');

        $this->oHandler->receive($this->makeAunWire(7), self::SRC_HOST, self::DST_HOST);
    }

    // =========================================================================
    // receive() — sliding-window dedup
    // =========================================================================

    public function testDelayedRetransmitInWindowIsDuplicate(): void
    {
        // The old single-entry implementation forgot seq=4 once seq=8 and seq=12 arrived.
        // The sliding window must catch the retransmit even with intervening packets.
        $iCallCount = 0;
        $this->oServices->method('inboundPacket')
            ->willReturnCallback(function () use (&$iCallCount) { $iCallCount++; });
        $this->oServices->method('getReplies')->willReturn([]);
        $this->oSocket->method('send');

        $this->oHandler->receive($this->makeAunWire(2, 0x99, 0, 4),  self::SRC_HOST, self::DST_HOST);
        $this->oHandler->receive($this->makeAunWire(2, 0x99, 0, 8),  self::SRC_HOST, self::DST_HOST);
        $this->oHandler->receive($this->makeAunWire(2, 0x99, 0, 12), self::SRC_HOST, self::DST_HOST);
        // Delayed retransmit of the first packet
        $this->oHandler->receive($this->makeAunWire(2, 0x99, 0, 4),  self::SRC_HOST, self::DST_HOST);

        $this->assertSame(3, $iCallCount, 'Delayed retransmit must not dispatch to services');
    }

    public function testWindowEvictsOldestSeqWhenFull(): void
    {
        $this->oServices->method('getReplies')->willReturn([]);
        $this->oSocket->method('send');

        // Feed SEQ_WINDOW_SIZE+1 unique sequences so the first one is evicted
        $iWindowSize = 8;
        for ($i = 1; $i <= $iWindowSize + 1; $i++) {
            $this->oHandler->receive($this->makeAunWire(2, 0x99, 0, $i * 4), self::SRC_HOST, self::DST_HOST);
        }

        $aWindows = $this->getProp('aSeqWindows');
        // seq=4 (the first) must have been evicted
        $this->assertNotContains(4, $aWindows[self::SRC_HOST]['seqs']);
        // seq=8 (the second) must still be present
        $this->assertContains(8, $aWindows[self::SRC_HOST]['seqs']);
        // The window must not exceed the maximum size
        $this->assertCount($iWindowSize, $aWindows[self::SRC_HOST]['seqs']);
    }

    public function testEvictedSeqIsNoLongerDetectedAsDuplicate(): void
    {
        $iCallCount = 0;
        $this->oServices->method('inboundPacket')
            ->willReturnCallback(function () use (&$iCallCount) { $iCallCount++; });
        $this->oServices->method('getReplies')->willReturn([]);
        $this->oSocket->method('send');

        // Fill and overflow the window so seq=4 is evicted
        $iWindowSize = 8;
        for ($i = 1; $i <= $iWindowSize + 1; $i++) {
            $this->oHandler->receive($this->makeAunWire(2, 0x99, 0, $i * 4), self::SRC_HOST, self::DST_HOST);
        }

        // seq=4 is no longer in the window — a very-late retransmit passes through
        $iCallsBefore = $iCallCount;
        $this->oHandler->receive($this->makeAunWire(2, 0x99, 0, 4), self::SRC_HOST, self::DST_HOST);
        $this->assertSame($iCallsBefore + 1, $iCallCount, 'Evicted seq must not be treated as duplicate');
    }

    // =========================================================================
    // onClose()
    // =========================================================================

    public function testOnCloseDoesNotThrow(): void
    {
        $this->oHandler->onClose();
        $this->assertTrue(true);
    }

    // =========================================================================
    // End-to-end regression test for bug 1: a real ServiceDispatcher::
    // addAckEvent() callback (as used by FileServer's block-by-block
    // load/save protocol) must actually fire when the client's real ack for
    // that block arrives — not just have inboundPacket() invoked on a mock.
    // Uses a real ServiceDispatcher rather than the mocked one from setUp().
    // =========================================================================

    public function testRealAckEventCallbackFiresWhenExpectedAckArrives(): void
    {
        $rp = new \ReflectionProperty(ServiceDispatcher::class, 'oSingleton');
        $rp->setAccessible(true);
        $rp->setValue(null, null);

        $oRealServices = ServiceDispatcher::create($this->oLogger, []);

        // getSequence() is lazily assigned and cached on first call, so reading it here
        // (before send() embeds it in the wire frame) locks in the same value used later.
        $oPacket = $this->makeEconetPacket();
        $iSeq = $oPacket->getSequence();

        $bFired = false;
        $oReceivedAck = null;
        $oRealServices->addAckEvent(self::DST_NET, self::DST_STN, $iSeq, function ($oAckPacket) use (&$bFired, &$oReceivedAck) {
            $bFired = true;
            $oReceivedAck = $oAckPacket;
        });

        $oPacketDispatcher = $this->createMock(PacketDispatcher::class);
        $oHandler = new Handler($this->oLogger, $oRealServices, $oPacketDispatcher);
        $oSocket = $this->createMock(DatagramSocket::class);
        $oSocket->method('send');
        $oHandler->setSocket($oSocket);

        $oHandler->send($oPacket);
        $oHandler->timer(); // transmits

        $sAck = $this->makeAunWire(3, 0, 0, $iSeq, '');
        $oHandler->receive($sAck, self::DST_HOST, '127.0.0.1:32768');

        $rp->setValue(null, null);

        $this->assertTrue($bFired, 'addAckEvent callback must fire for the real, expected ack');
        $this->assertNotNull($oReceivedAck);
    }
}
