<?php

/*
 * @group unit-tests
 */

use PHPUnit\Framework\TestCase;
use Monolog\Logger;
use Monolog\Handler\NullHandler;
use HomeLan\FileStore\Messages\EconetPacket;
use HomeLan\FileStore\Services\Provider\Viewdata;
use HomeLan\FileStore\Services\ServiceDispatcher;
use React\Socket\ConnectorInterface;
use React\Socket\ConnectionInterface;
use React\Stream\ReadableStreamInterface;
use React\Stream\WritableStreamInterface;
use React\Promise\PromiseInterface;
use Evenement\EventEmitter;

include_once('include/system.inc.php');

// ---------------------------------------------------------------------------
// Minimal connection stub — replaces React\Socket\ConnectionInterface.
// ---------------------------------------------------------------------------
class FakeViewdataConnection extends EventEmitter implements ConnectionInterface
{
    public string $sWritten = '';
    private bool $bClosed   = false;

    public function write($data): bool
    {
        $this->sWritten .= $data;
        return true;
    }

    public function end($data = null): void
    {
        if ($data !== null) {
            $this->write($data);
        }
        $this->emit('end');
        $this->close();
    }

    public function close(): void
    {
        if ($this->bClosed) {
            return;
        }
        $this->bClosed = true;
        $this->emit('close');
    }

    public function isReadable(): bool { return !$this->bClosed; }
    public function isWritable(): bool { return !$this->bClosed; }
    public function pause(): void {}
    public function resume(): void {}
    public function pipe(WritableStreamInterface $dest, array $options = []): WritableStreamInterface { return $dest; }
    public function getRemoteAddress() { return 'tcp://127.0.0.1:6502'; }
    public function getLocalAddress() { return 'tcp://127.0.0.1:0'; }
}

// ---------------------------------------------------------------------------
// Minimal connector stub — replaces React\Socket\Connector.
// Resolves/rejects synchronously so tests don't need a real event loop.
// ---------------------------------------------------------------------------
class FakeViewdataConnector implements ConnectorInterface
{
    /** @var array<int,FakeViewdataConnection> */
    public array $connections = [];

    public bool $bRejectNextConnect = false;

    public function connect($uri): PromiseInterface
    {
        if ($this->bRejectNextConnect) {
            $this->bRejectNextConnect = false;
            return \React\Promise\reject(new \Exception('connection refused'));
        }
        $oConnection = new FakeViewdataConnection();
        $this->connections[] = $oConnection;
        return \React\Promise\resolve($oConnection);
    }
}

// ---------------------------------------------------------------------------
// Testable subclass.
//
// Overrides createConnector() to return a FakeViewdataConnector.
// Exposes setLastActivity() to manipulate aClients for housekeeping tests
// (requires aClients to be protected in Viewdata, which it is).
// ---------------------------------------------------------------------------
class ViewdataTestable extends Viewdata
{
    public FakeViewdataConnector $oConnector;

    public function __construct(\Psr\Log\LoggerInterface $oLogger)
    {
        parent::__construct($oLogger);
        $this->oConnector = new FakeViewdataConnector();
    }

    protected function createConnector(): ConnectorInterface
    {
        return $this->oConnector;
    }

    public function setLastActivity(string $sKey, int $iTimestamp): void
    {
        if (isset($this->aClients[$sKey])) {
            $this->aClients[$sKey]['lastactivity'] = $iTimestamp;
        }
    }
}

// ---------------------------------------------------------------------------
// Tests
// ---------------------------------------------------------------------------
class ViewdataTest extends TestCase
{
    private ViewdataTestable $oProvider;
    private ServiceDispatcher $oDispatcher;

