<?php

/*
 * @group unit-tests
 *
 * Unit tests for HomeLan\FileStore\Messages\IPv4Request.
 * Covers all public getters and the forward() method.
 */

include_once(__DIR__ . '/../../src/include/system.inc.php');

use PHPUnit\Framework\TestCase;
use Monolog\Logger;
use Monolog\Handler\NullHandler;
use HomeLan\FileStore\Messages\EconetPacket;
use HomeLan\FileStore\Messages\IPv4Request;

class IPv4RequestTest extends TestCase
{
    private \Psr\Log\LoggerInterface $oLogger;

    protected function setUp(): void
    {
        $this->oLogger = new Logger('test');
        $this->oLogger->pushHandler(new NullHandler());
    }

    // -----------------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------------

    private function buildIPv4(
        string $sSrcIP,
        string $sDstIP,
        int    $iProtocol   = 0x01, // ICMP
        string $sPayload    = '',
        int    $iTtl        = 64,
        int    $iTos        = 0,
        int    $iId         = 1,
        int    $iFlagOffset = 0
    ): string {
        $iVerLen = 0x45; // version=4, IHL=5 (20-byte header)
        $iLength = 20 + strlen($sPayload);

        $sHeader = pack('CCnnnCCn',
            $iVerLen, $iTos, $iLength, $iId, $iFlagOffset, $iTtl, $iProtocol, 0
        ) . inet_pton($sSrcIP) . inet_pton($sDstIP);

        // Compute one's-complement checksum
        $aPairs = unpack('n*', $sHeader);
        $iSum   = array_sum($aPairs);
        while ($iSum >> 16) { $iSum = ($iSum >> 16) + ($iSum & 0xffff); }
        $iChecksum = ~$iSum & 0xffff;
        $sHeader[10] = chr($iChecksum >> 8);
        $sHeader[11] = chr($iChecksum & 0xff);

        return $sHeader . $sPayload;
    }

    private function makeEconetPacket(string $sData, int $iFlags = 0x01): EconetPacket
    {
        $oPkt = new EconetPacket();
        $oPkt->setFlags($iFlags);
        $oPkt->setPort(0xD2);
        $oPkt->setSourceNetwork(1);
        $oPkt->setSourceStation(5);
        $oPkt->setDestinationNetwork(1);
        $oPkt->setDestinationStation(24);
        $oPkt->setData($sData);
        return $oPkt;
    }

    private function makeRequest(
        string $sSrcIP  = '192.168.0.5',
        string $sDstIP  = '192.168.0.1',
        int    $iProto  = 0x01,
        string $sData   = '',
        int    $iTtl    = 64,
        int    $iTos    = 0,
        int    $iId     = 1,
        int    $iFlagOffset = 0
    ): IPv4Request {
        $sIPv4 = $this->buildIPv4($sSrcIP, $sDstIP, $iProto, $sData, $iTtl, $iTos, $iId, $iFlagOffset);
        $oPkt  = $this->makeEconetPacket($sIPv4);
        return new IPv4Request($oPkt, $this->oLogger);
    }

    // -----------------------------------------------------------------------
    // IP-address getters
    // -----------------------------------------------------------------------

    public function testGetSrcIPReturnsSourceAddress(): void
    {
        $oReq = $this->makeRequest('10.0.0.1', '10.0.0.2');
        $this->assertSame('10.0.0.1', $oReq->getSrcIP());
    }

    public function testGetDstIPReturnsDestinationAddress(): void
    {
        $oReq = $this->makeRequest('10.0.0.1', '10.0.0.2');
        $this->assertSame('10.0.0.2', $oReq->getDstIP());
    }

    // -----------------------------------------------------------------------
    // TTL, TOS, ID, FlagsOffset
    // -----------------------------------------------------------------------

    public function testGetTtlReturnsConfiguredTtl(): void
    {
        $oReq = $this->makeRequest(iTtl: 128);
        $this->assertSame(128, $oReq->getTtl());
    }

    public function testGetTosReturnsConfiguredTos(): void
    {
        $oReq = $this->makeRequest(iTos: 16);
        $this->assertSame(16, $oReq->getTos());
    }

    public function testGetIdReturnsConfiguredId(): void
    {
        $oReq = $this->makeRequest(iId: 0x1234);
        $this->assertSame(0x1234, $oReq->getId());
    }

    public function testGetFlagsOffsetReturnsConfiguredValue(): void
    {
        $oReq = $this->makeRequest(iFlagOffset: 0x4000);
        $this->assertSame(0x4000, $oReq->getFlagsOffset());
    }

    // -----------------------------------------------------------------------
    // Header length, packet length, version
    // -----------------------------------------------------------------------

    public function testGetIpHeaderLengthIs20ForMinimalHeader(): void
    {
        // IHL = 5 (5 * 4 = 20 bytes)
        $oReq = $this->makeRequest();
        $this->assertSame(20, $oReq->getIpHeaderLength());
    }

    public function testGetPacketLengthReturnsHeaderPlusPayload(): void
    {
        $oReq = $this->makeRequest(sData: 'HELLO');
        $this->assertSame(25, $oReq->getPacketLength());
    }

    public function testGetIpVersionReturnsFour(): void
    {
        $oReq = $this->makeRequest();
        $this->assertSame(4, $oReq->getIpVersion());
    }

    // -----------------------------------------------------------------------
    // Checksum
    // -----------------------------------------------------------------------

    public function testGetCheckSumIsNonZeroForValidPacket(): void
    {
        $oReq = $this->makeRequest();
        $this->assertNotSame(0, $oReq->getCheckSum());
    }

    // -----------------------------------------------------------------------
    // Protocol
    // -----------------------------------------------------------------------

