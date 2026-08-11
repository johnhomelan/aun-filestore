<?php

/*
 * @group unit-tests
 */

use PHPUnit\Framework\TestCase;
use Monolog\Logger;
use Monolog\Handler\NullHandler;
use HomeLan\FileStore\Messages\EconetPacket;
use HomeLan\FileStore\Services\Provider\PrintServer;
use HomeLan\FileStore\Services\Provider\PrintServer\Admin;
use HomeLan\FileStore\Services\Provider\PrintServer\PrinterRegistry;

include_once('include/system.inc.php');

// ---------------------------------------------------------------------------
// Minimal user stub — replaces a real Security user object.
// ---------------------------------------------------------------------------
class FakeUser
{
    public function __construct(private readonly string $sUsername) {}
    public function getUsername(): string { return $this->sUsername; }
}

// ---------------------------------------------------------------------------
// Default registry INI used by all tests unless overridden via stubRegistryIni.
// Contains three printers covering all three behaviour types.
// ---------------------------------------------------------------------------
const TESTABLE_REGISTRY_INI = <<<INI
[PRINT]
description   = Default printer
enabled       = yes
behavior      = spool
script        =
allowed_users =

[LASER1]
description   = Laser printer
enabled       = yes
behavior      = script
script        = /usr/bin/topdf %source% %destination%
allowed_users =

[NULL]
description   = Null (discard)
enabled       = yes
behavior      = discard
script        =
allowed_users = ADMIN
INI;

// ---------------------------------------------------------------------------
// Testable subclass — overrides protected I/O wrappers so no real filesystem,
// config, Security, or React process calls are made.
// ---------------------------------------------------------------------------
class PrintServerTestable extends PrintServer
{
    public string  $stubSpoolDir     = '/spool';
    public array   $stubExistingDirs = [];
    public ?object $stubUser         = null;
    public string  $stubRegistryIni  = TESTABLE_REGISTRY_INI;

    public array $capCreatedDirs    = [];
    public array $capWrittenFiles   = [];
    public array $capConvertCalls   = [];      // [{path, script}]
    public array $capSpoolPathCalls = [];      // [{printer, net, stn}]

    protected function getPrinterRegistry(): PrinterRegistry
    {
        return new PrinterRegistry($this->stubRegistryIni);
    }

    protected function getSpoolDir(): string { return $this->stubSpoolDir; }

    /**
     * Overrides the real getSpoolPath to avoid real filesystem calls.
     * Captures printer/net/stn for routing assertions, then returns a
     * simplified {spoolDir}/{user_or_anon} path (without the printer subdir)
     * so that existing assertions on capWrittenFiles paths remain stable.
     * Returns null when the stub spool directory is not in stubExistingDirs.
     */
    protected function getSpoolPath(string $sPrinterName, int $iNet, int $iStn): ?string
    {
        $this->capSpoolPathCalls[] = ['printer' => $sPrinterName, 'net' => $iNet, 'stn' => $iStn];
        if (!in_array($this->stubSpoolDir, $this->stubExistingDirs, true)) {
            return null;
        }
        $oUser = $this->stubUser;
        if (is_object($oUser)) {
            $sUserDir = $this->stubSpoolDir . DIRECTORY_SEPARATOR . $oUser->getUsername();
        } else {
            $sUserDir = $this->stubSpoolDir . DIRECTORY_SEPARATOR . 'anon-' . $iNet . '-' . $iStn;
        }
        if (!in_array($sUserDir, $this->stubExistingDirs, true)) {
            $this->capCreatedDirs[] = $sUserDir;
        }
        return $sUserDir;
    }

    protected function isDir(string $sPath): bool { return in_array($sPath, $this->stubExistingDirs, true); }
    protected function getUser(int $iNet, int $iStn) { return $this->stubUser; }
    protected function makeDir(string $sPath): void { $this->capCreatedDirs[] = $sPath; }
    protected function putFile(string $sPath, string $sData): void { $this->capWrittenFiles[] = ['path' => $sPath, 'data' => $sData]; }

