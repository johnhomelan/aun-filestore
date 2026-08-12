<?php

/*
 * @group unit-tests
 *
 * Unit tests for the DfsSsd VFS plugin.
 *
 * The plugin provides read-only access to DFS SSD (single-sided) disk image files
 * stored on the local filesystem. .ssd files appear as virtual directories in
 * the econet namespace. Unlike the other Acorn disk-image plugins, DfsSsd's
 * catalogue is flat — a single directory letter (e.g. '$' or 'A') followed by a
 * filename, never more than two levels deep.
 *
 * DfsReader is mocked out via DfsSsd::setImageReader() rather than reading a
 * real binary image, so the tests exercise the full catalogue-walking behaviour
 * of the plugin — including the path-resolution edge cases around an existing
 * image with a requested sub-path that does not exist inside it — without
 * needing to synthesise a valid on-disk DFS image format.
 */

if (!defined('CONFIG_security_mode')) {
    define('CONFIG_security_mode', 'singleuser');
}
if (!defined('CONFIG_vfs_plugin_localdfsssd_root')) {
    define('CONFIG_vfs_plugin_localdfsssd_root', '/tmp/dfsssd-test-default-' . uniqid());
}

include_once(__DIR__ . '/../../src/include/system.inc.php');

use PHPUnit\Framework\TestCase;
use Monolog\Logger;
use Monolog\Handler\NullHandler;
use HomeLan\FileStore\Authentication\User;
use HomeLan\FileStore\Vfs\Plugin\DfsSsd;
use HomeLan\FileStore\Vfs\FilePath;
use HomeLan\FileStore\Vfs\Exception as VfsException;
use HomeLan\Retro\Acorn\Disk\DfsReader;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;

class VfsPluginDfsSsdTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    protected User $oUser;
    protected string $sRoot;

    // -------------------------------------------------------------------------
    // Setup / Teardown
    // -------------------------------------------------------------------------

    protected function setUp(): void
    {
        $this->sRoot = sys_get_temp_dir() . '/dfsssd_test_' . uniqid() . '/';
        mkdir($this->sRoot, 0755, true);

        config::overrideValue('vfs_plugin_localdfsssd_root', rtrim($this->sRoot, '/'));

        $oLogger = new Logger('dfsssd-test');
        $oLogger->pushHandler(new NullHandler());
        DfsSsd::reset();
        DfsSsd::init($oLogger, false);

        $this->oUser = new User();
        $this->oUser->setUsername('dfsssduser');
        $this->oUser->setHomedir('$');
        $this->oUser->setBootOpt(0);
        $this->oUser->setUnixUid(5000);
        $this->oUser->setPriv('u');
    }

    protected function tearDown(): void
    {
        config::resetValue('vfs_plugin_localdfsssd_root');
        $this->_deleteDir($this->sRoot);
        DfsSsd::reset();
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
     * Creates a stub *.ssd file on disk and registers a mock reader for it. The
     * stub file's content is irrelevant — the mock is always used in place of a
     * real DfsReader.
     */
    protected function _seedImage(string $sName, object $oMock): string
    {
        $sImagePath = $this->sRoot . $sName . '.ssd';
        file_put_contents($sImagePath, str_repeat("\x00", 64));
        DfsSsd::setImageReader($sImagePath, $oMock);
        return $sImagePath;
    }

    protected function _sampleCatalogue(): array
    {
        return [
            '$' => [
                'HELLO' => ['loadaddr' => 0xFF04, 'execaddr' => 0xFF19, 'size' => 11, 'startsector' => 2],
            ],
            'A' => [
                'PROG' => ['loadaddr' => 0x8000, 'execaddr' => 0x8000, 'size' => 20, 'startsector' => 5],
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
        DfsSsd::init($oLogger, false);
        $this->assertTrue(true);
    }

    public function testHouseKeepingIsNoOp(): void
    {
        DfsSsd::houseKeeping();
        $this->assertTrue(true);
    }

    // -------------------------------------------------------------------------
    // Access mode
    // -------------------------------------------------------------------------

    public function testGetAccessModeReturnsReadOnly(): void
    {
        $this->assertSame('-r/-r', DfsSsd::_getAccessMode(0, 0, 0));
    }

    // -------------------------------------------------------------------------
    // getFile against a mocked image
    // -------------------------------------------------------------------------

    public function testGetFileReturnsContentsForFileInDefaultDir(): void
    {
        $oMock = Mockery::mock(DfsReader::class);
        $oMock->shouldReceive('getCatalogue')->andReturn($this->_sampleCatalogue());
        $oMock->shouldReceive('getFile')->with('HELLO')->andReturn('Hello World');
        $this->_seedImage('testdisk', $oMock);

        $sData = DfsSsd::getFile($this->oUser, new FilePath('$.testdisk', 'HELLO'));
        $this->assertSame('Hello World', $sData);
    }

    public function testGetFileReturnsContentsForFileInNamedDir(): void
    {
        $oMock = Mockery::mock(DfsReader::class);
        $oMock->shouldReceive('getCatalogue')->andReturn($this->_sampleCatalogue());
        $oMock->shouldReceive('getFile')->with('A.PROG')->andReturn('PROGDATA');
        $this->_seedImage('testdisk', $oMock);

        $sData = DfsSsd::getFile($this->oUser, new FilePath('$.testdisk', 'A.PROG'));
        $this->assertSame('PROGDATA', $sData);
    }

    public function testGetFileThrowsForPathWithNoImage(): void
    {
        $this->expectException(VfsException::class);
        DfsSsd::getFile($this->oUser, new FilePath('$.nonexistent', 'file'));
    }

    public function testGetFileThrowsForNonExistentFileInsideImage(): void
    {
        $oMock = Mockery::mock(DfsReader::class);
        $oMock->shouldReceive('getCatalogue')->andReturn($this->_sampleCatalogue());
        $this->_seedImage('testdisk', $oMock);

        $this->expectException(VfsException::class);
        DfsSsd::getFile($this->oUser, new FilePath('$.testdisk', 'NOSUCHFILE'));
    }

    // -------------------------------------------------------------------------
    // getDirectoryListing
    // -------------------------------------------------------------------------

    public function testGetDirectoryListingReturnsOriginalArrayWhenNoDiskImages(): void
    {
        $aExisting = ['prior' => 'entry'];
        $aResult   = DfsSsd::getDirectoryListing('$', $aExisting);
        $this->assertArrayHasKey('prior', $aResult);
    }

    public function testGetDirectoryListingRootShowsSsdAsVirtualDirectory(): void
    {
        file_put_contents($this->sRoot . 'testdisk.ssd', str_repeat("\x00", 256));

        $aListing = DfsSsd::getDirectoryListing('$', []);

        $this->assertArrayHasKey('testdisk.ssd', $aListing);
        $this->assertTrue($aListing['testdisk.ssd']->isDir());
    }

    public function testGetDirectoryListingInsideImageShowsDefaultDirFilesAndOtherDirs(): void
    {
        $oMock = Mockery::mock(DfsReader::class);
        $oMock->shouldReceive('getCatalogue')->andReturn($this->_sampleCatalogue());
        $this->_seedImage('testdisk', $oMock);

        $aListing = DfsSsd::getDirectoryListing('$.testdisk', []);

        $this->assertArrayHasKey('HELLO', $aListing);
        $this->assertFalse($aListing['HELLO']->isDir());

        // Non-'$' directory letters are exposed as virtual sub-directories.
        $this->assertArrayHasKey('A', $aListing);
        $this->assertTrue($aListing['A']->isDir());
    }

    public function testGetDirectoryListingInsideImageFileHasCorrectLoadAndExecAddresses(): void
    {
        $oMock = Mockery::mock(DfsReader::class);
        $oMock->shouldReceive('getCatalogue')->andReturn($this->_sampleCatalogue());
        $this->_seedImage('testdisk', $oMock);

        $aListing = DfsSsd::getDirectoryListing('$.testdisk', []);

        $this->assertSame(0xFF04, $aListing['HELLO']->getLoadAddr());
        $this->assertSame(0xFF19, $aListing['HELLO']->getExecAddr());
    }

    public function testGetDirectoryListingDescendsIntoNamedDir(): void
    {
        $oMock = Mockery::mock(DfsReader::class);
        $oMock->shouldReceive('getCatalogue')->andReturn($this->_sampleCatalogue());
        $this->_seedImage('testdisk', $oMock);

        $aListing = DfsSsd::getDirectoryListing('$.testdisk.A', []);

        $this->assertArrayHasKey('PROG', $aListing);
        $this->assertSame(0x8000, $aListing['PROG']->getLoadAddr());
    }

    public function testGetDirectoryListingPreservesExistingEntries(): void
    {
        $oMock = Mockery::mock(DfsReader::class);
        $oMock->shouldReceive('getCatalogue')->andReturn($this->_sampleCatalogue());
        $this->_seedImage('testdisk', $oMock);

        $aExisting = ['prior' => 'value'];
        $aResult   = DfsSsd::getDirectoryListing('$.testdisk', $aExisting);

        $this->assertArrayHasKey('prior', $aResult);
        $this->assertArrayHasKey('HELLO', $aResult);
    }

    public function testGetDirectoryListingUnknownSubdirReturnsOriginalArray(): void
    {
        $oMock = Mockery::mock(DfsReader::class);
        $oMock->shouldReceive('getCatalogue')->andReturn($this->_sampleCatalogue());
        $this->_seedImage('testdisk', $oMock);

        $aExisting = ['x' => 'y'];
        $aResult   = DfsSsd::getDirectoryListing('$.testdisk.NOSUCHDIR', $aExisting);

        $this->assertSame($aExisting, $aResult);
    }

    // -------------------------------------------------------------------------
    // _buildFiledescriptorFromEconetPath
    // -------------------------------------------------------------------------

    public function testBuildFiledescriptorThrowsForNonExistentPath(): void
    {
        $this->expectException(VfsException::class);
        DfsSsd::_buildFiledescriptorFromEconetPath(
            $this->oUser, new FilePath('$.nonexistent', 'file'), true, true
        );
    }

    public function testBuildFiledescriptorForImageRootIsADirectoryHandle(): void
    {
        $oMock = Mockery::mock(DfsReader::class);
        $this->_seedImage('testdisk', $oMock);

        $oFd = DfsSsd::_buildFiledescriptorFromEconetPath(
            $this->oUser, new FilePath('$', 'testdisk'), true, true
        );

        $this->assertTrue($oFd->isDir());
        $this->assertFalse($oFd->isFile());
    }

    public function testBuildFiledescriptorForFileInDefaultDir(): void
    {
        $oMock = Mockery::mock(DfsReader::class);
        $oMock->shouldReceive('getCatalogue')->andReturn($this->_sampleCatalogue());
        $oMock->shouldReceive('isFile')->with('HELLO')->andReturn(true);
        $oMock->shouldReceive('isDir')->with('HELLO')->andReturn(false);
        $this->_seedImage('testdisk', $oMock);

        $oFd = DfsSsd::_buildFiledescriptorFromEconetPath(
            $this->oUser, new FilePath('$.testdisk', 'HELLO'), true, true
        );

        $this->assertTrue($oFd->isFile());
        $this->assertFalse($oFd->isDir());
    }

    public function testBuildFiledescriptorForNamedDir(): void
    {
        $oMock = Mockery::mock(DfsReader::class);
        $oMock->shouldReceive('getCatalogue')->andReturn($this->_sampleCatalogue());
        $oMock->shouldReceive('isFile')->with('A')->andReturn(false);
        $oMock->shouldReceive('isDir')->with('A')->andReturn(true);
        $this->_seedImage('testdisk', $oMock);

        $oFd = DfsSsd::_buildFiledescriptorFromEconetPath(
            $this->oUser, new FilePath('$.testdisk', 'A'), true, true
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
    public function testBuildFiledescriptorThrowsForNonExistentFileInDefaultDir(): void
    {
        $oMock = Mockery::mock(DfsReader::class);
        $oMock->shouldReceive('getCatalogue')->andReturn($this->_sampleCatalogue());
        $this->_seedImage('testdisk', $oMock);

        $this->expectException(VfsException::class);
        DfsSsd::_buildFiledescriptorFromEconetPath(
            $this->oUser, new FilePath('$.testdisk', 'NOSUCHFILE'), true, true
        );
    }

    public function testBuildFiledescriptorThrowsForNonExistentFileInNamedDir(): void
    {
        $oMock = Mockery::mock(DfsReader::class);
        $oMock->shouldReceive('getCatalogue')->andReturn($this->_sampleCatalogue());
        $this->_seedImage('testdisk', $oMock);

        $this->expectException(VfsException::class);
        DfsSsd::_buildFiledescriptorFromEconetPath(
            $this->oUser, new FilePath('$.testdisk', 'A.NOSUCHFILE'), true, true
        );
    }

    // -------------------------------------------------------------------------
    // Handle-based read I/O
    // -------------------------------------------------------------------------

    public function testReadReturnsDataAtCurrentPosition(): void
    {
        $oMock = Mockery::mock(DfsReader::class);
        $oMock->shouldReceive('getCatalogue')->andReturn($this->_sampleCatalogue());
        $oMock->shouldReceive('isFile')->with('HELLO')->andReturn(true);
        $oMock->shouldReceive('isDir')->with('HELLO')->andReturn(false);
        $oMock->shouldReceive('getFile')->with('HELLO')->andReturn('Hello World');
        $this->_seedImage('testdisk', $oMock);

        $oFd = DfsSsd::_buildFiledescriptorFromEconetPath($this->oUser, new FilePath('$.testdisk', 'HELLO'), true, true);
        $oFd->setPos(6);
        $this->assertSame('World', $oFd->read(5));
    }

    public function testSetPosUpdatesFsFtell(): void
    {
        $oMock = Mockery::mock(DfsReader::class);
        $oMock->shouldReceive('getCatalogue')->andReturn($this->_sampleCatalogue());
        $oMock->shouldReceive('isFile')->with('HELLO')->andReturn(true);
        $oMock->shouldReceive('isDir')->with('HELLO')->andReturn(false);
        $this->_seedImage('testdisk', $oMock);

        $oFd = DfsSsd::_buildFiledescriptorFromEconetPath($this->oUser, new FilePath('$.testdisk', 'HELLO'), true, true);
        $oFd->setPos(5);
        $this->assertSame(5, $oFd->fsFTell());
    }

    public function testFsFStatReturnsSizeAndSector(): void
    {
        $oMock = Mockery::mock(DfsReader::class);
        $oMock->shouldReceive('getCatalogue')->andReturn($this->_sampleCatalogue());
        $oMock->shouldReceive('isFile')->with('HELLO')->andReturn(true);
        $oMock->shouldReceive('isDir')->with('HELLO')->andReturn(false);
        $oMock->shouldReceive('getStat')->with('HELLO')->andReturn(['size' => 11, 'sector' => 2]);
        $this->_seedImage('testdisk', $oMock);

        $oFd = DfsSsd::_buildFiledescriptorFromEconetPath($this->oUser, new FilePath('$.testdisk', 'HELLO'), true, true);
        $aStat = $oFd->fsFStat();

        $this->assertSame(11, $aStat['size']);
        $this->assertSame(2, $aStat['ino']);
        $this->assertSame(1, $aStat['nlink']);
        $this->assertNull($aStat['dev']);
    }

    public function testIsEofFalseBeforeEndAndTrueAtEnd(): void
    {
        $oMock = Mockery::mock(DfsReader::class);
        $oMock->shouldReceive('getCatalogue')->andReturn($this->_sampleCatalogue());
        $oMock->shouldReceive('isFile')->with('HELLO')->andReturn(true);
        $oMock->shouldReceive('isDir')->with('HELLO')->andReturn(false);
        $oMock->shouldReceive('getStat')->with('HELLO')->andReturn(['size' => 11, 'sector' => 2]);
        $this->_seedImage('testdisk', $oMock);

        $oFd = DfsSsd::_buildFiledescriptorFromEconetPath($this->oUser, new FilePath('$.testdisk', 'HELLO'), true, true);

        $oFd->setPos(0);
        $this->assertFalse($oFd->isEof());
        $oFd->setPos(11);
        $this->assertTrue($oFd->isEof());
    }

    public function testFsCloseRemovesHandle(): void
    {
        $oMock = Mockery::mock(DfsReader::class);
        $this->_seedImage('testdisk', $oMock);

        $oFd = DfsSsd::_buildFiledescriptorFromEconetPath($this->oUser, new FilePath('$', 'testdisk'), true, true);
        $oFd->close();

        $this->expectException(VfsException::class);
        DfsSsd::fsFtell($this->oUser, 0);
    }

    // -------------------------------------------------------------------------
    // Mutating whole-file operations — read-only plugin
    // -------------------------------------------------------------------------

    public function testCreateDirectoryReturnsFalse(): void
    {
        $this->assertFalse(DfsSsd::createDirectory($this->oUser, new FilePath('$', 'newdir')));
    }

    public function testDeleteFileReturnsFalse(): void
    {
        $this->assertFalse(DfsSsd::deleteFile($this->oUser, new FilePath('$', 'somefile')));
    }

    public function testMoveFileReturnsFalse(): void
    {
        $this->assertFalse(
            DfsSsd::moveFile($this->oUser, new FilePath('$', 'src'), new FilePath('$', 'dst'))
        );
    }

    public function testSaveFileIsNoOp(): void
    {
        // DfsSsd::saveFile() has an empty body — must not throw.
        DfsSsd::saveFile($this->oUser, new FilePath('$', 'f'), 'data', 0, 0);
        $this->assertTrue(true);
    }

    public function testCreateFileIsNoOp(): void
    {
        DfsSsd::createFile($this->oUser, new FilePath('$', 'f'), 10, 0, 0);
        $this->assertTrue(true);
    }

    public function testSetMetaIsNoOp(): void
    {
        DfsSsd::setMeta('$.f', 0, 0, 0);
        $this->assertTrue(true);
    }

    // -------------------------------------------------------------------------
    // Locking — no-ops
    // -------------------------------------------------------------------------

    public function testFsLockIsNoOp(): void
    {
        DfsSsd::fsLock($this->oUser, 0, true);
        DfsSsd::fsLock($this->oUser, 0, false);
        $this->assertTrue(true);
    }

    public function testFsUnlockIsNoOp(): void
    {
        DfsSsd::fsUnlock($this->oUser, 0);
        $this->assertTrue(true);
    }

    // -------------------------------------------------------------------------
    // DfsSsd write / setExt are no-ops (unlike the other image plugins, which
    // throw VfsException) — they just log and return.
    // -------------------------------------------------------------------------

    public function testWriteIsNoOpDoesNotThrow(): void
    {
        DfsSsd::write($this->oUser, 9999, 'somedata');
        $this->assertTrue(true);
    }

    public function testSetExtIsNoOpDoesNotThrow(): void
    {
        DfsSsd::setExt($this->oUser, 9999, 0);
        $this->assertTrue(true);
    }

    // -------------------------------------------------------------------------
    // Invalid-handle error paths
    // -------------------------------------------------------------------------

    public function testFsFtellWithInvalidHandleThrowsVfsException(): void
    {
        $this->expectException(VfsException::class);
        DfsSsd::fsFtell($this->oUser, 9999);
    }

    public function testFsFStatWithInvalidHandleThrowsVfsException(): void
    {
        $this->expectException(VfsException::class);
        DfsSsd::fsFStat($this->oUser, 9999);
    }

    public function testIsEofWithInvalidHandleThrowsVfsException(): void
    {
        $this->expectException(VfsException::class);
        DfsSsd::isEof($this->oUser, 9999);
    }

    public function testSetPosWithInvalidHandleThrowsVfsException(): void
    {
        $this->expectException(VfsException::class);
        DfsSsd::setPos($this->oUser, 9999, 0);
    }

    public function testReadWithInvalidHandleThrowsVfsException(): void
    {
        $this->expectException(VfsException::class);
        DfsSsd::read($this->oUser, 9999, 10);
    }

    public function testFsCloseWithInvalidHandleIsNoOp(): void
    {
        DfsSsd::fsClose($this->oUser, 9999);
        $this->assertTrue(true);
    }
}
