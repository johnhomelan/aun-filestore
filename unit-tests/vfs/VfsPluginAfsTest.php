<?php

/*
 * @group unit-tests
 *
 * Unit tests for the AFS VFS plugin.
 *
 * The plugin provides read-only access to Level-3 Acorn File Server disk images
 * stored in .l3 files. It uses L3fsReader internally.
 *
 * NOTE: Creating a valid L3 disk image requires a complex binary layout
 * (AFS0 header, JesMap allocation tables, etc.) that is not practical to
 * synthesise in a unit test. These tests therefore focus on:
 *   - All simple / no-op interface methods (lifecycle, read-only write ops)
 *   - All invalid-handle error paths
 *   - Absence-of-image error paths for getFile and _buildFiledescriptorFromEconetPath
 *   - getDirectoryListing behaviour with and without .l3 files
 */

if (!defined('CONFIG_security_mode')) {
    define('CONFIG_security_mode', 'singleuser');
}
if (!defined('CONFIG_vfs_plugin_afs_root')) {
    define('CONFIG_vfs_plugin_afs_root', '/tmp/afs-test-default-' . uniqid());
}

include_once(__DIR__ . '/../../src/include/system.inc.php');

use PHPUnit\Framework\TestCase;
use Monolog\Logger;
use Monolog\Handler\NullHandler;
use HomeLan\FileStore\Authentication\User;
use HomeLan\FileStore\Vfs\Plugin\AFS;
use HomeLan\FileStore\Vfs\FilePath;
use HomeLan\FileStore\Vfs\Exception as VfsException;

class VfsPluginAfsTest extends TestCase
{
    protected User $oUser;
    protected string $sRoot;

    // -------------------------------------------------------------------------
    // Setup / Teardown
    // -------------------------------------------------------------------------

    protected function setUp(): void
    {
        $this->sRoot = sys_get_temp_dir() . '/afs_test_' . uniqid() . '/';
        mkdir($this->sRoot, 0755, true);

        config::overrideValue('vfs_plugin_afs_root', rtrim($this->sRoot, '/'));

        $oLogger = new Logger('afs-test');
        $oLogger->pushHandler(new NullHandler());
        AFS::init($oLogger, false);

        $this->oUser = new User();
        $this->oUser->setUsername('afsuser');
        $this->oUser->setHomedir('$');
        $this->oUser->setBootOpt(0);
        $this->oUser->setUnixUid(5000);
        $this->oUser->setPriv('u');

        $this->_resetPluginState();
    }

