<?php

/*
 * @group unit-tests
 */

use PHPUnit\Framework\TestCase;
use Monolog\Logger;
use Monolog\Handler\NullHandler;
use HomeLan\FileStore\Messages\EconetPacket;
use HomeLan\FileStore\Services\Provider\FileServer;

include_once('include/system.inc.php');

// ---------------------------------------------------------------------------
// Fake VFS metadata object
// ---------------------------------------------------------------------------
class FakeMeta
{
    public bool   $bIsDir    = false;
    public int    $iLoad     = 0xFFFF1900;
    public int    $iExec     = 0xFFFF1900;
    public int    $iSize     = 1024;
    public int    $iAccess   = 0b00001001;
    public string $sCTime    = "\x10\x5a";   // 2 bytes: day, year+month
    public int    $iSin      = 0x4567;

    public function isDir(): bool       { return $this->bIsDir; }
    public function getLoadAddr(): int  { return $this->iLoad; }
    public function getExecAddr(): int  { return $this->iExec; }
    public function getSize(): int      { return $this->iSize; }
    public function getAccess(): int    { return $this->iAccess; }
    public function getCTime(): string  { return $this->sCTime; }
    public function getSin(): int       { return $this->iSin; }
    public function getDay(): int       { return 16; }
    public function getMonth(): int     { return 10; }
    public function getYear(): int      { return 24; }
    public function getEconetMode(): string { return 'DRW/r '; }
    public function getEconetName(): string { return 'MYFILE'; }
    public function getEconetDirName(): string { return 'HOME'; }
}

// ---------------------------------------------------------------------------
// Fake VFS file-handle object
// ---------------------------------------------------------------------------
class FakeHandle
{
    public int    $iId         = 2;
    public string $sPath       = '$.HOME.FILE';
    public string $sDirName    = 'HOME';
    public string $sParentPath = '$.HOME';
    public bool   $bIsDir      = false;
    public bool   $bIsEof      = false;
    public string $sReadData   = 'HELLO';
    public string $sWritten    = '';
    public int    $iPos        = 0;
    public array  $aFstat      = ['size' => 1024];
    public ?int   $capSetPos   = null;
    public ?int   $capSetExt   = null;

    public function getID(): int   { return $this->iId; }
    public function getEconetPath(): string       { return $this->sPath; }
    public function getEconetDirName(): string    { return $this->sDirName; }
    public function getEconetParentPath(): string { return $this->sParentPath; }
    public function isDir(): bool  { return $this->bIsDir; }
    public function isEof(): bool  { return $this->bIsEof; }
    public function read(int $iLen) { $s = substr($this->sReadData, $this->iPos, $iLen); $this->iPos += strlen((string) $s); return $s; }
    public function write($data): void { $this->sWritten .= (string) $data; }
    public function fsFTell(): int { return $this->iPos; }
    public function fsFStat(): array { return $this->aFstat; }
    public function setPos(int $iPos): void { $this->iPos = $iPos; $this->capSetPos = $iPos; }
    public function setExt(int $iExt): void { $this->capSetExt = $iExt; }
}

// ---------------------------------------------------------------------------
// Fake directory-entry object (returned by vfsGetDirectoryListing)
// ---------------------------------------------------------------------------
class FakeDirEntry
{
    public string $sName     = 'MYFILE';
    public int    $iLoad     = 0xFFFF1900;
    public int    $iExec     = 0xFFFF1900;
    public int    $iSize     = 512;
    public int    $iAccess   = 0b00001001;
    public string $sMode     = 'DRW/r ';
    public int    $iDay      = 10;
    public int    $iMonth    = 6;
    public int    $iYear     = 24;
    public int    $iSin      = 0x1234;

    public function getEconetName(): string  { return $this->sName; }
    public function getLoadAddr(): int       { return $this->iLoad; }
    public function getExecAddr(): int       { return $this->iExec; }
    public function getAccess(): int         { return $this->iAccess; }
    public function getEconetMode(): string  { return $this->sMode; }
    public function getDay(): int            { return $this->iDay; }
    public function getMonth(): int          { return $this->iMonth; }
    public function getYear(): int           { return $this->iYear; }
    public function getSin(): int            { return $this->iSin; }
    public function getSize(): int           { return $this->iSize; }
    public function getCTime(): string       { return pack('CC', $this->iDay, ($this->iYear << 4) | $this->iMonth); }
}

// ---------------------------------------------------------------------------
// Fake security-user object
// ---------------------------------------------------------------------------
class FsTestUser
{
    public string  $sUsername  = 'JBROWN';
    public string  $sHomedir   = '$.JBROWN';
    public string  $sLib       = '$.Library';
    public int     $iBootOpt   = 0;
    public bool    $bIsAdmin   = false;
    public ?string $capCsd     = null;
    public ?string $capLib     = null;

    public function getUsername(): string { return $this->sUsername; }
    public function getHomedir(): string  { return $this->sHomedir; }
    public function getLib(): string      { return $this->sLib; }
    public function getBootOpt(): int     { return $this->iBootOpt; }
    public function isAdmin(): bool       { return $this->bIsAdmin; }
    public function setCsd(string $s): void { $this->capCsd = $s; }
    public function setLib(string $s): void { $this->capLib = $s; }
    public function setRoot(string $s): void {}
    public function getRoot(): string { return '$'; }
}

// ---------------------------------------------------------------------------
// Testable subclass — overrides all protected wrappers to avoid real I/O
// ---------------------------------------------------------------------------
class FileServerTestable extends FileServer
{
    // ---- Stubs (configure per-test) ----
    public bool    $stubIsLoggedIn    = true;
    public ?object $stubUser          = null;
    public bool    $stubLoginResult   = true;
    public bool    $stubLogoutThrows  = false;
    public ?object $stubMeta          = null;
    public ?object $stubHandle        = null;
    public array   $stubHandles       = [];          // handle-id → object
    public array   $stubDirEntries    = [];
    public array   $stubUsersOnline   = [];
    public array   $stubUserStation   = [];          // ['network'=>n,'station'=>s]
    public bool    $stubMetaThrows    = false;
    public bool    $stubHandleThrows  = false;
    public bool    $stubCreateDirThrows = false;
    public bool    $stubDeleteThrows  = false;
    public bool    $stubMoveThrows    = false;
    public bool    $stubRemoveUser    = true;
    public bool    $stubCreateHandleThrows = false;

    // ---- Captures ----
    public array   $capSetMeta       = [];
    public array   $capCreatedDirs   = [];
    public array   $capDeletedFiles  = [];
    public array   $capMovedFiles    = [];
    public array   $capSetOpt        = [];
    public bool    $capCloseAll      = false;

    // ---- Wrappers ----

    protected function vfsInit(): void {}

    protected function secIsLoggedIn(int $iNet, int $iStn): bool
    { return $this->stubIsLoggedIn; }

    protected function secUpdateIdleTimer(int $iNet, int $iStn): void {}

    protected function secGetUser(int $iNet, int $iStn)
    { return $this->stubUser; }

    protected function secLogin(int $iNet, int $iStn, string $sUser, string $sPass): bool
    { return $this->stubLoginResult; }

    protected function secLogout(int $iNet, int $iStn): void
    {
        if($this->stubLogoutThrows) throw new \Exception("Not logged in");
    }

    protected function vfsGetMeta(int $iNet, int $iStn, string $sPath)
    {
        if($this->stubMetaThrows || $this->stubMeta === null) throw new \Exception("No file");
        return $this->stubMeta;
    }

    protected function vfsSetMeta(int $iNet, int $iStn, string $sPath, $iLoad, $iExec, $iAccess): void
    { $this->capSetMeta[] = compact('sPath','iLoad','iExec','iAccess'); }

    protected function vfsGetFsHandle(int $iNet, int $iStn, $iHandle)
    {
        if($this->stubHandleThrows) throw new \Exception("No handle");
        if(array_key_exists($iHandle, $this->stubHandles)) return $this->stubHandles[$iHandle];
        if($this->stubHandle !== null) return $this->stubHandle;
        throw new \Exception("No handle $iHandle");
    }

