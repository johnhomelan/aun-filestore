<?php

/*
 * @group unit-tests
 *
 * Unit tests for HomeLan\FileStore\Piconet\Handler.
 *
 * React\Socket\ConnectionInterface is replaced by MockPiconetConnection, which
 * wraps a real php://temp stream so that Handler's fwrite($conn->stream, ...)
 * calls can be captured.  ServiceDispatcher and PacketDispatcher are standard
 * PHPUnit mocks — their constructors are not invoked.
 */

include_once(__DIR__ . '/../../src/include/system.inc.php');
include_once(__DIR__ . '/MockPiconetConnection.php');

use PHPUnit\Framework\TestCase;
use Monolog\Logger;
use Monolog\Handler\NullHandler;
use HomeLan\FileStore\Piconet\Handler;
use HomeLan\FileStore\Services\ServiceDispatcher;
use HomeLan\FileStore\Encapsulation\PacketDispatcher;
use HomeLan\FileStore\Messages\EconetPacket;
use React\EventLoop\LoopInterface;

class PiconetHandlerTest extends TestCase
{
    private Logger $oLogger;
    private ServiceDispatcher $oServices;
    private PacketDispatcher $oPacketDispatcher;
    private Handler $oHandler;

    protected function setUp(): void
    {
        $this->oLogger = new Logger('piconet-test');
        $this->oLogger->pushHandler(new NullHandler());

        $this->oServices = $this->createMock(ServiceDispatcher::class);
        $this->oPacketDispatcher = $this->createMock(PacketDispatcher::class);

        $this->oHandler = new Handler($this->oLogger, $this->oServices, $this->oPacketDispatcher);

        config::overrideValue('piconet_station', 5);
        config::overrideValue('piconet_local_network', 1);
        config::overrideValue('remote_bridge_enabled', 0);
    }

