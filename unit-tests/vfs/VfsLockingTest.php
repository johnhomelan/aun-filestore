<?php

/*
 * @group unit-tests
 *
 * Tests for Vfs-layer lock enforcement and plugin call propagation.
 *
 * The locking logic lives in the private Vfs::_acquireLock() and
 * Vfs::_releaseLock() methods.  Rather than exercising them through
 * createFsHandle() (which carries a Security dependency), we call them
 * directly via ReflectionMethod and supply FileDescriptor objects that
 * delegate to MockVfsPlugin — a spy defined below that records every
 * fsLock / fsUnlock call.
 *
 * This approach lets every test run in complete isolation:
 *   • No filesystem access
 *   • No Security / session state
 *   • Vfs static state is fully reset between tests
 */

include_once(__DIR__ . '/../../src/include/system.inc.php');

use PHPUnit\Framework\TestCase;
use Monolog\Logger;
use Monolog\Handler\NullHandler;
use HomeLan\FileStore\Vfs\Vfs;
use HomeLan\FileStore\Vfs\FileDescriptor;
use HomeLan\FileStore\Vfs\DirectoryEntry;
use HomeLan\FileStore\Vfs\Exception as VfsException;
use HomeLan\FileStore\Vfs\Plugin\PluginInterface;
use HomeLan\FileStore\Vfs\FilePath;
use HomeLan\FileStore\Authentication\User;

// ---------------------------------------------------------------------------
// Spy plugin — records every fsLock / fsUnlock call in static arrays.
// All other PluginInterface methods are no-ops so we never touch the
// filesystem or any real resource.
// ---------------------------------------------------------------------------
class MockVfsPlugin implements PluginInterface
{
    /**
     * Each entry: ['fHandle' => mixed, 'bExclusive' => bool]
     * @var array<int,array{fHandle:mixed,bExclusive:bool}>
     */
    static public array $aFsLockCalls = [];

    /**
     * Each entry: ['fHandle' => mixed]
     * @var array<int,array{fHandle:mixed}>
     */
    static public array $aFsUnlockCalls = [];

    static public function reset(): void
    {
        self::$aFsLockCalls   = [];
        self::$aFsUnlockCalls = [];
    }

    // ---- PluginInterface — all no-ops except fsLock / fsUnlock ----

    static public function init(\Psr\Log\LoggerInterface $oLogger, bool $bMultiuser = false): void {}
    static public function houseKeeping(): void {}

    static public function _buildFiledescriptorFromEconetPath(User $oUser, FilePath $oEconetPath, bool $bMustExist, bool $bReadOnly): ?FileDescriptor
    {
        return null;
    }

    static public function _getAccessMode(int $iGid, int $iUid, int $iMode): string { return ''; }

    /**
     * @param array<string,DirectoryEntry> $aDirectoryListing
     * @return array<string,DirectoryEntry>
     */
    static public function getDirectoryListing(string $sEconetPath, array $aDirectoryListing): array
    {
        return $aDirectoryListing;
    }

    static public function createDirectory(User $oUser, FilePath $oPath): bool  { return false; }
    static public function deleteFile(User $oUser, FilePath $oEconetPath): bool  { return false; }
    static public function moveFile(User $oUser, FilePath $oFrom, FilePath $oTo): bool { return false; }
    static public function saveFile(User $oUser, FilePath $oPath, string $sData, int $iLoadAddr, int $iExecAddr): bool { return false; }
    static public function createFile(User $oUser, FilePath $oPath, int $iSize, int $iLoadAddr, int $iExecAddr): bool { return false; }
    static public function getFile(User $oUser, FilePath $oEconetPath): string { return ''; }
    static public function setMeta(string $sEconetPath, ?int $iLoad, ?int $iExec, ?int $iAccess): void {}

