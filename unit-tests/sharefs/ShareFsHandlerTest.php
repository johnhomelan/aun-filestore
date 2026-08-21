<?php

/*
 * @group unit-tests
 *
 * Tests for ShareFs\ShareFsHandler - the ShareFS RPC command dispatcher (port 49171).
 *
 * Vfs is exercised through the SpyVfsPlugin test double (borrowed from
 * unit-tests/vfs/SpyVfsPlugins.php) so these tests cover ShareFsHandler's own
 * request/response/access-control logic without needing a real filesystem.
 */

require_once __DIR__ . '/../vfs/SpyVfsPlugins.php';

use PHPUnit\Framework\TestCase;
use Monolog\Logger;
use Monolog\Handler\NullHandler;
use HomeLan\FileStore\ShareFs\ShareFsHandler;
use HomeLan\FileStore\ShareFs\ShareAuthTable;
use HomeLan\FileStore\ShareFs\ShareList;
use HomeLan\FileStore\ShareFs\Messages\ShareFsPacket;
use HomeLan\FileStore\Vfs\Vfs;
use HomeLan\FileStore\Vfs\FileDescriptor;
use HomeLan\FileStore\Vfs\DirectoryEntry;
use HomeLan\FileStore\Vfs\Plugin\SpyVfsPlugin;
use HomeLan\FileStore\Authentication\Security;
use HomeLan\FileStore\Authentication\User;
use React\Datagram\Socket as DatagramSocket;

include_once(__DIR__ . '/../../src/include/system.inc.php');

class ShareFsHandlerTest extends TestCase
{
    private const string CLIENT_ADDRESS = '10.0.0.1:1234';
    private const string OTHER_ADDRESS  = '10.0.0.2:1234';
    private const int NETWORK = 254;
    private const int STATION = 1;

    private Logger $oLogger;
    private DatagramSocket $oSocket;
    private ShareFsHandler $oHandler;

    /** @var list<array{data:string, addr:string}> */
    private array $aSent = [];

    protected function setUp(): void
    {
        $this->oLogger = new Logger('test');
        $this->oLogger->pushHandler(new NullHandler());

        SpyVfsPlugin::reset();
        $this->resetVfsState();
        $this->resetSecurityState();
        Vfs::init($this->oLogger, 'SpyVfsPlugin');
        $this->loginServiceIdentity();

        ShareAuthTable::reset();
        ShareList::reset();
        ShareList::init($this->oLogger, "SHARE DISC0 \$.DISC0\nSHARE ARCHIVE \$.ARCHIVE readonly\nSHARE SECRET \$.SECRET protected secretpw\n");

        config::overrideValue('sharefs_service_network', self::NETWORK);
        config::overrideValue('sharefs_service_station', self::STATION);
        config::overrideValue('vfs_default_disc_free', 0x9000);
        config::overrideValue('vfs_default_disc_size', 0x9000);

        $this->aSent = [];
        $this->oSocket = $this->createMock(DatagramSocket::class);
        $this->oSocket->method('send')->willReturnCallback(function (string $sData, string $sAddr): void {
            $this->aSent[] = ['data' => $sData, 'addr' => $sAddr];
        });
        $this->oHandler = new ShareFsHandler($this->oLogger);
        $this->oHandler->setSocket($this->oSocket);
    }

    protected function tearDown(): void
    {
        SpyVfsPlugin::reset();
        $this->resetVfsState();
        $this->resetSecurityState();
        config::resetValue('sharefs_service_network');
        config::resetValue('sharefs_service_station');
        config::resetValue('vfs_default_disc_free');
        config::resetValue('vfs_default_disc_size');
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
    }

    private function resetSecurityState(): void
    {
        $rp = new \ReflectionProperty(Security::class, 'aSessions');
        $rp->setAccessible(true);
        $rp->setValue(null, []);
    }

    private function loginServiceIdentity(): void
    {
        $oUser = new User();
        $oUser->setUsername('SHAREFS');
        $rp = new \ReflectionProperty(Security::class, 'aSessions');
        $rp->setAccessible(true);
        $rp->setValue(null, [
            self::NETWORK => [
                self::STATION => ['idle' => time(), 'datetime' => time(), 'provider' => SpyVfsPlugin::class, 'user' => $oUser],
            ],
        ]);
    }

