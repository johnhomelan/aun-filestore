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
// Testable subclass — overrides the five protected I/O wrappers so no real
// filesystem, config, or Security calls are made.
// ---------------------------------------------------------------------------
class PrintServerTestable extends PrintServer
{
    public string $stubSpoolDir       = '/spool';
    public array  $stubExistingDirs   = [];
    public ?object $stubUser          = null;

    public array $capCreatedDirs  = [];
    public array $capWrittenFiles = [];

    protected function getSpoolDir(): string { return $this->stubSpoolDir; }
    protected function isDir(string $sPath): bool { return in_array($sPath, $this->stubExistingDirs, true); }
    protected function getUser(int $iNet, int $iStn) { return $this->stubUser; }
    protected function makeDir(string $sPath): void { $this->capCreatedDirs[] = $sPath; }
    protected function putFile(string $sPath, string $sData): void { $this->capWrittenFiles[] = ['path' => $sPath, 'data' => $sData]; }
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

    private function enquiryPkt(int $iNet = 1, int $iStn = 5): EconetPacket
    {
        // 8 bytes: 6 for the printer name, 2 for the 16-bit request code (LE).
        $oPkt = new EconetPacket();
        $oPkt->setPort(0x9F);
        $oPkt->setSourceNetwork($iNet);
        $oPkt->setSourceStation($iStn);
        $oPkt->setFlags(0);
        $oPkt->setDestinationNetwork(0);
        $oPkt->setDestinationStation(0);
        $oPkt->setData(str_pad('LASER1', 6, "\x00") . pack('v', 5));
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

    // Spool-start packet: exactly 1 byte, value 0.
    private function spoolStartPkt(int $iNet = 1, int $iStn = 5): EconetPacket
    {
        return $this->dataPkt(pack('C', 0), $iNet, $iStn);
    }

    // Mid-job data packet: payload bytes followed by 0x00 continuation marker.
    private function midJobPkt(string $sData, int $iNet = 1, int $iStn = 5): EconetPacket
    {
        return $this->dataPkt($sData . "\x00", $iNet, $iStn);
    }

    // End-of-job packet: payload bytes followed by 0x03 ETX marker.
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
        // broadcastPacketIn() must route PrinterServerEnquiry (0x9F) to
        // processEnquiry() and queue a status reply — same as the unicast path.
        $this->oServer->broadcastPacketIn($this->enquiryPkt());
        $this->assertCount(1, $this->oServer->getReplies());
    }

    public function testBroadcastOnUnknownPortGeneratesNoReply(): void
    {
        // Broadcasts on ports other than 0x9F are ignored by the print server.
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
    // processEnquiry reply format
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
    // getReplies drains the buffer
    // -----------------------------------------------------------------------

    public function testGetRepliesDrainsBuffer(): void
    {
        $this->oServer->unicastPacketIn($this->enquiryPkt());
        $this->oServer->getReplies();                        // first call drains
        $this->assertEmpty($this->oServer->getReplies());   // second call is empty
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
        $this->dispatch($this->midJobPkt('HELLO'));   // "HELLO" stored, \x00 consumed as marker
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
        $this->oServer->stubExistingDirs = [];    // spool dir absent
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
        // "PAGE" from mid-job + "END" from end-of-job (ETX excluded)
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
        $this->oServer->stubExistingDirs = ['/spool'];   // subdir missing
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
        $this->assertSame(2,  $aJob['network']);
        $this->assertSame(15, $aJob['station']);
    }

    public function testGetJobsSizeReflectsAccumulatedData(): void
    {
        $this->dispatch($this->spoolStartPkt());
        $this->dispatch($this->midJobPkt('ABCDE'));  // 5 bytes stored
        $this->assertSame(5, $this->oServer->getJobs()[0]['size']);
    }
}
