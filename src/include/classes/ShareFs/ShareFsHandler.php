<?php

namespace HomeLan\FileStore\ShareFs;

use HomeLan\FileStore\ShareFs\Messages\ShareFsPacket;
use HomeLan\FileStore\Vfs\Vfs;
use HomeLan\FileStore\Vfs\FileDescriptor;
use HomeLan\FileStore\Vfs\DirectoryEntry;
use React\Datagram\SocketInterface;
use config;
use Exception;

/**
 * Handles the ShareFS file-data RPC protocol over UDP (port 49171).
 *
 * Command semantics verified against andrewtimmins/riscos-access-server's src/ops.c - see
 * docs/protocols/sharefs.md for the full command table and what is/isn't implemented (the
 * alternate 'B'-command framing, and RDEADHANDLES' proactive broadcast, are documented gaps).
 *
 * There is no per-client login: every operation runs as one fixed service identity
 * (Command\ShareFsd logs it in once at startup - see sharefs_service_* config), matching real
 * ShareFS/Access+ having no user-account concept at all. Handle ownership (which UDP client
 * may use which handle) is tracked here rather than in Vfs, since Vfs's handle table has no
 * notion of "client" - only of (network, station), which every client here shares.
 */
class ShareFsHandler
{
    // Standard POSIX errno values, since the wire protocol expects them regardless of host OS.
    private const int EPERM  = 1;
    private const int ENOENT = 2;
    private const int EIO    = 5;
    private const int EBADF  = 9;
    private const int EACCES = 13;
    private const int ENOTDIR = 20;
    private const int EINVAL = 22;
    private const int ENOSYS = 38;

    /** Seconds a pending streaming read/write/rename transaction may sit idle before houseKeeping() expires it. */
    private const int PENDING_TIMEOUT_SECONDS = 30;

    /** Directory listing byte budget per RREADDIR page, matching the reference server's ~1800-byte entry buffer. */
    private const int READDIR_PAGE_BUDGET = 1400;

    private SocketInterface $oSocket;

    /** @var array<int, string> Vfs handle id => owning client's "ip:port" */
    private array $aHandleOwners = [];

    /** @var array<int, string> Vfs handle id => the share it was opened under, so a write-type command on an already-open handle can still be rejected against a Read-only share. */
    private array $aHandleShares = [];

    /** @var array<string, array{handle:int, client:string, address:string, start:int, pos:int, end:int, started:int}> keyed by 3-byte rid */
    private array $aPendingReads = [];

    /** @var array<string, array{handle:int, client:string, address:string, start:int, pos:int, end:int, started:int}> keyed by 3-byte rid */
    private array $aPendingWrites = [];

    /** @var array{rid:string, client:string, address:string, oldSharePath:string, newNameLength:int, started:int}|null */
    private ?array $aPendingRename = null;

    public function __construct(private readonly \Psr\Log\LoggerInterface $oLogger)
    {
    }

    public function setSocket(SocketInterface $oSocket): void
    {
        $this->oSocket = $oSocket;
    }

    public function receive(string $sMessage, string $sSrcAddress): void
    {
        if ($sMessage === '') {
            return;
        }
        try {
            match ($sMessage[0]) {
                'A', 'F' => $this->handleRequest(ShareFsPacket::decodeRequest($sMessage), $sSrcAddress),
                'd' => $this->handleWriteContinuation(ShareFsPacket::decodeWriteData($sMessage), $sSrcAddress),
                'r' => $this->handleReadAck(ShareFsPacket::decodeReadAck($sMessage), $sSrcAddress),
                default => $this->oLogger->debug("ShareFs Data: ignoring command type \"{$sMessage[0]}\" from {$sSrcAddress}"),
            };
        } catch (Exception $oException) {
            $this->oLogger->warning("ShareFs Data: discarding malformed packet from {$sSrcAddress}: " . $oException->getMessage());
        }
    }

    /** Expires stale streaming transactions - called from ShareFsd's periodic housekeeping timer. */
    public function houseKeeping(): void
    {
        $iNow = time();
        foreach (['aPendingReads', 'aPendingWrites'] as $sProp) {
            foreach ($this->$sProp as $sRid => $aState) {
                if ($iNow - $aState['started'] > self::PENDING_TIMEOUT_SECONDS) {
                    unset($this->{$sProp}[$sRid]);
                }
            }
        }
        if ($this->aPendingRename !== null && $iNow - $this->aPendingRename['started'] > self::PENDING_TIMEOUT_SECONDS) {
            $this->aPendingRename = null;
        }
    }

    private function send(string $sPayload, string $sDestAddress): void
    {
        $this->oSocket->send($sPayload, $sDestAddress);
    }

    private function sendError(string $sRid, int $iErrno, string $sDestAddress): void
    {
        $this->send(ShareFsPacket::encodeError($sRid, $iErrno), $sDestAddress);
    }

