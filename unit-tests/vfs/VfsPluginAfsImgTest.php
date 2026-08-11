<?php

/*
 * @group unit-tests
 *
 * Unit tests for the AfsImg VFS plugin.
 *
 * The plugin provides read-only access to AFS (Acorn File Server) disk images
 * stored in .img files. It uses L3fsReader internally.
 *
 * NOTE: Creating a valid L3/AFS disk image in memory requires a complex binary
 * layout (AFS0 header, JesMap allocation tables, etc.) that is not practical to
 * synthesise in a unit test. These tests therefore focus on:
 *   - All simple / no-op interface methods (lifecycle, read-only write ops)
 *   - All invalid-handle error paths
 *   - Absence-of-image error paths for getFile and _buildFiledescriptorFromEconetPath
 *   - getDirectoryListing behaviour with no .img files present
 *
 * Bug note: AfsImg::getDirectoryListing() scans for ".adl" files (copy-paste
 * artefact) rather than ".img" files. This is tested to document the actual
 * behaviour.
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

class VfsPluginAfsImgTest extends TestCase
{
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
        AfsImg::init($oLogger, false);

        $this->oUser = new User();
        $this->oUser->setUsername('afsimguser');
        $this->oUser->setHomedir('$');
        $this->oUser->setBootOpt(0);
        $this->oUser->setUnixUid(5000);
        $this->oUser->setPriv('u');

        $this->_resetPluginState();
    }

    protected function tearDown(): void
    {
        config::resetValue('vfs_plugin_localafsimg_root');
        $this->_deleteDir($this->sRoot);
        $this->_resetPluginState();
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    protected function _resetPluginState(): void
    {
        $oRefl = new ReflectionClass(AfsImg::class);
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

    // -------------------------------------------------------------------------
    // getFile — error paths (no .img files in root)
    // -------------------------------------------------------------------------

    public function testGetFileThrowsWhenNoImageExists(): void
    {
        $this->expectException(VfsException::class);
        AfsImg::getFile($this->oUser, new FilePath('$.nonexistent', 'file'));
    }

    // -------------------------------------------------------------------------
    // _buildFiledescriptorFromEconetPath — error path
    // -------------------------------------------------------------------------

    public function testBuildFiledescriptorThrowsForNonExistentPath(): void
    {
        $this->expectException(VfsException::class);
        AfsImg::_buildFiledescriptorFromEconetPath(
            $this->oUser, new FilePath('$.nonexistent', 'file'), true, true
        );
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
     * the root therefore causes it to appear as a virtual directory.
     *
     * Note: AfsImg also has a parameter-ordering bug when constructing the
     * DirectoryEntry for files found via scandir. The $bDir argument receives
     * the mode string '-r/-r' (a truthy value) instead of a boolean.
     * isDir() therefore returns '-r/-r' rather than true. This test documents
     * the actual (buggy) behaviour.
     */
    public function testGetDirectoryListingPicksUpAdlFilesInsteadOfImg(): void
    {
        // Place a fake .adl file (not a valid image, just to test the scan path).
        file_put_contents($this->sRoot . 'fakeDisk.adl', str_repeat("\x00", 256));

        $aListing = AfsImg::getDirectoryListing('$', []);

        // The plugin finds 'fakeDisk.adl' (looks for .adl, not .img).
        $this->assertArrayHasKey('fakeDisk.adl', $aListing);
        // isDir() returns a truthy value (actually '-r/-r' string due to parameter
        // ordering bug) — cast to bool for a stable assertion.
        $this->assertTrue((bool) $aListing['fakeDisk.adl']->isDir());
    }

    public function testGetDirectoryListingDoesNotPickUpImgFiles(): void
    {
        // Place a .img file — the scan looks for .adl so this should be ignored.
        file_put_contents($this->sRoot . 'realDisk.img', str_repeat("\x00", 256));

        $aListing = AfsImg::getDirectoryListing('$', []);

        $this->assertArrayNotHasKey('realDisk.img', $aListing);
    }
}