    private function aCmd(int $iCode, string $sBody, string $sRid = "\x01\x02\x03"): string
    {
        return 'A' . $sRid . pack('V', $iCode) . $sBody;
    }

    private function fCmd(int $iCode, int $iHandle = 0, string $sRid = "\x01\x02\x03"): string
    {
        return 'F' . $sRid . pack('V', $iCode) . pack('V', $iHandle);
    }

    private function pathBody(string $sPath): string
    {
        return pack('V', 0) . $sPath . "\x00";
    }

    private function makeHandle(string $sEconetPath, int $iEconetHandle, bool $bFile = true, bool $bDir = false): FileDescriptor
    {
        $oUser = Security::getUser(self::NETWORK, self::STATION);
        return new FileDescriptor(
            $this->oLogger,
            SpyVfsPlugin::class,
            $oUser,
            '/tmp/mock/' . $iEconetHandle,
            $sEconetPath,
            "vfs-handle-{$iEconetHandle}",
            $iEconetHandle,
            $bFile,
            $bDir
        );
    }

    private function registerHandle(FileDescriptor $oHandle): void
    {
        $rp = new \ReflectionProperty(Vfs::class, 'aHandles');
        $rp->setAccessible(true);
        $aHandles = $rp->getValue(null);
        $aHandles[self::NETWORK][self::STATION][$oHandle->getID()] = $oHandle;
        $rp->setValue(null, $aHandles);
    }

    private function lastSent(): array
    {
        $this->assertNotEmpty($this->aSent, 'Expected at least one reply to have been sent');
        return $this->aSent[count($this->aSent) - 1];
    }

    private function assertLastIsSuccess(): string
    {
        $aLast = $this->lastSent();
        $this->assertSame('R', $aLast['data'][0], 'Expected a success (R) reply, got: ' . bin2hex($aLast['data']));
        return substr($aLast['data'], 4);
    }

    private function assertLastIsError(int $iErrno): void
    {
        $aLast = $this->lastSent();
        $this->assertSame('E', $aLast['data'][0], 'Expected an error (E) reply, got: ' . bin2hex($aLast['data']));
        $aFields = unpack('Verrno', substr($aLast['data'], 4, 4));
        $this->assertSame($iErrno, $aFields['errno']);
    }

    // -------------------------------------------------------------------------
    // RVERSION - no auth required, works on any share/no share
    // -------------------------------------------------------------------------

    public function testRversionReturnsVersionTwo(): void
    {
        $this->oHandler->receive($this->fCmd(ShareFsPacket::CODE_RVERSION), self::CLIENT_ADDRESS);
        $sPayload = $this->assertLastIsSuccess();
        $aFields = unpack('Vversion', $sPayload);
        $this->assertSame(2, $aFields['version']);
    }

    // -------------------------------------------------------------------------
    // RFIND
    // -------------------------------------------------------------------------

    public function testFindReturnsFileDescForExistingFile(): void
    {
        SpyVfsPlugin::$fnGetDirListing = static fn(string $sPath, array $aListing) => $sPath === '$.DISC0'
            ? ['MYFILE' => new DirectoryEntry('MYFILE', 'myfile', SpyVfsPlugin::class, 0xFFF00A00, 0x12345678, 100, '$.DISC0.MYFILE', time(), 'WR/r')]
            : $aListing;

        $this->oHandler->receive($this->aCmd(ShareFsPacket::CODE_RFIND, $this->pathBody('DISC0.MYFILE')), self::CLIENT_ADDRESS);

        $sPayload = $this->assertLastIsSuccess();
        $aDesc = ShareFsPacket::decodeFileDesc($sPayload);
        $this->assertSame(100, $aDesc['length']);
        $this->assertSame(ShareFsPacket::TYPE_FILE, $aDesc['type']);
    }

    public function testFindOnUnknownShareIsError(): void
    {
        $this->oHandler->receive($this->aCmd(ShareFsPacket::CODE_RFIND, $this->pathBody('NOSUCH.FILE')), self::CLIENT_ADDRESS);
        $this->assertLastIsError(2); // ENOENT
    }

    public function testFindOnProtectedShareWithoutAuthIsDenied(): void
    {
        $this->oHandler->receive($this->aCmd(ShareFsPacket::CODE_RFIND, $this->pathBody('SECRET.FILE')), self::CLIENT_ADDRESS);
        $this->assertLastIsError(13); // EACCES
    }

