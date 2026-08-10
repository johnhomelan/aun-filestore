<?php

/**
 * Configurable spy VFS plugins for unit testing Vfs:: methods.
 *
 * Defined in the HomeLan\FileStore\Vfs\Plugin namespace so that Vfs::getVfsPlugins()
 * resolves them when passed as short names (e.g. 'SpyVfsPlugin').
 *
 * Each method checks a static nullable callable; if set the callable is invoked and
 * its return value is returned, otherwise a sensible default is used.  Every call is
 * appended to the static $aCallLog array so tests can assert that specific operations
 * reached the plugin.
 *
 * Two independent spy classes (SpyVfsPlugin, SpyVfsPlugin2) are provided so that
 * plugin-chain fall-through can be tested with both plugins active.
 */

namespace HomeLan\FileStore\Vfs\Plugin;

use HomeLan\FileStore\Vfs\FileDescriptor;
use HomeLan\FileStore\Vfs\FilePath;
use HomeLan\FileStore\Authentication\User;
use Psr\Log\LoggerInterface;

// ---------------------------------------------------------------------------
// SpyVfsPlugin
// ---------------------------------------------------------------------------

class SpyVfsPlugin implements PluginInterface
{
    // Configurable callbacks — set from tests to control return values/exceptions.
    // Note: `callable` is not a valid PHP property type; use mixed/null.
    static public mixed $fnBuildFd          = null;
    static public mixed $fnGetDirListing    = null;
    static public mixed $fnCreateDirectory  = null;
    static public mixed $fnDeleteFile       = null;
    static public mixed $fnMoveFile         = null;
    static public mixed $fnSaveFile         = null;
    static public mixed $fnCreateFile       = null;
    static public mixed $fnGetFile          = null;
    static public mixed $fnSetMeta          = null;
    static public mixed $fnFsFtell          = null;
    static public mixed $fnFsFStat          = null;
    static public mixed $fnIsEof            = null;
    static public mixed $fnSetPos           = null;
    static public mixed $fnRead             = null;
    static public mixed $fnWrite            = null;
    static public mixed $fnFsClose          = null;
    static public mixed $fnHouseKeeping     = null;

    /** Every call is recorded: ['method' => string, 'args' => array] */
    static public array $aCallLog = [];

    static public function reset(): void
    {
        self::$fnBuildFd         = null;
        self::$fnGetDirListing   = null;
        self::$fnCreateDirectory = null;
        self::$fnDeleteFile      = null;
        self::$fnMoveFile        = null;
        self::$fnSaveFile        = null;
        self::$fnCreateFile      = null;
        self::$fnGetFile         = null;
        self::$fnSetMeta         = null;
        self::$fnFsFtell         = null;
        self::$fnFsFStat         = null;
        self::$fnIsEof           = null;
        self::$fnSetPos          = null;
        self::$fnRead            = null;
        self::$fnWrite           = null;
        self::$fnFsClose         = null;
        self::$fnHouseKeeping    = null;
        self::$aCallLog          = [];
    }

    static public function init(LoggerInterface $oLogger, bool $bMultiuser = false): void {}

    static public function houseKeeping(): void
    {
        self::$aCallLog[] = ['method' => 'houseKeeping', 'args' => []];
        if (self::$fnHouseKeeping !== null) {
            (self::$fnHouseKeeping)();
        }
    }

    static public function _buildFiledescriptorFromEconetPath($oUser, FilePath $oEconetPath, $bMustExist, $bReadOnly): ?FileDescriptor
    {
        self::$aCallLog[] = ['method' => '_buildFiledescriptorFromEconetPath', 'args' => [$oEconetPath->getFilePath(), $bMustExist, $bReadOnly]];
        if (self::$fnBuildFd !== null) {
            return (self::$fnBuildFd)($oUser, $oEconetPath, $bMustExist, $bReadOnly);
        }
        return null;
    }

    static public function _getAccessMode($iGid, $iUid, $iMode): int { return 0; }

    static public function getDirectoryListing(string $sEconetPath, array $aDirectoryListing): array
    {
        self::$aCallLog[] = ['method' => 'getDirectoryListing', 'args' => [$sEconetPath]];
        if (self::$fnGetDirListing !== null) {
            return (self::$fnGetDirListing)($sEconetPath, $aDirectoryListing);
        }
        return $aDirectoryListing;
    }

    static public function createDirectory($oUser, FilePath $oPath): bool
    {
        self::$aCallLog[] = ['method' => 'createDirectory', 'args' => [$oPath->getFilePath()]];
        if (self::$fnCreateDirectory !== null) {
            return (self::$fnCreateDirectory)($oUser, $oPath);
        }
        return false;
    }

    static public function deleteFile($oUser, FilePath $oEconetPath): bool
    {
        self::$aCallLog[] = ['method' => 'deleteFile', 'args' => [$oEconetPath->getFilePath()]];
        if (self::$fnDeleteFile !== null) {
            return (self::$fnDeleteFile)($oUser, $oEconetPath);
        }
        return false;
    }

