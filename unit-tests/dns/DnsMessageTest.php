<?php

/*
 * @group unit-tests
 *
 * Tests for Dns\Messages\DnsMessage - decoding a single-question DNS query and encoding the
 * matching response (see docs/protocols/dns.md).
 */

include_once(__DIR__ . '/../../src/include/system.inc.php');

use PHPUnit\Framework\TestCase;
use HomeLan\FileStore\Dns\Messages\DnsMessage;

class DnsMessageTest extends TestCase
{
    private function buildQueryBytes(string $sName, int $iType, int $iClass = DnsMessage::CLASS_IN, int $iId = 0x1234, bool $bRd = true, int $iOpcode = 0): string
    {
        $iFlags = ($iOpcode << 11) | ($bRd ? (1 << 8) : 0);
        $sHeader = pack('n6', $iId, $iFlags, 1, 0, 0, 0);
        $sQuestion = $this->encodeName($sName) . pack('n2', $iType, $iClass);
        return $sHeader . $sQuestion;
    }

    private function encodeName(string $sName): string
    {
        $sEncoded = '';
        foreach (explode('.', $sName) as $sLabel) {
            if ($sLabel === '') {
                continue;
            }
            $sEncoded .= chr(strlen($sLabel)) . $sLabel;
        }
        return $sEncoded . "\x00";
    }

    // -----------------------------------------------------------------------
    // decodeQuery
    // -----------------------------------------------------------------------

    public function testDecodesId(): void
    {
        $oQuery = DnsMessage::decodeQuery($this->buildQueryBytes('bbc.local', DnsMessage::TYPE_A, iId: 0xBEEF));
        $this->assertSame(0xBEEF, $oQuery->getId());
    }

    public function testDecodesName(): void
    {
        $oQuery = DnsMessage::decodeQuery($this->buildQueryBytes('fileserver.econet.local', DnsMessage::TYPE_A));
        $this->assertSame('fileserver.econet.local', $oQuery->getName());
    }

    public function testDecodesType(): void
    {
        $oQuery = DnsMessage::decodeQuery($this->buildQueryBytes('bbc.local', DnsMessage::TYPE_AAAA));
        $this->assertSame(DnsMessage::TYPE_AAAA, $oQuery->getType());
    }

    public function testDecodesClass(): void
    {
        $oQuery = DnsMessage::decodeQuery($this->buildQueryBytes('bbc.local', DnsMessage::TYPE_A, iClass: DnsMessage::CLASS_IN));
        $this->assertSame(DnsMessage::CLASS_IN, $oQuery->getClass());
    }

    public function testDecodesRecursionDesiredTrue(): void
    {
        $oQuery = DnsMessage::decodeQuery($this->buildQueryBytes('bbc.local', DnsMessage::TYPE_A, bRd: true));
        $this->assertTrue($oQuery->isRecursionDesired());
    }

    public function testDecodesRecursionDesiredFalse(): void
    {
        $oQuery = DnsMessage::decodeQuery($this->buildQueryBytes('bbc.local', DnsMessage::TYPE_A, bRd: false));
        $this->assertFalse($oQuery->isRecursionDesired());
    }

    public function testDecodesOpcode(): void
    {
        $oQuery = DnsMessage::decodeQuery($this->buildQueryBytes('bbc.local', DnsMessage::TYPE_A, iOpcode: 2));
        $this->assertSame(2, $oQuery->getOpcode());
    }

    public function testRootLabelTerminatesTheName(): void
    {
        // A single root query (name "") is a degenerate but legal case: just the zero-length terminator.
        $oQuery = DnsMessage::decodeQuery($this->buildQueryBytes('', DnsMessage::TYPE_A));
        $this->assertSame('', $oQuery->getName());
    }

    public function testTruncatedPacketThrows(): void
    {
        $this->expectException(\Exception::class);
        DnsMessage::decodeQuery("\x00");
    }

    public function testResponseBitSetThrows(): void
    {
        $sBytes = $this->buildQueryBytes('bbc.local', DnsMessage::TYPE_A);
        $sBytes[2] = chr(ord($sBytes[2]) | 0x80); // set QR bit
        $this->expectException(\Exception::class);
        DnsMessage::decodeQuery($sBytes);
    }

