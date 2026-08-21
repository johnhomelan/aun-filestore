<?php

/*
 * @group unit-tests
 *
 * Tests for UDP-in-IPv4-over-Econet message classes, and the IPv4 provider's use of them to
 * relay UDP traffic over the Remote Socket Protocol (see docs/protocols/remote-socket.md):
 *   - UdpRequest: decodes source/destination port, length, and payload from a UDP datagram
 *   - UdpEconetReply: builds a valid IPv4/UDP datagram; checksum, addressing, payload
 *   - IPv4 provider: UDP to an interface IP is relayed to a registered RelayServer client
 *   - IPv4 provider: with no relay server, or nothing registered for the port, the packet is
 *     dropped silently
 *   - IPv4 provider: injectRelayReply() builds and buffers a reply addressed back to the sender
 */

include_once(__DIR__.'/../../src/include/system.inc.php');

use PHPUnit\Framework\TestCase;
use Monolog\Logger;
use Monolog\Handler\NullHandler;
use Ratchet\ConnectionInterface;
use HomeLan\FileStore\Messages\EconetPacket;
use HomeLan\FileStore\Messages\IPv4Request;
use HomeLan\FileStore\Messages\UdpRequest;
use HomeLan\FileStore\Messages\UdpEconetReply;
use HomeLan\FileStore\Services\Provider\IPv4;
use HomeLan\FileStore\Services\Provider\IPv4\Interfaces;
use HomeLan\FileStore\Services\Provider\IPv4\Routes;
use HomeLan\FileStore\Services\Provider\IPv4\NAT;
use HomeLan\FileStore\RemoteSocket\RelayServer;
use HomeLan\FileStore\RemoteSocket\Messages\Frame;

// ---------------------------------------------------------------------------
// Testable IPv4 subclass (reused from icmpTest's TestableIPv4Icmp pattern)
// ---------------------------------------------------------------------------
class TestableIPv4Udp extends IPv4
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

#[\AllowDynamicProperties]
class FakeUdpRelayConnection implements ConnectionInterface
{
    /** @var list<string> */
    public array $aSent = [];

    public function send($data)
    {
        $this->aSent[] = (string) $data;
        return $this;
    }

    public function close(): void
    {
    }

    /** @return list<Frame> */
    public function decodedFrames(): array
    {
        return array_map(static fn (string $sJson): Frame => Frame::decode($sJson), $this->aSent);
    }
}

class udpTest extends TestCase
{
    private Logger $oLogger;

    private const IFACE_CFG = "1 24 192.168.0.1 255.255.255.0\n";
    private const IFACE_IP  = '192.168.0.1';
    private const IFACE_NET = 1;
    private const IFACE_STN = 24;
    private const CLIENT_IP = '192.168.0.5';
    private const RELAY_SECRET = 'test-secret';

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

    /** Build a raw IPv4+UDP binary (suitable for EconetPacket::setData). */
    private function buildUdpDatagramBytes(
        string $sSrcIP   = self::CLIENT_IP,
        string $sDstIP   = self::IFACE_IP,
        int    $iSrcPort = 41230,
        int    $iDstPort = 32770,
        string $sPayload = 'hello sharefs',
        int    $iPktId   = 1
    ): string {
        $iUdpLen = 8 + strlen($sPayload);
        $sUdp  = pack('n', $iSrcPort) . pack('n', $iDstPort) . pack('n', $iUdpLen) . "\x00\x00" . $sPayload;

        $iTotalLen = 20 + strlen($sUdp);
        $sIP  = chr(0x45) . chr(0x00) . pack('n', $iTotalLen)
              . pack('n', $iPktId) . pack('n', 0x0000)
              . chr(64) . chr(0x11)           // TTL=64, protocol=UDP
              . "\x00\x00"
              . inet_pton($sSrcIP) . inet_pton($sDstIP);
        $sIP = substr_replace($sIP, $this->onesComplementChecksum($sIP), 10, 2);

        return $sIP . $sUdp;
    }