    protected function convertFile(string $sPath, ?string $sPrinterScript = null): void
    {
        $this->capConvertCalls[] = ['path' => $sPath, 'script' => $sPrinterScript];
    }
}

// ---------------------------------------------------------------------------
// Tests
// ---------------------------------------------------------------------------
class PrintServerTest extends TestCase
{
    private PrintServerTestable $oServer;

    protected function setUp(): void
    {
        $oLogger = new Logger('test');
        $oLogger->pushHandler(new NullHandler());
        $this->oServer = new PrintServerTestable($oLogger);
    }

    // -----------------------------------------------------------------------
    // Packet builders
    // -----------------------------------------------------------------------

    /** Enquiry for LASER1 (default, kept for backward compat with existing tests). */
    private function enquiryPkt(int $iNet = 1, int $iStn = 5): EconetPacket
    {
        return $this->enquiryPktFor('LASER1', $iNet, $iStn);
    }

    /** Enquiry for any named printer. */
    private function enquiryPktFor(string $sPrinter, int $iNet = 1, int $iStn = 5): EconetPacket
    {
        $oPkt = new EconetPacket();
        $oPkt->setPort(0x9F);
        $oPkt->setSourceNetwork($iNet);
        $oPkt->setSourceStation($iStn);
        $oPkt->setFlags(0);
        $oPkt->setDestinationNetwork(0);
        $oPkt->setDestinationStation(0);
        $oPkt->setData(str_pad(substr($sPrinter, 0, 6), 6, "\x00") . pack('v', 5));
        return $oPkt;
    }

    private function dataPkt(string $sPayload, int $iNet = 1, int $iStn = 5): EconetPacket
    {
        $oPkt = new EconetPacket();
        $oPkt->setPort(0xD1);
        $oPkt->setSourceNetwork($iNet);
        $oPkt->setSourceStation($iStn);
        $oPkt->setFlags(0);
        $oPkt->setDestinationNetwork(0);
        $oPkt->setDestinationStation(0);
        $oPkt->setData($sPayload);
        return $oPkt;
    }

    private function spoolStartPkt(int $iNet = 1, int $iStn = 5): EconetPacket
    {
        return $this->dataPkt(pack('C', 0), $iNet, $iStn);
    }

    private function midJobPkt(string $sData, int $iNet = 1, int $iStn = 5): EconetPacket
    {
        return $this->dataPkt($sData . "\x00", $iNet, $iStn);
    }

    private function endJobPkt(string $sData, int $iNet = 1, int $iStn = 5): EconetPacket
    {
        return $this->dataPkt($sData . "\x03", $iNet, $iStn);
    }

    private function dispatch(EconetPacket $oPkt): array
    {
        $this->oServer->unicastPacketIn($oPkt);
        return $this->oServer->getReplies();
    }

    // -----------------------------------------------------------------------
    // Service metadata
    // -----------------------------------------------------------------------

    public function testGetNameReturnsPrintServer(): void
    {
        $this->assertSame('Print Server', $this->oServer->getName());
    }

    public function testGetServicePortsReturnsEnquiryAndDataPorts(): void
    {
        $this->assertSame([0x9F, 0xD1], $this->oServer->getServicePorts());
    }

    public function testGetAdminInterfaceReturnsAdminInstance(): void
    {
        $this->assertInstanceOf(Admin::class, $this->oServer->getAdminInterface());
    }

    // -----------------------------------------------------------------------
    // Packet routing
    // -----------------------------------------------------------------------

    public function testBroadcastEnquiryGeneratesReply(): void
    {
        $this->oServer->broadcastPacketIn($this->enquiryPkt());
        $this->assertCount(1, $this->oServer->getReplies());
    }

    public function testBroadcastOnUnknownPortGeneratesNoReply(): void
    {
        $oPkt = new EconetPacket();
        $oPkt->setPort(0xAB);
        $oPkt->setFlags(0);
        $oPkt->setSourceNetwork(1);
        $oPkt->setSourceStation(5);
        $oPkt->setDestinationNetwork(255);
        $oPkt->setDestinationStation(255);
        $oPkt->setData('');
        $this->oServer->broadcastPacketIn($oPkt);
        $this->assertEmpty($this->oServer->getReplies());
    }

