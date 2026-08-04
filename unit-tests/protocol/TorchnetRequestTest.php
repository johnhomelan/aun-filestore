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

class TorchnetRequestTest extends TestCase
{
    private Logger $oLogger;

    protected function setUp(): void
    {
        $this->oLogger = new Logger('torchnet-test');
        $this->oLogger->pushHandler(new NullHandler());
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * Build an EconetPacket arriving on port $iPort from network $iNet,
     * station $iStn, carrying the raw TorchNet payload $sPayload.
     */
    private function buildPacket(int $iPort, int $iNet, int $iStn, string $sPayload): EconetPacket
    {
        $oPacket = new EconetPacket();
        $oPacket->setPort($iPort);
        $oPacket->setFlags(0x00);
        $oPacket->setSourceNetwork($iNet);
        $oPacket->setSourceStation($iStn);
        $oPacket->setData($sPayload);
        return $oPacket;
    }

    /**
     * Build a TorchnetRequest from a raw TorchNet payload byte string.
     * Defaults to port 0x90, network 1, station 5.
     */
    private function makeRequest(string $sPayload, int $iPort = 0x90, int $iNet = 1, int $iStn = 5): TorchnetRequest
    {
        return new TorchnetRequest($this->buildPacket($iPort, $iNet, $iStn, $sPayload), $this->oLogger);
    }

    // -------------------------------------------------------------------------
    // Source addressing
    // -------------------------------------------------------------------------

    public function testGetSourceStation(): void
    {
        $oReq = $this->makeRequest(pack('C', 0x02) . pack('C', 0x01), 0x90, 2, 17);
        $this->assertEquals(17, $oReq->getSourceStation());
    }

    public function testGetSourceNetwork(): void
    {
        $oReq = $this->makeRequest(pack('C', 0x02) . pack('C', 0x01), 0x90, 3, 1);
        $this->assertEquals(3, $oReq->getSourceNetwork());
    }

    // -------------------------------------------------------------------------
    // Reply port
    // -------------------------------------------------------------------------

    public function testGetReplyPortFileOps(): void
    {
        $oReq = $this->makeRequest(pack('C', 0x01), 0x90);
        $this->assertEquals(0x90, $oReq->getReplyPort());
    }

    public function testGetReplyPortExtended(): void
    {
        $oReq = $this->makeRequest(pack('C', 0x08) . pack('C', 5) . 'Hello', 0x91);
        $this->assertEquals(0x91, $oReq->getReplyPort());
    }

    // -------------------------------------------------------------------------
    // Command decoding
    // -------------------------------------------------------------------------

    /**
     * @dataProvider commandCodeProvider
     */
    public function testGetCommandKnownCodes(int $iCode, string $sExpected): void
    {
        $oReq = $this->makeRequest(pack('C', $iCode));
        $this->assertEquals($sExpected, $oReq->getCommand());
    }

    public function commandCodeProvider(): array
    {
        return [
            'OPEN'           => [0x01, 'TORCH_OPEN'],
            'CLOSE'          => [0x02, 'TORCH_CLOSE'],
            'READ_BLOCK'     => [0x03, 'TORCH_READ_BLOCK'],
            'WRITE_BLOCK'    => [0x04, 'TORCH_WRITE_BLOCK'],
            'DELETE'         => [0x05, 'TORCH_DELETE'],
            'SEARCH_FIRST'   => [0x06, 'TORCH_SEARCH_FIRST'],
            'SEARCH_NEXT'    => [0x07, 'TORCH_SEARCH_NEXT'],
            'CONSOLE_NOTIFY' => [0x08, 'TORCH_CONSOLE_NOTIFY'],
            'PRINT_REDIRECT' => [0x09, 'TORCH_PRINT_REDIRECT'],
            'CREATE'         => [0x0D, 'TORCH_CREATE'],
            'RENAME'         => [0x0E, 'TORCH_RENAME'],
            'MEM_PEEK'       => [0x10, 'TORCH_MEM_PEEK'],
            'MEM_POKE'       => [0x11, 'TORCH_MEM_POKE'],
            'CONTROL_ACTION' => [0x1A, 'TORCH_CONTROL_ACTION'],
        ];
    }

    public function testGetCommandUnknownCode(): void
    {
        $oReq = $this->makeRequest(pack('C', 0x42));
        $this->assertEquals('TORCH_UNKNOWN_0x42', $oReq->getCommand());
    }

    public function testGetRawCommand(): void
    {
        $oReq = $this->makeRequest(pack('C', 0x03));
        $this->assertEquals(0x03, $oReq->getRawCommand());
    }

    // -------------------------------------------------------------------------
    // getByte / get16bitIntLittleEndian on the per-command payload
    // -------------------------------------------------------------------------

    public function testGetByteReadsPerCommandPayload(): void
    {
        // CLOSE: [cmd=0x02, handle=0x07]
        $oReq = $this->makeRequest(pack('CC', 0x02, 0x07));
        $this->assertEquals(0x07, $oReq->getByte(1));
    }

    public function testGet16bitLittleEndianFromPayload(): void
    {
        // READ BLOCK: [cmd=0x03, handle=0x01, recordLo=0x80, recordHi=0x00, maxSectors=0x01]
        $oReq = $this->makeRequest(pack('CCCCC', 0x03, 0x01, 0x80, 0x00, 0x01));
        // get16bitIntLittleEndian(2) → bytes 2-3 of sData (0x80, 0x00) → 0x0080 = 128
        $this->assertEquals(128, $oReq->get16bitIntLittleEndian(2));
    }

    // -------------------------------------------------------------------------
    // parseCpmFilename
    // -------------------------------------------------------------------------

    public function testParseCpmFilenameWithExtension(): void
    {
        // OPEN: [cmd=0x01, drive='E', mode=0x01, 'MYPROG  COM']
        $sPayload = pack('C', 0x01)           // cmd
                  . pack('C', ord('E'))        // drive
                  . pack('C', 0x01)            // access mode
                  . 'MYPROG  COM';             // 11-byte 8+3 name (no dot)
        $oReq = $this->makeRequest($sPayload);
        $aName = $oReq->parseCpmFilename(3);
        $this->assertEquals('MYPROG', $aName['name']);
        $this->assertEquals('COM',    $aName['ext']);
    }

    public function testParseCpmFilenameMaxLengthName(): void
    {
        // 8-char name, 3-char extension: 'LONGFILEEXE'
        $sPayload = pack('C', 0x01) . pack('C', ord('E')) . pack('C', 0x01) . 'LONGFILEEXE';
        $oReq = $this->makeRequest($sPayload);
        $aName = $oReq->parseCpmFilename(3);
        $this->assertEquals('LONGFILE', $aName['name']);
        $this->assertEquals('EXE',      $aName['ext']);
    }

    public function testParseCpmFilenameEmptyExtension(): void
    {
        // Name with three trailing spaces for the extension field
        $sPayload = pack('C', 0x01) . pack('C', ord('E')) . pack('C', 0x01) . 'AUTOEXEC   ';
        $oReq = $this->makeRequest($sPayload);
        $aName = $oReq->parseCpmFilename(3);
        $this->assertEquals('AUTOEXEC', $aName['name']);
        $this->assertEquals('',         $aName['ext']);
    }

    public function testParseCpmFilenameAtDifferentOffset(): void
    {
        // RENAME: [cmd=0x0E, drive='E', oldName(11), newName(11)]
        $sPayload = pack('C', 0x0E)
                  . pack('C', ord('E'))
                  . 'OLD     BAS'   // old name at offset 2 (positions 2-12)
                  . 'NEW     BAS';  // new name at offset 13 (positions 13-23)
        $oReq = $this->makeRequest($sPayload);

        $aOld = $oReq->parseCpmFilename(2);
        $this->assertEquals('OLD', $aOld['name']);
        $this->assertEquals('BAS', $aOld['ext']);

        $aNew = $oReq->parseCpmFilename(13);
        $this->assertEquals('NEW', $aNew['name']);
        $this->assertEquals('BAS', $aNew['ext']);
    }

    public function testParseCpmFilenameTrimsPaddingSpaces(): void
    {
        // Name shorter than 8 chars, padded with spaces
        $sPayload = pack('C', 0x01) . pack('C', ord('E')) . pack('C', 0x01) . 'ED      COM';
        $oReq = $this->makeRequest($sPayload);
        $aName = $oReq->parseCpmFilename(3);
        $this->assertEquals('ED',  $aName['name']);
        $this->assertEquals('COM', $aName['ext']);
    }

    // -------------------------------------------------------------------------
    // buildReply
    // -------------------------------------------------------------------------

    public function testBuildReplyReturnsTorchnetReply(): void
    {
        $oReq = $this->makeRequest(pack('C', 0x02) . pack('C', 0x01));
        $this->assertInstanceOf(TorchnetReply::class, $oReq->buildReply());
    }
}