    public function testFindOnProtectedShareWithAuthSucceeds(): void
    {
        ShareAuthTable::add('10.0.0.1', 'SECRET');
        SpyVfsPlugin::$fnGetDirListing = static fn(string $sPath, array $aListing) => $sPath === '$.SECRET'
            ? ['FILE' => new DirectoryEntry('FILE', 'file', SpyVfsPlugin::class, 0, 0, 10, '$.SECRET.FILE', time(), 'WR/r')]
            : $aListing;

        $this->oHandler->receive($this->aCmd(ShareFsPacket::CODE_RFIND, $this->pathBody('SECRET.FILE')), self::CLIENT_ADDRESS);
        $this->assertSame('R', $this->lastSent()['data'][0]);
    }

    // -------------------------------------------------------------------------
    // ROPENIN / ROPENUP
    // -------------------------------------------------------------------------

    public function testOpenInReturnsHandle(): void
    {
        $oFd = $this->makeHandle('$.DISC0.MYFILE', 10, true, false);
        SpyVfsPlugin::$fnBuildFd = static fn() => $oFd;
        SpyVfsPlugin::$fnGetDirListing = static fn(string $sPath, array $aListing) => $sPath === '$.DISC0'
            ? ['MYFILE' => new DirectoryEntry('MYFILE', 'myfile', SpyVfsPlugin::class, 0, 0, 5, '$.DISC0.MYFILE', time(), 'WR/r')]
            : $aListing;

        $this->oHandler->receive($this->aCmd(ShareFsPacket::CODE_ROPENIN, $this->pathBody('DISC0.MYFILE')), self::CLIENT_ADDRESS);

        $sPayload = $this->assertLastIsSuccess();
        $aFields = unpack('Vhandle', substr($sPayload, 20, 4));
        $this->assertSame(10, $aFields['handle']);
    }

    public function testOpenUpOnReadOnlyShareIsDenied(): void
    {
        $this->oHandler->receive($this->aCmd(ShareFsPacket::CODE_ROPENUP, $this->pathBody('ARCHIVE.FILE')), self::CLIENT_ADDRESS);
        $this->assertLastIsError(13); // EACCES
    }

    public function testOpenInOnReadOnlyShareIsAllowed(): void
    {
        $oFd = $this->makeHandle('$.ARCHIVE.FILE', 11, true, false);
        SpyVfsPlugin::$fnBuildFd = static fn() => $oFd;
        SpyVfsPlugin::$fnGetDirListing = static fn(string $sPath, array $aListing) => $sPath === '$.ARCHIVE'
            ? ['FILE' => new DirectoryEntry('FILE', 'file', SpyVfsPlugin::class, 0, 0, 5, '$.ARCHIVE.FILE', time(), 'WR/r')]
            : $aListing;

        $this->oHandler->receive($this->aCmd(ShareFsPacket::CODE_ROPENIN, $this->pathBody('ARCHIVE.FILE')), self::CLIENT_ADDRESS);
        $this->assertSame('R', $this->lastSent()['data'][0]);
    }

    public function testWriteOnAHandleOpenedFromAReadOnlyShareIsDenied(): void
    {
        $oFd = $this->makeHandle('$.ARCHIVE.FILE', 12, true, false);
        SpyVfsPlugin::$fnBuildFd = static fn() => $oFd;
        SpyVfsPlugin::$fnGetDirListing = static fn(string $sPath, array $aListing) => $sPath === '$.ARCHIVE'
            ? ['FILE' => new DirectoryEntry('FILE', 'file', SpyVfsPlugin::class, 0, 0, 5, '$.ARCHIVE.FILE', time(), 'WR/r')]
            : $aListing;

        // ROPENIN (read-only open) is allowed on a Read-only share ...
        $this->oHandler->receive($this->aCmd(ShareFsPacket::CODE_ROPENIN, $this->pathBody('ARCHIVE.FILE')), self::CLIENT_ADDRESS);
        $sPayload = $this->assertLastIsSuccess();
        $aFields = unpack('Vhandle', substr($sPayload, 20, 4));
        $this->assertSame(12, $aFields['handle']);

        // ... but a write-type command on the resulting handle is not.
        $sBody = pack('V', 12) . pack('V', 0) . pack('V', 5);
        $this->oHandler->receive($this->aCmd(ShareFsPacket::CODE_RWRITE, $sBody), self::CLIENT_ADDRESS);
        $this->assertLastIsError(13); // EACCES
    }

