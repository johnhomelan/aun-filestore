<?php

/*
 * @group unit-tests
 */

use PHPUnit\Framework\TestCase;
use Monolog\Logger;
use Monolog\Handler\NullHandler;
use HomeLan\FileStore\Messages\EconetPacket;
use HomeLan\FileStore\Services\Provider\BeebTerm;
use HomeLan\FileStore\Services\ServiceDispatcher;

include_once('include/system.inc.php');

// ---------------------------------------------------------------------------
// Minimal stream stub — replaces React's ReadableStream / WritableStream.
// Supports on() for event registration, write() to capture stdin data, and
// emit() to fire registered handlers (used in tests to simulate process I/O).
// ---------------------------------------------------------------------------
class FakeStream
{
    private array $aListeners = [];
    public string $sWritten   = '';

    public function on(string $sEvent, callable $fn): void
    {
        $this->aListeners[$sEvent][] = $fn;
    }

    public function write(string $sData): void
    {
        $this->sWritten .= $sData;
    }

    public function emit(string $sEvent, ...$aArgs): void
    {
        foreach ($this->aListeners[$sEvent] ?? [] as $fn) {
            $fn(...$aArgs);
        }
    }
}

// ---------------------------------------------------------------------------
// Minimal process stub — replaces React\ChildProcess\Process.
// Tracks whether start() and terminate() were called.
// ---------------------------------------------------------------------------
class FakeProcess
{
    public FakeStream $stdout;
    public FakeStream $stdin;
    public bool $bStarted    = false;
    public bool $bTerminated = false;

    private static int $iNextPid = 1000;
    private int $iPid;

    public function __construct(private readonly string $sCommand)
    {
        $this->stdout = new FakeStream();
        $this->stdin  = new FakeStream();
        $this->iPid   = self::$iNextPid++;
    }

    public function start($oLoop): void  { $this->bStarted = true; }
    public function terminate(): void    { $this->bTerminated = true; }
    public function getPid(): int        { return $this->iPid; }
    public function getCommand(): string { return $this->sCommand; }
}

// ---------------------------------------------------------------------------
// Testable subclass.
//
// Overrides createProcess() to return a FakeProcess and record it.
// Exposes setLastActivity() to manipulate aClients for housekeeping tests
// (requires aClients to be protected in BeebTerm, which it is).
// ---------------------------------------------------------------------------
class BeebTermTestable extends BeebTerm
{
    public array $processes = [];

