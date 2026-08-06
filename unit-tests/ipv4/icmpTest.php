<?php

/*
 * @group unit-tests
 *
 * Tests for ICMP message classes and IPv4 provider ICMP handling:
 *   - IcmpRequest: decodes echo request fields; isEchoRequest() detection
 *   - IcmpEchoReply: builds a valid IPv4/ICMP echo reply; checksum, addressing, payload
 *   - IcmpUnreachable: builds net-unreachable and host-unreachable packets
 *   - IPv4 provider: echo request to interface IP → echo reply
 *   - IPv4 provider: packet with no route → ICMP network unreachable
 *   - IPv4 provider: non-ICMP packet to interface IP → no reply
 */

include_once(__DIR__.'/../../src/include/system.inc.php');

use PHPUnit\Framework\TestCase;
use Monolog\Logger;
use Monolog\Handler\NullHandler;
use HomeLan\FileStore\Messages\EconetPacket;
use HomeLan\FileStore\Messages\IPv4Request;
use HomeLan\FileStore\Messages\IcmpRequest;
use HomeLan\FileStore\Messages\IcmpEchoReply;
use HomeLan\FileStore\Messages\IcmpUnreachable;
use HomeLan\FileStore\Services\Provider\IPv4;
use HomeLan\FileStore\Services\Provider\IPv4\Interfaces;
use HomeLan\FileStore\Services\Provider\IPv4\Routes;
use HomeLan\FileStore\Services\Provider\IPv4\NAT;

// ---------------------------------------------------------------------------
// Testable IPv4 subclass (reused from ipv4ProviderTest pattern)
// ---------------------------------------------------------------------------
class TestableIPv4Icmp extends IPv4
{
    private string $sIfaceConfig;
    private string $sRouteConfig;

    public function __construct(\Psr\Log\LoggerInterface $oLogger, string $sIfaceConfig = '', string $sRouteConfig = '')
    {
        $this->sIfaceConfig = $sIfaceConfig;
        $this->sRouteConfig = $sRouteConfig;
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
        return new NAT($this, $this->oLogger, '');
    }
}

// ---------------------------------------------------------------------------
// Test helpers — packet builders
// ---------------------------------------------------------------------------

class icmpTest extends TestCase
{
    private Logger $oLogger;

    // Interface under test
    private const IFACE_CFG = "1 24 192.168.0.1 255.255.255.0\n";
    private const IFACE_IP  = '192.168.0.1';
    private const IFACE_NET = 1;
    private const IFACE_STN = 24;
    private const CLIENT_IP = '192.168.0.5';

    protected function setUp(): void
    {
        $this->oLogger = new Logger('test');
        $this->oLogger->pushHandler(new NullHandler());
    }

    // -----------------------------------------------------------------------
    // Low-level helpers
    // -----------------------------------------------------------------------

    private function onesComplementChecksum(string $sData): string
    {
        if (strlen($sData) % 2 !== 0) {
            $sData .= "\x00";
        }
        $aPairs = unpack('n*', $sData);
        $iSum   = array_sum($aPairs);
        while ($iSum >> 16) {
            $iSum = ($iSum >> 16) + ($iSum & 0xffff);
        }
        return pack('n', ~$iSum & 0xffff);
    }

    /**
     * Build a raw IPv4+ICMP echo-request binary.
     * Returns the raw bytes (suitable for EconetPacket::setData).
     */
    private function buildEchoRequestBytes(
        string $sSrcIP  = self::CLIENT_IP,
        string $sDstIP  = self::IFACE_IP,
        int    $iId     = 0x1234,
        int    $iSeq    = 0x0001,
        string $sData   = 'ping payload',
        int    $iPktId  = 1
    ): string {
        $sIcmp  = chr(8) . chr(0) . "\x00\x00"  // type=8, code=0, checksum placeholder
               . pack('n', $iId) . pack('n', $iSeq)
               . $sData;
        $sIcmp  = substr_replace($sIcmp, $this->onesComplementChecksum($sIcmp), 2, 2);

        $iTotalLen = 20 + strlen($sIcmp);
        $sIP  = chr(0x45) . chr(0x00) . pack('n', $iTotalLen)
              . pack('n', $iPktId) . pack('n', 0x0000)
              . chr(64) . chr(0x01)           // TTL=64, protocol=ICMP
              . "\x00\x00"                     // checksum placeholder
              . inet_pton($sSrcIP) . inet_pton($sDstIP);
        $sIP = substr_replace($sIP, $this->onesComplementChecksum($sIP), 10, 2);

        return $sIP . $sIcmp;
    }

