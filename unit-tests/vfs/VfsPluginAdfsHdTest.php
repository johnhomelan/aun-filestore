<?php

/*
 * @group unit-tests
 *
 * Unit tests for the AdfsHD VFS plugin.
 *
 * The plugin provides read-only access to ADFS hard disk image files (.dat)
 * stored on the local filesystem. .dat images appear as virtual directories in
 * the econet namespace.
 *
 * AdfsReaderHD is mocked out via AdfsHD::setImageReader() rather than reading a
 * real binary image, so the tests exercise the full catalogue-walking behaviour
 * of the plugin — including the path-resolution edge cases around an existing
 * image with a requested sub-path that does not exist inside it — without
 * needing to synthesise a valid on-disk ADFS image format.
 */

if (!defined('CONFIG_security_mode')) {
    define('CONFIG_security_mode', 'singleuser');
}
if (!defined('CONFIG_vfs_plugin_localadfshd_root')) {
    define('CONFIG_vfs_plugin_localadfshd_root', '/tmp/adfshd-test-default-' . uniqid());
}

include_once(__DIR__ . '/../../src/include/system.inc.php');

use PHPUnit\Framework\TestCase;
use Monolog\Logger;
use Monolog\Handler\NullHandler;
use HomeLan\FileStore\Authentication\User;
use HomeLan\FileStore\Vfs\Plugin\AdfsHD;
use HomeLan\FileStore\Vfs\FilePath;
use HomeLan\FileStore\Vfs\Exception as VfsException;
use HomeLan\Retro\Acorn\Disk\AdfsReaderHD;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;

class VfsPluginAdfsHdTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    protected User $oUser;
    protected string $sRoot;

    // -------------------------------------------------------------------------
    // Setup / Teardown
    // -------------------------------------------------------------------------

    protected function setUp(): void
    {
        $this->sRoot = sys_get_temp_dir() . '/adfshd_test_' . uniqid() . '/';
        mkdir($this->sRoot, 0755, true);

        config::overrideValue('vfs_plugin_localadfshd_root', rtrim($this->sRoot, '/'));

        $oLogger = new Logger('adfshd-test');
        $oLogger->pushHandler(new NullHandler());
        AdfsHD::reset();
        AdfsHD::init($oLogger, false);

        $this->oUser = new User();
        $this->oUser->setUsername('adfshduser');
        $this->oUser->setHomedir('$');
        $this->oUser->setBootOpt(0);
        $this->oUser->setUnixUid(5000);
        $this->oUser->setPriv('u');
    }

    protected function tearDown(): void
    {
        config::resetValue('vfs_plugin_localadfshd_root');
        $this->_deleteDir($this->sRoot);
        AdfsHD::reset();
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    protected function _deleteDir(string $sDir): void
    {
        if (!is_dir($sDir)) {
            return;
        }
        $oIt = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($sDir, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($oIt as $oFile) {
            $oFile->isDir() ? rmdir($oFile->getRealPath()) : unlink($oFile->getRealPath());
        }
        rmdir($sDir);
    }

    /**
     * Creates a stub *.dat file on disk and registers a mock reader for it. The
     * stub file's content is irrelevant — the mock is always used in place of a
     * real AdfsReaderHD.
     */
    protected function _seedImage(string $sName, object $oMock): string
    {
        $sImagePath = $this->sRoot . $sName . '.dat';
        file_put_contents($sImagePath, str_repeat("\x00", 64));
        AdfsHD::setImageReader($sImagePath, $oMock);
        return $sImagePath;
    }

    protected function _sampleCatalogue(): array
    {
        return [
            'FILE1' => ['type' => 'file', 'load' => 0xFFFF0E00, 'exec' => 0xFFFF0E00, 'size' => 4, 'startsector' => 10],
            'DIR1'  => [
                'type' => 'dir', 'load' => 0, 'exec' => 0, 'startsector' => 20,
                'dir'  => [
                    'NESTED' => ['type' => 'file', 'load' => 0x1900, 'exec' => 0x8023, 'size' => 2, 'startsector' => 30],
                ],
            ],
        ];
    }

    // -------------------------------------------------------------------------
    // Basic plugin lifecycle
    // -------------------------------------------------------------------------

    public function testInitDoesNotThrow(): void
    {
        $oLogger = new Logger('test');
        $oLogger->pushHandler(new NullHandler());
        AdfsHD::init($oLogger, false);
        $this->assertTrue(true);
    }

    public function testHouseKeepingIsNoOp(): void
    {
        AdfsHD::houseKeeping();
        $this->assertTrue(true);
    }

    // -------------------------------------------------------------------------
    // Access mode
    // -------------------------------------------------------------------------

    public function testGetAccessModeReturnsReadOnly(): void
    {
        $this->assertSame('-r/-r', AdfsHD::_getAccessMode(0, 0, 0));
    }

    // -------------------------------------------------------------------------
    // getFile against a mocked image
    // -------------------------------------------------------------------------

    public function testGetFileReturnsContentsForFileInsideImage(): void
    {
        $oMock = Mockery::mock(AdfsReaderHD::class);
        $oMock->shouldReceive('getCatalogue')->andReturn($this->_sampleCatalogue());
        $oMock->shouldReceive('getFile')->with('FILE1')->andReturn('DATA');
        $this->_seedImage('scsi0', $oMock);

        $sData = AdfsHD::getFile($this->oUser, new FilePath('$', 'scsi0.FILE1'));
        $this->assertSame('DATA', $sData);
    }

    public function testGetFileFindsNestedFileInsideImage(): void
    {
        $oMock = Mockery::mock(AdfsReaderHD::class);
        $oMock->shouldReceive('getCatalogue')->andReturn($this->_sampleCatalogue());
        $oMock->shouldReceive('getFile')->with('DIR1.NESTED')->andReturn('NN');
        $this->_seedImage('scsi0', $oMock);

        $sData = AdfsHD::getFile($this->oUser, new FilePath('$.scsi0', 'DIR1.NESTED'));
        $this->assertSame('NN', $sData);
    }

    public function testGetFileThrowsWhenNoImageExists(): void
    {
        $this->expectException(VfsException::class);
        AdfsHD::getFile($this->oUser, new FilePath('$.nonexistent', 'file'));
    }

    public function testGetFileThrowsForPathNotInCatalogue(): void
    {
        $oMock = Mockery::mock(AdfsReaderHD::class);
        $oMock->shouldReceive('getCatalogue')->andReturn($this->_sampleCatalogue());
        $this->_seedImage('scsi0', $oMock);

        $this->expectException(VfsException::class);
        AdfsHD::getFile($this->oUser, new FilePath('$', 'scsi0.NOSUCHFILE'));
    }

    public function testGetFileThrowsForFileNotInEmptyImage(): void
    {
        $oMock = Mockery::mock(AdfsReaderHD::class);
        $oMock->shouldReceive('getCatalogue')->andReturn([]);
        $this->_seedImage('scsi0', $oMock);

        $this->expectException(VfsException::class);
        AdfsHD::getFile($this->oUser, new FilePath('$.scsi0', 'NOSUCHFILE'));
    }

    // -------------------------------------------------------------------------
    // getDirectoryListing
    // -------------------------------------------------------------------------

    public function testGetDirectoryListingReturnsCatalogueEntries(): void
    {
        $oMock = Mockery::mock(AdfsReaderHD::class);
        $oMock->shouldReceive('getCatalogue')->andReturn($this->_sampleCatalogue());
        $this->_seedImage('scsi0', $oMock);

        $aListing = AdfsHD::getDirectoryListing('$.scsi0', []);

        $this->assertArrayHasKey('FILE1', $aListing);
        $this->assertFalse($aListing['FILE1']->isDir());
        $this->assertSame(4, $aListing['FILE1']->getSize());

        $this->assertArrayHasKey('DIR1', $aListing);
        $this->assertTrue($aListing['DIR1']->isDir());
    }

    public function testGetDirectoryListingDescendsIntoSubdirectory(): void
    {
        $oMock = Mockery::mock(AdfsReaderHD::class);
        $oMock->shouldReceive('getCatalogue')->andReturn($this->_sampleCatalogue());
        $this->_seedImage('scsi0', $oMock);

        $aListing = AdfsHD::getDirectoryListing('$.scsi0.DIR1', []);

        $this->assertArrayHasKey('NESTED', $aListing);
        $this->assertSame(0x1900, $aListing['NESTED']->getLoadAddr());
        $this->assertSame(0x8023, $aListing['NESTED']->getExecAddr());
    }

    public function testGetDirectoryListingReturnsOriginalArrayWhenNoDiskImages(): void
    {
        $aExisting = ['prior' => 'entry'];
        $aResult   = AdfsHD::getDirectoryListing('$', $aExisting);
        $this->assertArrayHasKey('prior', $aResult);
    }

    public function testGetDirectoryListingInsideEmptyImageReturnsEmptyArray(): void
    {
        $oMock = Mockery::mock(AdfsReaderHD::class);
        $oMock->shouldReceive('getCatalogue')->andReturn([]);
        $this->_seedImage('scsi0', $oMock);

        $aListing = AdfsHD::getDirectoryListing('$.scsi0', []);
        $this->assertEmpty($aListing);
    }

    public function testGetDirectoryListingRootShowsDatAsVirtualDirectory(): void
    {
        file_put_contents($this->sRoot . 'scsi0.dat', str_repeat("\x00", 256));

        $aListing = AdfsHD::getDirectoryListing('$', []);

        $this->assertArrayHasKey('scsi0.dat', $aListing);
        $this->assertTrue($aListing['scsi0.dat']->isDir());
    }

    public function testGetDirectoryListingPreservesExistingEntries(): void
    {
        file_put_contents($this->sRoot . 'scsi0.dat', str_repeat("\x00", 256));

        $aExisting = ['prior' => 'value'];
        $aResult   = AdfsHD::getDirectoryListing('$', $aExisting);

        $this->assertArrayHasKey('prior', $aResult);
        $this->assertArrayHasKey('scsi0.dat', $aResult);
    }

    // -------------------------------------------------------------------------
    // _buildFiledescriptorFromEconetPath
    // -------------------------------------------------------------------------

    public function testBuildFiledescriptorThrowsForNonExistentPath(): void
    {
        $this->expectException(VfsException::class);
        AdfsHD::_buildFiledescriptorFromEconetPath(
            $this->oUser, new FilePath('$.nonexistent', 'file'), true, true
        );
    }

    public function testBuildFiledescriptorForDiskImageItselfReturnsDirectory(): void
    {
        $oMock = Mockery::mock(AdfsReaderHD::class);
        $this->_seedImage('scsi0', $oMock);

        $oFd = AdfsHD::_buildFiledescriptorFromEconetPath(
            $this->oUser, new FilePath('$', 'scsi0'), true, true
        );

        $this->assertTrue($oFd->isDir());
        $this->assertFalse($oFd->isFile());
    }

    public function testBuildFiledescriptorForFileInsideImage(): void
    {
        $oMock = Mockery::mock(AdfsReaderHD::class);
        $oMock->shouldReceive('getCatalogue')->andReturn($this->_sampleCatalogue());
        $oMock->shouldReceive('isFile')->with('FILE1')->andReturn(true);
        $oMock->shouldReceive('isDir')->with('FILE1')->andReturn(false);
        $this->_seedImage('scsi0', $oMock);

        $oFd = AdfsHD::_buildFiledescriptorFromEconetPath(
            $this->oUser, new FilePath('$', 'scsi0.FILE1'), true, true
        );

        $this->assertTrue($oFd->isFile());
        $this->assertFalse($oFd->isDir());
    }

    public function testBuildFiledescriptorForDirectoryInsideImage(): void
    {
        $oMock = Mockery::mock(AdfsReaderHD::class);
        $oMock->shouldReceive('getCatalogue')->andReturn($this->_sampleCatalogue());
        $oMock->shouldReceive('isFile')->with('DIR1')->andReturn(false);
        $oMock->shouldReceive('isDir')->with('DIR1')->andReturn(true);
        $this->_seedImage('scsi0', $oMock);

        $oFd = AdfsHD::_buildFiledescriptorFromEconetPath(
            $this->oUser, new FilePath('$.scsi0', 'DIR1'), true, true
        );

        $this->assertTrue($oFd->isDir());
        $this->assertFalse($oFd->isFile());
    }

    /**
     * Regression test for the bug where a nonexistent sub-path under an existing
     * image would fall through to a second "does the whole path map to an image
     * file?" check that silently ignored the sub-path and matched the image root
     * instead of throwing "No such file".
     */
    public function testBuildFiledescriptorThrowsForNonExistentPathInsideImage(): void
    {
        $oMock = Mockery::mock(AdfsReaderHD::class);
        $oMock->shouldReceive('getCatalogue')->andReturn($this->_sampleCatalogue());
        $this->_seedImage('scsi0', $oMock);

        $this->expectException(VfsException::class);
        AdfsHD::_buildFiledescriptorFromEconetPath(
            $this->oUser, new FilePath('$', 'scsi0.NOSUCHFILE'), true, true
        );
    }

    public function testBuildFiledescriptorThrowsForNonExistentNestedPathInsideImage(): void
    {
        $oMock = Mockery::mock(AdfsReaderHD::class);
        $oMock->shouldReceive('getCatalogue')->andReturn($this->_sampleCatalogue());
        $this->_seedImage('scsi0', $oMock);

        $this->expectException(VfsException::class);
        AdfsHD::_buildFiledescriptorFromEconetPath(
            $this->oUser, new FilePath('$.scsi0', 'DIR1.NOSUCHFILE'), true, true
        );
    }

    // -------------------------------------------------------------------------
    // Handle-based read I/O
    // -------------------------------------------------------------------------

    public function testReadReturnsDataAtCurrentPosition(): void
    {
        $oMock = Mockery::mock(AdfsReaderHD::class);
        $oMock->shouldReceive('getCatalogue')->andReturn($this->_sampleCatalogue());
        $oMock->shouldReceive('isFile')->with('FILE1')->andReturn(true);
        $oMock->shouldReceive('isDir')->with('FILE1')->andReturn(false);
        $oMock->shouldReceive('getFile')->with('FILE1')->andReturn('DATA');
        $this->_seedImage('scsi0', $oMock);

        AdfsHD::_buildFiledescriptorFromEconetPath($this->oUser, new FilePath('$', 'scsi0.FILE1'), true, true);

        $this->assertSame('DA', AdfsHD::read($this->oUser, 0, 2));
        AdfsHD::setPos($this->oUser, 0, 2);
        $this->assertSame('TA', AdfsHD::read($this->oUser, 0, 2));
    }

    public function testSetPosAndFsFtell(): void
    {
        $oMock = Mockery::mock(AdfsReaderHD::class);
        $this->_seedImage('scsi0', $oMock);

        $oFd = AdfsHD::_buildFiledescriptorFromEconetPath($this->oUser, new FilePath('$', 'scsi0'), true, true);
        $oFd->setPos(42);
        $this->assertSame(42, $oFd->fsFTell());
    }

    public function testFsFStatReturnsSizeAndSector(): void
    {
        $oMock = Mockery::mock(AdfsReaderHD::class);
        $oMock->shouldReceive('getCatalogue')->andReturn($this->_sampleCatalogue());
        $oMock->shouldReceive('isFile')->with('FILE1')->andReturn(true);
        $oMock->shouldReceive('isDir')->with('FILE1')->andReturn(false);
        $oMock->shouldReceive('getFile')->with('FILE1')->andReturn('DATA');
        $oMock->shouldReceive('getStat')->with('FILE1')->andReturn(['size' => 4, 'sector' => 10]);
        $this->_seedImage('scsi0', $oMock);

        AdfsHD::_buildFiledescriptorFromEconetPath($this->oUser, new FilePath('$', 'scsi0.FILE1'), true, true);

        $aStat = AdfsHD::fsFStat($this->oUser, 0);
        $this->assertSame(4, $aStat['size']);
        $this->assertSame(10, $aStat['ino']);
    }

    public function testFsCloseRemovesHandle(): void
    {
        $oMock = Mockery::mock(AdfsReaderHD::class);
        $this->_seedImage('scsi0', $oMock);

        $oFd = AdfsHD::_buildFiledescriptorFromEconetPath($this->oUser, new FilePath('$', 'scsi0'), true, true);
        $oFd->close();

        $this->expectException(VfsException::class);
        AdfsHD::fsFtell($this->oUser, 0);
    }

    // -------------------------------------------------------------------------
    // Mutating operations — all return false (read-only plugin)
    // -------------------------------------------------------------------------

    public function testCreateDirectoryReturnsFalse(): void
    {
        $this->assertFalse(AdfsHD::createDirectory($this->oUser, new FilePath('$', 'newdir')));
    }

    public function testDeleteFileReturnsFalse(): void
    {
        $this->assertFalse(AdfsHD::deleteFile($this->oUser, new FilePath('$', 'somefile')));
    }

    public function testMoveFileReturnsFalse(): void
    {
        $this->assertFalse(
            AdfsHD::moveFile($this->oUser, new FilePath('$', 'src'), new FilePath('$', 'dst'))
        );
    }

    public function testSaveFileIsNoOp(): void
    {
        AdfsHD::saveFile($this->oUser, new FilePath('$', 'f'), 'data', 0, 0);
        $this->assertTrue(true);
    }

    public function testCreateFileIsNoOp(): void
    {
        AdfsHD::createFile($this->oUser, new FilePath('$', 'f'), 10, 0, 0);
        $this->assertTrue(true);
    }

    public function testSetMetaIsNoOp(): void
    {
        AdfsHD::setMeta('$.f', 0, 0, 0);
        $this->assertTrue(true);
    }

    // -------------------------------------------------------------------------
    // Locking — no-ops
    // -------------------------------------------------------------------------

    public function testFsLockIsNoOp(): void
    {
        AdfsHD::fsLock($this->oUser, 0, true);
        AdfsHD::fsLock($this->oUser, 0, false);
        $this->assertTrue(true);
    }

    public function testFsUnlockIsNoOp(): void
    {
        AdfsHD::fsUnlock($this->oUser, 0);
        $this->assertTrue(true);
    }

    // -------------------------------------------------------------------------
    // Write / setExt — throw VfsException (truly read-only)
    // -------------------------------------------------------------------------

    public function testWriteThrowsVfsException(): void
    {
        $this->expectException(VfsException::class);
        AdfsHD::write($this->oUser, 9999, 'data');
    }

    public function testSetExtThrowsVfsException(): void
    {
        $this->expectException(VfsException::class);
        AdfsHD::setExt($this->oUser, 9999, 0);
    }

    // -------------------------------------------------------------------------
    // Invalid-handle error paths
    // -------------------------------------------------------------------------

    public function testFsFtellWithInvalidHandleThrowsVfsException(): void
    {
        $this->expectException(VfsException::class);
        AdfsHD::fsFtell($this->oUser, 9999);
    }

    public function testFsFStatWithInvalidHandleThrowsVfsException(): void
    {
        $this->expectException(VfsException::class);
        AdfsHD::fsFStat($this->oUser, 9999);
    }

    public function testIsEofWithInvalidHandleThrowsVfsException(): void
    {
        $this->expectException(VfsException::class);
        AdfsHD::isEof($this->oUser, 9999);
    }

    public function testSetPosWithInvalidHandleThrowsVfsException(): void
    {
        $this->expectException(VfsException::class);
        AdfsHD::setPos($this->oUser, 9999, 0);
    }

    public function testReadWithInvalidHandleThrowsVfsException(): void
    {
        $this->expectException(VfsException::class);
        AdfsHD::read($this->oUser, 9999, 10);
    }

    public function testFsCloseWithInvalidHandleIsNoOp(): void
    {
        AdfsHD::fsClose($this->oUser, 9999);
        $this->assertTrue(true);
    }
}
