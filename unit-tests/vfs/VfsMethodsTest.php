<?php

/*
 * @group unit-tests
 *
 * Comprehensive tests for Vfs:: public methods.
 *
 * Tests are organised by method.  Each test group documents the behaviour being
 * verified so that the intent is clear without reading the implementation.
 *
 * Two spy VFS plugins (SpyVfsPlugin / SpyVfsPlugin2) are defined in
 * SpyVfsPlugins.php in the HomeLan\FileStore\Vfs\Plugin namespace so that
 * Vfs::getVfsPlugins() resolves them by short name.
 */

include_once(__DIR__ . '/../../src/include/system.inc.php');
include_once(__DIR__ . '/SpyVfsPlugins.php');

use PHPUnit\Framework\TestCase;
use Monolog\Logger;
use Monolog\Handler\NullHandler;
use HomeLan\FileStore\Vfs\Vfs;
use HomeLan\FileStore\Vfs\FileDescriptor;
use HomeLan\FileStore\Vfs\Exception as VfsException;
use HomeLan\FileStore\Vfs\FilePath;
use HomeLan\FileStore\Vfs\DirectoryEntry;
use HomeLan\FileStore\Authentication\Security;
use HomeLan\FileStore\Authentication\User;
use HomeLan\FileStore\Vfs\Plugin\SpyVfsPlugin;
use HomeLan\FileStore\Vfs\Plugin\SpyVfsPlugin2;

class VfsMethodsTest extends TestCase
{
    private Logger $oLogger;

    // -------------------------------------------------------------------------
    // Setup / teardown
    // -------------------------------------------------------------------------

    protected function setUp(): void
    {
        $this->oLogger = new Logger('test');
        $this->oLogger->pushHandler(new NullHandler());

        SpyVfsPlugin::reset();
        SpyVfsPlugin2::reset();
        $this->resetVfsState();
        $this->resetSecurityState();

        Vfs::init($this->oLogger, 'SpyVfsPlugin');
    }