    public function testZeroOnAHandleOpenedFromAReadOnlyShareIsDenied(): void
    {
        $oFd = $this->makeHandle('$.ARCHIVE.FILE', 13, true, false);
        SpyVfsPlugin::$fnBuildFd = static fn() => $oFd;
        SpyVfsPlugin::$fnGetDirListing = static fn(string $sPath, array $aListing) => $sPath === '$.ARCHIVE'
            ? ['FILE' => new DirectoryEntry('FILE', 'file', SpyVfsPlugin::class, 0, 0, 5, '$.ARCHIVE.FILE', time(), 'WR/r')]
            : $aListing;

        $this->oHandler->receive($this->aCmd(ShareFsPacket::CODE_ROPENIN, $this->pathBody('ARCHIVE.FILE')), self::CLIENT_ADDRESS);
        $this->assertSame('R', $this->lastSent()['data'][0]);

        $sBody = pack('V', 13) . pack('V', 0) . pack('V', 5);
        $this->oHandler->receive($this->aCmd(ShareFsPacket::CODE_RZERO, $sBody), self::CLIENT_ADDRESS);
        $this->assertLastIsError(13); // EACCES
    }

    public function testWriteOnAHandleFromAWritableShareStillSucceeds(): void
    {
        $oFd = $this->makeHandle('$.DISC0.FILE', 14, true, false);
        SpyVfsPlugin::$fnBuildFd = static fn() => $oFd;
        SpyVfsPlugin::$fnGetDirListing = static fn(string $sPath, array $aListing) => $sPath === '$.DISC0'
            ? ['FILE' => new DirectoryEntry('FILE', 'file', SpyVfsPlugin::class, 0, 0, 5, '$.DISC0.FILE', time(), 'WR/r')]
            : $aListing;
        SpyVfsPlugin::$fnWrite = static fn($u, $h, string $sData) => strlen($sData);

        $this->oHandler->receive($this->aCmd(ShareFsPacket::CODE_ROPENUP, $this->pathBody('DISC0.FILE')), self::CLIENT_ADDRESS);
        $this->assertSame('R', $this->lastSent()['data'][0]);

        $sBody = pack('V', 14) . pack('V', 0) . pack('V', 5);
        $this->oHandler->receive($this->aCmd(ShareFsPacket::CODE_RWRITE, $sBody), self::CLIENT_ADDRESS);
        $this->assertSame('w', $this->lastSent()['data'][0]);
    }

    // -------------------------------------------------------------------------
    // Handle ownership
    // -------------------------------------------------------------------------

    public function testHandleCannotBeUsedByADifferentClient(): void
    {
        $oFd = $this->makeHandle('$.DISC0.FILE', 9, true, false);
        SpyVfsPlugin::$fnBuildFd = static fn() => $oFd;
        SpyVfsPlugin::$fnGetDirListing = static fn(string $sPath, array $aListing) => $sPath === '$.DISC0'
            ? ['FILE' => new DirectoryEntry('FILE', 'file', SpyVfsPlugin::class, 0, 0, 5, '$.DISC0.FILE', time(), 'WR/r')]
            : $aListing;

        $this->oHandler->receive($this->aCmd(ShareFsPacket::CODE_ROPENIN, $this->pathBody('DISC0.FILE')), self::CLIENT_ADDRESS);
        $this->assertSame('R', $this->lastSent()['data'][0]);

        $this->oHandler->receive($this->fCmd(ShareFsPacket::CODE_RGETSEQPTR, 9), self::OTHER_ADDRESS);
        $this->assertLastIsError(9); // EBADF
    }

    // -------------------------------------------------------------------------
    // RCREATE / RCREATEDIR / RDELETE / RACCESS / RCLOSE
    // -------------------------------------------------------------------------

    public function testCreateOnReadOnlyShareIsDenied(): void
    {
        $this->oHandler->receive($this->aCmd(ShareFsPacket::CODE_RCREATE, $this->pathBody('ARCHIVE.NEWFILE')), self::CLIENT_ADDRESS);
        $this->assertLastIsError(13); // EACCES
    }