    static public function fsFtell(User $oUser, mixed $fLocalHandle): int  { return 0; }
    /** @return array<mixed> */
    static public function fsFStat(User $oUser, mixed $fLocalHandle): array { return []; }
    static public function isEof(User $oUser, mixed $fLocalHandle): bool    { return true; }
    static public function setPos(User $oUser, mixed $fLocalHandle, int $iPos): void {}
    static public function setExt(User $oUser, mixed $fLocalHandle, int $iExt): void {}
    static public function read(User $oUser, mixed $fLocalHandle, int $iLength): string { return ''; }
    static public function write(User $oUser, mixed $fLocalHandle, string $sData): int     { return 0; }

    static public function fsLock(User $oUser, mixed $fLocalHandle, bool $bExclusive): void
    {
        self::$aFsLockCalls[] = ['fHandle' => $fLocalHandle, 'bExclusive' => $bExclusive];
    }

    static public function fsUnlock(User $oUser, mixed $fLocalHandle): void
    {
        self::$aFsUnlockCalls[] = ['fHandle' => $fLocalHandle];
    }

    static public function fsClose(User $oUser, mixed $fLocalHandle): void {}
}

// ---------------------------------------------------------------------------

class VfsLockingTest extends TestCase
{
    private \Psr\Log\LoggerInterface $oLogger;
    private User $oUser;

    // ---- Setup / teardown --------------------------------------------------

    protected function setUp(): void
    {
        $this->oLogger = new Logger('test');
        $this->oLogger->pushHandler(new NullHandler());
        $this->oUser = $this->createMock(User::class);
        MockVfsPlugin::reset();
        $this->resetVfsState();
    }

    protected function tearDown(): void
    {
        $this->resetVfsState();
    }

    // ---- Helpers -----------------------------------------------------------

    /** Wipe all Vfs static state so each test starts from a clean slate. */
    private function resetVfsState(): void
    {
        foreach (['aLocks', 'aHandleLocks', 'aHandles', 'aFileHandleIDs'] as $sProp) {
            $rp = new \ReflectionProperty(Vfs::class, $sProp);
            $rp->setAccessible(true);
            $rp->setValue(null, []);
        }
    }

    private function getStaticProp(string $sProp): mixed
    {
        $rp = new \ReflectionProperty(Vfs::class, $sProp);
        $rp->setAccessible(true);
        return $rp->getValue(null);
    }

    /**
     * Build a FileDescriptor that delegates all plugin calls to MockVfsPlugin.
     *
     * $sVfsHandle is the token MockVfsPlugin will receive as $fLocalHandle in
     * fsLock / fsUnlock.  Defaults to "handle-{$iEconetHandle}" if omitted.
     */
    private function makeHandle(string $sEconetPath, int $iEconetHandle, string $sVfsHandle = ''): FileDescriptor
    {
        if ($sVfsHandle === '') {
            $sVfsHandle = "handle-{$iEconetHandle}";
        }
        return new FileDescriptor(
            $this->oLogger,
            MockVfsPlugin::class,
            $this->oUser,
            '/tmp/mock/' . $sVfsHandle,
            $sEconetPath,
            $sVfsHandle,
            $iEconetHandle,
            true,   // bFile  — locking only applies to file handles
            false   // bDir
        );
    }

    /**
     * Inject a FileDescriptor directly into Vfs::$aHandles so that
     * closeFsHandle() / closeAllFsHandles() can find it.
     */
    private function registerHandle(int $iNet, int $iStn, FileDescriptor $oHandle): void
    {
        $rp = new \ReflectionProperty(Vfs::class, 'aHandles');
        $rp->setAccessible(true);
        $aHandles = $rp->getValue(null);
        $aHandles[$iNet][$iStn][$oHandle->getID()] = $oHandle;
        $rp->setValue(null, $aHandles);
    }

