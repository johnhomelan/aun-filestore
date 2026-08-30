<?php

namespace HomeLan\FileStore\Services\Provider;

use HomeLan\FileStore\Services\ProviderInterface;
use HomeLan\FileStore\Services\Provider\AdminInterface;
use HomeLan\FileStore\Services\ServiceDispatcher;
use HomeLan\FileStore\Authentication\Security;
use HomeLan\FileStore\Authentication\User;
use HomeLan\FileStore\Messages\EconetPacket;
use HomeLan\FileStore\Messages\TorchnetRequest;
use HomeLan\FileStore\Messages\TorchnetReply;
use HomeLan\FileStore\Vfs\CpmVfs;
use HomeLan\FileStore\Vfs\Exception as VfsException;
use HomeLan\FileStore\Vfs\FileDescriptor;
use HomeLan\FileStore\Vfs\FilePath;
use HomeLan\FileStore\Vfs\Vfs;
use HomeLan\FileStore\Services\Provider\Torchnet\Admin;

use config;

/**
 * TorchNet file-server service.
 *
 * Handles the TorchNet wire protocol (ports 0x90 and 0x91) used by Torch
 * Communicator workstations running CP/M.  All filesystem access goes through
 * the CP/M compatibility layer:
 *
 *   • Directory listings use CpmVfs::createFsHandle() + getDirectoryListing()
 *     so that entries come back as CpmDirectoryEntry objects with getCpmName()
 *     already returning the CP/M dot-extension form (e.g. "MYPROG.COM").
 *
 *   • File I/O uses CpmVfs::createFsHandleForFile(), deleteFileCpm(), and
 *     moveFileCpm().  These methods use an internal toAcornFilePath() helper
 *     that correctly converts directory '\' to '.' and filename '.' to '\',
 *     preserving the extension-separator convention used by CpmDirectoryEntry.
 *
 * Filesystem layout:
 *   Drive letter 'E' → Acorn directory $.TorchDrives.E  (default)
 *                       Override per drive via config key torchnet_drive_e
 *   File MYPROG.COM  → Acorn file   $.TorchDrives.E.MYPROG\COM
 *                       ('\' in the Acorn name is the CP/M extension marker;
 *                        CpmDirectoryEntry::getCpmName() converts it to '.')
 *
 * @package core
 *
 * @phpstan-type TorchnetCpmPattern array{name:string,ext:string}
 * @phpstan-type TorchnetSearchMatch array{name:string,ext:string,size:int}
 * @phpstan-type TorchnetSearchState array{drive:string,pattern:TorchnetCpmPattern,matches:array<int,TorchnetSearchMatch>,cursor:int}
 */
class Torchnet implements ProviderInterface
{
    /** @var array<int,TorchnetReply> */
    private array $aReplyBuffer = [];

    /** @var array<int,array<int,array<int,FileDescriptor>>> [net][stn][torchHandle] => FileDescriptor */
    private array $aFileHandles = [];

    /** @var array<int,array<int,int>> [net][stn] => next handle id to allocate (1–254, wrapping) */
    private array $aNextHandle = [];

    /**
     * Per-station search state.
     * Key: "{net}.{stn}"
     * Value: ['drive', 'pattern' => ['name','ext'], 'matches' => [...], 'cursor']
     *
     * @var array<string,TorchnetSearchState>
     */
    private array $aSearchState = [];

    public function __construct(private readonly \Psr\Log\LoggerInterface $oLogger)
    {
    }

    public function getName(): string
    {
        return 'TorchNet File Server';
    }

    public function getAdminInterface(): ?AdminInterface
    {
        return new Admin($this);
    }

    /**
     * @return array<int,int>
    */
    public function getServicePorts(): array
    {
        return [0x90, 0x91];
    }

    public function registerService(ServiceDispatcher $oServiceDispatcher): void
    {
    }

    /**
     * @return array<int,array<string,mixed>>
    */
    public function getJobs(): array
    {
        return [];
    }

