<?php

/*
 * @group unit-tests
 *
 * Unit tests for the DfsSsd VFS plugin.
 *
 * The plugin provides read-only access to DFS SSD (single-sided) disk image files
 * stored on the local filesystem. .ssd files appear as virtual directories in
 * the econet namespace.
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

class VfsPluginDfsSsdTest extends TestCase
{
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
        DfsSsd::init($oLogger, false);

        $this->oUser = new User();
        $this->oUser->setUsername('dfsssduser');
        $this->oUser->setHomedir('$');
        $this->oUser->setBootOpt(0);
        $this->oUser->setUnixUid(5000);
        $this->oUser->setPriv('u');

        $this->_resetPluginState();
    }

    protected function tearDown(): void
    {
        config::resetValue('vfs_plugin_localdfsssd_root');
        $this->_deleteDir($this->sRoot);
        $this->_resetPluginState();
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * Reset static plugin state between tests using reflection.
     */
    protected function _resetPluginState(): void
    {
        $oRefl = new ReflectionClass(DfsSsd::class);

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
     * Build a minimal valid DFS SSD disk image containing one file:
     *   Directory: $
     *   Name:      HELLO
     *   Load addr: 0xFF04
     *   Exec addr: 0xFF19
     *   Size:      11 bytes
     *   Data:      "Hello World"
     */
    protected function _buildMinimalDfsSsd(): string
    {
        // Sector 0 (256 bytes): disc title + catalogue
        $sSector0 = str_repeat("\x00", 256);
        // Bytes 0-7: disc title 'TESTDISK'
        $sSector0 = substr_replace($sSector0, 'TESTDISK', 0, 8);
        // Bytes 8-14: filename 'HELLO  ' (7 bytes), byte 15: directory '$' (0x24)
        $sSector0 = substr_replace($sSector0, "HELLO  \x24", 8, 8);

        // Sector 1 (256 bytes): disc options + file metadata
        $sSector1 = str_repeat("\x00", 256);
        // Bytes 0-3: title continuation 'TEST'
        $sSector1 = substr_replace($sSector1, 'TEST', 0, 4);
        // Bytes 8-15: file metadata
        //   [0] load-low=0x04 [1] load-mid=0xFF  → decoded load = 0xFF04
        //   [2] exec-low=0x19 [3] exec-mid=0xFF  → decoded exec = 0xFF19
        //   [4] size-low=0x0B [5] size-mid=0x00  → decoded size = 11
        //   [6] high-bits=0x00 (all high nibbles = 0)
        //   [7] start-sector=0x02
        $sSector1 = substr_replace(
            $sSector1,
            pack('C8', 0x04, 0xFF, 0x19, 0xFF, 0x0B, 0x00, 0x00, 0x02),
            8,
            8
        );

        // Sector 2 (256 bytes): file data "Hello World" at the start
        $sSector2 = str_repeat("\x00", 256);
        $sSector2 = substr_replace($sSector2, 'Hello World', 0, 11);

        return $sSector0 . $sSector1 . $sSector2;
    }

    /** Write a minimal SSD image to the test root and return its path. */
    protected function _writeSsdImage(string $sName = 'testdisk'): string
    {
        $sPath = $this->sRoot . $sName . '.ssd';
        file_put_contents($sPath, $this->_buildMinimalDfsSsd());
        return $sPath;
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
    // Read-only write-path operations (return false / no-op, NOT exceptions)
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
    // Locking — no-ops on image plugins
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
    // DfsSsd write / setExt are no-ops (unlike the other image plugins)
    // -------------------------------------------------------------------------

    public function testWriteIsNoOpDoesNotThrow(): void
    {
        // DfsSsd::write() just logs — it does NOT throw VfsException.
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
        // fsClose on a non-existent handle should silently do nothing.
        DfsSsd::fsClose($this->oUser, 9999);
        $this->assertTrue(true);
    }

    // -------------------------------------------------------------------------
    // getFile — error paths
    // -------------------------------------------------------------------------

    public function testGetFileThrowsForPathWithNoImage(): void
    {
        $this->expectException(VfsException::class);
        DfsSsd::getFile($this->oUser, new FilePath('$.nonexistent', 'file'));
    }

    public function testGetFileThrowsForNonExistentFileInsideImage(): void
    {
        $this->_writeSsdImage('testdisk');
        $this->expectException(VfsException::class);
        DfsSsd::getFile($this->oUser, new FilePath('$.testdisk', 'NOSUCHFILE'));
    }

    // -------------------------------------------------------------------------
    // getDirectoryListing — without images
    // -------------------------------------------------------------------------

    public function testGetDirectoryListingReturnsOriginalArrayWhenNoDiskImages(): void
    {
        $aExisting = ['prior' => 'entry'];
        $aResult   = DfsSsd::getDirectoryListing('$', $aExisting);
        // 'prior' entry must be preserved; no extra disk-image entries.
        $this->assertArrayHasKey('prior', $aResult);
    }

    // -------------------------------------------------------------------------
    // getDirectoryListing — with a real SSD image
    // -------------------------------------------------------------------------

    public function testGetDirectoryListingRootShowsSsdAsVirtualDirectory(): void
    {
        $this->_writeSsdImage('testdisk');
        $aListing = DfsSsd::getDirectoryListing('$', []);

        // The .ssd file is exposed under the key 'testdisk.ssd' with isDir() == TRUE.
        $this->assertArrayHasKey('testdisk.ssd', $aListing);
        $this->assertTrue($aListing['testdisk.ssd']->isDir());
    }

    public function testGetDirectoryListingInsideImageShowsFiles(): void
    {
        $this->_writeSsdImage('testdisk');
        $aListing = DfsSsd::getDirectoryListing('$.testdisk', []);

        $this->assertArrayHasKey('HELLO', $aListing);
        $this->assertFalse($aListing['HELLO']->isDir());
    }

    public function testGetDirectoryListingFileHasCorrectLoadAndExecAddresses(): void
    {
        $this->_writeSsdImage('testdisk');
        $aListing = DfsSsd::getDirectoryListing('$.testdisk', []);

        $this->assertSame(0xFF04, $aListing['HELLO']->getLoadAddr());
        $this->assertSame(0xFF19, $aListing['HELLO']->getExecAddr());
    }

    public function testGetDirectoryListingPreservesExistingEntries(): void
    {
        $this->_writeSsdImage('testdisk');
        $aExisting = ['prior' => 'value'];
        $aResult   = DfsSsd::getDirectoryListing('$.testdisk', $aExisting);

        $this->assertArrayHasKey('prior', $aResult);
        $this->assertArrayHasKey('HELLO', $aResult);
    }

    public function testGetDirectoryListingUnknownSubdirReturnsOriginalArray(): void
    {
        $this->_writeSsdImage('testdisk');
        $aExisting = ['x' => 'y'];
        $aResult   = DfsSsd::getDirectoryListing('$.testdisk.A', $aExisting);

        // No catalogue dir 'A' in the minimal image; original array must be unchanged.
        $this->assertSame($aExisting, $aResult);
    }

    // -------------------------------------------------------------------------
    // getFile — happy path
    // -------------------------------------------------------------------------

    public function testGetFileReturnsFileContents(): void
    {
        $this->_writeSsdImage('testdisk');
        $sData = DfsSsd::getFile($this->oUser, new FilePath('$.testdisk', 'HELLO'));
        $this->assertSame('Hello World', $sData);
    }

    // -------------------------------------------------------------------------
    // File handle I/O
    // -------------------------------------------------------------------------

    /** Extract the VFS (plugin-internal) handle integer from a FileDescriptor. */
    protected function _getVfsHandle(\HomeLan\FileStore\Vfs\FileDescriptor $oFd): int
    {
        $oRefl = new ReflectionClass($oFd);
        $oProp = $oRefl->getProperty('iVfsHandle');
        $oProp->setAccessible(true);
        return (int) $oProp->getValue($oFd);
    }

    public function testFsFtellInitiallyZero(): void
    {
        $this->_writeSsdImage('testdisk');
        $oFd = DfsSsd::_buildFiledescriptorFromEconetPath(
            $this->oUser, new FilePath('$.testdisk', 'HELLO'), true, true
        );
        $this->assertSame(0, $oFd->fsFTell());
        $oFd->close();
    }

    public function testSetPosUpdatesFsFtell(): void
    {
        $this->_writeSsdImage('testdisk');
        $oFd = DfsSsd::_buildFiledescriptorFromEconetPath(
            $this->oUser, new FilePath('$.testdisk', 'HELLO'), true, true
        );
        $oFd->setPos(5);
        $this->assertSame(5, $oFd->fsFTell());
        $oFd->close();
    }

    public function testReadReturnsSubstringAtCurrentPosition(): void
    {
        $this->_writeSsdImage('testdisk');
        $oFd = DfsSsd::_buildFiledescriptorFromEconetPath(
            $this->oUser, new FilePath('$.testdisk', 'HELLO'), true, true
        );
        $oFd->setPos(6);
        $sData = $oFd->read(5);
        $this->assertSame('World', $sData);
        $oFd->close();
    }

    public function testFsFStatReturnsSizeAndSector(): void
    {
        $this->_writeSsdImage('testdisk');
        $oFd   = DfsSsd::_buildFiledescriptorFromEconetPath(
            $this->oUser, new FilePath('$.testdisk', 'HELLO'), true, true
        );
        $aStat = $oFd->fsFStat();
        $this->assertSame(11, $aStat['size']);
        $this->assertSame(2, $aStat['ino']);      // start sector
        $this->assertSame(1, $aStat['nlink']);
        $this->assertNull($aStat['dev']);
        $oFd->close();
    }

    public function testIsEofFalseBeforeEnd(): void
    {
        $this->_writeSsdImage('testdisk');
        $oFd = DfsSsd::_buildFiledescriptorFromEconetPath(
            $this->oUser, new FilePath('$.testdisk', 'HELLO'), true, true
        );
        $oFd->setPos(0);
        $this->assertFalse($oFd->isEof());
        $oFd->close();
    }

    public function testIsEofTrueAtEnd(): void
    {
        $this->_writeSsdImage('testdisk');
        $oFd = DfsSsd::_buildFiledescriptorFromEconetPath(
            $this->oUser, new FilePath('$.testdisk', 'HELLO'), true, true
        );
        $oFd->setPos(11); // file size = 11
        $this->assertTrue($oFd->isEof());
        $oFd->close();
    }

    public function testFsCloseRemovesHandle(): void
    {
        $this->_writeSsdImage('testdisk');
        $oFd     = DfsSsd::_buildFiledescriptorFromEconetPath(
            $this->oUser, new FilePath('$.testdisk', 'HELLO'), true, true
        );
        $iHandle = $this->_getVfsHandle($oFd);
        $oFd->close();

        // After close the VFS handle should be gone — fsFtell must throw.
        $this->expectException(VfsException::class);
        DfsSsd::fsFtell($this->oUser, $iHandle);
    }

    public function testBuildFiledescriptorThrowsForNonExistentFile(): void
    {
        $this->expectException(VfsException::class);
        DfsSsd::_buildFiledescriptorFromEconetPath(
            $this->oUser, new FilePath('$.testdisk', 'NOSUCHFILE'), true, true
        );
    }
}
