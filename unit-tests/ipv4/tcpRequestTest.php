<?php

/*
 * @group unit-tests
 *
 * Tests for TCPRequest and TcpIPReply:
 *   - TCP header field decoding (ports, seq, ack, flags, window)
 *   - Payload stripping (getData() returns only application data)
 *   - TcpIPReply round-trip: build a reply packet, decode it with TCPRequest
 */

include_once(__DIR__.'/../../src/include/system.inc.php');

use PHPUnit\Framework\TestCase;
use Monolog\Logger;
use Monolog\Handler\NullHandler;
use HomeLan\FileStore\Messages\EconetPacket;
use HomeLan\FileStore\Messages\TCPRequest;
use HomeLan\FileStore\Messages\TcpIPReply;

class tcpRequestTest extends TestCase
{
    private Logger $oLogger;

    // TCP flag bit positions in the flags byte (TCP header byte 14, 1-indexed as byte 13)
    const FLAG_FIN = 0x01;
    const FLAG_SYN = 0x02;
    const FLAG_RST = 0x04;
    const FLAG_PSH = 0x08;
    const FLAG_ACK = 0x10;
    const FLAG_URG = 0x20;

    protected function setUp(): void
    {
        $this->oLogger = new Logger('test');
        $this->oLogger->pushHandler(new NullHandler());
    }

    // -----------------------------------------------------------------------
    // Packet builder: returns an EconetPacket carrying a valid IPv4+TCP frame
    // -----------------------------------------------------------------------

    private function buildTcpEconetPacket(
        string $sSrcIP    = '192.168.0.5',
        string $sDstIP    = '192.168.1.1',
        int    $iSrcPort  = 12345,
        int    $iDstPort  = 23,
        int    $iSeq      = 1000,
        int    $iAck      = 0,
        int    $iTcpFlags = self::FLAG_SYN,
        int    $iWindow   = 65535,
        string $sPayload  = '',
        int    $iPktId    = 42
    ): EconetPacket {
        $iTotalLen = 40 + strlen($sPayload);

        // IPv4 header (20 bytes, no options)
        $sIPHdr  = chr(0x45);                       // version=4, IHL=5
        $sIPHdr .= chr(0x00);                       // TOS
        $sIPHdr .= pack('n', $iTotalLen);           // total length
        $sIPHdr .= pack('n', $iPktId);              // packet ID
        $sIPHdr .= pack('n', 0x4000);              // flags: DF, fragment offset 0
        $sIPHdr .= chr(64);                         // TTL
        $sIPHdr .= chr(0x06);                       // protocol: TCP
        $sIPHdr .= pack('n', 0);                    // checksum (not verified by decoder)
        $sIPHdr .= inet_pton($sSrcIP);              // source IP (4 bytes)
        $sIPHdr .= inet_pton($sDstIP);              // destination IP (4 bytes)

        // TCP header (20 bytes, no options)
        $sTCPHdr  = pack('n', $iSrcPort);           // source port
        $sTCPHdr .= pack('n', $iDstPort);           // destination port
        $sTCPHdr .= pack('N', $iSeq);               // sequence number
        $sTCPHdr .= pack('N', $iAck);               // acknowledgement number
        $sTCPHdr .= chr(0x50);                      // data offset=5 (20 bytes), reserved=0
        $sTCPHdr .= chr($iTcpFlags);                // flags
        $sTCPHdr .= pack('n', $iWindow);            // window size
        $sTCPHdr .= pack('n', 0);                   // checksum (not verified by decoder)
        $sTCPHdr .= pack('n', 0);                   // urgent pointer

        $oPkt = new EconetPacket();
        $oPkt->setFlags(0x01);                      // regular IPv4 frame
        $oPkt->setSourceNetwork(1);
        $oPkt->setSourceStation(5);
        $oPkt->setDestinationNetwork(1);
        $oPkt->setDestinationStation(24);
        $oPkt->setPort(0xD2);
        $oPkt->setData($sIPHdr . $sTCPHdr . $sPayload);
        return $oPkt;
    }

    // -----------------------------------------------------------------------
    // Port decoding
    // -----------------------------------------------------------------------

    public function testDecodesSrcPort(): void
    {
        $oReq = new TCPRequest($this->buildTcpEconetPacket(iSrcPort: 54321), $this->oLogger);
        $this->assertSame(54321, $oReq->getSrcPort());
    }

