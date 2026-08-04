<?php

/*
 * @group unit-tests
 */

use PHPUnit\Framework\TestCase;
use Monolog\Logger;
use Monolog\Handler\NullHandler;
use HomeLan\FileStore\Messages\EconetPacket;
use HomeLan\FileStore\Messages\TorchnetRequest;
use HomeLan\FileStore\Messages\TorchnetReply;

include_once('include/system.inc.php');

class TorchnetReplyTest extends TestCase
{
    private Logger $oLogger;

    protected function setUp(): void
    {
        $this->oLogger = new Logger('torchnet-reply-test');
        $this->oLogger->pushHandler(new NullHandler());
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * Build a minimal TorchnetRequest so we can construct a TorchnetReply.
     * Uses a CLOSE command packet (2 bytes) on net 1, station 5, port 0x90.
     */
    private function makeRequest(int $iNet = 1, int $iStn = 5, int $iPort = 0x90): TorchnetRequest
    {
        $oPacket = new EconetPacket();
        $oPacket->setPort($iPort);
        $oPacket->setFlags(0x00);
        $oPacket->setSourceNetwork($iNet);
        $oPacket->setSourceStation($iStn);
        $oPacket->setData(pack('CC', 0x02, 0x01)); // CLOSE handle=1
        return new TorchnetRequest($oPacket, $this->oLogger);
    }

    /**
     * Build a reply, apply $fnSet to it, then return the raw response bytes
     * by round-tripping through buildEconetpacket().
     */
    private function getReplyBytes(callable $fnSet, int $iNet = 1, int $iStn = 5): string
    {
        $oReq   = $this->makeRequest($iNet, $iStn);
        $oReply = $oReq->buildReply();
        $fnSet($oReply);
        return (string) $oReply->buildEconetpacket()->getData();
    }

    /** Unpack a binary string to an array of unsigned bytes (1-indexed). */
    private function bytes(string $sData): array
    {
        return unpack('C*', $sData);
    }

    // -------------------------------------------------------------------------
    // Simple status responses
    // -------------------------------------------------------------------------

    public function testOkProducesZeroStatusByte(): void
    {
        $aBytes = $this->bytes($this->getReplyBytes(fn($r) => $r->ok()));
        $this->assertCount(1, $aBytes);
        $this->assertEquals(0x00, $aBytes[1]);
    }

    public function testErrorProducesDefaultFfStatus(): void
    {
        $aBytes = $this->bytes($this->getReplyBytes(fn($r) => $r->error()));
        $this->assertCount(1, $aBytes);
        $this->assertEquals(0xFF, $aBytes[1]);
    }

    public function testErrorAcceptsCustomStatus(): void
    {
        $aBytes = $this->bytes($this->getReplyBytes(fn($r) => $r->error(0xFE)));
        $this->assertCount(1, $aBytes);
        $this->assertEquals(0xFE, $aBytes[1]);
    }

    // -------------------------------------------------------------------------
    // Open / Create responses
    // -------------------------------------------------------------------------

    public function testOpenOkProducesZeroStatusAndHandle(): void
    {
        $aBytes = $this->bytes($this->getReplyBytes(fn($r) => $r->openOk(0x03)));
        $this->assertCount(2, $aBytes);
        $this->assertEquals(0x00, $aBytes[1]); // status = success
        $this->assertEquals(0x03, $aBytes[2]); // file handle
    }

    public function testOpenOkPreservesHandleValue(): void
    {
        $aBytes = $this->bytes($this->getReplyBytes(fn($r) => $r->openOk(0xAB)));
        $this->assertEquals(0xAB, $aBytes[2]);
    }

    public function testOpenErrorProducesFfStatusAndZeroHandle(): void
    {
        $aBytes = $this->bytes($this->getReplyBytes(fn($r) => $r->openError()));
        $this->assertCount(2, $aBytes);
        $this->assertEquals(0xFF, $aBytes[1]); // status = error
        $this->assertEquals(0x00, $aBytes[2]); // zero handle
    }

    // -------------------------------------------------------------------------
    // Read Block responses
    // -------------------------------------------------------------------------

    public function testReadOkNoEofHasZeroStatus(): void
    {
        $sData  = str_repeat("\xAB", 128);
        $aBytes = $this->bytes($this->getReplyBytes(fn($r) => $r->readOk($sData, false)));
        $this->assertEquals(0x00, $aBytes[1]); // status = valid data
        $this->assertEquals(128,  $aBytes[2]); // length
    }

    public function testReadOkAtEofHasOneStatus(): void
    {
        $sData  = str_repeat("\xCD", 64);
        $aBytes = $this->bytes($this->getReplyBytes(fn($r) => $r->readOk($sData, true)));
        $this->assertEquals(0x01, $aBytes[1]); // status = EOF
        $this->assertEquals(64,   $aBytes[2]); // length of partial sector
    }

    public function testReadOkDataFollowsLengthByte(): void
    {
        $sData  = pack('CCC', 0x11, 0x22, 0x33);
        $aBytes = $this->bytes($this->getReplyBytes(fn($r) => $r->readOk($sData)));
        $this->assertCount(5, $aBytes); // 1 status + 1 length + 3 data
        $this->assertEquals(0x11, $aBytes[3]);
        $this->assertEquals(0x22, $aBytes[4]);
        $this->assertEquals(0x33, $aBytes[5]);
    }

    public function testReadEofHasOneStatusAndZeroLength(): void
    {
        $aBytes = $this->bytes($this->getReplyBytes(fn($r) => $r->readEof()));
        $this->assertCount(2, $aBytes);
        $this->assertEquals(0x01, $aBytes[1]); // status = EOF
        $this->assertEquals(0x00, $aBytes[2]); // zero bytes
    }

    // -------------------------------------------------------------------------
    // Search responses
    // -------------------------------------------------------------------------

    public function testSearchFoundHasZeroStatus(): void
    {
        $aBytes = $this->bytes($this->getReplyBytes(fn($r) => $r->searchFound('MYPROG', 'COM', 4)));
        $this->assertEquals(0x00, $aBytes[1]);
    }

    public function testSearchFoundHasCorrect17ByteLength(): void
    {
        $sRaw = $this->getReplyBytes(fn($r) => $r->searchFound('MYPROG', 'COM', 4));
        // 1 status + 11 name + 1 record count + 4 allocation bitmask
        $this->assertEquals(17, strlen($sRaw));
    }

    public function testSearchFoundEncodesNamePaddedTo8Chars(): void
    {
        $sRaw  = $this->getReplyBytes(fn($r) => $r->searchFound('ED', 'COM', 1));
        // Bytes 2-9 (1-indexed) are the name field
        $sName = substr($sRaw, 1, 8);
        $this->assertEquals('ED      ', $sName);
    }

    public function testSearchFoundEncodesExtensionPaddedTo3Chars(): void
    {
        $sRaw = $this->getReplyBytes(fn($r) => $r->searchFound('MYPROG', 'AS', 1));
        // Bytes 10-12 (0-indexed 9-11) are the extension field
        $sExt = substr($sRaw, 9, 3);
        $this->assertEquals('AS ', $sExt);
    }

    public function testSearchFoundEncodesRecordCount(): void
    {
        $aBytes = $this->bytes($this->getReplyBytes(fn($r) => $r->searchFound('MYPROG', 'COM', 7)));
        $this->assertEquals(7, $aBytes[13]); // byte 13 (1-indexed) = record count
    }

    public function testSearchFoundTruncatesLongName(): void
    {
        $sRaw  = $this->getReplyBytes(fn($r) => $r->searchFound('TOOLONGNAME', 'TXT', 1));
        $sName = substr($sRaw, 1, 8);
        $this->assertEquals('TOOLONGN', $sName);
    }

    public function testSearchEndProducesFfStatus(): void
    {
        $aBytes = $this->bytes($this->getReplyBytes(fn($r) => $r->searchEnd()));
        $this->assertCount(1, $aBytes);
        $this->assertEquals(0xFF, $aBytes[1]);
    }

    // -------------------------------------------------------------------------
    // buildEconetpacket addressing
    // -------------------------------------------------------------------------

    public function testBuildEconetpacketAddressedToRequestSource(): void
    {
        $oReq   = $this->makeRequest(3, 42, 0x90);
        $oReply = $oReq->buildReply();
        $oReply->ok();
        $oPkt = $oReply->buildEconetpacket();

        $this->assertEquals(42,   $oPkt->getDestinationStation());
        $this->assertEquals(3,    $oPkt->getDestinationNetwork());
    }

    public function testBuildEconetpacketUsesRequestReplyPort(): void
    {
        $oReq   = $this->makeRequest(1, 5, 0x91);
        $oReply = $oReq->buildReply();
        $oReply->ok();
        $oPkt = $oReply->buildEconetpacket();

        $this->assertEquals(0x91, $oPkt->getPort());
    }

    public function testBuildEconetpacketDataMatchesReplyPayload(): void
    {
        $oReq   = $this->makeRequest();
        $oReply = $oReq->buildReply();
        $oReply->openOk(0x05);
        $oPkt   = $oReply->buildEconetpacket();
        $aBytes = $this->bytes((string) $oPkt->getData());

        $this->assertEquals(0x00, $aBytes[1]); // status
        $this->assertEquals(0x05, $aBytes[2]); // handle
    }
}
