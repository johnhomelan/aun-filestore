<?php

/*
 * @group unit-tests
 *
 * Unit tests for the AdfsAdl VFS plugin.
 *
 * The plugin provides read-only access to ADFS floppy disk image files (.adl)
 * stored on the local filesystem. .adl images appear as virtual directories in
 * the econet namespace; the ADFS catalogue inside the image is exposed as files.
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

class VfsPluginAdfsAdlTest extends TestCase
{
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
        AdfsAdl::init($oLogger, false);

        $this->oUser = new User();
        $this->oUser->setUsername('adfsadluser');
        $this->oUser->setHomedir('$');
        $this->oUser->setBootOpt(0);
        $this->oUser->setUnixUid(5000);
        $this->oUser->setPriv('u');

        $this->_resetPluginState();
    }

    protected function tearDown(): void
    {
        config::resetValue('vfs_plugin_localadfsadl_root');
        $this->_deleteDir($this->sRoot);
        $this->_resetPluginState();
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    protected function _resetPluginState(): void
    {
        $oRefl = new ReflectionClass(AdfsAdl::class);
        foreach (['aImageReaders', 'aFileHandles'] as $sProp) {
            $oProp = $oRefl->getProperty($sProp);
            $oProp->setAccessible(true);
            $oProp->setValue(null, []);
        }
        $oProp = $oRefl->getProperty('iFileHandle');
        $oProp->setAccessible(true);
        $oProp->setValue(null, 0);
    }

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
     * Build a minimal valid ADFS ADL image with an empty root directory.
     *
     * The root directory occupies sectors 2-6 (interleaved format, track 0).
     * With bInterleaved=true and SECTORS_PER_TRACK=16, sector n on track 0
     * is at byte offset n*256. Eight zero-filled sectors (2048 bytes) cover
     * the root directory with no entries (first metadata byte = 0).
     */
    protected function _buildMinimalAdfsAdl(): string
    {
        return str_repeat("\x00", 2048);
    }

    /** Write a minimal ADL image to the test root and return its path. */
    protected function _writeAdlImage(string $sName = 'testdisk'): string
    {
        $sPath = $this->sRoot . $sName . '.adl';
        file_put_contents($sPath, $this->_buildMinimalAdfsAdl());
        return $sPath;
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

    // -------------------------------------------------------------------------
    // getFile — error paths (no image / empty image)
    // -------------------------------------------------------------------------

    public function testGetFileThrowsWhenNoImageExists(): void
    {
        $this->expectException(VfsException::class);
        AdfsAdl::getFile($this->oUser, new FilePath('$.nonexistent', 'file'));
    }

    public function testGetFileThrowsForFileNotInEmptyImage(): void
    {
        $this->_writeAdlImage('testdisk');
        $this->expectException(VfsException::class);
        AdfsAdl::getFile($this->oUser, new FilePath('$.testdisk', 'NOSUCHFILE'));
    }

    // -------------------------------------------------------------------------
    // getDirectoryListing
    // -------------------------------------------------------------------------

    public function testGetDirectoryListingReturnsOriginalArrayWhenNoDiskImages(): void
    {
        $aExisting = ['prior' => 'entry'];
        $aResult   = AdfsAdl::getDirectoryListing('$', $aExisting);
        $this->assertArrayHasKey('prior', $aResult);
    }

    public function testGetDirectoryListingRootShowsAdlAsVirtualDirectory(): void
    {
        $this->_writeAdlImage('testdisk');
        $aListing = AdfsAdl::getDirectoryListing('$', []);

        // The .adl file is exposed with its full filename as the key.
        $this->assertArrayHasKey('testdisk.adl', $aListing);
        $this->assertTrue($aListing['testdisk.adl']->isDir());
    }

    public function testGetDirectoryListingInsideEmptyImageReturnsEmptyArray(): void
    {
        $this->_writeAdlImage('testdisk');
        $aListing = AdfsAdl::getDirectoryListing('$.testdisk', []);

        // The zero-filled image has an empty catalogue.
        $this->assertEmpty($aListing);
    }

    public function testGetDirectoryListingPreservesExistingEntries(): void
    {
        $this->_writeAdlImage('testdisk');
        $aExisting = ['prior' => 'value'];
        $aResult   = AdfsAdl::getDirectoryListing('$', $aExisting);

        $this->assertArrayHasKey('prior', $aResult);
        $this->assertArrayHasKey('testdisk.adl', $aResult);
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

    /** Extract the VFS handle integer from a FileDescriptor via reflection. */
    protected function _getVfsHandle(\HomeLan\FileStore\Vfs\FileDescriptor $oFd): int
    {
        $oRefl = new ReflectionClass($oFd);
        $oProp = $oRefl->getProperty('iVfsHandle');
        $oProp->setAccessible(true);
        return (int) $oProp->getValue($oFd);
    }

    public function testBuildFiledescriptorForDiskImageItselfReturnsDirectory(): void
    {
        $this->_writeAdlImage('testdisk');
        $oFd = AdfsAdl::_buildFiledescriptorFromEconetPath(
            $this->oUser, new FilePath('$', 'testdisk'), true, true
        );
        $this->assertTrue($oFd->isDir());
        $this->assertFalse($oFd->isFile());
        $oFd->close();
    }

    public function testFsFtellInitiallyZeroForDiskRoot(): void
    {
        $this->_writeAdlImage('testdisk');
        $oFd = AdfsAdl::_buildFiledescriptorFromEconetPath(
            $this->oUser, new FilePath('$', 'testdisk'), true, true
        );
        $this->assertSame(0, $oFd->fsFTell());
        $oFd->close();
    }

    public function testFsCloseRemovesHandle(): void
    {
        $this->_writeAdlImage('testdisk');
        $oFd     = AdfsAdl::_buildFiledescriptorFromEconetPath(
            $this->oUser, new FilePath('$', 'testdisk'), true, true
        );
        $iHandle = $this->_getVfsHandle($oFd);
        $oFd->close();

        $this->expectException(VfsException::class);
        AdfsAdl::fsFtell($this->oUser, $iHandle);
    }
}