    protected function vfsCreateFsHandle(int $iNet, int $iStn, string $sPath, bool $bMustExist=false, bool $bReadOnly=false)
    {
        if($this->stubCreateHandleThrows) throw new \Exception("Cannot create");
        if($this->stubHandle !== null) return $this->stubHandle;
        throw new \Exception("No handle for $sPath");
    }

    protected function vfsCloseFsHandle(int $iNet, int $iStn, $iHandle): void {}

    protected function vfsCloseAllFsHandles(int $iNet, int $iStn): void
    { $this->capCloseAll = true; }

    protected function vfsGetDirectoryListing($oFd): array
    { return $this->stubDirEntries; }

    protected function vfsCreateDirectory(int $iNet, int $iStn, string $sPath): void
    {
        if($this->stubCreateDirThrows) throw new \Exception("Cannot create");
        $this->capCreatedDirs[] = $sPath;
    }

    protected function vfsDeleteFile(int $iNet, int $iStn, string $sPath): void
    {
        if($this->stubDeleteThrows) throw new \Exception("Cannot delete");
        $this->capDeletedFiles[] = $sPath;
    }

    protected function vfsMoveFile(int $iNet, int $iStn, string $sFrom, string $sTo): void
    {
        if($this->stubMoveThrows) throw new \Exception("Cannot move");
        $this->capMovedFiles[] = ['from' => $sFrom, 'to' => $sTo];
    }

    protected function vfsCreateFile(int $iNet, int $iStn, string $sPath, int $iSize, $iLoad, $iExec): void {}

    protected function vfsSaveFile(int $iNet, int $iStn, string $sPath, string $sData, $iLoad, $iExec): void {}

    protected function vfsGetFile(int $iNet, int $iStn, string $sPath): string
    { return ''; }

    protected function vfsReplaceFsHandle(int $iNet, int $iStn, $iOldId, $iNewId): void {}

    protected function secGetUsersStation(string $sUser): array
    { return $this->stubUserStation; }

    protected function secGetUsersOnline(): array
    { return $this->stubUsersOnline; }

    protected function secSetPassword(int $iNet, int $iStn, ?string $sOld, ?string $sNew): void {}

    protected function secCreateUser(int $iNet, int $iStn, \HomeLan\FileStore\Authentication\User $oUser): void {}

    protected function secRemoveUser(int $iNet, int $iStn, string $sUser): bool
    { return $this->stubRemoveUser; }

    protected function secSetPriv(int $iNet, int $iStn, string $sUser, string $sPriv): void {}

    protected function secSetOpt(int $iNet, int $iStn, string $sOpt): void
    { $this->capSetOpt[] = $sOpt; }
}

// ---------------------------------------------------------------------------
// Tests
// ---------------------------------------------------------------------------
class FileServerTest extends TestCase
{
    private FileServerTestable $oFs;

    protected function setUp(): void
    {
        $oLogger = new Logger('test');
        $oLogger->pushHandler(new NullHandler());
        $this->oFs = new FileServerTestable($oLogger);
        $this->oFs->stubUser   = new FsTestUser();
        $this->oFs->stubHandle = new FakeHandle();
    }

    // -----------------------------------------------------------------------
    // Packet builder helpers
    // -----------------------------------------------------------------------

    /**
     * Build an EconetPacket carrying an FsRequest.
     * FsRequest header: [replyPort:1][funcCode:1][urd:1][csd:1][lib:1][sData...]
     */
    private function makePkt(int $iFn, string $sData = '', int $iNet = 1, int $iStn = 5): EconetPacket
    {
        $oPkt = new EconetPacket();
        $oPkt->setPort(0x99);
        $oPkt->setSourceNetwork($iNet);
        $oPkt->setSourceStation($iStn);
        $oPkt->setFlags(0);
        $oPkt->setDestinationNetwork(0);
        $oPkt->setDestinationStation(255);
        // replyPort=0x90, funcCode=$iFn, urd=1, csd=2, lib=3
        $oPkt->setData(pack('CCCCC', 0x90, $iFn, 1, 2, 3) . $sData);
        return $oPkt;
    }

    /** Make a data-port packet (routed to streamPacketIn, not processRequest) */
    private function makeDataPkt(string $sData = '', int $iNet = 1, int $iStn = 5): EconetPacket
    {
        $oPkt = new EconetPacket();
        $oPkt->setPort((int) config::getValue('econet_data_stream_port'));
        $oPkt->setSourceNetwork($iNet);
        $oPkt->setSourceStation($iStn);
        $oPkt->setFlags(0);
        $oPkt->setDestinationNetwork(0);
        $oPkt->setDestinationStation(255);
        $oPkt->setData($sData);
        return $oPkt;
    }

    private function dispatch(EconetPacket $oPkt): array
    {
        $this->oFs->unicastPacketIn($oPkt);
        return $this->oFs->getReplies();
    }

    /** Return reply bytes as a 1-indexed array */
    private function bytes(EconetPacket $oPkt): array
    {
        return array_values(unpack('C*', (string) $oPkt->getData()));
    }

    // -----------------------------------------------------------------------
    // Service metadata
    // -----------------------------------------------------------------------

    public function testGetName(): void
    {
        $this->assertSame('File Server', $this->oFs->getName());
    }

    public function testGetServicePortsContains99(): void
    {
        $this->assertContains(0x99, $this->oFs->getServicePorts());
    }

    public function testGetServicePortsContainsDataStreamPort(): void
    {
        $this->assertContains((int) config::getValue('econet_data_stream_port'), $this->oFs->getServicePorts());
    }

    public function testGetAdminInterfaceIsNotNull(): void
    {
        $this->assertNotNull($this->oFs->getAdminInterface());
    }

    public function testGetRepliesDrainsBuffer(): void
    {
        // A broadcast CLI packet with a bad command puts something in the buffer
        $oPkt = $this->makePkt(0, "BYE\r");
        $this->oFs->broadcastPacketIn($oPkt);
        $this->oFs->getReplies();
        $this->assertEmpty($this->oFs->getReplies());
    }

    // -----------------------------------------------------------------------
    // Routing — broadcast vs unicast, data stream vs command port
    // -----------------------------------------------------------------------

    public function testBroadcastNonCliProducesNoReply(): void
    {
        // Broadcast with function 3 (EXAMINE) — should be silently ignored
        $this->oFs->broadcastPacketIn($this->makePkt(3, pack('CCC', 0, 0, 5)));
        $this->assertEmpty($this->oFs->getReplies());
    }

    public function testBroadcastCliIsProcessed(): void
    {
        // *BYE via broadcast CLI — logout throws (not logged in) but we still get a reply
        $this->oFs->stubLogoutThrows = true;
        $this->oFs->broadcastPacketIn($this->makePkt(0, "BYE\r"));
        $this->assertCount(1, $this->oFs->getReplies());
    }

    public function testUnicastDataStreamPortProducesNoCommandReply(): void
    {
        // Packets on the data-stream port go to streamPacketIn; no active stream means nothing happens
        $this->oFs->unicastPacketIn($this->makeDataPkt('SOMEDATA'));
        $this->assertEmpty($this->oFs->getReplies());
    }

    // -----------------------------------------------------------------------
    // Not-logged-in gate
    // -----------------------------------------------------------------------

    public function testNotLoggedInReturnsWhoAreYou(): void
    {
        $this->oFs->stubIsLoggedIn = false;
        [$oReply] = $this->dispatch($this->makePkt(23));  // LOGOFF
        $aB = $this->bytes($oReply);
        $this->assertSame(0x00, $aB[0]);  // type DONE
        $this->assertSame(0xbf, $aB[1]);  // error "Who are you?"
    }

    public function testNotLoggedInSendsExactlyOneReply(): void
    {
        $this->oFs->stubIsLoggedIn = false;
        $aReplies = $this->dispatch($this->makePkt(18, pack('CC', 5, 0) . "FILE\r"));
        $this->assertCount(1, $aReplies);
    }

    // -----------------------------------------------------------------------
    // Unknown / unhandled function codes
    // -----------------------------------------------------------------------

    public function testUsersExtSendsErrorReply(): void
    {
        $aReplies = $this->dispatch($this->makePkt(33));  // EC_FS_FUNC_USERS_EXT
        $aB = $this->bytes($aReplies[0]);
        $this->assertSame(0x8f, $aB[1]);
    }

