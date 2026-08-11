<?php

/*
 * @group unit-tests
 *
 * Unit tests for the AdfsHD VFS plugin.
 *
 * The plugin provides read-only access to ADFS hard disk image files (.dat)
 * stored on the local filesystem. .dat images appear as virtual directories in
 * the econet namespace. Unlike AdfsAdl the images are NOT interleaved (sectors
 * are stored sequentially on disk).
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

class VfsPluginAdfsHdTest extends TestCase
{
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
        AdfsHD::init($oLogger, false);

        $this->oUser = new User();
        $this->oUser->setUsername('adfshduser');
        $this->oUser->setHomedir('$');
        $this->oUser->setBootOpt(0);
        $this->oUser->setUnixUid(5000);
        $this->oUser->setPriv('u');

        $this->_resetPluginState();
    }

    protected function tearDown(): void
    {
        config::resetValue('vfs_plugin_localadfshd_root');
        $this->_deleteDir($this->sRoot);
        $this->_resetPluginState();
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    protected function _resetPluginState(): void
    {
        $oRefl = new ReflectionClass(AdfsHD::class);
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
     * Build a minimal valid ADFS HD image with an empty root directory.
     *
     * AdfsReaderHD uses non-interleaved layout (bInterleaved=false), so sector n
     * is simply at byte offset n*256. The root directory occupies sectors 2-6
     * (bytes 512-1791). All-zero content means the catalogue loop breaks
     * immediately on the first entry (byte 0 of first metadata slot = 0).
     */
    protected function _buildMinimalAdfsHd(): string
    {
        return str_repeat("\x00", 2048);
    }

    /** Write a minimal .dat image to the test root and return its path. */
    protected function _writeDatImage(string $sName = 'scsi0'): string
    {
        $sPath = $this->sRoot . $sName . '.dat';
        file_put_contents($sPath, $this->_buildMinimalAdfsHd());
        return $sPath;
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
    // Mutating operations — read-only plugin, all return false
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

    // -------------------------------------------------------------------------
    // getFile — error paths
    // -------------------------------------------------------------------------

    public function testGetFileThrowsWhenNoImageExists(): void
    {
        $this->expectException(VfsException::class);
        AdfsHD::getFile($this->oUser, new FilePath('$.nonexistent', 'file'));
    }

    public function testGetFileThrowsForFileNotInEmptyImage(): void
    {
        $this->_writeDatImage('scsi0');
        $this->expectException(VfsException::class);
        AdfsHD::getFile($this->oUser, new FilePath('$.scsi0', 'NOSUCHFILE'));
    }

    // -------------------------------------------------------------------------
    // getDirectoryListing
    // -------------------------------------------------------------------------

    public function testGetDirectoryListingReturnsOriginalArrayWhenNoDiskImages(): void
    {
        $aExisting = ['prior' => 'entry'];
        $aResult   = AdfsHD::getDirectoryListing('$', $aExisting);
        $this->assertArrayHasKey('prior', $aResult);
    }

    public function testGetDirectoryListingRootShowsDatAsVirtualDirectory(): void
    {
        $this->_writeDatImage('scsi0');
        $aListing = AdfsHD::getDirectoryListing('$', []);

        // The .dat file is exposed with its full filename as the key.
        $this->assertArrayHasKey('scsi0.dat', $aListing);
        $this->assertTrue($aListing['scsi0.dat']->isDir());
    }

    public function testGetDirectoryListingInsideEmptyImageReturnsEmptyArray(): void
    {
        $this->_writeDatImage('scsi0');
        $aListing = AdfsHD::getDirectoryListing('$.scsi0', []);

        // The zero-filled image has an empty ADFS catalogue.
        $this->assertEmpty($aListing);
    }

    public function testGetDirectoryListingPreservesExistingEntries(): void
    {
        $this->_writeDatImage('scsi0');
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
        $this->_writeDatImage('scsi0');
        $oFd = AdfsHD::_buildFiledescriptorFromEconetPath(
            $this->oUser, new FilePath('$', 'scsi0'), true, true
        );
        $this->assertTrue($oFd->isDir());
        $this->assertFalse($oFd->isFile());
        $oFd->close();
    }

    public function testFsFtellInitiallyZeroForDiskRoot(): void
    {
        $this->_writeDatImage('scsi0');
        $oFd = AdfsHD::_buildFiledescriptorFromEconetPath(
            $this->oUser, new FilePath('$', 'scsi0'), true, true
        );
        $this->assertSame(0, $oFd->fsFTell());
        $oFd->close();
    }

    public function testSetPosAndFsFtellRoundTrip(): void
    {
        $this->_writeDatImage('scsi0');
        $oFd = AdfsHD::_buildFiledescriptorFromEconetPath(
            $this->oUser, new FilePath('$', 'scsi0'), true, true
        );
        $oFd->setPos(42);
        $this->assertSame(42, $oFd->fsFTell());
        $oFd->close();
    }

    public function testFsCloseRemovesHandle(): void
    {
        $this->_writeDatImage('scsi0');
        $oFd     = AdfsHD::_buildFiledescriptorFromEconetPath(
            $this->oUser, new FilePath('$', 'scsi0'), true, true
        );
        $iHandle = $this->_getVfsHandle($oFd);
        $oFd->close();

        $this->expectException(VfsException::class);
        AdfsHD::fsFtell($this->oUser, $iHandle);
    }
}