    /** Build a directory (non-file) FileDescriptor — should never acquire a lock. */
    private function makeDirHandle(string $sEconetPath, int $iEconetHandle): FileDescriptor
    {
        return new FileDescriptor(
            $this->oLogger,
            MockVfsPlugin::class,
            $this->oUser,
            '/tmp/mock/dir',
            $sEconetPath,
            "dir-handle-{$iEconetHandle}",
            $iEconetHandle,
            false,  // bFile
            true    // bDir
        );
    }

    private function acquireLock(int $iNet, int $iStn, FileDescriptor $oHandle, bool $bReadOnly): void
    {
        $rm = new \ReflectionMethod(Vfs::class, '_acquireLock');
        $rm->setAccessible(true);
        $rm->invoke(null, $iNet, $iStn, $oHandle, $bReadOnly);
    }

    private function releaseLock(int $iNet, int $iStn, int $iHandleId, FileDescriptor $oHandle): void
    {
        $rm = new \ReflectionMethod(Vfs::class, '_releaseLock');
        $rm->setAccessible(true);
        $rm->invoke(null, $iNet, $iStn, $iHandleId, $oHandle);
    }

    // -----------------------------------------------------------------------
    // Lock acquisition — allowed cases
    // -----------------------------------------------------------------------

    public function testReadOpenAllowedWhenNoPriorHandles(): void
    {
        $oH = $this->makeHandle('$.MYFILE', 1);
        $this->acquireLock(1, 5, $oH, true);

        $aLocks = $this->getStaticProp('aLocks');
        $this->assertSame(1, $aLocks['$.MYFILE']['readers']);
        $this->assertSame(0, $aLocks['$.MYFILE']['writers']);
    }

    public function testWriteOpenAllowedWhenNoPriorHandles(): void
    {
        $oH = $this->makeHandle('$.MYFILE', 1);
        $this->acquireLock(1, 5, $oH, false);

        $aLocks = $this->getStaticProp('aLocks');
        $this->assertSame(0, $aLocks['$.MYFILE']['readers']);
        $this->assertSame(1, $aLocks['$.MYFILE']['writers']);
    }

    public function testConcurrentReadersAreAllowed(): void
    {
        // Multiple stations may hold read handles simultaneously.
        $oH1 = $this->makeHandle('$.MYFILE', 1);
        $oH2 = $this->makeHandle('$.MYFILE', 2);

        $this->acquireLock(1, 5, $oH1, true);
        $this->acquireLock(1, 6, $oH2, true); // must not throw

        $aLocks = $this->getStaticProp('aLocks');
        $this->assertSame(2, $aLocks['$.MYFILE']['readers']);
        $this->assertSame(0, $aLocks['$.MYFILE']['writers']);
    }

    public function testLocksAreIndependentAcrossFiles(): void
    {
        // A write lock on one file must not block a write open on a different file.
        $oHa = $this->makeHandle('$.FILE_A', 1);
        $oHb = $this->makeHandle('$.FILE_B', 2);

        $this->acquireLock(1, 5, $oHa, false);
        $this->acquireLock(1, 5, $oHb, false); // must not throw

        $aLocks = $this->getStaticProp('aLocks');
        $this->assertSame(1, $aLocks['$.FILE_A']['writers']);
        $this->assertSame(1, $aLocks['$.FILE_B']['writers']);
    }

    // -----------------------------------------------------------------------
    // Lock acquisition — conflict cases
    // -----------------------------------------------------------------------

    public function testReadOpenBlockedByExistingWriter(): void
    {
        $oHw = $this->makeHandle('$.MYFILE', 1);
        $oHr = $this->makeHandle('$.MYFILE', 2);
        $this->acquireLock(1, 5, $oHw, false);

        $caught = null;
        try {
            $this->acquireLock(1, 6, $oHr, true);
        } catch (VfsException $e) {
            $caught = $e;
        }

        $this->assertInstanceOf(VfsException::class, $caught);
        $this->assertTrue($caught->isLocked(), 'Exception must report isLocked()=true');
        $this->assertFalse($caught->isHard(),  'Lock-conflict exception must be soft (isHard=false)');
    }