    public function testCatHeaderReturnsCatType(): void
    {
        [$oReply] = $this->dispatch($this->makePkt(4));  // EC_FS_FUNC_CAT_HEADER
        $aB = $this->bytes($oReply);
        $this->assertSame(3, $aB[0]);  // reply type CAT
        $this->assertSame(0, $aB[1]);  // status OK
    }

    public function testCatHeaderContainsDiscName(): void
    {
        config::overrideValue('vfs_disc_name', 'TESTDISC');
        [$oReply] = $this->dispatch($this->makePkt(4));
        $sData = $oReply->getData();
        $this->assertStringContainsString('TESTDISC', $sData);
        config::resetValue('vfs_disc_name');
    }

    public function testCatHeaderContainsCsdDirName(): void
    {
        $oHandle = new FakeHandle();
        $oHandle->sDirName = 'MYDIR';
        $this->oFs->stubHandle = $oHandle;
        [$oReply] = $this->dispatch($this->makePkt(4));
        $sData = $oReply->getData();
        $this->assertStringContainsString('MYDIR', $sData);
    }

    public function testCatHeaderErrorWhenHandleThrows(): void
    {
        $this->oFs->stubHandleThrows = true;
        [$oReply] = $this->dispatch($this->makePkt(4));
        $aB = $this->bytes($oReply);
        $this->assertSame(0, $aB[0]);   // DONE type (error)
        $this->assertSame(0xff, $aB[1]); // error code
    }

    // -----------------------------------------------------------------------
    // Login
    // -----------------------------------------------------------------------

    public function testLoginSuccessSendsLoginTypeReply(): void
    {
        $oUser = new FsTestUser();
        $this->oFs->stubUser   = $oUser;
        $this->oFs->stubHandle = new FakeHandle();
        $this->oFs->stubLoginResult = true;
        [$oReply] = $this->dispatch($this->makePkt(0, "I AM JBROWN PASS\r"));
        $aB = $this->bytes($oReply);
        $this->assertSame(5, $aB[0]);  // type LOGIN
        $this->assertSame(0, $aB[1]);  // status OK
    }

    public function testLoginSuccessReplyContainsHandles(): void
    {
        $oUser = new FsTestUser();
        $oHandle = new FakeHandle();
        $oHandle->iId = 7;
        $this->oFs->stubUser   = $oUser;
        $this->oFs->stubHandle = $oHandle;
        [$oReply] = $this->dispatch($this->makePkt(0, "I AM JBROWN PASS\r"));
        $aB = $this->bytes($oReply);
        // loginResponse: [LOGIN=5][0][urd][csd][lib][opt]
        $this->assertSame(7, $aB[2]);  // urd = handle ID
    }

    public function testLoginFailureSendsIncorrectPasswordError(): void
    {
        $this->oFs->stubLoginResult = false;
        [$oReply] = $this->dispatch($this->makePkt(0, "I AM JBROWN BADPW\r"));
        $aB = $this->bytes($oReply);
        $this->assertSame(0xbb, $aB[1]);
    }

    public function testLoginWithNoCredentialsSendsError(): void
    {
        [$oReply] = $this->dispatch($this->makePkt(0, "I AM \r"));
        $aB = $this->bytes($oReply);
        $this->assertSame(0xbb, $aB[1]);
    }

    // -----------------------------------------------------------------------
    // Logout
    // -----------------------------------------------------------------------

    public function testLogoutSuccessSendsDoneOk(): void
    {
        [$oReply] = $this->dispatch($this->makePkt(23));
        $aB = $this->bytes($oReply);
        $this->assertSame(0, $aB[0]);
        $this->assertSame(0, $aB[1]);
    }

    public function testLogoutNotLoggedInSendsError(): void
    {
        $this->oFs->stubLogoutThrows = true;
        [$oReply] = $this->dispatch($this->makePkt(0, "BYE\r"));
        $aB = $this->bytes($oReply);
        $this->assertSame(0xbf, $aB[1]);
    }

    // -----------------------------------------------------------------------
    // getInfo
    // -----------------------------------------------------------------------

    private function makeGetInfoPkt(int $iArg, string $sPath): EconetPacket
    {
        return $this->makePkt(18, pack('C', $iArg) . $sPath . "\r");
    }

    public function testGetInfoCase1ReturnsLoadAndExecAddrs(): void
    {
        $oMeta = new FakeMeta();
        $oMeta->iLoad = 0xFFFF1900;
        $oMeta->iExec = 0xFFFF2000;
        $this->oFs->stubMeta = $oMeta;
        [$oReply] = $this->dispatch($this->makeGetInfoPkt(1, 'MYFILE'));
        $aB = $this->bytes($oReply);
        $this->assertSame(0, $aB[0]);  // DONE
        $this->assertSame(0, $aB[1]);  // OK
        $this->assertSame(0x01, $aB[2]);  // file type
        // Bytes 3-6: load addr LE
        $iLoad = unpack('V', pack('C4', $aB[3], $aB[4], $aB[5], $aB[6]))[1];
        $this->assertSame(0xFFFF1900, $iLoad);
    }

    public function testGetInfoCase1ForDirReturnsTypeByte2(): void
    {
        $oMeta = new FakeMeta();
        $oMeta->bIsDir = true;
        $this->oFs->stubMeta = $oMeta;
        [$oReply] = $this->dispatch($this->makeGetInfoPkt(1, 'MYDIR'));
        $aB = $this->bytes($oReply);
        $this->assertSame(0x02, $aB[2]);
    }

    public function testGetInfoCase2ReturnsExecAddr(): void
    {
        // Case 2 = EC_FS_GET_INFO_EXEC: returns type(1) + exec(4) = 7 bytes total
        $oMeta = new FakeMeta();
        $oMeta->iExec = 0xFFFF2000;
        $this->oFs->stubMeta = $oMeta;
        [$oReply] = $this->dispatch($this->makeGetInfoPkt(2, 'MYFILE'));
        $aB = $this->bytes($oReply);
        $this->assertSame(0, $aB[1]);
        $this->assertSame(7, count($aB));
        $iExec = unpack('V', pack('C4', $aB[3], $aB[4], $aB[5], $aB[6]))[1];
        $this->assertSame(0xFFFF2000, $iExec);
    }

    public function testGetInfoCase3ReturnsSizeForFile(): void
    {
        $oMeta = new FakeMeta();
        $oMeta->iSize = 0x1234;
        $this->oFs->stubMeta = $oMeta;
        [$oReply] = $this->dispatch($this->makeGetInfoPkt(3, 'MYFILE'));
        $aB = $this->bytes($oReply);
        $this->assertSame(0, $aB[1]);  // OK
        // 24-bit LE size at bytes 3,4,5
        $iSize = $aB[3] | ($aB[4] << 8) | ($aB[5] << 16);
        $this->assertSame(0x1234, $iSize);
    }

    public function testGetInfoCase4ReturnsAccessByte(): void
    {
        $oMeta = new FakeMeta();
        $oMeta->iAccess = 0x33;
        $this->oFs->stubMeta = $oMeta;
        [$oReply] = $this->dispatch($this->makeGetInfoPkt(4, 'MYFILE'));
        $aB = $this->bytes($oReply);
        $this->assertSame(0x33, $aB[3]);  // access at offset 3 (after done+status+type)
    }

    public function testGetInfoCase5ReturnsAllAttributes(): void
    {
        // Case 5 = EC_FS_GET_INFO_ALL: type(1)+load(4)+exec(4)+size(3)+access(1)+ctime(2) = 15 payload
        $oMeta = new FakeMeta();
        $this->oFs->stubMeta = $oMeta;
        [$oReply] = $this->dispatch($this->makeGetInfoPkt(5, 'MYFILE'));
        $aB = $this->bytes($oReply);
        $this->assertSame(0, $aB[0]);
        $this->assertSame(0, $aB[1]);
        $this->assertSame(17, count($aB));  // 2 header + 15 payload
    }