    private function udpEconetPacket(
        string $sSrcIP   = self::CLIENT_IP,
        string $sDstIP   = self::IFACE_IP,
        int    $iSrcPort = 41230,
        int    $iDstPort = 32770,
        string $sPayload = 'hello sharefs',
        int    $iSrcNet  = 1,
        int    $iSrcStn  = 5
    ): EconetPacket {
        $oPkt = new EconetPacket();
        $oPkt->setFlags(0x01);
        $oPkt->setSourceNetwork($iSrcNet);
        $oPkt->setSourceStation($iSrcStn);
        $oPkt->setDestinationNetwork(self::IFACE_NET);
        $oPkt->setDestinationStation(self::IFACE_STN);
        $oPkt->setPort(0xD2);
        $oPkt->setData($this->buildUdpDatagramBytes($sSrcIP, $sDstIP, $iSrcPort, $iDstPort, $sPayload));
        return $oPkt;
    }

    // -----------------------------------------------------------------------
    // UdpRequest decoding
    // -----------------------------------------------------------------------

    public function testUdpRequestIsValidForAUdpPacket(): void
    {
        $oUdp = new UdpRequest($this->udpEconetPacket(), $this->oLogger);
        $this->assertTrue($oUdp->isValid());
    }

    public function testUdpRequestDecodesSrcAndDstPort(): void
    {
        $oUdp = new UdpRequest($this->udpEconetPacket(iSrcPort: 55000, iDstPort: 32771), $this->oLogger);
        $this->assertSame(55000, $oUdp->getSrcPort());
        $this->assertSame(32771, $oUdp->getDstPort());
    }

    public function testUdpRequestDecodesLength(): void
    {
        $oUdp = new UdpRequest($this->udpEconetPacket(sPayload: 'abcde'), $this->oLogger);
        $this->assertSame(8 + 5, $oUdp->getLength());
    }

    public function testUdpRequestDecodesPayload(): void
    {
        $oUdp = new UdpRequest($this->udpEconetPacket(sPayload: 'freeway broadcast'), $this->oLogger);
        $this->assertSame('freeway broadcast', $oUdp->getPayload());
    }

    public function testUdpRequestDecodesSrcAndDstIp(): void
    {
        $oUdp = new UdpRequest($this->udpEconetPacket(), $this->oLogger);
        $this->assertSame(self::CLIENT_IP, $oUdp->getSrcIP());
        $this->assertSame(self::IFACE_IP,  $oUdp->getDstIP());
    }

    public function testNonUdpPacketIsNotValid(): void
    {
        // An ICMP packet decoded as UdpRequest should report invalid, not throw or misparse.
        $sIcmp  = chr(8) . chr(0) . "\x00\x00" . pack('n', 1) . pack('n', 1) . 'x';
        $sIcmp  = substr_replace($sIcmp, $this->onesComplementChecksum($sIcmp), 2, 2);
        $iTotalLen = 20 + strlen($sIcmp);
        $sIP  = chr(0x45) . chr(0x00) . pack('n', $iTotalLen)
              . pack('n', 1) . pack('n', 0x0000)
              . chr(64) . chr(0x01)
              . "\x00\x00"
              . inet_pton(self::CLIENT_IP) . inet_pton(self::IFACE_IP);
        $sIP = substr_replace($sIP, $this->onesComplementChecksum($sIP), 10, 2);

        $oPkt = new EconetPacket();
        $oPkt->setFlags(0x01);
        $oPkt->setSourceNetwork(1);
        $oPkt->setSourceStation(5);
        $oPkt->setDestinationNetwork(self::IFACE_NET);
        $oPkt->setDestinationStation(self::IFACE_STN);
        $oPkt->setPort(0xD2);
        $oPkt->setData($sIP . $sIcmp);

        $oUdp = new UdpRequest($oPkt, $this->oLogger);
        $this->assertFalse($oUdp->isValid());
    }

    // -----------------------------------------------------------------------
    // UdpEconetReply — packet structure
    // -----------------------------------------------------------------------

    private function buildUdpReply(string $sPayload = 'reply payload', int $iSrcPort = 32770, int $iDstPort = 41230): UdpEconetReply
    {
        $oReply = new UdpEconetReply();
        $oReply->setSrcIP(self::IFACE_IP);
        $oReply->setDstIP(self::CLIENT_IP);
        $oReply->setSrcStation(self::IFACE_STN);
        $oReply->setSrcNetwork(self::IFACE_NET);
        $oReply->setDstStation(5);
        $oReply->setDstNetwork(1);
        $oReply->setSrcPort($iSrcPort);
        $oReply->setDstPort($iDstPort);
        $oReply->setData($sPayload);
        return $oReply;
    }