    private function icmpEconetPacket(
        string $sSrcIP = self::CLIENT_IP,
        string $sDstIP = self::IFACE_IP,
        int    $iId    = 0x1234,
        int    $iSeq   = 0x0001,
        string $sData  = 'ping payload',
        int    $iSrcNet = 1,
        int    $iSrcStn = 5
    ): EconetPacket {
        $oPkt = new EconetPacket();
        $oPkt->setFlags(0x01);
        $oPkt->setSourceNetwork($iSrcNet);
        $oPkt->setSourceStation($iSrcStn);
        $oPkt->setDestinationNetwork(self::IFACE_NET);
        $oPkt->setDestinationStation(self::IFACE_STN);
        $oPkt->setPort(0xD2);
        $oPkt->setData($this->buildEchoRequestBytes($sSrcIP, $sDstIP, $iId, $iSeq, $sData));
        return $oPkt;
    }

    /**
     * Build an EconetPacket carrying a plain TCP SYN to an unroutable destination.
     * Used to trigger the network-unreachable path.
     */
    private function tcpSynToUnroutablePacket(
        string $sSrcIP = self::CLIENT_IP,
        string $sDstIP = '10.99.99.1'
    ): EconetPacket {
        $sTcp  = pack('n', 12345) . pack('n', 80)  // src port, dst port
               . pack('N', 1000) . pack('N', 0)    // seq, ack
               . chr(0x50) . chr(0x02)              // data offset=5, SYN flag
               . pack('n', 65535)                   // window
               . pack('n', 0) . pack('n', 0);       // checksum, urgent

        $iTotalLen = 40;
        $sIP = chr(0x45) . chr(0x00) . pack('n', $iTotalLen)
             . pack('n', 1) . pack('n', 0x4000)
             . chr(64) . chr(0x06)                  // protocol: TCP
             . "\x00\x00"
             . inet_pton($sSrcIP) . inet_pton($sDstIP);
        $sIP = substr_replace($sIP, $this->onesComplementChecksum($sIP), 10, 2);

        $oPkt = new EconetPacket();
        $oPkt->setFlags(0x01);
        $oPkt->setSourceNetwork(1);
        $oPkt->setSourceStation(5);
        $oPkt->setDestinationNetwork(self::IFACE_NET);
        $oPkt->setDestinationStation(self::IFACE_STN);
        $oPkt->setPort(0xD2);
        $oPkt->setData($sIP . $sTcp);
        return $oPkt;
    }

    // -----------------------------------------------------------------------
    // IcmpRequest decoding
    // -----------------------------------------------------------------------

    public function testIcmpRequestDetectsEchoRequest(): void
    {
        $oIcmp = new IcmpRequest($this->icmpEconetPacket(), $this->oLogger);
        $this->assertTrue($oIcmp->isEchoRequest());
    }

    public function testIcmpRequestDecodesType(): void
    {
        $oIcmp = new IcmpRequest($this->icmpEconetPacket(), $this->oLogger);
        $this->assertSame(8, $oIcmp->getType());
    }

    public function testIcmpRequestDecodesCode(): void
    {
        $oIcmp = new IcmpRequest($this->icmpEconetPacket(), $this->oLogger);
        $this->assertSame(0, $oIcmp->getCode());
    }

    public function testIcmpRequestDecodesId(): void
    {
        $oIcmp = new IcmpRequest($this->icmpEconetPacket(iId: 0xABCD), $this->oLogger);
        $this->assertSame(0xABCD, $oIcmp->getId());
    }