    public function testCreateReturnsHandleAndFileDesc(): void
    {
        $oFd = $this->makeHandle('$.DISC0.NEWFILE', 20, true, false);
        SpyVfsPlugin::$fnCreateFile = static fn() => true;
        SpyVfsPlugin::$fnBuildFd = static fn() => $oFd;

        $this->oHandler->receive($this->aCmd(ShareFsPacket::CODE_RCREATE, $this->pathBody('DISC0.NEWFILE')), self::CLIENT_ADDRESS);

        $sPayload = $this->assertLastIsSuccess();
        $aFields = unpack('Vhandle', substr($sPayload, 20, 4));
        $this->assertSame(20, $aFields['handle']);
    }

    public function testCreateDirReturnsHandle(): void
    {
        SpyVfsPlugin::$fnCreateDirectory = static fn() => true;
        $oFd = $this->makeHandle('$.DISC0.NEWDIR', 21, false, true);
        SpyVfsPlugin::$fnBuildFd = static fn() => $oFd;
        SpyVfsPlugin::$fnGetDirListing = static fn(string $sPath, array $aListing) => $sPath === '$.DISC0'
            ? ['NEWDIR' => new DirectoryEntry('NEWDIR', 'newdir', SpyVfsPlugin::class, null, null, 0, '$.DISC0.NEWDIR', time(), 'WR/r', true)]
            : $aListing;

        $this->oHandler->receive($this->aCmd(ShareFsPacket::CODE_RCREATEDIR, $this->pathBody('DISC0.NEWDIR')), self::CLIENT_ADDRESS);

        $sPayload = $this->assertLastIsSuccess();
        $aDesc = ShareFsPacket::decodeFileDesc($sPayload);
        $this->assertSame(ShareFsPacket::TYPE_DIR, $aDesc['type']);
    }

    public function testDeleteReturnsFileDescOfDeletedItem(): void
    {
        SpyVfsPlugin::$fnDeleteFile = static fn() => true;
        SpyVfsPlugin::$fnGetDirListing = static fn(string $sPath, array $aListing) => $sPath === '$.DISC0'
            ? ['OLD' => new DirectoryEntry('OLD', 'old', SpyVfsPlugin::class, 0, 0, 42, '$.DISC0.OLD', time(), 'WR/r')]
            : $aListing;

        $this->oHandler->receive($this->aCmd(ShareFsPacket::CODE_RDELETE, $this->pathBody('DISC0.OLD')), self::CLIENT_ADDRESS);

        $sPayload = $this->assertLastIsSuccess();
        $aDesc = ShareFsPacket::decodeFileDesc($sPayload);
        $this->assertSame(42, $aDesc['length']);
    }

    public function testAccessReturnsUpdatedFileDesc(): void
    {
        SpyVfsPlugin::$fnSetMeta = static function () {};
        SpyVfsPlugin::$fnGetDirListing = static fn(string $sPath, array $aListing) => $sPath === '$.DISC0'
            ? ['FILE' => new DirectoryEntry('FILE', 'file', SpyVfsPlugin::class, 0, 0, 5, '$.DISC0.FILE', time(), 'WR/r')]
            : $aListing;

        $sBody = pack('V', 0x13) . pack('V', 0) . "DISC0.FILE\x00";
        $this->oHandler->receive($this->aCmd(ShareFsPacket::CODE_RACCESS, $sBody), self::CLIENT_ADDRESS);

        $this->assertSame('R', $this->lastSent()['data'][0]);
    }

    public function testFreeSpaceReturnsConfiguredValues(): void
    {
        $this->oHandler->receive($this->aCmd(ShareFsPacket::CODE_RFREESPACE, ''), self::CLIENT_ADDRESS);
        $sPayload = $this->assertLastIsSuccess();
        $aFields = unpack('Vfree/Vlargest/Vtotal', $sPayload);
        $this->assertSame(0x9000, $aFields['free']);
        $this->assertSame(0x9000, $aFields['total']);
    }

    public function testCloseReleasesTheHandle(): void
    {
        $oFd = $this->makeHandle('$.DISC0.FILE', 30, true, false);
        $this->registerHandle($oFd);
        $rp = new \ReflectionProperty(ShareFsHandler::class, 'aHandleOwners');
        $rp->setAccessible(true);
        $rp->setValue($this->oHandler, [30 => self::CLIENT_ADDRESS]);

        $this->oHandler->receive($this->fCmd(ShareFsPacket::CODE_RCLOSE, 30), self::CLIENT_ADDRESS);
        $this->assertSame('R', $this->lastSent()['data'][0]);

        $this->oHandler->receive($this->fCmd(ShareFsPacket::CODE_RGETSEQPTR, 30), self::CLIENT_ADDRESS);
        $this->assertLastIsError(9); // EBADF - handle no longer owned
    }