    public function testGetInfoCase7ReturnsUid(): void
    {
        $oMeta = new FakeMeta();
        $oMeta->iSin = 0x4321;
        $this->oFs->stubMeta = $oMeta;
        [$oReply] = $this->dispatch($this->makeGetInfoPkt(7, 'MYFILE'));
        $aB = $this->bytes($oReply);
        $this->assertSame(0, $aB[1]);  // OK
        $iSin = $aB[3] | ($aB[4] << 8) | ($aB[5] << 16);
        $this->assertSame(0x4321, $iSin);
    }

    public function testGetInfoCase7FileNotFoundReturnsError(): void
    {
        // Case 7 (UID) is one of the cases that calls setError() on not-found
        $this->oFs->stubMeta = null;
        [$oReply] = $this->dispatch($this->makeGetInfoPkt(7, 'NOPE'));
        $aB = $this->bytes($oReply);
        $this->assertSame(0xd6, $aB[1]);
    }

    public function testGetInfoUnknownArgReturnsError(): void
    {
        $this->oFs->stubMeta = new FakeMeta();
        [$oReply] = $this->dispatch($this->makeGetInfoPkt(99, 'MYFILE'));
        $aB = $this->bytes($oReply);
        $this->assertSame(0x8e, $aB[1]);
    }

    // -----------------------------------------------------------------------
    // setInfo — regression test for case 4 wrong byte offsets
    // -----------------------------------------------------------------------

    public function testSetInfoCase4ReadsAccessFromByte2(): void
    {
        // sData = [arg=4][access=0x21][path\r]
        // Before the fix, getByte(1) would read arg byte 4 as access.
        $sData = pack('CC', 4, 0x21) . "MYFILE\r";
        $this->dispatch($this->makePkt(19, $sData));
        $this->assertCount(1, $this->oFs->capSetMeta);
        $this->assertSame(0x21, $this->oFs->capSetMeta[0]['iAccess']);
    }

    public function testSetInfoCase4ReadsPathFromByte3(): void
    {
        $sData = pack('CC', 4, 0x21) . "TARGET\r";
        $this->dispatch($this->makePkt(19, $sData));
        $this->assertSame('TARGET', $this->oFs->capSetMeta[0]['sPath']);
    }

    public function testSetInfoCase1SetsAllFields(): void
    {
        // sData = [arg=1][load:4][exec:4][access:1][path\r]
        $sData = pack('C', 1)
            . pack('V', 0xFFFF1900)   // load
            . pack('V', 0xFFFF2000)   // exec
            . pack('C', 0x33)         // access
            . "MYFILE\r";
        $this->dispatch($this->makePkt(19, $sData));
        $this->assertSame(0xFFFF1900, $this->oFs->capSetMeta[0]['iLoad']);
        $this->assertSame(0xFFFF2000, $this->oFs->capSetMeta[0]['iExec']);
        $this->assertSame(0x33, $this->oFs->capSetMeta[0]['iAccess']);
    }

    public function testSetInfoSendsDoneOk(): void
    {
        $sData = pack('CC', 4, 0x21) . "MYFILE\r";
        [$oReply] = $this->dispatch($this->makePkt(19, $sData));
        $aB = $this->bytes($oReply);
        $this->assertSame(0, $aB[0]);
        $this->assertSame(0, $aB[1]);
    }

    // -----------------------------------------------------------------------
    // getArgs — regression: error path must not call DoneOk after setError
    // -----------------------------------------------------------------------

    private function makeGetArgsPkt(int $iHandle, int $iArg): EconetPacket
    {
        return $this->makePkt(12, pack('CC', $iHandle, $iArg));
    }

    public function testGetArgsCase0ReturnsFtell(): void
    {
        $oHandle = new FakeHandle();
        $oHandle->iPos = 42;
        $this->oFs->stubHandle = $oHandle;
        [$oReply] = $this->dispatch($this->makeGetArgsPkt(2, 0));
        $aB = $this->bytes($oReply);
        $this->assertSame(0, $aB[1]);
        // DoneOk=[0,0] + 24-bit LE pos at [2,3,4]
        $iPos = $aB[2] | ($aB[3] << 8) | ($aB[4] << 16);
        $this->assertSame(42, $iPos);
    }

    public function testGetArgsCase1ReturnsSize(): void
    {
        $oHandle = new FakeHandle();
        $oHandle->aFstat = ['size' => 512];
        $this->oFs->stubHandle = $oHandle;
        [$oReply] = $this->dispatch($this->makeGetArgsPkt(2, 1));
        $aB = $this->bytes($oReply);
        $this->assertSame(0, $aB[1]);
    }

    public function testGetArgsErrorCaseReturnsErrorNotDoneOk(): void
    {
        // Regression: previously the error path called DoneOk() after setError()
        $this->oFs->stubHandle = new FakeHandle();
        [$oReply] = $this->dispatch($this->makeGetArgsPkt(2, 99));
        $aB = $this->bytes($oReply);
        $this->assertSame(0x8f, $aB[1]);  // must be error, not success (0)
    }

    // -----------------------------------------------------------------------
    // setArgs — new method exercising EC_FS_FUNC_SET_ARGS
    // -----------------------------------------------------------------------

    private function makeSetArgsPkt(int $iHandle, int $iArg, int $iValue): EconetPacket
    {
        // sData = [handle][arg][value_lo][value_mid][value_hi]
        $sData = pack('CC', $iHandle, $iArg)
            . pack('C', $iValue & 0xFF)
            . pack('C', ($iValue >> 8) & 0xFF)
            . pack('C', ($iValue >> 16) & 0xFF);
        return $this->makePkt(13, $sData);
    }

    public function testSetArgsCase0SetsFilePointer(): void
    {
        $oHandle = new FakeHandle();
        $this->oFs->stubHandle = $oHandle;
        $this->dispatch($this->makeSetArgsPkt(2, 0, 128));
        $this->assertSame(128, $oHandle->capSetPos);
    }

    public function testSetArgsCase1SetsExtent(): void
    {
        $oHandle = new FakeHandle();
        $this->oFs->stubHandle = $oHandle;
        $this->dispatch($this->makeSetArgsPkt(2, 1, 512));
        $this->assertSame(512, $oHandle->capSetExt);
    }

    public function testSetArgsCase0SendsDoneOk(): void
    {
        $this->oFs->stubHandle = new FakeHandle();
        [$oReply] = $this->dispatch($this->makeSetArgsPkt(2, 0, 0));
        $aB = $this->bytes($oReply);
        $this->assertSame(0, $aB[1]);
    }

    public function testSetArgsUnknownArgSendsError(): void
    {
        $this->oFs->stubHandle = new FakeHandle();
        [$oReply] = $this->dispatch($this->makeSetArgsPkt(2, 99, 0));
        $aB = $this->bytes($oReply);
        $this->assertSame(0x8f, $aB[1]);
    }

    // -----------------------------------------------------------------------
    // getByte — regression: was passing string to appendByte instead of ord()
    // -----------------------------------------------------------------------

    public function testGetByteReturnsCorrectByteValue(): void
    {
        $oHandle = new FakeHandle();
        $oHandle->sReadData = "\x42";  // 'B' = 66
        $this->oFs->stubHandle = $oHandle;
        [$oReply] = $this->dispatch($this->makePkt(8, pack('C', 2)));
        $aB = $this->bytes($oReply);
        $this->assertSame(0, $aB[1]);   // OK
        $this->assertSame(0x42, $aB[2]);  // the byte value
        $this->assertSame(0, $aB[3]);   // not-EOF flag
    }

    public function testGetByteAtEofReturnsEofFlag(): void
    {
        $oHandle = new FakeHandle();
        $oHandle->bIsEof = true;
        $this->oFs->stubHandle = $oHandle;
        [$oReply] = $this->dispatch($this->makePkt(8, pack('C', 2)));
        $aB = $this->bytes($oReply);
        $this->assertSame(0, $aB[2]);   // byte = 0 at EOF
        $this->assertNotSame(0, $aB[3]);  // EOF flag is non-zero
    }

    public function testGetByteNonAsciiByteIsNotZero(): void
    {
        // Regression: chr(0xFF) was being cast to int 0
        $oHandle = new FakeHandle();
        $oHandle->sReadData = "\xFF";
        $this->oFs->stubHandle = $oHandle;
        [$oReply] = $this->dispatch($this->makePkt(8, pack('C', 2)));
        $aB = $this->bytes($oReply);
        $this->assertSame(0xFF, $aB[2]);  // must be 255, not 0
    }

