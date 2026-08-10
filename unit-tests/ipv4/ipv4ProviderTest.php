<?php

/*
 * @group unit-tests
 *
 * Tests for IPv4 service provider:
 *   - broadcastPacketIn() — ARP dispatch (DCI-2 and DCI-4)
 *   - unicastPacketIn()   — IPv4 forwarding, ICMP echo, ARP dequeue
 *   - processUnicastIPv4Pkt() — ARP hit, ARP miss, route lookup, no-route unreachable
 *   - houseKeeping()      — timeout expired queues
 *   - Admin accessors     — getName, getServicePorts, getInterfaces, getRoutes, etc.
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
// Testable subclass – overrides factory methods so tables are initialised
// from inline config strings rather than reading files.
// ---------------------------------------------------------------------------
class TestableIPv4 extends IPv4
{
    private string $sIfaceConfig;
    private string $sRouteConfig;
    private string $sNatConfig;

    public function __construct(
        \Psr\Log\LoggerInterface $oLogger,
        string $sIfaceConfig = '',
        string $sRouteConfig = '',
        string $sNatConfig   = ''
    ) {
        $this->sIfaceConfig = $sIfaceConfig;
        $this->sRouteConfig = $sRouteConfig;
        $this->sNatConfig   = $sNatConfig;
        parent::__construct($oLogger);
    }

    protected function createInterfaces(?string $sConfig = null): Interfaces
    {
        return new Interfaces($this, $this->oLogger, $this->sIfaceConfig);
    }

    protected function createRoutes(?string $sConfig = null): Routes
    {
        return new Routes($this, $this->oLogger, $this->sRouteConfig);
    }

    protected function createNat(?string $sConfig = null): NAT
    {
        return new NAT($this, $this->oLogger, $this->sNatConfig);
    }
}

// ---------------------------------------------------------------------------
// Tests
// ---------------------------------------------------------------------------
class ipv4ProviderTest extends TestCase
{
    // Interface under test: net=1, stn=24, ip=192.168.0.1, mask=255.255.255.0
    private const IFACE_CFG = "1 24 192.168.0.1 255.255.255.0\n";
    private const IFACE_IP  = '192.168.0.1';
    private const IFACE_NET = 1;
    private const IFACE_STN = 24;
    private const OTHER_IP  = '10.0.0.1';

    private TestableIPv4 $oIPv4;
    private Logger $oLogger;

    protected function setUp(): void
    {
        $this->oLogger = new Logger('test');
        $this->oLogger->pushHandler(new NullHandler());
        $this->oIPv4 = new TestableIPv4($this->oLogger, self::IFACE_CFG);
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

    private function buildIPv4(
        string $sSrcIP,
        string $sDstIP,
        int    $iProtocol,
        string $sPayload = '',
        int    $iTtl     = 64
    ): string {
        $iVerLen = 0x45;
        $iLength = 20 + strlen($sPayload);
        $sHeader = pack('CCnnnCCn', $iVerLen, 0, $iLength, 1, 0, $iTtl, $iProtocol, 0)
            . inet_pton($sSrcIP)
            . inet_pton($sDstIP);
        $aPairs = unpack('n*', $sHeader);
        $iSum   = array_sum($aPairs);
        while ($iSum >> 16) { $iSum = ($iSum >> 16) + ($iSum & 0xffff); }
        $iChecksum = ~$iSum & 0xffff;
        $sHeader[10] = chr($iChecksum >> 8);
        $sHeader[11] = chr($iChecksum & 0xff);
        return $sHeader . $sPayload;
    }

    private function buildIcmpEchoRequest(int $iId = 1, int $iSeq = 1): string
    {
        $sIcmp = pack('CCnnn', 8, 0, 0, $iId, $iSeq);
        $aPairs = unpack('n*', $sIcmp);
        $iSum   = array_sum($aPairs);
        while ($iSum >> 16) { $iSum = ($iSum >> 16) + ($iSum & 0xffff); }
        $iChecksum = ~$iSum & 0xffff;
        return pack('CCnnn', 8, 0, $iChecksum, $iId, $iSeq);
    }

    private function ipv4EconetPkt(
        string $sSrcIP,
        string $sDstIP,
        int    $iProtocol,
        string $sPayload  = '',
        int    $iSrcNet   = 1,
        int    $iSrcStn   = 5,
        int    $iTtl      = 64
    ): EconetPacket {
        $oPkt = new EconetPacket();
        $oPkt->setFlags(0x01);
        $oPkt->setPort(0xD2);
        $oPkt->setSourceNetwork($iSrcNet);
        $oPkt->setSourceStation($iSrcStn);
        $oPkt->setDestinationNetwork(self::IFACE_NET);
        $oPkt->setDestinationStation(self::IFACE_STN);
        $oPkt->setData($this->buildIPv4($sSrcIP, $sDstIP, $iProtocol, $sPayload, $iTtl));
        return $oPkt;
    }

    // -----------------------------------------------------------------------
    // Admin accessors
    // -----------------------------------------------------------------------

    public function testGetNameReturnsIPv4(): void
    {
        $this->assertSame('IPv4', $this->oIPv4->getName());
    }

    public function testGetServicePortsContainsDci2Port(): void
    {
        $this->assertContains(0xD2, $this->oIPv4->getServicePorts());
    }

    public function testGetInterfacesReturnsLoadedInterfaces(): void
    {
        $aIfaces = $this->oIPv4->getInterfaces();
        $this->assertCount(1, $aIfaces);
        $this->assertSame(self::IFACE_IP, $aIfaces[0]['ipaddr']);
    }

    public function testGetRoutesReturnsEmptyArrayWhenNoRoutesConfigured(): void
    {
        $this->assertSame([], $this->oIPv4->getRoutes());
    }

    public function testGetNatEntriesReturnsEmptyArrayWhenNoNatConfigured(): void
    {
        $this->assertIsArray($this->oIPv4->getNatEntries());
    }

    public function testGetConnTrackReturnsEmptyArrayWhenNoNatConfigured(): void
    {
        $this->assertIsArray($this->oIPv4->getConnTrack());
    }

    public function testGetArpEntriesIsEmptyInitially(): void
    {
        $this->assertSame([], $this->oIPv4->getArpEntries());
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
        $this->oIPv4->getReplies();
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
        $this->oIPv4->getReplies();
        $this->assertEmpty($this->oIPv4->getReplies());
    }

    public function testMultipleBroadcastsAccumulateInBuffer(): void
    {
        $this->oIPv4->broadcastPacketIn($this->whoHasBroadcast('192.168.0.5', self::IFACE_IP, 0x21));
        $this->oIPv4->broadcastPacketIn($this->whoHasBroadcast('192.168.0.6', self::IFACE_IP, 0xA1));
        $this->assertCount(2, $this->oIPv4->getReplies());
    }

    // -----------------------------------------------------------------------
    // unicastPacketIn — IPv4 → ICMP echo request to interface IP
    // -----------------------------------------------------------------------

    public function testIcmpEchoRequestToInterfaceGeneratesOneReply(): void
    {
        $sIcmp = $this->buildIcmpEchoRequest();
        $this->oIPv4->unicastPacketIn(
            $this->ipv4EconetPkt('192.168.0.5', self::IFACE_IP, 0x01, $sIcmp)
        );
        $this->assertCount(1, $this->oIPv4->getReplies());
    }

    public function testIcmpEchoReplyIsAddressedToOriginalRequester(): void
    {
        $sIcmp = $this->buildIcmpEchoRequest();
        $this->oIPv4->unicastPacketIn(
            $this->ipv4EconetPkt('192.168.0.5', self::IFACE_IP, 0x01, $sIcmp, iSrcNet: 1, iSrcStn: 5)
        );
        [$oReply] = $this->oIPv4->getReplies();
        $this->assertSame(5, $oReply->getDestinationStation());
        $this->assertSame(1, $oReply->getDestinationNetwork());
    }

    public function testIcmpEchoReplySentFromInterfaceStation(): void
    {
        $sIcmp = $this->buildIcmpEchoRequest();
        $this->oIPv4->unicastPacketIn(
            $this->ipv4EconetPkt('192.168.0.5', self::IFACE_IP, 0x01, $sIcmp)
        );
        [$oReply] = $this->oIPv4->getReplies();
        $this->assertSame(self::IFACE_STN, $oReply->getSourceStation());
        $this->assertSame(self::IFACE_NET, $oReply->getSourceNetwork());
    }

    public function testIcmpEchoReplyHasIPv4Flags(): void
    {
        $sIcmp = $this->buildIcmpEchoRequest();
        $this->oIPv4->unicastPacketIn(
            $this->ipv4EconetPkt('192.168.0.5', self::IFACE_IP, 0x01, $sIcmp)
        );
        [$oReply] = $this->oIPv4->getReplies();
        $this->assertSame(0x01, $oReply->getFlags());
    }

    public function testNonEchoIcmpToInterfaceGeneratesNoReply(): void
    {
        // ICMP type=3 (unreachable) — not an echo request, should be ignored
        $sIcmp = pack('CCnnn', 3, 0, 0, 0, 0);
        $this->oIPv4->unicastPacketIn(
            $this->ipv4EconetPkt('192.168.0.5', self::IFACE_IP, 0x01, $sIcmp)
        );
        $this->assertEmpty($this->oIPv4->getReplies());
    }

    // -----------------------------------------------------------------------
    // unicastPacketIn — IPv4 forwarding with ARP hit
    // -----------------------------------------------------------------------

    public function testForwardWithArpHitAddressesByArpEntry(): void
    {
        // Pre-populate ARP for 192.168.0.100 → stn 100
        $this->oIPv4->unicastPacketIn($this->isAtUnicast('192.168.0.100', self::IFACE_IP, 0x22, 1, 100));
        $this->oIPv4->getReplies(); // drain

        $this->oIPv4->unicastPacketIn(
            $this->ipv4EconetPkt('192.168.0.5', '192.168.0.100', 0x06)
        );

        $aReplies = $this->oIPv4->getReplies();
        $this->assertCount(1, $aReplies);
        $oReply = $aReplies[0];
        $this->assertSame(100, $oReply->getDestinationStation());
        $this->assertSame(1,   $oReply->getDestinationNetwork());
    }

    public function testForwardedPacketHasIPv4Flags(): void
    {
        $this->oIPv4->unicastPacketIn($this->isAtUnicast('192.168.0.100', self::IFACE_IP, 0x22, 1, 100));
        $this->oIPv4->getReplies();

        $this->oIPv4->unicastPacketIn(
            $this->ipv4EconetPkt('192.168.0.5', '192.168.0.100', 0x06)
        );

        [$oReply] = $this->oIPv4->getReplies();
        $this->assertSame(0x01, $oReply->getFlags());
    }

    public function testForwardedPacketTtlDecremented(): void
    {
        $this->oIPv4->unicastPacketIn($this->isAtUnicast('192.168.0.100', self::IFACE_IP, 0x22, 1, 100));
        $this->oIPv4->getReplies();

        $this->oIPv4->unicastPacketIn(
            $this->ipv4EconetPkt('192.168.0.5', '192.168.0.100', 0x06, iTtl: 64)
        );

        [$oReply] = $this->oIPv4->getReplies();
        $this->assertSame(63, ord($oReply->getData()[8]));
    }

    // -----------------------------------------------------------------------
    // unicastPacketIn — IPv4 forwarding with ARP miss (queues who-has)
    // -----------------------------------------------------------------------

    public function testForwardWithArpMissQueuesBroadcastArpRequest(): void
    {
        $this->oIPv4->unicastPacketIn(
            $this->ipv4EconetPkt('192.168.0.5', '192.168.0.100', 0x06)
        );

        $aReplies = $this->oIPv4->getReplies();
        $this->assertCount(1, $aReplies);
        // ArpWhoHas is a broadcast to stn=255
        $this->assertSame(255, $aReplies[0]->getDestinationStation());
    }

    public function testForwardWithArpMissArpRequestHasDci2Flags(): void
    {
        $this->oIPv4->unicastPacketIn(
            $this->ipv4EconetPkt('192.168.0.5', '192.168.0.100', 0x06)
        );

        [$oArpWho] = $this->oIPv4->getReplies();
        $this->assertSame(0x21, $oArpWho->getFlags());
    }

    // -----------------------------------------------------------------------
    // dequeueWaitingPackets — packets queued on ARP miss dispatched on is-at
    // -----------------------------------------------------------------------

    public function testArpIsAtDequeuesWaitingPacket(): void
    {
        // Trigger ARP miss — packet goes to queue
        $this->oIPv4->unicastPacketIn(
            $this->ipv4EconetPkt('192.168.0.5', '192.168.0.100', 0x06)
        );
        $this->oIPv4->getReplies(); // drain ArpWhoHas

        // Now receive is-at for 192.168.0.100 from stn 100
        $this->oIPv4->unicastPacketIn($this->isAtUnicast('192.168.0.100', self::IFACE_IP, 0x22, 1, 100));

        $aReplies = $this->oIPv4->getReplies();
        $this->assertCount(1, $aReplies);
        $this->assertSame(100, $aReplies[0]->getDestinationStation());
    }

    public function testMultiplePacketsQueuedOnArpMissAllDequeued(): void
    {
        $this->oIPv4->unicastPacketIn(
            $this->ipv4EconetPkt('192.168.0.5', '192.168.0.100', 0x06)
        );
        $this->oIPv4->unicastPacketIn(
            $this->ipv4EconetPkt('192.168.0.6', '192.168.0.100', 0x06)
        );
        $this->oIPv4->getReplies(); // drain ArpWhoHas (may be 1 or 2 depending on coalescing)

        $this->oIPv4->unicastPacketIn($this->isAtUnicast('192.168.0.100', self::IFACE_IP, 0x22, 1, 100));

        $aReplies = $this->oIPv4->getReplies();
        $this->assertCount(2, $aReplies);
    }

    // -----------------------------------------------------------------------
    // unicastPacketIn — no route → ICMP network unreachable
    // -----------------------------------------------------------------------

    public function testNoRouteToDestGeneratesIcmpUnreachable(): void
    {
        // src=192.168.0.5 (on interface subnet), dst=10.0.0.1 (no route)
        $this->oIPv4->unicastPacketIn(
            $this->ipv4EconetPkt('192.168.0.5', '10.0.0.1', 0x06)
        );

        $aReplies = $this->oIPv4->getReplies();
        $this->assertCount(1, $aReplies);
    }

    public function testNoRouteReplyAddressedToOriginalSource(): void
    {
        $this->oIPv4->unicastPacketIn(
            $this->ipv4EconetPkt('192.168.0.5', '10.0.0.1', 0x06, iSrcNet: 1, iSrcStn: 5)
        );
        [$oReply] = $this->oIPv4->getReplies();
        $this->assertSame(5, $oReply->getDestinationStation());
        $this->assertSame(1, $oReply->getDestinationNetwork());
    }

    // -----------------------------------------------------------------------
    // unicastPacketIn — invalid IPv4 packet is silently dropped
    // -----------------------------------------------------------------------

    public function testInvalidIPv4PacketIsDroppedWithNoReply(): void
    {
        // IHL=4 → header length 16 < 20; triggers exception inside IPv4Request
        $oPkt = new EconetPacket();
        $oPkt->setFlags(0x01);
        $oPkt->setPort(0xD2);
        $oPkt->setSourceNetwork(1);
        $oPkt->setSourceStation(5);
        $oPkt->setDestinationNetwork(self::IFACE_NET);
        $oPkt->setDestinationStation(self::IFACE_STN);
        $oPkt->setData("\x44" . str_repeat("\x00", 19));

        $this->oIPv4->unicastPacketIn($oPkt);
        $this->assertEmpty($this->oIPv4->getReplies());
    }

    // -----------------------------------------------------------------------
    // houseKeeping — expires timed-out ARP queues
    // -----------------------------------------------------------------------

    public function testHouseKeepingRemovesExpiredQueue(): void
    {
        // Trigger ARP miss to create a queue entry
        $this->oIPv4->unicastPacketIn(
            $this->ipv4EconetPkt('192.168.0.5', '192.168.0.200', 0x06)
        );
        $this->oIPv4->getReplies(); // drain ArpWhoHas

        // Wind back the queue's timeout so it appears expired
        $rProp = new \ReflectionProperty(IPv4::class, 'aPacketQueue');
        $rProp->setAccessible(true);
        $aQueue = $rProp->getValue($this->oIPv4);
        $aQueue['192.168.0.200']['timeout'] = time() - 1;
        $rProp->setValue($this->oIPv4, $aQueue);

        // Run housekeeping — should drop the queue
        $this->oIPv4->houseKeeping();

        // Verify the queue is gone via reflection
        $aQueue = $rProp->getValue($this->oIPv4);
        $this->assertArrayNotHasKey('192.168.0.200', $aQueue);
    }

    public function testHouseKeepingDoesNotRemoveUnexpiredQueue(): void
    {
        $this->oIPv4->unicastPacketIn(
            $this->ipv4EconetPkt('192.168.0.5', '192.168.0.201', 0x06)
        );
        $this->oIPv4->getReplies();

        // Run housekeeping — queue is fresh, should survive
        $this->oIPv4->houseKeeping();

        $rProp = new \ReflectionProperty(IPv4::class, 'aPacketQueue');
        $rProp->setAccessible(true);
        $aQueue = $rProp->getValue($this->oIPv4);
        $this->assertArrayHasKey('192.168.0.201', $aQueue);
    }
}
