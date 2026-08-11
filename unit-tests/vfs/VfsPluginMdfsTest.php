<?php

/*
 * @group unit-tests
 *
 * Unit tests for the Mdfs VFS plugin.
 *
 * The plugin provides access to SJ Research MDFS floppy/hard-disk images (*.mdfs) and
 * HDFS hard-disk images (*.hdfs) via the homelan/mdfs-disk-reader package. It is
 * read-only unless vfs_plugin_mdfs_write_enabled is set.
 *
 * MdfsReader/MdfsWriter are mocked out via Mdfs::setImageReader() rather than reading
 * real binary images, so the tests exercise the full catalogue-walking and read/write
 * behaviour of the plugin without needing to synthesise a valid on-disk image format.
 */

if (!defined('CONFIG_security_mode')) {
    define('CONFIG_security_mode', 'singleuser');
}
if (!defined('CONFIG_vfs_plugin_mdfs_root')) {
    define('CONFIG_vfs_plugin_mdfs_root', '/tmp/mdfs-test-default-' . uniqid());
}

include_once(__DIR__ . '/../../src/include/system.inc.php');

use PHPUnit\Framework\TestCase;
use Monolog\Logger;
use Monolog\Handler\NullHandler;
use HomeLan\FileStore\Authentication\User;
use HomeLan\FileStore\Vfs\Plugin\Mdfs;
use HomeLan\FileStore\Vfs\FilePath;
use HomeLan\FileStore\Vfs\Exception as VfsException;
use HomeLan\Retro\Acorn\Disk\MdfsReader;
use HomeLan\Retro\Acorn\Disk\MdfsWriter;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;

class VfsPluginMdfsTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    protected User $oUser;
    protected string $sRoot;

    // -------------------------------------------------------------------------
    // Setup / Teardown
    // -------------------------------------------------------------------------

    protected function setUp(): void
    {
        $this->sRoot = sys_get_temp_dir() . '/mdfs_test_' . uniqid() . '/';
        mkdir($this->sRoot, 0755, true);

        config::overrideValue('vfs_plugin_mdfs_root', rtrim($this->sRoot, '/'));
        config::overrideValue('vfs_plugin_mdfs_write_enabled', 0);

        $oLogger = new Logger('mdfs-test');
        $oLogger->pushHandler(new NullHandler());
        Mdfs::reset();
        Mdfs::init($oLogger, false);

        $this->oUser = new User();
        $this->oUser->setUsername('mdfsuser');
        $this->oUser->setHomedir('$');
        $this->oUser->setBootOpt(0);
        $this->oUser->setUnixUid(5000);
        $this->oUser->setPriv('u');
    }

    protected function tearDown(): void
    {
        config::resetValue('vfs_plugin_mdfs_root');
        config::resetValue('vfs_plugin_mdfs_write_enabled');
        $this->_deleteDir($this->sRoot);
        Mdfs::reset();
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
     * Creates a stub *.mdfs (or *.hdfs) file on disk and registers a mock reader
     * for it. The stub file's content is irrelevant — the mock is always used in
     * place of a real MdfsReader/MdfsWriter.
     */
    protected function _seedImage(string $sName, string $sExt, object $oMock): string
    {
        $sImagePath = $this->sRoot . $sName . '.' . $sExt;
        file_put_contents($sImagePath, str_repeat("\x00", 64));
        Mdfs::setImageReader($sImagePath, $oMock);
        return $sImagePath;
    }

    protected function _sampleCatalogue(): array
    {
        return [
            'FILE1' => ['type' => 'file', 'load' => 0xFFFF0E00, 'exec' => 0xFFFF0E00, 'size' => 4],
            'DIR1'  => [
                'type' => 'dir', 'load' => 0, 'exec' => 0, 'size' => 0,
                'dir'  => [
                    'NESTED' => ['type' => 'file', 'load' => 0x1900, 'exec' => 0x8023, 'size' => 2],
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
        Mdfs::init($oLogger, false);
        $this->assertTrue(true);
    }

    public function testHouseKeepingIsNoOp(): void
    {
        Mdfs::houseKeeping();
        $this->assertTrue(true);
    }

    // -------------------------------------------------------------------------
    // Access mode
    // -------------------------------------------------------------------------

    public function testGetAccessModeIsReadOnlyByDefault(): void
    {
        $this->assertSame('-r/-r', Mdfs::_getAccessMode(0, 0, 0));
    }

    public function testGetAccessModeIsReadWriteWhenEnabled(): void
    {
        config::overrideValue('vfs_plugin_mdfs_write_enabled', 1);
        Mdfs::init($this->_nullLogger(), false);
        $this->assertSame('wr/wr', Mdfs::_getAccessMode(0, 0, 0));
    }

    protected function _nullLogger(): Logger
    {
        $oLogger = new Logger('test');
        $oLogger->pushHandler(new NullHandler());
        return $oLogger;
    }

    // -------------------------------------------------------------------------
    // getFile / getDirectoryListing against a mocked image
    // -------------------------------------------------------------------------

    public function testGetFileReturnsContentsForFileInsideImage(): void
    {
        $oMock = Mockery::mock(MdfsReader::class);
        $oMock->shouldReceive('getCatalogue')->andReturn($this->_sampleCatalogue());
        $oMock->shouldReceive('getFile')->with('FILE1')->andReturn('DATA');
        $this->_seedImage('disk0', 'mdfs', $oMock);

        $sData = Mdfs::getFile($this->oUser, new FilePath('$', 'disk0.FILE1'));
        $this->assertSame('DATA', $sData);
    }

    public function testGetFileFindsNestedFileInsideImage(): void
    {
        $oMock = Mockery::mock(MdfsReader::class);
        $oMock->shouldReceive('getCatalogue')->andReturn($this->_sampleCatalogue());
        $oMock->shouldReceive('getFile')->with('DIR1.NESTED')->andReturn('NN');
        $this->_seedImage('disk0', 'mdfs', $oMock);

        $sData = Mdfs::getFile($this->oUser, new FilePath('$.disk0', 'DIR1.NESTED'));
        $this->assertSame('NN', $sData);
    }

    public function testGetFileThrowsWhenNoImageExists(): void
    {
        $this->expectException(VfsException::class);
        Mdfs::getFile($this->oUser, new FilePath('$.nonexistent', 'file'));
    }

    public function testGetFileThrowsForPathNotInCatalogue(): void
    {
        $oMock = Mockery::mock(MdfsReader::class);
        $oMock->shouldReceive('getCatalogue')->andReturn($this->_sampleCatalogue());
        $this->_seedImage('disk0', 'mdfs', $oMock);

        $this->expectException(VfsException::class);
        Mdfs::getFile($this->oUser, new FilePath('$', 'disk0.NOSUCHFILE'));
    }

    public function testGetFileThrowsWhenPathIsADirectory(): void
    {
        $oMock = Mockery::mock(MdfsReader::class);
        $oMock->shouldReceive('getCatalogue')->andReturn($this->_sampleCatalogue());
        $this->_seedImage('disk0', 'mdfs', $oMock);

        $this->expectException(VfsException::class);
        Mdfs::getFile($this->oUser, new FilePath('$', 'disk0.DIR1'));
    }

    public function testGetDirectoryListingReturnsCatalogueEntries(): void
    {
        $oMock = Mockery::mock(MdfsReader::class);
        $oMock->shouldReceive('getCatalogue')->andReturn($this->_sampleCatalogue());
        $this->_seedImage('disk0', 'mdfs', $oMock);

        $aListing = Mdfs::getDirectoryListing('$.disk0', []);

        $this->assertArrayHasKey('FILE1', $aListing);
        $this->assertFalse($aListing['FILE1']->isDir());
        $this->assertSame(4, $aListing['FILE1']->getSize());

        $this->assertArrayHasKey('DIR1', $aListing);
        $this->assertTrue($aListing['DIR1']->isDir());
    }

    public function testGetDirectoryListingDescendsIntoSubdirectory(): void
    {
        $oMock = Mockery::mock(MdfsReader::class);
        $oMock->shouldReceive('getCatalogue')->andReturn($this->_sampleCatalogue());
        $this->_seedImage('disk0', 'mdfs', $oMock);

        $aListing = Mdfs::getDirectoryListing('$.disk0.DIR1', []);

        $this->assertArrayHasKey('NESTED', $aListing);
        $this->assertSame(0x1900, $aListing['NESTED']->getLoadAddr());
        $this->assertSame(0x8023, $aListing['NESTED']->getExecAddr());
    }

    public function testGetDirectoryListingReturnsOriginalArrayWhenNoImages(): void
    {
        $aExisting = ['prior' => 'entry'];
        $aResult   = Mdfs::getDirectoryListing('$', $aExisting);
        $this->assertArrayHasKey('prior', $aResult);
    }

    public function testGetDirectoryListingRootShowsImageFileAsVirtualDirectory(): void
    {
        file_put_contents($this->sRoot . 'drive0.mdfs', str_repeat("\x00", 64));

        $aListing = Mdfs::getDirectoryListing('$', []);

        $this->assertArrayHasKey('drive0.mdfs', $aListing);
        $this->assertTrue($aListing['drive0.mdfs']->isDir());
        $this->assertSame('drive0', $aListing['drive0.mdfs']->getEconetName());
    }

    public function testGetDirectoryListingRootShowsHdfsFileAsVirtualDirectory(): void
    {
        file_put_contents($this->sRoot . 'winchester0.hdfs', str_repeat("\x00", 64));

        $aListing = Mdfs::getDirectoryListing('$', []);

        $this->assertArrayHasKey('winchester0.hdfs', $aListing);
        $this->assertSame('winchester0', $aListing['winchester0.hdfs']->getEconetName());
    }

    // -------------------------------------------------------------------------
    // _buildFiledescriptorFromEconetPath
    // -------------------------------------------------------------------------

    public function testBuildFiledescriptorThrowsForNonExistentPath(): void
    {
        $this->expectException(VfsException::class);
        Mdfs::_buildFiledescriptorFromEconetPath(
            $this->oUser, new FilePath('$.nonexistent', 'file'), true, true
        );
    }

    public function testBuildFiledescriptorForImageRootIsADirectoryHandle(): void
    {
        $oMock = Mockery::mock(MdfsReader::class);
        $this->_seedImage('disk0', 'mdfs', $oMock);

        $oFd = Mdfs::_buildFiledescriptorFromEconetPath(
            $this->oUser, new FilePath('$', 'disk0'), true, true
        );

        $this->assertTrue($oFd->isDir());
        $this->assertFalse($oFd->isFile());
    }

    public function testBuildFiledescriptorForFileInsideImage(): void
    {
        $oMock = Mockery::mock(MdfsReader::class);
        $oMock->shouldReceive('getCatalogue')->andReturn($this->_sampleCatalogue());
        $oMock->shouldReceive('getFile')->with('FILE1')->andReturn('DATA');
        $this->_seedImage('disk0', 'mdfs', $oMock);

        $oFd = Mdfs::_buildFiledescriptorFromEconetPath(
            $this->oUser, new FilePath('$', 'disk0.FILE1'), true, true
        );

        $this->assertTrue($oFd->isFile());
        $this->assertFalse($oFd->isDir());
    }

    public function testBuildFiledescriptorForDirectoryInsideImage(): void
    {
        $oMock = Mockery::mock(MdfsReader::class);
        $oMock->shouldReceive('getCatalogue')->andReturn($this->_sampleCatalogue());
        $this->_seedImage('disk0', 'mdfs', $oMock);

        $oFd = Mdfs::_buildFiledescriptorFromEconetPath(
            $this->oUser, new FilePath('$.disk0', 'DIR1'), true, true
        );

        $this->assertTrue($oFd->isDir());
        $this->assertFalse($oFd->isFile());
    }

    public function testBuildFiledescriptorForNewFileFailsWhenWriteDisabled(): void
    {
        $oMock = Mockery::mock(MdfsReader::class);
        $oMock->shouldReceive('getCatalogue')->andReturn($this->_sampleCatalogue());
        $this->_seedImage('disk0', 'mdfs', $oMock);

        $this->expectException(VfsException::class);
        Mdfs::_buildFiledescriptorFromEconetPath(
            $this->oUser, new FilePath('$', 'disk0.NEWFILE'), false, false
        );
    }

    public function testBuildFiledescriptorForNewFileSucceedsWhenWriteEnabled(): void
    {
        config::overrideValue('vfs_plugin_mdfs_write_enabled', 1);
        Mdfs::init($this->_nullLogger(), false);

        $oMock = Mockery::mock(MdfsWriter::class);
        $oMock->shouldReceive('getCatalogue')->andReturn($this->_sampleCatalogue());
        $this->_seedImage('disk0', 'mdfs', $oMock);

        $oFd = Mdfs::_buildFiledescriptorFromEconetPath(
            $this->oUser, new FilePath('$', 'disk0.NEWFILE'), false, false
        );

        $this->assertFalse($oFd->isFile());
        $this->assertFalse($oFd->isDir());
    }

    // -------------------------------------------------------------------------
    // Handle-based read I/O
    // -------------------------------------------------------------------------

    public function testReadReturnsDataInChunks(): void
    {
        $oMock = Mockery::mock(MdfsReader::class);
        $oMock->shouldReceive('getCatalogue')->andReturn($this->_sampleCatalogue());
        $oMock->shouldReceive('getFile')->with('FILE1')->andReturn('DATA');
        $this->_seedImage('disk0', 'mdfs', $oMock);

        Mdfs::_buildFiledescriptorFromEconetPath($this->oUser, new FilePath('$', 'disk0.FILE1'), true, true);

        $this->assertSame('DA', Mdfs::read($this->oUser, 0, 2));
        $this->assertSame('TA', Mdfs::read($this->oUser, 0, 2));
        $this->assertTrue(Mdfs::isEof($this->oUser, 0));
    }

    public function testSetPosAndFsFtell(): void
    {
        $oMock = Mockery::mock(MdfsReader::class);
        $oMock->shouldReceive('getCatalogue')->andReturn($this->_sampleCatalogue());
        $oMock->shouldReceive('getFile')->with('FILE1')->andReturn('DATA');
        $this->_seedImage('disk0', 'mdfs', $oMock);

        Mdfs::_buildFiledescriptorFromEconetPath($this->oUser, new FilePath('$', 'disk0.FILE1'), true, true);

        Mdfs::setPos($this->oUser, 0, 2);
        $this->assertSame(2, Mdfs::fsFtell($this->oUser, 0));
        $this->assertSame('TA', Mdfs::read($this->oUser, 0, 10));
    }

    public function testFsFStatReturnsSize(): void
    {
        $oMock = Mockery::mock(MdfsReader::class);
        $oMock->shouldReceive('getCatalogue')->andReturn($this->_sampleCatalogue());
        $oMock->shouldReceive('getFile')->with('FILE1')->andReturn('DATA');
        $this->_seedImage('disk0', 'mdfs', $oMock);

        Mdfs::_buildFiledescriptorFromEconetPath($this->oUser, new FilePath('$', 'disk0.FILE1'), true, true);

        $aStat = Mdfs::fsFStat($this->oUser, 0);
        $this->assertSame(4, $aStat['size']);
    }

    // -------------------------------------------------------------------------
    // Write / setExt — read-only by default
    // -------------------------------------------------------------------------

    public function testWriteThrowsWhenWriteDisabled(): void
    {
        $oMock = Mockery::mock(MdfsReader::class);
        $oMock->shouldReceive('getCatalogue')->andReturn($this->_sampleCatalogue());
        $oMock->shouldReceive('getFile')->with('FILE1')->andReturn('DATA');
        $this->_seedImage('disk0', 'mdfs', $oMock);

        Mdfs::_buildFiledescriptorFromEconetPath($this->oUser, new FilePath('$', 'disk0.FILE1'), true, true);

        $this->expectException(VfsException::class);
        Mdfs::write($this->oUser, 0, 'X');
    }

    public function testSetExtThrowsWhenWriteDisabled(): void
    {
        $oMock = Mockery::mock(MdfsReader::class);
        $oMock->shouldReceive('getCatalogue')->andReturn($this->_sampleCatalogue());
        $oMock->shouldReceive('getFile')->with('FILE1')->andReturn('DATA');
        $this->_seedImage('disk0', 'mdfs', $oMock);

        Mdfs::_buildFiledescriptorFromEconetPath($this->oUser, new FilePath('$', 'disk0.FILE1'), true, true);

        $this->expectException(VfsException::class);
        Mdfs::setExt($this->oUser, 0, 10);
    }

    // -------------------------------------------------------------------------
    // Write handle I/O — write-enabled
    // -------------------------------------------------------------------------

    public function testWriteHandleBuffersDataAndFlushesOnClose(): void
    {
        config::overrideValue('vfs_plugin_mdfs_write_enabled', 1);
        Mdfs::init($this->_nullLogger(), false);

        $oMock = Mockery::mock(MdfsWriter::class);
        $oMock->shouldReceive('getCatalogue')->andReturn($this->_sampleCatalogue());
        $oMock->shouldReceive('getFile')->with('FILE1')->andReturn('DATA');
        $this->_seedImage('disk0', 'mdfs', $oMock);

        Mdfs::_buildFiledescriptorFromEconetPath($this->oUser, new FilePath('$', 'disk0.FILE1'), true, false);

        Mdfs::setPos($this->oUser, 0, 0);
        Mdfs::write($this->oUser, 0, 'NEWDATA');

        $oMock->shouldReceive('writeFile')->once()->with('FILE1', 'NEWDATA', 0xFFFF0E00, 0xFFFF0E00, Mockery::any());

        Mdfs::fsClose($this->oUser, 0);
    }

    public function testSetExtTruncatesData(): void
    {
        config::overrideValue('vfs_plugin_mdfs_write_enabled', 1);
        Mdfs::init($this->_nullLogger(), false);

        $oMock = Mockery::mock(MdfsWriter::class);
        $oMock->shouldReceive('getCatalogue')->andReturn($this->_sampleCatalogue());
        $oMock->shouldReceive('getFile')->with('FILE1')->andReturn('DATA');
        $this->_seedImage('disk0', 'mdfs', $oMock);

        Mdfs::_buildFiledescriptorFromEconetPath($this->oUser, new FilePath('$', 'disk0.FILE1'), true, false);

        Mdfs::setExt($this->oUser, 0, 2);
        $aStat = Mdfs::fsFStat($this->oUser, 0);
        $this->assertSame(2, $aStat['size']);
    }

    // -------------------------------------------------------------------------
    // Mutating whole-file operations — read-only by default
    // -------------------------------------------------------------------------

    public function testCreateDirectoryReturnsFalseWhenWriteDisabled(): void
    {
        $this->assertFalse(Mdfs::createDirectory($this->oUser, new FilePath('$', 'newdir')));
    }

    public function testDeleteFileReturnsFalseWhenWriteDisabled(): void
    {
        $this->assertFalse(Mdfs::deleteFile($this->oUser, new FilePath('$', 'somefile')));
    }

    public function testMoveFileReturnsFalseWhenWriteDisabled(): void
    {
        $this->assertFalse(Mdfs::moveFile($this->oUser, new FilePath('$', 'src'), new FilePath('$', 'dst')));
    }

    public function testSaveFileReturnsFalseWhenWriteDisabled(): void
    {
        $this->assertFalse(Mdfs::saveFile($this->oUser, new FilePath('$', 'f'), 'data', 0, 0));
    }

    public function testCreateFileReturnsFalseWhenWriteDisabled(): void
    {
        $this->assertFalse(Mdfs::createFile($this->oUser, new FilePath('$', 'f'), 10, 0, 0));
    }

    public function testSetMetaIsNoOpWhenWriteDisabled(): void
    {
        Mdfs::setMeta('$.disk0.FILE1', 0, 0, 0);
        $this->assertTrue(true);
    }

    // -------------------------------------------------------------------------
    // Mutating whole-file operations — write-enabled
    // -------------------------------------------------------------------------

    public function testSaveFileWritesThroughToWriter(): void
    {
        config::overrideValue('vfs_plugin_mdfs_write_enabled', 1);
        Mdfs::init($this->_nullLogger(), false);

        $oMock = Mockery::mock(MdfsWriter::class);
        $this->_seedImage('disk0', 'mdfs', $oMock);

        $oMock->shouldReceive('writeFile')->once()->with('FILE1', 'HELLO', 0x1900, 0x8023);

        $this->assertTrue(Mdfs::saveFile($this->oUser, new FilePath('$', 'disk0.FILE1'), 'HELLO', 0x1900, 0x8023));
    }

    public function testSaveFileReturnsFalseWhenNoImageMatches(): void
    {
        config::overrideValue('vfs_plugin_mdfs_write_enabled', 1);
        Mdfs::init($this->_nullLogger(), false);

        $this->assertFalse(Mdfs::saveFile($this->oUser, new FilePath('$.nonexistent', 'f'), 'data', 0, 0));
    }

    public function testCreateFileWritesZeroFilledData(): void
    {
        config::overrideValue('vfs_plugin_mdfs_write_enabled', 1);
        Mdfs::init($this->_nullLogger(), false);

        $oMock = Mockery::mock(MdfsWriter::class);
        $this->_seedImage('disk0', 'mdfs', $oMock);

        $oMock->shouldReceive('writeFile')->once()->with('NEWFILE', str_repeat("\x00", 5), 0, 0);

        $this->assertTrue(Mdfs::createFile($this->oUser, new FilePath('$', 'disk0.NEWFILE'), 5, 0, 0));
    }

    public function testDeleteFileCallsWriterDeleteFile(): void
    {
        config::overrideValue('vfs_plugin_mdfs_write_enabled', 1);
        Mdfs::init($this->_nullLogger(), false);

        $oMock = Mockery::mock(MdfsWriter::class);
        $this->_seedImage('disk0', 'mdfs', $oMock);

        $oMock->shouldReceive('isDir')->with('FILE1')->andReturn(false);
        $oMock->shouldReceive('deleteFile')->once()->with('FILE1');

        $this->assertTrue(Mdfs::deleteFile($this->oUser, new FilePath('$', 'disk0.FILE1')));
    }

    public function testDeleteFileCallsWriterDeleteDirForDirectories(): void
    {
        config::overrideValue('vfs_plugin_mdfs_write_enabled', 1);
        Mdfs::init($this->_nullLogger(), false);

        $oMock = Mockery::mock(MdfsWriter::class);
        $this->_seedImage('disk0', 'mdfs', $oMock);

        $oMock->shouldReceive('isDir')->with('DIR1')->andReturn(true);
        $oMock->shouldReceive('deleteDir')->once()->with('DIR1');

        $this->assertTrue(Mdfs::deleteFile($this->oUser, new FilePath('$', 'disk0.DIR1')));
    }

    public function testCreateDirectoryCallsWriterCreateDir(): void
    {
        config::overrideValue('vfs_plugin_mdfs_write_enabled', 1);
        Mdfs::init($this->_nullLogger(), false);

        $oMock = Mockery::mock(MdfsWriter::class);
        $this->_seedImage('disk0', 'mdfs', $oMock);

        $oMock->shouldReceive('createDir')->once()->with('NEWDIR');

        $this->assertTrue(Mdfs::createDirectory($this->oUser, new FilePath('$', 'disk0.NEWDIR')));
    }

    public function testMoveFileWithinSameImage(): void
    {
        config::overrideValue('vfs_plugin_mdfs_write_enabled', 1);
        Mdfs::init($this->_nullLogger(), false);

        $oMock = Mockery::mock(MdfsWriter::class);
        $this->_seedImage('disk0', 'mdfs', $oMock);

        $oMock->shouldReceive('isFile')->with('OLD')->andReturn(true);
        $oMock->shouldReceive('getCatalogue')->andReturn($this->_sampleCatalogue());
        $oMock->shouldReceive('getFile')->with('OLD')->andReturn('DATA');
        $oMock->shouldReceive('writeFile')->once()->with('NEW', 'DATA', Mockery::any(), Mockery::any(), Mockery::any());
        $oMock->shouldReceive('deleteFile')->once()->with('OLD');

        $this->assertTrue(Mdfs::moveFile($this->oUser, new FilePath('$', 'disk0.OLD'), new FilePath('$', 'disk0.NEW')));
    }

    public function testMoveFileAcrossDifferentImagesReturnsFalse(): void
    {
        config::overrideValue('vfs_plugin_mdfs_write_enabled', 1);
        Mdfs::init($this->_nullLogger(), false);

        $oMock1 = Mockery::mock(MdfsWriter::class);
        $oMock2 = Mockery::mock(MdfsWriter::class);
        $this->_seedImage('disk0', 'mdfs', $oMock1);
        $this->_seedImage('disk1', 'mdfs', $oMock2);

        $this->assertFalse(Mdfs::moveFile($this->oUser, new FilePath('$', 'disk0.OLD'), new FilePath('$', 'disk1.NEW')));
    }

    public function testSetMetaUpdatesLoadExecAndAccess(): void
    {
        config::overrideValue('vfs_plugin_mdfs_write_enabled', 1);
        Mdfs::init($this->_nullLogger(), false);

        $oMock = Mockery::mock(MdfsWriter::class);
        $this->_seedImage('disk0', 'mdfs', $oMock);

        $oMock->shouldReceive('getCatalogue')->andReturn($this->_sampleCatalogue());
        $oMock->shouldReceive('setLoadExec')->once()->with('FILE1', 0x2000, 0x2001);
        $oMock->shouldReceive('setAccess')->once()->with('FILE1', 0x0C);

        Mdfs::setMeta('$.disk0.FILE1', 0x2000, 0x2001, 0x0C);
    }

    // -------------------------------------------------------------------------
    // Locking — no-ops
    // -------------------------------------------------------------------------

    public function testFsLockIsNoOp(): void
    {
        Mdfs::fsLock($this->oUser, 0, true);
        Mdfs::fsLock($this->oUser, 0, false);
        $this->assertTrue(true);
    }

    public function testFsUnlockIsNoOp(): void
    {
        Mdfs::fsUnlock($this->oUser, 0);
        $this->assertTrue(true);
    }

    // -------------------------------------------------------------------------
    // Invalid-handle error paths
    // -------------------------------------------------------------------------

    public function testFsFtellWithInvalidHandleThrowsVfsException(): void
    {
        $this->expectException(VfsException::class);
        Mdfs::fsFtell($this->oUser, 9999);
    }

    public function testFsFStatWithInvalidHandleThrowsVfsException(): void
    {
        $this->expectException(VfsException::class);
        Mdfs::fsFStat($this->oUser, 9999);
    }

    public function testIsEofWithInvalidHandleThrowsVfsException(): void
    {
        $this->expectException(VfsException::class);
        Mdfs::isEof($this->oUser, 9999);
    }

    public function testSetPosWithInvalidHandleThrowsVfsException(): void
    {
        $this->expectException(VfsException::class);
        Mdfs::setPos($this->oUser, 9999, 0);
    }

    public function testReadWithInvalidHandleThrowsVfsException(): void
    {
        $this->expectException(VfsException::class);
        Mdfs::read($this->oUser, 9999, 10);
    }

    public function testWriteWithInvalidHandleThrowsVfsException(): void
    {
        $this->expectException(VfsException::class);
        Mdfs::write($this->oUser, 9999, 'data');
    }

    public function testFsCloseWithInvalidHandleIsNoOp(): void
    {
        Mdfs::fsClose($this->oUser, 9999);
        $this->assertTrue(true);
    }
}