    public function testIcmpRequestDecodesSequence(): void
    {
        $oIcmp = new IcmpRequest($this->icmpEconetPacket(iSeq: 42), $this->oLogger);
        $this->assertSame(42, $oIcmp->getSequence());
    }

    public function testIcmpRequestDecodesEchoData(): void
    {
        $oIcmp = new IcmpRequest($this->icmpEconetPacket(sData: 'test data'), $this->oLogger);
        $this->assertSame('test data', $oIcmp->getEchoData());
    }

    public function testIcmpRequestDecodesSrcAndDstIp(): void
    {
        $oIcmp = new IcmpRequest($this->icmpEconetPacket(), $this->oLogger);
        $this->assertSame(self::CLIENT_IP, $oIcmp->getSrcIP());
        $this->assertSame(self::IFACE_IP,  $oIcmp->getDstIP());
    }

    public function testNonIcmpPacketIsNotEchoRequest(): void
    {
        // Build a TCP packet; IcmpRequest should report not an echo request
        $oPkt = $this->tcpSynToUnroutablePacket(sDstIP: self::IFACE_IP);
        $oIcmp = new IcmpRequest($oPkt, $this->oLogger);
        $this->assertFalse($oIcmp->isEchoRequest());
    }

    // -----------------------------------------------------------------------
    // IcmpEchoReply — packet structure
    // -----------------------------------------------------------------------

    public function testEchoReplyFlagsIsRegularIp(): void
    {
        $oEpkt = $this->buildEchoReply()->buildEconetpacket();
        $this->assertSame(0x01, $oEpkt->getFlags());
    }

    public function testEchoReplyPortIs0xD2(): void
    {
        $oEpkt = $this->buildEchoReply()->buildEconetpacket();
        $this->assertSame(0xD2, $oEpkt->getPort());
    }

    public function testEchoReplyDecodesAsIcmpTypeZero(): void
    {
        $oEpkt  = $this->buildEchoReply()->buildEconetpacket();
        $oIPv4  = new IPv4Request($oEpkt, $this->oLogger);
        $sIcmp  = $oIPv4->getData();
        $this->assertSame(0, ord($sIcmp[0]));   // type = 0 (echo reply)
        $this->assertSame(0, ord($sIcmp[1]));   // code = 0
    }

    public function testEchoReplyPreservesIdAndSequence(): void
    {
        $oReply = new IcmpEchoReply();
        $oReply->setSrcIP(self::IFACE_IP);
        $oReply->setDstIP(self::CLIENT_IP);
        $oReply->setSrcStation(self::IFACE_STN);
        $oReply->setSrcNetwork(self::IFACE_NET);
        $oReply->setDstStation(5);
        $oReply->setDstNetwork(1);
        $oReply->setId(0x5A5A);
        $oReply->setSequence(77);
        $oReply->setData('');

        $oEpkt = $oReply->buildEconetpacket();
        $oIPv4 = new IPv4Request($oEpkt, $this->oLogger);
        $sIcmp = $oIPv4->getData();

        $iId  = unpack('n', $sIcmp[4].$sIcmp[5])[1];
        $iSeq = unpack('n', $sIcmp[6].$sIcmp[7])[1];
        $this->assertSame(0x5A5A, $iId);
        $this->assertSame(77, $iSeq);
    }

    public function testEchoReplyPreservesPayload(): void
    {
        $oReply = $this->buildEchoReply(sData: 'hello world');
        $oEpkt  = $oReply->buildEconetpacket();
        $oIPv4  = new IPv4Request($oEpkt, $this->oLogger);
        $sIcmp  = $oIPv4->getData();
        $this->assertSame('hello world', substr($sIcmp, 8));
    }

    public function testEchoReplyAddressing(): void
    {
        $oEpkt = $this->buildEchoReply()->buildEconetpacket();
        $this->assertSame(self::IFACE_STN, $oEpkt->getSourceStation());
        $this->assertSame(self::IFACE_NET, $oEpkt->getSourceNetwork());
        $this->assertSame(5,               $oEpkt->getDestinationStation());
        $this->assertSame(1,               $oEpkt->getDestinationNetwork());
    }