    public function testMultiQuestionCountThrows(): void
    {
        $sHeader = pack('n6', 0x1234, 0, 2, 0, 0, 0); // qdcount = 2
        $sQuestion = $this->encodeName('bbc.local') . pack('n2', DnsMessage::TYPE_A, DnsMessage::CLASS_IN);
        $this->expectException(\Exception::class);
        DnsMessage::decodeQuery($sHeader . $sQuestion);
    }

    public function testCompressionPointerInQuestionNameThrows(): void
    {
        $sHeader = pack('n6', 0x1234, 0, 1, 0, 0, 0);
        $this->expectException(\Exception::class);
        DnsMessage::decodeQuery($sHeader . "\xC0\x0C" . pack('n2', DnsMessage::TYPE_A, DnsMessage::CLASS_IN));
    }

    // -----------------------------------------------------------------------
    // encodeResponse
    // -----------------------------------------------------------------------

    public function testResponseEchoesIdAndQuestion(): void
    {
        $oQuery = DnsMessage::decodeQuery($this->buildQueryBytes('bbc.local', DnsMessage::TYPE_A, iId: 0x4242));
        $sResponse = $oQuery->encodeResponse(DnsMessage::RCODE_NOERROR, []);

        $aHeader = unpack('nid/nflags/nqdcount/nancount', $sResponse);
        $this->assertSame(0x4242, $aHeader['id']);
        $this->assertSame(1, $aHeader['qdcount']);
    }

    public function testResponseSetsQrBit(): void
    {
        $oQuery = DnsMessage::decodeQuery($this->buildQueryBytes('bbc.local', DnsMessage::TYPE_A));
        $sResponse = $oQuery->encodeResponse(DnsMessage::RCODE_NOERROR, []);
        $aHeader = unpack('nid/nflags', $sResponse);
        $this->assertSame(1, ($aHeader['flags'] >> 15) & 0x1);
    }

    public function testResponseEchoesRecursionDesired(): void
    {
        $oQuery = DnsMessage::decodeQuery($this->buildQueryBytes('bbc.local', DnsMessage::TYPE_A, bRd: true));
        $sResponse = $oQuery->encodeResponse(DnsMessage::RCODE_NOERROR, []);
        $aHeader = unpack('nid/nflags', $sResponse);
        $this->assertSame(1, ($aHeader['flags'] >> 8) & 0x1);
    }

    public function testResponseEncodesRcode(): void
    {
        $oQuery = DnsMessage::decodeQuery($this->buildQueryBytes('bbc.local', DnsMessage::TYPE_A));
        $sResponse = $oQuery->encodeResponse(DnsMessage::RCODE_NXDOMAIN, []);
        $aHeader = unpack('nid/nflags', $sResponse);
        $this->assertSame(DnsMessage::RCODE_NXDOMAIN, $aHeader['flags'] & 0xF);
    }

    public function testResponseWithNoAnswersHasZeroAncount(): void
    {
        $oQuery = DnsMessage::decodeQuery($this->buildQueryBytes('bbc.local', DnsMessage::TYPE_A));
        $sResponse = $oQuery->encodeResponse(DnsMessage::RCODE_NXDOMAIN, []);
        $aHeader = unpack('nid/nflags/nqdcount/nancount', $sResponse);
        $this->assertSame(0, $aHeader['ancount']);
    }

    public function testResponseEncodesAnswerCount(): void
    {
        $oQuery = DnsMessage::decodeQuery($this->buildQueryBytes('bbc.local', DnsMessage::TYPE_A));
        $sResponse = $oQuery->encodeResponse(DnsMessage::RCODE_NOERROR, [
            ['type' => DnsMessage::TYPE_A, 'ttl' => 300, 'rdata' => (string) inet_pton('192.168.0.5')],
        ]);
        $aHeader = unpack('nid/nflags/nqdcount/nancount', $sResponse);
        $this->assertSame(1, $aHeader['ancount']);
    }