    private function sendSuccess(string $sRid, string $sDestAddress, string $sPayload = ''): void
    {
        $this->send(ShareFsPacket::encodeSuccess($sRid, $sPayload), $sDestAddress);
    }

    // -----------------------------------------------------------------------
    // Request dispatch
    // -----------------------------------------------------------------------

    private function handleRequest(ShareFsPacket $oRequest, string $sSrcAddress): void
    {
        $sRid = $oRequest->getRid();

        try {
            match ($oRequest->getCode()) {
                ShareFsPacket::CODE_RFIND      => $this->handleFind($oRequest, $sSrcAddress),
                ShareFsPacket::CODE_ROPENIN    => $this->handleOpen($oRequest, $sSrcAddress, true),
                ShareFsPacket::CODE_ROPENUP    => $this->handleOpen($oRequest, $sSrcAddress, false),
                ShareFsPacket::CODE_ROPENDIR   => $this->handleOpenDir($oRequest, $sSrcAddress),
                ShareFsPacket::CODE_RCREATE    => $this->handleCreate($oRequest, $sSrcAddress),
                ShareFsPacket::CODE_RCREATEDIR => $this->handleCreateDir($oRequest, $sSrcAddress),
                ShareFsPacket::CODE_RDELETE    => $this->handleDelete($oRequest, $sSrcAddress),
                ShareFsPacket::CODE_RACCESS    => $this->handleAccess($oRequest, $sSrcAddress),
                ShareFsPacket::CODE_RFREESPACE => $this->handleFreeSpace($oRequest, $sSrcAddress),
                ShareFsPacket::CODE_RRENAME    => $this->handleRenameArm($oRequest, $sSrcAddress),
                ShareFsPacket::CODE_RCLOSE     => $this->handleClose($oRequest, $sSrcAddress),
                ShareFsPacket::CODE_RREAD      => $this->handleReadStart($oRequest, $sSrcAddress),
                ShareFsPacket::CODE_RWRITE     => $this->handleWriteStart($oRequest, $sSrcAddress),
                ShareFsPacket::CODE_RREADDIR   => $this->handleReadDir($oRequest, $sSrcAddress),
                ShareFsPacket::CODE_RENSURE    => $this->handleEnsure($oRequest, $sSrcAddress),
                ShareFsPacket::CODE_RSETLENGTH => $this->handleSetLength($oRequest, $sSrcAddress),
                ShareFsPacket::CODE_RSETINFO   => $this->handleSetInfo($oRequest, $sSrcAddress),
                ShareFsPacket::CODE_RGETSEQPTR => $this->handleGetSeqPtr($oRequest, $sSrcAddress),
                ShareFsPacket::CODE_RSETSEQPTR => $this->handleSetSeqPtr($oRequest, $sSrcAddress),
                ShareFsPacket::CODE_RDEADHANDLES => $this->handleDeadHandles($oRequest, $sSrcAddress),
                ShareFsPacket::CODE_RZERO      => $this->handleZero($oRequest, $sSrcAddress),
                ShareFsPacket::CODE_RVERSION   => $this->sendSuccess($sRid, $sSrcAddress, pack('V', 2)),
                default => $this->sendError($sRid, self::ENOSYS, $sSrcAddress),
            };
        } catch (ShareFsException $oShareFsException) {
            $this->sendError($sRid, $oShareFsException->getErrno(), $sSrcAddress);
        } catch (Exception $oException) {
            $this->oLogger->info('ShareFs Data: command ' . $oRequest->getCode() . " from {$sSrcAddress} failed: " . $oException->getMessage());
            $this->sendError($sRid, self::EIO, $sSrcAddress);
        }
    }

    // -----------------------------------------------------------------------
    // Path resolution and access control
    // -----------------------------------------------------------------------

    /** The share name component of a "<share>.<relative path>" or bare "<share>" client path. */
    private static function shareNameOf(string $sSharePath): string
    {
        $iDot = strpos($sSharePath, '.');
        return $iDot === false ? $sSharePath : substr($sSharePath, 0, $iDot);
    }

    private function requireShare(string $sShareName, string $sClientIp, bool $bRequireWrite = false): Share
    {
        $oShare = ShareList::getShare($sShareName);
        if ($oShare === null) {
            throw new ShareFsException(self::ENOENT, "No such share \"{$sShareName}\"");
        }
        if ($oShare->isProtected() && !ShareAuthTable::check($sClientIp, $sShareName)) {
            throw new ShareFsException(self::EACCES, "Share \"{$sShareName}\" requires Access+ authentication");
        }
        if ($bRequireWrite && $oShare->isReadOnly()) {
            throw new ShareFsException(self::EACCES, "Share \"{$sShareName}\" is read-only");
        }
        return $oShare;
    }

