<?php

/*
 * @group unit-tests
 *
 * Unit tests for HomeLan\FileStore\WebSocket\Handler.
 *
 * Ratchet\ConnectionInterface is mocked by PHPUnit — it only declares send()
 * and close() so there are no void-return-type problems.
 * WebSocket\Map and Aun\Map static state is reset in setUp() so tests are
 * independent.
 */

include_once(__DIR__ . '/../../src/include/system.inc.php');

use PHPUnit\Framework\TestCase;
use Monolog\Logger;
use Monolog\Handler\NullHandler;
use HomeLan\FileStore\WebSocket\Handler;
use HomeLan\FileStore\WebSocket\Map as WebSocketMap;
use HomeLan\FileStore\Aun\Map as AunMap;
use HomeLan\FileStore\Services\ServiceDispatcher;
use HomeLan\FileStore\Encapsulation\PacketDispatcher;
use HomeLan\FileStore\Messages\EconetPacket;
use Ratchet\ConnectionInterface;

class WebSocketHandlerTest extends TestCase
{
    // Config constants — must match aunmap test config
    private const WS_NET = 128;
    private const WS_STN = 254;

    // Dynamic network range in WebSocket\Map
    private const DYN_NET = 128;

    private Logger $oLogger;
    private ServiceDispatcher $oServices;
    private PacketDispatcher $oPacketDispatcher;
    private Handler $oHandler;
    private ConnectionInterface $oConnection;

    protected function setUp(): void
    {
        $this->oLogger = new Logger('ws-test');
        $this->oLogger->pushHandler(new NullHandler());

        $this->resetWsMapState();
        $this->resetAunMapState();

        // The WebSocketMap must have a dynamic network so ctrl messages can
        // allocate an address.  Re-add it each setUp after the reset.
        WebSocketMap::addDynamicRangeNetwork(self::DYN_NET);

        $this->oServices = $this->createMock(ServiceDispatcher::class);
        $this->oPacketDispatcher = $this->createMock(PacketDispatcher::class);
        $this->oConnection = $this->createMock(ConnectionInterface::class);

        $this->oHandler = new Handler($this->oLogger, $this->oServices, $this->oPacketDispatcher);
    }