    protected function tearDown(): void
    {
        SpyVfsPlugin::reset();
        SpyVfsPlugin2::reset();
        $this->resetVfsState();
        $this->resetSecurityState();
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function resetVfsState(): void
    {
        foreach (['aLocks', 'aHandleLocks', 'aHandles', 'aFileHandleIDs', 'aSinMapping'] as $sProp) {
            $rp = new \ReflectionProperty(Vfs::class, $sProp);
            $rp->setAccessible(true);
            $rp->setValue(null, []);
        }
        $rp = new \ReflectionProperty(Vfs::class, 'iSin');
        $rp->setAccessible(true);
        $rp->setValue(null, 1);
    }

    private function resetSecurityState(): void
    {
        $rp = new \ReflectionProperty(Security::class, 'aSessions');
        $rp->setAccessible(true);
        $rp->setValue(null, []);
    }

    /** Create a User with sensible defaults for test-path resolution. */
    private function makeUser(string $sUsername = 'testuser'): User
    {
        $oUser = new User();
        $oUser->setUsername($sUsername);
        $oUser->setCsd('$.HOME');
        $oUser->setRoot('$');
        return $oUser;
    }

    /** Inject a session so Security::isLoggedIn() returns true for (net, stn). */
    private function loginStation(int $iNet, int $iStn, ?User $oUser = null): void
    {
        if ($oUser === null) {
            $oUser = $this->makeUser();
        }
        $rp = new \ReflectionProperty(Security::class, 'aSessions');
        $rp->setAccessible(true);
        $aSessions = $rp->getValue(null) ?? [];
        $aSessions[$iNet][$iStn] = [
            'idle'     => time(),
            'datetime' => time(),
            'provider' => SpyVfsPlugin::class,
            'user'     => $oUser,
        ];
        $rp->setValue(null, $aSessions);
    }

    private function getVfsProp(string $sProp): mixed
    {
        $rp = new \ReflectionProperty(Vfs::class, $sProp);
        $rp->setAccessible(true);
        return $rp->getValue(null);
    }

    private function setVfsProp(string $sProp, mixed $value): void
    {
        $rp = new \ReflectionProperty(Vfs::class, $sProp);
        $rp->setAccessible(true);
        $rp->setValue(null, $value);
    }

    /** Inject a handle into Vfs::$aHandles so getFsHandle / closeFsHandle can find it. */
    private function registerHandle(int $iNet, int $iStn, FileDescriptor $oHandle): void
    {
        $aHandles = $this->getVfsProp('aHandles');
        $aHandles[$iNet][$iStn][$oHandle->getID()] = $oHandle;
        $this->setVfsProp('aHandles', $aHandles);
    }

    /**
     * Build a FileDescriptor backed by SpyVfsPlugin.
     *
     * @param bool $bFile  True = file handle (subject to locking)
     * @param bool $bDir   True = directory handle
     */
    private function makeHandle(
        string $sEconetPath,
        int    $iEconetHandle,
        bool   $bFile = true,
        bool   $bDir  = false,
        string $sVfsHandle = ''
    ): FileDescriptor {
        if ($sVfsHandle === '') {
            $sVfsHandle = "vfs-handle-{$iEconetHandle}";
        }
        return new FileDescriptor(
            $this->oLogger,
            SpyVfsPlugin::class,
            $this->makeUser(),
            '/tmp/mock/' . $sVfsHandle,
            $sEconetPath,
            $sVfsHandle,
            $iEconetHandle,
            $bFile,
            $bDir
        );
    }

    /**
     * Build a FileDescriptor that getDirectoryListing() can use (needs getEconetPath()).
     */
    private function makeDirFd(string $sEconetPath, int $iHandle = 1): FileDescriptor
    {
        return $this->makeHandle($sEconetPath, $iHandle, false, true);
    }

    /**
     * Suppress debug echo() calls inside Vfs::buildFullPath().
     * Any callable that goes through that private helper should be wrapped here.
     */
    private function silent(callable $fn): mixed
    {
        ob_start();
        try {
            return $fn();
        } finally {
            ob_end_clean();
        }
    }

    /** Build a simple DirectoryEntry for use in plugin stubs. */
    private function makeDirectoryEntry(string $sName, string $sPath): DirectoryEntry
    {
        return new DirectoryEntry(
            $sName,
            $sName,
            SpyVfsPlugin::class,
            0xFFFF8000,
            0xFFFF8000,
            0,
            $sPath,
            time(),
            'WR/WR',
            false
        );
    }

    // =========================================================================
    // getSin()
    // =========================================================================

    public function testGetSinReturnsNonZeroInt(): void
    {
        $iSin = Vfs::getSin('$.HOME.FILE');
        $this->assertGreaterThan(0, $iSin);
    }

    public function testGetSinReturnsSameValueForSamePath(): void
    {
        $iFirst  = Vfs::getSin('$.HOME.REPEATED');
        $iSecond = Vfs::getSin('$.HOME.REPEATED');
        $this->assertSame($iFirst, $iSecond);
    }

    public function testGetSinReturnsDifferentValuesForDifferentPaths(): void
    {
        $iA = Vfs::getSin('$.HOME.FILEA');
        $iB = Vfs::getSin('$.HOME.FILEB');
        $this->assertNotSame($iA, $iB);
    }

    public function testGetSinIsMonotonicallyIncreasing(): void
    {
        $iA = Vfs::getSin('$.PATH.ONE');
        $iB = Vfs::getSin('$.PATH.TWO');
        $iC = Vfs::getSin('$.PATH.THREE');
        $this->assertGreaterThan($iA, $iB);
        $this->assertGreaterThan($iB, $iC);
    }

    // =========================================================================
    // getFreeFileHandleID()
    // =========================================================================

    public function testGetFreeFileHandleIDStartsAtOne(): void
    {
        $oUser = $this->makeUser('alice');
        $this->assertSame(1, Vfs::getFreeFileHandleID($oUser));
    }

    public function testGetFreeFileHandleIDIncrementsForSameUser(): void
    {
        $oUser = $this->makeUser('bob');
        $this->assertSame(1, Vfs::getFreeFileHandleID($oUser));
        $this->assertSame(2, Vfs::getFreeFileHandleID($oUser));
        $this->assertSame(3, Vfs::getFreeFileHandleID($oUser));
    }

    public function testGetFreeFileHandleIDWrapsAt254(): void
    {
        $oUser = $this->makeUser('wrap');
        // Drive the counter to 254
        for ($i = 1; $i <= 254; $i++) {
            Vfs::getFreeFileHandleID($oUser);
        }
        // Next call should wrap back to 1
        $this->assertSame(1, Vfs::getFreeFileHandleID($oUser));
    }

    public function testGetFreeFileHandleIDIsIndependentPerUser(): void
    {
        $oAlice = $this->makeUser('alice2');
        $oBob   = $this->makeUser('bob2');

        Vfs::getFreeFileHandleID($oAlice); // alice = 1
        Vfs::getFreeFileHandleID($oAlice); // alice = 2

        $this->assertSame(1, Vfs::getFreeFileHandleID($oBob)); // bob still starts at 1
    }

    // =========================================================================
    // getVfsPlugins()
    // =========================================================================

    public function testGetVfsPluginsReturnsSinglePlugin(): void
    {
        $aPlugins = Vfs::getVfsPlugins();
        $this->assertCount(1, $aPlugins);
        // getVfsPlugins() returns FQNs with a leading backslash
        $this->assertSame('\\' . SpyVfsPlugin::class, $aPlugins[0]);
    }

    public function testGetVfsPluginsReturnsTwoPluginsWhenConfigured(): void
    {
        Vfs::init($this->oLogger, 'SpyVfsPlugin,SpyVfsPlugin2');
        $aPlugins = Vfs::getVfsPlugins();
        $this->assertCount(2, $aPlugins);
        $this->assertSame('\\' . SpyVfsPlugin::class,  $aPlugins[0]);
        $this->assertSame('\\' . SpyVfsPlugin2::class, $aPlugins[1]);
    }

    // =========================================================================
    // getDirectoryListing()
    // =========================================================================

    public function testGetDirectoryListingReturnsPluginEntries(): void
    {
        $oEntry = $this->makeDirectoryEntry('README', '$.HOME.README');
        SpyVfsPlugin::$fnGetDirListing = static function (string $sPath, array $aExisting) use ($oEntry): array {
            return array_merge($aExisting, ['README' => $oEntry]);
        };

        $oFd = $this->makeDirFd('$.HOME');
        $aResult = Vfs::getDirectoryListing($oFd);

        $this->assertArrayHasKey('README', $aResult);
        $this->assertSame($oEntry, $aResult['README']);
    }

    public function testGetDirectoryListingMergesEntriesFromMultiplePlugins(): void
    {
        Vfs::init($this->oLogger, 'SpyVfsPlugin,SpyVfsPlugin2');

        $oEntry1 = $this->makeDirectoryEntry('FILE1', '$.D.FILE1');
        $oEntry2 = $this->makeDirectoryEntry('FILE2', '$.D.FILE2');

        SpyVfsPlugin::$fnGetDirListing  = static fn(string $p, array $a) => array_merge($a, ['FILE1' => $oEntry1]);
        SpyVfsPlugin2::$fnGetDirListing = static fn(string $p, array $a) => array_merge($a, ['FILE2' => $oEntry2]);

        $oFd = $this->makeDirFd('$.D');
        $aResult = Vfs::getDirectoryListing($oFd);

        $this->assertArrayHasKey('FILE1', $aResult);
        $this->assertArrayHasKey('FILE2', $aResult);
    }

    public function testGetDirectoryListingReturnsEmptyOnHardException(): void
    {
        SpyVfsPlugin::$fnGetDirListing = static function (): never {
            throw new VfsException('Hard failure', true);
        };

        $oFd = $this->makeDirFd('$.BAD');
        $aResult = Vfs::getDirectoryListing($oFd);

        $this->assertEmpty($aResult);
    }

    public function testGetDirectoryListingContinuesAfterSoftException(): void
    {
        Vfs::init($this->oLogger, 'SpyVfsPlugin,SpyVfsPlugin2');

        $oEntry = $this->makeDirectoryEntry('SAFE', '$.D.SAFE');
        SpyVfsPlugin::$fnGetDirListing  = static function (): never {
            throw new VfsException('Soft failure', false);
        };
        SpyVfsPlugin2::$fnGetDirListing = static fn(string $p, array $a) => array_merge($a, ['SAFE' => $oEntry]);

        $oFd = $this->makeDirFd('$.D');
        $aResult = Vfs::getDirectoryListing($oFd);

        $this->assertArrayHasKey('SAFE', $aResult);
    }

    public function testGetDirectoryListingPassesCorrectPathToPlugin(): void
    {
        $sCapturedPath = null;
        SpyVfsPlugin::$fnGetDirListing = static function (string $sPath, array $a) use (&$sCapturedPath): array {
            $sCapturedPath = $sPath;
            return $a;
        };

        $oFd = $this->makeDirFd('$.MYDIR');
        Vfs::getDirectoryListing($oFd);

        $this->assertSame('$.MYDIR', $sCapturedPath);
    }

    // =========================================================================
    // createDirectory()
    // =========================================================================

    public function testCreateDirectoryThrowsWhenNotLoggedIn(): void
    {
        $this->expectException(\Exception::class);
        $this->silent(fn() => Vfs::createDirectory(1, 5, '$.NEW'));
    }

    public function testCreateDirectorySucceedsWhenPluginReturnsTrue(): void
    {
        SpyVfsPlugin::$fnCreateDirectory = static fn() => true;
        $this->loginStation(1, 5);

        $this->silent(fn() => Vfs::createDirectory(1, 5, '$.NEW'));
        $this->assertTrue(true); // reached without exception
    }

    public function testCreateDirectoryThrowsWhenAllPluginsReturnFalse(): void
    {
        $this->loginStation(1, 5);
        // SpyVfsPlugin returns false by default
        $this->expectException(\Exception::class);
        $this->silent(fn() => Vfs::createDirectory(1, 5, '$.NOPLUGIN'));
    }

    public function testCreateDirectoryFallsThroughToSecondPlugin(): void
    {
        Vfs::init($this->oLogger, 'SpyVfsPlugin,SpyVfsPlugin2');
        SpyVfsPlugin::$fnCreateDirectory  = static fn() => false;  // first fails
        SpyVfsPlugin2::$fnCreateDirectory = static fn() => true;   // second succeeds
        $this->loginStation(1, 5);

        $this->silent(fn() => Vfs::createDirectory(1, 5, '$.NEW'));
        $this->assertNotEmpty(SpyVfsPlugin2::$aCallLog);
    }

    public function testCreateDirectoryStopsOnHardVfsException(): void
    {
        Vfs::init($this->oLogger, 'SpyVfsPlugin,SpyVfsPlugin2');
        SpyVfsPlugin::$fnCreateDirectory = static function (): never {
            throw new VfsException('Disk full', true);
        };
        $this->loginStation(1, 5);

        $caught = null;
        try {
            $this->silent(fn() => Vfs::createDirectory(1, 5, '$.NEW'));
        } catch (VfsException $e) {
            $caught = $e;
        }

        $this->assertInstanceOf(VfsException::class, $caught);
        $this->assertTrue($caught->isHard());
        // Second plugin must NOT have been tried
        $this->assertEmpty(SpyVfsPlugin2::$aCallLog);
    }

    public function testCreateDirectoryContinuesAfterSoftVfsException(): void
    {
        Vfs::init($this->oLogger, 'SpyVfsPlugin,SpyVfsPlugin2');
        SpyVfsPlugin::$fnCreateDirectory  = static function (): never {
            throw new VfsException('Soft', false);
        };
        SpyVfsPlugin2::$fnCreateDirectory = static fn() => true;
        $this->loginStation(1, 5);

        $this->silent(fn() => Vfs::createDirectory(1, 5, '$.SOFT'));
        // Verify second plugin was reached
        $aMethods = array_column(SpyVfsPlugin2::$aCallLog, 'method');
        $this->assertContains('createDirectory', $aMethods);
    }

    // =========================================================================
    // deleteFile()
    // =========================================================================

    public function testDeleteFileThrowsWhenNotLoggedIn(): void
    {
        $this->expectException(\Exception::class);
        $this->silent(fn() => Vfs::deleteFile(1, 5, '$.HOME.FILE'));
    }

    public function testDeleteFileSucceedsWhenPluginReturnsTrue(): void
    {
        SpyVfsPlugin::$fnDeleteFile = static fn() => true;
        $this->loginStation(1, 5);

        $this->silent(fn() => Vfs::deleteFile(1, 5, '$.HOME.FILE'));
        $this->assertTrue(true);
    }

    public function testDeleteFileThrowsWhenAllPluginsReturnFalse(): void
    {
        $this->loginStation(1, 5);
        $this->expectException(\Exception::class);
        $this->silent(fn() => Vfs::deleteFile(1, 5, '$.HOME.MISSING'));
    }

    public function testDeleteFileStopsOnHardVfsException(): void
    {
        Vfs::init($this->oLogger, 'SpyVfsPlugin,SpyVfsPlugin2');
        SpyVfsPlugin::$fnDeleteFile = static function (): never {
            throw new VfsException('Locked', true);
        };
        $this->loginStation(1, 5);

        $caught = null;
        try {
            $this->silent(fn() => Vfs::deleteFile(1, 5, '$.HOME.FILE'));
        } catch (VfsException $e) {
            $caught = $e;
        }

        $this->assertInstanceOf(VfsException::class, $caught);
        $this->assertTrue($caught->isHard());
        $this->assertEmpty(SpyVfsPlugin2::$aCallLog);
    }

    public function testDeleteFileFallsThrough(): void
    {
        Vfs::init($this->oLogger, 'SpyVfsPlugin,SpyVfsPlugin2');
        SpyVfsPlugin::$fnDeleteFile  = static fn() => false;
        SpyVfsPlugin2::$fnDeleteFile = static fn() => true;
        $this->loginStation(1, 5);

        $this->silent(fn() => Vfs::deleteFile(1, 5, '$.HOME.FILE'));
        $aMethods = array_column(SpyVfsPlugin2::$aCallLog, 'method');
        $this->assertContains('deleteFile', $aMethods);
    }

    // =========================================================================
    // moveFile()
    // =========================================================================

    public function testMoveFileThrowsWhenNotLoggedIn(): void
    {
        $this->expectException(\Exception::class);
        $this->silent(fn() => Vfs::moveFile(1, 5, '$.HOME.SRC', '$.HOME.DST'));
    }

    public function testMoveFileSucceedsWhenPluginReturnsTrue(): void
    {
        SpyVfsPlugin::$fnMoveFile = static fn() => true;
        $this->loginStation(1, 5);

        $this->silent(fn() => Vfs::moveFile(1, 5, '$.HOME.SRC', '$.HOME.DST'));
        $this->assertTrue(true);
    }

    public function testMoveFileThrowsWhenAllPluginsReturnFalse(): void
    {
        $this->loginStation(1, 5);
        $this->expectException(\Exception::class);
        $this->silent(fn() => Vfs::moveFile(1, 5, '$.HOME.SRC', '$.HOME.DST'));
    }

    public function testMoveFileStopsOnHardVfsException(): void
    {
        Vfs::init($this->oLogger, 'SpyVfsPlugin,SpyVfsPlugin2');
        SpyVfsPlugin::$fnMoveFile = static function (): never {
            throw new VfsException('No such file', true);
        };
        $this->loginStation(1, 5);

        $caught = null;
        try {
            $this->silent(fn() => Vfs::moveFile(1, 5, '$.HOME.SRC', '$.HOME.DST'));
        } catch (VfsException $e) {
            $caught = $e;
        }

        $this->assertInstanceOf(VfsException::class, $caught);
        $this->assertEmpty(SpyVfsPlugin2::$aCallLog);
    }

    public function testMoveFileFallsThrough(): void
    {
        Vfs::init($this->oLogger, 'SpyVfsPlugin,SpyVfsPlugin2');
        SpyVfsPlugin::$fnMoveFile  = static fn() => false;
        SpyVfsPlugin2::$fnMoveFile = static fn() => true;
        $this->loginStation(1, 5);

        $this->silent(fn() => Vfs::moveFile(1, 5, '$.HOME.SRC', '$.HOME.DST'));
        $aMethods = array_column(SpyVfsPlugin2::$aCallLog, 'method');
        $this->assertContains('moveFile', $aMethods);
    }

    // =========================================================================
    // saveFile()
    // =========================================================================

    public function testSaveFileThrowsWhenNotLoggedIn(): void
    {
        $this->expectException(\Exception::class);
        $this->silent(fn() => Vfs::saveFile(1, 5, '$.HOME.DOC', 'data', 0, 0));
    }

    public function testSaveFileSucceedsWhenPluginReturnsTrue(): void
    {
        SpyVfsPlugin::$fnSaveFile = static fn() => true;
        $this->loginStation(1, 5);

        $this->silent(fn() => Vfs::saveFile(1, 5, '$.HOME.DOC', 'hello', 0xFF00, 0xFF00));
        $this->assertTrue(true);
    }

    public function testSaveFilePassesDataAndAddressesToPlugin(): void
    {
        $sCapturedData  = null;
        $iCapturedLoad  = null;
        $iCapturedExec  = null;
        SpyVfsPlugin::$fnSaveFile = static function ($oUser, FilePath $oPath, string $sData, int $iLoad, int $iExec) use (&$sCapturedData, &$iCapturedLoad, &$iCapturedExec): bool {
            $sCapturedData = $sData;
            $iCapturedLoad = $iLoad;
            $iCapturedExec = $iExec;
            return true;
        };
        $this->loginStation(1, 5);

        $this->silent(fn() => Vfs::saveFile(1, 5, '$.HOME.DOC', 'testdata', 0x1234, 0x5678));

        $this->assertSame('testdata', $sCapturedData);
        $this->assertSame(0x1234, $iCapturedLoad);
        $this->assertSame(0x5678, $iCapturedExec);
    }

    public function testSaveFileThrowsWhenAllPluginsFail(): void
    {
        $this->loginStation(1, 5);
        $this->expectException(\Exception::class);
        $this->silent(fn() => Vfs::saveFile(1, 5, '$.HOME.DOC', 'data', 0, 0));
    }

    public function testSaveFileStopsOnHardVfsException(): void
    {
        Vfs::init($this->oLogger, 'SpyVfsPlugin,SpyVfsPlugin2');
        SpyVfsPlugin::$fnSaveFile = static function (): never {
            throw new VfsException('Read only', true);
        };
        $this->loginStation(1, 5);

        $this->expectException(VfsException::class);
        $this->silent(fn() => Vfs::saveFile(1, 5, '$.HOME.DOC', 'data', 0, 0));
    }

    // =========================================================================
    // createFile()
    // =========================================================================

    public function testCreateFileThrowsWhenNotLoggedIn(): void
    {
        $this->expectException(\Exception::class);
        $this->silent(fn() => Vfs::createFile(1, 5, '$.HOME.NEW', 1024, 0, 0));
    }

    public function testCreateFileSucceedsWhenPluginReturnsTrue(): void
    {
        SpyVfsPlugin::$fnCreateFile = static fn() => true;
        $this->loginStation(1, 5);

        $this->silent(fn() => Vfs::createFile(1, 5, '$.HOME.NEW', 512, 0x100, 0x200));
        $this->assertTrue(true);
    }

    public function testCreateFilePassesSizeAndAddressesToPlugin(): void
    {
        $iCapturedSize = null;
        $iCapturedLoad = null;
        SpyVfsPlugin::$fnCreateFile = static function ($oUser, FilePath $oPath, int $iSize, int $iLoad, int $iExec) use (&$iCapturedSize, &$iCapturedLoad): bool {
            $iCapturedSize = $iSize;
            $iCapturedLoad = $iLoad;
            return true;
        };
        $this->loginStation(1, 5);

        $this->silent(fn() => Vfs::createFile(1, 5, '$.HOME.NEW', 4096, 0xABCD, 0));

        $this->assertSame(4096, $iCapturedSize);
        $this->assertSame(0xABCD, $iCapturedLoad);
    }

    public function testCreateFileThrowsWhenAllPluginsFail(): void
    {
        $this->loginStation(1, 5);
        $this->expectException(\Exception::class);
        $this->silent(fn() => Vfs::createFile(1, 5, '$.HOME.NEW', 0, 0, 0));
    }

    public function testCreateFileStopsOnHardVfsException(): void
    {
        Vfs::init($this->oLogger, 'SpyVfsPlugin,SpyVfsPlugin2');
        SpyVfsPlugin::$fnCreateFile = static function (): never {
            throw new VfsException('Disk full', true);
        };
        $this->loginStation(1, 5);

        $this->expectException(VfsException::class);
        $this->silent(fn() => Vfs::createFile(1, 5, '$.HOME.NEW', 0, 0, 0));
    }

    // =========================================================================
    // getFile()
    // =========================================================================

    public function testGetFileThrowsWhenNotLoggedIn(): void
    {
        $this->expectException(\Exception::class);
        $this->silent(fn() => Vfs::getFile(1, 5, '$.HOME.DOC'));
    }

    public function testGetFileReturnsPluginContents(): void
    {
        SpyVfsPlugin::$fnGetFile = static fn() => 'file-contents';
        $this->loginStation(1, 5);

        $sResult = $this->silent(fn() => Vfs::getFile(1, 5, '$.HOME.DOC'));
        $this->assertSame('file-contents', $sResult);
    }

    public function testGetFileThrowsGenericExceptionWhenAllPluginsThrowSoft(): void
    {
        Vfs::init($this->oLogger, 'SpyVfsPlugin,SpyVfsPlugin2');
        SpyVfsPlugin::$fnGetFile  = static function (): never { throw new VfsException('Not found', false); };
        SpyVfsPlugin2::$fnGetFile = static function (): never { throw new VfsException('Not found', false); };
        $this->loginStation(1, 5);

        $this->expectException(\Exception::class);
        $this->silent(fn() => Vfs::getFile(1, 5, '$.HOME.DOC'));
    }

    public function testGetFileStopsImmediatelyOnHardVfsException(): void
    {
        Vfs::init($this->oLogger, 'SpyVfsPlugin,SpyVfsPlugin2');
        SpyVfsPlugin::$fnGetFile = static function (): never {
            throw new VfsException('Hard error', true);
        };
        $this->loginStation(1, 5);

        $caught = null;
        try {
            $this->silent(fn() => Vfs::getFile(1, 5, '$.HOME.DOC'));
        } catch (VfsException $e) {
            $caught = $e;
        }

        $this->assertInstanceOf(VfsException::class, $caught);
        $this->assertTrue($caught->isHard());
        $this->assertEmpty(SpyVfsPlugin2::$aCallLog, 'Second plugin must not be tried after hard exception');
    }

    public function testGetFileFallsThroughToSecondPlugin(): void
    {
        Vfs::init($this->oLogger, 'SpyVfsPlugin,SpyVfsPlugin2');
        SpyVfsPlugin::$fnGetFile  = static function (): never { throw new VfsException('Not mine', false); };
        SpyVfsPlugin2::$fnGetFile = static fn() => 'from-plugin-2';
        $this->loginStation(1, 5);

        $sResult = $this->silent(fn() => Vfs::getFile(1, 5, '$.HOME.DOC'));
        $this->assertSame('from-plugin-2', $sResult);
    }

    // A bare "*command" name not found in the CSD must also be searched for in
    // the user's library directory (default $.LIBRARY) before giving up — this
    // is what lets a client's "*command" successfully load a file that only
    // lives in the library, not just the CSD.
    public function testGetFileFallsBackToLibraryForBareUnqualifiedName(): void
    {
        SpyVfsPlugin::$fnGetFile = static function($oUser, FilePath $oPath): string {
            if($oPath->getFilePath() === '$.LIBRARY.CMD'){
                return 'from-library';
            }
            throw new VfsException('Not found', false);
        };
        $this->loginStation(1, 5);

        $sResult = $this->silent(fn() => Vfs::getFile(1, 5, 'CMD'));
        $this->assertSame('from-library', $sResult);
    }

    public function testGetFileDoesNotFallBackToLibraryForQualifiedPath(): void
    {
        SpyVfsPlugin::$fnGetFile = static function (): never { throw new VfsException('Not found', false); };
        $this->loginStation(1, 5);

        $this->expectException(\Exception::class);
        $this->silent(fn() => Vfs::getFile(1, 5, '$.HOME.CMD'));

        $aQueriedPaths = array_column(SpyVfsPlugin::$aCallLog, 'args');
        foreach($aQueriedPaths as $aArgs){
            $this->assertNotSame('$.LIBRARY.CMD', $aArgs[0] ?? null, 'A qualified path must never trigger a library lookup');
        }
    }

    // The library is a fixed, server-wide location — a chroot'd user must
    // still be able to reach it for command lookup, not have "$.LIBRARY"
    // rewritten into "<chroot-root>.LIBRARY" (which won't exist).
    public function testGetFileLibraryFallbackIgnoresChrootPrefix(): void
    {
        $oUser = $this->makeUser();
        $oUser->setRoot('$.SOMEDISK');
        SpyVfsPlugin::$fnGetFile = static function($oUser, FilePath $oPath): string {
            if($oPath->getFilePath() === '$.LIBRARY.CMD'){
                return 'from-library';
            }
            throw new VfsException('Not found', false);
        };
        $this->loginStation(1, 5, $oUser);

        $sResult = $this->silent(fn() => Vfs::getFile(1, 5, 'CMD'));
        $this->assertSame('from-library', $sResult);
    }

    // =========================================================================
    // getMeta()
    // =========================================================================

    public function testGetMetaThrowsWhenNotLoggedIn(): void
    {
        $this->expectException(\Exception::class);
        $this->silent(fn() => Vfs::getMeta(1, 5, '$.HOME.FILE'));
    }

    public function testGetMetaReturnsDirectoryEntryForExactFilenameMatch(): void
    {
        $oEntry = $this->makeDirectoryEntry('README', '$.HOME.README');
        SpyVfsPlugin::$fnGetDirListing = static fn(string $p, array $a) => array_merge($a, ['README' => $oEntry]);
        $this->loginStation(1, 5);

        $oResult = $this->silent(fn() => Vfs::getMeta(1, 5, '$.HOME.README'));
        $this->assertSame($oEntry, $oResult);
    }

    public function testGetMetaFindsFileCaseInsensitively(): void
    {
        $oEntry = $this->makeDirectoryEntry('README', '$.HOME.README');
        SpyVfsPlugin::$fnGetDirListing = static fn(string $p, array $a) => array_merge($a, ['README' => $oEntry]);
        $this->loginStation(1, 5);

        // Request with different case
        $oResult = $this->silent(fn() => Vfs::getMeta(1, 5, '$.HOME.readme'));
        $this->assertSame($oEntry, $oResult);
    }

    public function testGetMetaThrowsWhenFileNotInListing(): void
    {
        // Plugin returns an empty listing (file not present)
        SpyVfsPlugin::$fnGetDirListing = static fn(string $p, array $a) => $a;
        $this->loginStation(1, 5);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('No such file');
        $this->silent(fn() => Vfs::getMeta(1, 5, '$.HOME.MISSING'));
    }

    public function testGetMetaRethrowsHardVfsExceptionFromPlugin(): void
    {
        SpyVfsPlugin::$fnGetDirListing = static function (): never {
            throw new VfsException('Catalogue unavailable', true);
        };
        $this->loginStation(1, 5);

        $caught = null;
        try {
            $this->silent(fn() => Vfs::getMeta(1, 5, '$.HOME.FILE'));
        } catch (VfsException $e) {
            $caught = $e;
        }

        $this->assertInstanceOf(VfsException::class, $caught);
        $this->assertTrue($caught->isHard());
    }

    public function testGetMetaMergesListingsFromMultiplePlugins(): void
    {
        Vfs::init($this->oLogger, 'SpyVfsPlugin,SpyVfsPlugin2');
        $oEntry = $this->makeDirectoryEntry('SHARED', '$.HOME.SHARED');
        SpyVfsPlugin::$fnGetDirListing  = static fn(string $p, array $a) => $a; // contributes nothing
        SpyVfsPlugin2::$fnGetDirListing = static fn(string $p, array $a) => array_merge($a, ['SHARED' => $oEntry]);
        $this->loginStation(1, 5);

        $oResult = $this->silent(fn() => Vfs::getMeta(1, 5, '$.HOME.SHARED'));
        $this->assertSame($oEntry, $oResult);
    }

    public function testGetMetaFallsBackToLibraryForBareUnqualifiedName(): void
    {
        $oEntry = $this->makeDirectoryEntry('CMD', '$.LIBRARY.CMD');
        SpyVfsPlugin::$fnGetDirListing = static function(string $sPath, array $a) use ($oEntry) {
            if($sPath === '$.LIBRARY'){
                return array_merge($a, ['CMD' => $oEntry]);
            }
            return $a; // CSD listing does not have it
        };
        $this->loginStation(1, 5);

        $oResult = $this->silent(fn() => Vfs::getMeta(1, 5, 'CMD'));
        $this->assertSame($oEntry, $oResult);
    }

    public function testGetMetaDoesNotFallBackToLibraryForQualifiedPath(): void
    {
        SpyVfsPlugin::$fnGetDirListing = static fn(string $p, array $a) => $a;
        $this->loginStation(1, 5);

        $this->expectException(\Exception::class);
        $this->silent(fn() => Vfs::getMeta(1, 5, '$.HOME.CMD'));

        $aQueriedDirs = array_column(SpyVfsPlugin::$aCallLog, 'args');
        foreach($aQueriedDirs as $aArgs){
            $this->assertNotSame('$.LIBRARY', $aArgs[0] ?? null, 'A qualified path must never trigger a library lookup');
        }
    }

    // =========================================================================
    // setMeta()
    // =========================================================================

    public function testSetMetaThrowsWhenNotLoggedIn(): void
    {
        $this->expectException(\Exception::class);
        $this->silent(fn() => Vfs::setMeta(1, 5, '$.HOME.FILE', 0, 0, 0));
    }

    public function testSetMetaCallsPluginSetMeta(): void
    {
        $bCalled = false;
        SpyVfsPlugin::$fnSetMeta = static function () use (&$bCalled): void { $bCalled = true; };
        $this->loginStation(1, 5);

        $this->silent(fn() => Vfs::setMeta(1, 5, '$.HOME.FILE', 0x1234, 0x5678, 0));
        $this->assertTrue($bCalled);
    }

    public function testSetMetaCallsAllPlugins(): void
    {
        Vfs::init($this->oLogger, 'SpyVfsPlugin,SpyVfsPlugin2');
        $this->loginStation(1, 5);

        $this->silent(fn() => Vfs::setMeta(1, 5, '$.HOME.FILE', 0, 0, 3));

        $aMethods1 = array_column(SpyVfsPlugin::$aCallLog,  'method');
        $aMethods2 = array_column(SpyVfsPlugin2::$aCallLog, 'method');
        $this->assertContains('setMeta', $aMethods1, 'First plugin must be called');
        $this->assertContains('setMeta', $aMethods2, 'Second plugin must be called');
    }

    public function testSetMetaStopsOnHardVfsException(): void
    {
        Vfs::init($this->oLogger, 'SpyVfsPlugin,SpyVfsPlugin2');
        SpyVfsPlugin::$fnSetMeta = static function (): never {
            throw new VfsException('Read-only', true);
        };
        $this->loginStation(1, 5);

        $caught = null;
        try {
            $this->silent(fn() => Vfs::setMeta(1, 5, '$.HOME.FILE', 0, 0, 0));
        } catch (VfsException $e) {
            $caught = $e;
        }

        $this->assertInstanceOf(VfsException::class, $caught);
        $this->assertTrue($caught->isHard());
        $this->assertEmpty(SpyVfsPlugin2::$aCallLog, 'Second plugin must not be reached after hard exception');
    }

    public function testSetMetaIgnoresSoftVfsExceptionAndContinues(): void
    {
        Vfs::init($this->oLogger, 'SpyVfsPlugin,SpyVfsPlugin2');
        SpyVfsPlugin::$fnSetMeta = static function (): never {
            throw new VfsException('Soft', false);
        };
        $this->loginStation(1, 5);

        $this->silent(fn() => Vfs::setMeta(1, 5, '$.HOME.FILE', 0, 0, 0));

        $aMethods2 = array_column(SpyVfsPlugin2::$aCallLog, 'method');
        $this->assertContains('setMeta', $aMethods2, 'Second plugin must still be called after soft exception');
    }

    // =========================================================================
    // createFsHandle()
    // =========================================================================

    public function testCreateFsHandleThrowsWhenNotLoggedIn(): void
    {
        $this->expectException(\Exception::class);
        $this->silent(fn() => Vfs::createFsHandle(1, 5, '$.HOME.FILE'));
    }

    public function testCreateFsHandleThrowsWhenNoPluginBuildsDescriptor(): void
    {
        // SpyVfsPlugin::_buildFiledescriptorFromEconetPath returns null by default
        $this->loginStation(1, 5);

        $this->expectException(\Exception::class);
        $this->silent(fn() => Vfs::createFsHandle(1, 5, '$.HOME.FILE'));
    }

    public function testCreateFsHandleReturnsFileDescriptorFromPlugin(): void
    {
        $oUser = $this->makeUser();
        $oExpected = new FileDescriptor(
            $this->oLogger, SpyVfsPlugin::class, $oUser,
            '/tmp/spy', '$.HOME.FILE', 'spy-handle', 7, true, false
        );
        SpyVfsPlugin::$fnBuildFd = static fn() => $oExpected;
        $this->loginStation(1, 5, $oUser);

        $oResult = $this->silent(fn() => Vfs::createFsHandle(1, 5, '$.HOME.FILE'));
        $this->assertSame($oExpected, $oResult);
    }

    public function testCreateFsHandleStoresHandleInRegistry(): void
    {
        $oUser = $this->makeUser();
        $oFd = new FileDescriptor(
            $this->oLogger, SpyVfsPlugin::class, $oUser,
            '/tmp/spy', '$.HOME.FILE', 'spy-handle', 12, false, true // directory — no lock
        );
        SpyVfsPlugin::$fnBuildFd = static fn() => $oFd;
        $this->loginStation(1, 5, $oUser);

        $this->silent(fn() => Vfs::createFsHandle(1, 5, '$.HOME.FILE'));

        $aHandles = $this->getVfsProp('aHandles');
        $this->assertArrayHasKey(12, $aHandles[1][5]);
    }

    public function testCreateFsHandleAcquiresReadLockForFileHandle(): void
    {
        $oUser = $this->makeUser();
        $oFd = new FileDescriptor(
            $this->oLogger, SpyVfsPlugin::class, $oUser,
            '/tmp/spy', '$.HOME.FILE', 'spy-h', 3, true, false // bFile=true
        );
        SpyVfsPlugin::$fnBuildFd = static fn() => $oFd;
        $this->loginStation(1, 5, $oUser);

        $this->silent(fn() => Vfs::createFsHandle(1, 5, '$.HOME.FILE', true, true));

        $aLocks = $this->getVfsProp('aLocks');
        $this->assertArrayHasKey('$.HOME.FILE', $aLocks);
        $this->assertSame(1, $aLocks['$.HOME.FILE']['readers']);
        $this->assertSame(0, $aLocks['$.HOME.FILE']['writers']);
    }

    public function testCreateFsHandleAcquiresWriteLockForFileHandle(): void
    {
        $oUser = $this->makeUser();
        $oFd = new FileDescriptor(
            $this->oLogger, SpyVfsPlugin::class, $oUser,
            '/tmp/spy', '$.HOME.FILE', 'spy-h', 3, true, false // bFile=true
        );
        SpyVfsPlugin::$fnBuildFd = static fn() => $oFd;
        $this->loginStation(1, 5, $oUser);

        $this->silent(fn() => Vfs::createFsHandle(1, 5, '$.HOME.FILE', false, false));

        $aLocks = $this->getVfsProp('aLocks');
        $this->assertSame(0, $aLocks['$.HOME.FILE']['readers']);
        $this->assertSame(1, $aLocks['$.HOME.FILE']['writers']);
    }

    public function testCreateFsHandleDoesNotLockDirectoryHandle(): void
    {
        $oUser = $this->makeUser();
        $oFd = new FileDescriptor(
            $this->oLogger, SpyVfsPlugin::class, $oUser,
            '/tmp/spy', '$.HOME', 'spy-dir', 9, false, true // bDir=true
        );
        SpyVfsPlugin::$fnBuildFd = static fn() => $oFd;
        $this->loginStation(1, 5, $oUser);

        $this->silent(fn() => Vfs::createFsHandle(1, 5, '$.HOME'));

        $aLocks = $this->getVfsProp('aLocks');
        $this->assertEmpty($aLocks, 'Directory handles must not acquire a lock');
    }

    public function testCreateFsHandleStopsOnHardVfsExceptionFromPlugin(): void
    {
        Vfs::init($this->oLogger, 'SpyVfsPlugin,SpyVfsPlugin2');
        SpyVfsPlugin::$fnBuildFd = static function (): never {
            throw new VfsException('Access denied', true);
        };
        $this->loginStation(1, 5);

        $caught = null;
        try {
            $this->silent(fn() => Vfs::createFsHandle(1, 5, '$.HOME.FILE'));
        } catch (VfsException $e) {
            $caught = $e;
        }

        $this->assertInstanceOf(VfsException::class, $caught);
        $this->assertTrue($caught->isHard());
        $this->assertEmpty(SpyVfsPlugin2::$aCallLog, 'Second plugin must not be tried after hard exception');
    }

    // A bare "*command" name not found in the CSD (e.g. via *RUN, or the OPEN
    // that backs it) must also be searched for in the user's library
    // directory before giving up.
    public function testCreateFsHandleFallsBackToLibraryWhenPluginReturnsNullForCsd(): void
    {
        $oUser = $this->makeUser();
        $oLibFd = new FileDescriptor(
            $this->oLogger, SpyVfsPlugin::class, $oUser,
            '/tmp/spy', '$.LIBRARY.CMD', 'spy-lib', 4, true, false
        );
        SpyVfsPlugin::$fnBuildFd = static function($u, FilePath $oPath) use ($oLibFd) {
            return $oPath->getFilePath() === '$.LIBRARY.CMD' ? $oLibFd : null;
        };
        $this->loginStation(1, 5, $oUser);

        $oResult = $this->silent(fn() => Vfs::createFsHandle(1, 5, 'CMD', true, true));
        $this->assertSame($oLibFd, $oResult);
    }

    // Mirrors LocalFile's real behaviour for a missing bare file: it never
    // throws, it returns a "phantom" descriptor that is neither a file nor a
    // directory. That must also trigger the library fallback.
    public function testCreateFsHandleFallsBackToLibraryWhenCsdDescriptorIsPhantom(): void
    {
        $oUser = $this->makeUser();
        $oPhantomFd = new FileDescriptor(
            $this->oLogger, SpyVfsPlugin::class, $oUser,
            '/tmp/spy', '$.HOME.CMD', null, 1, false, false // neither file nor dir
        );
        $oLibFd = new FileDescriptor(
            $this->oLogger, SpyVfsPlugin::class, $oUser,
            '/tmp/spy', '$.LIBRARY.CMD', 'spy-lib', 4, true, false
        );
        SpyVfsPlugin::$fnBuildFd = static function($u, FilePath $oPath) use ($oPhantomFd, $oLibFd) {
            return $oPath->getFilePath() === '$.LIBRARY.CMD' ? $oLibFd : $oPhantomFd;
        };
        $this->loginStation(1, 5, $oUser);

        $oResult = $this->silent(fn() => Vfs::createFsHandle(1, 5, 'CMD', true, true));
        $this->assertSame($oLibFd, $oResult);
    }

    public function testCreateFsHandleDoesNotFallBackToLibraryWhenDirectoryRequested(): void
    {
        $oUser = $this->makeUser();
        $oLibFd = new FileDescriptor(
            $this->oLogger, SpyVfsPlugin::class, $oUser,
            '/tmp/spy', '$.LIBRARY.CMD', 'spy-lib', 4, true, false
        );
        SpyVfsPlugin::$fnBuildFd = static function($u, FilePath $oPath) use ($oLibFd) {
            return $oPath->getFilePath() === '$.LIBRARY.CMD' ? $oLibFd : null;
        };
        $this->loginStation(1, 5, $oUser);

        $this->expectException(\Exception::class);
        $this->silent(fn() => Vfs::createFsHandle(1, 5, 'CMD', true, true, true));
    }

    public function testCreateFsHandleDoesNotFallBackToLibraryForQualifiedPath(): void
    {
        $oUser = $this->makeUser();
        $oLibFd = new FileDescriptor(
            $this->oLogger, SpyVfsPlugin::class, $oUser,
            '/tmp/spy', '$.LIBRARY.CMD', 'spy-lib', 4, true, false
        );
        SpyVfsPlugin::$fnBuildFd = static function($u, FilePath $oPath) use ($oLibFd) {
            return $oPath->getFilePath() === '$.LIBRARY.CMD' ? $oLibFd : null;
        };
        $this->loginStation(1, 5, $oUser);

        $this->expectException(\Exception::class);
        $this->silent(fn() => Vfs::createFsHandle(1, 5, '$.HOME.CMD', true, true));
    }

    // =========================================================================
    // replaceFsHandle()
    // =========================================================================

    public function testReplaceFsHandleSwapsHandles(): void
    {
        $oOld = $this->makeHandle('$.FILE.OLD', 1);
        $oNew = $this->makeHandle('$.FILE.NEW', 2);
        $this->registerHandle(1, 5, $oOld);
        $this->registerHandle(1, 5, $oNew);

        Vfs::replaceFsHandle(1, 5, 1, 2);

        $aHandles = $this->getVfsProp('aHandles');
        // Handle slot 1 should now point to $oNew
        $this->assertSame($oNew, $aHandles[1][5][1]);
    }

    public function testReplaceFsHandleIsNoOpIfHandleToReplaceNotFound(): void
    {
        $oNew = $this->makeHandle('$.FILE.NEW', 2);
        $this->registerHandle(1, 5, $oNew);

        Vfs::replaceFsHandle(1, 5, 99, 2); // 99 doesn't exist

        $aHandles = $this->getVfsProp('aHandles');
        $this->assertArrayNotHasKey(99, $aHandles[1][5] ?? []);
    }

    public function testReplaceFsHandleIsNoOpIfNewHandleNotFound(): void
    {
        $oOld = $this->makeHandle('$.FILE.OLD', 1);
        $this->registerHandle(1, 5, $oOld);

        Vfs::replaceFsHandle(1, 5, 1, 99); // 99 doesn't exist — old handle must stay

        $aHandles = $this->getVfsProp('aHandles');
        $this->assertSame($oOld, $aHandles[1][5][1]);
    }

    // =========================================================================
    // getFsHandle()
    // =========================================================================

    public function testGetFsHandleReturnsRegisteredHandle(): void
    {
        $oH = $this->makeHandle('$.HOME.FILE', 5);
        $this->registerHandle(1, 7, $oH);

        $oResult = Vfs::getFsHandle(1, 7, 5);
        $this->assertSame($oH, $oResult);
    }

    public function testGetFsHandleThrowsForUnregisteredHandle(): void
    {
        $this->expectException(\Exception::class);
        Vfs::getFsHandle(1, 5, 99);
    }

    public function testGetFsHandleFallsBackToFirstHandleOnHandleZero(): void
    {
        // NFS ROM sends handle 0 for directory listings; Vfs should return the first
        // registered handle in that case (the quirk is documented in the source).
        $oH = $this->makeHandle('$.HOME', 3, false, true);
        $this->registerHandle(1, 5, $oH);

        $oResult = Vfs::getFsHandle(1, 5, 0);
        $this->assertSame($oH, $oResult);
    }

    public function testGetFsHandleThrowsWhenStationHasNoHandlesAtAll(): void
    {
        $this->expectException(\Exception::class);
        Vfs::getFsHandle(1, 5, 0);
    }

    // =========================================================================
    // houseKeeping()
    // =========================================================================

    public function testHouseKeepingCallsPluginHouseKeeping(): void
    {
        $bCalled = false;
        SpyVfsPlugin::$fnHouseKeeping = static function () use (&$bCalled): void { $bCalled = true; };

        Vfs::houseKeeping();

        $this->assertTrue($bCalled);
    }

    public function testHouseKeepingCallsAllPluginsHouseKeeping(): void
    {
        Vfs::init($this->oLogger, 'SpyVfsPlugin,SpyVfsPlugin2');
        Vfs::houseKeeping();

        $aMethods1 = array_column(SpyVfsPlugin::$aCallLog,  'method');
        $aMethods2 = array_column(SpyVfsPlugin2::$aCallLog, 'method');
        $this->assertContains('houseKeeping', $aMethods1);
        $this->assertContains('houseKeeping', $aMethods2);
    }

    public function testHouseKeepingClosesHandlesForExpiredSessions(): void
    {
        // Register a handle for net=1, stn=5 WITHOUT a Security session.
        // houseKeeping() should detect that station (1,5) is not logged in and
        // close the handle.
        $oH = $this->makeHandle('$.HOME.FILE', 1, true, false, 'h-expired');
        $this->registerHandle(1, 5, $oH);

        Vfs::houseKeeping();

        $aHandles = $this->getVfsProp('aHandles');
        $this->assertEmpty($aHandles[1][5] ?? [], 'Handles for expired session must be removed');
    }

    public function testHouseKeepingRetainsHandlesForLoggedInStation(): void
    {
        $oUser = $this->makeUser();
        $this->loginStation(1, 5, $oUser);
        $oH = $this->makeHandle('$.HOME.FILE', 1, false, true); // dir handle
        $this->registerHandle(1, 5, $oH);

        Vfs::houseKeeping();

        $aHandles = $this->getVfsProp('aHandles');
        $this->assertArrayHasKey(1, $aHandles[1][5] ?? [], 'Handle for logged-in session must be kept');
    }

    public function testHouseKeepingResetsSinMappingWhenNoUsersOnline(): void
    {
        // Populate the SIN mapping
        Vfs::getSin('$.HOME.A');
        Vfs::getSin('$.HOME.B');

        // No sessions — getUsersOnline() returns []
        Vfs::houseKeeping();

        $aSinMapping = $this->getVfsProp('aSinMapping');
        $this->assertEmpty($aSinMapping, 'SIN mapping must be cleared when no users are online');

        $iSin = $this->getVfsProp('iSin');
        $this->assertSame(1, $iSin, 'SIN counter must reset to 1');
    }

    public function testHouseKeepingPreservesSinMappingWhenUsersAreOnline(): void
    {
        $this->loginStation(1, 5);
        Vfs::getSin('$.HOME.KEEP');

        Vfs::houseKeeping();

        $aSinMapping = $this->getVfsProp('aSinMapping');
        $this->assertArrayHasKey('$.HOME.KEEP', $aSinMapping, 'SIN mapping must be kept while users are logged in');
    }
}