    // -----------------------------------------------------------------------
    // putByte — regression: was writing integer directly to handle
    // -----------------------------------------------------------------------

    public function testPutByteWritesChrOfByteToHandle(): void
    {
        $oHandle = new FakeHandle();
        $this->oFs->stubHandle = $oHandle;
        $this->dispatch($this->makePkt(9, pack('CC', 2, 0x42)));
        $this->assertSame("\x42", $oHandle->sWritten);
    }

    public function testPutByteSendsDoneOk(): void
    {
        $this->oFs->stubHandle = new FakeHandle();
        [$oReply] = $this->dispatch($this->makePkt(9, pack('CC', 2, 65)));
        $aB = $this->bytes($oReply);
        $this->assertSame(0, $aB[1]);
    }

    // -----------------------------------------------------------------------
    // eof
    // -----------------------------------------------------------------------

    public function testEofAtEofReturns0xFF(): void
    {
        $oHandle = new FakeHandle();
        $oHandle->bIsEof = true;
        $this->oFs->stubHandle = $oHandle;
        [$oReply] = $this->dispatch($this->makePkt(17, pack('C', 2)));
        $aB = $this->bytes($oReply);
        $this->assertSame(0xFF, $aB[2]);
    }

    public function testEofNotAtEofReturns0(): void
    {
        $oHandle = new FakeHandle();
        $oHandle->bIsEof = false;
        $this->oFs->stubHandle = $oHandle;
        [$oReply] = $this->dispatch($this->makePkt(17, pack('C', 2)));
        $aB = $this->bytes($oReply);
        $this->assertSame(0, $aB[2]);
    }

    // -----------------------------------------------------------------------
    // examine — regression: case 2 was missing 0x80 end marker
    // -----------------------------------------------------------------------

    private function makeExaminePkt(int $iArg, int $iStart = 0, int $iCount = 10): EconetPacket
    {
        $oHandle = new FakeHandle();
        $oHandle->iId = 2;
        $this->oFs->stubHandle = $oHandle;
        return $this->makePkt(3, pack('CCC', $iArg, $iStart, $iCount));
    }

    public function testExamineCase0EndsWithMarker0x80(): void
    {
        $this->oFs->stubDirEntries = [new FakeDirEntry()];
        [$oReply] = $this->dispatch($this->makeExaminePkt(0));
        $sData = $oReply->getData();
        $this->assertSame(0x80, ord(substr($sData, -1)));
    }

    public function testExamineCase1EndsWithMarker0x80(): void
    {
        $this->oFs->stubDirEntries = [new FakeDirEntry()];
        [$oReply] = $this->dispatch($this->makeExaminePkt(1));
        $sData = $oReply->getData();
        $this->assertSame(0x80, ord(substr($sData, -1)));
    }

    public function testExamineCase2EndsWithMarker0x80(): void
    {
        // Regression: was missing the 0x80 terminator
        $this->oFs->stubDirEntries = [new FakeDirEntry()];
        [$oReply] = $this->dispatch($this->makeExaminePkt(2));
        $sData = $oReply->getData();
        $this->assertSame(0x80, ord(substr($sData, -1)));
    }

    public function testExamineCase3EndsWithMarker0x80(): void
    {
        $this->oFs->stubDirEntries = [new FakeDirEntry()];
        [$oReply] = $this->dispatch($this->makeExaminePkt(3));
        $sData = $oReply->getData();
        $this->assertSame(0x80, ord(substr($sData, -1)));
    }

    public function testExamineCase0CountByteMatchesEntries(): void
    {
        $this->oFs->stubDirEntries = [new FakeDirEntry(), new FakeDirEntry()];
        [$oReply] = $this->dispatch($this->makeExaminePkt(0));
        $aB = $this->bytes($oReply);
        $this->assertSame(2, $aB[2]);  // count byte (after done+status)
    }

    // -----------------------------------------------------------------------
    // openFile / closeFile
    // -----------------------------------------------------------------------

    public function testOpenFileSuccessReturnsHandleId(): void
    {
        $oHandle = new FakeHandle();
        $oHandle->iId = 9;
        $this->oFs->stubHandle = $oHandle;
        [$oReply] = $this->dispatch($this->makePkt(6, pack('CC', 0, 0) . "MYFILE\r"));
        $aB = $this->bytes($oReply);
        $this->assertSame(0, $aB[1]);
        $this->assertSame(9, $aB[2]);
    }

    public function testOpenFileFailureReturnsError(): void
    {
        $this->oFs->stubCreateHandleThrows = true;
        [$oReply] = $this->dispatch($this->makePkt(6, pack('CC', 0, 0) . "NOPE\r"));
        $aB = $this->bytes($oReply);
        $this->assertGreaterThan(0, $aB[1]);
    }

    public function testCloseFileSendsDoneOk(): void
    {
        $this->oFs->stubHandle = new FakeHandle();
        [$oReply] = $this->dispatch($this->makePkt(7, pack('C', 2)));
        $aB = $this->bytes($oReply);
        $this->assertSame(0, $aB[1]);
    }

    // -----------------------------------------------------------------------
    // createDirectory
    // -----------------------------------------------------------------------

    public function testCreateDirectorySuccess(): void
    {
        $this->dispatch($this->makePkt(27, pack('C', 0) . "NEWDIR\r"));
        $this->assertContains('NEWDIR', $this->oFs->capCreatedDirs);
    }

    public function testCreateDirectoryEmptyNameReturnsError(): void
    {
        [$oReply] = $this->dispatch($this->makePkt(27, pack('C', 0) . "\r"));
        $aB = $this->bytes($oReply);
        $this->assertGreaterThan(0, $aB[1]);
    }

    public function testCreateDirectoryTooLongNameReturnsError(): void
    {
        [$oReply] = $this->dispatch($this->makePkt(27, pack('C', 0) . "AVERYLONGDIRNAME\r"));
        $aB = $this->bytes($oReply);
        $this->assertGreaterThan(0, $aB[1]);
    }

    public function testCreateDirectorySendsDoneOkOnSuccess(): void
    {
        [$oReply] = $this->dispatch($this->makePkt(27, pack('C', 0) . "NEWDIR\r"));
        $aB = $this->bytes($oReply);
        $this->assertSame(0, $aB[1]);
    }

    // -----------------------------------------------------------------------
    // deleteFile
    // -----------------------------------------------------------------------

    public function testDeleteFileSendsDoneOk(): void
    {
        [$oReply] = $this->dispatch($this->makePkt(20, "MYFILE\r"));
        $aB = $this->bytes($oReply);
        $this->assertSame(0, $aB[1]);
    }

    public function testDeleteFileCallsVfsDelete(): void
    {
        $this->dispatch($this->makePkt(20, "MYFILE\r"));
        $this->assertContains('MYFILE', $this->oFs->capDeletedFiles);
    }

    public function testDeleteFileFailureReturnsError(): void
    {
        $this->oFs->stubDeleteThrows = true;
        [$oReply] = $this->dispatch($this->makePkt(20, "MYFILE\r"));
        $aB = $this->bytes($oReply);
        $this->assertGreaterThan(0, $aB[1]);
    }

    // -----------------------------------------------------------------------
    // whoAmI
    // -----------------------------------------------------------------------

    public function testWhoAmIReturnsUsername(): void
    {
        $oUser = new FsTestUser();
        $oUser->sUsername = 'ALICE';
        $this->oFs->stubUser = $oUser;
        [$oReply] = $this->dispatch($this->makePkt(32));
        $sData = $oReply->getData();
        $this->assertStringContainsString('ALICE', $sData);
    }

    public function testWhoAmINoUserReturnsError(): void
    {
        $this->oFs->stubUser = null;
        [$oReply] = $this->dispatch($this->makePkt(32));
        $aB = $this->bytes($oReply);
        $this->assertSame(0xbf, $aB[1]);
    }

    // -----------------------------------------------------------------------
    // usersOnline — regression: username must be space-padded to 10 chars
    // -----------------------------------------------------------------------