    public function testWriteOpenBlockedByExistingReader(): void
    {
        $oHr = $this->makeHandle('$.MYFILE', 1);
        $oHw = $this->makeHandle('$.MYFILE', 2);
        $this->acquireLock(1, 5, $oHr, true);

        $caught = null;
        try {
            $this->acquireLock(1, 6, $oHw, false);
        } catch (VfsException $e) {
            $caught = $e;
        }

        $this->assertInstanceOf(VfsException::class, $caught);
        $this->assertTrue($caught->isLocked());
        $this->assertFalse($caught->isHard());
    }

    public function testWriteOpenBlockedByExistingWriter(): void
    {
        $oHw1 = $this->makeHandle('$.MYFILE', 1);
        $oHw2 = $this->makeHandle('$.MYFILE', 2);
        $this->acquireLock(1, 5, $oHw1, false);

        $caught = null;
        try {
            $this->acquireLock(1, 6, $oHw2, false);
        } catch (VfsException $e) {
            $caught = $e;
        }

        $this->assertInstanceOf(VfsException::class, $caught);
        $this->assertTrue($caught->isLocked());
    }

    public function testConflictDoesNotIncrementLockCounters(): void
    {
        // A rejected open must leave the lock table unchanged.
        $oHw = $this->makeHandle('$.MYFILE', 1);
        $oHr = $this->makeHandle('$.MYFILE', 2);
        $this->acquireLock(1, 5, $oHw, false);

        try {
            $this->acquireLock(1, 6, $oHr, true);
        } catch (VfsException) {}

        $aLocks = $this->getStaticProp('aLocks');
        $this->assertSame(0, $aLocks['$.MYFILE']['readers'], 'Blocked read must not increment reader count');
        $this->assertSame(1, $aLocks['$.MYFILE']['writers'], 'Writer count must be unchanged after conflict');
    }

    // -----------------------------------------------------------------------
    // Plugin propagation — fsLock
    // -----------------------------------------------------------------------

    public function testReadOpenCallsPluginFsLockWithSharedFlag(): void
    {
        $oH = $this->makeHandle('$.MYFILE', 1, 'h1');
        $this->acquireLock(1, 5, $oH, true);

        $this->assertCount(1, MockVfsPlugin::$aFsLockCalls);
        $aCall = MockVfsPlugin::$aFsLockCalls[0];
        $this->assertSame('h1', $aCall['fHandle']);
        $this->assertFalse($aCall['bExclusive'], 'Shared (read) lock must pass bExclusive=false to plugin');
    }

    public function testWriteOpenCallsPluginFsLockWithExclusiveFlag(): void
    {
        $oH = $this->makeHandle('$.MYFILE', 1, 'h1');
        $this->acquireLock(1, 5, $oH, false);

        $this->assertCount(1, MockVfsPlugin::$aFsLockCalls);
        $aCall = MockVfsPlugin::$aFsLockCalls[0];
        $this->assertSame('h1', $aCall['fHandle']);
        $this->assertTrue($aCall['bExclusive'], 'Exclusive (write) lock must pass bExclusive=true to plugin');
    }

    public function testBlockedOpenDoesNotCallPluginFsLock(): void
    {
        // A lock-conflict rejection must not reach the plugin's fsLock.
        $oHw = $this->makeHandle('$.MYFILE', 1);
        $oHr = $this->makeHandle('$.MYFILE', 2);

        $this->acquireLock(1, 5, $oHw, false);
        MockVfsPlugin::reset(); // discard the successful lock's call

        try {
            $this->acquireLock(1, 6, $oHr, true);
        } catch (VfsException) {}

        $this->assertEmpty(MockVfsPlugin::$aFsLockCalls, 'A blocked open must not propagate fsLock to the plugin');
    }