    // -------------------------------------------------------------------------
    // RREAD (single-chunk, completes immediately)
    // -------------------------------------------------------------------------

    public function testReadSendsDataThenSuccess(): void
    {
        $oFd = $this->makeHandle('$.DISC0.FILE', 40, true, false);
        $this->registerHandle($oFd);
        $rp = new \ReflectionProperty(ShareFsHandler::class, 'aHandleOwners');
        $rp->setAccessible(true);
        $rp->setValue($this->oHandler, [40 => self::CLIENT_ADDRESS]);

        SpyVfsPlugin::$fnRead = static fn($u, $h, int $iLength) => str_repeat('A', $iLength);

        $sBody = pack('V', 40) . pack('V', 0) . pack('V', 16);
        $this->oHandler->receive($this->aCmd(ShareFsPacket::CODE_RREAD, $sBody), self::CLIENT_ADDRESS);

        $this->assertCount(2, $this->aSent);
        $this->assertSame('D', $this->aSent[0]['data'][0]);
        $this->assertSame(str_repeat('A', 16), substr($this->aSent[0]['data'], 8));
        $this->assertSame('R', $this->aSent[1]['data'][0]);
    }

    // -------------------------------------------------------------------------
    // RWRITE (single-chunk, completes on the first 'd')
    // -------------------------------------------------------------------------

    public function testWriteRequestsThenAcceptsDataThenSucceeds(): void
    {
        $oFd = $this->makeHandle('$.DISC0.FILE', 41, true, false);
        $this->registerHandle($oFd);
        $rp = new \ReflectionProperty(ShareFsHandler::class, 'aHandleOwners');
        $rp->setAccessible(true);
        $rp->setValue($this->oHandler, [41 => self::CLIENT_ADDRESS]);

        SpyVfsPlugin::$fnWrite = static fn($u, $h, string $sData) => strlen($sData);

        $sBody = pack('V', 41) . pack('V', 0) . pack('V', 5);
        $this->oHandler->receive($this->aCmd(ShareFsPacket::CODE_RWRITE, $sBody, "\xAA\xBB\xCC"), self::CLIENT_ADDRESS);

        $this->assertSame('w', $this->lastSent()['data'][0]);
        $sRid = substr($this->lastSent()['data'], 1, 3);

        $sDataPacket = 'd' . $sRid . pack('V', 0) . 'HELLO';
        $this->oHandler->receive($sDataPacket, self::CLIENT_ADDRESS);

        $this->assertSame('R', $this->lastSent()['data'][0]);
    }

    // -------------------------------------------------------------------------
    // RRENAME (two-step)
    // -------------------------------------------------------------------------

    public function testRenameArmsThenCompletesOnNewName(): void
    {
        $aMoveArgs = null;
        SpyVfsPlugin::$fnMoveFile = static function ($u, $oFrom, $oTo) use (&$aMoveArgs) {
            $aMoveArgs = [$oFrom->getFilePath(), $oTo->getFilePath()];
            return true;
        };

        $sNewName = 'NEWNAME';
        $sBody = pack('V', strlen($sNewName)) . pack('V', 0) . "DISC0.OLDNAME\x00";
        $this->oHandler->receive($this->aCmd(ShareFsPacket::CODE_RRENAME, $sBody, "\xDD\xEE\xFF"), self::CLIENT_ADDRESS);

        $this->assertSame('w', $this->lastSent()['data'][0]);
        $sRid = substr($this->lastSent()['data'], 1, 3);

        $this->oHandler->receive('d' . $sRid . pack('V', 0) . $sNewName, self::CLIENT_ADDRESS);

        $this->assertSame('R', $this->lastSent()['data'][0]);
        $this->assertNotNull($aMoveArgs);
    }

    // -------------------------------------------------------------------------
    // RSETINFO / RGETSEQPTR / RSETSEQPTR / RZERO
    // -------------------------------------------------------------------------