    public function broadcastPacketIn(EconetPacket $oPacket): void
    {
        // TorchNet is strictly unicast request/response.
    }

    public function unicastPacketIn(EconetPacket $oPacket): void
    {
        $this->processRequest(new TorchnetRequest($oPacket, $this->oLogger));
    }

    /**
     * @return array<int,EconetPacket>
    */
    public function getReplies(): array
    {
        $aReturn = [];
        foreach ($this->aReplyBuffer as $oReply) {
            $aReturn[] = $oReply->buildEconetpacket();
        }
        $this->aReplyBuffer = [];
        return $aReturn;
    }

    // -------------------------------------------------------------------------
    // Command dispatch
    // -------------------------------------------------------------------------

    private function processRequest(TorchnetRequest $oRequest): void
    {
        $this->oLogger->debug('TorchNet: ' . $oRequest->getCommand()
            . ' from ' . $oRequest->getSourceNetwork() . '.' . $oRequest->getSourceStation());

        switch ($oRequest->getCommand()) {
            case 'TORCH_OPEN':
                $this->openFile($oRequest, true);
                break;
            case 'TORCH_CREATE':
                $this->openFile($oRequest, false);
                break;
            case 'TORCH_CLOSE':
                $this->closeFile($oRequest);
                break;
            case 'TORCH_READ_BLOCK':
                $this->readBlock($oRequest);
                break;
            case 'TORCH_WRITE_BLOCK':
                $this->writeBlock($oRequest);
                break;
            case 'TORCH_DELETE':
                $this->deleteFile($oRequest);
                break;
            case 'TORCH_SEARCH_FIRST':
                $this->doSearch($oRequest, true);
                break;
            case 'TORCH_SEARCH_NEXT':
                $this->doSearch($oRequest, false);
                break;
            case 'TORCH_RENAME':
                $this->renameFile($oRequest);
                break;
            case 'TORCH_CONSOLE_NOTIFY':
            case 'TORCH_PRINT_REDIRECT':
                // Notification-only commands; no reply expected.
                break;
            default:
                // Memory peek/poke and control actions address the client's own
                // hardware.  The server cannot fulfill them; return an error.
                $oReply = $oRequest->buildReply();
                $oReply->error();
                $this->aReplyBuffer[] = $oReply;
                break;
        }
    }

    // -------------------------------------------------------------------------
    // File operations
    // -------------------------------------------------------------------------

    /**
     * OPEN (0x01) and CREATE (0x0D).
     *
     * Payload after cmd: [DriveId(1), AccessMode(1), Filename(11)]
     * Access modes: 0x01 = Read-Only, 0x02 = Write-Only, 0x03 = Read/Write.
     * CREATE uses bMustExist=false so the file is created if absent.
     */
    private function openFile(TorchnetRequest $oRequest, bool $bMustExist): void
    {
        $iNet  = $oRequest->getSourceNetwork();
        $iStn  = $oRequest->getSourceStation();
        $sDriveId = chr($oRequest->getByte(1) ?? 0);
        $iMode    = $oRequest->getByte(2);
        $aFilename = $oRequest->parseCpmFilename(3);

        $oReply = $oRequest->buildReply();

        if ($iNet === null || $iStn === null) {
            $this->oLogger->warning('TorchNet open: no source network/station on request');
            $oReply->openError();
            $this->aReplyBuffer[] = $oReply;
            return;
        }

        try {
            $sCpmPath  = $this->buildCpmFilePath($sDriveId, $aFilename['name'], $aFilename['ext']);
            $bReadOnly = ($iMode === 0x01);
            $oFd = $this->cpmCreateFileHandle($iNet, $iStn, $sCpmPath, $bMustExist, $bReadOnly);

            $iHandle = $this->allocateHandle($iNet, $iStn);
            $this->aFileHandles[$iNet][$iStn][$iHandle] = $oFd;
            $oReply->openOk($iHandle);
        } catch (\Throwable $e) {
            $this->oLogger->error('TorchNet open: ' . $e->getMessage());
            $oReply->openError();
        }

        $this->aReplyBuffer[] = $oReply;
    }

