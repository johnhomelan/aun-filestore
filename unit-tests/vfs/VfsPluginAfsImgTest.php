<?php

/*
 * @group unit-tests
 *
 * Unit tests for the AfsImg VFS plugin.
 *
 * The plugin provides read-only access to AFS (Acorn File Server) disk images
 * stored in .img files. It uses L3fsReader internally.
 *
 * L3fsReader is mocked out via AfsImg::setImageReader() rather than reading a
 * real binary image, so the tests exercise the full catalogue-walking behaviour
 * of the plugin — including the path-resolution edge cases around an existing
 * image with a requested sub-path that does not exist inside it — without
 * needing to synthesise a valid on-disk L3/AFS image format.
 *
 * One pre-existing, unrelated bug is documented (not fixed) below, matching
 * this file's existing precedent of pinning known-buggy behaviour rather than
 * silently changing it:
 *   - getDirectoryListing()'s root-level directory scan looks for ".adl" files
 *     (a copy-paste artefact) instead of ".img".
 *
 * (A second bug formerly documented here — both DirectoryEntry construction
 * sites in getDirectoryListing() passing constructor arguments in the wrong
 * order — has since been fixed.)
 */

if (!defined('CONFIG_security_mode')) {
    define('CONFIG_security_mode', 'singleuser');
}
// AfsImg uses config key 'vfs_plugin_localafsimg_root'; define a placeholder.
if (!defined('CONFIG_vfs_plugin_localafsimg_root')) {
    define('CONFIG_vfs_plugin_localafsimg_root', '/tmp/afsimg-test-default-' . uniqid());
}

include_once(__DIR__ . '/../../src/include/system.inc.php');

use PHPUnit\Framework\TestCase;
use Monolog\Logger;
use Monolog\Handler\NullHandler;
use HomeLan\FileStore\Authentication\User;
use HomeLan\FileStore\Vfs\Plugin\AfsImg;
use HomeLan\FileStore\Vfs\FilePath;
use HomeLan\FileStore\Vfs\Exception as VfsException;
use HomeLan\Retro\Acorn\Disk\L3fsReader;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;

class VfsPluginAfsImgTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    protected User $oUser;
    protected string $sRoot;

    // -------------------------------------------------------------------------
    // Setup / Teardown
    // -------------------------------------------------------------------------

    protected function setUp(): void
    {
        $this->sRoot = sys_get_temp_dir() . '/afsimg_test_' . uniqid() . '/';
        mkdir($this->sRoot, 0755, true);

        config::overrideValue('vfs_plugin_localafsimg_root', rtrim($this->sRoot, '/'));

        $oLogger = new Logger('afsimg-test');
        $oLogger->pushHandler(new NullHandler());
        AfsImg::reset();
        AfsImg::init($oLogger, false);

        $this->oUser = new User();
        $this->oUser->setUsername('afsimguser');
        $this->oUser->setHomedir('$');
        $this->oUser->setBootOpt(0);
        $this->oUser->setUnixUid(5000);
        $this->oUser->setPriv('u');
    }

    protected function tearDown(): void
    {
        config::resetValue('vfs_plugin_localafsimg_root');
        $this->_deleteDir($this->sRoot);
        AfsImg::reset();
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
     * Creates a stub *.img file on disk and registers a mock reader for it. The
     * stub file's content is irrelevant — the mock is always used in place of a
     * real L3fsReader.
     */
    protected function _seedImage(string $sName, object $oMock): string
    {
        $sImagePath = $this->sRoot . $sName . '.img';
        file_put_contents($sImagePath, str_repeat("\x00", 64));
        AfsImg::setImageReader($sImagePath, $oMock);
        return $sImagePath;
    }

    protected function _sampleCatalogue(): array
    {
        return [
            'FILE1' => ['type' => 'file', 'load' => 0xFFFF0E00, 'exec' => 0xFFFF0E00, 'size' => 4, 'sector' => 10],
            'DIR1'  => [
                'type' => 'dir', 'load' => 0, 'exec' => 0, 'sector' => 20,
                'dir'  => [
                    'NESTED' => ['type' => 'file', 'load' => 0x1900, 'exec' => 0x8023, 'size' => 2, 'sector' => 30],
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
        AfsImg::init($oLogger, false);
        $this->assertTrue(true);
    }

    public function testHouseKeepingIsNoOp(): void
    {
        AfsImg::houseKeeping();
        $this->assertTrue(true);
    }

    // -------------------------------------------------------------------------
    // Access mode
    // -------------------------------------------------------------------------

    public function testGetAccessModeReturnsReadOnly(): void
    {
        $this->assertSame('-r/-r', AfsImg::_getAccessMode(0, 0, 0));
    }

    // -------------------------------------------------------------------------
    // getFile against a mocked image
    // -------------------------------------------------------------------------

    public function testGetFileReturnsContentsForFileInsideImage(): void
    {
        $oMock = Mockery::mock(L3fsReader::class);
        $oMock->shouldReceive('getCatalogue')->andReturn($this->_sampleCatalogue());
        $oMock->shouldReceive('getFile')->with('FILE1')->andReturn('DATA');
        $this->_seedImage('myimage', $oMock);

        $sData = AfsImg::getFile($this->oUser, new FilePath('$', 'myimage.FILE1'));
        $this->assertSame('DATA', $sData);
    }

    public function testGetFileFindsNestedFileInsideImage(): void
    {
        $oMock = Mockery::mock(L3fsReader::class);
        $oMock->shouldReceive('getCatalogue')->andReturn($this->_sampleCatalogue());
        $oMock->shouldReceive('getFile')->with('DIR1.NESTED')->andReturn('NN');
        $this->_seedImage('myimage', $oMock);

        $sData = AfsImg::getFile($this->oUser, new FilePath('$.myimage', 'DIR1.NESTED'));
        $this->assertSame('NN', $sData);
    }

    public function testGetFileThrowsWhenNoImageExists(): void
    {
        $this->expectException(VfsException::class);
        AfsImg::getFile($this->oUser, new FilePath('$.nonexistent', 'file'));
    }

    public function testGetFileThrowsForPathNotInCatalogue(): void
    {
        $oMock = Mockery::mock(L3fsReader::class);
        $oMock->shouldReceive('getCatalogue')->andReturn($this->_sampleCatalogue());
        $this->_seedImage('myimage', $oMock);

        $this->expectException(VfsException::class);
        AfsImg::getFile($this->oUser, new FilePath('$', 'myimage.NOSUCHFILE'));
    }

    // -------------------------------------------------------------------------
    // _buildFiledescriptorFromEconetPath
    // -------------------------------------------------------------------------

    public function testBuildFiledescriptorThrowsForNonExistentPath(): void
    {
        $this->expectException(VfsException::class);
        AfsImg::_buildFiledescriptorFromEconetPath(
            $this->oUser, new FilePath('$.nonexistent', 'file'), true, true
        );
    }

    public function testBuildFiledescriptorForImageRootIsADirectoryHandle(): void
    {
        $oMock = Mockery::mock(L3fsReader::class);
        $this->_seedImage('myimage', $oMock);

        $oFd = AfsImg::_buildFiledescriptorFromEconetPath(
            $this->oUser, new FilePath('$', 'myimage'), true, true
        );

        $this->assertTrue($oFd->isDir());
        $this->assertFalse($oFd->isFile());
    }

    public function testBuildFiledescriptorForFileInsideImage(): void
    {
        $oMock = Mockery::mock(L3fsReader::class);
        $oMock->shouldReceive('getCatalogue')->andReturn($this->_sampleCatalogue());
        $oMock->shouldReceive('isFile')->with('FILE1')->andReturn(true);
        $oMock->shouldReceive('isDir')->with('FILE1')->andReturn(false);
        $this->_seedImage('myimage', $oMock);

        $oFd = AfsImg::_buildFiledescriptorFromEconetPath(
            $this->oUser, new FilePath('$', 'myimage.FILE1'), true, true
        );

        $this->assertTrue($oFd->isFile());
        $this->assertFalse($oFd->isDir());
    }

    public function testBuildFiledescriptorForDirectoryInsideImage(): void
    {
        $oMock = Mockery::mock(L3fsReader::class);
        $oMock->shouldReceive('getCatalogue')->andReturn($this->_sampleCatalogue());
        $oMock->shouldReceive('isFile')->with('DIR1')->andReturn(false);
        $oMock->shouldReceive('isDir')->with('DIR1')->andReturn(true);
        $this->_seedImage('myimage', $oMock);

        $oFd = AfsImg::_buildFiledescriptorFromEconetPath(
            $this->oUser, new FilePath('$.myimage', 'DIR1'), true, true
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
        $oMock = Mockery::mock(L3fsReader::class);
        $oMock->shouldReceive('getCatalogue')->andReturn($this->_sampleCatalogue());
        $this->_seedImage('myimage', $oMock);

        $this->expectException(VfsException::class);
        AfsImg::_buildFiledescriptorFromEconetPath(
            $this->oUser, new FilePath('$', 'myimage.NOSUCHFILE'), true, true
        );
    }

    public function testBuildFiledescriptorThrowsForNonExistentNestedPathInsideImage(): void
    {
        $oMock = Mockery::mock(L3fsReader::class);
        $oMock->shouldReceive('getCatalogue')->andReturn($this->_sampleCatalogue());
        $this->_seedImage('myimage', $oMock);

        $this->expectException(VfsException::class);
        AfsImg::_buildFiledescriptorFromEconetPath(
            $this->oUser, new FilePath('$.myimage', 'DIR1.NOSUCHFILE'), true, true
        );
    }

    // -------------------------------------------------------------------------
    // Handle-based read I/O
    // -------------------------------------------------------------------------

    public function testReadReturnsDataAtCurrentPosition(): void
    {
        $oMock = Mockery::mock(L3fsReader::class);
        $oMock->shouldReceive('getCatalogue')->andReturn($this->_sampleCatalogue());
        $oMock->shouldReceive('isFile')->with('FILE1')->andReturn(true);
        $oMock->shouldReceive('isDir')->with('FILE1')->andReturn(false);
        $oMock->shouldReceive('getFile')->with('FILE1')->andReturn('DATA');
        $this->_seedImage('myimage', $oMock);

        AfsImg::_buildFiledescriptorFromEconetPath($this->oUser, new FilePath('$', 'myimage.FILE1'), true, true);

        $this->assertSame('DA', AfsImg::read($this->oUser, 0, 2));
        AfsImg::setPos($this->oUser, 0, 2);
        $this->assertSame('TA', AfsImg::read($this->oUser, 0, 2));
    }

    public function testFsFStatReturnsSizeAndSector(): void
    {
        $oMock = Mockery::mock(L3fsReader::class);
        $oMock->shouldReceive('getCatalogue')->andReturn($this->_sampleCatalogue());
        $oMock->shouldReceive('isFile')->with('FILE1')->andReturn(true);
        $oMock->shouldReceive('isDir')->with('FILE1')->andReturn(false);
        $oMock->shouldReceive('getStat')->with('FILE1')->andReturn(['size' => 4, 'sector' => 10]);
        $this->_seedImage('myimage', $oMock);

        AfsImg::_buildFiledescriptorFromEconetPath($this->oUser, new FilePath('$', 'myimage.FILE1'), true, true);

        $aStat = AfsImg::fsFStat($this->oUser, 0);
        $this->assertSame(4, $aStat['size']);
        $this->assertSame(10, $aStat['ino']);
    }

    // -------------------------------------------------------------------------
    // getDirectoryListing
    // -------------------------------------------------------------------------

    public function testGetDirectoryListingReturnsOriginalArrayWhenNoImages(): void
    {
        $aExisting = ['prior' => 'entry'];
        $aResult   = AfsImg::getDirectoryListing('$', $aExisting);
        $this->assertArrayHasKey('prior', $aResult);
    }

    public function testGetDirectoryListingWithNoImgFilesReturnsEmptyForBlankRoot(): void
    {
        $aResult = AfsImg::getDirectoryListing('$', []);
        // No .img or .adl files in the temp root — listing must be empty.
        $this->assertEmpty($aResult);
    }

    /**
     * AfsImg::getDirectoryListing() scans for ".adl" files (a known copy-paste
     * artefact — the plugin was adapted from AdfsAdl). Placing an .adl file in
     * the root therefore causes it to appear as a virtual directory. This test
     * documents that still-open bug.
     */
    public function testGetDirectoryListingPicksUpAdlFilesInsteadOfImg(): void
    {
        // Place a fake .adl file (not a valid image, just to test the scan path).
        file_put_contents($this->sRoot . 'fakeDisk.adl', str_repeat("\x00", 256));

        $aListing = AfsImg::getDirectoryListing('$', []);

        // The plugin finds 'fakeDisk.adl' (looks for .adl, not .img).
        $this->assertArrayHasKey('fakeDisk.adl', $aListing);
        $this->assertTrue($aListing['fakeDisk.adl']->isDir());
    }

    public function testGetDirectoryListingDoesNotPickUpImgFiles(): void
    {
        // Place a .img file — the scan looks for .adl so this should be ignored.
        file_put_contents($this->sRoot . 'realDisk.img', str_repeat("\x00", 256));

        $aListing = AfsImg::getDirectoryListing('$', []);

        $this->assertArrayNotHasKey('realDisk.img', $aListing);
    }

    /**
     * The catalogue-based branch of getDirectoryListing() (reached when the
     * requested path resolves inside a real .img image) has the same
     * DirectoryEntry parameter-ordering bug as the root-level scan above: the
     * boolean "is directory" flag and the full econet path / ctime / mode
     * arguments are shifted by one position. This test documents the actual
     * (buggy) behaviour rather than silently changing it, matching this file's
     * existing precedent for the root-scan variant of the same bug.
     */
    public function testGetDirectoryListingCatalogueEntriesHaveCorrectConstructorArgs(): void
    {
        $oMock = Mockery::mock(L3fsReader::class);
        $oMock->shouldReceive('getCatalogue')->andReturn($this->_sampleCatalogue());
        $this->_seedImage('myimage', $oMock);

        $aListing = AfsImg::getDirectoryListing('$.myimage', []);

        $this->assertArrayHasKey('FILE1', $aListing);
        // FILE1 is a 'file' entry in the catalogue, so isDir() must be false.
        $this->assertFalse($aListing['FILE1']->isDir());
    }

    // -------------------------------------------------------------------------
    // Mutating operations — all return false (read-only plugin)
    // -------------------------------------------------------------------------

    public function testCreateDirectoryReturnsFalse(): void
    {
        $this->assertFalse(AfsImg::createDirectory($this->oUser, new FilePath('$', 'newdir')));
    }

    public function testDeleteFileReturnsFalse(): void
    {
        $this->assertFalse(AfsImg::deleteFile($this->oUser, new FilePath('$', 'somefile')));
    }

    public function testMoveFileReturnsFalse(): void
    {
        $this->assertFalse(
            AfsImg::moveFile($this->oUser, new FilePath('$', 'src'), new FilePath('$', 'dst'))
        );
    }

    public function testSaveFileIsNoOp(): void
    {
        AfsImg::saveFile($this->oUser, new FilePath('$', 'f'), 'data', 0, 0);
        $this->assertTrue(true);
    }

    public function testCreateFileIsNoOp(): void
    {
        AfsImg::createFile($this->oUser, new FilePath('$', 'f'), 10, 0, 0);
        $this->assertTrue(true);
    }

    public function testSetMetaIsNoOp(): void
    {
        AfsImg::setMeta('$.f', 0, 0, 0);
        $this->assertTrue(true);
    }

    // -------------------------------------------------------------------------
    // Locking — no-ops
    // -------------------------------------------------------------------------

    public function testFsLockIsNoOp(): void
    {
        AfsImg::fsLock($this->oUser, 0, true);
        AfsImg::fsLock($this->oUser, 0, false);
        $this->assertTrue(true);
    }

    public function testFsUnlockIsNoOp(): void
    {
        AfsImg::fsUnlock($this->oUser, 0);
        $this->assertTrue(true);
    }

    // -------------------------------------------------------------------------
    // Write / setExt — throw VfsException
    // -------------------------------------------------------------------------

    public function testWriteThrowsVfsException(): void
    {
        $this->expectException(VfsException::class);
        AfsImg::write($this->oUser, 9999, 'data');
    }

    public function testSetExtThrowsVfsException(): void
    {
        $this->expectException(VfsException::class);
        AfsImg::setExt($this->oUser, 9999, 0);
    }

    // -------------------------------------------------------------------------
    // Invalid-handle error paths
    // -------------------------------------------------------------------------

    public function testFsFtellWithInvalidHandleThrowsVfsException(): void
    {
        $this->expectException(VfsException::class);
        AfsImg::fsFtell($this->oUser, 9999);
    }

    public function testFsFStatWithInvalidHandleThrowsVfsException(): void
    {
        $this->expectException(VfsException::class);
        AfsImg::fsFStat($this->oUser, 9999);
    }

    public function testIsEofWithInvalidHandleThrowsVfsException(): void
    {
        $this->expectException(VfsException::class);
        AfsImg::isEof($this->oUser, 9999);
    }

    public function testSetPosWithInvalidHandleThrowsVfsException(): void
    {
        $this->expectException(VfsException::class);
        AfsImg::setPos($this->oUser, 9999, 0);
    }

    public function testReadWithInvalidHandleThrowsVfsException(): void
    {
        $this->expectException(VfsException::class);
        AfsImg::read($this->oUser, 9999, 10);
    }

    public function testFsCloseWithInvalidHandleIsNoOp(): void
    {
        AfsImg::fsClose($this->oUser, 9999);
        $this->assertTrue(true);
    }
}
