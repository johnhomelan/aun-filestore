<?php

/*
 * @group unit-tests
 *
 * Tests for ShareFs\Messages\ShareFsPacket - RPC framing, FileDesc, and streaming helpers.
 */

include_once(__DIR__ . '/../../src/include/system.inc.php');

use PHPUnit\Framework\TestCase;
use HomeLan\FileStore\ShareFs\Messages\ShareFsPacket;

class ShareFsPacketTest extends TestCase
{
    private function aCmd(int $iCode, string $sBody): string
    {
        return 'A' . "\x11\x22\x33" . pack('V', $iCode) . $sBody;
    }

    // -----------------------------------------------------------------------
    // Request envelope
    // -----------------------------------------------------------------------

    public function testDecodesACommandEnvelope(): void
    {
        $oRequest = ShareFsPacket::decodeRequest($this->aCmd(ShareFsPacket::CODE_RFIND, "\x00\x00\x00\x00DISC0.FILE\x00"));

        $this->assertSame('A', $oRequest->getCmdType());
        $this->assertSame("\x11\x22\x33", $oRequest->getRid());
        $this->assertSame(ShareFsPacket::CODE_RFIND, $oRequest->getCode());
    }

    public function testDecodesFCommandEnvelope(): void
    {
        $sPacket = 'F' . "\x01\x02\x03" . pack('V', ShareFsPacket::CODE_RVERSION) . pack('V', 0);
        $oRequest = ShareFsPacket::decodeRequest($sPacket);
        $this->assertSame('F', $oRequest->getCmdType());
        $this->assertSame(ShareFsPacket::CODE_RVERSION, $oRequest->getCode());
    }

    public function testDecodeRejectsUnknownCommandType(): void
    {
        $this->expectException(\Exception::class);
        ShareFsPacket::decodeRequest('Z' . "\x00\x00\x00" . pack('V', 0));
    }

    public function testDecodeRejectsTooShortPacket(): void
    {
        $this->expectException(\Exception::class);
        ShareFsPacket::decodeRequest('A');
    }

    // -----------------------------------------------------------------------
    // Per-command body decoders
    // -----------------------------------------------------------------------

    public function testDecodePath(): void
    {
        $sBody = pack('V', 0) . "DISC0.MYFILE\x00trailing-garbage-ignored";
        $this->assertSame('DISC0.MYFILE', ShareFsPacket::decodePath($sBody));
    }

    public function testDecodeAccessRequest(): void
    {
        // attrs(4) then path(z) at body offset 8
        $sBody = pack('V', 0x13) . pack('V', 0) . "DISC0.FILE\x00";
        $aResult = ShareFsPacket::decodeAccessRequest($sBody);
        $this->assertSame(0x13, $aResult['attrs']);
        $this->assertSame('DISC0.FILE', $aResult['path']);
    }

    public function testDecodeRenameArm(): void
    {
        $sBody = pack('V', 12) . pack('V', 0) . "DISC0.OLDNAME\x00";
        $aResult = ShareFsPacket::decodeRenameArm($sBody);
        $this->assertSame(12, $aResult['newNameLength']);
        $this->assertSame('DISC0.OLDNAME', $aResult['oldPath']);
    }

    public function testDecodeHandleOffsetAmount(): void
    {
        $sBody = pack('V', 5) . pack('V', 100) . pack('V', 256);
        $aResult = ShareFsPacket::decodeHandleOffsetAmount($sBody);
        $this->assertSame(5, $aResult['handle']);
        $this->assertSame(100, $aResult['offset']);
        $this->assertSame(256, $aResult['amount']);
    }

    public function testDecodeSetInfoRequest(): void
    {
        $sBody = pack('V', 5) . pack('V', 0xFFFF0000) . pack('V', 0x12345678);
        $aResult = ShareFsPacket::decodeSetInfoRequest($sBody);
        $this->assertSame(5, $aResult['handle']);
        $this->assertSame(0xFFFF0000, $aResult['load']);
        $this->assertSame(0x12345678, $aResult['exec']);
    }

    public function testDecodeHandleAndValue(): void
    {
        $sBody = pack('V', 7) . pack('V', 4096);
        $aResult = ShareFsPacket::decodeHandleAndValue($sBody);
        $this->assertSame(7, $aResult['handle']);
        $this->assertSame(4096, $aResult['value']);
    }

    public function testDecodeReaddirRequest(): void
    {
        $sBody = pack('V', 3) . pack('V', 20);
        $aResult = ShareFsPacket::decodeReaddirRequest($sBody);
        $this->assertSame(3, $aResult['handle']);
        $this->assertSame(20, $aResult['startEntry']);
    }

    // -----------------------------------------------------------------------
    // FileDesc
    // -----------------------------------------------------------------------