    public function testResponseAnswerNamePointsAtOffset12(): void
    {
        $oQuery = DnsMessage::decodeQuery($this->buildQueryBytes('bbc.local', DnsMessage::TYPE_A));
        $sResponse = $oQuery->encodeResponse(DnsMessage::RCODE_NOERROR, [
            ['type' => DnsMessage::TYPE_A, 'ttl' => 300, 'rdata' => (string) inet_pton('192.168.0.5')],
        ]);

        // The question section starts at offset 12 and is "bbc.local" (9 bytes) + 2 length
        // bytes + 1 terminator = 12 bytes, then 4 bytes of type/class -> answer starts at 28.
        $iAnswerOffset = 12 + 1 + 3 + 1 + 5 + 1 + 4;
        $sAnswerName = substr($sResponse, $iAnswerOffset, 2);
        $this->assertSame("\xC0\x0C", $sAnswerName);
    }

    public function testResponseAnswerRdataRoundTripsAnIpv4Address(): void
    {
        $oQuery = DnsMessage::decodeQuery($this->buildQueryBytes('bbc.local', DnsMessage::TYPE_A));
        $sResponse = $oQuery->encodeResponse(DnsMessage::RCODE_NOERROR, [
            ['type' => DnsMessage::TYPE_A, 'ttl' => 300, 'rdata' => (string) inet_pton('192.168.0.5')],
        ]);
        // Last 4 bytes of the response are the RDATA for a single A record.
        $sRdata = substr($sResponse, -4);
        $this->assertSame('192.168.0.5', inet_ntop($sRdata));
    }

    public function testResponseAnswerTtlIsEncoded(): void
    {
        $oQuery = DnsMessage::decodeQuery($this->buildQueryBytes('bbc.local', DnsMessage::TYPE_A));
        $sResponse = $oQuery->encodeResponse(DnsMessage::RCODE_NOERROR, [
            ['type' => DnsMessage::TYPE_A, 'ttl' => 12345, 'rdata' => (string) inet_pton('192.168.0.5')],
        ]);
        // TTL is the 4 bytes immediately before RDLENGTH+RDATA (2 + 4 bytes from the end).
        $sTtl = substr($sResponse, -10, 4);
        $aTtl = unpack('N', $sTtl);
        $this->assertSame(12345, $aTtl[1]);
    }

    // -----------------------------------------------------------------------
    // ipFromPtrName
    // -----------------------------------------------------------------------

    public function testIpFromPtrNameDecodesAnIpv4ReverseName(): void
    {
        $this->assertSame('192.168.0.5', DnsMessage::ipFromPtrName('5.0.168.192.in-addr.arpa'));
    }

    public function testIpFromPtrNameIsCaseInsensitiveAndIgnoresTrailingDot(): void
    {
        $this->assertSame('192.168.0.5', DnsMessage::ipFromPtrName('5.0.168.192.IN-ADDR.ARPA.'));
    }

    public function testIpFromPtrNameRejectsIpv6ReverseNames(): void
    {
        // dnsd is IPv4-only - ip6.arpa is not recognised as a scheme at all, not even parsed.
        $sReverseName = '1.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.8.e.f.ip6.arpa';
        $this->assertNull(DnsMessage::ipFromPtrName($sReverseName));
    }

    public function testIpFromPtrNameRejectsWrongOctetCount(): void
    {
        $this->assertNull(DnsMessage::ipFromPtrName('0.168.192.in-addr.arpa'));
    }

    public function testIpFromPtrNameRejectsAnOutOfRangeOctet(): void
    {
        $this->assertNull(DnsMessage::ipFromPtrName('999.0.168.192.in-addr.arpa'));
    }

    public function testIpFromPtrNameRejectsSomethingThatIsNeitherScheme(): void
    {
        $this->assertNull(DnsMessage::ipFromPtrName('www.example.com'));
    }

    // -----------------------------------------------------------------------
    // encodeDomainName
    // -----------------------------------------------------------------------

    public function testEncodeDomainNameRoundTripsThroughDecodeName(): void
    {
        // Build a minimal query whose question name is the encoded domain, then decode it back.
        $sEncoded = DnsMessage::encodeDomainName('fileserver.econet.local');
        $sHeader = pack('n6', 1, 0, 1, 0, 0, 0);
        $oQuery = DnsMessage::decodeQuery($sHeader . $sEncoded . pack('n2', DnsMessage::TYPE_A, DnsMessage::CLASS_IN));
        $this->assertSame('fileserver.econet.local', $oQuery->getName());
    }