    protected function setUp(): void
    {
        $oLogger = new Logger('viewdata-test');
        $oLogger->pushHandler(new NullHandler());

        $this->oProvider = new ViewdataTestable($oLogger);

        $this->oDispatcher = $this->createMock(ServiceDispatcher::class);
        $this->oDispatcher->method('getLoop')->willReturn(null);

        $this->oProvider->registerService($this->oDispatcher);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * Build an EconetPacket on port 0xa3 with the given control flags and data.
     * ViewdataRequest.decode() uses getFlags() to determine the message type.
     */
    private function pkt(int $iFlags, string $sData = '', int $iNet = 1, int $iStn = 5): EconetPacket
    {
        $o = new EconetPacket();
        $o->setPort(0xa3);
        $o->setFlags($iFlags);
        $o->setSourceNetwork($iNet);
        $o->setSourceStation($iStn);
        $o->setData($sData);
        return $o;
    }

    /** Deliver a packet and return all buffered replies (buffer is drained). */
    private function dispatch(EconetPacket $oPkt): array
    {
        $this->oProvider->unicastPacketIn($oPkt);
        return $this->oProvider->getReplies();
    }

    /** Build a LOGIN packet (flags 0x81). */
    private function loginPkt(int $iNet = 1, int $iStn = 5): EconetPacket
    {
        return $this->pkt(0x81, '', $iNet, $iStn);
    }

    /** Build a DATA packet (flags 0x80, payload = [rxseq][txseq][data]). */
    private function dataPkt(int $iRxSeq, int $iTxSeq, string $sPayload = '', int $iNet = 1, int $iStn = 5): EconetPacket
    {
        return $this->pkt(0x80, chr($iRxSeq) . chr($iTxSeq) . $sPayload, $iNet, $iStn);
    }

    /** Build a TERMINATE packet (flags 0x84). */
    private function terminatePkt(int $iNet = 1, int $iStn = 5): EconetPacket
    {
        return $this->pkt(0x84, '', $iNet, $iStn);
    }

    // -------------------------------------------------------------------------
    // Service metadata
    // -------------------------------------------------------------------------

    public function testGetServicePortsContains0xa3(): void
    {
        $this->assertContains(0xa3, $this->oProvider->getServicePorts());
    }

    public function testGetNameReturnsViewdata(): void
    {
        $this->assertEquals('Viewdata', $this->oProvider->getName());
    }

    // -------------------------------------------------------------------------
    // LOGIN — successful connection
    // -------------------------------------------------------------------------

    public function testLoginWithSuccessfulConnectRepliesWithLoginOkFlag(): void
    {
        $aReplies = $this->dispatch($this->loginPkt());
        $this->assertCount(1, $aReplies);
        $this->assertEquals(0x82, $aReplies[0]->getFlags());
    }

    public function testLoginReplyIsAddressedToRequestingStation(): void
    {
        $aReplies = $this->dispatch($this->loginPkt(3, 42));
        $this->assertEquals(42, $aReplies[0]->getDestinationStation());
        $this->assertEquals(3,  $aReplies[0]->getDestinationNetwork());
    }

    public function testLoginWithSuccessfulConnectOpensConnection(): void
    {
        $this->dispatch($this->loginPkt());
        $this->assertCount(1, $this->oProvider->oConnector->connections);
    }

    public function testLoginSessionAppearsInGetSessions(): void
    {
        $this->dispatch($this->loginPkt(1, 5));
        $aSessions = $this->oProvider->getSessions();
        $this->assertCount(1, $aSessions);
        $this->assertEquals(1, $aSessions[0]['network']);
        $this->assertEquals(5, $aSessions[0]['station']);
    }

    // -------------------------------------------------------------------------
    // LOGIN — failed connection
    // -------------------------------------------------------------------------

    public function testLoginWithFailedConnectRepliesWithLoginRejectFlag(): void
    {
        $this->oProvider->oConnector->bRejectNextConnect = true;
        $aReplies = $this->dispatch($this->loginPkt());
        $this->assertCount(1, $aReplies);
        $this->assertEquals(0x83, $aReplies[0]->getFlags());
    }

    public function testLoginWithFailedConnectReplyCarriesErrorText(): void
    {
        $this->oProvider->oConnector->bRejectNextConnect = true;
        $aReplies = $this->dispatch($this->loginPkt());
        $this->assertNotEmpty($aReplies[0]->getData());
    }

    public function testLoginWithFailedConnectDoesNotCreateSession(): void
    {
        $this->oProvider->oConnector->bRejectNextConnect = true;
        $this->dispatch($this->loginPkt());
        $this->assertEmpty($this->oProvider->getSessions());
    }

    // -------------------------------------------------------------------------
    // LOGIN — duplicate session (same station logs in again)
    // -------------------------------------------------------------------------

    public function testLoginDuplicateSessionClosesOldConnection(): void
    {
        $this->dispatch($this->loginPkt());
        $this->dispatch($this->loginPkt());  // same net/stn

        $this->assertTrue($this->oProvider->oConnector->connections[0]->isWritable() === false,
            'First connection should be closed when a duplicate login arrives');
    }

    public function testLoginDuplicateSessionOpensNewConnection(): void
    {
        $this->dispatch($this->loginPkt());
        $this->dispatch($this->loginPkt());

        $this->assertCount(2, $this->oProvider->oConnector->connections);
    }

    public function testLoginDuplicateSessionKeepsExactlyOneSession(): void
    {
        $this->dispatch($this->loginPkt());
        $this->dispatch($this->loginPkt());

        $this->assertCount(1, $this->oProvider->getSessions());
    }

    // -------------------------------------------------------------------------
    // DATA — active session
    // -------------------------------------------------------------------------

    public function testEconetDataInWithNewRxSeqWritesPayloadToConnection(): void
    {
        $this->dispatch($this->loginPkt());

        $this->dispatch($this->dataPkt(1, 0, 'hello'));

        $this->assertEquals('hello', $this->oProvider->oConnector->connections[0]->sWritten);
    }

    public function testEconetDataInWithNewRxSeqSendsAckReply(): void
    {
        $this->dispatch($this->loginPkt());

        $aReplies = $this->dispatch($this->dataPkt(1, 0, 'hello'));

        $this->assertCount(1, $aReplies);
        $this->assertEquals(0x0, $aReplies[0]->getFlags());
    }

    public function testEconetDataInAckCarriesCorrectTxAndRxSeqBytes(): void
    {
        $this->dispatch($this->loginPkt());

        // Send with RxSeq=7; server txseq starts at 0
        $aReplies = $this->dispatch($this->dataPkt(7, 0, 'x'));
        $aBytes   = unpack('C*', (string) $aReplies[0]->getData());

        $this->assertEquals(0, $aBytes[1]); // server txseq = 0
        $this->assertEquals(7, $aBytes[2]); // echoed client rxseq = 7
    }

    public function testEconetDataInWithDuplicateRxSeqDoesNotWriteToConnection(): void
    {
        $this->dispatch($this->loginPkt());

        $this->dispatch($this->dataPkt(3, 0, 'first'));
        $sAfterFirst = $this->oProvider->oConnector->connections[0]->sWritten;

        $this->dispatch($this->dataPkt(3, 0, 'duplicate'));

        $this->assertEquals($sAfterFirst, $this->oProvider->oConnector->connections[0]->sWritten,
            'A duplicate RxSeq must not be written to the connection');
    }

    public function testEconetDataInWithDuplicateRxSeqProducesNoReply(): void
    {
        $this->dispatch($this->loginPkt());
        $this->dispatch($this->dataPkt(3, 0, 'first'));

        $aReplies = $this->dispatch($this->dataPkt(3, 0, 'duplicate'));

        $this->assertEmpty($aReplies, 'Duplicate RxSeq must not generate a reply');
    }

    // -------------------------------------------------------------------------
    // DATA — no active session
    // -------------------------------------------------------------------------

    public function testEconetDataInWithNoSessionSendsTerminateFlag(): void
    {
        $aReplies = $this->dispatch($this->dataPkt(1, 0, 'hello'));

        $this->assertCount(1, $aReplies);
        $this->assertEquals(0x4, $aReplies[0]->getFlags());
    }

    // -------------------------------------------------------------------------
    // TERMINATE request
    // -------------------------------------------------------------------------

    public function testTerminateRequestClosesTheSession(): void
    {
        $this->dispatch($this->loginPkt());

        $this->dispatch($this->terminatePkt());

        $this->assertEmpty($this->oProvider->getSessions());
    }

    public function testTerminateRequestClosesTheConnection(): void
    {
        $this->dispatch($this->loginPkt());

        $this->dispatch($this->terminatePkt());

        $this->assertFalse($this->oProvider->oConnector->connections[0]->isWritable());
    }

    // -------------------------------------------------------------------------
    // closeSession()
    // -------------------------------------------------------------------------

    public function testCloseSessionSendsTerminateReply(): void
    {
        $this->dispatch($this->loginPkt(1, 7));

        $this->oProvider->closeSession('1-7');

        $aReplies = $this->oProvider->getReplies();
        $this->assertCount(1, $aReplies);
        $this->assertEquals(0x4, $aReplies[0]->getFlags());
    }

    public function testCloseSessionClosesConnection(): void
    {
        $this->dispatch($this->loginPkt(1, 7));

        $this->oProvider->closeSession('1-7');

        $this->assertFalse($this->oProvider->oConnector->connections[0]->isWritable());
    }

    public function testCloseSessionRemovesSessionFromGetSessions(): void
    {
        $this->dispatch($this->loginPkt(1, 7));

        $this->oProvider->closeSession('1-7');

        $this->assertEmpty($this->oProvider->getSessions());
    }

    public function testCloseSessionWithUnknownKeyIsNoOp(): void
    {
        $this->oProvider->closeSession('99-99');
        $this->assertEmpty($this->oProvider->getReplies());
    }

    // -------------------------------------------------------------------------
    // processDataOut()
    // -------------------------------------------------------------------------

    public function testProcessDataOutSendsDataReplyWithZeroFlags(): void
    {
        $this->dispatch($this->loginPkt(1, 5));

        $this->oProvider->processDataOut('1-5', 'from server');

        $aReplies = $this->oProvider->getReplies();
        $this->assertCount(1, $aReplies);
        $this->assertEquals(0x0, $aReplies[0]->getFlags());
    }

    public function testProcessDataOutReplyStartsWithTxSeqThenRxSeq(): void
    {
        $this->dispatch($this->loginPkt(1, 5));
        // txseq = 0, rxseq = 0 at session start

        $this->oProvider->processDataOut('1-5', 'x');

        $aBytes = unpack('C*', (string) $this->oProvider->getReplies()[0]->getData());
        $this->assertEquals(0, $aBytes[1]); // txseq
        $this->assertEquals(0, $aBytes[2]); // rxseq
    }

    public function testProcessDataOutReplyContainsPayloadAfterSeqBytes(): void
    {
        $this->dispatch($this->loginPkt(1, 5));

        $this->oProvider->processDataOut('1-5', 'hello');

        $sData = (string) $this->oProvider->getReplies()[0]->getData();
        $this->assertStringContainsString('hello', $sData);
    }

    public function testProcessDataOutIncrementsTxSeqOnEachCall(): void
    {
        $this->dispatch($this->loginPkt(1, 5));

        $this->oProvider->processDataOut('1-5', 'first');
        $aFirst  = unpack('C*', (string) $this->oProvider->getReplies()[0]->getData());

        $this->oProvider->processDataOut('1-5', 'second');
        $aSecond = unpack('C*', (string) $this->oProvider->getReplies()[0]->getData());

        $this->assertEquals(0, $aFirst[1]);  // txseq = 0 on first call
        $this->assertEquals(1, $aSecond[1]); // txseq = 1 on second call
    }

    public function testProcessDataOutWithNoSessionIsNoOp(): void
    {
        $this->oProvider->processDataOut('99-99', 'data');
        $this->assertEmpty($this->oProvider->getReplies());
    }

    // -------------------------------------------------------------------------
    // Connection events
    // -------------------------------------------------------------------------

    public function testConnectionDataEventSendsReplyToClient(): void
    {
        $this->dispatch($this->loginPkt(1, 5));

        $this->oProvider->oConnector->connections[0]->emit('data', ['output from server']);

        $aReplies = $this->oProvider->getReplies();
        $this->assertCount(1, $aReplies);
        $this->assertStringContainsString('output from server', (string) $aReplies[0]->getData());
    }

    public function testConnectionCloseEventClosesSession(): void
    {
        $this->dispatch($this->loginPkt(1, 5));

        $this->oProvider->oConnector->connections[0]->emit('close');

        $this->assertEmpty($this->oProvider->getSessions());
    }

    // -------------------------------------------------------------------------
    // houseKeeping()
    // -------------------------------------------------------------------------

    public function testHouseKeepingClosesTimedOutSessions(): void
    {
        $this->dispatch($this->loginPkt(1, 5));

        $this->oProvider->setLastActivity('1-5', time() - (Viewdata::DEFAULT_TIMEOUT + 10));
        $this->oProvider->houseKeeping();

        $this->assertEmpty($this->oProvider->getSessions());
    }

    public function testHouseKeepingDoesNotCloseRecentSessions(): void
    {
        $this->dispatch($this->loginPkt(1, 5));

        $this->oProvider->houseKeeping();

        $this->assertCount(1, $this->oProvider->getSessions());
    }

    // -------------------------------------------------------------------------
    // getSessions()
    // -------------------------------------------------------------------------

    public function testGetSessionsReturnsNetworkAndStation(): void
    {
        $this->dispatch($this->loginPkt(3, 17));
        $aSessions = $this->oProvider->getSessions();
        $this->assertEquals(3,  $aSessions[0]['network']);
        $this->assertEquals(17, $aSessions[0]['station']);
    }

    // -------------------------------------------------------------------------
    // getReplies() buffer management
    // -------------------------------------------------------------------------

    public function testGetRepliesDrainsBufferOnEachCall(): void
    {
        $this->dispatch($this->loginPkt());
        // dispatch already drained — buffer must now be empty
        $this->assertCount(0, $this->oProvider->getReplies());
    }
}