    public function testEachSuccessfulOpenCallsPluginFsLockOnce(): void
    {
        // Two concurrent reads must each trigger exactly one fsLock call.
        $oH1 = $this->makeHandle('$.MYFILE', 1, 'h1');
        $oH2 = $this->makeHandle('$.MYFILE', 2, 'h2');

        $this->acquireLock(1, 5, $oH1, true);
        $this->acquireLock(1, 6, $oH2, true);

        $this->assertCount(2, MockVfsPlugin::$aFsLockCalls);
        $this->assertSame('h1', MockVfsPlugin::$aFsLockCalls[0]['fHandle']);
        $this->assertSame('h2', MockVfsPlugin::$aFsLockCalls[1]['fHandle']);
    }

    // -----------------------------------------------------------------------
    // Plugin propagation — fsUnlock
    // -----------------------------------------------------------------------

    public function testReleaseReadHandleCallsPluginFsUnlock(): void
    {
        $oH = $this->makeHandle('$.MYFILE', 1, 'h1');
        $this->acquireLock(1, 5, $oH, true);
        MockVfsPlugin::reset();

        $this->releaseLock(1, 5, 1, $oH);

        $this->assertCount(1, MockVfsPlugin::$aFsUnlockCalls);
        $this->assertSame('h1', MockVfsPlugin::$aFsUnlockCalls[0]['fHandle']);
    }

    public function testReleaseWriteHandleCallsPluginFsUnlock(): void
    {
        $oH = $this->makeHandle('$.MYFILE', 1, 'h1');
        $this->acquireLock(1, 5, $oH, false);
        MockVfsPlugin::reset();

        $this->releaseLock(1, 5, 1, $oH);

        $this->assertCount(1, MockVfsPlugin::$aFsUnlockCalls);
        $this->assertSame('h1', MockVfsPlugin::$aFsUnlockCalls[0]['fHandle']);
    }

    public function testReleaseOfUnregisteredHandleIsNoOp(): void
    {
        // Releasing a handle that was never acquired must not call fsUnlock and
        // must not throw.
        $oH = $this->makeHandle('$.MYFILE', 99);
        $this->releaseLock(1, 5, 99, $oH);
        $this->assertEmpty(MockVfsPlugin::$aFsUnlockCalls);
    }

    public function testFsUnlockNotCalledOnLockedConflict(): void
    {
        // A failed lock acquisition calls fsClose on the rejected handle (to
        // clean up) but must never call fsUnlock.
        $oHw = $this->makeHandle('$.MYFILE', 1);
        $oHr = $this->makeHandle('$.MYFILE', 2);
        $this->acquireLock(1, 5, $oHw, false);
        MockVfsPlugin::reset();

        try {
            $this->acquireLock(1, 6, $oHr, true);
        } catch (VfsException) {}

        $this->assertEmpty(MockVfsPlugin::$aFsUnlockCalls, 'fsUnlock must not be called for a blocked open');
    }

    // -----------------------------------------------------------------------
    // Internal state — handle-lock tracking
    // -----------------------------------------------------------------------

    public function testHandleLockRecordedAsReadAfterReadOpen(): void
    {
        $oH = $this->makeHandle('$.MYFILE', 3);
        $this->acquireLock(1, 5, $oH, true);

        $aHandleLocks = $this->getStaticProp('aHandleLocks');
        $this->assertSame('read', $aHandleLocks[1][5][3]);
    }

    public function testHandleLockRecordedAsWriteAfterWriteOpen(): void
    {
        $oH = $this->makeHandle('$.MYFILE', 3);
        $this->acquireLock(1, 5, $oH, false);

        $aHandleLocks = $this->getStaticProp('aHandleLocks');
        $this->assertSame('write', $aHandleLocks[1][5][3]);
    }

    public function testHandleLockEntryRemovedAfterRelease(): void
    {
        $oH = $this->makeHandle('$.MYFILE', 3);
        $this->acquireLock(1, 5, $oH, false);
        $this->releaseLock(1, 5, 3, $oH);

        $aHandleLocks = $this->getStaticProp('aHandleLocks');
        $this->assertArrayNotHasKey(3, $aHandleLocks[1][5] ?? []);
    }