    public function testReplyFlagsIsRegularIp(): void
    {
        $oEpkt = $this->buildUdpReply()->buildEconetpacket();
        $this->assertSame(0x01, $oEpkt->getFlags());
    }

    public function testReplyPortIs0xD2(): void
    {
        $oEpkt = $this->buildUdpReply()->buildEconetpacket();
        $this->assertSame(0xD2, $oEpkt->getPort());
    }

    public function testReplyDecodesAsUdp(): void
    {
        $oEpkt = $this->buildUdpReply()->buildEconetpacket();
        $oIPv4 = new IPv4Request($oEpkt, $this->oLogger);
        $this->assertSame('UDP', $oIPv4->getProtocol());
    }

    public function testReplyPreservesSrcAndDstPort(): void
    {
        $oEpkt = $this->buildUdpReply(iSrcPort: 32771, iDstPort: 55000)->buildEconetpacket();
        $oUdp  = new UdpRequest($oEpkt, $this->oLogger);
        $this->assertSame(32771, $oUdp->getSrcPort());
        $this->assertSame(55000, $oUdp->getDstPort());
    }

    public function testReplyPreservesPayload(): void
    {
        $oEpkt = $this->buildUdpReply(sPayload: 'ShareFS reply bytes')->buildEconetpacket();
        $oUdp  = new UdpRequest($oEpkt, $this->oLogger);
        $this->assertSame('ShareFS reply bytes', $oUdp->getPayload());
    }

    public function testReplyAddressing(): void
    {
        $oEpkt = $this->buildUdpReply()->buildEconetpacket();
        $this->assertSame(self::IFACE_STN, $oEpkt->getSourceStation());
        $this->assertSame(self::IFACE_NET, $oEpkt->getSourceNetwork());
        $this->assertSame(5, $oEpkt->getDestinationStation());
        $this->assertSame(1, $oEpkt->getDestinationNetwork());
    }

    public function testReplyIpAddressing(): void
    {
        $oEpkt = $this->buildUdpReply()->buildEconetpacket();
        $oIPv4 = new IPv4Request($oEpkt, $this->oLogger);
        $this->assertSame(self::IFACE_IP,  $oIPv4->getSrcIP());
        $this->assertSame(self::CLIENT_IP, $oIPv4->getDstIP());
    }

    public function testReplyIpChecksumIsValid(): void
    {
        $oEpkt = $this->buildUdpReply()->buildEconetpacket();
        $oIPv4 = new IPv4Request($oEpkt, $this->oLogger);

        // Verify the IP header checksum: re-summing the 20-byte header should give 0xffff.
        $sHeader = substr($oEpkt->getData() ?? '', 0, 20);
        $aPairs = unpack('n*', $sHeader);
        $iSum   = array_sum($aPairs);
        while ($iSum >> 16) {
            $iSum = ($iSum >> 16) + ($iSum & 0xffff);
        }
        $this->assertSame(0xffff, $iSum, 'IP header checksum should be valid (sum = 0xffff after complement)');
    }

    public function testReplyLengthMatchesPayload(): void
    {
        $oEpkt = $this->buildUdpReply(sPayload: 'abc')->buildEconetpacket();
        $oUdp  = new UdpRequest($oEpkt, $this->oLogger);
        $this->assertSame(8 + 3, $oUdp->getLength());
    }

    // -----------------------------------------------------------------------
    // IPv4 provider — relaying UDP over the Remote Socket Protocol
    // -----------------------------------------------------------------------

    /** @return array{0: RelayServer, 1: FakeUdpRelayConnection} a RelayServer with one connection registered for UDP/32770 */
    private function buildRegisteredRelay(): array
    {
        $oRelayServer = new RelayServer($this->oLogger, self::RELAY_SECRET, function (): void {});
        $oConn = new FakeUdpRelayConnection();
        $oRelayServer->onOpen($oConn);
        $oRelayServer->onMessage($oConn, Frame::hello(self::RELAY_SECRET)->encode());
        $oRelayServer->onMessage($oConn, Frame::register([['protocol' => 'UDP', 'port' => 32770]])->encode());
        return [$oRelayServer, $oConn];
    }

    public function testUdpToInterfaceWithNoRelayServerGeneratesNoReply(): void
    {
        $oIPv4 = new TestableIPv4Udp($this->oLogger, self::IFACE_CFG);
        $oIPv4->unicastPacketIn($this->udpEconetPacket());
        $this->assertEmpty($oIPv4->getReplies());
    }

