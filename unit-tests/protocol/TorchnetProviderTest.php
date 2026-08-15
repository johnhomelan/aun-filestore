<?php

/*
 * @group unit-tests
 */

use PHPUnit\Framework\TestCase;
use Monolog\Logger;
use Monolog\Handler\NullHandler;
use HomeLan\FileStore\Messages\EconetPacket;
use HomeLan\FileStore\Services\Provider\Torchnet;
use HomeLan\FileStore\Vfs\FileDescriptor;
use HomeLan\FileStore\Vfs\CpmDirectoryEntry;

include_once('include/system.inc.php');

// ---------------------------------------------------------------------------
// Testable subclass — overrides all five protected CpmVfs wrapper methods so
// no real filesystem is touched.  Call arguments are captured in public
// properties; return values and exceptions are injected via public stubs.
//
// File-operation wrappers receive CP/M file paths (e.g. '\TorchDrives\E\MYPROG.COM').
// Directory wrappers receive CP/M directory paths (e.g. '\TorchDrives\E').
// ---------------------------------------------------------------------------
class TorchnetTestable extends Torchnet
{
    // --- stubs (must be set via mockFd() before dispatching a packet that opens a file/dir) ---
    public FileDescriptor $stubCpmFileFd;    // returned by cpmCreateFileHandle
    public FileDescriptor $stubCpmDirFd;     // returned by cpmCreateFsHandle (directory)
    public array       $stubCpmListing     = [];      // returned by cpmGetDirectoryListing
    public ?\Throwable $stubFileCreateEx   = null;
    public ?\Throwable $stubDeleteEx       = null;
    public ?\Throwable $stubMoveEx         = null;
    public ?\Throwable $stubDirCreateEx    = null;

    // --- captured calls (read after dispatch) ---
    public ?array $capFileCreate          = null; // ['path', 'mustExist', 'readOnly']
    public ?array $capDelete              = null; // ['path']
    public ?array $capMove                = null; // ['from', 'to']
    public ?array $capDirCreate           = null; // ['path', 'mustExist', 'readOnly']
    public bool   $cpmListingWasCalled    = false;

    protected function cpmCreateFileHandle(int $iNet, int $iStn, string $sCpmPath, bool $bMustExist, bool $bReadOnly): FileDescriptor
    {
        if ($this->stubFileCreateEx !== null) {
            throw $this->stubFileCreateEx;
        }
        $this->capFileCreate = ['path' => $sCpmPath, 'mustExist' => $bMustExist, 'readOnly' => $bReadOnly];
        return $this->stubCpmFileFd;
    }

    protected function cpmDeleteFile(int $iNet, int $iStn, string $sCpmPath): void
    {
        if ($this->stubDeleteEx !== null) {
            throw $this->stubDeleteEx;
        }
        $this->capDelete = ['path' => $sCpmPath];
    }

    protected function cpmMoveFile(int $iNet, int $iStn, string $sFrom, string $sTo): void
    {
        if ($this->stubMoveEx !== null) {
            throw $this->stubMoveEx;
        }
        $this->capMove = ['from' => $sFrom, 'to' => $sTo];
    }

    protected function cpmCreateFsHandle(int $iNet, int $iStn, string $sCpmPath, bool $bMustExist, bool $bReadOnly): FileDescriptor
    {
        if ($this->stubDirCreateEx !== null) {
            throw $this->stubDirCreateEx;
        }
        $this->capDirCreate = ['path' => $sCpmPath, 'mustExist' => $bMustExist, 'readOnly' => $bReadOnly];
        return $this->stubCpmDirFd;
    }

    protected function cpmGetDirectoryListing(FileDescriptor $oFd): array
    {
        $this->cpmListingWasCalled = true;
        return $this->stubCpmListing;
    }
}

// ---------------------------------------------------------------------------
// Test suite
// ---------------------------------------------------------------------------
class TorchnetProviderTest extends TestCase
{
    private TorchnetTestable $oProvider;