    public function testUsersOnlineSendsDoneOk(): void
    {
        [$oReply] = $this->dispatch($this->makePkt(15, pack('CC', 0, 10)));
        $aB = $this->bytes($oReply);
        $this->assertSame(0, $aB[1]);
    }

    public function testUsersOnlineUsernameIsPaddedToTenChars(): void
    {
        $oUser = new FsTestUser();
        $oUser->sUsername = 'BOB';     // only 3 chars
        $this->oFs->stubUsersOnline = [
            1 => [5 => ['user' => $oUser]],
        ];
        $this->oFs->stubUser = $oUser;
        [$oReply] = $this->dispatch($this->makePkt(15, pack('CC', 0, 10)));
        $sData = $oReply->getData();
        // Skip: done(1)+status(1)+count(1)+net(1)+stn(1) = 5 bytes before username
        $sUsername = substr($sData, 5, 10);
        $this->assertSame(10, strlen($sUsername));
        $this->assertStringStartsWith('BOB', $sUsername);
        $this->assertStringContainsString('       ', $sUsername);  // padded with spaces
    }

    // -----------------------------------------------------------------------
    // getUsersStation — regression: was missing DoneOk() in success path
    // -----------------------------------------------------------------------

    public function testGetUsersStationFoundReturnsStatusByteZero(): void
    {
        // Regression: previously the success path had no DoneOk, so byte 0 was 'priv' not 'DONE'
        $oUser = new FsTestUser();
        $oUser->bIsAdmin = false;
        $this->oFs->stubUser       = $oUser;
        $this->oFs->stubUserStation = ['network' => 1, 'station' => 7];
        [$oReply] = $this->dispatch($this->makePkt(24, "JBROWN\r"));
        $aB = $this->bytes($oReply);
        $this->assertSame(0, $aB[0]);  // type DONE
        $this->assertSame(0, $aB[1]);  // status OK
    }

    public function testGetUsersStationFoundReturnsNetAndStn(): void
    {
        $oUser = new FsTestUser();
        $this->oFs->stubUser        = $oUser;
        $this->oFs->stubUserStation = ['network' => 3, 'station' => 9];
        [$oReply] = $this->dispatch($this->makePkt(24, "JBROWN\r"));
        $aB = $this->bytes($oReply);
        // [DONE=0][OK=0][priv][network][station]
        $this->assertSame(3, $aB[3]);
        $this->assertSame(9, $aB[4]);
    }

    public function testGetUsersStationNotFoundReturnsDoneNoton(): void
    {
        $this->oFs->stubUserStation = [];  // no network/station keys
        [$oReply] = $this->dispatch($this->makePkt(24, "NOBODY\r"));
        $aB = $this->bytes($oReply);
        $this->assertSame(0xAE, $aB[1]);  // DoneNoton error code
    }

    // -----------------------------------------------------------------------
    // getDiscs
    // -----------------------------------------------------------------------

    public function testGetDiscsForDrive0ReturnsSingleDisc(): void
    {
        [$oReply] = $this->dispatch($this->makePkt(14, pack('CC', 0, 1)));
        $aB = $this->bytes($oReply);
        $this->assertSame(0, $aB[1]);   // DiscsOk status
        $this->assertSame(1, $aB[2]);   // count = 1
    }

    public function testGetDiscsForDrive1ReturnsZeroDiscs(): void
    {
        [$oReply] = $this->dispatch($this->makePkt(14, pack('CC', 1, 1)));
        $aB = $this->bytes($oReply);
        $this->assertSame(0, $aB[2]);
    }

    // -----------------------------------------------------------------------
    // getDiscFree — regression: was using 32-bit instead of 24-bit fields
    // -----------------------------------------------------------------------

    public function testGetDiscFreeReplyIsSixBytesOfPayload(): void
    {
        [$oReply] = $this->dispatch($this->makePkt(26, "DISC\r"));
        // done(1)+status(1)+free(3)+size(3) = 8 bytes total
        $this->assertSame(8, strlen($oReply->getData()));
    }

    public function testGetDiscFreeStatusIsOk(): void
    {
        [$oReply] = $this->dispatch($this->makePkt(26, "DISC\r"));
        $aB = $this->bytes($oReply);
        $this->assertSame(0, $aB[1]);
    }

    // -----------------------------------------------------------------------
    // getVersion
    // -----------------------------------------------------------------------

    public function testGetVersionContainsVersionString(): void
    {
        [$oReply] = $this->dispatch($this->makePkt(25));
        $sData = $oReply->getData();
        $this->assertStringContainsString((string) config::getValue('version'), $sData);
    }

    // -----------------------------------------------------------------------
    // getTime
    // -----------------------------------------------------------------------

    public function testGetTimeSendsFiveBytePayload(): void
    {
        [$oReply] = $this->dispatch($this->makePkt(16));
        // done(1)+status(1)+day(1)+year+month(1)+hour(1)+min(1)+sec(1) = 7 bytes
        $this->assertSame(7, strlen($oReply->getData()));
    }

    public function testGetTimeDayIsInRange(): void
    {
        [$oReply] = $this->dispatch($this->makePkt(16));
        $aB = $this->bytes($oReply);
        $this->assertGreaterThanOrEqual(1, $aB[2]);
        $this->assertLessThanOrEqual(31, $aB[2]);
    }

    // -----------------------------------------------------------------------
    // setOpt — regression: was appending spurious disc-free bytes
    // -----------------------------------------------------------------------

    public function testSetOptReplyIsExactlyTwoBytes(): void
    {
        [$oReply] = $this->dispatch($this->makePkt(22, pack('C', 4)));
        $this->assertSame(2, strlen($oReply->getData()));
    }

    public function testSetOptSendsDoneOk(): void
    {
        [$oReply] = $this->dispatch($this->makePkt(22, pack('C', 4)));
        $aB = $this->bytes($oReply);
        $this->assertSame(0, $aB[0]);
        $this->assertSame(0, $aB[1]);
    }

    // -----------------------------------------------------------------------
    // getUserDiscFree
    // -----------------------------------------------------------------------

    public function testGetUserDiscFreeReturns24BitValue(): void
    {
        [$oReply] = $this->dispatch($this->makePkt(30, "JBROWN\r"));
        // done(1)+status(1)+free(3) = 5 bytes
        $this->assertSame(5, strlen($oReply->getData()));
    }

    // -----------------------------------------------------------------------
    // CLI — *CAT and *FSOPT regressions (were no-ops / hangs)
    // -----------------------------------------------------------------------

    public function testCliCatReturnsUnrecognisedOkReply(): void
    {
        // Regression: *CAT was break without any reply — client would hang
        [$oReply] = $this->dispatch($this->makePkt(0, "CAT\r"));
        $aB = $this->bytes($oReply);
        $this->assertSame(8, $aB[0]);  // type UNREC = 8
        $this->assertSame(0, $aB[1]);
    }

    public function testCliFsoptReturnsDoneOk(): void
    {
        // Regression: *FSOPT was break without any reply — client would hang
        [$oReply] = $this->dispatch($this->makePkt(0, "FSOPT\r"));
        $aB = $this->bytes($oReply);
        $this->assertSame(0, $aB[0]);
        $this->assertSame(0, $aB[1]);
    }

    public function testCliUnrecognisedCommandSendsUnrecReply(): void
    {
        [$oReply] = $this->dispatch($this->makePkt(0, "FROB\r"));
        $aB = $this->bytes($oReply);
        $this->assertSame(8, $aB[0]);  // UNREC
    }

    // -----------------------------------------------------------------------
    // renameFileByHandle — function code 28
    // -----------------------------------------------------------------------

    public function testRenameByHandleSendsDoneOk(): void
    {
        // data: [src_dir=0][src_name\r][dst_dir=0][dst_name\r]
        $sData = pack('C', 0) . "OLDFILE\r" . pack('C', 0) . "NEWFILE\r";
        [$oReply] = $this->dispatch($this->makePkt(28, $sData));
        $aB = $this->bytes($oReply);
        $this->assertSame(0, $aB[0]);
        $this->assertSame(0, $aB[1]);
    }

