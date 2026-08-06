<?php

/*
 * @group unit-tests
 *
 * Tests for IPv4 service provider ARP dispatch:
 *   - DCI-2 ARP who-has (0x21) → DCI-2 is-at reply (0x22)
 *   - DCI-4 ARP who-has (0xA1) → DCI-4 is-at reply (0xA2)
 *   - ARP for non-interface IP → no reply
 *   - Incoming ARP (either dialect) caches the sender in the ARP table
 *   - Unicast is-at (0x22 / 0xA2) updates the ARP cache
 *   - getReplies() drains the reply buffer
 */

include_once(__DIR__.'/../../src/include/system.inc.php');

use PHPUnit\Framework\TestCase;
use Monolog\Logger;
use Monolog\Handler\NullHandler;
use HomeLan\FileStore\Messages\EconetPacket;
use HomeLan\FileStore\Services\Provider\IPv4;
use HomeLan\FileStore\Services\Provider\IPv4\Interfaces;
use HomeLan\FileStore\Services\Provider\IPv4\Routes;
use HomeLan\FileStore\Services\Provider\IPv4\NAT;

// ---------------------------------------------------------------------------
// Testable subclass – overrides factory methods so Interfaces is initialised
// from an inline config string rather than reading a file.
// ---------------------------------------------------------------------------
class TestableIPv4 extends IPv4
{
    private string $sIfaceConfig;

    public function __construct(\Psr\Log\LoggerInterface $oLogger, string $sIfaceConfig = '')
    {
        $this->sIfaceConfig = $sIfaceConfig;
        parent::__construct($oLogger);
    }

    protected function createInterfaces(?string $sConfig = null): Interfaces
    {
        return new Interfaces($this, $this->oLogger, $this->sIfaceConfig);
    }

    protected function createRoutes(?string $sConfig = null): Routes
    {
        return new Routes($this, $this->oLogger, '');
    }

    protected function createNat(?string $sConfig = null): NAT
    {
        return new NAT($this, $this->oLogger, '');
    }
}

// ---------------------------------------------------------------------------
// Tests
// ---------------------------------------------------------------------------
class ipv4ProviderTest extends TestCase
{
    // Interface under test: net=1, stn=24, ip=192.168.0.1, mask=255.255.255.0
    private const IFACE_CFG  = "1 24 192.168.0.1 255.255.255.0\n";
    private const IFACE_IP   = '192.168.0.1';
    private const IFACE_NET  = 1;
    private const IFACE_STN  = 24;
    private const OTHER_IP   = '10.0.0.1';

    private TestableIPv4 $oIPv4;

    protected function setUp(): void
    {
        $oLogger = new Logger('test');
        $oLogger->pushHandler(new NullHandler());
        $this->oIPv4 = new TestableIPv4($oLogger, self::IFACE_CFG);
    }

    // -----------------------------------------------------------------------
    // Packet builders
    // -----------------------------------------------------------------------

    private function whoHasBroadcast(
        string $sSenderIP,
        string $sTargetIP,
        int    $iFlags,
        int    $iNet = 1,
        int    $iStn = 5
    ): EconetPacket {
        $oPkt = new EconetPacket();
        $oPkt->setFlags($iFlags);
        $oPkt->setSourceNetwork($iNet);
        $oPkt->setSourceStation($iStn);
        $oPkt->setDestinationNetwork(0);
        $oPkt->setDestinationStation(255);
        $oPkt->setPort(0xD2);
        $oPkt->setData(inet_pton($sSenderIP) . inet_pton($sTargetIP));
        return $oPkt;
    }

    private function isAtUnicast(
        string $sResponderIP,
        string $sRequesterIP,
        int    $iFlags,
        int    $iNet = 1,
        int    $iStn = 10
    ): EconetPacket {
        $oPkt = new EconetPacket();
        $oPkt->setFlags($iFlags);
        $oPkt->setSourceNetwork($iNet);
        $oPkt->setSourceStation($iStn);
        $oPkt->setDestinationNetwork(self::IFACE_NET);
        $oPkt->setDestinationStation(self::IFACE_STN);
        $oPkt->setPort(0xD2);
        $oPkt->setData(inet_pton($sResponderIP) . inet_pton($sRequesterIP));
        return $oPkt;
    }

    // -----------------------------------------------------------------------
    // DCI-2 ARP who-has → DCI-2 reply
    // -----------------------------------------------------------------------

    public function testDci2WhoHasForInterfaceIpGeneratesOneReply(): void
    {
        $this->oIPv4->broadcastPacketIn($this->whoHasBroadcast('192.168.0.5', self::IFACE_IP, 0x21));
        $this->assertCount(1, $this->oIPv4->getReplies());
    }

    public function testDci2WhoHasReplyHasDci2Flags(): void
    {
        $this->oIPv4->broadcastPacketIn($this->whoHasBroadcast('192.168.0.5', self::IFACE_IP, 0x21));
        [$oReply] = $this->oIPv4->getReplies();
        $this->assertSame(0x22, $oReply->getFlags());
    }