    /**
     * Resolves a "<share>.<relative path>" client path to a full Vfs econet path, enforcing
     * the share's protected/readonly attributes on the way.
     */
    private function resolveSharePath(string $sSharePath, string $sClientIp, bool $bRequireWrite = false): string
    {
        $sShareName = self::shareNameOf($sSharePath);
        $iDot = strpos($sSharePath, '.');
        $sRelative = $iDot === false ? '' : substr($sSharePath, $iDot + 1);

        $oShare = $this->requireShare($sShareName, $sClientIp, $bRequireWrite);

        return $sRelative === '' ? $oShare->getVfsPath() : $oShare->getVfsPath() . '.' . $sRelative;
    }

    /** @return array{network:int, station:int} */
    private function serviceAddress(): array
    {
        return ['network' => config::getValueAsInt('sharefs_service_network'), 'station' => config::getValueAsInt('sharefs_service_station')];
    }

    /** Records which share a newly opened/created handle belongs to, for requireWritableHandle()'s benefit later. */
    private function trackHandle(int $iHandle, string $sSrcAddress, string $sShareName): void
    {
        $this->aHandleOwners[$iHandle] = $sSrcAddress;
        $this->aHandleShares[$iHandle] = $sShareName;
    }

    private function requireOwnedHandle(int $iHandle, string $sClientKey): FileDescriptor
    {
        if (($this->aHandleOwners[$iHandle] ?? null) !== $sClientKey) {
            throw new ShareFsException(self::EBADF, "Handle {$iHandle} not owned by this client");
        }
        $aAddress = $this->serviceAddress();
        return Vfs::getFsHandle($aAddress['network'], $aAddress['station'], $iHandle);
    }

    /**
     * Like requireOwnedHandle(), but also rejects the handle if the share it was opened under
     * is Read-only - opening a file with ROPENIN (read-only) is allowed on any share, so a
     * write-type command reaching the handle later is the only remaining place this needs
     * checking; path-based write commands already check it via resolveSharePath().
     */
    private function requireWritableHandle(int $iHandle, string $sClientKey): FileDescriptor
    {
        $oFd = $this->requireOwnedHandle($iHandle, $sClientKey);
        $sShareName = $this->aHandleShares[$iHandle] ?? null;
        if ($sShareName !== null) {
            $oShare = ShareList::getShare($sShareName);
            if ($oShare !== null && $oShare->isReadOnly()) {
                throw new ShareFsException(self::EACCES, "Share \"{$sShareName}\" is read-only");
            }
        }
        return $oFd;
    }

    // -----------------------------------------------------------------------
    // FileDesc construction
    // -----------------------------------------------------------------------

    private function fileDescFor(DirectoryEntry $oMeta): string
    {
        return ShareFsPacket::encodeFileDesc(
            $oMeta->getLoadAddr() ?? 0,
            $oMeta->getExecAddr() ?? 0,
            $oMeta->getSize(),
            RiscOsMeta::econetAccessToShareFsAttrs($oMeta->getAccess()),
            $oMeta->isDir() ? ShareFsPacket::TYPE_DIR : ShareFsPacket::TYPE_FILE
        );
    }

    // -----------------------------------------------------------------------
    // RFIND - stat only, no handle
    // -----------------------------------------------------------------------

    private function handleFind(ShareFsPacket $oRequest, string $sSrcAddress): void
    {
        $sPath = $this->resolveSharePath(ShareFsPacket::decodePath($oRequest->getBody()), AccessPlusHandler::addressToIp($sSrcAddress));
        $aAddress = $this->serviceAddress();

        try {
            $oMeta = Vfs::getMeta($aAddress['network'], $aAddress['station'], $sPath);
        } catch (Exception) {
            throw new ShareFsException(self::ENOENT, 'Not found');
        }

        $this->sendSuccess($oRequest->getRid(), $sSrcAddress, $this->fileDescFor($oMeta));
    }

    // -----------------------------------------------------------------------
    // ROPENIN / ROPENUP - open an existing file, allocate a handle
    // -----------------------------------------------------------------------

    private function handleOpen(ShareFsPacket $oRequest, string $sSrcAddress, bool $bReadOnly): void
    {
        $sClientIp = AccessPlusHandler::addressToIp($sSrcAddress);
        $sClientPath = ShareFsPacket::decodePath($oRequest->getBody());
        $sPath = $this->resolveSharePath($sClientPath, $sClientIp, !$bReadOnly);
        $aAddress = $this->serviceAddress();

        try {
            $oMeta = Vfs::getMeta($aAddress['network'], $aAddress['station'], $sPath);
        } catch (Exception) {
            throw new ShareFsException(self::ENOENT, 'Not found');
        }
        if ($oMeta->isDir()) {
            throw new ShareFsException(self::EINVAL, 'ROPENIN/ROPENUP on a directory - use ROPENDIR');
        }

        $oFd = Vfs::createFsHandle($aAddress['network'], $aAddress['station'], $sPath, true, $bReadOnly);
        $this->trackHandle($oFd->getID(), $sSrcAddress, self::shareNameOf($sClientPath));

        $this->sendSuccess($oRequest->getRid(), $sSrcAddress, $this->fileDescFor($oMeta) . pack('V', $oFd->getID()));
    }