    public function testRenameByHandleCallsVfsMoveFile(): void
    {
        $oHandle = new FakeHandle();
        $oHandle->sPath = '$.HOME';
        $this->oFs->stubHandle = $oHandle;
        $sData = pack('C', 0) . "SRC\r" . pack('C', 0) . "DST\r";
        $this->dispatch($this->makePkt(28, $sData));
        $this->assertCount(1, $this->oFs->capMovedFiles);
        $this->assertSame('$.HOME.SRC', $this->oFs->capMovedFiles[0]['from']);
        $this->assertSame('$.HOME.DST', $this->oFs->capMovedFiles[0]['to']);
    }

    public function testRenameByHandleReturnsErrorOnFailure(): void
    {
        $this->oFs->stubMoveThrows = true;
        $sData = pack('C', 0) . "SRC\r" . pack('C', 0) . "DST\r";
        [$oReply] = $this->dispatch($this->makePkt(28, $sData));
        $aB = $this->bytes($oReply);
        $this->assertGreaterThan(0, $aB[1]);
    }

    // -----------------------------------------------------------------------
    // copyData — function code 35
    // -----------------------------------------------------------------------

    public function testCopyDataSendsDoneOk(): void
    {
        $oSrc = new FakeHandle(); $oSrc->iId = 5; $oSrc->sReadData = 'HELLO';
        $oDst = new FakeHandle(); $oDst->iId = 6;
        $this->oFs->stubHandles = [5 => $oSrc, 6 => $oDst];
        // [src=5][src_offset=0 LE3][dst=6][dst_offset=0 LE3][len=5 LE3]
        $sData = pack('C', 5) . "\x00\x00\x00" . pack('C', 6) . "\x00\x00\x00" . "\x05\x00\x00";
        [$oReply] = $this->dispatch($this->makePkt(35, $sData));
        $aB = $this->bytes($oReply);
        $this->assertSame(0, $aB[1]);
    }

    public function testCopyDataCopiesBytesFromSrcToDst(): void
    {
        $oSrc = new FakeHandle(); $oSrc->iId = 5; $oSrc->sReadData = 'HELLO';
        $oDst = new FakeHandle(); $oDst->iId = 6;
        $this->oFs->stubHandles = [5 => $oSrc, 6 => $oDst];
        $sData = pack('C', 5) . "\x00\x00\x00" . pack('C', 6) . "\x00\x00\x00" . "\x05\x00\x00";
        $this->dispatch($this->makePkt(35, $sData));
        $this->assertSame('HELLO', $oDst->sWritten);
    }

    public function testCopyDataReturnsErrorOnBadHandle(): void
    {
        $this->oFs->stubHandleThrows = true;
        $sData = pack('C', 5) . "\x00\x00\x00" . pack('C', 6) . "\x00\x00\x00" . "\x05\x00\x00";
        [$oReply] = $this->dispatch($this->makePkt(35, $sData));
        $aB = $this->bytes($oReply);
        $this->assertGreaterThan(0, $aB[1]);
    }

    // -----------------------------------------------------------------------
    // setArgs — sub-arg 1 (setExt) now works
    // -----------------------------------------------------------------------

    public function testSetArgsPtrSendsDoneOk(): void
    {
        $oHandle = new FakeHandle(); $oHandle->iId = 2;
        $this->oFs->stubHandle = $oHandle;
        // handle=2, arg=0 (PTR), pos=100 LE3
        $sData = pack('C', 2) . pack('C', 0) . "\x64\x00\x00";
        [$oReply] = $this->dispatch($this->makePkt(13, $sData));
        $aB = $this->bytes($oReply);
        $this->assertSame(0, $aB[1]);
    }

    public function testSetArgsExtSendsDoneOk(): void
    {
        $oHandle = new FakeHandle(); $oHandle->iId = 2;
        $this->oFs->stubHandle = $oHandle;
        // handle=2, arg=1 (EXT), ext=256 LE3
        $sData = pack('C', 2) . pack('C', 1) . "\x00\x01\x00";
        [$oReply] = $this->dispatch($this->makePkt(13, $sData));
        $aB = $this->bytes($oReply);
        $this->assertSame(0, $aB[1]);
    }

    public function testSetArgsExtCallsSetExtOnHandle(): void
    {
        $oHandle = new FakeHandle(); $oHandle->iId = 2;
        $this->oFs->stubHandle = $oHandle;
        $sData = pack('C', 2) . pack('C', 1) . "\x00\x01\x00"; // ext = 256
        $this->dispatch($this->makePkt(13, $sData));
        $this->assertSame(256, $oHandle->capSetExt);
    }

    // -----------------------------------------------------------------------
    // *CAT CLI — produces real directory listing
    // -----------------------------------------------------------------------

    public function testCliCatContainsFilenames(): void
    {
        $oEntry = new FakeDirEntry();
        $oEntry->sName = 'MYFILE';
        $this->oFs->stubDirEntries = ['MYFILE' => $oEntry];
        [$oReply] = $this->dispatch($this->makePkt(0, "CAT\r"));
        $this->assertStringContainsString('MYFILE', $oReply->getData());
    }

    public function testCliCatContainsDiscName(): void
    {
        config::overrideValue('vfs_disc_name', 'MYDISC');
        [$oReply] = $this->dispatch($this->makePkt(0, "CAT\r"));
        $this->assertStringContainsString('MYDISC', $oReply->getData());
        config::resetValue('vfs_disc_name');
    }

    public function testCliCatRequiresLogin(): void
    {
        $this->oFs->stubUser = null;
        [$oReply] = $this->dispatch($this->makePkt(0, "CAT\r"));
        $aB = $this->bytes($oReply);
        $this->assertGreaterThan(0, $aB[1]);
    }

    // -----------------------------------------------------------------------
    // *SAVE CLI — must return a reply (not silently hang)
    // -----------------------------------------------------------------------

    public function testCliSaveReturnsReply(): void
    {
        $aReplies = $this->dispatch($this->makePkt(0, "SAVE MYFILE 0000 1000\r"));
        $this->assertCount(1, $aReplies);
    }

    public function testCliSaveReturnsErrorCode(): void
    {
        [$oReply] = $this->dispatch($this->makePkt(0, "SAVE MYFILE 0000 1000\r"));
        $aB = $this->bytes($oReply);
        $this->assertGreaterThan(0, $aB[1]);  // error code > 0 means an error reply
    }

    // -----------------------------------------------------------------------
    // *OPT CLI
    // -----------------------------------------------------------------------

    public function testCliOptSendsDoneOk(): void
    {
        [$oReply] = $this->dispatch($this->makePkt(0, "OPT 4,2\r"));
        $aB = $this->bytes($oReply);
        $this->assertSame(0, $aB[0]);
        $this->assertSame(0, $aB[1]);
    }

    public function testCliOptCapturesBootOption(): void
    {
        $this->dispatch($this->makePkt(0, "OPT 4,3\r"));
        $this->assertSame(['3'], $this->oFs->capSetOpt);
    }

    public function testCliOptZeroIsValid(): void
    {
        [$oReply] = $this->dispatch($this->makePkt(0, "OPT 4,0\r"));
        $aB = $this->bytes($oReply);
        $this->assertSame(0, $aB[1]);
        $this->assertSame(['0'], $this->oFs->capSetOpt);
    }

    public function testCliOptBadOptionNumberReturnsError(): void
    {
        [$oReply] = $this->dispatch($this->makePkt(0, "OPT 1,2\r"));
        $aB = $this->bytes($oReply);
        $this->assertGreaterThan(0, $aB[1]);
    }

    public function testCliOptValueOutOfRangeReturnsError(): void
    {
        [$oReply] = $this->dispatch($this->makePkt(0, "OPT 4,9\r"));
        $aB = $this->bytes($oReply);
        $this->assertGreaterThan(0, $aB[1]);
    }

    public function testCliOptMissingCommaReturnsError(): void
    {
        [$oReply] = $this->dispatch($this->makePkt(0, "OPT 4\r"));
        $aB = $this->bytes($oReply);
        $this->assertGreaterThan(0, $aB[1]);
    }

    public function testCliOptNoArgsReturnsError(): void
    {
        [$oReply] = $this->dispatch($this->makePkt(0, "OPT\r"));
        $aB = $this->bytes($oReply);
        $this->assertGreaterThan(0, $aB[1]);
    }