    public function testDci2WhoHasReplyAddressedToRequester(): void
    {
        $this->oIPv4->broadcastPacketIn($this->whoHasBroadcast('192.168.0.5', self::IFACE_IP, 0x21, 1, 5));
        [$oReply] = $this->oIPv4->getReplies();
        $this->assertSame(5, $oReply->getDestinationStation());
        $this->assertSame(1, $oReply->getDestinationNetwork());
    }

    public function testDci2WhoHasReplySentFromInterfaceStation(): void
    {
        $this->oIPv4->broadcastPacketIn($this->whoHasBroadcast('192.168.0.5', self::IFACE_IP, 0x21));
        [$oReply] = $this->oIPv4->getReplies();
        $this->assertSame(self::IFACE_STN, $oReply->getSourceStation());
        $this->assertSame(self::IFACE_NET, $oReply->getSourceNetwork());
    }

    public function testDci2WhoHasReplyPayload(): void
    {
        $this->oIPv4->broadcastPacketIn($this->whoHasBroadcast('192.168.0.5', self::IFACE_IP, 0x21));
        [$oReply] = $this->oIPv4->getReplies();
        // payload = requested IP then sender IP
        $this->assertSame(inet_pton(self::IFACE_IP) . inet_pton('192.168.0.5'), $oReply->getData());
    }

    // -----------------------------------------------------------------------
    // DCI-4 ARP who-has → DCI-4 reply
    // -----------------------------------------------------------------------

    public function testDci4WhoHasForInterfaceIpGeneratesOneReply(): void
    {
        $this->oIPv4->broadcastPacketIn($this->whoHasBroadcast('192.168.0.5', self::IFACE_IP, 0xA1));
        $this->assertCount(1, $this->oIPv4->getReplies());
    }

    public function testDci4WhoHasReplyHasDci4Flags(): void
    {
        $this->oIPv4->broadcastPacketIn($this->whoHasBroadcast('192.168.0.5', self::IFACE_IP, 0xA1));
        [$oReply] = $this->oIPv4->getReplies();
        $this->assertSame(0xA2, $oReply->getFlags());
    }

    public function testDci4WhoHasReplyAddressedToRequester(): void
    {
        $this->oIPv4->broadcastPacketIn($this->whoHasBroadcast('192.168.0.5', self::IFACE_IP, 0xA1, 2, 9));
        [$oReply] = $this->oIPv4->getReplies();
        $this->assertSame(9, $oReply->getDestinationStation());
        $this->assertSame(2, $oReply->getDestinationNetwork());
    }

    public function testDci4WhoHasReplySentFromInterfaceStation(): void
    {
        $this->oIPv4->broadcastPacketIn($this->whoHasBroadcast('192.168.0.5', self::IFACE_IP, 0xA1));
        [$oReply] = $this->oIPv4->getReplies();
        $this->assertSame(self::IFACE_STN, $oReply->getSourceStation());
        $this->assertSame(self::IFACE_NET, $oReply->getSourceNetwork());
    }

    public function testDci4WhoHasReplyPayload(): void
    {
        $this->oIPv4->broadcastPacketIn($this->whoHasBroadcast('192.168.0.5', self::IFACE_IP, 0xA1));
        [$oReply] = $this->oIPv4->getReplies();
        $this->assertSame(inet_pton(self::IFACE_IP) . inet_pton('192.168.0.5'), $oReply->getData());
    }

    // DCI-2 and DCI-4 replies carry the same payload — only flags differ
    public function testDci2AndDci4ReplyPayloadsAreIdentical(): void
    {
        $this->oIPv4->broadcastPacketIn($this->whoHasBroadcast('192.168.0.5', self::IFACE_IP, 0x21));
        [$oReply2] = $this->oIPv4->getReplies();

        $this->oIPv4->broadcastPacketIn($this->whoHasBroadcast('192.168.0.5', self::IFACE_IP, 0xA1));
        [$oReply4] = $this->oIPv4->getReplies();

        $this->assertSame($oReply2->getData(), $oReply4->getData());
    }

    // -----------------------------------------------------------------------
    // ARP for non-interface IP → no reply
    // -----------------------------------------------------------------------

    public function testDci2WhoHasForNonInterfaceIpGeneratesNoReply(): void
    {
        $this->oIPv4->broadcastPacketIn($this->whoHasBroadcast('192.168.0.5', self::OTHER_IP, 0x21));
        $this->assertEmpty($this->oIPv4->getReplies());
    }

    public function testDci4WhoHasForNonInterfaceIpGeneratesNoReply(): void
    {
        $this->oIPv4->broadcastPacketIn($this->whoHasBroadcast('192.168.0.5', self::OTHER_IP, 0xA1));
        $this->assertEmpty($this->oIPv4->getReplies());
    }