    /**
     * CLOSE (0x02).
     *
     * Payload after cmd: [FileHandle(1)]
     */
    private function closeFile(TorchnetRequest $oRequest): void
    {
        $iNet    = $oRequest->getSourceNetwork();
        $iStn    = $oRequest->getSourceStation();
        $iHandle = $oRequest->getByte(1);

        $oReply = $oRequest->buildReply();

        if (isset($this->aFileHandles[$iNet][$iStn][$iHandle])) {
            try {
                $this->aFileHandles[$iNet][$iStn][$iHandle]->close();
            } catch (\Throwable $e) {
                $this->oLogger->error('TorchNet close: ' . $e->getMessage());
                $oReply->error();
                $this->aReplyBuffer[] = $oReply;
                return;
            }
            unset($this->aFileHandles[$iNet][$iStn][$iHandle]);
            $oReply->ok();
        } else {
            $this->oLogger->warning("TorchNet close: unknown handle $iHandle");
            $oReply->error();
        }

        $this->aReplyBuffer[] = $oReply;
    }

    /**
     * READ BLOCK (0x03).
     *
     * Payload after cmd: [FileHandle(1), RecordOffset(2 LE), MaxSectors(1)]
     * Each record / sector is 128 bytes.  RecordOffset is zero-indexed.
     */
    private function readBlock(TorchnetRequest $oRequest): void
    {
        $iNet    = $oRequest->getSourceNetwork();
        $iStn    = $oRequest->getSourceStation();
        $iHandle = $oRequest->getByte(1);
        $iRecord = $oRequest->get16bitIntLittleEndian(2);
        $iMax    = $oRequest->getByte(4);

        $oReply = $oRequest->buildReply();

        if (!isset($this->aFileHandles[$iNet][$iStn][$iHandle])) {
            $oReply->readEof();
            $this->aReplyBuffer[] = $oReply;
            return;
        }

        $oFd = $this->aFileHandles[$iNet][$iStn][$iHandle];

        try {
            $oFd->setPos($iRecord * 128);
            $sData = $oFd->read($iMax * 128);
            $bEof  = (bool) $oFd->isEof();

            $sDataStr = is_scalar($sData) ? (string) $sData : '';
            if ($sDataStr === '') {
                $oReply->readEof();
            } else {
                $oReply->readOk($sDataStr, $bEof);
            }
        } catch (\Throwable $e) {
            $this->oLogger->error('TorchNet read: ' . $e->getMessage());
            $oReply->readEof();
        }

        $this->aReplyBuffer[] = $oReply;
    }

    /**
     * WRITE BLOCK (0x04).
     *
     * Payload after cmd: [FileHandle(1), RecordOffset(2 LE), Length(1)=128, Data(128)]
     */
    private function writeBlock(TorchnetRequest $oRequest): void
    {
        $iNet    = $oRequest->getSourceNetwork();
        $iStn    = $oRequest->getSourceStation();
        $iHandle = $oRequest->getByte(1);
        $iRecord = $oRequest->get16bitIntLittleEndian(2);
        $sData   = substr((string) $oRequest->getData(), 4, 128);

        $oReply = $oRequest->buildReply();

        if (!isset($this->aFileHandles[$iNet][$iStn][$iHandle])) {
            $oReply->error();
            $this->aReplyBuffer[] = $oReply;
            return;
        }

        $oFd = $this->aFileHandles[$iNet][$iStn][$iHandle];

        try {
            $oFd->setPos($iRecord * 128);
            $oFd->write($sData);
            $oReply->ok();
        } catch (\Throwable $e) {
            $this->oLogger->error('TorchNet write: ' . $e->getMessage());
            $oReply->error();
        }

        $this->aReplyBuffer[] = $oReply;
    }