    static public function moveFile($oUser, FilePath $oFrom, FilePath $oTo): bool
    {
        self::$aCallLog[] = ['method' => 'moveFile', 'args' => [$oFrom->getFilePath(), $oTo->getFilePath()]];
        if (self::$fnMoveFile !== null) {
            return (self::$fnMoveFile)($oUser, $oFrom, $oTo);
        }
        return false;
    }

    static public function saveFile($oUser, FilePath $oPath, string $sData, int $iLoadAddr, int $iExecAddr): bool
    {
        self::$aCallLog[] = ['method' => 'saveFile', 'args' => [$oPath->getFilePath(), $sData, $iLoadAddr, $iExecAddr]];
        if (self::$fnSaveFile !== null) {
            return (self::$fnSaveFile)($oUser, $oPath, $sData, $iLoadAddr, $iExecAddr);
        }
        return false;
    }

    static public function createFile($oUser, FilePath $oPath, int $iSize, int $iLoadAddr, int $iExecAddr): bool
    {
        self::$aCallLog[] = ['method' => 'createFile', 'args' => [$oPath->getFilePath(), $iSize, $iLoadAddr, $iExecAddr]];
        if (self::$fnCreateFile !== null) {
            return (self::$fnCreateFile)($oUser, $oPath, $iSize, $iLoadAddr, $iExecAddr);
        }
        return false;
    }

    static public function getFile($oUser, FilePath $oEconetPath): string
    {
        self::$aCallLog[] = ['method' => 'getFile', 'args' => [$oEconetPath->getFilePath()]];
        if (self::$fnGetFile !== null) {
            return (self::$fnGetFile)($oUser, $oEconetPath);
        }
        return '';
    }

    static public function setMeta(string $sEconetPath, int $iLoad, int $iExec, int $iAccess): void
    {
        self::$aCallLog[] = ['method' => 'setMeta', 'args' => [$sEconetPath, $iLoad, $iExec, $iAccess]];
        if (self::$fnSetMeta !== null) {
            (self::$fnSetMeta)($sEconetPath, $iLoad, $iExec, $iAccess);
        }
    }

    static public function fsFtell($oUser, $fLocalHandle): int
    {
        self::$aCallLog[] = ['method' => 'fsFtell', 'args' => []];
        if (self::$fnFsFtell !== null) {
            return (self::$fnFsFtell)($oUser, $fLocalHandle);
        }
        return 0;
    }

    static public function fsFStat($oUser, $fLocalHandle): array
    {
        self::$aCallLog[] = ['method' => 'fsFStat', 'args' => []];
        if (self::$fnFsFStat !== null) {
            return (self::$fnFsFStat)($oUser, $fLocalHandle);
        }
        return [];
    }

    static public function isEof($oUser, $fLocalHandle): bool
    {
        self::$aCallLog[] = ['method' => 'isEof', 'args' => []];
        if (self::$fnIsEof !== null) {
            return (self::$fnIsEof)($oUser, $fLocalHandle);
        }
        return false;
    }

    static public function setPos($oUser, $fLocalHandle, $iPos): void
    {
        self::$aCallLog[] = ['method' => 'setPos', 'args' => [$iPos]];
        if (self::$fnSetPos !== null) {
            (self::$fnSetPos)($oUser, $fLocalHandle, $iPos);
        }
    }

    static public function setExt($oUser, $fLocalHandle, int $iExt): void {}

    static public function read($oUser, $fLocalHandle, $iLength): string
    {
        self::$aCallLog[] = ['method' => 'read', 'args' => [$iLength]];
        if (self::$fnRead !== null) {
            return (self::$fnRead)($oUser, $fLocalHandle, $iLength);
        }
        return '';
    }

    static public function write($oUser, $fLocalHandle, $sData): int
    {
        self::$aCallLog[] = ['method' => 'write', 'args' => [$sData]];
        if (self::$fnWrite !== null) {
            return (self::$fnWrite)($oUser, $fLocalHandle, $sData);
        }
        return strlen((string) $sData);
    }

    static public function fsLock($oUser, $fLocalHandle, bool $bExclusive): void {}
    static public function fsUnlock($oUser, $fLocalHandle): void {}

    static public function fsClose($oUser, $fLocalHandle): void
    {
        self::$aCallLog[] = ['method' => 'fsClose', 'args' => [$fLocalHandle]];
        if (self::$fnFsClose !== null) {
            (self::$fnFsClose)($oUser, $fLocalHandle);
        }
    }
}

// ---------------------------------------------------------------------------
// SpyVfsPlugin2 — independent second plugin for chain / fall-through tests
// ---------------------------------------------------------------------------

class SpyVfsPlugin2 implements PluginInterface
{
    static public mixed $fnBuildFd         = null;
    static public mixed $fnGetDirListing   = null;
    static public mixed $fnCreateDirectory = null;
    static public mixed $fnDeleteFile      = null;
    static public mixed $fnMoveFile        = null;
    static public mixed $fnSaveFile        = null;
    static public mixed $fnCreateFile      = null;
    static public mixed $fnGetFile         = null;
    static public mixed $fnSetMeta         = null;

    static public array $aCallLog = [];