    public function testEchoReplyIpAddressing(): void
    {
        $oEpkt = $this->buildEchoReply()->buildEconetpacket();
        $oIPv4 = new IPv4Request($oEpkt, $this->oLogger);
        $this->assertSame(self::IFACE_IP,  $oIPv4->getSrcIP());
        $this->assertSame(self::CLIENT_IP, $oIPv4->getDstIP());
    }

    public function testEchoReplyChecksumIsValid(): void
    {
        $oEpkt = $this->buildEchoReply(sData: 'checksum test')->buildEconetpacket();
        $oIPv4 = new IPv4Request($oEpkt, $this->oLogger);
        $sIcmp = $oIPv4->getData();

        // Verify checksum: re-running the algorithm over the full ICMP segment should give 0
        if (strlen($sIcmp) % 2 !== 0) {
            $sIcmp .= "\x00";
        }
        $aPairs = unpack('n*', $sIcmp);
        $iSum   = array_sum($aPairs);
        while ($iSum >> 16) {
            $iSum = ($iSum >> 16) + ($iSum & 0xffff);
        }
        $this->assertSame(0xffff, $iSum, 'ICMP checksum should be valid (sum = 0xffff after complement)');
    }

    // -----------------------------------------------------------------------
    // IcmpUnreachable — packet structure
    // -----------------------------------------------------------------------

    public function testNetUnreachableTypeAndCode(): void
    {
        $oEpkt = $this->buildNetUnreachable()->buildEconetpacket();
        $oIPv4 = new IPv4Request($oEpkt, $this->oLogger);
        $sIcmp = $oIPv4->getData();
        $this->assertSame(3, ord($sIcmp[0]));   // type = 3
        $this->assertSame(0, ord($sIcmp[1]));   // code = 0 (net unreachable)
    }

    public function testHostUnreachableCode(): void
    {
        $oReply = $this->buildUnreachable(IcmpUnreachable::HOST_UNREACHABLE);
        $oEpkt  = $oReply->buildEconetpacket();
        $oIPv4  = new IPv4Request($oEpkt, $this->oLogger);
        $sIcmp  = $oIPv4->getData();
        $this->assertSame(1, ord($sIcmp[1]));   // code = 1 (host unreachable)
    }

    public function testUnreachableChecksumIsValid(): void
    {
        $oEpkt = $this->buildNetUnreachable()->buildEconetpacket();
        $oIPv4 = new IPv4Request($oEpkt, $this->oLogger);
        $sIcmp = $oIPv4->getData();

        if (strlen($sIcmp) % 2 !== 0) {
            $sIcmp .= "\x00";
        }
        $aPairs = unpack('n*', $sIcmp);
        $iSum   = array_sum($aPairs);
        while ($iSum >> 16) {
            $iSum = ($iSum >> 16) + ($iSum & 0xffff);
        }
        $this->assertSame(0xffff, $iSum, 'ICMP checksum should be valid');
    }

    public function testUnreachableContainsOriginalHeader(): void
    {
        // The first 8 bytes of the original IP packet should appear at ICMP offset 8
        $sOrigRaw    = $this->buildEchoRequestBytes();
        $sOrigIpHdr  = substr($sOrigRaw, 0, 20);

        $oReply = $this->buildNetUnreachable($sOrigRaw);
        $oEpkt  = $oReply->buildEconetpacket();
        $oIPv4  = new IPv4Request($oEpkt, $this->oLogger);
        $sIcmp  = $oIPv4->getData();

        // bytes 8–27 of ICMP = original IP header (skipping 4-byte unused field at bytes 4-7)
        $this->assertSame($sOrigIpHdr, substr($sIcmp, 8, 20));
    }