    protected function createProcess(string $sCommand): object
    {
        $oProcess = new FakeProcess($sCommand);
        $this->processes[] = $oProcess;
        return $oProcess;
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
class BeebTermTest extends TestCase
{
    private BeebTermTestable $oProvider;
    private ServiceDispatcher $oDispatcher;

    private const SERVICES = "shell \"SHELL\"\nbash \"BASH\"\n";

    protected function setUp(): void
    {
        $oLogger = new Logger('beebterm-test');
        $oLogger->pushHandler(new NullHandler());

        $this->oProvider = new BeebTermTestable($oLogger, self::SERVICES);

        $this->oDispatcher = $this->createMock(ServiceDispatcher::class);
        $this->oDispatcher->method('getLoop')->willReturn(null);

        $this->oProvider->registerService($this->oDispatcher);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * Build an EconetPacket on port 0xa2 with the given control flags and data.
     * BeebTermRequest.decode() uses getFlags() to determine the message type.
     */
    private function pkt(int $iFlags, string $sData = '', int $iNet = 1, int $iStn = 5): EconetPacket
    {
        $o = new EconetPacket();
        $o->setPort(0xa2);
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

    /** Build a LOGIN packet (flags 0x81, data = service name terminated by \r). */
    private function loginPkt(string $sService, int $iNet = 1, int $iStn = 5): EconetPacket
    {
        return $this->pkt(0x81, $sService . "\r", $iNet, $iStn);
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

    public function testGetServicePortsContains0xa2(): void
    {
        $this->assertContains(0xa2, $this->oProvider->getServicePorts());
    }

    public function testGetNameReturnsBeebTerm(): void
    {
        $this->assertEquals('Beeb Term', $this->oProvider->getName());
    }

    // -------------------------------------------------------------------------
    // Constructor / service loading
    // -------------------------------------------------------------------------

    public function testConstructorParsesServiceNamesFromString(): void
    {
        $aNames = array_column($this->oProvider->getServices(), 'name');
        $this->assertContains('shell', $aNames);
        $this->assertContains('bash',  $aNames);
    }

    public function testConstructorParsesServiceCommandsFromString(): void
    {
        $aByName = array_column($this->oProvider->getServices(), 'command', 'name');
        $this->assertEquals('SHELL', $aByName['shell']);
        $this->assertEquals('BASH',  $aByName['bash']);
    }

    public function testConstructorIgnoresBlankAndInvalidLines(): void
    {
        $oLogger = new Logger('t');
        $oLogger->pushHandler(new NullHandler());
        $oProvider = new BeebTermTestable($oLogger, "   \n# comment\ninvalid line without quotes\n");
        $this->assertEmpty($oProvider->getServices());
    }

    public function testAddServiceAppearsInGetServices(): void
    {
        $this->oProvider->addService('vi', '/usr/bin/vi');
        $aNames = array_column($this->oProvider->getServices(), 'name');
        $this->assertContains('vi', $aNames);
    }

    // -------------------------------------------------------------------------
    // LOGIN — valid service
    // -------------------------------------------------------------------------

    public function testLoginWithValidServiceRepliesWithLoginOkFlag(): void
    {
        $aReplies = $this->dispatch($this->loginPkt('shell'));
        $this->assertCount(1, $aReplies);
        $this->assertEquals(0x82, $aReplies[0]->getFlags());
    }

    public function testLoginReplyContainsServiceName(): void
    {
        $aReplies = $this->dispatch($this->loginPkt('shell'));
        $this->assertStringContainsString('shell', (string) $aReplies[0]->getData());
    }

    public function testLoginReplyIsAddressedToRequestingStation(): void
    {
        $aReplies = $this->dispatch($this->loginPkt('shell', 3, 42));
        $this->assertEquals(42, $aReplies[0]->getDestinationStation());
        $this->assertEquals(3,  $aReplies[0]->getDestinationNetwork());
    }

    public function testLoginWithValidServiceStartsProcess(): void
    {
        $this->dispatch($this->loginPkt('shell'));
        $this->assertTrue($this->oProvider->processes[0]->bStarted);
    }

    public function testLoginRunsTheCommandRegisteredForTheService(): void
    {
        $this->dispatch($this->loginPkt('shell'));
        $this->assertEquals('SHELL', $this->oProvider->processes[0]->getCommand());
    }

    public function testLoginSessionAppearsInGetSessions(): void
    {
        $this->dispatch($this->loginPkt('shell', 1, 5));
        $aSessions = $this->oProvider->getSessions();
        $this->assertCount(1, $aSessions);
        $this->assertEquals(1, $aSessions[0]['network']);
        $this->assertEquals(5, $aSessions[0]['station']);
    }

    // -------------------------------------------------------------------------
    // LOGIN — invalid service
    // -------------------------------------------------------------------------

    public function testLoginWithInvalidServiceRepliesWithLoginRejectFlag(): void
    {
        $aReplies = $this->dispatch($this->loginPkt('nosuchservice'));
        $this->assertCount(1, $aReplies);
        $this->assertEquals(0x83, $aReplies[0]->getFlags());
    }

    public function testLoginWithInvalidServiceReplyCarriesErrorText(): void
    {
        $aReplies = $this->dispatch($this->loginPkt('nosuchservice'));
        $this->assertNotEmpty($aReplies[0]->getData());
    }

    public function testLoginWithInvalidServiceDoesNotCreateSession(): void
    {
        $this->dispatch($this->loginPkt('nosuchservice'));
        $this->assertEmpty($this->oProvider->getSessions());
    }

    public function testLoginWithInvalidServiceDoesNotStartProcess(): void
    {
        $this->dispatch($this->loginPkt('nosuchservice'));
        $this->assertEmpty($this->oProvider->processes);
    }

    // -------------------------------------------------------------------------
    // LOGIN — duplicate session (same station logs in again)
    // -------------------------------------------------------------------------

    public function testLoginDuplicateSessionTerminatesOldProcess(): void
    {
        $this->dispatch($this->loginPkt('shell'));
        $this->dispatch($this->loginPkt('shell'));  // same net/stn

        $this->assertTrue($this->oProvider->processes[0]->bTerminated,
            'First process should be terminated when a duplicate login arrives');
    }

    public function testLoginDuplicateSessionCreatesNewProcess(): void
    {
        $this->dispatch($this->loginPkt('shell'));
        $this->dispatch($this->loginPkt('shell'));

        $this->assertCount(2, $this->oProvider->processes);
        $this->assertFalse($this->oProvider->processes[1]->bTerminated);
    }

    public function testLoginDuplicateSessionKeepsExactlyOneSession(): void
    {
        $this->dispatch($this->loginPkt('shell'));
        $this->dispatch($this->loginPkt('shell'));

        $this->assertCount(1, $this->oProvider->getSessions());
    }

    // -------------------------------------------------------------------------
    // DATA — active session
    // -------------------------------------------------------------------------

    public function testEconetDataInWithNewRxSeqWritesPayloadToStdin(): void
    {
        $this->dispatch($this->loginPkt('shell'));

        $this->dispatch($this->dataPkt(1, 0, 'hello'));

        $this->assertEquals('hello', $this->oProvider->processes[0]->stdin->sWritten);
    }

    public function testEconetDataInWithNewRxSeqSendsAckReply(): void
    {
        $this->dispatch($this->loginPkt('shell'));

        $aReplies = $this->dispatch($this->dataPkt(1, 0, 'hello'));

        $this->assertCount(1, $aReplies);
        $this->assertEquals(0x0, $aReplies[0]->getFlags());
    }

    public function testEconetDataInAckCarriesCorrectTxAndRxSeqBytes(): void
    {
        $this->dispatch($this->loginPkt('shell'));

        // Send with RxSeq=7; server txseq starts at 0
        $aReplies = $this->dispatch($this->dataPkt(7, 0, 'x'));
        $aBytes   = unpack('C*', (string) $aReplies[0]->getData());

        $this->assertEquals(0, $aBytes[1]); // server txseq = 0
        $this->assertEquals(7, $aBytes[2]); // echoed client rxseq = 7
    }

    public function testEconetDataInWithDuplicateRxSeqDoesNotWriteToStdin(): void
    {
        $this->dispatch($this->loginPkt('shell'));

        $this->dispatch($this->dataPkt(3, 0, 'first'));
        $sAfterFirst = $this->oProvider->processes[0]->stdin->sWritten;

        $this->dispatch($this->dataPkt(3, 0, 'duplicate'));

        $this->assertEquals($sAfterFirst, $this->oProvider->processes[0]->stdin->sWritten,
            'A duplicate RxSeq must not be written to stdin');
    }

    public function testEconetDataInWithDuplicateRxSeqProducesNoReply(): void
    {
        $this->dispatch($this->loginPkt('shell'));
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
        $this->dispatch($this->loginPkt('shell'));

        $this->dispatch($this->terminatePkt());

        $this->assertEmpty($this->oProvider->getSessions());
    }

    public function testTerminateRequestTerminatesTheProcess(): void
    {
        $this->dispatch($this->loginPkt('shell'));

        $this->dispatch($this->terminatePkt());

        $this->assertTrue($this->oProvider->processes[0]->bTerminated);
    }

    // -------------------------------------------------------------------------
    // closeSession()
    // -------------------------------------------------------------------------

    public function testCloseSessionSendsTerminateReply(): void
    {
        $this->dispatch($this->loginPkt('shell', 1, 7));

        $this->oProvider->closeSession('1-7');

        $aReplies = $this->oProvider->getReplies();
        $this->assertCount(1, $aReplies);
        $this->assertEquals(0x4, $aReplies[0]->getFlags());
    }

    public function testCloseSessionTerminatesProcess(): void
    {
        $this->dispatch($this->loginPkt('shell', 1, 7));

        $this->oProvider->closeSession('1-7');

        $this->assertTrue($this->oProvider->processes[0]->bTerminated);
    }

    public function testCloseSessionRemovesSessionFromGetSessions(): void
    {
        $this->dispatch($this->loginPkt('shell', 1, 7));

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
        $this->dispatch($this->loginPkt('shell', 1, 5));

        $this->oProvider->processDataOut('1-5', 'from process');

        $aReplies = $this->oProvider->getReplies();
        $this->assertCount(1, $aReplies);
        $this->assertEquals(0x0, $aReplies[0]->getFlags());
    }

    public function testProcessDataOutReplyStartsWithTxSeqThenRxSeq(): void
    {
        $this->dispatch($this->loginPkt('shell', 1, 5));
        // txseq = 0, rxseq = 0 at session start

        $this->oProvider->processDataOut('1-5', 'x');

        $aBytes = unpack('C*', (string) $this->oProvider->getReplies()[0]->getData());
        $this->assertEquals(0, $aBytes[1]); // txseq
        $this->assertEquals(0, $aBytes[2]); // rxseq
    }

    public function testProcessDataOutReplyContainsPayloadAfterSeqBytes(): void
    {
        $this->dispatch($this->loginPkt('shell', 1, 5));

        $this->oProvider->processDataOut('1-5', 'hello');

        $sData = (string) $this->oProvider->getReplies()[0]->getData();
        $this->assertStringContainsString('hello', $sData);
    }

    public function testProcessDataOutIncrementsTxSeqOnEachCall(): void
    {
        $this->dispatch($this->loginPkt('shell', 1, 5));

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
    // Process stdout events
    // -------------------------------------------------------------------------

    public function testProcessStdoutDataEventSendsReplyToClient(): void
    {
        $this->dispatch($this->loginPkt('shell', 1, 5));

        $this->oProvider->processes[0]->stdout->emit('data', 'output from process');

        $aReplies = $this->oProvider->getReplies();
        $this->assertCount(1, $aReplies);
        $this->assertStringContainsString('output from process', (string) $aReplies[0]->getData());
    }

    public function testProcessStdoutCloseEventClosesSession(): void
    {
        $this->dispatch($this->loginPkt('shell', 1, 5));

        $this->oProvider->processes[0]->stdout->emit('close');

        $this->assertEmpty($this->oProvider->getSessions());
    }

    // -------------------------------------------------------------------------
    // houseKeeping()
    // -------------------------------------------------------------------------

    public function testHouseKeepingClosesTimedOutSessions(): void
    {
        $this->dispatch($this->loginPkt('shell', 1, 5));

        $this->oProvider->setLastActivity('1-5', time() - (BeebTerm::DEFAULT_TIMEOUT + 10));
        $this->oProvider->houseKeeping();

        $this->assertEmpty($this->oProvider->getSessions());
    }

    public function testHouseKeepingDoesNotCloseRecentSessions(): void
    {
        $this->dispatch($this->loginPkt('shell', 1, 5));

        $this->oProvider->houseKeeping();

        $this->assertCount(1, $this->oProvider->getSessions());
    }

    // -------------------------------------------------------------------------
    // getSessions()
    // -------------------------------------------------------------------------

    public function testGetSessionsReturnsNetworkAndStation(): void
    {
        $this->dispatch($this->loginPkt('shell', 3, 17));
        $aSessions = $this->oProvider->getSessions();
        $this->assertEquals(3,  $aSessions[0]['network']);
        $this->assertEquals(17, $aSessions[0]['station']);
    }

    public function testGetSessionsReturnsProcessCommandAndPid(): void
    {
        $this->dispatch($this->loginPkt('shell'));
        $aSessions = $this->oProvider->getSessions();
        $this->assertEquals('SHELL', $aSessions[0]['command']);
        $this->assertIsInt($aSessions[0]['pid']);
    }

    // -------------------------------------------------------------------------
    // getReplies() buffer management
    // -------------------------------------------------------------------------

    public function testGetRepliesDrainsBufferOnEachCall(): void
    {
        $this->dispatch($this->loginPkt('shell'));
        // dispatch already drained — buffer must now be empty
        $this->assertCount(0, $this->oProvider->getReplies());
    }
}