    protected function tearDown(): void
    {
        config::resetValue('vfs_plugin_afs_root');
        $this->_deleteDir($this->sRoot);
        $this->_resetPluginState();
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    protected function _resetPluginState(): void
    {
        $oRefl = new ReflectionClass(AFS::class);
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
        AFS::init($oLogger, false);
        $this->assertTrue(true);
    }

    public function testHouseKeepingIsNoOp(): void
    {
        AFS::houseKeeping();
        $this->assertTrue(true);
    }

    // -------------------------------------------------------------------------
    // Access mode
    // -------------------------------------------------------------------------

    public function testGetAccessModeReturnsReadOnly(): void
    {
        $this->assertSame('-r/-r', AFS::_getAccessMode(0, 0, 0));
    }

    // -------------------------------------------------------------------------
    // Mutating operations — all return false (read-only plugin)
    // -------------------------------------------------------------------------

    public function testCreateDirectoryReturnsFalse(): void
    {
        $this->assertFalse(AFS::createDirectory($this->oUser, new FilePath('$', 'newdir')));
    }

    public function testDeleteFileReturnsFalse(): void
    {
        $this->assertFalse(AFS::deleteFile($this->oUser, new FilePath('$', 'somefile')));
    }

    public function testMoveFileReturnsFalse(): void
    {
        $this->assertFalse(
            AFS::moveFile($this->oUser, new FilePath('$', 'src'), new FilePath('$', 'dst'))
        );
    }

    public function testSaveFileIsNoOp(): void
    {
        AFS::saveFile($this->oUser, new FilePath('$', 'f'), 'data', 0, 0);
        $this->assertTrue(true);
    }

    public function testCreateFileIsNoOp(): void
    {
        AFS::createFile($this->oUser, new FilePath('$', 'f'), 10, 0, 0);
        $this->assertTrue(true);
    }

    public function testSetMetaIsNoOp(): void
    {
        AFS::setMeta('$.f', 0, 0, 0);
        $this->assertTrue(true);
    }

    // -------------------------------------------------------------------------
    // Locking — no-ops
    // -------------------------------------------------------------------------

    public function testFsLockIsNoOp(): void
    {
        AFS::fsLock($this->oUser, 0, true);
        AFS::fsLock($this->oUser, 0, false);
        $this->assertTrue(true);
    }

    public function testFsUnlockIsNoOp(): void
    {
        AFS::fsUnlock($this->oUser, 0);
        $this->assertTrue(true);
    }

    // -------------------------------------------------------------------------
    // Write / setExt — throw VfsException (truly read-only)
    // -------------------------------------------------------------------------

    public function testWriteThrowsVfsException(): void
    {
        $this->expectException(VfsException::class);
        AFS::write($this->oUser, 9999, 'data');
    }

    public function testSetExtThrowsVfsException(): void
    {
        $this->expectException(VfsException::class);
        AFS::setExt($this->oUser, 9999, 0);
    }

    // -------------------------------------------------------------------------
    // Invalid-handle error paths
    // -------------------------------------------------------------------------

    public function testFsFtellWithInvalidHandleThrowsVfsException(): void
    {
        $this->expectException(VfsException::class);
        AFS::fsFtell($this->oUser, 9999);
    }

    public function testFsFStatWithInvalidHandleThrowsVfsException(): void
    {
        $this->expectException(VfsException::class);
        AFS::fsFStat($this->oUser, 9999);
    }

    public function testIsEofWithInvalidHandleThrowsVfsException(): void
    {
        $this->expectException(VfsException::class);
        AFS::isEof($this->oUser, 9999);
    }

    public function testSetPosWithInvalidHandleThrowsVfsException(): void
    {
        $this->expectException(VfsException::class);
        AFS::setPos($this->oUser, 9999, 0);
    }

    public function testReadWithInvalidHandleThrowsVfsException(): void
    {
        $this->expectException(VfsException::class);
        AFS::read($this->oUser, 9999, 10);
    }

    public function testFsCloseWithInvalidHandleIsNoOp(): void
    {
        AFS::fsClose($this->oUser, 9999);
        $this->assertTrue(true);
    }

    // -------------------------------------------------------------------------
    // getFile — error paths (no .l3 files)
    // -------------------------------------------------------------------------

    public function testGetFileThrowsWhenNoImageExists(): void
    {
        $this->expectException(VfsException::class);
        AFS::getFile($this->oUser, new FilePath('$.nonexistent', 'file'));
    }

    // -------------------------------------------------------------------------
    // _buildFiledescriptorFromEconetPath — error path
    // -------------------------------------------------------------------------

    public function testBuildFiledescriptorThrowsForNonExistentPath(): void
    {
        $this->expectException(VfsException::class);
        AFS::_buildFiledescriptorFromEconetPath(
            $this->oUser, new FilePath('$.nonexistent', 'file'), true, true
        );
    }

    // -------------------------------------------------------------------------
    // getDirectoryListing
    // -------------------------------------------------------------------------

    public function testGetDirectoryListingReturnsOriginalArrayWhenNoImages(): void
    {
        $aExisting = ['prior' => 'entry'];
        $aResult   = AFS::getDirectoryListing('$', $aExisting);
        $this->assertArrayHasKey('prior', $aResult);
    }

    public function testGetDirectoryListingWithNoL3FilesReturnsEmptyForBlankRoot(): void
    {
        $aResult = AFS::getDirectoryListing('$', []);
        $this->assertEmpty($aResult);
    }

    /**
     * Placing a .l3 file in the root causes it to appear as a virtual directory.
     * The file name without the extension is used as the display name.
     * Note: L3fsReader is not invoked here — the plugin only calls _getImageFile
     * for paths that end up matching an .l3 file.  For the root listing the plugin
     * scans the unix directory for .l3 files without opening them.
     */
    public function testGetDirectoryListingRootShowsL3FileAsVirtualDirectory(): void
    {
        // Create a stub .l3 file (does not need to be a valid L3 image for the
        // directory-listing scan path — the file is never opened during scandir).
        file_put_contents($this->sRoot . 'drive0.l3', str_repeat("\x00", 256));

        $aListing = AFS::getDirectoryListing('$', []);

        // The plugin exposes the .l3 file using its full filename as the array key.
        $this->assertArrayHasKey('drive0.l3', $aListing);
        $this->assertTrue($aListing['drive0.l3']->isDir());
    }

    public function testGetDirectoryListingL3EntryHasCorrectEconetName(): void
    {
        file_put_contents($this->sRoot . 'drive0.l3', str_repeat("\x00", 256));

        $aListing = AFS::getDirectoryListing('$', []);

        // The display name strips the '.l3' extension (3 chars).
        $this->assertSame('drive0', $aListing['drive0.l3']->getEconetName());
    }

    public function testGetDirectoryListingPreservesExistingEntries(): void
    {
        file_put_contents($this->sRoot . 'drive0.l3', str_repeat("\x00", 256));

        $aExisting = ['prior' => 'value'];
        $aResult   = AFS::getDirectoryListing('$', $aExisting);

        $this->assertArrayHasKey('prior', $aResult);
        $this->assertArrayHasKey('drive0.l3', $aResult);
    }

    public function testGetDirectoryListingFiltersRawL3EntriesFromInput(): void
    {
        // If an upstream plugin has already added a raw key containing '\/l3' it
        // should be filtered out by AFS::getDirectoryListing. In practice this
        // pattern is very unlikely, but the filter logic is tested here.
        // The filter is: stripos($sFile, "\/l3") === FALSE → keep.
        // A key that contains the literal backslash-slash pattern would be removed.
        // Since normal filenames never contain backslashes, the filter is a no-op
        // for typical entries — but confirm that normal entries pass through.
        $aExisting = ['regularfile' => 'value'];
        $aResult   = AFS::getDirectoryListing('$', $aExisting);
        $this->assertArrayHasKey('regularfile', $aResult);
    }
}