    public function testUnicastEnquiryPacketGeneratesReply(): void
    {
        $aReplies = $this->dispatch($this->enquiryPkt());
        $this->assertCount(1, $aReplies);
    }

    public function testUnicastDataSpoolStartGeneratesReply(): void
    {
        $aReplies = $this->dispatch($this->spoolStartPkt());
        $this->assertCount(1, $aReplies);
    }

    // -----------------------------------------------------------------------
    // processEnquiry reply format (known, enabled printer)
    // -----------------------------------------------------------------------

    public function testEnquiryReplyPortIs9E(): void
    {
        [$oReply] = $this->dispatch($this->enquiryPkt());
        $this->assertSame(0x9E, $oReply->getPort());
    }

    public function testEnquiryReplyDataIsTwoBytesOfZero(): void
    {
        [$oReply] = $this->dispatch($this->enquiryPkt());
        $this->assertSame(pack('v', 0), $oReply->getData());
    }

    public function testEnquiryReplyRoutedToSourceStation(): void
    {
        [$oReply] = $this->dispatch($this->enquiryPkt(iNet: 2, iStn: 7));
        $this->assertSame(7, $oReply->getDestinationStation());
        $this->assertSame(2, $oReply->getDestinationNetwork());
    }

    // -----------------------------------------------------------------------
    // processEnquiry routing: unknown / disabled / unauthorised
    // -----------------------------------------------------------------------

    public function testEnquiryForUnknownPrinterGeneratesNoReply(): void
    {
        $aReplies = $this->dispatch($this->enquiryPktFor('FAKE'));
        $this->assertEmpty($aReplies);
    }

    public function testEnquiryForDisabledPrinterReturnsStatus6(): void
    {
        $this->oServer->stubRegistryIni = "[OFFPRN]\nenabled=no\nbehavior=spool\nscript=\nallowed_users=";
        [$oReply] = $this->dispatch($this->enquiryPktFor('OFFPRN'));
        $aB = unpack('v', $oReply->getData());
        $this->assertSame(6, $aB[1]);
    }

    public function testEnquiryForDisabledPrinterStillSendsOneReply(): void
    {
        $this->oServer->stubRegistryIni = "[OFFPRN]\nenabled=no\nbehavior=spool\nscript=\nallowed_users=";
        $aReplies = $this->dispatch($this->enquiryPktFor('OFFPRN'));
        $this->assertCount(1, $aReplies);
    }

    public function testEnquiryForUnauthorisedUserReturnsStatus5(): void
    {
        $this->oServer->stubRegistryIni = "[PRIV]\nenabled=yes\nbehavior=spool\nscript=\nallowed_users=SYSOP";
        $this->oServer->stubUser = new FakeUser('GUEST');
        [$oReply] = $this->dispatch($this->enquiryPktFor('PRIV'));
        $aB = unpack('v', $oReply->getData());
        $this->assertSame(5, $aB[1]);
    }

    public function testEnquiryForAuthorisedUserReturnsStatus0(): void
    {
        $this->oServer->stubRegistryIni = "[PRIV]\nenabled=yes\nbehavior=spool\nscript=\nallowed_users=SYSOP";
        $this->oServer->stubUser = new FakeUser('SYSOP');
        [$oReply] = $this->dispatch($this->enquiryPktFor('PRIV'));
        $aB = unpack('v', $oReply->getData());
        $this->assertSame(0, $aB[1]);
    }

    public function testEnquiryForOpenPrinterWithNullUserReturnsStatus0(): void
    {
        // allowed_users empty means all permitted, including unauthenticated stations
        $this->oServer->stubUser = null;
        [$oReply] = $this->dispatch($this->enquiryPkt());
        $aB = unpack('v', $oReply->getData());
        $this->assertSame(0, $aB[1]);
    }

    // -----------------------------------------------------------------------
    // processEnquiry records active printer
    // -----------------------------------------------------------------------