    /**
     * DELETE (0x05).
     *
     * Payload after cmd: [DriveId(1), UserGroup(1), Filename(11)]
     */
    private function deleteFile(TorchnetRequest $oRequest): void
    {
        $iNet     = $oRequest->getSourceNetwork();
        $iStn     = $oRequest->getSourceStation();
        $sDriveId = chr($oRequest->getByte(1) ?? 0);
        $aFilename = $oRequest->parseCpmFilename(3);

        $oReply = $oRequest->buildReply();

        if ($iNet === null || $iStn === null) {
            $this->oLogger->warning('TorchNet delete: no source network/station on request');
            $oReply->error();
            $this->aReplyBuffer[] = $oReply;
            return;
        }

        try {
            $sCpmPath = $this->buildCpmFilePath($sDriveId, $aFilename['name'], $aFilename['ext']);
            $this->cpmDeleteFile($iNet, $iStn, $sCpmPath);
            $oReply->ok();
        } catch (\Throwable $e) {
            $this->oLogger->error('TorchNet delete: ' . $e->getMessage());
            $oReply->error();
        }

        $this->aReplyBuffer[] = $oReply;
    }

    /**
     * RENAME (0x0E).
     *
     * Payload after cmd: [DriveId(1), OldName(11), NewName(11)]
     */
    private function renameFile(TorchnetRequest $oRequest): void
    {
        $iNet     = $oRequest->getSourceNetwork();
        $iStn     = $oRequest->getSourceStation();
        $sDriveId = chr($oRequest->getByte(1) ?? 0);
        $aOldName = $oRequest->parseCpmFilename(2);
        $aNewName = $oRequest->parseCpmFilename(13);

        $oReply = $oRequest->buildReply();

        if ($iNet === null || $iStn === null) {
            $this->oLogger->warning('TorchNet rename: no source network/station on request');
            $oReply->error();
            $this->aReplyBuffer[] = $oReply;
            return;
        }

        try {
            $sOldPath = $this->buildCpmFilePath($sDriveId, $aOldName['name'], $aOldName['ext']);
            $sNewPath = $this->buildCpmFilePath($sDriveId, $aNewName['name'], $aNewName['ext']);
            $this->cpmMoveFile($iNet, $iStn, $sOldPath, $sNewPath);
            $oReply->ok();
        } catch (\Throwable $e) {
            $this->oLogger->error('TorchNet rename: ' . $e->getMessage());
            $oReply->error();
        }

        $this->aReplyBuffer[] = $oReply;
    }

    // -------------------------------------------------------------------------
    // Directory search
    // -------------------------------------------------------------------------