    // -----------------------------------------------------------------------
    // ROPENDIR - allocate a directory handle (A-cmd variant: handle+token only,
    // entries are paged in separately via RREADDIR)
    // -----------------------------------------------------------------------

    private function handleOpenDir(ShareFsPacket $oRequest, string $sSrcAddress): void
    {
        $sClientPath = ShareFsPacket::decodePath($oRequest->getBody());
        $sPath = $this->resolveSharePath($sClientPath, AccessPlusHandler::addressToIp($sSrcAddress));
        $aAddress = $this->serviceAddress();

        try {
            $oFd = Vfs::createFsHandle($aAddress['network'], $aAddress['station'], $sPath, true, true, true);
        } catch (Exception) {
            throw new ShareFsException(self::ENOTDIR, 'Not a directory');
        }
        $this->trackHandle($oFd->getID(), $sSrcAddress, self::shareNameOf($sClientPath));

        // No server-side listing cache to invalidate, so the token is always 0.
        $this->sendSuccess($oRequest->getRid(), $sSrcAddress, pack('V', $oFd->getID()) . pack('V', 0));
    }

    // -----------------------------------------------------------------------
    // RCREATE - create+open a new file for writing
    // -----------------------------------------------------------------------

    private function handleCreate(ShareFsPacket $oRequest, string $sSrcAddress): void
    {
        $sClientIp = AccessPlusHandler::addressToIp($sSrcAddress);
        $sClientPath = ShareFsPacket::decodePath($oRequest->getBody());
        $sPath = $this->resolveSharePath($sClientPath, $sClientIp, true);
        $aAddress = $this->serviceAddress();

        $iFiletype = RiscOsMeta::filetypeFromSuffix($sClientPath) ?? RiscOsMeta::FILETYPE_DATA;
        $iCentiseconds = RiscOsMeta::unixTimeToCentiseconds(time());
        $iLoad = RiscOsMeta::makeLoadAddr($iFiletype, $iCentiseconds);
        $iExec = RiscOsMeta::makeExecAddr($iCentiseconds);

        Vfs::createFile($aAddress['network'], $aAddress['station'], $sPath, 0, $iLoad, $iExec);
        $oFd = Vfs::createFsHandle($aAddress['network'], $aAddress['station'], $sPath, true, false);
        $this->trackHandle($oFd->getID(), $sSrcAddress, self::shareNameOf($sClientPath));

        $sFileDesc = ShareFsPacket::encodeFileDesc($iLoad, $iExec, 0, 0x01 | 0x02 | 0x10, ShareFsPacket::TYPE_FILE);
        $this->sendSuccess($oRequest->getRid(), $sSrcAddress, $sFileDesc . pack('V', $oFd->getID()));
    }

    // -----------------------------------------------------------------------
    // RCREATEDIR
    // -----------------------------------------------------------------------

    private function handleCreateDir(ShareFsPacket $oRequest, string $sSrcAddress): void
    {
        $sClientPath = ShareFsPacket::decodePath($oRequest->getBody());
        $sPath = $this->resolveSharePath($sClientPath, AccessPlusHandler::addressToIp($sSrcAddress), true);
        $aAddress = $this->serviceAddress();

        Vfs::createDirectory($aAddress['network'], $aAddress['station'], $sPath);
        $oFd = Vfs::createFsHandle($aAddress['network'], $aAddress['station'], $sPath, true, true, true);
        $this->trackHandle($oFd->getID(), $sSrcAddress, self::shareNameOf($sClientPath));

        $oMeta = Vfs::getMeta($aAddress['network'], $aAddress['station'], $sPath);
        $this->sendSuccess($oRequest->getRid(), $sSrcAddress, $this->fileDescFor($oMeta) . pack('V', $oFd->getID()));
    }

    // -----------------------------------------------------------------------
    // RDELETE - reply carries the FileDesc of what was deleted
    // -----------------------------------------------------------------------

    private function handleDelete(ShareFsPacket $oRequest, string $sSrcAddress): void
    {
        $sPath = $this->resolveSharePath(ShareFsPacket::decodePath($oRequest->getBody()), AccessPlusHandler::addressToIp($sSrcAddress), true);
        $aAddress = $this->serviceAddress();

        try {
            $oMeta = Vfs::getMeta($aAddress['network'], $aAddress['station'], $sPath);
        } catch (Exception) {
            throw new ShareFsException(self::ENOENT, 'Not found');
        }
        $sFileDesc = $this->fileDescFor($oMeta);
        Vfs::deleteFile($aAddress['network'], $aAddress['station'], $sPath);

        $this->sendSuccess($oRequest->getRid(), $sSrcAddress, $sFileDesc);
    }