    public function testSuccessfulEnquiryRecordsActivePrinterForStation(): void
    {
        $this->oServer->stubExistingDirs = ['/spool'];
        $this->oServer->stubUser = null;
        $this->dispatch($this->enquiryPktFor('LASER1', 1, 5));
        $this->dispatch($this->spoolStartPkt(1, 5));
        $this->dispatch($this->endJobPkt('X', 1, 5));
        // After enquiry for LASER1, the job must be routed to LASER1
        $this->assertNotEmpty($this->oServer->capSpoolPathCalls);
        $this->assertSame('LASER1', $this->oServer->capSpoolPathCalls[0]['printer']);
    }

    public function testActivePrinterClearedAfterJobCompletion(): void
    {
        $this->oServer->stubExistingDirs = ['/spool', '/spool/jbrown'];
        $this->oServer->stubUser = new FakeUser('jbrown');
        // First job: enquiry sets LASER1 as active printer
        $this->dispatch($this->enquiryPktFor('LASER1'));
        $this->dispatch($this->spoolStartPkt());
        $this->dispatch($this->endJobPkt('X'));
        $this->oServer->capSpoolPathCalls = [];
        // Second job without enquiry: falls back to first enabled printer (PRINT)
        $this->dispatch($this->spoolStartPkt());
        $this->dispatch($this->endJobPkt('Y'));
        $this->assertSame('PRINT', $this->oServer->capSpoolPathCalls[0]['printer']);
    }

    // -----------------------------------------------------------------------
    // getReplies drains the buffer
    // -----------------------------------------------------------------------

    public function testGetRepliesDrainsBuffer(): void
    {
        $this->oServer->unicastPacketIn($this->enquiryPkt());
        $this->oServer->getReplies();
        $this->assertEmpty($this->oServer->getReplies());
    }

    // -----------------------------------------------------------------------
    // Spool start
    // -----------------------------------------------------------------------

    public function testSpoolStartCreatesJobRecord(): void
    {
        $this->dispatch($this->spoolStartPkt(iNet: 1, iStn: 10));
        $aJobs = $this->oServer->getJobs();
        $this->assertCount(1, $aJobs);
        $this->assertSame(1,  $aJobs[0]['network']);
        $this->assertSame(10, $aJobs[0]['station']);
    }

    public function testSpoolStartReplyContainsByteZero(): void
    {
        [$oReply] = $this->dispatch($this->spoolStartPkt());
        $this->assertSame(pack('C', 0), $oReply->getData());
    }

    public function testSpoolStartReplyPortIsD1(): void
    {
        [$oReply] = $this->dispatch($this->spoolStartPkt());
        $this->assertSame(0xD1, $oReply->getPort());
    }

    public function testSpoolStartJobSizeIsZero(): void
    {
        $this->dispatch($this->spoolStartPkt());
        $this->assertSame(0, $this->oServer->getJobs()[0]['size']);
    }

    public function testSpoolStartJobRecordHasPrinterField(): void
    {
        $this->dispatch($this->spoolStartPkt());
        $this->assertArrayHasKey('printer', $this->oServer->getJobs()[0]);
    }

    // -----------------------------------------------------------------------
    // Mid-job data
    // -----------------------------------------------------------------------

    public function testMidJobDataRepliesWithByteZero(): void
    {
        [$oReply] = $this->dispatch($this->midJobPkt('HELLO'));
        $this->assertSame(pack('C', 0), $oReply->getData());
    }

    public function testMidJobDataAccumulatesInBuffer(): void
    {
        $this->dispatch($this->spoolStartPkt());
        $this->dispatch($this->midJobPkt('HELLO'));
        $this->assertSame(5, $this->oServer->getJobs()[0]['size']);
    }

    public function testMidJobDataAppendsAcrossPackets(): void
    {
        $this->dispatch($this->spoolStartPkt());
        $this->dispatch($this->midJobPkt('HELLO'));
        $this->dispatch($this->midJobPkt('WORLD'));
        $this->assertSame(10, $this->oServer->getJobs()[0]['size']);
    }

    // -----------------------------------------------------------------------
    // End-of-job: no spool directory
    // -----------------------------------------------------------------------

