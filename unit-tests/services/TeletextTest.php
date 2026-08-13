<?php

/*
 * @group unit-tests
 */

use PHPUnit\Framework\TestCase;
use Monolog\Logger;
use Monolog\Handler\NullHandler;
use HomeLan\FileStore\Messages\EconetPacket;
use HomeLan\FileStore\Services\Provider\Teletext;
use HomeLan\FileStore\Services\Provider\Teletext\Storage;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;

include_once(__DIR__ . '/../../src/include/system.inc.php');

// ---------------------------------------------------------------------------
// Testable subclass — overrides now() for deterministic date/time
// assertions, matching the established MaceMailTestable pattern. Storage
// itself is a real constructor-injected Mockery mock, not stubbed here —
// Teletext never touches a filesystem in these tests.
// ---------------------------------------------------------------------------
class TeletextTestable extends Teletext
{
    public \DateTimeImmutable $stubNow;
    public int $stubNowTimestamp = 1_000_000;

    /** @var array<string, int|null> channel => last-imported unix timestamp, or absent for "never" */
    public array $stubTeefaxImportedTimes = [];

    public array $capSpawnedChannels = [];
    public $stubTeefaxProcess = null;

    public function __construct(\Psr\Log\LoggerInterface $oLogger, ?Storage $oStorage = null)
    {
        parent::__construct($oLogger, $oStorage);
        $this->stubNow = new \DateTimeImmutable('2026-06-15 14:32:07');
        $this->stubTeefaxProcess = Mockery::mock(\React\ChildProcess\Process::class, ['dummy'])->makePartial();
        $this->stubTeefaxProcess->shouldReceive('start')->andReturnNull()->byDefault();
    }

    protected function now(): \DateTimeImmutable
    {
        return $this->stubNow;
    }

    protected function _now(): int
    {
        return $this->stubNowTimestamp;
    }

    protected function _readTeefaxImportedMarker(string $sChannel): ?int
    {
        return $this->stubTeefaxImportedTimes[$sChannel] ?? null;
    }

    protected function _spawnTeefaxImport(string $sChannel): \React\ChildProcess\Process
    {
        $this->capSpawnedChannels[] = $sChannel;
        return $this->stubTeefaxProcess;
    }
}