    protected function setUp(): void
    {
        $oLogger = new Logger('torchnet-provider-test');
        $oLogger->pushHandler(new NullHandler());
        $this->oProvider = new TorchnetTestable($oLogger);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function pkt(string $sPayload, int $iNet = 1, int $iStn = 5, int $iPort = 0x90): EconetPacket
    {
        $o = new EconetPacket();
        $o->setPort($iPort);
        $o->setFlags(0x00);
        $o->setSourceNetwork($iNet);
        $o->setSourceStation($iStn);
        $o->setData($sPayload);
        return $o;
    }

    /** Dispatch one packet and return the first reply's data as a byte array. */
    private function dispatch(EconetPacket $oPkt): array
    {
        $this->oProvider->unicastPacketIn($oPkt);
        $aReplies = $this->oProvider->getReplies();
        $this->assertCount(1, $aReplies, 'Expected exactly one reply packet');
        return (array) unpack('C*', (string) $aReplies[0]->getData());
    }

    private function dispatchExpectNoReply(EconetPacket $oPkt): void
    {
        $this->oProvider->unicastPacketIn($oPkt);
        $this->assertCount(0, $this->oProvider->getReplies(), 'Expected no reply packet');
    }

    private function mockFd(): FileDescriptor
    {
        return $this->createMock(FileDescriptor::class);
    }

    private function mockEntry(string $sCpmName, int $iSize = 512, bool $bDir = false): CpmDirectoryEntry
    {
        $oEntry = $this->createMock(CpmDirectoryEntry::class);
        $oEntry->method('getCpmName')->willReturn($sCpmName);
        $oEntry->method('getSize')->willReturn($iSize);
        $oEntry->method('isDir')->willReturn($bDir);
        return $oEntry;
    }

    // -------------------------------------------------------------------------
    // getServicePorts
    // -------------------------------------------------------------------------

    public function testServicePortsInclude0x90And0x91(): void
    {
        $this->assertContains(0x90, $this->oProvider->getServicePorts());
        $this->assertContains(0x91, $this->oProvider->getServicePorts());
    }

    // -------------------------------------------------------------------------
    // OPEN (0x01)
    // -------------------------------------------------------------------------

    public function testOpenPassesCpmFilePathToWrapper(): void
    {
        $this->oProvider->stubCpmFileFd = $this->mockFd();
        $this->dispatch($this->pkt(pack('CC', 0x01, ord('E')) . pack('C', 0x01) . 'MYPROG  COM'));
        // Backslash is the directory separator; dot is the extension separator
        $this->assertEquals('\TorchDrives\E\MYPROG.COM', $this->oProvider->capFileCreate['path']);
    }

    public function testOpenReadOnlyModeSetsBReadOnly(): void
    {
        $this->oProvider->stubCpmFileFd = $this->mockFd();
        $this->dispatch($this->pkt(pack('CC', 0x01, ord('E')) . pack('C', 0x01) . 'MYPROG  COM'));
        $this->assertTrue($this->oProvider->capFileCreate['readOnly']);
    }

    public function testOpenWriteModeClearsBReadOnly(): void
    {
        $this->oProvider->stubCpmFileFd = $this->mockFd();
        $this->dispatch($this->pkt(pack('CC', 0x01, ord('E')) . pack('C', 0x02) . 'MYPROG  COM'));
        $this->assertFalse($this->oProvider->capFileCreate['readOnly']);
    }

    public function testOpenSetsMustExistTrue(): void
    {
        $this->oProvider->stubCpmFileFd = $this->mockFd();
        $this->dispatch($this->pkt(pack('CC', 0x01, ord('E')) . pack('C', 0x01) . 'MYPROG  COM'));
        $this->assertTrue($this->oProvider->capFileCreate['mustExist']);
    }

    public function testOpenSuccessReplyHasZeroStatusAndHandle(): void
    {
        $this->oProvider->stubCpmFileFd = $this->mockFd();
        $aBytes = $this->dispatch($this->pkt(pack('CC', 0x01, ord('E')) . pack('C', 0x01) . 'MYPROG  COM'));
        $this->assertEquals(0x00, $aBytes[1]);
        $this->assertGreaterThan(0, $aBytes[2]);
    }

    public function testOpenFailureWhenWrapperThrows(): void
    {
        $this->oProvider->stubFileCreateEx = new \RuntimeException('disk error');
        $aBytes = $this->dispatch($this->pkt(pack('CC', 0x01, ord('E')) . pack('C', 0x01) . 'MYPROG  COM'));
        $this->assertEquals(0xFF, $aBytes[1]);
    }

    public function testOpenFileWithNoExtensionOmitsDot(): void
    {
        $this->oProvider->stubCpmFileFd = $this->mockFd();
        $this->dispatch($this->pkt(pack('CC', 0x01, ord('E')) . pack('C', 0x01) . 'AUTOEXEC   '));
        $this->assertEquals('\TorchDrives\E\AUTOEXEC', $this->oProvider->capFileCreate['path']);
    }

    // -------------------------------------------------------------------------
    // CREATE (0x0D)
    // -------------------------------------------------------------------------

    public function testCreateSetsMustExistFalse(): void
    {
        $this->oProvider->stubCpmFileFd = $this->mockFd();
        $this->dispatch($this->pkt(pack('CC', 0x0D, ord('E')) . pack('C', 0x02) . 'NEWFILE COM'));
        $this->assertFalse($this->oProvider->capFileCreate['mustExist']);
    }

    public function testCreatePassesCpmFilePath(): void
    {
        $this->oProvider->stubCpmFileFd = $this->mockFd();
        $this->dispatch($this->pkt(pack('CC', 0x0D, ord('E')) . pack('C', 0x02) . 'NEWFILE COM'));
        $this->assertEquals('\TorchDrives\E\NEWFILE.COM', $this->oProvider->capFileCreate['path']);
    }

    // -------------------------------------------------------------------------
    // CLOSE (0x02)
    // -------------------------------------------------------------------------

    public function testCloseCallsFdCloseAndRepliesOk(): void
    {
        $oFd = $this->mockFd();
        $oFd->expects($this->once())->method('close');
        $this->oProvider->stubCpmFileFd = $oFd;

        $aOpen   = $this->dispatch($this->pkt(pack('CC', 0x01, ord('E')) . pack('C', 0x01) . 'MYPROG  COM'));
        $iHandle = $aOpen[2];

        $aClose = $this->dispatch($this->pkt(pack('CC', 0x02, $iHandle)));
        $this->assertEquals(0x00, $aClose[1]);
    }

    public function testCloseWithUnknownHandleRepliesError(): void
    {
        $aBytes = $this->dispatch($this->pkt(pack('CC', 0x02, 0x7F)));
        $this->assertEquals(0xFF, $aBytes[1]);
    }

    // -------------------------------------------------------------------------
    // READ BLOCK (0x03)
    // -------------------------------------------------------------------------

    private function openAndGetHandle(string $sFilename = 'MYPROG  COM'): array
    {
        $oFd = $this->mockFd();
        $this->oProvider->stubCpmFileFd = $oFd;
        $aOpen = $this->dispatch($this->pkt(pack('CC', 0x01, ord('E')) . pack('C', 0x01) . $sFilename));
        return [$aOpen[2], $oFd];
    }

    public function testReadBlockSeeksToCorrectByteOffset(): void
    {
        [$iHandle, $oFd] = $this->openAndGetHandle();
        // Record offset 3 → byte position 3 × 128 = 384
        $oFd->expects($this->once())->method('setPos')->with(384);
        $oFd->method('read')->willReturn(str_repeat("\x00", 128));
        $oFd->method('isEof')->willReturn(false);

        $this->dispatch($this->pkt(pack('C', 0x03) . pack('C', $iHandle) . pack('v', 3) . pack('C', 1)));
    }

    public function testReadBlockReadsCorrectByteCount(): void
    {
        [$iHandle, $oFd] = $this->openAndGetHandle();
        // maxSectors = 2 → read 2 × 128 = 256 bytes
        $oFd->expects($this->once())->method('read')->with(256)->willReturn(str_repeat("\xAB", 256));
        $oFd->method('setPos');
        $oFd->method('isEof')->willReturn(false);

        $this->dispatch($this->pkt(pack('C', 0x03) . pack('C', $iHandle) . pack('v', 0) . pack('C', 2)));
    }

    public function testReadBlockDataAppearsInReply(): void
    {
        [$iHandle, $oFd] = $this->openAndGetHandle();
        $sExpected = str_repeat("\xCD", 128);
        $oFd->method('setPos');
        $oFd->method('read')->willReturn($sExpected);
        $oFd->method('isEof')->willReturn(false);

        $aBytes = $this->dispatch($this->pkt(pack('C', 0x03) . pack('C', $iHandle) . pack('v', 0) . pack('C', 1)));

        $this->assertEquals(0x00, $aBytes[1]);   // status = valid data
        $this->assertEquals(128,  $aBytes[2]);   // length
        $this->assertEquals(0xCD, $aBytes[3]);   // first data byte
        $this->assertEquals(0xCD, $aBytes[130]); // last data byte
    }

    public function testReadBlockAtEofSetsStatusOne(): void
    {
        [$iHandle, $oFd] = $this->openAndGetHandle();
        $oFd->method('setPos');
        $oFd->method('read')->willReturn(str_repeat("\x00", 64));
        $oFd->method('isEof')->willReturn(true);

        $aBytes = $this->dispatch($this->pkt(pack('C', 0x03) . pack('C', $iHandle) . pack('v', 0) . pack('C', 1)));
        $this->assertEquals(0x01, $aBytes[1]);
    }

    public function testReadBlockWhenReadReturnsNullProducesEof(): void
    {
        [$iHandle, $oFd] = $this->openAndGetHandle();
        $oFd->method('setPos');
        $oFd->method('read')->willReturn(null);
        $oFd->method('isEof')->willReturn(true);

        $aBytes = $this->dispatch($this->pkt(pack('C', 0x03) . pack('C', $iHandle) . pack('v', 0) . pack('C', 1)));
        $this->assertEquals(0x01, $aBytes[1]);
    }

    public function testReadBlockWithUnknownHandleProducesEof(): void
    {
        $aBytes = $this->dispatch($this->pkt(pack('CCCC', 0x03, 0x7F, 0x00, 0x01)));
        $this->assertEquals(0x01, $aBytes[1]);
    }

    // -------------------------------------------------------------------------
    // WRITE BLOCK (0x04)
    // -------------------------------------------------------------------------

    public function testWriteBlockSeeksToCorrectByteOffset(): void
    {
        [$iHandle, $oFd] = $this->openAndGetHandle();
        // Record offset 5 → byte position 5 × 128 = 640
        $oFd->expects($this->once())->method('setPos')->with(640);
        $oFd->method('write');

        $sData = str_repeat("\xFF", 128);
        $this->dispatch($this->pkt(
            pack('C', 0x04) . pack('C', $iHandle) . pack('v', 5) . pack('C', 128) . $sData
        ));
    }

    public function testWriteBlockPassesDataToFd(): void
    {
        [$iHandle, $oFd] = $this->openAndGetHandle();
        $sData = str_repeat("\xBE", 128);
        $oFd->method('setPos');
        $oFd->expects($this->once())->method('write')->with($sData);

        $this->dispatch($this->pkt(
            pack('C', 0x04) . pack('C', $iHandle) . pack('v', 0) . pack('C', 128) . $sData
        ));
    }

    public function testWriteBlockSuccessRepliesOk(): void
    {
        [$iHandle, $oFd] = $this->openAndGetHandle();
        $oFd->method('setPos');
        $oFd->method('write');

        $aBytes = $this->dispatch($this->pkt(
            pack('C', 0x04) . pack('C', $iHandle) . pack('v', 0) . pack('C', 128) . str_repeat("\x00", 128)
        ));
        $this->assertEquals(0x00, $aBytes[1]);
    }

    public function testWriteBlockWithUnknownHandleRepliesError(): void
    {
        $aBytes = $this->dispatch($this->pkt(
            pack('C', 0x04) . pack('C', 0x7F) . pack('v', 0) . pack('C', 128) . str_repeat("\x00", 128)
        ));
        $this->assertEquals(0xFF, $aBytes[1]);
    }

    // -------------------------------------------------------------------------
    // DELETE (0x05)
    // -------------------------------------------------------------------------

    public function testDeletePassesCpmFilePathToWrapper(): void
    {
        // DELETE: cmd=0x05, drive='E', userGroup=0x00, filename='MYPROG  COM'
        $this->dispatch($this->pkt(pack('CCC', 0x05, ord('E'), 0x00) . 'MYPROG  COM'));
        $this->assertEquals('\TorchDrives\E\MYPROG.COM', $this->oProvider->capDelete['path']);
    }

    public function testDeleteSuccessRepliesOk(): void
    {
        $aBytes = $this->dispatch($this->pkt(pack('CCC', 0x05, ord('E'), 0x00) . 'MYPROG  COM'));
        $this->assertEquals(0x00, $aBytes[1]);
    }

    public function testDeleteFailureWhenWrapperThrowsRepliesError(): void
    {
        $this->oProvider->stubDeleteEx = new \RuntimeException('file locked');
        $aBytes = $this->dispatch($this->pkt(pack('CCC', 0x05, ord('E'), 0x00) . 'MYPROG  COM'));
        $this->assertEquals(0xFF, $aBytes[1]);
    }

    public function testDeleteFileWithNoExtensionOmitsDot(): void
    {
        $this->dispatch($this->pkt(pack('CCC', 0x05, ord('E'), 0x00) . 'MAKEFILE   '));
        $this->assertEquals('\TorchDrives\E\MAKEFILE', $this->oProvider->capDelete['path']);
    }

    // -------------------------------------------------------------------------
    // RENAME (0x0E)
    // -------------------------------------------------------------------------

    public function testRenamePassesCpmFilePathsToWrapper(): void
    {
        $this->dispatch($this->pkt(
            pack('CC', 0x0E, ord('E')) . 'MYPROG  COM' . 'NEWPROG COM'
        ));
        $this->assertEquals('\TorchDrives\E\MYPROG.COM',  $this->oProvider->capMove['from']);
        $this->assertEquals('\TorchDrives\E\NEWPROG.COM', $this->oProvider->capMove['to']);
    }

    public function testRenameSuccessRepliesOk(): void
    {
        $aBytes = $this->dispatch($this->pkt(
            pack('CC', 0x0E, ord('E')) . 'MYPROG  COM' . 'NEWPROG COM'
        ));
        $this->assertEquals(0x00, $aBytes[1]);
    }

    public function testRenameFailureWhenWrapperThrowsRepliesError(): void
    {
        $this->oProvider->stubMoveEx = new \RuntimeException('write protected');
        $aBytes = $this->dispatch($this->pkt(
            pack('CC', 0x0E, ord('E')) . 'MYPROG  COM' . 'NEWPROG COM'
        ));
        $this->assertEquals(0xFF, $aBytes[1]);
    }

    // -------------------------------------------------------------------------
    // SEARCH FIRST (0x06)
    // -------------------------------------------------------------------------

    private function searchPkt(int $iCmd, string $sDriveId, string $sPattern): EconetPacket
    {
        $sPattern = str_pad($sPattern, 11, ' ');
        return $this->pkt(pack('CCC', $iCmd, ord($sDriveId), 0x00) . $sPattern);
    }

    public function testSearchFirstOpensCorrectCpmDirectory(): void
    {
        $this->oProvider->stubCpmDirFd  = $this->mockFd();
        $this->oProvider->stubCpmListing = [];

        $this->dispatch($this->searchPkt(0x06, 'E', '????????COM'));

        $this->assertEquals('\TorchDrives\E', $this->oProvider->capDirCreate['path']);
    }

    public function testSearchFirstCallsGetDirectoryListing(): void
    {
        $this->oProvider->stubCpmDirFd  = $this->mockFd();
        $this->oProvider->stubCpmListing = [];

        $this->dispatch($this->searchPkt(0x06, 'E', '????????COM'));

        $this->assertTrue($this->oProvider->cpmListingWasCalled);
    }

    public function testSearchFirstMatchingEntryReturnsZeroStatus(): void
    {
        $this->oProvider->stubCpmDirFd  = $this->mockFd();
        $this->oProvider->stubCpmListing = [$this->mockEntry('MYPROG.COM', 512)];

        $aBytes = $this->dispatch($this->searchPkt(0x06, 'E', '????????COM'));
        $this->assertEquals(0x00, $aBytes[1]);
    }

    public function testSearchFirstEncodesFilenameIn11ByteField(): void
    {
        $this->oProvider->stubCpmDirFd  = $this->mockFd();
        $this->oProvider->stubCpmListing = [$this->mockEntry('MYPROG.COM', 512)];

        $aBytes = $this->dispatch($this->searchPkt(0x06, 'E', '????????COM'));

        // Bytes 2–9 = name padded to 8 chars, bytes 10–12 = extension
        $sName = '';
        for ($i = 2; $i <= 9; $i++) {
            $sName .= chr($aBytes[$i]);
        }
        $sExt = '';
        for ($i = 10; $i <= 12; $i++) {
            $sExt .= chr($aBytes[$i]);
        }
        $this->assertEquals('MYPROG  ', $sName);
        $this->assertEquals('COM',      $sExt);
    }

    public function testSearchFirstEncodesRecordCountFromFileSize(): void
    {
        $this->oProvider->stubCpmDirFd  = $this->mockFd();
        // 512 bytes = 4 records of 128 bytes
        $this->oProvider->stubCpmListing = [$this->mockEntry('MYPROG.COM', 512)];

        $aBytes = $this->dispatch($this->searchPkt(0x06, 'E', '????????COM'));
        $this->assertEquals(4, $aBytes[13]);
    }

    public function testSearchFirstNoMatchReturnsSearchEnd(): void
    {
        $this->oProvider->stubCpmDirFd  = $this->mockFd();
        $this->oProvider->stubCpmListing = [$this->mockEntry('MYPROG.BAS', 256)];

        $aBytes = $this->dispatch($this->searchPkt(0x06, 'E', '????????COM'));
        $this->assertEquals(0xFF, $aBytes[1]);
    }

    public function testSearchFirstSkipsDirectoryEntries(): void
    {
        $this->oProvider->stubCpmDirFd  = $this->mockFd();
        $this->oProvider->stubCpmListing = [
            $this->mockEntry('SUBDIR.COM', 0, true),
            $this->mockEntry('MYPROG.COM', 256),
        ];

        $aBytes = $this->dispatch($this->searchPkt(0x06, 'E', '????????COM'));
        $this->assertEquals(0x00, $aBytes[1]);
        $sFirst5 = chr($aBytes[2]) . chr($aBytes[3]) . chr($aBytes[4]) . chr($aBytes[5]) . chr($aBytes[6]);
        $this->assertStringNotContainsString('SUBDI', $sFirst5);
    }

    public function testSearchFirstFiltersToMatchingPatternOnly(): void
    {
        $this->oProvider->stubCpmDirFd  = $this->mockFd();
        $this->oProvider->stubCpmListing = [
            $this->mockEntry('MYPROG.BAS', 256),
            $this->mockEntry('EDITOR.COM', 512),
        ];

        $aBytes = $this->dispatch($this->searchPkt(0x06, 'E', '????????COM'));
        $this->assertEquals(0x00, $aBytes[1]);
        $sFirst5 = chr($aBytes[2]) . chr($aBytes[3]) . chr($aBytes[4]) . chr($aBytes[5]) . chr($aBytes[6]);
        $this->assertEquals('EDITO', $sFirst5);
    }

    public function testSearchFirstReturnsSearchEndWhenDirThrows(): void
    {
        $this->oProvider->stubDirCreateEx = new \RuntimeException('drive not found');
        $aBytes = $this->dispatch($this->searchPkt(0x06, 'E', '????????COM'));
        $this->assertEquals(0xFF, $aBytes[1]);
    }

    // -------------------------------------------------------------------------
    // SEARCH NEXT (0x07)
    // -------------------------------------------------------------------------

    public function testSearchNextAdvancesCursorToSecondEntry(): void
    {
        $this->oProvider->stubCpmDirFd  = $this->mockFd();
        $this->oProvider->stubCpmListing = [
            $this->mockEntry('FIRST.COM',  128),
            $this->mockEntry('SECOND.COM', 256),
        ];

        $this->dispatch($this->searchPkt(0x06, 'E', '????????COM'));

        $aBytes = $this->dispatch($this->searchPkt(0x07, 'E', '????????COM'));
        $this->assertEquals(0x00, $aBytes[1]);
        $sName = chr($aBytes[2]) . chr($aBytes[3]) . chr($aBytes[4]) . chr($aBytes[5]) . chr($aBytes[6]) . chr($aBytes[7]);
        $this->assertEquals('SECOND', $sName);
    }

    public function testSearchNextBeyondLastEntryReturnsSearchEnd(): void
    {
        $this->oProvider->stubCpmDirFd  = $this->mockFd();
        $this->oProvider->stubCpmListing = [$this->mockEntry('ONLY.COM', 128)];

        $this->dispatch($this->searchPkt(0x06, 'E', '????????COM'));
        $aBytes = $this->dispatch($this->searchPkt(0x07, 'E', '????????COM'));
        $this->assertEquals(0xFF, $aBytes[1]);
    }

    public function testSearchNextDoesNotReopenDirectory(): void
    {
        $this->oProvider->stubCpmDirFd  = $this->mockFd();
        $this->oProvider->stubCpmListing = [
            $this->mockEntry('A.COM', 128),
            $this->mockEntry('B.COM', 256),
        ];

        $this->dispatch($this->searchPkt(0x06, 'E', '????????COM'));
        $this->oProvider->cpmListingWasCalled = false;

        $this->dispatch($this->searchPkt(0x07, 'E', '????????COM'));

        $this->assertFalse($this->oProvider->cpmListingWasCalled,
            'Search Next should reuse cached results, not re-open the directory');
    }

    public function testSearchFirstRestartsSearch(): void
    {
        $this->oProvider->stubCpmDirFd  = $this->mockFd();
        $this->oProvider->stubCpmListing = [$this->mockEntry('MYPROG.COM', 128)];

        $this->dispatch($this->searchPkt(0x06, 'E', '????????COM'));
        $aBytes = $this->dispatch($this->searchPkt(0x06, 'E', '????????COM'));
        $this->assertEquals(0x00, $aBytes[1]);
    }

    // -------------------------------------------------------------------------
    // Notification-only commands (no reply)
    // -------------------------------------------------------------------------

    public function testConsoleNotifyProducesNoReply(): void
    {
        $this->dispatchExpectNoReply($this->pkt(pack('CC', 0x08, 5) . 'Hello'));
    }

    public function testPrintRedirectProducesNoReply(): void
    {
        $this->dispatchExpectNoReply($this->pkt(pack('CCC', 0x09, 0x00, 0x01) . 'print data'));
    }

    // -------------------------------------------------------------------------
    // Unknown / unimplemented commands
    // -------------------------------------------------------------------------

    public function testUnknownCommandRepliesWithError(): void
    {
        $aBytes = $this->dispatch($this->pkt(pack('C', 0x42)));
        $this->assertEquals(0xFF, $aBytes[1]);
    }

    public function testMemPeekRepliesWithError(): void
    {
        $aBytes = $this->dispatch($this->pkt(pack('CCCC', 0x10, 0x00, 0x10, 0x04)));
        $this->assertEquals(0xFF, $aBytes[1]);
    }

    // -------------------------------------------------------------------------
    // Reply addressing
    // -------------------------------------------------------------------------

    public function testReplyIsAddressedBackToRequestingStation(): void
    {
        $this->oProvider->stubCpmFileFd = $this->mockFd();
        $this->oProvider->unicastPacketIn(
            $this->pkt(pack('CC', 0x01, ord('E')) . pack('C', 0x01) . 'MYPROG  COM', 3, 42)
        );
        $aReplies = $this->oProvider->getReplies();
        $this->assertCount(1, $aReplies);
        $this->assertEquals(42, $aReplies[0]->getDestinationStation());
        $this->assertEquals(3,  $aReplies[0]->getDestinationNetwork());
    }

    public function testReplyPortMatchesInboundPort(): void
    {
        $this->oProvider->stubCpmDirFd  = $this->mockFd();
        $this->oProvider->stubCpmListing = [];
        $this->oProvider->unicastPacketIn(
            $this->pkt(pack('CCC', 0x06, ord('E'), 0x00) . str_pad('', 11, ' '), 1, 5, 0x91)
        );
        $aReplies = $this->oProvider->getReplies();
        $this->assertCount(1, $aReplies);
        $this->assertEquals(0x91, $aReplies[0]->getPort());
    }

    // -------------------------------------------------------------------------
    // getReplies drains the buffer
    // -------------------------------------------------------------------------

    public function testGetRepliesDrainsBufferOnEachCall(): void
    {
        $this->oProvider->stubCpmFileFd = $this->mockFd();
        $oPkt = $this->pkt(pack('CC', 0x01, ord('E')) . pack('C', 0x01) . 'MYPROG  COM');
        $this->oProvider->unicastPacketIn($oPkt);

        $this->assertCount(1, $this->oProvider->getReplies());
        $this->assertCount(0, $this->oProvider->getReplies());
    }
}