    public function testEndOfJobNoSpoolDirWritesNoFile(): void
    {
        $this->oServer->stubExistingDirs = [];
        $this->dispatch($this->spoolStartPkt());
        $this->dispatch($this->endJobPkt('DATA'));
        $this->assertEmpty($this->oServer->capWrittenFiles);
    }

    public function testEndOfJobNoSpoolDirStillReplies(): void
    {
        $this->oServer->stubExistingDirs = [];
        $this->dispatch($this->spoolStartPkt());
        [$oReply] = $this->dispatch($this->endJobPkt('DATA'));
        $this->assertSame(pack('C', 0), $oReply->getData());
    }

    public function testEndOfJobNoSpoolDirClearsJobRecord(): void
    {
        $this->oServer->stubExistingDirs = [];
        $this->dispatch($this->spoolStartPkt());
        $this->dispatch($this->endJobPkt('DATA'));
        $this->assertEmpty($this->oServer->getJobs());
    }

    // -----------------------------------------------------------------------
    // End-of-job: spool directory present, known user
    // -----------------------------------------------------------------------

    public function testEndOfJobKnownUserWritesToUserSubdir(): void
    {
        $this->oServer->stubExistingDirs = ['/spool', '/spool/jbrown'];
        $this->oServer->stubUser = new FakeUser('jbrown');
        $this->dispatch($this->spoolStartPkt(iNet: 1, iStn: 5));
        $this->dispatch($this->midJobPkt('PAGE'));
        $this->dispatch($this->endJobPkt('END'));
        $this->assertCount(1, $this->oServer->capWrittenFiles);
        $this->assertStringStartsWith('/spool/jbrown/', $this->oServer->capWrittenFiles[0]['path']);
    }

    public function testEndOfJobKnownUserSpoolFileContainsData(): void
    {
        $this->oServer->stubExistingDirs = ['/spool', '/spool/jbrown'];
        $this->oServer->stubUser = new FakeUser('jbrown');
        $this->dispatch($this->spoolStartPkt());
        $this->dispatch($this->midJobPkt('PAGE'));
        $this->dispatch($this->endJobPkt('END'));
        $this->assertSame('PAGEEND', $this->oServer->capWrittenFiles[0]['data']);
    }

    public function testEndOfJobKnownUserFileHasRawExtension(): void
    {
        $this->oServer->stubExistingDirs = ['/spool', '/spool/jbrown'];
        $this->oServer->stubUser = new FakeUser('jbrown');
        $this->dispatch($this->spoolStartPkt());
        $this->dispatch($this->endJobPkt('X'));
        $this->assertStringEndsWith('.raw', $this->oServer->capWrittenFiles[0]['path']);
    }

    // -----------------------------------------------------------------------
    // End-of-job: spool directory present, anonymous station
    // -----------------------------------------------------------------------

    public function testEndOfJobAnonymousWritesToAnonSubdir(): void
    {
        $this->oServer->stubExistingDirs = ['/spool', '/spool/anon-1-5'];
        $this->oServer->stubUser = null;
        $this->dispatch($this->spoolStartPkt(iNet: 1, iStn: 5));
        $this->dispatch($this->endJobPkt('DATA'));
        $this->assertStringStartsWith('/spool/anon-1-5/', $this->oServer->capWrittenFiles[0]['path']);
    }

    // -----------------------------------------------------------------------
    // End-of-job: subdirectory creation
    // -----------------------------------------------------------------------

    public function testEndOfJobCreatesSubdirWhenAbsent(): void
    {
        $this->oServer->stubExistingDirs = ['/spool'];
        $this->oServer->stubUser = new FakeUser('jbrown');
        $this->dispatch($this->spoolStartPkt());
        $this->dispatch($this->endJobPkt('X'));
        $this->assertContains('/spool/jbrown', $this->oServer->capCreatedDirs);
    }

    public function testEndOfJobDoesNotCreateSubdirWhenPresent(): void
    {
        $this->oServer->stubExistingDirs = ['/spool', '/spool/jbrown'];
        $this->oServer->stubUser = new FakeUser('jbrown');
        $this->dispatch($this->spoolStartPkt());
        $this->dispatch($this->endJobPkt('X'));
        $this->assertEmpty($this->oServer->capCreatedDirs);
    }