    // -----------------------------------------------------------------------
    // CLOSE handle 0 closes all handles
    // -----------------------------------------------------------------------

    public function testCloseHandleZeroClosesAll(): void
    {
        $this->dispatch($this->makePkt(7, pack('C', 0)));
        $this->assertTrue($this->oFs->capCloseAll);
    }

    public function testCloseHandleZeroSendsDoneOk(): void
    {
        [$oReply] = $this->dispatch($this->makePkt(7, pack('C', 0)));
        $aB = $this->bytes($oReply);
        $this->assertSame(0, $aB[1]);
    }

    public function testCloseNonZeroHandleDoesNotCloseAll(): void
    {
        $this->dispatch($this->makePkt(7, pack('C', 2)));
        $this->assertFalse($this->oFs->capCloseAll);
    }

    // -----------------------------------------------------------------------
    // EXAMINE_ALL — access byte from metadata, not hardcoded 0
    // -----------------------------------------------------------------------

    public function testExamineAllAccessByteComesFromDirectoryEntry(): void
    {
        $oEntry = new FakeDirEntry();
        $oEntry->iAccess = 0x33;
        $this->oFs->stubDirEntries = ['MYFILE' => $oEntry];
        [$oReply] = $this->dispatch($this->makePkt(3, pack('CCC', 0, 0, 1)));
        $aB = $this->bytes($oReply);
        // Reply: done(1)+status(1)+count(1)+name(11)+load(4)+exec(4)+access(1)+ctime(2)+sin(3)+size(3)
        // access byte is at offset 2+1+11+4+4 = 22 (1-indexed: aB[22])
        $this->assertSame(0x33, $aB[22]);
    }

    // -----------------------------------------------------------------------
    // EXAMINE_NAME — length prefix matches sent bytes
    // -----------------------------------------------------------------------

    public function testExamineNameLengthPrefixIsConsistentWithData(): void
    {
        $oEntry = new FakeDirEntry();
        $oEntry->sName = 'ABCDEFGHIJ';  // exactly 10 chars
        $this->oFs->stubDirEntries = ['ABCDEFGHIJ' => $oEntry];
        [$oReply] = $this->dispatch($this->makePkt(3, pack('CCC', 2, 0, 1)));
        $sData = $oReply->getData();
        // Structure: done(1)+status(1)+count(1)+undef(1) then per-entry: len(1)+name(len)
        // Length prefix byte is at offset 4 (0-indexed)
        $aB = array_values(unpack('C*', $sData));
        $iLenPrefix = $aB[4];
        $this->assertSame(10, $iLenPrefix);
    }

    // -----------------------------------------------------------------------
    // NEWUSER requires admin privilege
    // -----------------------------------------------------------------------

    public function testNewUserRequiresAdminPrivilege(): void
    {
        $oUser = new FsTestUser();
        $oUser->bIsAdmin = false;
        $this->oFs->stubUser = $oUser;
        [$oReply] = $this->dispatch($this->makePkt(0, "NEWUSER JTEST\r"));
        $aB = $this->bytes($oReply);
        $this->assertGreaterThan(0, $aB[1]);
    }

    public function testNewUserSucceedsForAdmin(): void
    {
        $oUser = new FsTestUser();
        $oUser->bIsAdmin = true;
        $this->oFs->stubUser = $oUser;
        [$oReply] = $this->dispatch($this->makePkt(0, "NEWUSER JTEST\r"));
        $aB = $this->bytes($oReply);
        $this->assertSame(0, $aB[1]);
    }

    // -----------------------------------------------------------------------
    // GET_INFO sub-args 3/4/5 — file-not-found returns error, not zeros
    // -----------------------------------------------------------------------

    public function testGetInfoCase3NotFoundReturnsError(): void
    {
        $this->oFs->stubMeta = null;
        [$oReply] = $this->dispatch($this->makeGetInfoPkt(3, 'NOPE'));
        $aB = $this->bytes($oReply);
        $this->assertGreaterThan(0, $aB[1]);
    }

    public function testGetInfoCase4NotFoundReturnsError(): void
    {
        $this->oFs->stubMeta = null;
        [$oReply] = $this->dispatch($this->makeGetInfoPkt(4, 'NOPE'));
        $aB = $this->bytes($oReply);
        $this->assertGreaterThan(0, $aB[1]);
    }

    public function testGetInfoCase5NotFoundReturnsError(): void
    {
        $this->oFs->stubMeta = null;
        [$oReply] = $this->dispatch($this->makeGetInfoPkt(5, 'NOPE'));
        $aB = $this->bytes($oReply);
        $this->assertGreaterThan(0, $aB[1]);
    }

    // -----------------------------------------------------------------------
    // usersOnline — correct count across multiple stations
    // -----------------------------------------------------------------------

    public function testUsersOnlineCountsUsersNotNetworks(): void
    {
        $oU1 = new FsTestUser(); $oU1->sUsername = 'A';
        $oU2 = new FsTestUser(); $oU2->sUsername = 'B';
        $oU3 = new FsTestUser(); $oU3->sUsername = 'C';
        $this->oFs->stubUsersOnline = [
            1 => [5 => ['user' => $oU1], 6 => ['user' => $oU2]],
            2 => [1 => ['user' => $oU3]],
        ];
        [$oReply] = $this->dispatch($this->makePkt(15, pack('CC', 0, 10)));
        $aB = $this->bytes($oReply);
        // byte[2] = remaining count — should be 3 (3 users total), not 2 (2 networks)
        $this->assertSame(3, $aB[2]);
    }

    // -----------------------------------------------------------------------
    // *CAT — directory argument
    // -----------------------------------------------------------------------

    public function testCliCatWithDirectoryArgListsNamedDirectory(): void
    {
        $oEntry = new FakeDirEntry();
        $oEntry->sName = 'UTILS';
        $this->oFs->stubDirEntries = ['UTILS' => $oEntry];
        [$oReply] = $this->dispatch($this->makePkt(0, "CAT $.LIBRARY\r"));
        $this->assertStringContainsString('UTILS', $oReply->getData());
    }

    // -----------------------------------------------------------------------
    // *RENAME CLI — single-word argument returns syntax error
    // -----------------------------------------------------------------------

    public function testCliRenameWithOneWordReturnsSyntaxError(): void
    {
        [$oReply] = $this->dispatch($this->makePkt(0, "RENAME OLDNAME\r"));
        $aB = $this->bytes($oReply);
        $this->assertGreaterThan(0, $aB[1]);
    }

    public function testCliRenameWithTwoWordsSucceeds(): void
    {
        [$oReply] = $this->dispatch($this->makePkt(0, "RENAME OLD NEW\r"));
        $aB = $this->bytes($oReply);
        $this->assertSame(0, $aB[1]);
        $this->assertSame('OLD', $this->oFs->capMovedFiles[0]['from']);
        $this->assertSame('NEW', $this->oFs->capMovedFiles[0]['to']);
    }

    // -----------------------------------------------------------------------
    // SET_INFO — unknown sub-arg returns error (not silent DoneOk)
    // -----------------------------------------------------------------------

    public function testSetInfoUnknownArgReturnsError(): void
    {
        $this->oFs->stubMeta = new FakeMeta();
        [$oReply] = $this->dispatch($this->makePkt(19, pack('C', 99) . "MYFILE\r"));
        $aB = $this->bytes($oReply);
        $this->assertGreaterThan(0, $aB[1]);
    }

    // -----------------------------------------------------------------------
    // getTime — seconds byte present, minutes is correct integer
    // -----------------------------------------------------------------------

    public function testGetTimeSecondsIsInRange(): void
    {
        [$oReply] = $this->dispatch($this->makePkt(16));
        $aB = $this->bytes($oReply);
        // byte[6] is seconds (0-59)
        $this->assertGreaterThanOrEqual(0, $aB[6]);
        $this->assertLessThanOrEqual(59, $aB[6]);
    }

    public function testGetTimeMinutesIsInteger(): void
    {
        [$oReply] = $this->dispatch($this->makePkt(16));
        $aB = $this->bytes($oReply);
        // byte[5] is minutes (0-59)
        $this->assertGreaterThanOrEqual(0, $aB[5]);
        $this->assertLessThanOrEqual(59, $aB[5]);
    }
}