    public function testSetInfoUpdatesLoadExecAndReturnsFileDesc(): void
    {
        $oFd = $this->makeHandle('$.DISC0.FILE', 50, true, false);
        $this->registerHandle($oFd);
        $rp = new \ReflectionProperty(ShareFsHandler::class, 'aHandleOwners');
        $rp->setAccessible(true);
        $rp->setValue($this->oHandler, [50 => self::CLIENT_ADDRESS]);

        SpyVfsPlugin::$fnSetMeta = static function () {};
        SpyVfsPlugin::$fnMoveFile = static fn() => true;
        SpyVfsPlugin::$fnGetDirListing = static fn(string $sPath, array $aListing) => $sPath === '$.DISC0'
            ? ['FILE' => new DirectoryEntry('FILE', 'file', SpyVfsPlugin::class, 0xFFF00A00, 0, 5, '$.DISC0.FILE', time(), 'WR/r'),
               'FILE,00a' => new DirectoryEntry('FILE,00a', 'file,00a', SpyVfsPlugin::class, 0xFFF00A00, 0, 5, '$.DISC0.FILE,00a', time(), 'WR/r')]
            : $aListing;

        // Setting this load address gives the file filetype 0x00a, which has no ",xxx" suffix
        // on its current name - RSETINFO renames it to add one, matching real behaviour.
        $sBody = pack('V', 50) . pack('V', 0xFFF00A00) . pack('V', 0x12345678);
        $this->oHandler->receive($this->aCmd(ShareFsPacket::CODE_RSETINFO, $sBody), self::CLIENT_ADDRESS);

        $this->assertSame('R', $this->lastSent()['data'][0]);
    }

    public function testGetAndSetSeqPtr(): void
    {
        $oFd = $this->makeHandle('$.DISC0.FILE', 51, true, false);
        $this->registerHandle($oFd);
        $rp = new \ReflectionProperty(ShareFsHandler::class, 'aHandleOwners');
        $rp->setAccessible(true);
        $rp->setValue($this->oHandler, [51 => self::CLIENT_ADDRESS]);

        SpyVfsPlugin::$fnSetPos = static function () {};

        $sBody = pack('V', 51) . pack('V', 100);
        $this->oHandler->receive($this->aCmd(ShareFsPacket::CODE_RSETSEQPTR, $sBody), self::CLIENT_ADDRESS);
        $sPayload = $this->assertLastIsSuccess();
        $aFields = unpack('Vpos', $sPayload);
        $this->assertSame(100, $aFields['pos']);
    }

    public function testZeroExtendsFileAndReturnsNewLength(): void
    {
        $oFd = $this->makeHandle('$.DISC0.FILE', 52, true, false);
        $this->registerHandle($oFd);
        $rp = new \ReflectionProperty(ShareFsHandler::class, 'aHandleOwners');
        $rp->setAccessible(true);
        $rp->setValue($this->oHandler, [52 => self::CLIENT_ADDRESS]);

        SpyVfsPlugin::$fnWrite = static fn($u, $h, string $sData) => strlen($sData);

        $sBody = pack('V', 52) . pack('V', 10) . pack('V', 5);
        $this->oHandler->receive($this->aCmd(ShareFsPacket::CODE_RZERO, $sBody), self::CLIENT_ADDRESS);

        $sPayload = $this->assertLastIsSuccess();
        $aFields = unpack('Vlength', $sPayload);
        $this->assertSame(15, $aFields['length']);
    }

    // -------------------------------------------------------------------------
    // Misc
    // -------------------------------------------------------------------------

    public function testMalformedPacketIsDiscardedWithoutThrowing(): void
    {
        $this->oHandler->receive('', self::CLIENT_ADDRESS);
        $this->assertSame([], $this->aSent);
    }

    public function testUnknownCommandCodeRepliesEnosys(): void
    {
        $this->oHandler->receive($this->aCmd(0xFF, ''), self::CLIENT_ADDRESS);
        $this->assertLastIsError(38); // ENOSYS
    }

    public function testHouseKeepingExpiresStalePendingRead(): void
    {
        $rp = new \ReflectionProperty(ShareFsHandler::class, 'aPendingReads');
        $rp->setAccessible(true);
        $rp->setValue($this->oHandler, ["\x01\x02\x03" => [
            'handle' => 1, 'client' => self::CLIENT_ADDRESS, 'address' => self::CLIENT_ADDRESS,
            'start' => 0, 'pos' => 0, 'end' => 100, 'started' => time() - 3600,
        ]]);

        $this->oHandler->houseKeeping();

        $this->assertSame([], $rp->getValue($this->oHandler));
    }
}