// ---------------------------------------------------------------------------
// Tests
// ---------------------------------------------------------------------------
class TeletextTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    protected TeletextTestable $oProvider;
    protected $oStorage;

    protected function setUp(): void
    {
        if (!defined('CONFIG_teletext_server_name')) {
            define('CONFIG_teletext_server_name', '');
        }
        config::overrideValue('teletext_server_name', '');
        config::overrideValue('teletext_max_users', 99);

        $oLogger = new Logger('teletext-test');
        $oLogger->pushHandler(new NullHandler());

        $this->oStorage  = Mockery::mock(Storage::class);
        $this->oProvider = new TeletextTestable($oLogger, $this->oStorage);
    }

    protected function tearDown(): void
    {
        config::resetValue('teletext_server_name');
        config::resetValue('teletext_max_users');
        config::resetValue('teletext_teefax_channel');
        config::resetValue('teletext_teefax_refresh_interval');
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    protected function _discoveryPacket(string $sFilter, int $iNet = 0, int $iStn = 201): EconetPacket
    {
        $sData = chr(0x80) . str_pad(substr($sFilter, 0, 8), 8, ' ');
        $oPacket = new EconetPacket();
        $oPacket->setPort(Teletext::PORT_FIND_SERVER);
        $oPacket->setFlags(0x80);
        $oPacket->setSourceNetwork($iNet);
        $oPacket->setSourceStation($iStn);
        $oPacket->setData($sData);
        return $oPacket;
    }

    protected function _opPacket(int $iOp, string $sPayload = '', int $iNet = 0, int $iStn = 201): EconetPacket
    {
        $oPacket = new EconetPacket();
        $oPacket->setPort(Teletext::PORT_CLIENT_REQUEST);
        $oPacket->setFlags(0x80);
        $oPacket->setSourceNetwork($iNet);
        $oPacket->setSourceStation($iStn);
        $oPacket->setData(chr($iOp) . $sPayload);
        return $oPacket;
    }

    protected function _pageRequestPacket(int $iOp, string $sChannel, string $sPage, string $sSubpage = '', int $iNet = 0, int $iStn = 201): EconetPacket
    {
        return $this->_opPacket($iOp, $sChannel . $sPage . $sSubpage, $iNet, $iStn);
    }

    // -------------------------------------------------------------------------
    // Basic plugin lifecycle
    // -------------------------------------------------------------------------

    public function testGetNameReturnsTeletext(): void
    {
        $this->assertSame('Teletext', $this->oProvider->getName());
    }

    public function testGetAdminInterfaceReturnsAnAdminObject(): void
    {
        $this->assertInstanceOf(
            \HomeLan\FileStore\Services\Provider\Teletext\Admin::class,
            $this->oProvider->getAdminInterface()
        );
    }

    public function testGetJobsReturnsEmptyArray(): void
    {
        $this->assertSame([], $this->oProvider->getJobs());
    }

    public function testGetServicePortsIncludesAllSixPorts(): void
    {
        $aPorts = $this->oProvider->getServicePorts();
        $this->assertCount(6, $aPorts);
        foreach ([0xB0, 0xB1, 0xB2, 0xB3, 0xB4, 0xB5] as $iPort) {
            $this->assertContains($iPort, $aPorts);
        }
    }

    public function testGetRepliesDrainsBuffer(): void
    {
        $this->oProvider->broadcastPacketIn($this->_discoveryPacket('TELETEXT'));
        $this->assertCount(1, $this->oProvider->getReplies());
        $this->assertCount(0, $this->oProvider->getReplies());
    }

    // -------------------------------------------------------------------------
    // Discovery (0xB0 -> 0xB1)
    // -------------------------------------------------------------------------

    public function testDiscoveryWithMatchingFilterRepliesOnPort0xB1(): void
    {
        $this->oProvider->broadcastPacketIn($this->_discoveryPacket('TELETEXT'));
        $aReplies = $this->oProvider->getReplies();
        $this->assertCount(1, $aReplies);
        $this->assertSame(Teletext::PORT_FIND_SERVER_REPLY, $aReplies[0]->getPort());
    }

    public function testDiscoveryWithWildcardSpacesFilterReplies(): void
    {
        $this->oProvider->broadcastPacketIn($this->_discoveryPacket('        '));
        $this->assertCount(1, $this->oProvider->getReplies());
    }

    public function testDiscoveryWithNonMatchingFilterProducesNoReply(): void
    {
        $this->oProvider->broadcastPacketIn($this->_discoveryPacket('PRINTERX'));
        $this->assertCount(0, $this->oProvider->getReplies());
    }

    public function testDiscoveryReplyLayout(): void
    {
        $this->oProvider->broadcastPacketIn($this->_discoveryPacket('TELETEXT'));
        $sData = $this->oProvider->getReplies()[0]->getData();

        $this->assertSame(0, ord($sData[0]));                    // status
        $this->assertSame(0xB2, ord($sData[1]));                 // base port
        $this->assertSame(1, ord($sData[2]));                    // version
        $this->assertSame('TELETEXT', substr($sData, 3, 8));     // server type
        $this->assertSame(0, ord($sData[11]));                   // name length
        $this->assertSame(12, strlen($sData));
    }

    public function testDiscoveryReplyIncludesServerNameWhenConfigured(): void
    {
        config::overrideValue('teletext_server_name', 'ACORN');
        $this->oProvider->broadcastPacketIn($this->_discoveryPacket('TELETEXT'));
        $sData = $this->oProvider->getReplies()[0]->getData();

        $this->assertSame(5, ord($sData[11]));
        $this->assertSame('ACORN', substr($sData, 12, 5));
    }

    public function testDiscoveryAlsoHandledViaUnicast(): void
    {
        $this->oProvider->unicastPacketIn($this->_discoveryPacket('TELETEXT'));
        $this->assertCount(1, $this->oProvider->getReplies());
    }

    public function testDiscoveryReplyAddressedToRequester(): void
    {
        $this->oProvider->broadcastPacketIn($this->_discoveryPacket('TELETEXT', 3, 99));
        $oReply = $this->oProvider->getReplies()[0];
        $this->assertSame(3, $oReply->getDestinationNetwork());
        $this->assertSame(99, $oReply->getDestinationStation());
    }

    // -------------------------------------------------------------------------
    // Read version (op 0x80)
    // -------------------------------------------------------------------------

    public function testReadVersionRepliesOnPort0xB2WithVersionString(): void
    {
        $this->oProvider->unicastPacketIn($this->_opPacket(Teletext::OP_READ_VERSION));
        $oReply = $this->oProvider->getReplies()[0];
        $this->assertSame(Teletext::PORT_SERVER_REPLY, $oReply->getPort());
        $this->assertSame("\x001.00\x0D", $oReply->getData());
    }

    // -------------------------------------------------------------------------
    // Page request (ops 0x81/0x86/0x89 -> 0xB2 ack, then 0xB4 page data)
    // -------------------------------------------------------------------------

    public function testPageRequestRejectsBadChannel(): void
    {
        $this->oProvider->unicastPacketIn($this->_pageRequestPacket(Teletext::OP_PAGE_REQUEST, 'X', '100'));
        $sData = $this->oProvider->getReplies()[0]->getData();
        $this->assertSame(Teletext::ERROR_BAD_CHANNEL, ord($sData[0]));
    }

    public function testPageRequestRejectsBadPageNumber(): void
    {
        // 'G' is not a valid hex digit, unlike the letters A-F.
        $this->oProvider->unicastPacketIn($this->_pageRequestPacket(Teletext::OP_PAGE_REQUEST, '1', '1GH'));
        $sData = $this->oProvider->getReplies()[0]->getData();
        $this->assertSame(Teletext::ERROR_BAD_PAGE, ord($sData[0]));
    }

    public function testPageRequestRejectsPageOutsideMagazineRange(): void
    {
        // First digit must be 1-8 per the magazine convention, even though
        // the other two digits now accept hex.
        $this->oProvider->unicastPacketIn($this->_pageRequestPacket(Teletext::OP_PAGE_REQUEST, '1', '900'));
        $sData = $this->oProvider->getReplies()[0]->getData();
        $this->assertSame(Teletext::ERROR_BAD_PAGE, ord($sData[0]));
    }

    public function testPageRequestAcceptsUppercaseHexDigits(): void
    {
        $this->oStorage->shouldReceive('getPage')->once()->with('1', '1B0', 1)->andReturn(str_repeat('A', 1024));
        $this->oProvider->unicastPacketIn($this->_pageRequestPacket(Teletext::OP_PAGE_REQUEST, '1', '1B0'));
        $this->assertCount(2, $this->oProvider->getReplies());
    }

    public function testPageRequestAcceptsLowercaseHexDigitsAndNormalisesThem(): void
    {
        $this->oStorage->shouldReceive('getPage')->once()->with('1', '1B0', 1)->andReturn(str_repeat('A', 1024));
        $this->oProvider->unicastPacketIn($this->_pageRequestPacket(Teletext::OP_PAGE_REQUEST, '1', '1b0'));
        $this->assertCount(2, $this->oProvider->getReplies());
    }

    public function testPageRequestRejectsHexDigitInTheMagazinePosition(): void
    {
        // Only the second and third digits may be hex — the magazine digit
        // (the first) must still be a plain decimal 1-8.
        $this->oProvider->unicastPacketIn($this->_pageRequestPacket(Teletext::OP_PAGE_REQUEST, '1', 'A00'));
        $sData = $this->oProvider->getReplies()[0]->getData();
        $this->assertSame(Teletext::ERROR_BAD_PAGE, ord($sData[0]));
    }

    public function testPageRequestNotFoundWhenStorageReturnsNull(): void
    {
        $this->oStorage->shouldReceive('getPage')->with('1', '100', 1)->andReturn(null);
        $this->oProvider->unicastPacketIn($this->_pageRequestPacket(Teletext::OP_PAGE_REQUEST, '1', '100'));
        $sData = $this->oProvider->getReplies()[0]->getData();
        $this->assertSame(Teletext::ERROR_NOT_FOUND, ord($sData[0]));
    }

    public function testPageRequestSuccessSendsAckThenPageData(): void
    {
        $this->oStorage->shouldReceive('getPage')->with('1', '100', 1)->andReturn(str_repeat('A', 1024));
        $this->oProvider->unicastPacketIn($this->_pageRequestPacket(Teletext::OP_PAGE_REQUEST, '1', '100'));

        $aReplies = $this->oProvider->getReplies();
        $this->assertCount(2, $aReplies);
        $this->assertSame(Teletext::PORT_SERVER_REPLY, $aReplies[0]->getPort());
        $this->assertSame("\x00\x00", $aReplies[0]->getData());
        $this->assertSame(Teletext::PORT_PAGE_DATA, $aReplies[1]->getPort());
    }

    public function testPageRequestPageDataIs1024BytesWithSubpageBytesSet(): void
    {
        $this->oStorage->shouldReceive('getPage')->with('1', '100', 1)->andReturn(str_repeat('A', 1024));
        $this->oProvider->unicastPacketIn($this->_pageRequestPacket(Teletext::OP_PAGE_REQUEST, '1', '100'));

        $sData = $this->oProvider->getReplies()[1]->getData();
        $this->assertSame(1025, strlen($sData)); // control byte + 1024-byte page
        $this->assertSame(0x80, ord($sData[0]));
        $this->assertSame(0x00, ord($sData[1 + 0x3FE]));
        $this->assertSame(0x01, ord($sData[1 + 0x3FF]));
    }

    public function testPageRequestPageDataPadsShortStoredContent(): void
    {
        $this->oStorage->shouldReceive('getPage')->with('1', '100', 1)->andReturn('short');
        $this->oProvider->unicastPacketIn($this->_pageRequestPacket(Teletext::OP_PAGE_REQUEST, '1', '100'));

        $sData = $this->oProvider->getReplies()[1]->getData();
        $this->assertSame(1025, strlen($sData));
        $this->assertStringStartsWith("\x80short", $sData);
    }

    public function testPageRequestWhenServiceSuspendedReturnsError7(): void
    {
        $this->oProvider->unicastPacketIn($this->_opPacket(Teletext::OP_TOGGLE_SERVICE));
        $this->oProvider->getReplies();

        $this->oProvider->unicastPacketIn($this->_pageRequestPacket(Teletext::OP_PAGE_REQUEST, '1', '100'));
        $sData = $this->oProvider->getReplies()[0]->getData();
        $this->assertSame(Teletext::ERROR_SERVICE_SUSPENDED, ord($sData[0]));
    }

    public function testPageRequestWithDelayUsesTheSameHandler(): void
    {
        $this->oStorage->shouldReceive('getPage')->with('2', '200', 1)->andReturn(str_repeat('B', 1024));
        $this->oProvider->unicastPacketIn($this->_pageRequestPacket(Teletext::OP_PAGE_REQUEST_DELAY, '2', '200'));
        $this->assertCount(2, $this->oProvider->getReplies());
    }

    public function testDiscPageUsesTheSameHandler(): void
    {
        $this->oStorage->shouldReceive('getPage')->with('2', '200', 1)->andReturn(str_repeat('B', 1024));
        $this->oProvider->unicastPacketIn($this->_pageRequestPacket(Teletext::OP_DISC_PAGE, '2', '200'));
        $this->assertCount(2, $this->oProvider->getReplies());
    }

    // -------------------------------------------------------------------------
    // Subpages
    // -------------------------------------------------------------------------

    public function testOmittedSubpageFieldRequestsSubpage1(): void
    {
        $this->oStorage->shouldReceive('getPage')->once()->with('1', '100', 1)->andReturn(str_repeat('A', 1024));
        $this->oProvider->unicastPacketIn($this->_pageRequestPacket(Teletext::OP_PAGE_REQUEST, '1', '100'));
        $this->assertCount(2, $this->oProvider->getReplies());
    }

    public function testAllZeroSubpageFieldAlsoMeansSubpage1(): void
    {
        $this->oStorage->shouldReceive('getPage')->once()->with('1', '100', 1)->andReturn(str_repeat('A', 1024));
        $this->oProvider->unicastPacketIn($this->_pageRequestPacket(Teletext::OP_PAGE_REQUEST, '1', '100', '0000'));
        $this->assertCount(2, $this->oProvider->getReplies());
    }

    public function testExplicitSubpageFieldIsPassedToStorage(): void
    {
        $this->oStorage->shouldReceive('getPage')->once()->with('1', '100', 2)->andReturn(str_repeat('B', 1024));
        $this->oProvider->unicastPacketIn($this->_pageRequestPacket(Teletext::OP_PAGE_REQUEST, '1', '100', '0002'));
        $this->assertCount(2, $this->oProvider->getReplies());
    }

    public function testSubpageFieldWithoutLeadingZerosIsAccepted(): void
    {
        $this->oStorage->shouldReceive('getPage')->once()->with('1', '100', 42)->andReturn(str_repeat('B', 1024));
        $this->oProvider->unicastPacketIn($this->_pageRequestPacket(Teletext::OP_PAGE_REQUEST, '1', '100', '42'));
        $this->assertCount(2, $this->oProvider->getReplies());
    }

    public function testUnknownSubpageReturnsNotFound(): void
    {
        $this->oStorage->shouldReceive('getPage')->with('1', '100', 3)->andReturn(null);
        $this->oProvider->unicastPacketIn($this->_pageRequestPacket(Teletext::OP_PAGE_REQUEST, '1', '100', '0003'));
        $sData = $this->oProvider->getReplies()[0]->getData();
        $this->assertSame(Teletext::ERROR_NOT_FOUND, ord($sData[0]));
    }

    public function testMalformedSubpageFieldReturnsBadPageError(): void
    {
        $this->oStorage->shouldNotReceive('getPage');
        $this->oProvider->unicastPacketIn($this->_pageRequestPacket(Teletext::OP_PAGE_REQUEST, '1', '100', 'XXXX'));
        $sData = $this->oProvider->getReplies()[0]->getData();
        $this->assertSame(Teletext::ERROR_BAD_PAGE, ord($sData[0]));
    }

    public function testPageDataReportsTheServedSubpageInBcd(): void
    {
        $this->oStorage->shouldReceive('getPage')->with('1', '100', 23)->andReturn(str_repeat('A', 1024));
        $this->oProvider->unicastPacketIn($this->_pageRequestPacket(Teletext::OP_PAGE_REQUEST, '1', '100', '0023'));

        $sData = $this->oProvider->getReplies()[1]->getData();
        $this->assertSame(0x00, ord($sData[1 + 0x3FE]));
        $this->assertSame(0x23, ord($sData[1 + 0x3FF]));
    }

    public function testPageDataReportsAFourDigitSubpageInBcd(): void
    {
        $this->oStorage->shouldReceive('getPage')->with('1', '100', 1234)->andReturn(str_repeat('A', 1024));
        $this->oProvider->unicastPacketIn($this->_pageRequestPacket(Teletext::OP_PAGE_REQUEST, '1', '100', '1234'));

        $sData = $this->oProvider->getReplies()[1]->getData();
        $this->assertSame(0x12, ord($sData[1 + 0x3FE]));
        $this->assertSame(0x34, ord($sData[1 + 0x3FF]));
    }

    public function testDefaultSubpage1StillReportsBcd0001(): void
    {
        $this->oStorage->shouldReceive('getPage')->with('1', '100', 1)->andReturn(str_repeat('A', 1024));
        $this->oProvider->unicastPacketIn($this->_pageRequestPacket(Teletext::OP_PAGE_REQUEST, '1', '100'));

        $sData = $this->oProvider->getReplies()[1]->getData();
        $this->assertSame(0x00, ord($sData[1 + 0x3FE]));
        $this->assertSame(0x01, ord($sData[1 + 0x3FF]));
    }

    // -------------------------------------------------------------------------
    // Cancel request (op 0x82)
    // -------------------------------------------------------------------------

    public function testCancelRequestRepliesSuccess(): void
    {
        $this->oProvider->unicastPacketIn($this->_opPacket(Teletext::OP_CANCEL_REQUEST));
        $oReply = $this->oProvider->getReplies()[0];
        $this->assertSame(Teletext::PORT_SERVER_REPLY, $oReply->getPort());
        $this->assertSame("\x00", $oReply->getData());
    }

    // -------------------------------------------------------------------------
    // Read max users (op 0x83)
    // -------------------------------------------------------------------------

    public function testReadMaxUsersRepliesConfiguredValue(): void
    {
        config::overrideValue('teletext_max_users', 42);
        $this->oProvider->unicastPacketIn($this->_opPacket(Teletext::OP_READ_MAX_USERS));
        $sData = $this->oProvider->getReplies()[0]->getData();
        $this->assertSame("\x00" . chr(42), $sData);
    }

    // -------------------------------------------------------------------------
    // Read date/time (op 0x84)
    // -------------------------------------------------------------------------

    public function testReadDateTimeRepliesFormattedString(): void
    {
        $this->oProvider->unicastPacketIn($this->_opPacket(Teletext::OP_READ_DATETIME));
        $sData = $this->oProvider->getReplies()[0]->getData();
        $this->assertSame("\x0014:32:0715/06/2026", $sData);
    }

    // -------------------------------------------------------------------------
    // Logoff (op 0x85)
    // -------------------------------------------------------------------------

    public function testLogoffRepliesSuccess(): void
    {
        $this->oProvider->unicastPacketIn($this->_opPacket(Teletext::OP_LOGOFF));
        $this->assertSame("\x00", $this->oProvider->getReplies()[0]->getData());
    }

    // -------------------------------------------------------------------------
    // Read port data (op 0x87)
    // -------------------------------------------------------------------------

    public function testReadPortDataRepliesNotSupported(): void
    {
        $this->oProvider->unicastPacketIn($this->_opPacket(Teletext::OP_READ_PORT_DATA, chr(3)));
        $sData = $this->oProvider->getReplies()[0]->getData();
        $this->assertSame(Teletext::ERROR_NOT_SUPPORTED, ord($sData[0]));
    }

    // -------------------------------------------------------------------------
    // View screen (op 0x88)
    // -------------------------------------------------------------------------

    public function testViewScreenRepliesSuccess(): void
    {
        $this->oProvider->unicastPacketIn($this->_opPacket(Teletext::OP_VIEW_SCREEN));
        $this->assertSame("\x00", $this->oProvider->getReplies()[0]->getData());
    }

    // -------------------------------------------------------------------------
    // Toggle service (op 0x8A)
    // -------------------------------------------------------------------------

    public function testToggleServiceTogglesAndReportsState(): void
    {
        $this->assertTrue($this->oProvider->isServiceActive());

        $this->oProvider->unicastPacketIn($this->_opPacket(Teletext::OP_TOGGLE_SERVICE));
        $this->assertSame("\x00\x00", $this->oProvider->getReplies()[0]->getData());
        $this->assertFalse($this->oProvider->isServiceActive());

        $this->oProvider->unicastPacketIn($this->_opPacket(Teletext::OP_TOGGLE_SERVICE));
        $this->assertSame("\x00\x01", $this->oProvider->getReplies()[0]->getData());
        $this->assertTrue($this->oProvider->isServiceActive());
    }

    // -------------------------------------------------------------------------
    // Toggle header (op 0x8B)
    // -------------------------------------------------------------------------

    public function testToggleHeaderTogglesAndReportsState(): void
    {
        $this->assertTrue($this->oProvider->isHeaderOn());

        $this->oProvider->unicastPacketIn($this->_opPacket(Teletext::OP_TOGGLE_HEADER));
        $this->assertSame("\x00\x00", $this->oProvider->getReplies()[0]->getData());
        $this->assertFalse($this->oProvider->isHeaderOn());
    }

    // -------------------------------------------------------------------------
    // Unknown operation / unknown port
    // -------------------------------------------------------------------------

    public function testUnknownOpReturnsUnknownFunctionError(): void
    {
        $this->oProvider->unicastPacketIn($this->_opPacket(0xFF));
        $sData = $this->oProvider->getReplies()[0]->getData();
        $this->assertSame(Teletext::ERROR_UNKNOWN_FUNCTION, ord($sData[0]));
    }

    public function testClientRequestPortIgnoredViaBroadcast(): void
    {
        $this->oProvider->broadcastPacketIn($this->_opPacket(Teletext::OP_READ_VERSION));
        $this->assertCount(0, $this->oProvider->getReplies());
    }

    public function testUnknownPortLogsAndProducesNoReply(): void
    {
        $oPacket = new EconetPacket();
        $oPacket->setPort(0xAB);
        $oPacket->setFlags(0x80);
        $oPacket->setSourceNetwork(0);
        $oPacket->setSourceStation(1);
        $oPacket->setData('');
        $this->oProvider->unicastPacketIn($oPacket);
        $this->assertCount(0, $this->oProvider->getReplies());
    }

    // -------------------------------------------------------------------------
    // Current page broadcast (port 0xB5)
    // -------------------------------------------------------------------------

    public function testBroadcastCurrentPageProducesNoPacketWhenNothingServedYet(): void
    {
        $this->oProvider->broadcastCurrentPage();
        $this->assertCount(0, $this->oProvider->getReplies());
    }

    public function testBroadcastCurrentPageProducesPacketAfterAPageWasServed(): void
    {
        $this->oStorage->shouldReceive('getPage')->with('3', '150', 1)->andReturn(str_repeat('A', 1024));
        $this->oProvider->unicastPacketIn($this->_pageRequestPacket(Teletext::OP_PAGE_REQUEST, '3', '150'));
        $this->oProvider->getReplies();

        $this->oProvider->broadcastCurrentPage();
        $aReplies = $this->oProvider->getReplies();

        $this->assertCount(1, $aReplies);
        $this->assertSame(Teletext::PORT_CURRENT_PAGE, $aReplies[0]->getPort());
        $this->assertSame(255, $aReplies[0]->getDestinationStation());
        $this->assertSame('3150', $aReplies[0]->getData());
    }

    public function testBroadcastCurrentPageReportsAHexPageNumberUppercased(): void
    {
        $this->oStorage->shouldReceive('getPage')->with('1', '1B0', 1)->andReturn(str_repeat('A', 1024));
        $this->oProvider->unicastPacketIn($this->_pageRequestPacket(Teletext::OP_PAGE_REQUEST, '1', '1b0'));
        $this->oProvider->getReplies();

        $this->oProvider->broadcastCurrentPage();
        $aReplies = $this->oProvider->getReplies();

        $this->assertSame('11B0', $aReplies[0]->getData());
    }

    // -------------------------------------------------------------------------
    // Admin support
    // -------------------------------------------------------------------------

    public function testGetChannelSummariesReflectsStorage(): void
    {
        $this->oStorage->shouldReceive('getChannels')->andReturn(['1', '3']);
        $this->oStorage->shouldReceive('getPages')->with('1')->andReturn(['100', '101']);
        $this->oStorage->shouldReceive('getPages')->with('3')->andReturn(['300']);

        $this->assertSame(
            [
                ['channel' => '1', 'page_count' => 2],
                ['channel' => '3', 'page_count' => 1],
            ],
            $this->oProvider->getChannelSummaries()
        );
    }

    // -------------------------------------------------------------------------
    // Teefax refresh (housekeeping-driven background import)
    // -------------------------------------------------------------------------

    public function testCheckTeefaxRefreshDoesNothingWhenNoChannelConfigured(): void
    {
        config::overrideValue('teletext_teefax_channel', '');
        $this->oProvider->checkTeefaxRefresh();
        $this->assertSame([], $this->oProvider->capSpawnedChannels);
    }

    public function testCheckTeefaxRefreshDoesNothingWhenChannelIsNotASingleDigit(): void
    {
        config::overrideValue('teletext_teefax_channel', '99');
        $this->oProvider->checkTeefaxRefresh();
        $this->assertSame([], $this->oProvider->capSpawnedChannels);
    }

    public function testCheckTeefaxRefreshSpawnsWhenNeverImported(): void
    {
        config::overrideValue('teletext_teefax_channel', '7');
        $this->oProvider->stubTeefaxImportedTimes = [];

        $this->oProvider->checkTeefaxRefresh();

        $this->assertSame(['7'], $this->oProvider->capSpawnedChannels);
    }

    public function testCheckTeefaxRefreshSkipsWhenRecentlyImported(): void
    {
        config::overrideValue('teletext_teefax_channel', '7');
        config::overrideValue('teletext_teefax_refresh_interval', 3600);
        $this->oProvider->stubNowTimestamp = 1_000_000;
        $this->oProvider->stubTeefaxImportedTimes = ['7' => 999_000]; // 1000s ago, well within the hour

        $this->oProvider->checkTeefaxRefresh();

        $this->assertSame([], $this->oProvider->capSpawnedChannels);
    }

    public function testCheckTeefaxRefreshSpawnsWhenIntervalHasElapsed(): void
    {
        config::overrideValue('teletext_teefax_channel', '7');
        config::overrideValue('teletext_teefax_refresh_interval', 3600);
        $this->oProvider->stubNowTimestamp = 1_010_000;
        $this->oProvider->stubTeefaxImportedTimes = ['7' => 1_000_000]; // 10000s ago, over the hour

        $this->oProvider->checkTeefaxRefresh();

        $this->assertSame(['7'], $this->oProvider->capSpawnedChannels);
    }

    public function testCheckTeefaxRefreshDoesNotSpawnWhileAlreadyRunning(): void
    {
        config::overrideValue('teletext_teefax_channel', '7');
        $this->oProvider->stubTeefaxImportedTimes = [];

        $oRunningProcess = Mockery::mock(\React\ChildProcess\Process::class, ['dummy'])->makePartial();
        $oRunningProcess->shouldReceive('isRunning')->andReturn(true);

        $oRefl = new ReflectionProperty(Teletext::class, 'oTeefaxProcess');
        $oRefl->setAccessible(true);
        $oRefl->setValue($this->oProvider, $oRunningProcess);

        $this->oProvider->checkTeefaxRefresh();

        $this->assertSame([], $this->oProvider->capSpawnedChannels);
    }

    public function testCheckTeefaxRefreshSpawnsAgainOnceThePreviousProcessHasFinished(): void
    {
        config::overrideValue('teletext_teefax_channel', '7');
        $this->oProvider->stubTeefaxImportedTimes = [];

        $oFinishedProcess = Mockery::mock(\React\ChildProcess\Process::class, ['dummy'])->makePartial();
        $oFinishedProcess->shouldReceive('isRunning')->andReturn(false);

        $oRefl = new ReflectionProperty(Teletext::class, 'oTeefaxProcess');
        $oRefl->setAccessible(true);
        $oRefl->setValue($this->oProvider, $oFinishedProcess);

        $this->oProvider->checkTeefaxRefresh();

        $this->assertSame(['7'], $this->oProvider->capSpawnedChannels);
    }

    public function testIsTeefaxRefreshDueTrueWhenNeverImported(): void
    {
        $this->oProvider->stubTeefaxImportedTimes = [];
        $this->assertTrue($this->oProvider->isTeefaxRefreshDue('7'));
    }

    public function testIsTeefaxRefreshDueFalseWithinTheInterval(): void
    {
        config::overrideValue('teletext_teefax_refresh_interval', 3600);
        $this->oProvider->stubNowTimestamp = 1_000_000;
        $this->oProvider->stubTeefaxImportedTimes = ['7' => 999_500];

        $this->assertFalse($this->oProvider->isTeefaxRefreshDue('7'));
    }

    public function testIsTeefaxRefreshDueTrueOnceTheIntervalHasElapsed(): void
    {
        config::overrideValue('teletext_teefax_refresh_interval', 3600);
        $this->oProvider->stubNowTimestamp = 1_010_000;
        $this->oProvider->stubTeefaxImportedTimes = ['7' => 1_000_000];

        $this->assertTrue($this->oProvider->isTeefaxRefreshDue('7'));
    }

    public function testSpawnTeefaxImportBuildsTheExpectedCommand(): void
    {
        $oLogger = new Logger('teletext-real-spawn-test');
        $oLogger->pushHandler(new NullHandler());
        $oProvider = new Teletext($oLogger, $this->oStorage);

        $oMethod = new ReflectionMethod(Teletext::class, '_spawnTeefaxImport');
        $oMethod->setAccessible(true);
        $oProcess = $oMethod->invoke($oProvider, '7');

        $this->assertInstanceOf(\React\ChildProcess\Process::class, $oProcess);
        $this->assertStringContainsString('teefax-import', $oProcess->getCommand());
        $this->assertMatchesRegularExpression("/--channel='?7'?/", $oProcess->getCommand());
    }

    // -------------------------------------------------------------------------
    // triggerTeefaxImport() — the admin "refresh now" action. Reaches
    // ServiceDispatcher::create()->getLoop(), so these swap in a stub
    // singleton the same way MaceMailAdminTest/MaceMailControllerTest do.
    // -------------------------------------------------------------------------

    protected function _swapServiceDispatcherSingleton(): void
    {
        $oStub = $this->createStub(\HomeLan\FileStore\Services\ServiceDispatcher::class);
        $rp = new ReflectionProperty(\HomeLan\FileStore\Services\ServiceDispatcher::class, 'oSingleton');
        $rp->setAccessible(true);
        $rp->setValue(null, $oStub);
    }

    protected function _restoreServiceDispatcherSingleton(): void
    {
        $rp = new ReflectionProperty(\HomeLan\FileStore\Services\ServiceDispatcher::class, 'oSingleton');
        $rp->setAccessible(true);
        $rp->setValue(null, null);
    }

    public function testTriggerTeefaxImportReturnsFalseWhenNoChannelConfigured(): void
    {
        config::overrideValue('teletext_teefax_channel', '');
        $this->_swapServiceDispatcherSingleton();

        $bResult = $this->oProvider->triggerTeefaxImport();

        $this->_restoreServiceDispatcherSingleton();
        $this->assertFalse($bResult);
        $this->assertSame([], $this->oProvider->capSpawnedChannels);
    }

    public function testTriggerTeefaxImportStartsAnImportRegardlessOfDueTime(): void
    {
        config::overrideValue('teletext_teefax_channel', '7');
        config::overrideValue('teletext_teefax_refresh_interval', 3600);
        $this->oProvider->stubNowTimestamp = 1_000_000;
        $this->oProvider->stubTeefaxImportedTimes = ['7' => 999_999]; // just imported, not due
        $this->_swapServiceDispatcherSingleton();

        $bResult = $this->oProvider->triggerTeefaxImport();

        $this->_restoreServiceDispatcherSingleton();
        $this->assertTrue($bResult);
        $this->assertSame(['7'], $this->oProvider->capSpawnedChannels);
    }

    public function testTriggerTeefaxImportReturnsFalseWhileAlreadyRunning(): void
    {
        config::overrideValue('teletext_teefax_channel', '7');
        $this->_swapServiceDispatcherSingleton();

        $oRunningProcess = Mockery::mock(\React\ChildProcess\Process::class, ['dummy'])->makePartial();
        $oRunningProcess->shouldReceive('isRunning')->andReturn(true);
        $oRefl = new ReflectionProperty(Teletext::class, 'oTeefaxProcess');
        $oRefl->setAccessible(true);
        $oRefl->setValue($this->oProvider, $oRunningProcess);

        $bResult = $this->oProvider->triggerTeefaxImport();

        $this->_restoreServiceDispatcherSingleton();
        $this->assertFalse($bResult);
        $this->assertSame([], $this->oProvider->capSpawnedChannels);
    }
}