    /**
     * SEARCH FIRST (0x06) and SEARCH NEXT (0x07).
     *
     * Payload after cmd: [DriveId(1), UserGroup(1), FilenameMask(11)]
     *
     * Search First starts a new search; Search Next advances the cursor.
     * Both commands carry the same parameters (per spec), which also lets
     * Search Next restart if the client sends a different drive or pattern.
     *
     * Files are listed via CpmVfs::getDirectoryListing() so that entry names
     * arrive as getCpmName() strings (e.g. "MYPROG.COM") ready for matching.
     */
    private function doSearch(TorchnetRequest $oRequest, bool $bFirst): void
    {
        $iNet     = $oRequest->getSourceNetwork();
        $iStn     = $oRequest->getSourceStation();
        $sDriveId = chr($oRequest->getByte(1) ?? 0);
        $aPattern = $oRequest->parseCpmFilename(3);

        $oReply   = $oRequest->buildReply();

        if ($iNet === null || $iStn === null) {
            $this->oLogger->warning('TorchNet search: no source network/station on request');
            $oReply->searchEnd();
            $this->aReplyBuffer[] = $oReply;
            return;
        }

        $sKey     = $iNet . '.' . $iStn;

        $bNeedNewSearch = $bFirst
            || !isset($this->aSearchState[$sKey])
            || $this->aSearchState[$sKey]['drive']   !== $sDriveId
            || $this->aSearchState[$sKey]['pattern'] !== $aPattern;

        if ($bNeedNewSearch) {
            $sCpmDirPath = $this->buildCpmDirPath($sDriveId);

            try {
                $oFd = $this->cpmCreateFsHandle($iNet, $iStn, $sCpmDirPath, true, true);
                $aAllEntries = $this->cpmGetDirectoryListing($oFd);
                $oFd->close();
            } catch (\Throwable $e) {
                $this->oLogger->error('TorchNet search: ' . $e->getMessage());
                $oReply->searchEnd();
                $this->aReplyBuffer[] = $oReply;
                return;
            }

            // Filter by pattern, skipping subdirectories.
            $aMatches = [];
            foreach ($aAllEntries as $oEntry) {
                if ($oEntry->isDir()) {
                    continue;
                }
                // getCpmName() translates Acorn '\' → '.' giving e.g. "MYPROG.COM"
                $sCpmName = $oEntry->getCpmName();
                if (str_contains($sCpmName, '.')) {
                    [$sEntryName, $sEntryExt] = explode('.', $sCpmName, 2);
                } else {
                    $sEntryName = $sCpmName;
                    $sEntryExt  = '';
                }
                if ($this->matchCpmPattern($sEntryName, $sEntryExt, $aPattern['name'], $aPattern['ext'])) {
                    $aMatches[] = [
                        'name' => $sEntryName,
                        'ext'  => $sEntryExt,
                        'size' => (int) $oEntry->getSize(),
                    ];
                }
            }

            $this->aSearchState[$sKey] = [
                'drive'   => $sDriveId,
                'pattern' => $aPattern,
                'matches' => $aMatches,
                'cursor'  => 0,
            ];
        }

        $aState = $this->aSearchState[$sKey];

        if ($aState['cursor'] >= count($aState['matches'])) {
            $oReply->searchEnd();
        } else {
            $aMatch       = $aState['matches'][$aState['cursor']];
            $iRecordCount = max(1, (int) ceil($aMatch['size'] / 128));
            $oReply->searchFound($aMatch['name'], $aMatch['ext'], $iRecordCount);
            $aState['cursor']++;
            $this->aSearchState[$sKey] = $aState;
        }

        $this->aReplyBuffer[] = $oReply;
    }

    // -------------------------------------------------------------------------
    // Path helpers
    // -------------------------------------------------------------------------

    /**
     * Build a CP/M-style file path for a given drive + 8+3 name.
     *
     * Uses '\' as the directory separator (CP/M convention) and '.' as the
     * filename extension separator (also CP/M convention).  The result is
     * suitable for passing to CpmVfs::createFsHandleForFile(),
     * CpmVfs::deleteFileCpm(), and CpmVfs::moveFileCpm(), which translate it
     * to the correct Acorn path — converting directory '\' to '.' and filename
     * '.' to '\' (the extension-separator convention stored in the Acorn FS).
     *
     * Example: drive='E', name='MYPROG', ext='COM' → '\TorchDrives\E\MYPROG.COM'
     */
    private function buildCpmFilePath(string $sDriveId, string $sName, string $sExt): string
    {
        $sCpmDrivePath = $this->buildCpmDirPath($sDriveId); // e.g. '\TorchDrives\E'
        $sFilename     = ($sExt !== '') ? "{$sName}.{$sExt}" : $sName;
        return "{$sCpmDrivePath}\\{$sFilename}";
    }

    /**
     * Return the Acorn root path for a drive letter.
     *
     * Configurable via the config key "torchnet_drive_{letter}" (lowercase).
     * Defaults to "$.TorchDrives.{LETTER}".
     */
    private function getDrivePath(string $sDriveId): string
    {
        $sKey = 'torchnet_drive_' . strtolower($sDriveId);
        try {
            $sPath = config::getValueAsString($sKey);
            if ($sPath !== '') {
                return $sPath;
            }
        } catch (\Throwable) {
        }
        return '$.TorchDrives.' . strtoupper($sDriveId);
    }