    static public function reset(): void
    {
        self::$fnBuildFd         = null;
        self::$fnGetDirListing   = null;
        self::$fnCreateDirectory = null;
        self::$fnDeleteFile      = null;
        self::$fnMoveFile        = null;
        self::$fnSaveFile        = null;
        self::$fnCreateFile      = null;
        self::$fnGetFile         = null;
        self::$fnSetMeta         = null;
        self::$aCallLog          = [];
    }

    static public function init(LoggerInterface $oLogger, bool $bMultiuser = false): void {}
    static public function houseKeeping(): void
    {
        self::$aCallLog[] = ['method' => 'houseKeeping', 'args' => []];
    }

    static public function _buildFiledescriptorFromEconetPath($oUser, FilePath $oEconetPath, $bMustExist, $bReadOnly): ?FileDescriptor
    {
        self::$aCallLog[] = ['method' => '_buildFiledescriptorFromEconetPath', 'args' => [$oEconetPath->getFilePath()]];
        if (self::$fnBuildFd !== null) {
            return (self::$fnBuildFd)($oUser, $oEconetPath, $bMustExist, $bReadOnly);
        }
        return null;
    }

    static public function _getAccessMode($iGid, $iUid, $iMode): int { return 0; }

    static public function getDirectoryListing(string $sEconetPath, array $aDirectoryListing): array
    {
        self::$aCallLog[] = ['method' => 'getDirectoryListing', 'args' => [$sEconetPath]];
        if (self::$fnGetDirListing !== null) {
            return (self::$fnGetDirListing)($sEconetPath, $aDirectoryListing);
        }
        return $aDirectoryListing;
    }

    static public function createDirectory($oUser, FilePath $oPath): bool
    {
        self::$aCallLog[] = ['method' => 'createDirectory', 'args' => [$oPath->getFilePath()]];
        if (self::$fnCreateDirectory !== null) {
            return (self::$fnCreateDirectory)($oUser, $oPath);
        }
        return false;
    }

    static public function deleteFile($oUser, FilePath $oEconetPath): bool
    {
        self::$aCallLog[] = ['method' => 'deleteFile', 'args' => [$oEconetPath->getFilePath()]];
        if (self::$fnDeleteFile !== null) {
            return (self::$fnDeleteFile)($oUser, $oEconetPath);
        }
        return false;
    }

    static public function moveFile($oUser, FilePath $oFrom, FilePath $oTo): bool
    {
        self::$aCallLog[] = ['method' => 'moveFile', 'args' => [$oFrom->getFilePath(), $oTo->getFilePath()]];
        if (self::$fnMoveFile !== null) {
            return (self::$fnMoveFile)($oUser, $oFrom, $oTo);
        }
        return false;
    }

    static public function saveFile($oUser, FilePath $oPath, string $sData, int $iLoadAddr, int $iExecAddr): bool
    {
        self::$aCallLog[] = ['method' => 'saveFile', 'args' => [$oPath->getFilePath()]];
        if (self::$fnSaveFile !== null) {
            return (self::$fnSaveFile)($oUser, $oPath, $sData, $iLoadAddr, $iExecAddr);
        }
        return false;
    }

    static public function createFile($oUser, FilePath $oPath, int $iSize, int $iLoadAddr, int $iExecAddr): bool
    {
        self::$aCallLog[] = ['method' => 'createFile', 'args' => [$oPath->getFilePath()]];
        if (self::$fnCreateFile !== null) {
            return (self::$fnCreateFile)($oUser, $oPath, $iSize, $iLoadAddr, $iExecAddr);
        }
        return false;
    }

    static public function getFile($oUser, FilePath $oEconetPath): string
    {
        self::$aCallLog[] = ['method' => 'getFile', 'args' => [$oEconetPath->getFilePath()]];
        if (self::$fnGetFile !== null) {
            return (self::$fnGetFile)($oUser, $oEconetPath);
        }
        return '';
    }

    static public function setMeta(string $sEconetPath, int $iLoad, int $iExec, int $iAccess): void
    {
        self::$aCallLog[] = ['method' => 'setMeta', 'args' => [$sEconetPath]];
        if (self::$fnSetMeta !== null) {
            (self::$fnSetMeta)($sEconetPath, $iLoad, $iExec, $iAccess);
        }
    }

    static public function fsFtell($oUser, $fLocalHandle): int    { return 0; }
    static public function fsFStat($oUser, $fLocalHandle): array  { return []; }
    static public function isEof($oUser, $fLocalHandle): bool     { return false; }
    static public function setPos($oUser, $fLocalHandle, $iPos): void {}
    static public function setExt($oUser, $fLocalHandle, int $iExt): void {}
    static public function read($oUser, $fLocalHandle, $iLength): string { return ''; }
    static public function write($oUser, $fLocalHandle, $sData): int     { return 0; }
    static public function fsLock($oUser, $fLocalHandle, bool $bExclusive): void {}
    static public function fsUnlock($oUser, $fLocalHandle): void {}
    static public function fsClose($oUser, $fLocalHandle): void
    {
        self::$aCallLog[] = ['method' => 'fsClose', 'args' => []];
    }
}