    public function testPtrAnswerRdataIsAWireEncodedDomainName(): void
    {
        $oQuery = DnsMessage::decodeQuery($this->buildQueryBytes('5.0.168.192.in-addr.arpa', DnsMessage::TYPE_PTR));
        $sResponse = $oQuery->encodeResponse(DnsMessage::RCODE_NOERROR, [
            ['type' => DnsMessage::TYPE_PTR, 'ttl' => 300, 'rdata' => DnsMessage::encodeDomainName('fileserver')],
        ]);
        $this->assertSame(DnsMessage::encodeDomainName('fileserver'), substr($sResponse, -12));
    }

    // -----------------------------------------------------------------------
    // stripAaaaRecords - dnsd is IPv4-only, so any AAAA in a forwarded upstream
    // response must never survive to reach an Econet client (see docs/protocols/dns.md).
    // -----------------------------------------------------------------------

    /** A raw resource record: name (already wire-encoded), type, class, ttl, rdata. */
    private function buildRR(string $sWireName, int $iType, int $iClass, int $iTtl, string $sRdata): string
    {
        return $sWireName . pack('n2N', $iType, $iClass, $iTtl) . pack('n', strlen($sRdata)) . $sRdata;
    }

    /**
     * @param list<string> $aAnswers
     * @param list<string> $aAuthority
     * @param list<string> $aAdditional
     */
    private function buildResponseBytes(
        int $iId,
        string $sQuestionName,
        int $iQType,
        array $aAnswers,
        array $aAuthority = [],
        array $aAdditional = [],
        int $iFlags = 0x8180,
    ): string {
        $sHeader = pack(
            'n6',
            $iId,
            $iFlags,
            1,
            count($aAnswers),
            count($aAuthority),
            count($aAdditional),
        );
        $sQuestion = DnsMessage::encodeDomainName($sQuestionName) . pack('n2', $iQType, DnsMessage::CLASS_IN);
        return $sHeader . $sQuestion . implode('', $aAnswers) . implode('', $aAuthority) . implode('', $aAdditional);
    }

    public function testStripAaaaRemovesAnAaaaAnswer(): void
    {
        $sPacket = $this->buildResponseBytes(0x1234, 'bbc.local', DnsMessage::TYPE_AAAA, [
            $this->buildRR("\xC0\x0C", DnsMessage::TYPE_AAAA, DnsMessage::CLASS_IN, 300, str_repeat("\x00", 15) . "\x01"),
        ]);
        $sFiltered = DnsMessage::stripAaaaRecords($sPacket);

        $aHeader = unpack('nid/nflags/nqdcount/nancount', $sFiltered);
        $this->assertSame(0, $aHeader['ancount']);
    }

    public function testStripAaaaKeepsAnARecord(): void
    {
        $sPacket = $this->buildResponseBytes(0x1234, 'bbc.local', DnsMessage::TYPE_A, [
            $this->buildRR("\xC0\x0C", DnsMessage::TYPE_A, DnsMessage::CLASS_IN, 300, (string) inet_pton('192.168.0.5')),
        ]);
        $sFiltered = DnsMessage::stripAaaaRecords($sPacket);

        $aHeader = unpack('nid/nflags/nqdcount/nancount', $sFiltered);
        $this->assertSame(1, $aHeader['ancount']);
        $this->assertSame('192.168.0.5', inet_ntop(substr($sFiltered, -4)));
    }

    public function testStripAaaaKeepsARecordAndDropsAaaaFromTheSameAnswerSection(): void
    {
        $sPacket = $this->buildResponseBytes(0x1234, 'bbc.local', DnsMessage::TYPE_A, [
            $this->buildRR("\xC0\x0C", DnsMessage::TYPE_A, DnsMessage::CLASS_IN, 300, (string) inet_pton('192.168.0.5')),
            $this->buildRR("\xC0\x0C", DnsMessage::TYPE_AAAA, DnsMessage::CLASS_IN, 300, str_repeat("\x00", 16)),
        ]);
        $sFiltered = DnsMessage::stripAaaaRecords($sPacket);

        $aHeader = unpack('nid/nflags/nqdcount/nancount', $sFiltered);
        $this->assertSame(1, $aHeader['ancount']);
    }