    public function testFileDescRoundTrips(): void
    {
        $sEncoded = ShareFsPacket::encodeFileDesc(0xFFF00A00, 0x12345678, 1024, 0x13, ShareFsPacket::TYPE_FILE);
        $aDecoded = ShareFsPacket::decodeFileDesc($sEncoded);

        $this->assertSame(0xFFF00A00, $aDecoded['load']);
        $this->assertSame(0x12345678, $aDecoded['exec']);
        $this->assertSame(1024, $aDecoded['length']);
        $this->assertSame(0x13, $aDecoded['attrs']);
        $this->assertSame(ShareFsPacket::TYPE_FILE, $aDecoded['type']);
    }

    public function testFileDescIsTwentyBytes(): void
    {
        $this->assertSame(20, strlen(ShareFsPacket::encodeFileDesc(0, 0, 0, 0, 0)));
    }

    public function testDecodeFileDescRejectsTooShortPayload(): void
    {
        $this->expectException(\Exception::class);
        ShareFsPacket::decodeFileDesc('short');
    }

    // -----------------------------------------------------------------------
    // Replies
    // -----------------------------------------------------------------------

    public function testEncodeSuccess(): void
    {
        $this->assertSame('R' . "\x01\x02\x03" . 'payload', ShareFsPacket::encodeSuccess("\x01\x02\x03", 'payload'));
    }

    public function testEncodeError(): void
    {
        $sEncoded = ShareFsPacket::encodeError("\x01\x02\x03", 2);
        $this->assertSame('E' . "\x01\x02\x03" . pack('V', 2), $sEncoded);
    }

    // -----------------------------------------------------------------------
    // RREAD streaming (D/r)
    // -----------------------------------------------------------------------

    public function testEncodeReadData(): void
    {
        $sEncoded = ShareFsPacket::encodeReadData("\x01\x02\x03", 0, 'hello');
        $this->assertSame('D' . "\x01\x02\x03" . pack('V', 0) . 'hello', $sEncoded);
    }

    public function testDecodeReadAck(): void
    {
        $this->assertSame("\x01\x02\x03", ShareFsPacket::decodeReadAck('r' . "\x01\x02\x03"));
    }

    public function testDecodeReadAckRejectsWrongType(): void
    {
        $this->expectException(\Exception::class);
        ShareFsPacket::decodeReadAck('x' . "\x01\x02\x03");
    }

    // -----------------------------------------------------------------------
    // RWRITE streaming (w/d)
    // -----------------------------------------------------------------------

    public function testEncodeWriteRequest(): void
    {
        $sEncoded = ShareFsPacket::encodeWriteRequest("\x01\x02\x03", 0, 1024);
        $this->assertSame('w' . "\x01\x02\x03" . pack('V', 0) . pack('V', 0) . pack('V', 1024), $sEncoded);
    }

    public function testDecodeWriteData(): void
    {
        $sPacket = 'd' . "\x01\x02\x03" . pack('V', 0) . 'HELLO';
        $aResult = ShareFsPacket::decodeWriteData($sPacket);
        $this->assertSame("\x01\x02\x03", $aResult['rid']);
        $this->assertSame(0, $aResult['relPos']);
        $this->assertSame('HELLO', $aResult['data']);
    }

    public function testDecodeWriteDataRejectsWrongType(): void
    {
        $this->expectException(\Exception::class);
        ShareFsPacket::decodeWriteData('x' . "\x01\x02\x03" . pack('V', 0));
    }

    // -----------------------------------------------------------------------
    // RREADDIR (S+B)
    // -----------------------------------------------------------------------

    public function testDirEntriesRoundTrip(): void
    {
        $aEntries = [
            ['name' => 'FILE1', 'load' => 0xFFF00A00, 'exec' => 0x12345678, 'length' => 100, 'attrs' => 0x13, 'type' => ShareFsPacket::TYPE_FILE],
            ['name' => 'SUBDIR', 'load' => 0, 'exec' => 0, 'length' => 0, 'attrs' => 0x03, 'type' => ShareFsPacket::TYPE_DIR],
        ];
        $sBlob = ShareFsPacket::encodeDirEntries($aEntries);
        $aDecoded = ShareFsPacket::decodeDirEntries($sBlob);

        $this->assertCount(2, $aDecoded);
        $this->assertSame('FILE1', $aDecoded[0]['name']);
        $this->assertSame(100, $aDecoded[0]['length']);
        $this->assertSame('SUBDIR', $aDecoded[1]['name']);
        $this->assertSame(ShareFsPacket::TYPE_DIR, $aDecoded[1]['type']);
    }

    public function testEncodeReaddirPageHasSHeadAndBTrailer(): void
    {
        $sBlob = ShareFsPacket::encodeDirEntries([]);
        $sPage = ShareFsPacket::encodeReaddirPage("\x01\x02\x03", $sBlob);

        $this->assertSame('S', $sPage[0]);
        $this->assertSame("\x01\x02\x03", substr($sPage, 1, 3));
        $this->assertSame('B', substr($sPage, -12, 1));
    }
}