    /**
     * Return the CP/M-style path for a drive's root directory, suitable for
     * passing to CpmVfs::createFsHandle() for a directory listing.
     *
     * CpmVfs::toCpmPath() strips the '$' prefix and converts '.' to '\',
     * so "$.TorchDrives.E" becomes "\TorchDrives\E".  CpmVfs::toAcornPath()
     * then converts that back to "$.TorchDrives.E" when building the handle —
     * a round-trip that is correct for the directory path (no '\' in filename).
     */
    private function buildCpmDirPath(string $sDriveId): string
    {
        return CpmVfs::toCpmPath($this->getDrivePath($sDriveId));
    }

    // -------------------------------------------------------------------------
    // Handle allocation
    // -------------------------------------------------------------------------

    private function allocateHandle(int $iNet, int $iStn): int
    {
        if (!isset($this->aNextHandle[$iNet][$iStn])) {
            $this->aNextHandle[$iNet][$iStn] = 1;
        }
        $iHandle = $this->aNextHandle[$iNet][$iStn];
        // Wrap at 0xFE — 0xFF is reserved as an error sentinel in responses.
        $this->aNextHandle[$iNet][$iStn] = ($iHandle % 0xFE) + 1;
        return $iHandle;
    }

    // -------------------------------------------------------------------------
    // Admin browsing helpers
    // -------------------------------------------------------------------------

    /**
     * Return configured drives as ['letter' => 'acorn_path'].
     * Drive E is always included with its default or configured path.
     *
     * @return array<string,string>
     */
    public function getConfiguredDrives(): array
    {
        $aDrives = [];
        foreach (range('A', 'Z') as $sLetter) {
            $sKey = 'torchnet_drive_' . strtolower($sLetter);
            try {
                $sPath = config::getValueAsString($sKey);
                if ($sPath !== '') {
                    $aDrives[$sLetter] = $sPath;
                }
            } catch (\Throwable) {
            }
        }
        if (!array_key_exists('E', $aDrives)) {
            $aDrives['E'] = '$.TorchDrives.E';
        }
        ksort($aDrives);
        return $aDrives;
    }

    /**
     * List a directory by Acorn path, querying VFS plugins directly.
     * Bypasses the authentication layer so it is safe to call from the admin UI.
     *
     * @return array<string,\HomeLan\FileStore\Vfs\DirectoryEntry>
     */
    public function getAdminDirectoryListing(string $sAcornPath): array
    {
        $aListing = [];
        foreach (Vfs::getVfsPlugins() as $sPlugin) {
            try {
                $aListing = $sPlugin::getDirectoryListing($sAcornPath, $aListing);
            } catch (VfsException $e) {
                if ($e->isHard()) {
                    break;
                }
            } catch (\Throwable) {
            }
        }
        return $aListing;
    }

    /**
     * Return the raw contents of a file by Acorn path, querying VFS plugins directly.
     * Returns null if no plugin can serve the file.
     */
    public function getAdminFileContents(string $sAcornPath): ?string
    {
        $iLastDot = strrpos($sAcornPath, '.');
        if ($iLastDot === false) {
            return null;
        }
        $oPath      = new FilePath(substr($sAcornPath, 0, $iLastDot), substr($sAcornPath, $iLastDot + 1));
        $oDummyUser = new User();
        $oDummyUser->setUsername('_admin');
        $oDummyUser->setUnixUid(posix_getuid());

        foreach (Vfs::getVfsPlugins() as $sPlugin) {
            try {
                return $sPlugin::getFile($oDummyUser, $oPath);
            } catch (VfsException $e) {
                if ($e->isHard()) {
                    return null;
                }
            } catch (\Throwable) {
            }
        }
        return null;
    }

    // -------------------------------------------------------------------------
    // Admin data accessors
    // -------------------------------------------------------------------------