    // -----------------------------------------------------------------------
    // Internal state — lock-table lifecycle
    // -----------------------------------------------------------------------

    public function testLockTableEntryCreatedOnFirstAcquire(): void
    {
        $oH = $this->makeHandle('$.NEWFILE', 1);
        $aLocksBefore = $this->getStaticProp('aLocks');
        $this->assertArrayNotHasKey('$.NEWFILE', $aLocksBefore);

        $this->acquireLock(1, 5, $oH, true);

        $aLocksAfter = $this->getStaticProp('aLocks');
        $this->assertArrayHasKey('$.NEWFILE', $aLocksAfter);
    }

    public function testLockTableEntryRemovedWhenLastHandleReleased(): void
    {
        $oH = $this->makeHandle('$.MYFILE', 1);
        $this->acquireLock(1, 5, $oH, false);
        $this->releaseLock(1, 5, 1, $oH);

        $aLocks = $this->getStaticProp('aLocks');
        $this->assertArrayNotHasKey('$.MYFILE', $aLocks, 'Lock table entry must be removed when no handles remain');
    }

    public function testLockTableRetainedWhileSecondReaderStillOpen(): void
    {
        $oH1 = $this->makeHandle('$.MYFILE', 1);
        $oH2 = $this->makeHandle('$.MYFILE', 2);
        $this->acquireLock(1, 5, $oH1, true);
        $this->acquireLock(1, 6, $oH2, true);

        $this->releaseLock(1, 5, 1, $oH1); // release first reader only

        $aLocks = $this->getStaticProp('aLocks');
        $this->assertArrayHasKey('$.MYFILE', $aLocks);
        $this->assertSame(1, $aLocks['$.MYFILE']['readers'], 'One reader must remain in the lock table');
    }

    // -----------------------------------------------------------------------
    // Lock release enables subsequent opens
    // -----------------------------------------------------------------------

    public function testWriteAllowedAfterAllReadersReleased(): void
    {
        $oHr = $this->makeHandle('$.MYFILE', 1);
        $oHw = $this->makeHandle('$.MYFILE', 2);

        $this->acquireLock(1, 5, $oHr, true);
        $this->releaseLock(1, 5, 1, $oHr);

        // After the reader is gone a write open must succeed.
        $this->acquireLock(1, 5, $oHw, false);

        $aLocks = $this->getStaticProp('aLocks');
        $this->assertSame(1, $aLocks['$.MYFILE']['writers']);
    }

    public function testReadAllowedAfterWriterReleased(): void
    {
        $oHw = $this->makeHandle('$.MYFILE', 1);
        $oHr = $this->makeHandle('$.MYFILE', 2);

        $this->acquireLock(1, 5, $oHw, false);
        $this->releaseLock(1, 5, 1, $oHw);

        // After the writer is gone a read open must succeed.
        $this->acquireLock(1, 5, $oHr, true);

        $aLocks = $this->getStaticProp('aLocks');
        $this->assertSame(1, $aLocks['$.MYFILE']['readers']);
    }

    // -----------------------------------------------------------------------
    // closeFsHandle — releases the lock and removes the handle
    // -----------------------------------------------------------------------

    public function testCloseFsHandleCallsFsUnlockOnPlugin(): void
    {
        $oH = $this->makeHandle('$.MYFILE', 1, 'h1');
        $this->acquireLock(1, 5, $oH, true);
        $this->registerHandle(1, 5, $oH);
        MockVfsPlugin::reset();

        Vfs::closeFsHandle(1, 5, 1);

        $this->assertCount(1, MockVfsPlugin::$aFsUnlockCalls);
        $this->assertSame('h1', MockVfsPlugin::$aFsUnlockCalls[0]['fHandle']);
    }