    public function testEndOfJobClearsJobRecord(): void
    {
        $this->oServer->stubExistingDirs = ['/spool', '/spool/jbrown'];
        $this->oServer->stubUser = new FakeUser('jbrown');
        $this->dispatch($this->spoolStartPkt());
        $this->dispatch($this->endJobPkt('DATA'));
        $this->assertEmpty($this->oServer->getJobs());
    }

    // -----------------------------------------------------------------------
    // Data without a prior spool-start
    // -----------------------------------------------------------------------

    public function testDataWithoutSpoolStartCreatesBufferOnTheFly(): void
    {
        $this->dispatch($this->midJobPkt('TEXT', iNet: 1, iStn: 20));
        $aJobs = $this->oServer->getJobs();
        $this->assertCount(1, $aJobs);
        $this->assertSame(20, $aJobs[0]['station']);
    }

    public function testDataWithoutSpoolStartReplies(): void
    {
        [$oReply] = $this->dispatch($this->midJobPkt('TEXT'));
        $this->assertSame(pack('C', 0), $oReply->getData());
    }

    // -----------------------------------------------------------------------
    // getJobs
    // -----------------------------------------------------------------------

    public function testGetJobsReturnsAllActiveJobs(): void
    {
        $this->dispatch($this->spoolStartPkt(iNet: 1, iStn: 10));
        $this->dispatch($this->spoolStartPkt(iNet: 1, iStn: 20));
        $this->assertCount(2, $this->oServer->getJobs());
    }

    public function testGetJobsFieldsArePresent(): void
    {
        $this->dispatch($this->spoolStartPkt(iNet: 2, iStn: 15));
        $aJob = $this->oServer->getJobs()[0];
        $this->assertArrayHasKey('network', $aJob);
        $this->assertArrayHasKey('station', $aJob);
        $this->assertArrayHasKey('began',   $aJob);
        $this->assertArrayHasKey('size',    $aJob);
        $this->assertArrayHasKey('printer', $aJob);
        $this->assertSame(2,  $aJob['network']);
        $this->assertSame(15, $aJob['station']);
    }

    public function testGetJobsSizeReflectsAccumulatedData(): void
    {
        $this->dispatch($this->spoolStartPkt());
        $this->dispatch($this->midJobPkt('ABCDE'));
        $this->assertSame(5, $this->oServer->getJobs()[0]['size']);
    }

    // -----------------------------------------------------------------------
    // Printer behaviour routing
    // -----------------------------------------------------------------------

    public function testSpoolBehaviorDoesNotCallConvertFile(): void
    {
        // PRINT has spool behavior in the default test registry
        $this->oServer->stubExistingDirs = ['/spool', '/spool/jbrown'];
        $this->oServer->stubUser = new FakeUser('jbrown');
        $this->dispatch($this->enquiryPktFor('PRINT'));
        $this->dispatch($this->spoolStartPkt());
        $this->dispatch($this->endJobPkt('DATA'));
        $this->assertEmpty($this->oServer->capConvertCalls);
    }

    public function testSpoolBehaviorWritesFile(): void
    {
        $this->oServer->stubExistingDirs = ['/spool', '/spool/jbrown'];
        $this->oServer->stubUser = new FakeUser('jbrown');
        $this->dispatch($this->enquiryPktFor('PRINT'));
        $this->dispatch($this->spoolStartPkt());
        $this->dispatch($this->endJobPkt('DATA'));
        $this->assertCount(1, $this->oServer->capWrittenFiles);
    }

    public function testScriptBehaviorCallsConvertFile(): void
    {
        // LASER1 has script behavior
        $this->oServer->stubExistingDirs = ['/spool', '/spool/jbrown'];
        $this->oServer->stubUser = new FakeUser('jbrown');
        $this->dispatch($this->enquiryPktFor('LASER1'));
        $this->dispatch($this->spoolStartPkt());
        $this->dispatch($this->endJobPkt('DATA'));
        $this->assertCount(1, $this->oServer->capConvertCalls);
    }