    protected function tearDown(): void
    {
        $this->resetWsMapState();
        $this->resetAunMapState();
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

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

    private function getProp(string $sProp): mixed
    {
        $rp = new \ReflectionProperty(Handler::class, $sProp);
        $rp->setAccessible(true);
        return $rp->getValue($this->oHandler);
    }

    /**
     * Encode a JSON 'pkt' message the way a websocket client would.
     *
     * The payload binary is the 8-byte AUN wire header followed by $sData:
     *   type(1) | port(1) | cb(1) | pad(1) | seq(4LE) | data
     */
    private function makePktJson(
        int    $iDstNet  = self::WS_NET,
        int    $iDstStn  = self::WS_STN,
        int    $iSrcNet  = 1,
        int    $iSrcStn  = 1,
        int    $iAunType = 2,      // 2=Unicast
        int    $iPort    = 0x01,   // must be ≤ 0x7F for valid UTF-8 in JSON
        int    $iCb      = 0,
        int    $iSeq     = 42,
        string $sData    = 'test'
    ): string {
        $sPayload = pack('CCCCV', $iAunType, $iPort, $iCb, 0, $iSeq) . $sData;
        return json_encode([
            'type'    => 'pkt',
            'src'     => ['station' => $iSrcStn, 'network' => $iSrcNet],
            'dst'     => ['station' => $iDstStn, 'network' => $iDstNet],
            'payload' => $sPayload,
        ], JSON_THROW_ON_ERROR);
    }

    /** Build a ctrl JSON message. */
    private function makeCtrlJson(string $sRequest = 'dynamic_alloction_request', array $aArgs = []): string
    {
        return json_encode([
            'type'    => 'ctrl',
            'request' => $sRequest,
            'args'    => $aArgs,
        ], JSON_THROW_ON_ERROR);
    }

    // =========================================================================
    // onOpen()
    // =========================================================================

    public function testOnOpenAttachesConnection(): void
    {
        $this->oHandler->onOpen($this->oConnection);

        $oConnections = $this->getProp('oConnections');
        $this->assertTrue($oConnections->contains($this->oConnection));
    }

    public function testOnOpenIncrementsConnectionSequence(): void
    {
        $this->oHandler->onOpen($this->oConnection);
        $this->assertSame(1, $this->getProp('iConnectionSequence'));
    }

    public function testOnOpenMultipleConnectionsIncrementSequencePerOpen(): void
    {
        $oConn2 = $this->createMock(ConnectionInterface::class);

        $this->oHandler->onOpen($this->oConnection);
        $this->oHandler->onOpen($oConn2);

        $this->assertSame(2, $this->getProp('iConnectionSequence'));
    }

    // =========================================================================
    // onClose()
    // =========================================================================

    public function testOnCloseDetachesConnection(): void
    {
        $this->oHandler->onOpen($this->oConnection);
        $this->oHandler->onClose($this->oConnection);

        $oConnections = $this->getProp('oConnections');
        $this->assertFalse($oConnections->contains($this->oConnection));
    }

    public function testOnCloseFreesWebSocketMapAddress(): void
    {
        // Register the connection in the map via a ctrl message, then close.
        $this->oHandler->onOpen($this->oConnection);
        $this->oConnection->method('send');

        $this->oHandler->onMessage($this->oConnection, $this->makeCtrlJson());

        // After freeAddress the map entry is removed — verify by trying to
        // free it a second time (returns false when already absent).
        $this->oHandler->onClose($this->oConnection);

        $this->assertFalse(WebSocketMap::freeAddress($this->oConnection));
    }

    public function testOnCloseDoesNotThrowWhenConnectionNeverOpened(): void
    {
        $this->oHandler->onClose($this->oConnection);
        $this->assertTrue(true);
    }

    // =========================================================================
    // onError()
    // =========================================================================

    public function testOnErrorClosesConnection(): void
    {
        $this->oHandler->onOpen($this->oConnection);

        $this->oConnection->expects($this->once())->method('close');

        $this->oHandler->onError($this->oConnection, new \Exception('test error'));
    }

    public function testOnErrorDetachesConnection(): void
    {
        $this->oHandler->onOpen($this->oConnection);

        $this->oConnection->method('close');
        $this->oHandler->onError($this->oConnection, new \Exception('test error'));

        $oConnections = $this->getProp('oConnections');
        $this->assertFalse($oConnections->contains($this->oConnection));
    }

    public function testOnErrorFreesMapAddress(): void
    {
        $this->oHandler->onOpen($this->oConnection);
        $this->oConnection->method('send');

        $this->oHandler->onMessage($this->oConnection, $this->makeCtrlJson());

        $this->oConnection->method('close');
        $this->oHandler->onError($this->oConnection, new \Exception('test error'));

        $this->assertFalse(WebSocketMap::freeAddress($this->oConnection));
    }

    // =========================================================================
    // onMessage() — 'pkt' addressed to our (websocket server) address
    // =========================================================================

    public function testOnMessageUnicastToOurAddressSendsAck(): void
    {
        $this->oConnection->expects($this->once())->method('send');
        $this->oServices->method('getReplies')->willReturn([]);

        $this->oHandler->onMessage($this->oConnection, $this->makePktJson());
    }

    public function testOnMessageUnicastAckContainsPktType(): void
    {
        $sSentAck = null;
        $this->oConnection->method('send')
            ->willReturnCallback(function ($s) use (&$sSentAck) { $sSentAck = $s; });
        $this->oServices->method('getReplies')->willReturn([]);

        $this->oHandler->onMessage($this->oConnection, $this->makePktJson());

        $this->assertNotNull($sSentAck);
        $aDecoded = json_decode($sSentAck, true);
        $this->assertSame('pkt', $aDecoded['type']);
    }

    public function testOnMessageUnicastAckHasTypeByteThreeInPayload(): void
    {
        $sSentAck = null;
        $this->oConnection->method('send')
            ->willReturnCallback(function ($s) use (&$sSentAck) { $sSentAck = $s; });
        $this->oServices->method('getReplies')->willReturn([]);

        $this->oHandler->onMessage($this->oConnection, $this->makePktJson(iSeq: 77));

        $aDecoded = json_decode($sSentAck, true);
        $aType = unpack('C', $aDecoded['payload']);
        $this->assertSame(3, $aType[1], 'AUN ack type byte must be 3');
    }

    public function testOnMessageUnicastAckEchoesSequenceNumber(): void
    {
        $sSentAck = null;
        $this->oConnection->method('send')
            ->willReturnCallback(function ($s) use (&$sSentAck) { $sSentAck = $s; });
        $this->oServices->method('getReplies')->willReturn([]);

        $this->oHandler->onMessage($this->oConnection, $this->makePktJson(iSeq: 77));

        $aDecoded = json_decode($sSentAck, true);
        $aSeq = unpack('V', substr($aDecoded['payload'], 4, 4));
        $this->assertSame(77, (int) $aSeq[1]);
    }

    public function testOnMessagePktToOurAddressDispatchesToServices(): void
    {
        $this->oServices->expects($this->once())->method('inboundPacket');
        $this->oServices->method('getReplies')->willReturn([]);
        $this->oConnection->method('send');

        $this->oHandler->onMessage($this->oConnection, $this->makePktJson());
    }

    public function testOnMessagePktToOurAddressForwardsRepliesToPacketDispatcher(): void
    {
        $oReply = new EconetPacket();
        $this->oServices->method('getReplies')->willReturn([$oReply]);
        $this->oConnection->method('send');

        $this->oPacketDispatcher->expects($this->once())
            ->method('sendPacket')
            ->with($oReply);

        $this->oHandler->onMessage($this->oConnection, $this->makePktJson());
    }

    public function testOnMessagePktToOurAddressForwardsMultipleReplies(): void
    {
        $aReplies = [new EconetPacket(), new EconetPacket()];
        $this->oServices->method('getReplies')->willReturn($aReplies);
        $this->oConnection->method('send');

        $this->oPacketDispatcher->expects($this->exactly(2))->method('sendPacket');

        $this->oHandler->onMessage($this->oConnection, $this->makePktJson());
    }

    // =========================================================================
    // onMessage() — 'pkt' addressed to a different station
    // =========================================================================

    public function testOnMessagePktToOtherAddressDoesNotDispatch(): void
    {
        $this->oServices->expects($this->never())->method('inboundPacket');

        // Station 1, network 1 — not us (128.254)
        $this->oHandler->onMessage($this->oConnection, $this->makePktJson(iDstNet: 1, iDstStn: 1));
    }

    public function testOnMessagePktToOtherAddressSendsAck(): void
    {
        // Transit packets are acked immediately (store-and-forward semantics)
        $this->oConnection->expects($this->once())->method('send');

        $this->oHandler->onMessage($this->oConnection, $this->makePktJson(iDstNet: 1, iDstStn: 1));
    }

    // =========================================================================
    // onMessage() — 'ctrl'
    // =========================================================================

    public function testOnMessageCtrlSendsAck(): void
    {
        $this->oConnection->expects($this->once())->method('send');

        $this->oHandler->onMessage($this->oConnection, $this->makeCtrlJson());
    }

    public function testOnMessageCtrlAckIsCtrlType(): void
    {
        $sSentAck = null;
        $this->oConnection->method('send')
            ->willReturnCallback(function ($s) use (&$sSentAck) { $sSentAck = $s; });

        $this->oHandler->onMessage($this->oConnection, $this->makeCtrlJson());

        $aDecoded = json_decode($sSentAck, true);
        $this->assertSame('ctrl', $aDecoded['type']);
    }

    public function testOnMessageDynamicAllocRequestReturnsAnAddress(): void
    {
        $sSentAck = null;
        $this->oConnection->method('send')
            ->willReturnCallback(function ($s) use (&$sSentAck) { $sSentAck = $s; });

        $this->oHandler->onMessage($this->oConnection, $this->makeCtrlJson('dynamic_alloction_request'));

        $aDecoded = json_decode($sSentAck, true);
        $this->assertArrayHasKey('response', $aDecoded);
        $this->assertNotEmpty($aDecoded['response']);
    }

    public function testOnMessageCtrlDoesNotDispatchToServices(): void
    {
        $this->oServices->expects($this->never())->method('inboundPacket');
        $this->oConnection->method('send');

        $this->oHandler->onMessage($this->oConnection, $this->makeCtrlJson());
    }

    public function testOnMessageCtrlDoesNotCallGetReplies(): void
    {
        $this->oServices->expects($this->never())->method('getReplies');
        $this->oConnection->method('send');

        $this->oHandler->onMessage($this->oConnection, $this->makeCtrlJson());
    }

    // =========================================================================
    // onMessage() — malformed / invalid messages are discarded, not fatal
    // =========================================================================

    public function testOnMessageInvalidTypeIsDiscardedSilently(): void
    {
        // With the fix, an unknown type is logged and dropped — no exception thrown.
        $this->oConnection->expects($this->never())->method('send');

        $sInvalidMsg = json_encode(['type' => 'bad_type']);
        $this->oHandler->onMessage($this->oConnection, $sInvalidMsg);
        $this->assertTrue(true); // reached here without exception
    }

    public function testOnMessageInvalidJsonIsDiscardedSilently(): void
    {
        $this->oConnection->expects($this->never())->method('send');

        $this->oHandler->onMessage($this->oConnection, 'not-valid-json{{{');
        $this->assertTrue(true);
    }

    public function testOnMessageMalformedJsonDoesNotDispatchToServices(): void
    {
        $this->oServices->expects($this->never())->method('inboundPacket');

        $this->oHandler->onMessage($this->oConnection, 'not-valid-json{{{');
    }

    public function testOnMessagePktWithTooShortPayloadIsDiscarded(): void
    {
        $this->oConnection->expects($this->never())->method('send');
        $this->oServices->expects($this->never())->method('inboundPacket');

        $sMsg = json_encode([
            'type'    => 'pkt',
            'src'     => ['station' => 1, 'network' => 1],
            'dst'     => ['station' => self::WS_STN, 'network' => self::WS_NET],
            'payload' => 'x',  // 1 byte — below 8-byte minimum
        ]);
        $this->oHandler->onMessage($this->oConnection, $sMsg);
    }

    public function testOnMessagePktMissingPayloadFieldIsDiscarded(): void
    {
        $this->oConnection->expects($this->never())->method('send');
        $this->oServices->expects($this->never())->method('inboundPacket');

        $sMsg = json_encode([
            'type' => 'pkt',
            'src'  => ['station' => 1, 'network' => 1],
            'dst'  => ['station' => self::WS_STN, 'network' => self::WS_NET],
        ]);
        $this->oHandler->onMessage($this->oConnection, $sMsg);
    }

    // =========================================================================
    // Immediate pkt variants
    // =========================================================================

    public function testOnMessageImmediateMachineTypeQuerySendsImmediateReply(): void
    {
        $sSentAck = null;
        $this->oConnection->method('send')
            ->willReturnCallback(function ($s) use (&$sSentAck) { $sSentAck = $s; });
        $this->oServices->method('getReplies')->willReturn([]);

        // cb=0: machine type query
        $this->oHandler->onMessage($this->oConnection, $this->makePktJson(iAunType: 5, iCb: 0));

        $aDecoded = json_decode($sSentAck, true);
        $aType = unpack('C', $aDecoded['payload']);
        $this->assertSame(6, $aType[1], 'Response to machine type query must be ImmediateReply (type 6)');
    }

    public function testOnMessageImmediateMachineTypeReplyIdentifiesAsFileStore(): void
    {
        $sSentAck = null;
        $this->oConnection->method('send')
            ->willReturnCallback(function ($s) use (&$sSentAck) { $sSentAck = $s; });
        $this->oServices->method('getReplies')->willReturn([]);

        $this->oHandler->onMessage($this->oConnection, $this->makePktJson(iAunType: 5, iCb: 0));

        $aDecoded = json_decode($sSentAck, true);
        // Machine type byte is at payload offset 8
        $iMachineType = unpack('C', substr($aDecoded['payload'], 8, 1))[1];
        $this->assertSame(0x40, $iMachineType);
    }

    public function testOnMessageImmediateEchoQuerySendsImmediateReply(): void
    {
        $sSentAck = null;
        $this->oConnection->method('send')
            ->willReturnCallback(function ($s) use (&$sSentAck) { $sSentAck = $s; });
        $this->oServices->method('getReplies')->willReturn([]);

        $this->oHandler->onMessage($this->oConnection, $this->makePktJson(iAunType: 5, iCb: 8));

        $aDecoded = json_decode($sSentAck, true);
        $aType = unpack('C', $aDecoded['payload']);
        $this->assertSame(6, $aType[1], 'Echo response must be ImmediateReply (type 6)');
    }

    // =========================================================================
    // Duplicate packet (dedup / sliding window)
    // =========================================================================

    public function testDuplicatePacketReacksButDoesNotDispatchToServices(): void
    {
        // First delivery — normal
        $this->oServices->method('getReplies')->willReturn([]);
        $this->oConnection->method('send');
        $this->oHandler->onMessage($this->oConnection, $this->makePktJson(iSeq: 10));

        // Second delivery with same seq — re-ack expected, but inboundPacket must NOT be called again
        $this->oServices->expects($this->never())->method('inboundPacket');
        $this->oHandler->onMessage($this->oConnection, $this->makePktJson(iSeq: 10));
    }

    public function testDuplicatePacketStillSendsAck(): void
    {
        $this->oServices->method('getReplies')->willReturn([]);

        $iSendCount = 0;
        $this->oConnection->method('send')
            ->willReturnCallback(function () use (&$iSendCount) { $iSendCount++; });

        $this->oHandler->onMessage($this->oConnection, $this->makePktJson(iSeq: 20));
        $this->oHandler->onMessage($this->oConnection, $this->makePktJson(iSeq: 20));

        // Two send() calls: one for the original, one re-ack for the duplicate
        $this->assertSame(2, $iSendCount);
    }

    public function testNewSeqAfterDuplicateIsDispatchedNormally(): void
    {
        $this->oServices->method('getReplies')->willReturn([]);
        $this->oConnection->method('send');

        $this->oHandler->onMessage($this->oConnection, $this->makePktJson(iSeq: 30));
        $this->oHandler->onMessage($this->oConnection, $this->makePktJson(iSeq: 30)); // duplicate

        // A distinct seq must still be dispatched
        $this->oServices->expects($this->once())->method('inboundPacket');
        $this->oHandler->onMessage($this->oConnection, $this->makePktJson(iSeq: 31));
    }

    public function testWindowEvictsOldestSeqAfterFull(): void
    {
        $this->oServices->method('getReplies')->willReturn([]);
        $this->oConnection->method('send');

        // Fill the 8-entry window with seqs 1..8
        for ($i = 1; $i <= 8; $i++) {
            $this->oHandler->onMessage($this->oConnection, $this->makePktJson(iSeq: $i));
        }

        // Seq 9 pushes seq 1 out of the window — seq 1 is no longer a duplicate
        $this->oHandler->onMessage($this->oConnection, $this->makePktJson(iSeq: 9));

        // Re-sending seq 1 must be treated as a new packet and dispatched
        $this->oServices->expects($this->once())->method('inboundPacket');
        $this->oHandler->onMessage($this->oConnection, $this->makePktJson(iSeq: 1));
    }

    public function testSeqWindowIsClearedOnClose(): void
    {
        // Use reflection to verify aSeqWindows is pruned after onClose
        $this->oServices->method('getReplies')->willReturn([]);
        $this->oConnection->method('send');

        $this->oHandler->onOpen($this->oConnection);
        $this->oHandler->onMessage($this->oConnection, $this->makePktJson(iSeq: 99));
        $this->oHandler->onClose($this->oConnection);

        $rp = new \ReflectionProperty(Handler::class, 'aSeqWindows');
        $rp->setAccessible(true);
        $this->assertEmpty($rp->getValue($this->oHandler));
    }

    public function testTwoConnectionsHaveIndependentSeqWindows(): void
    {
        $oConn2 = $this->createMock(ConnectionInterface::class);

        $this->oServices->method('getReplies')->willReturn([]);
        $this->oConnection->method('send');
        $oConn2->method('send');

        // Conn1 sends seq 5 — recorded in its window
        $this->oHandler->onOpen($this->oConnection);
        $this->oHandler->onMessage($this->oConnection, $this->makePktJson(iSeq: 5));

        // Conn2 sends the same seq 5 — different window, must be treated as new
        $this->oHandler->onOpen($oConn2);
        $this->oServices->expects($this->once())->method('inboundPacket');
        $this->oHandler->onMessage($oConn2, $this->makePktJson(iSeq: 5));
    }

    // =========================================================================
    // Transit forwarding — packets addressed to a non-local destination
    // =========================================================================

    public function testTransitPacketIsForwardedViaPacketDispatcher(): void
    {
        $this->oHandler->onOpen($this->oConnection);
        $this->oConnection->method('send');

        $this->oPacketDispatcher->expects($this->once())
            ->method('sendPacket')
            ->with($this->isInstanceOf(EconetPacket::class));

        // Destination is network 5, station 10 — not the local WebSocket station
        $this->oHandler->onMessage($this->oConnection, $this->makePktJson(iDstNet: 5, iDstStn: 10));
    }

    public function testTransitPacketIsAckedToSender(): void
    {
        $this->oHandler->onOpen($this->oConnection);

        $this->oConnection->expects($this->once())->method('send');

        $this->oHandler->onMessage($this->oConnection, $this->makePktJson(iDstNet: 5, iDstStn: 10));
    }

    public function testDuplicateTransitPacketIsNotForwardedTwice(): void
    {
        $this->oHandler->onOpen($this->oConnection);
        $this->oConnection->method('send');

        // sendPacket must be called exactly once across two identical seq numbers
        $this->oPacketDispatcher->expects($this->once())->method('sendPacket');

        $sMsg = $this->makePktJson(iDstNet: 5, iDstStn: 10, iSeq: 77);
        $this->oHandler->onMessage($this->oConnection, $sMsg);
        $this->oHandler->onMessage($this->oConnection, $sMsg);
    }

    public function testLocalPacketIsNotForwardedViaPacketDispatcher(): void
    {
        $this->oHandler->onOpen($this->oConnection);
        $this->oConnection->method('send');
        $this->oServices->method('getReplies')->willReturn([]);

        // sendPacket must never be called for a packet addressed to the local station
        $this->oPacketDispatcher->expects($this->never())->method('sendPacket');

        $this->oHandler->onMessage($this->oConnection, $this->makePktJson(
            iDstNet: self::WS_NET,
            iDstStn: self::WS_STN,
        ));
    }
}
