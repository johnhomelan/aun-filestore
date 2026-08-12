<?php

/*
 * @group unit-tests
 *
 * Unit tests for the AdfsAdl VFS plugin.
 *
 * The plugin provides read-only access to Acorn ADFS ADL floppy disk image files
 * (.adl) stored on the local filesystem. .adl images appear as virtual
 * directories in the econet namespace.
 *
 * AdfsReader is mocked out via AdfsAdl::setImageReader() rather than reading a
 * real binary image, so the tests exercise the full catalogue-walking behaviour
 * of the plugin — including the path-resolution edge cases around an existing
 * image with a requested sub-path that does not exist inside it — without
 * needing to synthesise a valid on-disk ADFS image format.
 */

if (!defined('CONFIG_security_mode')) {
    define('CONFIG_security_mode', 'singleuser');
}
if (!defined('CONFIG_vfs_plugin_localadfsadl_root')) {
    define('CONFIG_vfs_plugin_localadfsadl_root', '/tmp/adfsadl-test-default-' . uniqid());
}

include_once(__DIR__ . '/../../src/include/system.inc.php');

use PHPUnit\Framework\TestCase;
use Monolog\Logger;
use Monolog\Handler\NullHandler;
use HomeLan\FileStore\Authentication\User;
use HomeLan\FileStore\Vfs\Plugin\AdfsAdl;
use HomeLan\FileStore\Vfs\FilePath;
use HomeLan\FileStore\Vfs\Exception as VfsException;
use HomeLan\Retro\Acorn\Disk\AdfsReader;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;

class VfsPluginAdfsAdlTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    protected User $oUser;
    protected string $sRoot;

    // -------------------------------------------------------------------------
    // Setup / Teardown
    // -------------------------------------------------------------------------

    protected function setUp(): void
    {
        $this->sRoot = sys_get_temp_dir() . '/adfsadl_test_' . uniqid() . '/';
        mkdir($this->sRoot, 0755, true);

        config::overrideValue('vfs_plugin_localadfsadl_root', rtrim($this->sRoot, '/'));

        $oLogger = new Logger('adfsadl-test');
        $oLogger->pushHandler(new NullHandler());
        AdfsAdl::reset();
        AdfsAdl::init($oLogger, false);

        $this->oUser = new User();
        $this->oUser->setUsername('adfsadluser');
        $this->oUser->setHomedir('$');
        $this->oUser->setBootOpt(0);
        $this->oUser->setUnixUid(5000);
        $this->oUser->setPriv('u');
    }

    protected function tearDown(): void
    {
        config::resetValue('vfs_plugin_localadfsadl_root');
        $this->_deleteDir($this->sRoot);
        AdfsAdl::reset();
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
     * Creates a stub *.adl file on disk and registers a mock reader for it. The
     * stub file's content is irrelevant — the mock is always used in place of a
     * real AdfsReader.
     */
    protected function _seedImage(string $sName, object $oMock): string
    {
        $sImagePath = $this->sRoot . $sName . '.adl';
        file_put_contents($sImagePath, str_repeat("\x00", 64));
        AdfsAdl::setImageReader($sImagePath, $oMock);
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
        AdfsAdl::init($oLogger, false);
        $this->assertTrue(true);
    }

    public function testHouseKeepingIsNoOp(): void
    {
        AdfsAdl::houseKeeping();
        $this->assertTrue(true);
    }

    // -------------------------------------------------------------------------
    // Access mode
    // -------------------------------------------------------------------------

    public function testGetAccessModeReturnsReadOnly(): void
    {
        $this->assertSame('-r/-r', AdfsAdl::_getAccessMode(0, 0, 0));
    }

    // -------------------------------------------------------------------------
    // getFile against a mocked image
    // -------------------------------------------------------------------------

    public function testGetFileReturnsContentsForFileInsideImage(): void
    {
        $oMock = Mockery::mock(AdfsReader::class);
        $oMock->shouldReceive('getCatalogue')->andReturn($this->_sampleCatalogue());
        $oMock->shouldReceive('getFile')->with('FILE1')->andReturn('DATA');
        $this->_seedImage('floppy0', $oMock);

        $sData = AdfsAdl::getFile($this->oUser, new FilePath('$', 'floppy0.FILE1'));
        $this->assertSame('DATA', $sData);
    }

    public function testGetFileFindsNestedFileInsideImage(): void
    {
        $oMock = Mockery::mock(AdfsReader::class);
        $oMock->shouldReceive('getCatalogue')->andReturn($this->_sampleCatalogue());
        $oMock->shouldReceive('getFile')->with('DIR1.NESTED')->andReturn('NN');
        $this->_seedImage('floppy0', $oMock);

        $sData = AdfsAdl::getFile($this->oUser, new FilePath('$.floppy0', 'DIR1.NESTED'));
        $this->assertSame('NN', $sData);
    }

    public function testGetFileThrowsWhenNoImageExists(): void
    {
        $this->expectException(VfsException::class);
        AdfsAdl::getFile($this->oUser, new FilePath('$.nonexistent', 'file'));
    }

    public function testGetFileThrowsForPathNotInCatalogue(): void
    {
        $oMock = Mockery::mock(AdfsReader::class);
        $oMock->shouldReceive('getCatalogue')->andReturn($this->_sampleCatalogue());
        $this->_seedImage('floppy0', $oMock);

        $this->expectException(VfsException::class);
        AdfsAdl::getFile($this->oUser, new FilePath('$', 'floppy0.NOSUCHFILE'));
    }

    // -------------------------------------------------------------------------
    // getDirectoryListing
    // -------------------------------------------------------------------------

    public function testGetDirectoryListingReturnsCatalogueEntries(): void
    {
        $oMock = Mockery::mock(AdfsReader::class);
        $oMock->shouldReceive('getCatalogue')->andReturn($this->_sampleCatalogue());
        $this->_seedImage('floppy0', $oMock);

        $aListing = AdfsAdl::getDirectoryListing('$.floppy0', []);

        $this->assertArrayHasKey('FILE1', $aListing);
        $this->assertFalse($aListing['FILE1']->isDir());
        $this->assertSame(4, $aListing['FILE1']->getSize());

        $this->assertArrayHasKey('DIR1', $aListing);
        $this->assertTrue($aListing['DIR1']->isDir());
    }

    public function testGetDirectoryListingDescendsIntoSubdirectory(): void
    {
        $oMock = Mockery::mock(AdfsReader::class);
        $oMock->shouldReceive('getCatalogue')->andReturn($this->_sampleCatalogue());
        $this->_seedImage('floppy0', $oMock);

        $aListing = AdfsAdl::getDirectoryListing('$.floppy0.DIR1', []);

        $this->assertArrayHasKey('NESTED', $aListing);
        $this->assertSame(0x1900, $aListing['NESTED']->getLoadAddr());
        $this->assertSame(0x8023, $aListing['NESTED']->getExecAddr());
    }

    public function testGetDirectoryListingReturnsOriginalArrayWhenNoImages(): void
    {
        $aExisting = ['prior' => 'entry'];
        $aResult   = AdfsAdl::getDirectoryListing('$', $aExisting);
        $this->assertArrayHasKey('prior', $aResult);
    }

    public function testGetDirectoryListingWithNoAdlFilesReturnsEmptyForBlankRoot(): void
    {
        $aResult = AdfsAdl::getDirectoryListing('$', []);
        $this->assertEmpty($aResult);
    }

    public function testGetDirectoryListingRootShowsAdlFileAsVirtualDirectory(): void
    {
        file_put_contents($this->sRoot . 'drive0.adl', str_repeat("\x00", 256));

        $aListing = AdfsAdl::getDirectoryListing('$', []);

        $this->assertArrayHasKey('drive0.adl', $aListing);
        $this->assertTrue($aListing['drive0.adl']->isDir());
        $this->assertSame('drive0', $aListing['drive0.adl']->getEconetName());
    }

    public function testGetDirectoryListingPreservesExistingEntries(): void
    {
        file_put_contents($this->sRoot . 'drive0.adl', str_repeat("\x00", 256));

        $aExisting = ['prior' => 'value'];
        $aResult   = AdfsAdl::getDirectoryListing('$', $aExisting);

        $this->assertArrayHasKey('prior', $aResult);
        $this->assertArrayHasKey('drive0.adl', $aResult);
    }

    // -------------------------------------------------------------------------
    // _buildFiledescriptorFromEconetPath
    // -------------------------------------------------------------------------

    public function testBuildFiledescriptorThrowsForNonExistentPath(): void
    {
        $this->expectException(VfsException::class);
        AdfsAdl::_buildFiledescriptorFromEconetPath(
            $this->oUser, new FilePath('$.nonexistent', 'file'), true, true
        );
    }

    public function testBuildFiledescriptorForImageRootIsADirectoryHandle(): void
    {
        $oMock = Mockery::mock(AdfsReader::class);
        $this->_seedImage('floppy0', $oMock);

        $oFd = AdfsAdl::_buildFiledescriptorFromEconetPath(
            $this->oUser, new FilePath('$', 'floppy0'), true, true
        );

        $this->assertTrue($oFd->isDir());
        $this->assertFalse($oFd->isFile());
    }

    public function testBuildFiledescriptorForFileInsideImage(): void
    {
        $oMock = Mockery::mock(AdfsReader::class);
        $oMock->shouldReceive('getCatalogue')->andReturn($this->_sampleCatalogue());
        $oMock->shouldReceive('isFile')->with('FILE1')->andReturn(true);
        $oMock->shouldReceive('isDir')->with('FILE1')->andReturn(false);
        $this->_seedImage('floppy0', $oMock);

        $oFd = AdfsAdl::_buildFiledescriptorFromEconetPath(
            $this->oUser, new FilePath('$', 'floppy0.FILE1'), true, true
        );

        $this->assertTrue($oFd->isFile());
        $this->assertFalse($oFd->isDir());
    }

    public function testBuildFiledescriptorForDirectoryInsideImage(): void
    {
        $oMock = Mockery::mock(AdfsReader::class);
        $oMock->shouldReceive('getCatalogue')->andReturn($this->_sampleCatalogue());
        $oMock->shouldReceive('isFile')->with('DIR1')->andReturn(false);
        $oMock->shouldReceive('isDir')->with('DIR1')->andReturn(true);
        $this->_seedImage('floppy0', $oMock);

        $oFd = AdfsAdl::_buildFiledescriptorFromEconetPath(
            $this->oUser, new FilePath('$.floppy0', 'DIR1'), true, true
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
        $oMock = Mockery::mock(AdfsReader::class);
        $oMock->shouldReceive('getCatalogue')->andReturn($this->_sampleCatalogue());
        $this->_seedImage('floppy0', $oMock);

        $this->expectException(VfsException::class);
        AdfsAdl::_buildFiledescriptorFromEconetPath(
            $this->oUser, new FilePath('$', 'floppy0.NOSUCHFILE'), true, true
        );
    }

    public function testBuildFiledescriptorThrowsForNonExistentNestedPathInsideImage(): void
    {
        $oMock = Mockery::mock(AdfsReader::class);
        $oMock->shouldReceive('getCatalogue')->andReturn($this->_sampleCatalogue());
        $this->_seedImage('floppy0', $oMock);

        $this->expectException(VfsException::class);
        AdfsAdl::_buildFiledescriptorFromEconetPath(
            $this->oUser, new FilePath('$.floppy0', 'DIR1.NOSUCHFILE'), true, true
        );
    }

    // -------------------------------------------------------------------------
    // Handle-based read I/O
    // -------------------------------------------------------------------------

    public function testReadReturnsDataAtCurrentPosition(): void
    {
        $oMock = Mockery::mock(AdfsReader::class);
        $oMock->shouldReceive('getCatalogue')->andReturn($this->_sampleCatalogue());
        $oMock->shouldReceive('isFile')->with('FILE1')->andReturn(true);
        $oMock->shouldReceive('isDir')->with('FILE1')->andReturn(false);
        $oMock->shouldReceive('getFile')->with('FILE1')->andReturn('DATA');
        $this->_seedImage('floppy0', $oMock);

        AdfsAdl::_buildFiledescriptorFromEconetPath($this->oUser, new FilePath('$', 'floppy0.FILE1'), true, true);

        $this->assertSame('DA', AdfsAdl::read($this->oUser, 0, 2));
        AdfsAdl::setPos($this->oUser, 0, 2);
        $this->assertSame('TA', AdfsAdl::read($this->oUser, 0, 2));
    }

    public function testSetPosAndFsFtell(): void
    {
        $oMock = Mockery::mock(AdfsReader::class);
        $this->_seedImage('floppy0', $oMock);

        $oFd = AdfsAdl::_buildFiledescriptorFromEconetPath($this->oUser, new FilePath('$', 'floppy0'), true, true);
        $oFd->setPos(42);
        $this->assertSame(42, $oFd->fsFTell());
    }

    public function testFsFStatReturnsSizeAndSector(): void
    {
        $oMock = Mockery::mock(AdfsReader::class);
        $oMock->shouldReceive('getCatalogue')->andReturn($this->_sampleCatalogue());
        $oMock->shouldReceive('isFile')->with('FILE1')->andReturn(true);
        $oMock->shouldReceive('isDir')->with('FILE1')->andReturn(false);
        $oMock->shouldReceive('getStat')->with('FILE1')->andReturn(['size' => 4, 'sector' => 10]);
        $this->_seedImage('floppy0', $oMock);

        AdfsAdl::_buildFiledescriptorFromEconetPath($this->oUser, new FilePath('$', 'floppy0.FILE1'), true, true);

        $aStat = AdfsAdl::fsFStat($this->oUser, 0);
        $this->assertSame(4, $aStat['size']);
        $this->assertSame(10, $aStat['ino']);
    }

    public function testFsCloseRemovesHandle(): void
    {
        $oMock = Mockery::mock(AdfsReader::class);
        $this->_seedImage('floppy0', $oMock);

        $oFd = AdfsAdl::_buildFiledescriptorFromEconetPath($this->oUser, new FilePath('$', 'floppy0'), true, true);
        $oFd->close();

        $this->expectException(VfsException::class);
        AdfsAdl::fsFtell($this->oUser, 0);
    }

    // -------------------------------------------------------------------------
    // Mutating operations — all return false (read-only plugin)
    // -------------------------------------------------------------------------

    public function testCreateDirectoryReturnsFalse(): void
    {
        $this->assertFalse(AdfsAdl::createDirectory($this->oUser, new FilePath('$', 'newdir')));
    }

    public function testDeleteFileReturnsFalse(): void
    {
        $this->assertFalse(AdfsAdl::deleteFile($this->oUser, new FilePath('$', 'somefile')));
    }

    public function testMoveFileReturnsFalse(): void
    {
        $this->assertFalse(
            AdfsAdl::moveFile($this->oUser, new FilePath('$', 'src'), new FilePath('$', 'dst'))
        );
    }

    public function testSaveFileIsNoOp(): void
    {
        AdfsAdl::saveFile($this->oUser, new FilePath('$', 'f'), 'data', 0, 0);
        $this->assertTrue(true);
    }

    public function testCreateFileIsNoOp(): void
    {
        AdfsAdl::createFile($this->oUser, new FilePath('$', 'f'), 10, 0, 0);
        $this->assertTrue(true);
    }

    public function testSetMetaIsNoOp(): void
    {
        AdfsAdl::setMeta('$.f', 0, 0, 0);
        $this->assertTrue(true);
    }

    // -------------------------------------------------------------------------
    // Locking — no-ops
    // -------------------------------------------------------------------------

    public function testFsLockIsNoOp(): void
    {
        AdfsAdl::fsLock($this->oUser, 0, true);
        AdfsAdl::fsLock($this->oUser, 0, false);
        $this->assertTrue(true);
    }

    public function testFsUnlockIsNoOp(): void
    {
        AdfsAdl::fsUnlock($this->oUser, 0);
        $this->assertTrue(true);
    }

    // -------------------------------------------------------------------------
    // Write / setExt — throw VfsException (truly read-only)
    // -------------------------------------------------------------------------

    public function testWriteThrowsVfsException(): void
    {
        $this->expectException(VfsException::class);
        AdfsAdl::write($this->oUser, 9999, 'data');
    }

    public function testSetExtThrowsVfsException(): void
    {
        $this->expectException(VfsException::class);
        AdfsAdl::setExt($this->oUser, 9999, 0);
    }

    // -------------------------------------------------------------------------
    // Invalid-handle error paths
    // -------------------------------------------------------------------------

    public function testFsFtellWithInvalidHandleThrowsVfsException(): void
    {
        $this->expectException(VfsException::class);
        AdfsAdl::fsFtell($this->oUser, 9999);
    }

    public function testFsFStatWithInvalidHandleThrowsVfsException(): void
    {
        $this->expectException(VfsException::class);
        AdfsAdl::fsFStat($this->oUser, 9999);
    }

    public function testIsEofWithInvalidHandleThrowsVfsException(): void
    {
        $this->expectException(VfsException::class);
        AdfsAdl::isEof($this->oUser, 9999);
    }

    public function testSetPosWithInvalidHandleThrowsVfsException(): void
    {
        $this->expectException(VfsException::class);
        AdfsAdl::setPos($this->oUser, 9999, 0);
    }

    public function testReadWithInvalidHandleThrowsVfsException(): void
    {
        $this->expectException(VfsException::class);
        AdfsAdl::read($this->oUser, 9999, 10);
    }

    public function testFsCloseWithInvalidHandleIsNoOp(): void
    {
        AdfsAdl::fsClose($this->oUser, 9999);
        $this->assertTrue(true);
    }
}