    // -----------------------------------------------------------------------
    // RACCESS - set attribute bits
    // -----------------------------------------------------------------------

    private function handleAccess(ShareFsPacket $oRequest, string $sSrcAddress): void
    {
        $aRequest = ShareFsPacket::decodeAccessRequest($oRequest->getBody());
        $sPath = $this->resolveSharePath($aRequest['path'], AccessPlusHandler::addressToIp($sSrcAddress), true);
        $aAddress = $this->serviceAddress();

        Vfs::setMeta($aAddress['network'], $aAddress['station'], $sPath, null, null, self::shareFsAttrsToEconetAccess($aRequest['attrs']));

        $oMeta = Vfs::getMeta($aAddress['network'], $aAddress['station'], $sPath);
        $this->sendSuccess($oRequest->getRid(), $sSrcAddress, $this->fileDescFor($oMeta));
    }

    /** Inverse of RiscOsMeta::econetAccessToShareFsAttrs() - see that method's docblock for why the two bit layouts don't correspond directly. */
    private static function shareFsAttrsToEconetAccess(int $iShareFsAttrs): int
    {
        $iAccess = 0;
        if (($iShareFsAttrs & 0x08) === 0) {
            $iAccess |= 8; // owner write, unless the client asked for Locked
        }
        if (($iShareFsAttrs & 0x08) !== 0) {
            $iAccess |= 16; // locked
        }
        if (($iShareFsAttrs & 0x10) !== 0) {
            $iAccess |= 1; // public read
        }
        if (($iShareFsAttrs & 0x20) !== 0) {
            $iAccess |= 2; // public write
        }
        return $iAccess;
    }

    // -----------------------------------------------------------------------
    // RFREESPACE - fake values, matching the same config the Econet fileserver reports
    // -----------------------------------------------------------------------

    private function handleFreeSpace(ShareFsPacket $oRequest, string $sSrcAddress): void
    {
        $iFree = config::getValueAsInt('vfs_default_disc_free');
        $iTotal = config::getValueAsInt('vfs_default_disc_size');
        $this->sendSuccess($oRequest->getRid(), $sSrcAddress, pack('V', $iFree) . pack('V', $iFree) . pack('V', $iTotal));
    }

    // -----------------------------------------------------------------------
    // RRENAME - two-step: arm here, new name delivered via a w/d exchange
    // -----------------------------------------------------------------------

    private function handleRenameArm(ShareFsPacket $oRequest, string $sSrcAddress): void
    {
        $aRequest = ShareFsPacket::decodeRenameArm($oRequest->getBody());
        $sClientIp = AccessPlusHandler::addressToIp($sSrcAddress);

        // Validate the old path resolves and is writable before arming - fail fast rather
        // than waiting for the new name to arrive.
        $this->resolveSharePath($aRequest['oldPath'], $sClientIp, true);

        if ($this->aPendingRename !== null) {
            throw new ShareFsException(self::EPERM, 'A rename is already in progress');
        }

        $this->aPendingRename = [
            'rid'           => $oRequest->getRid(),
            'client'        => $sSrcAddress,
            'address'       => $sSrcAddress,
            'oldSharePath'  => $aRequest['oldPath'],
            'newNameLength' => $aRequest['newNameLength'],
            'started'       => time(),
        ];

        $this->send(ShareFsPacket::encodeWriteRequest($oRequest->getRid(), 0, $aRequest['newNameLength']), $sSrcAddress);
    }

    /** @param array{rid:string, relPos:int, data:string} $aWriteData */
    private function handleWriteContinuation(array $aWriteData, string $sSrcAddress): void
    {
        if ($this->aPendingRename !== null && $this->aPendingRename['rid'] === $aWriteData['rid']) {
            $this->completeRename($aWriteData['data'], $sSrcAddress);
            return;
        }

        $aPending = $this->aPendingWrites[$aWriteData['rid']] ?? null;
        if ($aPending === null || $aPending['client'] !== $sSrcAddress) {
            return;
        }
        $this->continueWrite($aWriteData['rid'], $aWriteData['data'], $sSrcAddress);
    }

