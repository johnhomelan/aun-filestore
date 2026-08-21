<?php

/*
 * @group unit-tests
 *
 * Tests for Ntp\Messages\NtpMessage - decoding the 48-byte NTP request header and encoding the
 * matching server (mode 4) response (see docs/protocols/ntp.md).
 */

include_once(__DIR__ . '/../../src/include/system.inc.php');

use PHPUnit\Framework\TestCase;
use HomeLan\FileStore\Ntp\Messages\NtpMessage;

class NtpMessageTest extends TestCase
{
    private function buildRequestBytes(int $iVersion = 3, int $iMode = NtpMessage::MODE_CLIENT, int $iPoll = 4, string $sTransmitTimestamp = "\x00\x00\x00\x00\x00\x00\x00\x00"): string
    {
        $iFlags = (($iVersion & 0x7) << 3) | ($iMode & 0x7);
        $sHeader = chr($iFlags) . chr(0) . chr($iPoll & 0xFF) . chr(0);
        $sHeader .= str_repeat("\x00", 4); // root delay
        $sHeader .= str_repeat("\x00", 4); // root dispersion
        $sHeader .= str_repeat("\x00", 4); // reference id
        $sHeader .= str_repeat("\x00", 8); // reference timestamp
        $sHeader .= str_repeat("\x00", 8); // origin timestamp
        $sHeader .= str_repeat("\x00", 8); // receive timestamp
        $sHeader .= $sTransmitTimestamp;   // transmit timestamp
        return $sHeader;
    }

    /** @return array{0:int,1:int} [seconds since NTP epoch, fraction] */
    private function decodeRawTimestamp(string $sBytes): array
    {
        $aParts = unpack('Nseconds/Nfraction', $sBytes);
        return [$aParts['seconds'], $aParts['fraction']];
    }

    private function decodeUnixTime(string $sBytes): float
    {
        [$iSeconds, $iFraction] = $this->decodeRawTimestamp($sBytes);
        return ($iSeconds - 2208988800) + ($iFraction / 4294967296.0);
    }

    // -----------------------------------------------------------------------
    // decodeRequest
    // -----------------------------------------------------------------------

    public function testDecodesVersion(): void
    {
        $oRequest = NtpMessage::decodeRequest($this->buildRequestBytes(iVersion: 4));
        $this->assertSame(4, $oRequest->getVersion());
    }

    public function testDecodesMode(): void
    {
        $oRequest = NtpMessage::decodeRequest($this->buildRequestBytes(iMode: NtpMessage::MODE_CLIENT));
        $this->assertSame(NtpMessage::MODE_CLIENT, $oRequest->getMode());
    }

    public function testDecodesPositivePoll(): void
    {
        $oRequest = NtpMessage::decodeRequest($this->buildRequestBytes(iPoll: 10));
        $this->assertSame(10, $oRequest->getPoll());
    }

    public function testDecodesNegativePoll(): void
    {
        $oRequest = NtpMessage::decodeRequest($this->buildRequestBytes(iPoll: -6));
        $this->assertSame(-6, $oRequest->getPoll());
    }

    public function testThrowsOnAPacketShorterThanTheMinimumHeader(): void
    {
        $this->expectException(\Exception::class);
        NtpMessage::decodeRequest(str_repeat("\x00", 47));
    }

    public function testExactlyFortyEightBytesIsAccepted(): void
    {
        $oRequest = NtpMessage::decodeRequest($this->buildRequestBytes());
        $this->assertSame(NtpMessage::MODE_CLIENT, $oRequest->getMode());
    }

    // -----------------------------------------------------------------------
    // encodeResponse
    // -----------------------------------------------------------------------

    public function testResponseIsFortyEightBytes(): void
    {
        $oRequest = NtpMessage::decodeRequest($this->buildRequestBytes());
        $sResponse = $oRequest->encodeResponse(1, 'LOCL', -6, 1000.0, 1000.0);
        $this->assertSame(48, strlen($sResponse));
    }

    public function testResponseModeIsServer(): void
    {
        $oRequest = NtpMessage::decodeRequest($this->buildRequestBytes());
        $sResponse = $oRequest->encodeResponse(1, 'LOCL', -6, 1000.0, 1000.0);
        $this->assertSame(NtpMessage::MODE_SERVER, ord($sResponse[0]) & 0x7);
    }

    public function testResponseEchoesTheClientsVersion(): void
    {
        $oRequest = NtpMessage::decodeRequest($this->buildRequestBytes(iVersion: 4));
        $sResponse = $oRequest->encodeResponse(1, 'LOCL', -6, 1000.0, 1000.0);
        $this->assertSame(4, (ord($sResponse[0]) >> 3) & 0x7);
    }

    public function testResponseEchoesTheClientsPoll(): void
    {
        $oRequest = NtpMessage::decodeRequest($this->buildRequestBytes(iPoll: -8));
        $sResponse = $oRequest->encodeResponse(1, 'LOCL', -6, 1000.0, 1000.0);
        $iPollByte = ord($sResponse[2]);
        $this->assertSame(-8, $iPollByte >= 128 ? $iPollByte - 256 : $iPollByte);
    }