    public function testUnreachableAddressing(): void
    {
        $oEpkt = $this->buildNetUnreachable()->buildEconetpacket();
        $this->assertSame(self::IFACE_STN, $oEpkt->getSourceStation());
        $this->assertSame(self::IFACE_NET, $oEpkt->getSourceNetwork());
        $this->assertSame(5,               $oEpkt->getDestinationStation());
        $this->assertSame(1,               $oEpkt->getDestinationNetwork());
    }

    // -----------------------------------------------------------------------
    // IPv4 provider — echo request handling
    // -----------------------------------------------------------------------

    public function testEchoRequestToInterfaceIpGeneratesOneReply(): void
    {
        $oIPv4 = new TestableIPv4Icmp($this->oLogger, self::IFACE_CFG);
        $oIPv4->unicastPacketIn($this->icmpEconetPacket());
        $this->assertCount(1, $oIPv4->getReplies());
    }

    public function testEchoReplyAddressedBackToRequester(): void
    {
        $oIPv4 = new TestableIPv4Icmp($this->oLogger, self::IFACE_CFG);
        $oIPv4->unicastPacketIn($this->icmpEconetPacket(iSrcNet: 1, iSrcStn: 7));
        [$oReply] = $oIPv4->getReplies();
        $this->assertSame(7, $oReply->getDestinationStation());
        $this->assertSame(1, $oReply->getDestinationNetwork());
    }

    public function testEchoReplySentFromInterfaceStation(): void
    {
        $oIPv4 = new TestableIPv4Icmp($this->oLogger, self::IFACE_CFG);
        $oIPv4->unicastPacketIn($this->icmpEconetPacket());
        [$oReply] = $oIPv4->getReplies();
        $this->assertSame(self::IFACE_STN, $oReply->getSourceStation());
        $this->assertSame(self::IFACE_NET, $oReply->getSourceNetwork());
    }

    public function testEchoReplyIsIcmpTypeZeroInProvider(): void
    {
        $oIPv4 = new TestableIPv4Icmp($this->oLogger, self::IFACE_CFG);
        $oIPv4->unicastPacketIn($this->icmpEconetPacket());
        [$oReply] = $oIPv4->getReplies();
        $oDecodedIPv4 = new IPv4Request($oReply, $this->oLogger);
        $sIcmp = $oDecodedIPv4->getData();
        $this->assertSame(0, ord($sIcmp[0]));
    }

    public function testEchoReplyPayloadMatchesRequest(): void
    {
        $oIPv4 = new TestableIPv4Icmp($this->oLogger, self::IFACE_CFG);
        $oIPv4->unicastPacketIn($this->icmpEconetPacket(sData: 'ping!'));
        [$oReply] = $oIPv4->getReplies();
        $oDecodedIPv4 = new IPv4Request($oReply, $this->oLogger);
        $this->assertSame('ping!', substr($oDecodedIPv4->getData(), 8));
    }

    public function testEchoReplyPreservesIdAndSeqInProvider(): void
    {
        $oIPv4 = new TestableIPv4Icmp($this->oLogger, self::IFACE_CFG);
        $oIPv4->unicastPacketIn($this->icmpEconetPacket(iId: 0xBEEF, iSeq: 99));
        [$oReply] = $oIPv4->getReplies();
        $oDecodedIPv4 = new IPv4Request($oReply, $this->oLogger);
        $sIcmp = $oDecodedIPv4->getData();
        $iId  = unpack('n', $sIcmp[4].$sIcmp[5])[1];
        $iSeq = unpack('n', $sIcmp[6].$sIcmp[7])[1];
        $this->assertSame(0xBEEF, $iId);
        $this->assertSame(99, $iSeq);
    }

    public function testNonIcmpToInterfaceIpGeneratesNoReply(): void
    {
        $oIPv4 = new TestableIPv4Icmp($this->oLogger, self::IFACE_CFG);
        // TCP SYN aimed at the interface IP — no ICMP reply expected
        $oPkt = $this->tcpSynToUnroutablePacket(sDstIP: self::IFACE_IP);
        $oIPv4->unicastPacketIn($oPkt);
        $this->assertEmpty($oIPv4->getReplies());
    }

    // -----------------------------------------------------------------------
    // IPv4 provider — network unreachable
    // -----------------------------------------------------------------------