    private function completeRename(string $sNewName, string $sSrcAddress): void
    {
        $aPending = $this->aPendingRename;
        $this->aPendingRename = null;
        if ($aPending === null || $aPending['address'] !== $sSrcAddress) {
            return;
        }

        try {
            $sClientIp = AccessPlusHandler::addressToIp($sSrcAddress);
            $sOldFullPath = $this->resolveSharePath($aPending['oldSharePath'], $sClientIp, true);

            $sNewName = RiscOsMeta::filetypeFromSuffix($aPending['oldSharePath']) !== null && RiscOsMeta::filetypeFromSuffix($sNewName) === null
                ? RiscOsMeta::appendTypeSuffix($sNewName, (int) RiscOsMeta::filetypeFromSuffix($aPending['oldSharePath']))
                : $sNewName;

            $iLastDot = strrpos($aPending['oldSharePath'], '.');
            $sNewSharePath = $iLastDot === false ? $sNewName : substr($aPending['oldSharePath'], 0, $iLastDot + 1) . $sNewName;
            $sNewFullPath = $this->resolveSharePath($sNewSharePath, $sClientIp, true);

            $aAddress = $this->serviceAddress();
            Vfs::moveFile($aAddress['network'], $aAddress['station'], $sOldFullPath, $sNewFullPath);

            $this->sendSuccess($aPending['rid'], $sSrcAddress);
        } catch (ShareFsException $oShareFsException) {
            $this->sendError($aPending['rid'], $oShareFsException->getErrno(), $sSrcAddress);
        } catch (Exception) {
            $this->sendError($aPending['rid'], self::EIO, $sSrcAddress);
        }
    }

    // -----------------------------------------------------------------------
    // RCLOSE
    // -----------------------------------------------------------------------

    private function handleClose(ShareFsPacket $oRequest, string $sSrcAddress): void
    {
        $iHandle = ShareFsPacket::decodeHandle($oRequest->getBody());
        if (($this->aHandleOwners[$iHandle] ?? null) === $sSrcAddress) {
            $aAddress = $this->serviceAddress();
            Vfs::closeFsHandle($aAddress['network'], $aAddress['station'], $iHandle);
            unset($this->aHandleOwners[$iHandle], $this->aHandleShares[$iHandle]);
        }
        $this->sendSuccess($oRequest->getRid(), $sSrcAddress);
    }

    // -----------------------------------------------------------------------
    // RREAD - streaming D/r ping-pong
    // -----------------------------------------------------------------------

    private function handleReadStart(ShareFsPacket $oRequest, string $sSrcAddress): void
    {
        $aRequest = ShareFsPacket::decodeHandleOffsetAmount($oRequest->getBody());
        $oFd = $this->requireOwnedHandle($aRequest['handle'], $sSrcAddress);

        $iOffset = $aRequest['offset'] === 0xFFFFFFFF ? ($oFd->fsFTell() ?? 0) : $aRequest['offset'];
        if (!is_int($iOffset)) {
            $iOffset = 0;
        }
        $oFd->setPos($iOffset);

        $sRid = $oRequest->getRid();
        $this->aPendingReads[$sRid] = [
            'handle' => $aRequest['handle'], 'client' => $sSrcAddress, 'address' => $sSrcAddress,
            'start' => $iOffset, 'pos' => $iOffset, 'end' => $iOffset + $aRequest['amount'], 'started' => time(),
        ];

        $this->sendReadChunk($sRid, $sSrcAddress);
    }

    private function handleReadAck(string $sRid, string $sSrcAddress): void
    {
        $aPending = $this->aPendingReads[$sRid] ?? null;
        if ($aPending === null || $aPending['client'] !== $sSrcAddress) {
            return;
        }
        $this->sendReadChunk($sRid, $sSrcAddress);
    }

    private function sendReadChunk(string $sRid, string $sSrcAddress): void
    {
        $aPending = $this->aPendingReads[$sRid];
        $aAddress = $this->serviceAddress();

        try {
            $oFd = Vfs::getFsHandle($aAddress['network'], $aAddress['station'], $aPending['handle']);
        } catch (Exception) {
            unset($this->aPendingReads[$sRid]);
            $this->sendError($sRid, self::EBADF, $sSrcAddress);
            return;
        }

        $iChunk = min($aPending['end'] - $aPending['pos'], ShareFsPacket::CHUNK_SIZE);
        $mData = $iChunk > 0 ? $oFd->read($iChunk) : '';
        $sData = is_string($mData) ? $mData : '';

        // Offsets on the wire are relative to the read's own starting position, not absolute.
        $this->send(ShareFsPacket::encodeReadData($sRid, $aPending['pos'] - $aPending['start'], $sData), $sSrcAddress);

        $aPending['pos'] += strlen($sData);
        $this->aPendingReads[$sRid] = $aPending;

        if ($aPending['pos'] >= $aPending['end'] || strlen($sData) === 0) {
            $iSent = $aPending['pos'] - $aPending['start'];
            $this->sendSuccess($sRid, $sSrcAddress, pack('V', $iSent) . pack('V', $aPending['pos']));
            unset($this->aPendingReads[$sRid]);
        }
    }

    // -----------------------------------------------------------------------
    // RWRITE - streaming w/d ping-pong
    // -----------------------------------------------------------------------