    public function testUdpToInterfaceWithNothingRegisteredForThePortGeneratesNoReply(): void
    {
        $oIPv4 = new TestableIPv4Udp($this->oLogger, self::IFACE_CFG);
        $oIPv4->setRelayServer(new RelayServer($this->oLogger, self::RELAY_SECRET, function (): void {}));
        $oIPv4->unicastPacketIn($this->udpEconetPacket(iDstPort: 32771));
        $this->assertEmpty($oIPv4->getReplies());
    }

    public function testUdpToInterfaceIsRelayedToTheRegisteredClient(): void
    {
        [$oRelayServer, $oConn] = $this->buildRegisteredRelay();
        $oIPv4 = new TestableIPv4Udp($this->oLogger, self::IFACE_CFG);
        $oIPv4->setRelayServer($oRelayServer);

        $oIPv4->unicastPacketIn($this->udpEconetPacket(sPayload: 'freeway broadcast'));

        $aFrames = $oConn->decodedFrames();
        $oData = $aFrames[count($aFrames) - 1];
        $this->assertSame(Frame::TYPE_DATA, $oData->getType());
        $this->assertSame('UDP', $oData->getProtocol());
        $this->assertSame(self::IFACE_IP, $oData->getLocalAddr());
        $this->assertSame(32770, $oData->getLocalPort());
        $this->assertSame(self::CLIENT_IP, $oData->getRemoteAddr());
        $this->assertSame(41230, $oData->getRemotePort());
        $this->assertSame('freeway broadcast', $oData->getPayload());
    }

    public function testUdpToInterfaceDoesNotItselfGenerateAReply(): void
    {
        [$oRelayServer] = $this->buildRegisteredRelay();
        $oIPv4 = new TestableIPv4Udp($this->oLogger, self::IFACE_CFG);
        $oIPv4->setRelayServer($oRelayServer);

        $oIPv4->unicastPacketIn($this->udpEconetPacket());
        $this->assertEmpty($oIPv4->getReplies());
    }

    public function testInjectRelayReplyBuffersAReplyPacketBackToTheSender(): void
    {
        [$oRelayServer] = $this->buildRegisteredRelay();
        $oIPv4 = new TestableIPv4Udp($this->oLogger, self::IFACE_CFG);
        $oIPv4->setRelayServer($oRelayServer);

        // Seed the arp cache for CLIENT_IP by relaying the original request first.
        $oIPv4->unicastPacketIn($this->udpEconetPacket());

        $oIPv4->injectRelayReply(self::IFACE_IP, 32770, self::CLIENT_IP, 41230, 'ShareFS reply bytes');

        $aReplies = $oIPv4->getReplies();
        $this->assertCount(1, $aReplies);
        [$oReply] = $aReplies;
        $this->assertSame(self::IFACE_STN, $oReply->getSourceStation());
        $this->assertSame(self::IFACE_NET, $oReply->getSourceNetwork());
        $this->assertSame(5, $oReply->getDestinationStation());
        $this->assertSame(1, $oReply->getDestinationNetwork());

        $oUdp = new UdpRequest($oReply, $this->oLogger);
        $this->assertSame(self::IFACE_IP, $oUdp->getSrcIP());
        $this->assertSame(self::CLIENT_IP, $oUdp->getDstIP());
        $this->assertSame(32770, $oUdp->getSrcPort());
        $this->assertSame(41230, $oUdp->getDstPort());
        $this->assertSame('ShareFS reply bytes', $oUdp->getPayload());
    }

    public function testInjectRelayReplyWithNoArpEntryIsDroppedSilently(): void
    {
        $oIPv4 = new TestableIPv4Udp($this->oLogger, self::IFACE_CFG);
        $oIPv4->injectRelayReply(self::IFACE_IP, 32770, self::CLIENT_IP, 41230, 'unroutable reply');
        $this->assertEmpty($oIPv4->getReplies());
    }

    public function testInjectRelayReplyForUnknownLocalInterfaceIsDroppedSilently(): void
    {
        $oIPv4 = new TestableIPv4Udp($this->oLogger, self::IFACE_CFG);
        $oIPv4->unicastPacketIn($this->udpEconetPacket());
        $oIPv4->injectRelayReply('10.0.0.1', 32770, self::CLIENT_IP, 41230, 'reply');
        $this->assertEmpty($oIPv4->getReplies());
    }
}