    public function testCloseFsHandleClearsLockTable(): void
    {
        $oH = $this->makeHandle('$.MYFILE', 1);
        $this->acquireLock(1, 5, $oH, false);
        $this->registerHandle(1, 5, $oH);

        Vfs::closeFsHandle(1, 5, 1);

        $aLocks = $this->getStaticProp('aLocks');
        $this->assertArrayNotHasKey('$.MYFILE', $aLocks, 'Lock table entry must be removed after closeFsHandle');
    }

    public function testCloseFsHandleRemovesHandleFromRegistry(): void
    {
        $oH = $this->makeHandle('$.MYFILE', 1);
        $this->acquireLock(1, 5, $oH, false);
        $this->registerHandle(1, 5, $oH);

        Vfs::closeFsHandle(1, 5, 1);

        $aHandles = $this->getStaticProp('aHandles');
        $this->assertArrayNotHasKey(1, $aHandles[1][5] ?? [], 'Handle must be removed from registry after close');
    }

    public function testCloseFsHandleOnUnknownHandleIsNoOp(): void
    {
        // Closing a handle that was never registered must not throw and must
        // not call fsUnlock.
        Vfs::closeFsHandle(1, 5, 42);
        $this->assertEmpty(MockVfsPlugin::$aFsUnlockCalls);
    }

    public function testCloseFsHandleDoesNotCallFsUnlockForDirectoryHandle(): void
    {
        // Directory handles are never locked; closeFsHandle must not attempt
        // to release a lock for them.
        $oDir = $this->makeDirHandle('$.HOME', 5);
        $this->registerHandle(1, 5, $oDir);

        Vfs::closeFsHandle(1, 5, 5);

        $this->assertEmpty(MockVfsPlugin::$aFsUnlockCalls, 'Directory close must not call fsUnlock');
    }

    public function testCloseFsHandleOnlyReleasesTheLockOfTheNamedHandle(): void
    {
        // Two readers are open; closing one must decrement the reader count
        // to 1 and leave the second handle's lock intact.
        $oH1 = $this->makeHandle('$.MYFILE', 1, 'h1');
        $oH2 = $this->makeHandle('$.MYFILE', 2, 'h2');
        $this->acquireLock(1, 5, $oH1, true);
        $this->acquireLock(1, 6, $oH2, true);
        $this->registerHandle(1, 5, $oH1);
        $this->registerHandle(1, 6, $oH2);

        Vfs::closeFsHandle(1, 5, 1);

        $aLocks = $this->getStaticProp('aLocks');
        $this->assertSame(1, $aLocks['$.MYFILE']['readers'], 'One reader must remain after closing the first handle');
        $this->assertCount(1, MockVfsPlugin::$aFsUnlockCalls);
        $this->assertSame('h1', MockVfsPlugin::$aFsUnlockCalls[0]['fHandle']);
    }

    // -----------------------------------------------------------------------
    // closeAllFsHandles — releases every lock for a station
    // -----------------------------------------------------------------------

    public function testCloseAllFsHandlesCallsFsUnlockForEachFileHandle(): void
    {
        $oH1 = $this->makeHandle('$.FILE1', 1, 'h1');
        $oH2 = $this->makeHandle('$.FILE2', 2, 'h2');
        $this->acquireLock(1, 5, $oH1, true);
        $this->acquireLock(1, 5, $oH2, false);
        $this->registerHandle(1, 5, $oH1);
        $this->registerHandle(1, 5, $oH2);
        MockVfsPlugin::reset();

        Vfs::closeAllFsHandles(1, 5);

        $this->assertCount(2, MockVfsPlugin::$aFsUnlockCalls);
        $aHandles = array_column(MockVfsPlugin::$aFsUnlockCalls, 'fHandle');
        $this->assertContains('h1', $aHandles);
        $this->assertContains('h2', $aHandles);
    }