    public function testResponseSetsTheConfiguredStratum(): void
    {
        $oRequest = NtpMessage::decodeRequest($this->buildRequestBytes());
        $sResponse = $oRequest->encodeResponse(2, 'LOCL', -6, 1000.0, 1000.0);
        $this->assertSame(2, ord($sResponse[1]));
    }

    public function testResponseSetsTheConfiguredPrecision(): void
    {
        $oRequest = NtpMessage::decodeRequest($this->buildRequestBytes());
        $sResponse = $oRequest->encodeResponse(1, 'LOCL', -10, 1000.0, 1000.0);
        $iPrecisionByte = ord($sResponse[3]);
        $this->assertSame(-10, $iPrecisionByte >= 128 ? $iPrecisionByte - 256 : $iPrecisionByte);
    }

    public function testResponseRootDelayAndDispersionAreZero(): void
    {
        $oRequest = NtpMessage::decodeRequest($this->buildRequestBytes());
        $sResponse = $oRequest->encodeResponse(1, 'LOCL', -6, 1000.0, 1000.0);
        $this->assertSame("\x00\x00\x00\x00\x00\x00\x00\x00", substr($sResponse, 4, 8));
    }

    public function testResponseEncodesTheReferenceId(): void
    {
        $oRequest = NtpMessage::decodeRequest($this->buildRequestBytes());
        $sResponse = $oRequest->encodeResponse(1, 'LOCL', -6, 1000.0, 1000.0);
        $this->assertSame('LOCL', substr($sResponse, 12, 4));
    }

    public function testResponseReferenceIdShorterThanFourBytesIsZeroPadded(): void
    {
        $oRequest = NtpMessage::decodeRequest($this->buildRequestBytes());
        $sResponse = $oRequest->encodeResponse(1, 'GPS', -6, 1000.0, 1000.0);
        $this->assertSame("GPS\x00", substr($sResponse, 12, 4));
    }

    public function testResponseReferenceIdLongerThanFourBytesIsTruncated(): void
    {
        $oRequest = NtpMessage::decodeRequest($this->buildRequestBytes());
        $sResponse = $oRequest->encodeResponse(1, 'TOOLONG', -6, 1000.0, 1000.0);
        $this->assertSame('TOOL', substr($sResponse, 12, 4));
    }

    public function testResponseOriginTimestampIsTheClientsOwnTransmitTimestamp(): void
    {
        $sClientTransmit = "\x12\x34\x56\x78\x9A\xBC\xDE\xF0";
        $oRequest = NtpMessage::decodeRequest($this->buildRequestBytes(sTransmitTimestamp: $sClientTransmit));
        $sResponse = $oRequest->encodeResponse(1, 'LOCL', -6, 1000.0, 1000.0);
        // Origin timestamp is at offset 24 (see docs/protocols/ntp.md wire layout).
        $this->assertSame($sClientTransmit, substr($sResponse, 24, 8));
    }

    public function testResponseReceiveTimestampEncodesTheGivenTime(): void
    {
        $oRequest = NtpMessage::decodeRequest($this->buildRequestBytes());
        $fReceiveTime = 1700000000.25;
        $sResponse = $oRequest->encodeResponse(1, 'LOCL', -6, $fReceiveTime, 1700000000.5);
        // Receive timestamp is at offset 32.
        $this->assertEqualsWithDelta($fReceiveTime, $this->decodeUnixTime(substr($sResponse, 32, 8)), 0.0000001);
    }

    public function testResponseTransmitTimestampEncodesTheGivenTime(): void
    {
        $oRequest = NtpMessage::decodeRequest($this->buildRequestBytes());
        $fTransmitTime = 1700000000.75;
        $sResponse = $oRequest->encodeResponse(1, 'LOCL', -6, 1700000000.5, $fTransmitTime);
        // Transmit timestamp is at offset 40.
        $this->assertEqualsWithDelta($fTransmitTime, $this->decodeUnixTime(substr($sResponse, 40, 8)), 0.0000001);
    }

    public function testResponseReferenceTimestampEqualsTheReceiveTime(): void
    {
        $oRequest = NtpMessage::decodeRequest($this->buildRequestBytes());
        $fReceiveTime = 1700000000.125;
        $sResponse = $oRequest->encodeResponse(1, 'LOCL', -6, $fReceiveTime, 1700000000.5);
        // Reference timestamp is at offset 16.
        $this->assertEqualsWithDelta($fReceiveTime, $this->decodeUnixTime(substr($sResponse, 16, 8)), 0.0000001);
    }

    public function testEncodedTimestampSecondsFieldUsesTheNtpEpoch(): void
    {
        // 2026-01-01T00:00:00Z is a known number of seconds after both epochs.
        $fUnixTime = 1767225600.0;
        $oRequest = NtpMessage::decodeRequest($this->buildRequestBytes());
        $sResponse = $oRequest->encodeResponse(1, 'LOCL', -6, $fUnixTime, $fUnixTime);
        [$iSeconds] = $this->decodeRawTimestamp(substr($sResponse, 16, 8));
        $this->assertSame((int) $fUnixTime + 2208988800, $iSeconds);
    }
}