    public function testScriptBehaviorPassesPrinterScriptToConvertFile(): void
    {
        $this->oServer->stubExistingDirs = ['/spool', '/spool/jbrown'];
        $this->oServer->stubUser = new FakeUser('jbrown');
        $this->dispatch($this->enquiryPktFor('LASER1'));
        $this->dispatch($this->spoolStartPkt());
        $this->dispatch($this->endJobPkt('DATA'));
        $this->assertSame('/usr/bin/topdf %source% %destination%', $this->oServer->capConvertCalls[0]['script']);
    }

    public function testScriptBehaviorWithEmptyScriptPassesNullToConvertFile(): void
    {
        $this->oServer->stubRegistryIni = "[XPRN]\nenabled=yes\nbehavior=script\nscript=\nallowed_users=";
        $this->oServer->stubExistingDirs = ['/spool', '/spool/jbrown'];
        $this->oServer->stubUser = new FakeUser('jbrown');
        $this->dispatch($this->enquiryPktFor('XPRN'));
        $this->dispatch($this->spoolStartPkt());
        $this->dispatch($this->endJobPkt('DATA'));
        $this->assertNull($this->oServer->capConvertCalls[0]['script']);
    }

    public function testDiscardBehaviorDoesNotWriteFile(): void
    {
        // Override NULL to be open to all users for this test
        $this->oServer->stubRegistryIni = "[NULL]\ndescription=Null\nenabled=yes\nbehavior=discard\nscript=\nallowed_users=";
        $this->oServer->stubExistingDirs = ['/spool', '/spool/jbrown'];
        $this->oServer->stubUser = new FakeUser('jbrown');
        $this->dispatch($this->enquiryPktFor('NULL'));
        $this->dispatch($this->spoolStartPkt());
        $this->dispatch($this->endJobPkt('DATA'));
        $this->assertEmpty($this->oServer->capWrittenFiles);
    }

    public function testDiscardBehaviorDoesNotCallConvertFile(): void
    {
        $this->oServer->stubRegistryIni = "[NULL]\ndescription=Null\nenabled=yes\nbehavior=discard\nscript=\nallowed_users=";
        $this->oServer->stubExistingDirs = ['/spool', '/spool/jbrown'];
        $this->oServer->stubUser = new FakeUser('jbrown');
        $this->dispatch($this->enquiryPktFor('NULL'));
        $this->dispatch($this->spoolStartPkt());
        $this->dispatch($this->endJobPkt('DATA'));
        $this->assertEmpty($this->oServer->capConvertCalls);
    }

    public function testDiscardBehaviorStillRepliesOk(): void
    {
        $this->oServer->stubRegistryIni = "[NULL]\ndescription=Null\nenabled=yes\nbehavior=discard\nscript=\nallowed_users=";
        $this->oServer->stubExistingDirs = [];
        $this->oServer->stubUser = null;
        $this->dispatch($this->enquiryPktFor('NULL'));
        $this->dispatch($this->spoolStartPkt());
        [$oReply] = $this->dispatch($this->endJobPkt('DATA'));
        $this->assertSame(pack('C', 0), $oReply->getData());
    }

    public function testFallbackToFirstEnabledPrinterWhenNoEnquiry(): void
    {
        // No enquiry sent — job should fall back to PRINT (first in default registry)
        $this->oServer->stubExistingDirs = ['/spool', '/spool/jbrown'];
        $this->oServer->stubUser = new FakeUser('jbrown');
        $this->dispatch($this->spoolStartPkt());
        $this->dispatch($this->endJobPkt('X'));
        $this->assertSame('PRINT', $this->oServer->capSpoolPathCalls[0]['printer']);
    }

    public function testJobRoutedToEnquiredPrinterNotFallback(): void
    {
        $this->oServer->stubExistingDirs = ['/spool', '/spool/jbrown'];
        $this->oServer->stubUser = new FakeUser('jbrown');
        $this->dispatch($this->enquiryPktFor('LASER1'));
        $this->dispatch($this->spoolStartPkt());
        $this->dispatch($this->endJobPkt('X'));
        $this->assertSame('LASER1', $this->oServer->capSpoolPathCalls[0]['printer']);
    }
}