    private function handleWriteStart(ShareFsPacket $oRequest, string $sSrcAddress): void
    {
        $aRequest = ShareFsPacket::decodeHandleOffsetAmount($oRequest->getBody());
        $oFd = $this->requireWritableHandle($aRequest['handle'], $sSrcAddress);
        $oFd->setPos($aRequest['offset']);

        if ($aRequest['amount'] === 0) {
            $this->sendSuccess($oRequest->getRid(), $sSrcAddress);
            return;
        }

        $sRid = $oRequest->getRid();
        $this->aPendingWrites[$sRid] = [
            'handle' => $aRequest['handle'], 'client' => $sSrcAddress, 'address' => $sSrcAddress,
            'start' => $aRequest['offset'], 'pos' => $aRequest['offset'], 'end' => $aRequest['offset'] + $aRequest['amount'], 'started' => time(),
        ];

        $iChunk = min($aRequest['amount'], ShareFsPacket::CHUNK_SIZE);
        $this->send(ShareFsPacket::encodeWriteRequest($sRid, 0, $iChunk), $sSrcAddress);
    }

    private function continueWrite(string $sRid, string $sData, string $sSrcAddress): void
    {
        $aPending = $this->aPendingWrites[$sRid];
        $aAddress = $this->serviceAddress();

        try {
            $oFd = Vfs::getFsHandle($aAddress['network'], $aAddress['station'], $aPending['handle']);
            $oFd->setPos($aPending['pos']);
            $oFd->write($sData);
        } catch (Exception) {
            unset($this->aPendingWrites[$sRid]);
            $this->sendError($sRid, self::EIO, $sSrcAddress);
            return;
        }

        $aPending['pos'] += strlen($sData);
        $this->aPendingWrites[$sRid] = $aPending;

        if ($aPending['pos'] >= $aPending['end'] || $sData === '') {
            unset($this->aPendingWrites[$sRid]);
            $this->sendSuccess($sRid, $sSrcAddress);
            return;
        }

        // Positions sent to the client are relative to the write's own starting position.
        $iChunk = min($aPending['end'] - $aPending['pos'], ShareFsPacket::CHUNK_SIZE);
        $this->send(ShareFsPacket::encodeWriteRequest($sRid, $aPending['pos'] - $aPending['start'], $iChunk), $sSrcAddress);
    }

    // -----------------------------------------------------------------------
    // RREADDIR - paginated S+B catalogue
    // -----------------------------------------------------------------------

    private function handleReadDir(ShareFsPacket $oRequest, string $sSrcAddress): void
    {
        $aRequest = ShareFsPacket::decodeReaddirRequest($oRequest->getBody());
        $oFd = $this->requireOwnedHandle($aRequest['handle'], $sSrcAddress);
        if (!$oFd->isDir()) {
            throw new ShareFsException(self::ENOTDIR, 'Handle is not a directory');
        }

        $aListing = Vfs::getDirectoryListing($oFd);
        ksort($aListing, SORT_STRING | SORT_FLAG_CASE);
        $aNames = array_keys($aListing);
        $iTotal = count($aNames);

        $sBlob = '';
        $i = $aRequest['startEntry'];
        while ($i < $iTotal) {
            $oEntry = $aListing[$aNames[$i]];
            $aRow = [
                'name'   => $oEntry->getEconetName(),
                'load'   => $oEntry->getLoadAddr() ?? 0,
                'exec'   => $oEntry->getExecAddr() ?? 0,
                'length' => $oEntry->getSize(),
                'attrs'  => RiscOsMeta::econetAccessToShareFsAttrs($oEntry->getAccess()),
                'type'   => $oEntry->isDir() ? ShareFsPacket::TYPE_DIR : ShareFsPacket::TYPE_FILE,
            ];
            $sCandidate = $sBlob . ShareFsPacket::encodeDirEntries([$aRow]);
            if (strlen($sCandidate) > self::READDIR_PAGE_BUDGET && $sBlob !== '') {
                break;
            }
            $sBlob = $sCandidate;
            $i++;
        }

        $this->send(ShareFsPacket::encodeReaddirPage($oRequest->getRid(), $sBlob), $sSrcAddress);
    }

    // -----------------------------------------------------------------------
    // RENSURE / RSETLENGTH / RZERO
    //
    // Vfs/FileDescriptor expose read()/write()/setPos() but no truncate primitive, so these
    // can only grow a file (by writing zero bytes out to the target length) - shrinking a
    // file is not supported and returns ENOSYS. See docs/protocols/sharefs.md.
    // -----------------------------------------------------------------------

    private function handleEnsure(ShareFsPacket $oRequest, string $sSrcAddress): void
    {
        $aRequest = ShareFsPacket::decodeHandleAndValue($oRequest->getBody());
        $this->growFileTo($aRequest['handle'], $aRequest['value'], $sSrcAddress);
        $this->sendSuccess($oRequest->getRid(), $sSrcAddress, pack('V', $aRequest['value']));
    }