    protected function tearDown(): void
    {
        config::resetValue('piconet_station');
        config::resetValue('piconet_local_network');
        config::resetValue('remote_bridge_enabled');
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function getProp(string $sName): mixed
    {
        $rp = new \ReflectionProperty(Handler::class, $sName);
        $rp->setAccessible(true);
        return $rp->getValue($this->oHandler);
    }

    private function setProp(string $sName, mixed $value): void
    {
        $rp = new \ReflectionProperty(Handler::class, $sName);
        $rp->setAccessible(true);
        $rp->setValue($this->oHandler, $value);
    }

    private function makePacket(int $iDstStation = 5, int $iDstNetwork = 1, int $iPort = 0x99, int $iFlags = 0, string $sData = 'hello'): EconetPacket
    {
        $oPacket = new EconetPacket();
        $oPacket->setDestinationStation($iDstStation);
        $oPacket->setDestinationNetwork($iDstNetwork);
        $oPacket->setPort($iPort);
        $oPacket->setFlags($iFlags);
        $oPacket->setData($sData);
        return $oPacket;
    }

    /**
     * Build a valid piconet RX_BROADCAST message string.
     *
     * Scout frame layout: |DstStn|DstNet|SrcStn|SrcNet|Cb|Port| + 8 bytes data.
     */
    private function makeBroadcastMsg(
        int $iDstStn = 255, int $iDstNet = 0,
        int $iSrcStn = 1,   int $iSrcNet = 0,
        int $iCb = 0,       int $iPort = 0x99
    ): string {
        $sScout = pack('CCCCCC', $iDstStn, $iDstNet, $iSrcStn, $iSrcNet, $iCb, $iPort)
                . str_repeat("\x00", 8);
        return 'RX_BROADCAST ' . base64_encode($sScout);
    }

    /**
     * Build a valid piconet RX_TRANSMIT message string.
     *
     * Data frame layout: 4-byte header (ignored by decoder) + payload.
     */
    private function makeTransmitMsg(
        int    $iDstStn = 5,  int $iDstNet = 0,
        int    $iSrcStn = 1,  int $iSrcNet = 0,
        int    $iCb = 0,      int $iPort = 0x99,
        string $sPayload = 'hi'
    ): string {
        $sScout = pack('CCCCCC', $iDstStn, $iDstNet, $iSrcStn, $iSrcNet, $iCb, $iPort);
        $sData  = pack('CCCC', 0, 0, 0, 0) . $sPayload;
        return 'RX_TRANSMIT ' . base64_encode($sScout) . ' ' . base64_encode($sData);
    }

    // =========================================================================
    // Initial state
    // =========================================================================

    public function testInitiallyNotConnected(): void
    {
        $this->assertFalse($this->getProp('bConnected'));
    }

    public function testInitialReconnectDelayIsMinimum(): void
    {
        $this->assertSame(Handler::RECONNECT_DELAY_MIN, $this->getProp('iReconnectDelay'));
    }

    public function testInitialQueuesAreEmpty(): void
    {
        $this->assertEmpty($this->getProp('aQueue'));
        $this->assertEmpty($this->getProp('aAwaitingAck'));
    }

    // =========================================================================
    // onOpen()
    // =========================================================================

    public function testOnOpenSetsConnectedFlag(): void
    {
        $oConn = new MockPiconetConnection();
        $this->oHandler->onOpen($oConn);
        $this->assertTrue($this->getProp('bConnected'));
    }

    public function testOnOpenStoresConnectionReference(): void
    {
        $oConn = new MockPiconetConnection();
        $this->oHandler->onOpen($oConn);
        $this->assertSame($oConn, $this->getProp('oConnection'));
    }

    public function testOnOpenResetsReconnectDelayToMinimum(): void
    {
        $this->setProp('iReconnectDelay', Handler::RECONNECT_DELAY_MAX);
        $oConn = new MockPiconetConnection();
        $this->oHandler->onOpen($oConn);
        $this->assertSame(Handler::RECONNECT_DELAY_MIN, $this->getProp('iReconnectDelay'));
    }

    // =========================================================================
    // onConnect() / bringupInterface()
    // =========================================================================

    public function testOnConnectWritesStatusCommand(): void
    {
        $oConn = new MockPiconetConnection();
        $this->oHandler->onOpen($oConn);
        $this->oHandler->onConnect();
        $this->assertStringContainsString("STATUS\r\r", $oConn->getStreamContent());
    }

    public function testOnConnectCallsBringupInterface(): void
    {
        $oConn = new MockPiconetConnection();
        $this->oHandler->onOpen($oConn);
        $this->oHandler->onConnect();
        // bringupInterface writes SET_STATION — verify it was called
        $this->assertStringContainsString("SET_STATION", $oConn->getStreamContent());
    }

    public function testBringupInterfaceWritesSetStationWithConfiguredStation(): void
    {
        $oConn = new MockPiconetConnection();
        $this->oHandler->onOpen($oConn);
        $this->oHandler->bringupInterface();
        $this->assertStringContainsString("SET_STATION 5\r\r", $oConn->getStreamContent());
    }

    public function testBringupInterfaceWritesSetModeListenWhenNoBridge(): void
    {
        $oConn = new MockPiconetConnection();
        $this->oHandler->onOpen($oConn);
        $this->oHandler->bringupInterface();
        $sContent = $oConn->getStreamContent();
        $this->assertStringContainsString("SET_MODE LISTEN\r\r", $sContent);
        $this->assertStringNotContainsString("SET_MODE MONITOR", $sContent);
    }

    public function testBringupInterfaceWritesSetModeMonitorWhenRemoteBridgeEnabled(): void
    {
        config::overrideValue('remote_bridge_enabled', 1);
        $oConn = new MockPiconetConnection();
        $this->oHandler->onOpen($oConn);
        $this->oHandler->bringupInterface();
        $sContent = $oConn->getStreamContent();
        $this->assertStringContainsString("SET_MODE MONITOR\r\r", $sContent);
        $this->assertStringNotContainsString("SET_MODE LISTEN", $sContent);
    }

    // =========================================================================
    // onClose()
    // =========================================================================

    public function testOnCloseSetsNotConnected(): void
    {
        $oConn = new MockPiconetConnection();
        $this->oHandler->onOpen($oConn);
        $this->oHandler->onClose();
        $this->assertFalse($this->getProp('bConnected'));
    }

    public function testOnCloseSendsStopModeViaReactWrite(): void
    {
        $oConn = new MockPiconetConnection();
        $this->oHandler->onOpen($oConn);
        $this->oHandler->onClose();
        // onClose() uses the React interface write(), not fwrite() on stream
        $this->assertSame("SET_MODE STOP\r\r", $oConn->allWritten());
    }

    public function testOnCloseClearsAwaitingAckCallsServiceClearAckEvent(): void
    {
        $this->oServices->expects($this->exactly(2))
            ->method('clearAckEvent');

        $this->setProp('aAwaitingAck', [
            ['dst_network' => 1, 'dst_station' => 5, 'port' => 0x99, 'flags' => 0],
            ['dst_network' => 1, 'dst_station' => 7, 'port' => 0x99, 'flags' => 0],
        ]);

        $oConn = new MockPiconetConnection();
        $this->oHandler->onOpen($oConn);
        $this->oHandler->onClose();

        $this->assertEmpty($this->getProp('aAwaitingAck'));
    }

    public function testOnCloseClearsQueuedPacketsClearingAckEvents(): void
    {
        $this->oServices->expects($this->exactly(2))
            ->method('clearAckEvent');

        $this->setProp('aQueue', [
            ['packet' => $this->makePacket(5, 1), 'retries' => 3, 'attempts' => 0],
            ['packet' => $this->makePacket(7, 1), 'retries' => 3, 'attempts' => 0],
        ]);

        $oConn = new MockPiconetConnection();
        $this->oHandler->onOpen($oConn);
        $this->oHandler->onClose();

        $this->assertEmpty($this->getProp('aQueue'));
    }

    public function testOnCloseSchedulesReconnectViaLoop(): void
    {
        $oLoop = $this->createMock(LoopInterface::class);
        $oLoop->expects($this->once())->method('addTimer');
        $this->oHandler->setLoop($oLoop);
        $this->oHandler->setReconnectCallback(function () {});

        $oConn = new MockPiconetConnection();
        $this->oHandler->onOpen($oConn);
        $this->oHandler->onClose();
    }

    public function testOnCloseNullsConnectionReference(): void
    {
        $oConn = new MockPiconetConnection();
        $this->oHandler->onOpen($oConn);
        $this->oHandler->onClose();
        $this->assertNull($this->getProp('oConnection'));
    }

    public function testOnCloseIsIdempotentWhenCalledTwice(): void
    {
        $oConn = new MockPiconetConnection();
        $this->oHandler->onOpen($oConn);
        $this->oHandler->onClose();
        // Second close — connection is null; must not throw
        $this->oHandler->onClose();
        $this->assertTrue(true);
    }

    // =========================================================================
    // scheduleReconnect()
    // =========================================================================

    public function testScheduleReconnectDoesNothingWithoutLoop(): void
    {
        $this->oHandler->scheduleReconnect();
        $this->assertTrue(true);
    }

    public function testScheduleReconnectDoesNothingWithoutCallback(): void
    {
        $oLoop = $this->createMock(LoopInterface::class);
        $oLoop->expects($this->never())->method('addTimer');
        $this->oHandler->setLoop($oLoop);
        // No setReconnectCallback — timer must NOT be added
        $this->oHandler->scheduleReconnect();
    }

    public function testScheduleReconnectAddsTimerWithCurrentDelay(): void
    {
        $oLoop = $this->createMock(LoopInterface::class);
        $oLoop->expects($this->once())
              ->method('addTimer')
              ->with(Handler::RECONNECT_DELAY_MIN, $this->anything());
        $this->oHandler->setLoop($oLoop);
        $this->oHandler->setReconnectCallback(function () {});

        $this->oHandler->scheduleReconnect();
    }

    public function testScheduleReconnectDoublesDelayOnEachCall(): void
    {
        $oLoop = $this->createMock(LoopInterface::class);
        $this->oHandler->setLoop($oLoop);
        $this->oHandler->setReconnectCallback(function () {});

        $this->oHandler->scheduleReconnect();
        $this->assertSame(Handler::RECONNECT_DELAY_MIN * 2, $this->getProp('iReconnectDelay'));

        $this->oHandler->scheduleReconnect();
        $this->assertSame(Handler::RECONNECT_DELAY_MIN * 4, $this->getProp('iReconnectDelay'));
    }

    public function testScheduleReconnectCapsDelayAtMaximum(): void
    {
        $this->setProp('iReconnectDelay', Handler::RECONNECT_DELAY_MAX);
        $oLoop = $this->createMock(LoopInterface::class);
        $this->oHandler->setLoop($oLoop);
        $this->oHandler->setReconnectCallback(function () {});

        $this->oHandler->scheduleReconnect();
        $this->assertSame(Handler::RECONNECT_DELAY_MAX, $this->getProp('iReconnectDelay'));
    }

    // =========================================================================
    // send()
    // =========================================================================

    public function testSendDropsPacketWhenNotConnected(): void
    {
        $this->oHandler->send($this->makePacket());
        $this->assertEmpty($this->getProp('aQueue'));
        $this->assertEmpty($this->getProp('aAwaitingAck'));
    }

    public function testSendBroadcastWritesBcastCommandToStream(): void
    {
        $oConn = new MockPiconetConnection();
        $this->oHandler->onOpen($oConn);

        $oPacket = $this->makePacket(255, 1, 0x99, 0, 'data');
        $this->oHandler->send($oPacket);

        $sStream = $oConn->getStreamContent();
        $this->assertStringStartsWith('BCAST ', $sStream);
        $this->assertStringContainsString(base64_encode('data'), $sStream);
    }

    public function testSendBroadcastDoesNotAddToAwaitingAck(): void
    {
        $oConn = new MockPiconetConnection();
        $this->oHandler->onOpen($oConn);

        $this->oHandler->send($this->makePacket(255, 1));

        // Broadcasts do not require a scout ack
        $this->assertEmpty($this->getProp('aAwaitingAck'));
    }

    public function testSendUnicastWritesTxCommandWithStation(): void
    {
        $oConn = new MockPiconetConnection();
        $this->oHandler->onOpen($oConn);

        $this->oHandler->send($this->makePacket(7, 1, 0x99, 0));

        $this->assertMatchesRegularExpression('/^TX 7 /', $oConn->getStreamContent());
    }

    public function testSendUnicastTranslatesLocalNetworkToZero(): void
    {
        // piconet_local_network=1 → TX must use 0 as the wire network number
        $oConn = new MockPiconetConnection();
        $this->oHandler->onOpen($oConn);

        $this->oHandler->send($this->makePacket(5, 1, 0x99, 0));

        $this->assertMatchesRegularExpression('/^TX 5 0 /', $oConn->getStreamContent());
    }

    public function testSendUnicastPreservesNonLocalNetworkNumber(): void
    {
        $oConn = new MockPiconetConnection();
        $this->oHandler->onOpen($oConn);

        $this->oHandler->send($this->makePacket(5, 2, 0x99, 0));

        $this->assertMatchesRegularExpression('/^TX 5 2 /', $oConn->getStreamContent());
    }

    public function testSendUnicastIncludesPortAndFlagsInTxCommand(): void
    {
        $oConn = new MockPiconetConnection();
        $this->oHandler->onOpen($oConn);

        $this->oHandler->send($this->makePacket(5, 1, 0xAB, 0x03));

        // TX <station> <net> <flags> <port> <base64data>
        $this->assertMatchesRegularExpression('/^TX 5 0 3 171 /', $oConn->getStreamContent());
    }

    public function testSendUnicastAddsEntryToAwaitingAck(): void
    {
        $oConn = new MockPiconetConnection();
        $this->oHandler->onOpen($oConn);

        $this->oHandler->send($this->makePacket(5, 1, 0x99, 2));

        $aAwaitingAck = $this->getProp('aAwaitingAck');
        $this->assertCount(1, $aAwaitingAck);
        $this->assertSame(5,    $aAwaitingAck[0]['dst_station']);
        $this->assertSame(1,    $aAwaitingAck[0]['dst_network']);
        $this->assertSame(0x99, $aAwaitingAck[0]['port']);
        $this->assertSame(2,    $aAwaitingAck[0]['flags']);
    }

    public function testSecondSendIsQueuedWhileFirstIsInFlight(): void
    {
        $oConn = new MockPiconetConnection();
        $this->oHandler->onOpen($oConn);

        $this->oHandler->send($this->makePacket(5, 1));
        $this->oHandler->send($this->makePacket(7, 1));

        // Queue holds: current-packet-for-retry + waiting-packet
        $aQueue = $this->getProp('aQueue');
        $this->assertCount(2, $aQueue);
    }

    public function testSecondSendIsNotWrittenUntilFirstAcked(): void
    {
        $oConn = new MockPiconetConnection();
        $this->oHandler->onOpen($oConn);

        $this->oHandler->send($this->makePacket(5, 1, 0x99, 0, 'pkt1'));
        $sAfterFirst = $oConn->getStreamContent();

        $this->oHandler->send($this->makePacket(7, 1, 0x99, 0, 'pkt2'));
        $sAfterSecond = $oConn->getStreamContent();

        // Stream must not change — pkt2 is waiting for pkt1's ack
        $this->assertSame($sAfterFirst, $sAfterSecond);
    }

    // =========================================================================
    // decodeMessage() — malformed / unknown message types
    // =========================================================================

    public function testMalformedRxPacketDoesNotDispatchToServices(): void
    {
        // Invalid base64 in the scout field — decode() throws; packet must be discarded
        $this->oServices->expects($this->never())->method('inboundPacket');
        $this->oHandler->decodeMessage('RX_TRANSMIT !!!!notbase64!!!! !!!!notbase64!!!!');
    }

    public function testMalformedPacketLineDoesNotAbortSubsequentLines(): void
    {
        // A bad line in onMessage() must not stop subsequent valid lines from being processed
        $this->oServices->expects($this->once())->method('inboundPacket');
        $this->oServices->method('getReplies')->willReturn([]);

        $sBadLine = 'RX_TRANSMIT !!!!notbase64!!!!';
        $this->oHandler->onMessage($sBadLine . "\n" . $this->makeBroadcastMsg());
    }

    public function testUnknownMessageTypeIsIgnoredWithoutDispatching(): void
    {
        $this->oServices->expects($this->never())->method('inboundPacket');
        $this->oHandler->decodeMessage('SOME_FUTURE_MESSAGE payload=xyz');
    }

    public function testRxTransmitMissingDataFieldIsDiscarded(): void
    {
        // Scout-only RX_TRANSMIT — no data field at all
        $sScout = base64_encode(pack('CCCCCC', 5, 0, 1, 0, 0, 0x99));
        $this->oServices->expects($this->never())->method('inboundPacket');
        $this->oHandler->decodeMessage('RX_TRANSMIT ' . $sScout);
    }

    public function testShortScoutFrameIsDiscarded(): void
    {
        // Base64 of a 5-byte buffer — one byte short of the 6-byte minimum
        $sShortScout = base64_encode(str_repeat("\x00", 5));
        $sData       = base64_encode(str_repeat("\x00", 8));
        $this->oServices->expects($this->never())->method('inboundPacket');
        $this->oHandler->decodeMessage('RX_TRANSMIT ' . $sShortScout . ' ' . $sData);
    }

    public function testTxResultMissingStatusCodeIsHandledSafely(): void
    {
        // "TX_RESULT" with no code — must not crash or clear ack events
        $this->oServices->expects($this->never())->method('clearAckEvent');
        $this->oHandler->decodeMessage('TX_RESULT');
    }

    public function testTxResultUnknownCodeIsIgnored(): void
    {
        $this->oServices->expects($this->never())->method('clearAckEvent');
        $this->oHandler->decodeMessage('TX_RESULT NEW_FUTURE_CODE');
    }

    // =========================================================================
    // TX_RESULT with empty aAwaitingAck (spurious messages)
    // =========================================================================

    public function testDecodeTxResultOkWithEmptyAwaitingAckIsNoOp(): void
    {
        $this->oServices->expects($this->never())->method('clearAckEvent');
        $this->oServices->expects($this->never())->method('inboundPacket');
        $this->oHandler->decodeMessage('TX_RESULT OK');
    }

    public function testDecodeTxResultTimeoutWithEmptyAwaitingAckIsNoOp(): void
    {
        $this->oServices->expects($this->never())->method('clearAckEvent');
        $this->oHandler->decodeMessage('TX_RESULT TIMEOUT');
    }

    public function testDecodeTxResultUnexpectedWithEmptyAwaitingAckIsNoOp(): void
    {
        $this->oServices->expects($this->never())->method('clearAckEvent');
        $this->oHandler->decodeMessage('TX_RESULT UNEXPECTED');
    }

    // =========================================================================
    // decodeMessage()
    // =========================================================================

    public function testDecodeStatusIsNoOp(): void
    {
        $this->oServices->expects($this->never())->method('inboundPacket');
        $this->oHandler->decodeMessage('STATUS 1.0 OK');
    }

    public function testDecodeErrorIsNoOp(): void
    {
        $this->oServices->expects($this->never())->method('inboundPacket');
        $this->oHandler->decodeMessage('ERROR Something went wrong');
    }

    public function testDecodeMonitorIsNoOp(): void
    {
        $this->oServices->expects($this->never())->method('inboundPacket');
        $this->oHandler->decodeMessage('MONITOR');
    }

    public function testDecodeRxBroadcastDispatchesInboundPacket(): void
    {
        $this->oServices->expects($this->once())->method('inboundPacket');
        $this->oServices->method('getReplies')->willReturn([]);
        $this->oHandler->decodeMessage($this->makeBroadcastMsg());
    }

    public function testDecodeRxBroadcastForwardsRepliesToPacketDispatcher(): void
    {
        $oReply = $this->makePacket();
        $this->oServices->method('getReplies')->willReturn([$oReply]);
        $this->oPacketDispatcher->expects($this->once())
            ->method('sendPacket')
            ->with($oReply);

        $this->oHandler->decodeMessage($this->makeBroadcastMsg());
    }

    public function testDecodeRxTransmitDispatchesInboundPacket(): void
    {
        $this->oServices->expects($this->once())->method('inboundPacket');
        $this->oServices->method('getReplies')->willReturn([]);
        $this->oHandler->decodeMessage($this->makeTransmitMsg());
    }

    public function testDecodeRxTransmitBuildsCorrectEconetPacket(): void
    {
        $oCaptured = null;
        $this->oServices->method('inboundPacket')
            ->willReturnCallback(function ($oPkt) use (&$oCaptured) { $oCaptured = $oPkt; });
        $this->oServices->method('getReplies')->willReturn([]);

        // SrcStn=3, SrcNet=0 (local → becomes 1), port=0x88, DstStn=5
        $sMsg = $this->makeTransmitMsg(5, 0, 3, 0, 0, 0x88);
        $this->oHandler->decodeMessage($sMsg);

        $this->assertNotNull($oCaptured);
        $oEconet = $oCaptured->buildEconetPacket();
        $this->assertSame(3,    $oEconet->getSourceStation());
        $this->assertSame(1,    $oEconet->getSourceNetwork()); // 0 → local_network=1
        $this->assertSame(0x88, $oEconet->getPort());
    }

    public function testDecodeRxTransmitForwardsRepliesToPacketDispatcher(): void
    {
        $oReply = $this->makePacket();
        $this->oServices->method('getReplies')->willReturn([$oReply]);
        $this->oPacketDispatcher->expects($this->once())
            ->method('sendPacket')
            ->with($oReply);

        $this->oHandler->decodeMessage($this->makeTransmitMsg());
    }

    public function testDecodeTxResultOkShiftsAwaitingAck(): void
    {
        $this->setProp('aAwaitingAck', [
            ['dst_network' => 1, 'dst_station' => 5, 'port' => 0x99, 'flags' => 0],
        ]);
        $this->oServices->method('getReplies')->willReturn([]);

        $this->oHandler->decodeMessage('TX_RESULT OK');

        $this->assertEmpty($this->getProp('aAwaitingAck'));
    }

    public function testDecodeTxResultOkDispatchesAckPacketToServices(): void
    {
        $this->setProp('aAwaitingAck', [
            ['dst_network' => 1, 'dst_station' => 5, 'port' => 0x99, 'flags' => 0],
        ]);
        $this->oServices->expects($this->once())->method('inboundPacket');
        $this->oServices->method('getReplies')->willReturn([]);

        $this->oHandler->decodeMessage('TX_RESULT OK');
    }

    public function testDecodeTxResultOkTriggersTransmitOfNextQueuedPacket(): void
    {
        $oConn = new MockPiconetConnection();
        $this->oHandler->onOpen($oConn);

        // Queue two packets; TX OK for the first should release the second
        $this->oHandler->send($this->makePacket(5, 1, 0x99, 0, 'pkt1'));
        $this->oHandler->send($this->makePacket(7, 1, 0x99, 0, 'pkt2'));

        $this->oServices->method('getReplies')->willReturn([]);

        $this->oHandler->decodeMessage('TX_RESULT OK');

        // Both "TX 5 ..." and "TX 7 ..." should now be in the stream
        $sStream = $oConn->getStreamContent();
        $this->assertStringContainsString('TX 5', $sStream);
        $this->assertStringContainsString('TX 7', $sStream);
    }

    public function testDecodeTxResultTimeoutRetriesPacket(): void
    {
        $oConn = new MockPiconetConnection();
        $this->oHandler->onOpen($oConn);

        $this->oHandler->send($this->makePacket(5, 1));
        $sAfterFirstSend = $oConn->getStreamContent();

        $this->oHandler->decodeMessage('TX_RESULT TIMEOUT');
        $sAfterRetry = $oConn->getStreamContent();

        $this->assertGreaterThan(strlen($sAfterFirstSend), strlen($sAfterRetry));
    }

    public function testDecodeTxResultTimeoutClearsAckEventWhenRetriesExhausted(): void
    {
        $oConn = new MockPiconetConnection();
        $this->oHandler->onOpen($oConn);

        // retries=0: packet fires once, then first TIMEOUT exhausts all attempts
        $this->oHandler->send($this->makePacket(5, 1), 0);

        $this->oServices->expects($this->once())
            ->method('clearAckEvent')
            ->with(1, 5);

        $this->oHandler->decodeMessage('TX_RESULT TIMEOUT');

        $this->assertEmpty($this->getProp('aQueue'));
    }

    public function testDecodeTxResultLineJammedRetriesPacket(): void
    {
        $oConn = new MockPiconetConnection();
        $this->oHandler->onOpen($oConn);

        $this->oHandler->send($this->makePacket(5, 1));
        $sAfterSend = $oConn->getStreamContent();

        $this->oHandler->decodeMessage('TX_RESULT LINE_JAMMED');

        $this->assertGreaterThan(strlen($sAfterSend), strlen($oConn->getStreamContent()));
    }

    public function testDecodeTxResultNoScoutAckRetriesPacket(): void
    {
        $oConn = new MockPiconetConnection();
        $this->oHandler->onOpen($oConn);

        $this->oHandler->send($this->makePacket(5, 1));
        $sAfterSend = $oConn->getStreamContent();

        $this->oHandler->decodeMessage('TX_RESULT NO_SCOUT_ACK');

        $this->assertGreaterThan(strlen($sAfterSend), strlen($oConn->getStreamContent()));
    }

    public function testDecodeTxResultNoDataAckRetriesPacket(): void
    {
        $oConn = new MockPiconetConnection();
        $this->oHandler->onOpen($oConn);

        $this->oHandler->send($this->makePacket(5, 1));
        $sAfterSend = $oConn->getStreamContent();

        $this->oHandler->decodeMessage('TX_RESULT NO_DATA_ACK');

        $this->assertGreaterThan(strlen($sAfterSend), strlen($oConn->getStreamContent()));
    }

    public function testDecodeTxResultOverflowRetriesPacket(): void
    {
        $oConn = new MockPiconetConnection();
        $this->oHandler->onOpen($oConn);

        $this->oHandler->send($this->makePacket(5, 1));
        $sAfterSend = $oConn->getStreamContent();

        $this->oHandler->decodeMessage('TX_RESULT OVERFLOW');

        $this->assertGreaterThan(strlen($sAfterSend), strlen($oConn->getStreamContent()));
    }

    public function testDecodeTxResultUnexpectedClearsAckEventImmediately(): void
    {
        $this->setProp('aAwaitingAck', [
            ['dst_network' => 1, 'dst_station' => 5, 'port' => 0x99, 'flags' => 0],
        ]);
        $this->oServices->expects($this->once())
            ->method('clearAckEvent')
            ->with(1, 5);

        $this->oHandler->decodeMessage('TX_RESULT UNEXPECTED');
    }

    public function testDecodeTxResultUnexpectedWithNoAckIsNoOp(): void
    {
        // No aAwaitingAck entries — UNEXPECTED with no ack to clear must not crash
        $this->oServices->expects($this->never())->method('clearAckEvent');
        $this->oHandler->decodeMessage('TX_RESULT UNEXPECTED');
    }

    public function testDecodeTxResultMiscClearsAckEventWhenNoMoreRetries(): void
    {
        $oConn = new MockPiconetConnection();
        $this->oHandler->onOpen($oConn);

        $this->oHandler->send($this->makePacket(5, 1), 0);

        $this->oServices->expects($this->once())
            ->method('clearAckEvent')
            ->with(1, 5);

        $this->oHandler->decodeMessage('TX_RESULT MISC');
    }

    // =========================================================================
    // onMessage()
    // =========================================================================

    public function testOnMessageSplitsOnNewlineAndProcessesEachLine(): void
    {
        $this->oServices->expects($this->never())->method('inboundPacket');
        $this->oHandler->onMessage("STATUS 1.0\nERROR test\n");
        $this->assertTrue(true);
    }

    public function testOnMessageWithSingleLine(): void
    {
        $this->oHandler->onMessage('STATUS 1.0');
        $this->assertTrue(true);
    }

    public function testOnMessageDispatchesBroadcastFromMultiLinePayload(): void
    {
        $sBroadcast = $this->makeBroadcastMsg();
        $this->oServices->expects($this->once())->method('inboundPacket');
        $this->oServices->method('getReplies')->willReturn([]);

        $this->oHandler->onMessage("STATUS 1.0\n" . $sBroadcast . "\n");
    }

    // =========================================================================
    // onError()
    // =========================================================================

    public function testOnErrorDoesNotThrow(): void
    {
        $this->oHandler->onError(new \Exception('Device error'));
        $this->assertTrue(true);
    }

    // =========================================================================
    // Monitor mode (remote_bridge_enabled)
    // =========================================================================

    public function testMonitorModeIgnoresRxTransmitToOtherLocalStation(): void
    {
        config::overrideValue('remote_bridge_enabled', 1);
        $oConn = new MockPiconetConnection();
        $this->oHandler->onOpen($oConn);

        // Station 7, local network (net=0) — our station is 5 → must be ignored
        $sMsg = $this->makeTransmitMsg(7, 0, 1, 0, 0, 0x99);
        $this->oServices->expects($this->never())->method('inboundPacket');

        $this->oHandler->decodeMessage($sMsg);
    }

    public function testMonitorModePassesRxTransmitToOurStation(): void
    {
        config::overrideValue('remote_bridge_enabled', 1);
        config::overrideValue('piconet_station', 5);
        $oConn = new MockPiconetConnection();
        $this->oHandler->onOpen($oConn);

        // Station 5 on local network — our station → must reach services
        $sMsg = $this->makeTransmitMsg(5, 0, 1, 0, 0, 0x99);
        $this->oServices->expects($this->once())->method('inboundPacket');
        $this->oServices->method('getReplies')->willReturn([]);

        $this->oHandler->decodeMessage($sMsg);
    }

    public function testMonitorModePassesBroadcastStationToServices(): void
    {
        config::overrideValue('remote_bridge_enabled', 1);
        $oConn = new MockPiconetConnection();
        $this->oHandler->onOpen($oConn);

        // Station 255 on local network is a broadcast — must reach services
        $sMsg = $this->makeTransmitMsg(255, 0, 1, 0, 0, 0x99);
        $this->oServices->expects($this->once())->method('inboundPacket');
        $this->oServices->method('getReplies')->willReturn([]);

        $this->oHandler->decodeMessage($sMsg);
    }

    public function testMonitorModeForwardsNonLocalNetworkViaPacketDispatcher(): void
    {
        config::overrideValue('remote_bridge_enabled', 1);
        $oConn = new MockPiconetConnection();
        $this->oHandler->onOpen($oConn);

        // Network 99 — not local (not 0) → must be forwarded via PacketDispatcher
        $sMsg = $this->makeTransmitMsg(5, 99, 1, 0, 0, 0x99);
        $this->oPacketDispatcher->expects($this->once())
            ->method('sendPacket')
            ->with($this->isInstanceOf(\HomeLan\FileStore\Messages\EconetPacket::class));

        $this->oHandler->decodeMessage($sMsg);
    }

    public function testMonitorModeDoesNotCallInboundPacketForNonLocalNetwork(): void
    {
        config::overrideValue('remote_bridge_enabled', 1);
        $oConn = new MockPiconetConnection();
        $this->oHandler->onOpen($oConn);

        // Non-local network packet must not reach local services
        $sMsg = $this->makeTransmitMsg(5, 99, 1, 0, 0, 0x99);
        $this->oServices->expects($this->never())->method('inboundPacket');

        $this->oHandler->decodeMessage($sMsg);
    }

    public function testMonitorModeStillPassesRxBroadcastToServices(): void
    {
        // RX_BROADCAST is not affected by the unicast monitor filter
        config::overrideValue('remote_bridge_enabled', 1);
        $this->oServices->expects($this->once())->method('inboundPacket');
        $this->oServices->method('getReplies')->willReturn([]);

        $this->oHandler->decodeMessage($this->makeBroadcastMsg());
    }

    public function testMonitorModeDisabledPassesAllLocalUnicasts(): void
    {
        // With remote_bridge_enabled=0 the filter is not applied — all unicasts
        // go straight to services regardless of destination station.
        $oConn = new MockPiconetConnection();
        $this->oHandler->onOpen($oConn);

        $sMsg = $this->makeTransmitMsg(7, 0, 1, 0, 0, 0x99); // station 7, not ours
        $this->oServices->expects($this->once())->method('inboundPacket');
        $this->oServices->method('getReplies')->willReturn([]);

        $this->oHandler->decodeMessage($sMsg);
    }

    // =========================================================================
    // Retry backoff
    // =========================================================================

    public function testTxFailureSchedulesRetryViaLoopTimerWhenLoopAvailable(): void
    {
        $oLoop = $this->createMock(LoopInterface::class);
        $oLoop->expects($this->once())
            ->method('addTimer')
            ->with($this->greaterThan(0), $this->isType('callable'));
        $this->oHandler->setLoop($oLoop);

        $oConn = new MockPiconetConnection();
        $this->oHandler->onOpen($oConn);

        $this->oHandler->send($this->makePacket(5, 1));
        $this->oHandler->decodeMessage('TX_RESULT TIMEOUT');
    }

    public function testTxFailureRetriesImmediatelyWhenNoLoopAvailable(): void
    {
        // No loop set — falls back to immediate _runQueue(). Stream must grow.
        $oConn = new MockPiconetConnection();
        $this->oHandler->onOpen($oConn);

        $this->oHandler->send($this->makePacket(5, 1));
        $sAfterSend = $oConn->getStreamContent();

        $this->oHandler->decodeMessage('TX_RESULT TIMEOUT');

        $this->assertGreaterThan(strlen($sAfterSend), strlen($oConn->getStreamContent()));
    }

    public function testTxFailureWithLoopDoesNotRetryImmediately(): void
    {
        // When the loop handles the retry, the stream must NOT grow synchronously.
        $oLoop = $this->createMock(LoopInterface::class);
        $this->oHandler->setLoop($oLoop);

        $oConn = new MockPiconetConnection();
        $this->oHandler->onOpen($oConn);

        $this->oHandler->send($this->makePacket(5, 1));
        $sAfterSend = $oConn->getStreamContent();

        $this->oHandler->decodeMessage('TX_RESULT TIMEOUT');

        $this->assertSame($sAfterSend, $oConn->getStreamContent());
    }

    // =========================================================================
    // onClose() — duplicate clearAckEvent prevention
    // =========================================================================

    public function testOnCloseClearsAckEventOnceForInFlightPacket(): void
    {
        // An in-flight packet appears in both aAwaitingAck and at the front of
        // aQueue (with decremented retries). clearAckEvent must only fire once.
        $this->oServices->expects($this->once())
            ->method('clearAckEvent')
            ->with(1, 5);

        $this->setProp('aAwaitingAck', [
            ['dst_network' => 1, 'dst_station' => 5, 'port' => 0x99, 'flags' => 0],
        ]);
        $this->setProp('aQueue', [
            ['packet' => $this->makePacket(5, 1), 'retries' => 2, 'attempts' => 1],
        ]);

        $oConn = new MockPiconetConnection();
        $this->oHandler->onOpen($oConn);
        $this->oHandler->onClose();
    }

    public function testOnCloseClearsDistinctQueuedAndInFlightStationsSeparately(): void
    {
        // aAwaitingAck has station 5; aQueue has a different station 7 — two distinct clears
        $this->oServices->expects($this->exactly(2))->method('clearAckEvent');

        $this->setProp('aAwaitingAck', [
            ['dst_network' => 1, 'dst_station' => 5, 'port' => 0x99, 'flags' => 0],
        ]);
        $this->setProp('aQueue', [
            ['packet' => $this->makePacket(7, 1), 'retries' => 3, 'attempts' => 0],
        ]);

        $oConn = new MockPiconetConnection();
        $this->oHandler->onOpen($oConn);
        $this->oHandler->onClose();
    }

    // =========================================================================
    // Retry exhaustion / ack cleanup
    // =========================================================================

    public function testPacketRetriedDefaultThreeTimesBeforeGivingUp(): void
    {
        $oConn = new MockPiconetConnection();
        $this->oHandler->onOpen($oConn);

        $this->oHandler->send($this->makePacket(5, 1), 3);

        // Initial TX: 1 TX write
        // 3 x TIMEOUT retry: 3 more TX writes
        // Final TIMEOUT (retries exhausted): clearAckEvent
        $this->oServices->expects($this->once())->method('clearAckEvent');

        $this->oHandler->decodeMessage('TX_RESULT TIMEOUT');
        $this->oHandler->decodeMessage('TX_RESULT TIMEOUT');
        $this->oHandler->decodeMessage('TX_RESULT TIMEOUT');
        $this->oHandler->decodeMessage('TX_RESULT TIMEOUT');
    }

    public function testAckClearedAfterAllRetriesExhausted(): void
    {
        $oConn = new MockPiconetConnection();
        $this->oHandler->onOpen($oConn);

        $this->oHandler->send($this->makePacket(5, 1), 2);

        // Exhaust 3 total attempts (retries=2 → initial + 2 retries + 1 final fail)
        $this->oHandler->decodeMessage('TX_RESULT TIMEOUT');
        $this->oHandler->decodeMessage('TX_RESULT TIMEOUT');
        $this->oHandler->decodeMessage('TX_RESULT TIMEOUT');

        // After all retries: queue and awaitingAck must be empty
        $this->assertEmpty($this->getProp('aQueue'));
        $this->assertEmpty($this->getProp('aAwaitingAck'));
    }
}