    public function testCloseAllFsHandlesClearsAllLockTableEntries(): void
    {
        $oH1 = $this->makeHandle('$.FILE1', 1);
        $oH2 = $this->makeHandle('$.FILE2', 2);
        $this->acquireLock(1, 5, $oH1, true);
        $this->acquireLock(1, 5, $oH2, false);
        $this->registerHandle(1, 5, $oH1);
        $this->registerHandle(1, 5, $oH2);

        Vfs::closeAllFsHandles(1, 5);

        $aLocks = $this->getStaticProp('aLocks');
        $this->assertArrayNotHasKey('$.FILE1', $aLocks);
        $this->assertArrayNotHasKey('$.FILE2', $aLocks);
    }

    public function testCloseAllFsHandlesClearsHandleRegistry(): void
    {
        $oH1 = $this->makeHandle('$.FILE1', 1);
        $oH2 = $this->makeHandle('$.FILE2', 2);
        $this->acquireLock(1, 5, $oH1, true);
        $this->acquireLock(1, 5, $oH2, false);
        $this->registerHandle(1, 5, $oH1);
        $this->registerHandle(1, 5, $oH2);

        Vfs::closeAllFsHandles(1, 5);

        $aHandles = $this->getStaticProp('aHandles');
        $this->assertEmpty($aHandles[1][5] ?? [], 'All handles must be removed from registry after closeAllFsHandles');
    }

    public function testCloseAllFsHandlesSkipsFsUnlockForDirectoryHandles(): void
    {
        // A mix of one file handle and one directory handle: only the file
        // handle's lock must be released.
        $oFile = $this->makeHandle('$.DOC', 1, 'h-file');
        $oDir  = $this->makeDirHandle('$.HOME', 2);
        $this->acquireLock(1, 5, $oFile, false);
        $this->registerHandle(1, 5, $oFile);
        $this->registerHandle(1, 5, $oDir);
        MockVfsPlugin::reset();

        Vfs::closeAllFsHandles(1, 5);

        $this->assertCount(1, MockVfsPlugin::$aFsUnlockCalls, 'Only the file handle must trigger fsUnlock');
        $this->assertSame('h-file', MockVfsPlugin::$aFsUnlockCalls[0]['fHandle']);
    }

    public function testCloseAllFsHandlesOnStationWithNoHandlesIsNoOp(): void
    {
        // Must return silently without touching any other station's handles.
        $oH = $this->makeHandle('$.FILE', 1);
        $this->acquireLock(2, 10, $oH, false);
        $this->registerHandle(2, 10, $oH);

        Vfs::closeAllFsHandles(1, 5); // different station — must be a no-op

        $aLocks = $this->getStaticProp('aLocks');
        $this->assertArrayHasKey('$.FILE', $aLocks, 'Another station\'s locks must be untouched');
    }

    public function testCloseAllFsHandlesDoesNotAffectOtherStationsHandles(): void
    {
        // Handles for station (1,5) and (1,6) are registered.  Closing all
        // for (1,5) must leave (1,6)'s handles and locks intact.
        $oH5 = $this->makeHandle('$.FILE5', 1, 'h5');
        $oH6 = $this->makeHandle('$.FILE6', 2, 'h6');
        $this->acquireLock(1, 5, $oH5, false);
        $this->acquireLock(1, 6, $oH6, true);
        $this->registerHandle(1, 5, $oH5);
        $this->registerHandle(1, 6, $oH6);
        MockVfsPlugin::reset();

        Vfs::closeAllFsHandles(1, 5);

        $aLocks = $this->getStaticProp('aLocks');
        $this->assertArrayNotHasKey('$.FILE5', $aLocks, 'Station 5\'s lock must be released');
        $this->assertArrayHasKey('$.FILE6', $aLocks,    'Station 6\'s lock must be untouched');

        $this->assertCount(1, MockVfsPlugin::$aFsUnlockCalls);
        $this->assertSame('h5', MockVfsPlugin::$aFsUnlockCalls[0]['fHandle']);
    }
}