    /**
     * Return one row per network/station that has open file handles or an
     * active search state.  Each row has 'network', 'station', and
     * 'open_handles' (count of currently open file descriptors).
     *
     * @return array<int,array<string,int>>
     */
    public function getConnectedStations(): array
    {
        $aStations = [];

        foreach ($this->aFileHandles as $iNet => $aStations_) {
            foreach ($aStations_ as $iStn => $aHandles) {
                $sKey = $iNet . '.' . $iStn;
                $aStations[$sKey] = [
                    'network'      => $iNet,
                    'station'      => $iStn,
                    'open_handles' => count($aHandles),
                ];
            }
        }

        foreach ($this->aSearchState as $sStateKey => $aState) {
            if (!array_key_exists($sStateKey, $aStations)) {
                [$iNet, $iStn] = explode('.', $sStateKey, 2);
                $aStations[$sStateKey] = [
                    'network'      => (int) $iNet,
                    'station'      => (int) $iStn,
                    'open_handles' => 0,
                ];
            }
        }

        return array_values($aStations);
    }

    /**
     * Return one row per open file descriptor across all stations.
     * Each row has 'network', 'station', 'handle', and 'path' (Acorn path).
     *
     * @return array<int,array<string,int|string>>
     */
    public function getOpenFileHandles(): array
    {
        $aRows = [];

        foreach ($this->aFileHandles as $iNet => $aStations) {
            foreach ($aStations as $iStn => $aHandles) {
                foreach ($aHandles as $iHandle => $oFd) {
                    $aRows[] = [
                        'network' => $iNet,
                        'station' => $iStn,
                        'handle'  => $iHandle,
                        'path'    => (string) $oFd->getEconetPath(),
                    ];
                }
            }
        }

        return $aRows;
    }

    // -------------------------------------------------------------------------
    // VFS dispatch wrappers (protected so tests can override without calling
    // static VFS methods that depend on a real initialised filesystem)
    // -------------------------------------------------------------------------

    protected function cpmCreateFileHandle(int $iNet, int $iStn, string $sCpmPath, bool $bMustExist, bool $bReadOnly): FileDescriptor
    {
        return CpmVfs::createFsHandleForFile($iNet, $iStn, $sCpmPath, $bMustExist, $bReadOnly);
    }

    protected function cpmDeleteFile(int $iNet, int $iStn, string $sCpmPath): void
    {
        CpmVfs::deleteFileCpm($iNet, $iStn, $sCpmPath);
    }

    protected function cpmMoveFile(int $iNet, int $iStn, string $sFrom, string $sTo): void
    {
        CpmVfs::moveFileCpm($iNet, $iStn, $sFrom, $sTo);
    }

    protected function cpmCreateFsHandle(int $iNet, int $iStn, string $sCpmPath, bool $bMustExist, bool $bReadOnly): FileDescriptor
    {
        return CpmVfs::createFsHandle($iNet, $iStn, $sCpmPath, $bMustExist, $bReadOnly, true);
    }

    /**
     * @return \HomeLan\FileStore\Vfs\CpmDirectoryEntry[]
    */
    protected function cpmGetDirectoryListing(FileDescriptor $oFd): array
    {
        return CpmVfs::getDirectoryListing($oFd);
    }

    // -------------------------------------------------------------------------
    // CP/M wildcard matching
    // -------------------------------------------------------------------------

    /**
     * Match a CP/M 8+3 filename against an 8+3 pattern where '?' matches any
     * single character.  Comparison is case-insensitive.
     */
    private function matchCpmPattern(
        string $sName, string $sExt,
        string $sNamePat, string $sExtPat
    ): bool {
        $sName    = str_pad(strtoupper($sName),    8, ' ');
        $sExt     = str_pad(strtoupper($sExt),     3, ' ');
        $sNamePat = str_pad(strtoupper($sNamePat), 8, ' ');
        $sExtPat  = str_pad(strtoupper($sExtPat),  3, ' ');

        for ($i = 0; $i < 8; $i++) {
            if ($sNamePat[$i] !== '?' && $sNamePat[$i] !== $sName[$i]) {
                return false;
            }
        }
        for ($i = 0; $i < 3; $i++) {
            if ($sExtPat[$i] !== '?' && $sExtPat[$i] !== $sExt[$i]) {
                return false;
            }
        }
        return true;
    }
}