    // Non-ARP broadcast (different flags) generates no reply
    public function testNonArpBroadcastGeneratesNoReply(): void
    {
        $oPkt = new EconetPacket();
        $oPkt->setFlags(0x01);
        $oPkt->setSourceNetwork(1);
        $oPkt->setSourceStation(5);
        $oPkt->setDestinationNetwork(0);
        $oPkt->setDestinationStation(255);
        $oPkt->setPort(0xD2);
        $oPkt->setData(str_repeat("\x00", 8));
        $this->oIPv4->broadcastPacketIn($oPkt);
        $this->assertEmpty($this->oIPv4->getReplies());
    }

    // -----------------------------------------------------------------------
    // Incoming ARP who-has caches the sender in the ARP table
    // -----------------------------------------------------------------------

    public function testDci2WhoHasCachesSenderIp(): void
    {
        $this->oIPv4->broadcastPacketIn($this->whoHasBroadcast('192.168.0.5', self::IFACE_IP, 0x21, 1, 5));
        $this->oIPv4->getReplies(); // drain
        $aIps = array_column($this->oIPv4->getArpEntries(), 'ipv4');
        $this->assertContains('192.168.0.5', $aIps);
    }

    public function testDci2WhoHasCachesSenderStationAndNetwork(): void
    {
        $this->oIPv4->broadcastPacketIn($this->whoHasBroadcast('192.168.0.5', self::IFACE_IP, 0x21, 1, 5));
        $this->oIPv4->getReplies();
        $aEntries = $this->oIPv4->getArpEntries();
        $aEntry   = array_values(array_filter($aEntries, fn($e) => $e['ipv4'] === '192.168.0.5'))[0];
        $this->assertSame(5, $aEntry['station']);
        $this->assertSame(1, $aEntry['network']);
    }

    public function testDci4WhoHasCachesSenderIp(): void
    {
        $this->oIPv4->broadcastPacketIn($this->whoHasBroadcast('192.168.0.7', self::IFACE_IP, 0xA1, 1, 7));
        $this->oIPv4->getReplies();
        $aIps = array_column($this->oIPv4->getArpEntries(), 'ipv4');
        $this->assertContains('192.168.0.7', $aIps);
    }

    // -----------------------------------------------------------------------
    // Unicast ARP is-at updates the ARP cache
    // -----------------------------------------------------------------------

    public function testDci2IsAtUpdatesArpCache(): void
    {
        $this->oIPv4->unicastPacketIn($this->isAtUnicast('192.168.0.10', self::IFACE_IP, 0x22, 1, 10));
        $aIps = array_column($this->oIPv4->getArpEntries(), 'ipv4');
        $this->assertContains('192.168.0.10', $aIps);
    }

    public function testDci2IsAtCachesCorrectStation(): void
    {
        $this->oIPv4->unicastPacketIn($this->isAtUnicast('192.168.0.10', self::IFACE_IP, 0x22, 1, 10));
        $aEntries = $this->oIPv4->getArpEntries();
        $aEntry   = array_values(array_filter($aEntries, fn($e) => $e['ipv4'] === '192.168.0.10'))[0];
        $this->assertSame(10, $aEntry['station']);
    }

    public function testDci4IsAtUpdatesArpCache(): void
    {
        $this->oIPv4->unicastPacketIn($this->isAtUnicast('192.168.0.11', self::IFACE_IP, 0xA2, 1, 11));
        $aIps = array_column($this->oIPv4->getArpEntries(), 'ipv4');
        $this->assertContains('192.168.0.11', $aIps);
    }

    public function testDci4IsAtCachesCorrectStation(): void
    {
        $this->oIPv4->unicastPacketIn($this->isAtUnicast('192.168.0.11', self::IFACE_IP, 0xA2, 1, 11));
        $aEntries = $this->oIPv4->getArpEntries();
        $aEntry   = array_values(array_filter($aEntries, fn($e) => $e['ipv4'] === '192.168.0.11'))[0];
        $this->assertSame(11, $aEntry['station']);
    }

    // -----------------------------------------------------------------------
    // getReplies() drains the reply buffer
    // -----------------------------------------------------------------------

    public function testGetRepliesDrainsBuffer(): void
    {
        $this->oIPv4->broadcastPacketIn($this->whoHasBroadcast('192.168.0.5', self::IFACE_IP, 0x21));
        $this->oIPv4->getReplies();                      // first call drains
        $this->assertEmpty($this->oIPv4->getReplies()); // second call empty
    }

    public function testMultipleBroadcastsAccumulateInBuffer(): void
    {
        $this->oIPv4->broadcastPacketIn($this->whoHasBroadcast('192.168.0.5', self::IFACE_IP, 0x21));
        $this->oIPv4->broadcastPacketIn($this->whoHasBroadcast('192.168.0.6', self::IFACE_IP, 0xA1));
        $this->assertCount(2, $this->oIPv4->getReplies());
    }
}