    public function testDecodesDstPort(): void
    {
        $oReq = new TCPRequest($this->buildTcpEconetPacket(iDstPort: 80), $this->oLogger);
        $this->assertSame(80, $oReq->getDstPort());
    }

    // -----------------------------------------------------------------------
    // Sequence and acknowledgement numbers
    // -----------------------------------------------------------------------

    public function testDecodesSequenceNumber(): void
    {
        $oReq = new TCPRequest($this->buildTcpEconetPacket(iSeq: 123456789), $this->oLogger);
        $this->assertSame(123456789, $oReq->getSequence());
    }

    public function testDecodesAckNumber(): void
    {
        $oReq = new TCPRequest($this->buildTcpEconetPacket(iAck: 987654321), $this->oLogger);
        $this->assertSame(987654321, $oReq->getAck());
    }

    // -----------------------------------------------------------------------
    // Flag decoding
    // -----------------------------------------------------------------------

    public function testDecodesSynFlagWhenSet(): void
    {
        $oReq = new TCPRequest($this->buildTcpEconetPacket(iTcpFlags: self::FLAG_SYN), $this->oLogger);
        $this->assertTrue($oReq->getSynFlag());
    }

    public function testDecodesSynFlagWhenClear(): void
    {
        $oReq = new TCPRequest($this->buildTcpEconetPacket(iTcpFlags: self::FLAG_ACK), $this->oLogger);
        $this->assertFalse($oReq->getSynFlag());
    }

    public function testDecodesAckFlagWhenSet(): void
    {
        $oReq = new TCPRequest($this->buildTcpEconetPacket(iTcpFlags: self::FLAG_ACK), $this->oLogger);
        $this->assertTrue($oReq->getAckFlag());
    }

    public function testDecodesAckFlagWhenClear(): void
    {
        $oReq = new TCPRequest($this->buildTcpEconetPacket(iTcpFlags: self::FLAG_SYN), $this->oLogger);
        $this->assertFalse($oReq->getAckFlag());
    }

    public function testDecodesFinFlagWhenSet(): void
    {
        $oReq = new TCPRequest($this->buildTcpEconetPacket(iTcpFlags: self::FLAG_FIN), $this->oLogger);
        $this->assertTrue($oReq->getFinFlag());
    }

    public function testDecodesFinFlagWhenClear(): void
    {
        $oReq = new TCPRequest($this->buildTcpEconetPacket(iTcpFlags: self::FLAG_SYN), $this->oLogger);
        $this->assertFalse($oReq->getFinFlag());
    }

    public function testDecodesResetFlagWhenSet(): void
    {
        $oReq = new TCPRequest($this->buildTcpEconetPacket(iTcpFlags: self::FLAG_RST), $this->oLogger);
        $this->assertTrue($oReq->getResetFlag());
    }

    public function testDecodesResetFlagWhenClear(): void
    {
        $oReq = new TCPRequest($this->buildTcpEconetPacket(iTcpFlags: self::FLAG_ACK), $this->oLogger);
        $this->assertFalse($oReq->getResetFlag());
    }

    public function testDecodesPushFlagWhenSet(): void
    {
        $oReq = new TCPRequest($this->buildTcpEconetPacket(iTcpFlags: self::FLAG_PSH), $this->oLogger);
        $this->assertTrue($oReq->getPushFlag());
    }

    public function testDecodesMultipleFlagsSimultaneously(): void
    {
        $oReq = new TCPRequest(
            $this->buildTcpEconetPacket(iTcpFlags: self::FLAG_SYN | self::FLAG_ACK),
            $this->oLogger
        );
        $this->assertTrue($oReq->getSynFlag());
        $this->assertTrue($oReq->getAckFlag());
        $this->assertFalse($oReq->getFinFlag());
        $this->assertFalse($oReq->getResetFlag());
    }

    // -----------------------------------------------------------------------
    // Window size
    // -----------------------------------------------------------------------

    public function testDecodesWindowSize(): void
    {
        $oReq = new TCPRequest($this->buildTcpEconetPacket(iWindow: 8192), $this->oLogger);
        $this->assertSame(8192, $oReq->getWindow());
    }

    // -----------------------------------------------------------------------
    // Payload (application data)
    // -----------------------------------------------------------------------

    public function testGetDataReturnsPayloadWithTcpHeaderStripped(): void
    {
        $oReq = new TCPRequest($this->buildTcpEconetPacket(sPayload: 'Hello, Econet!'), $this->oLogger);
        $this->assertSame('Hello, Econet!', $oReq->getData());
    }