    public function testNoRouteGeneratesIcmpReply(): void
    {
        $oIPv4 = new TestableIPv4Icmp($this->oLogger, self::IFACE_CFG, '');
        $oIPv4->unicastPacketIn($this->tcpSynToUnroutablePacket());
        $this->assertCount(1, $oIPv4->getReplies());
    }

    public function testNoRouteReplyIsIcmpUnreachable(): void
    {
        $oIPv4 = new TestableIPv4Icmp($this->oLogger, self::IFACE_CFG, '');
        $oIPv4->unicastPacketIn($this->tcpSynToUnroutablePacket());
        [$oReply] = $oIPv4->getReplies();
        $oDecodedIPv4 = new IPv4Request($oReply, $this->oLogger);
        $sIcmp = $oDecodedIPv4->getData();
        $this->assertSame(3, ord($sIcmp[0]));   // type 3 = destination unreachable
        $this->assertSame(0, ord($sIcmp[1]));   // code 0 = net unreachable
    }

    public function testNoRouteReplyAddressedToSender(): void
    {
        $oIPv4 = new TestableIPv4Icmp($this->oLogger, self::IFACE_CFG, '');
        $oIPv4->unicastPacketIn($this->tcpSynToUnroutablePacket());
        [$oReply] = $oIPv4->getReplies();
        $this->assertSame(5, $oReply->getDestinationStation());
        $this->assertSame(1, $oReply->getDestinationNetwork());
    }

    public function testNoRouteUnreachableChecksumIsValid(): void
    {
        $oIPv4 = new TestableIPv4Icmp($this->oLogger, self::IFACE_CFG, '');
        $oIPv4->unicastPacketIn($this->tcpSynToUnroutablePacket());
        [$oReply] = $oIPv4->getReplies();
        $oDecodedIPv4 = new IPv4Request($oReply, $this->oLogger);
        $sIcmp = $oDecodedIPv4->getData();
        if (strlen($sIcmp) % 2 !== 0) {
            $sIcmp .= "\x00";
        }
        $aPairs = unpack('n*', $sIcmp);
        $iSum   = array_sum($aPairs);
        while ($iSum >> 16) {
            $iSum = ($iSum >> 16) + ($iSum & 0xffff);
        }
        $this->assertSame(0xffff, $iSum);
    }

    // -----------------------------------------------------------------------
    // Reply builders used by multiple tests
    // -----------------------------------------------------------------------

    private function buildEchoReply(string $sData = 'ping payload'): IcmpEchoReply
    {
        $oReply = new IcmpEchoReply();
        $oReply->setSrcIP(self::IFACE_IP);
        $oReply->setDstIP(self::CLIENT_IP);
        $oReply->setSrcStation(self::IFACE_STN);
        $oReply->setSrcNetwork(self::IFACE_NET);
        $oReply->setDstStation(5);
        $oReply->setDstNetwork(1);
        $oReply->setId(0x1234);
        $oReply->setSequence(1);
        $oReply->setData($sData);
        return $oReply;
    }

    private function buildUnreachable(int $iCode = IcmpUnreachable::NET_UNREACHABLE, string $sOrigRaw = ''): IcmpUnreachable
    {
        if ($sOrigRaw === '') {
            $sOrigRaw = $this->buildEchoRequestBytes();
        }
        $oReply = new IcmpUnreachable($iCode);
        $oReply->setSrcIP(self::IFACE_IP);
        $oReply->setDstIP(self::CLIENT_IP);
        $oReply->setSrcStation(self::IFACE_STN);
        $oReply->setSrcNetwork(self::IFACE_NET);
        $oReply->setDstStation(5);
        $oReply->setDstNetwork(1);
        $oReply->setOriginalPacket($sOrigRaw);
        return $oReply;
    }

    private function buildNetUnreachable(string $sOrigRaw = ''): IcmpUnreachable
    {
        return $this->buildUnreachable(IcmpUnreachable::NET_UNREACHABLE, $sOrigRaw);
    }
}