    public function testStripAaaaRemovesFromTheAuthoritySection(): void
    {
        $sPacket = $this->buildResponseBytes(0x1234, 'bbc.local', DnsMessage::TYPE_AAAA, [], [
            $this->buildRR("\xC0\x0C", DnsMessage::TYPE_AAAA, DnsMessage::CLASS_IN, 300, str_repeat("\x00", 16)),
        ]);
        $sFiltered = DnsMessage::stripAaaaRecords($sPacket);

        $aHeader = unpack('nid/nflags/nqdcount/nancount/nnscount', $sFiltered);
        $this->assertSame(0, $aHeader['nscount']);
    }

    public function testStripAaaaRemovesFromTheAdditionalSection(): void
    {
        $sPacket = $this->buildResponseBytes(0x1234, 'bbc.local', DnsMessage::TYPE_A, [], [], [
            $this->buildRR("\xC0\x0C", DnsMessage::TYPE_AAAA, DnsMessage::CLASS_IN, 300, str_repeat("\x00", 16)),
        ]);
        $sFiltered = DnsMessage::stripAaaaRecords($sPacket);

        $aHeader = unpack('nid/nflags/nqdcount/nancount/nnscount/narcount', $sFiltered);
        $this->assertSame(0, $aHeader['arcount']);
    }

    public function testStripAaaaPreservesIdAndFlags(): void
    {
        $sPacket = $this->buildResponseBytes(0xBEEF, 'bbc.local', DnsMessage::TYPE_A, [], [], [], 0x8183);
        $sFiltered = DnsMessage::stripAaaaRecords($sPacket);

        $aHeader = unpack('nid/nflags', $sFiltered);
        $this->assertSame(0xBEEF, $aHeader['id']);
        $this->assertSame(0x8183, $aHeader['flags']);
    }

    public function testStripAaaaPreservesTheQuestionSection(): void
    {
        $sPacket = $this->buildResponseBytes(0x1234, 'fileserver.econet.local', DnsMessage::TYPE_A, []);
        $sFiltered = DnsMessage::stripAaaaRecords($sPacket);

        $oQuery = DnsMessage::decodeQuery(substr_replace($sFiltered, "\x00\x00", 2, 2)); // clear QR to reuse decodeQuery
        $this->assertSame('fileserver.econet.local', $oQuery->getName());
    }

    public function testStripAaaaFollowsACompressedRrName(): void
    {
        // A single answer whose NAME is a compression pointer back to the question name.
        $sPacket = $this->buildResponseBytes(0x1234, 'bbc.local', DnsMessage::TYPE_A, [
            $this->buildRR("\xC0\x0C", DnsMessage::TYPE_A, DnsMessage::CLASS_IN, 300, (string) inet_pton('192.168.0.5')),
        ]);
        $sFiltered = DnsMessage::stripAaaaRecords($sPacket);

        // The retained answer's name should be re-encoded fully expanded, not left as a pointer.
        $this->assertStringContainsString(DnsMessage::encodeDomainName('bbc.local'), $sFiltered);
    }

    public function testStripAaaaFullyExpandsACompressedCnameTarget(): void
    {
        // CNAME "bbc.local" -> "target.local", where the target is spelled out in full.
        $sCnameRdata = DnsMessage::encodeDomainName('target.local');
        $sPacket = $this->buildResponseBytes(0x1234, 'bbc.local', DnsMessage::TYPE_A, [
            $this->buildRR("\xC0\x0C", DnsMessage::TYPE_CNAME, DnsMessage::CLASS_IN, 300, $sCnameRdata),
        ]);
        $sFiltered = DnsMessage::stripAaaaRecords($sPacket);

        $this->assertStringContainsString(DnsMessage::encodeDomainName('target.local'), $sFiltered);
    }

    public function testStripAaaaOnAMalformedPacketThrows(): void
    {
        $this->expectException(\Exception::class);
        DnsMessage::stripAaaaRecords("\x00\x01\x02");
    }

    public function testStripAaaaWithNoRecordsAtAllLeavesAnEmptyResponseIntact(): void
    {
        $sPacket = $this->buildResponseBytes(0x1234, 'nosuchhost', DnsMessage::TYPE_A, [], [], [], 0x8183);
        $sFiltered = DnsMessage::stripAaaaRecords($sPacket);

        $aHeader = unpack('nid/nflags/nqdcount/nancount/nnscount/narcount', $sFiltered);
        $this->assertSame(0, $aHeader['ancount']);
        $this->assertSame(0, $aHeader['nscount']);
        $this->assertSame(0, $aHeader['arcount']);
    }
}