    public function testGetDataReturnsEmptyStringWhenNoPayload(): void
    {
        $oReq = new TCPRequest($this->buildTcpEconetPacket(sPayload: ''), $this->oLogger);
        $this->assertSame('', $oReq->getData());
    }

    public function testPayloadIsBinaryClean(): void
    {
        $sBinary = "\x00\x01\x02\xff\xfe";
        $oReq = new TCPRequest($this->buildTcpEconetPacket(sPayload: $sBinary), $this->oLogger);
        $this->assertSame($sBinary, $oReq->getData());
    }

    // -----------------------------------------------------------------------
    // TcpIPReply round-trip: build with TcpIPReply, decode with TCPRequest
    // -----------------------------------------------------------------------

    public function testTcpIpReplyRoundTripPreservesPorts(): void
    {
        $oReply = $this->buildSynAckReply(iSrcPort: 80, iDstPort: 54321);
        $oTcp = new TCPRequest($oReply->buildEconetpacket(), $this->oLogger);
        $this->assertSame(80, $oTcp->getSrcPort());
        $this->assertSame(54321, $oTcp->getDstPort());
    }

    public function testTcpIpReplyRoundTripPreservesSequenceAndAck(): void
    {
        $oReply = $this->buildSynAckReply(iSeq: 500, iAck: 1001);
        $oTcp = new TCPRequest($oReply->buildEconetpacket(), $this->oLogger);
        $this->assertSame(500, $oTcp->getSequence());
        $this->assertSame(1001, $oTcp->getAck());
    }

    public function testTcpIpReplyRoundTripPreservesFlags(): void
    {
        $oReply = $this->buildSynAckReply();
        $oTcp = new TCPRequest($oReply->buildEconetpacket(), $this->oLogger);
        $this->assertTrue($oTcp->getSynFlag());
        $this->assertTrue($oTcp->getAckFlag());
        $this->assertFalse($oTcp->getFinFlag());
        $this->assertFalse($oTcp->getResetFlag());
    }

    public function testTcpIpReplyRoundTripPreservesPayload(): void
    {
        $oReply = $this->buildSynAckReply(sData: 'response data');
        $oTcp = new TCPRequest($oReply->buildEconetpacket(), $this->oLogger);
        $this->assertSame('response data', $oTcp->getData());
    }

    public function testTcpIpReplyWithDataOnlyFlagPreservesPayload(): void
    {
        $oReply = new TcpIPReply();
        $oReply->setSrcIP('10.0.0.1');
        $oReply->setDstIP('192.168.0.5');
        $oReply->setSrcPort(23);
        $oReply->setDstPort(12345);
        $oReply->setSeqNumber(1000);
        $oReply->setAckNumber(501);
        $oReply->setFlagAck(true);
        $oReply->setFlagPush(true);
        $oReply->setId(1);
        $oReply->setSrcStation(254);
        $oReply->setSrcNetwork(254);
        $oReply->setData("DATA\r\n");

        $oTcp = new TCPRequest($oReply->buildEconetpacket(), $this->oLogger);
        $this->assertTrue($oTcp->getAckFlag());
        $this->assertTrue($oTcp->getPushFlag());
        $this->assertFalse($oTcp->getSynFlag());
        $this->assertSame("DATA\r\n", $oTcp->getData());
    }

    // -----------------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------------

    private function buildSynAckReply(
        string $sSrcIP  = '10.0.0.1',
        string $sDstIP  = '192.168.0.5',
        int    $iSrcPort = 80,
        int    $iDstPort = 54321,
        int    $iSeq    = 500,
        int    $iAck    = 1001,
        string $sData   = ''
    ): TcpIPReply {
        $oReply = new TcpIPReply();
        $oReply->setSrcIP($sSrcIP);
        $oReply->setDstIP($sDstIP);
        $oReply->setSrcPort($iSrcPort);
        $oReply->setDstPort($iDstPort);
        $oReply->setSeqNumber($iSeq);
        $oReply->setAckNumber($iAck);
        $oReply->setFlagSyn(true);
        $oReply->setFlagAck(true);
        $oReply->setId(7);
        $oReply->setSrcStation(254);
        $oReply->setSrcNetwork(254);
        $oReply->setData($sData);
        return $oReply;
    }
}