    private function handleSetLength(ShareFsPacket $oRequest, string $sSrcAddress): void
    {
        $aRequest = ShareFsPacket::decodeHandleAndValue($oRequest->getBody());
        $oFd = $this->requireWritableHandle($aRequest['handle'], $sSrcAddress);
        $iCurrentLength = $this->currentHandleLength($oFd);
        if ($aRequest['value'] < $iCurrentLength) {
            throw new ShareFsException(self::ENOSYS, 'Shrinking a file is not supported');
        }
        $this->growFileTo($aRequest['handle'], $aRequest['value'], $sSrcAddress);
        $this->sendSuccess($oRequest->getRid(), $sSrcAddress, pack('V', $aRequest['value']));
    }

    private function handleZero(ShareFsPacket $oRequest, string $sSrcAddress): void
    {
        $aRequest = ShareFsPacket::decodeHandleOffsetAmount($oRequest->getBody());
        $oFd = $this->requireWritableHandle($aRequest['handle'], $sSrcAddress);
        $oFd->setPos($aRequest['offset']);
        $oFd->write(str_repeat("\x00", $aRequest['amount']));

        $this->sendSuccess($oRequest->getRid(), $sSrcAddress, pack('V', $aRequest['offset'] + $aRequest['amount']));
    }

    private function growFileTo(int $iHandle, int $iTargetLength, string $sSrcAddress): void
    {
        $oFd = $this->requireWritableHandle($iHandle, $sSrcAddress);
        $iCurrentLength = $this->currentHandleLength($oFd);
        if ($iTargetLength <= $iCurrentLength) {
            return;
        }
        $oFd->setPos($iCurrentLength);
        $oFd->write(str_repeat("\x00", $iTargetLength - $iCurrentLength));
    }

    private function currentHandleLength(FileDescriptor $oFd): int
    {
        $aAddress = $this->serviceAddress();
        try {
            return Vfs::getMeta($aAddress['network'], $aAddress['station'], $oFd->getEconetPath())->getSize();
        } catch (Exception) {
            return 0;
        }
    }

    // -----------------------------------------------------------------------
    // RSETINFO - set load/exec, preserving a ",xxx" filetype suffix via rename
    // -----------------------------------------------------------------------

    private function handleSetInfo(ShareFsPacket $oRequest, string $sSrcAddress): void
    {
        $aRequest = ShareFsPacket::decodeSetInfoRequest($oRequest->getBody());
        $oFd = $this->requireWritableHandle($aRequest['handle'], $sSrcAddress);
        $aAddress = $this->serviceAddress();

        $sPath = $oFd->getEconetPath();
        $iFiletype = RiscOsMeta::filetypeFromLoadAddr($aRequest['load']);
        $sDirName = $oFd->getEconetDirName() ?? '';
        $sNewName = RiscOsMeta::appendTypeSuffix($sDirName, $iFiletype);
        if ($sNewName !== $sDirName) {
            $sNewPath = $oFd->getEconetParentPath() . '.' . $sNewName;
            Vfs::moveFile($aAddress['network'], $aAddress['station'], $sPath, $sNewPath);
            $sPath = $sNewPath;
        }

        Vfs::setMeta($aAddress['network'], $aAddress['station'], $sPath, $aRequest['load'], $aRequest['exec'], null);

        $oMeta = Vfs::getMeta($aAddress['network'], $aAddress['station'], $sPath);
        $this->sendSuccess($oRequest->getRid(), $sSrcAddress, $this->fileDescFor($oMeta));
    }

    // -----------------------------------------------------------------------
    // RGETSEQPTR / RSETSEQPTR
    // -----------------------------------------------------------------------

    private function handleGetSeqPtr(ShareFsPacket $oRequest, string $sSrcAddress): void
    {
        $iHandle = ShareFsPacket::decodeHandle($oRequest->getBody());
        $oFd = $this->requireOwnedHandle($iHandle, $sSrcAddress);
        $mPos = $oFd->fsFTell();
        $this->sendSuccess($oRequest->getRid(), $sSrcAddress, pack('V', is_int($mPos) ? $mPos : 0));
    }

    private function handleSetSeqPtr(ShareFsPacket $oRequest, string $sSrcAddress): void
    {
        $aRequest = ShareFsPacket::decodeHandleAndValue($oRequest->getBody());
        $oFd = $this->requireOwnedHandle($aRequest['handle'], $sSrcAddress);
        $oFd->setPos($aRequest['value']);
        $this->sendSuccess($oRequest->getRid(), $sSrcAddress, pack('V', $aRequest['value']));
    }

    // -----------------------------------------------------------------------
    // RDEADHANDLES - always reports an empty list; see class docblock
    // -----------------------------------------------------------------------

    private function handleDeadHandles(ShareFsPacket $oRequest, string $sSrcAddress): void
    {
        $this->sendSuccess($oRequest->getRid(), $sSrcAddress, pack('V', 0));
    }
}