    public function testGetProtocolReturnsIcmpForProtocol1(): void
    {
        $oReq = $this->makeRequest(iProto: 0x01);
        $this->assertSame('ICMP', $oReq->getProtocol());
    }

    public function testGetProtocolReturnsTcpForProtocol6(): void
    {
        $oReq = $this->makeRequest(iProto: 0x06);
        $this->assertSame('TCP', $oReq->getProtocol());
    }

    public function testGetProtocolReturnsUdpForProtocol17(): void
    {
        $oReq = $this->makeRequest(iProto: 0x11);
        $this->assertSame('UDP', $oReq->getProtocol());
    }

    // -----------------------------------------------------------------------
    // getData() strips the IP header
    // -----------------------------------------------------------------------

    public function testGetDataReturnsOnlyPayloadNotHeader(): void
    {
        $oReq = $this->makeRequest(sData: 'PAYLOAD');
        $this->assertSame('PAYLOAD', $oReq->getData());
    }

    public function testGetDataIsEmptyWhenNoPayload(): void
    {
        $oReq = $this->makeRequest(sData: '');
        $this->assertSame('', $oReq->getData());
    }

    // -----------------------------------------------------------------------
    // Source station / network (from EconetPacket, not IP header)
    // -----------------------------------------------------------------------

    public function testGetSourceStationFromEconetPacket(): void
    {
        $oReq = $this->makeRequest();
        $this->assertSame(5, $oReq->getSourceStation());
    }

    public function testGetSourceNetworkFromEconetPacket(): void
    {
        $oReq = $this->makeRequest();
        $this->assertSame(1, $oReq->getSourceNetwork());
    }

    // -----------------------------------------------------------------------
    // Invalid packets throw
    // -----------------------------------------------------------------------

    public function testInvalidIhlThrows(): void
    {
        // First byte: version=4, IHL=4 → header length = 16 < 20
        $sData = "\x44" . str_repeat("\x00", 19);
        $oPkt  = $this->makeEconetPacket($sData);
        $this->expectException(\Exception::class);
        new IPv4Request($oPkt, $this->oLogger);
    }

    public function testInvalidVersionThrows(): void
    {
        // First byte: version=6, IHL=5 → version != 4
        $sData = "\x65" . str_repeat("\x00", 19);
        $oPkt  = $this->makeEconetPacket($sData);
        $this->expectException(\Exception::class);
        new IPv4Request($oPkt, $this->oLogger);
    }

    // -----------------------------------------------------------------------
    // forward()
    // -----------------------------------------------------------------------

    public function testForwardDecreasesTtlByOne(): void
    {
        $oReq  = $this->makeRequest(iTtl: 64);
        $oFwd  = $oReq->forward(2, 30, 1, 24);
        $sData = $oFwd->getData();
        $this->assertSame(63, ord($sData[8]));
    }

    public function testForwardClampsTtlToZeroNotNegative(): void
    {
        $oReq  = $this->makeRequest(iTtl: 0);
        $oFwd  = $oReq->forward(2, 30, 1, 24);
        $sData = $oFwd->getData();
        $this->assertSame(0, ord($sData[8]));
    }

    public function testForwardSetsEconetFlags(): void
    {
        $oReq = $this->makeRequest();
        $oFwd = $oReq->forward(2, 30, 1, 24);
        $this->assertSame(0x01, $oFwd->getFlags());
    }

    public function testForwardSetsEconetPort(): void
    {
        $oReq = $this->makeRequest();
        $oFwd = $oReq->forward(2, 30, 1, 24);
        $this->assertSame(0xD2, $oFwd->getPort());
    }

    public function testForwardSetsDestinationNetwork(): void
    {
        $oReq = $this->makeRequest();
        $oFwd = $oReq->forward(2, 30, 1, 24);
        $this->assertSame(2, $oFwd->getDestinationNetwork());
    }

    public function testForwardSetsDestinationStation(): void
    {
        $oReq = $this->makeRequest();
        $oFwd = $oReq->forward(2, 30, 1, 24);
        $this->assertSame(30, $oFwd->getDestinationStation());
    }

    public function testForwardSetsSourceNetwork(): void
    {
        $oReq = $this->makeRequest();
        $oFwd = $oReq->forward(2, 30, 1, 24);
        $this->assertSame(1, $oFwd->getSourceNetwork());
    }

    public function testForwardSetsSourceStation(): void
    {
        $oReq = $this->makeRequest();
        $oFwd = $oReq->forward(2, 30, 1, 24);
        $this->assertSame(24, $oFwd->getSourceStation());
    }

    public function testForwardedPacketHasValidChecksum(): void
    {
        $oReq  = $this->makeRequest(iTtl: 64);
        $oFwd  = $oReq->forward(2, 30, 1, 24);
        $sHdr  = substr($oFwd->getData(), 0, 20);

        // Verifying: sum of all 16-bit words in the header should be 0xFFFF
        $aPairs = unpack('n*', $sHdr);
        $iSum   = array_sum($aPairs);
        while ($iSum >> 16) { $iSum = ($iSum >> 16) + ($iSum & 0xffff); }
        $this->assertSame(0xffff, $iSum);
    }

    public function testForwardPreservesIPAddresses(): void
    {
        $oReq  = $this->makeRequest('10.1.2.3', '10.4.5.6');
        $oFwd  = $oReq->forward(2, 30, 1, 24);
        $sData = $oFwd->getData();
        $this->assertSame('10.1.2.3', inet_ntop(substr($sData, 12, 4)));
        $this->assertSame('10.4.5.6', inet_ntop(substr($sData, 16, 4)));
    }
}
