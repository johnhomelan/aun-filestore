<?php

namespace HomeLan\FileStore\Vfs;

/**
 * CP/M compatibility layer over the standard Vfs.
 *
 * Translates between CP/M paths (using '\' as the directory separator and a
 * leading '\' for absolute paths) and Acorn paths (using '.' as the directory
 * separator and '$' as the root prefix).  All Unix filesystem path resolution
 * remains the exclusive responsibility of the parent Vfs and its plugins —
 * this class works only with Acorn-style paths.
 *
 * Inbound  (CP/M → Acorn): toAcornPath()  '\Library\bin' → '$.Library.bin'
 * Outbound (Acorn → CP/M): toCpmPath()    '$.Library.bin' → '\Library\bin'
 *
 * getDirectoryListing() wraps each returned DirectoryEntry in a
 * CpmDirectoryEntry so the CP/M handler receives entry names already
 * translated to CP/M conventions.  Higher-level differences (drive letters,
 * 8.3 name formatting, etc.) remain the responsibility of the CP/M protocol
 * handler.
 *
 * @package corevfs
 * @author John Brown <john@home-lan.co.uk>
 */
class CpmVfs extends Vfs
{
    /**
     * Translate a CP/M directory path to an Acorn path.
     *
     * '\' (CP/M directory separator) → '.' (Acorn directory separator).
     * A leading '\' denotes a CP/M absolute path; after replacement it would
     * become a leading '.' which is mapped to Acorn's '$' root prefix so that
     * the parent Vfs can resolve it correctly.
     *
     * Examples:
     *   '\Library\bin' → '$.Library.bin'
     *   'Library\bin'  → 'Library.bin'
     */
    private static function toAcornPath(string $sCpmPath): string
    {
        $sResult = str_replace('\\', '.', $sCpmPath);
        if (str_starts_with($sResult, '.')) {
            $sResult = '$' . $sResult;
        }
        return $sResult;
    }

    /**
     * Translate a CP/M file path to an Acorn path, correctly handling the
     * filename extension separator.
     *
     * The path is split at the last '\' to separate the directory portion from
     * the filename.  The directory uses '\' as separator (→ '.'), while the
     * filename uses '.' as the CP/M extension separator which must become '\' in
     * the Acorn filename (the convention used by CpmDirectoryEntry::getCpmName).
     *
     * Examples:
     *   '\TorchDrives\E\MYPROG.COM' → '$.TorchDrives.E.MYPROG\COM'
     *   '\TorchDrives\E\AUTOEXEC'   → '$.TorchDrives.E.AUTOEXEC'
     */
    private static function toAcornFilePath(string $sCpmPath): string
    {
        $iLastSlash = strrpos($sCpmPath, '\\');
        if ($iLastSlash === false) {
            return str_replace('.', '\\', $sCpmPath);
        }
        $sDirPart  = substr($sCpmPath, 0, $iLastSlash);
        $sFilePart = substr($sCpmPath, $iLastSlash + 1);
        $sAcornDir = str_replace('\\', '.', $sDirPart);
        if (str_starts_with($sAcornDir, '.')) {
            $sAcornDir = '$' . $sAcornDir;
        }
        return $sAcornDir . '.' . str_replace('.', '\\', $sFilePart);
    }

    /**
     * Translate an Acorn path back to a CP/M path.
     *
     * '.' (Acorn directory separator) → '\' (CP/M directory separator).
     * The Acorn '$' root prefix is stripped; the '.' that follows it becomes
     * the leading '\' that signals an absolute path in CP/M.  '$' alone
     * (the root directory itself) returns '\'.
     *
     * Examples:
     *   '$.Library.bin' → '\Library\bin'
     *   'Library.bin'   → 'Library\bin'
     *   '$'             → '\'
     */
    public static function toCpmPath(string $sAcornPath): string
    {
        if (str_starts_with($sAcornPath, '$')) {
            $sAcornPath = substr($sAcornPath, 1);
        }
        $sResult = str_replace('.', '\\', $sAcornPath);
        return $sResult !== '' ? $sResult : '\\';
    }

    public static function createDirectory(int $iNetwork, int $iStation, string $sEconetPath): void
    {
        parent::createDirectory($iNetwork, $iStation, self::toAcornPath($sEconetPath));
    }

    public static function deleteFile(int $iNetwork, int $iStation, string $sEconetPath): void
    {
        parent::deleteFile($iNetwork, $iStation, self::toAcornPath($sEconetPath));
    }

    /**
     * Delete a file identified by a CP/M file path (directory separators '\',
     * extension separator '.').  Translates to the correct Acorn path before
     * delegating to Vfs::deleteFile().
     */
    public static function deleteFileCpm(int $iNetwork, int $iStation, string $sCpmFilePath): void
    {
        parent::deleteFile($iNetwork, $iStation, self::toAcornFilePath($sCpmFilePath));
    }

    public static function moveFile(int $iNetwork, int $iStation, string $sEconetPathFrom, string $sEconetPathTo): void
    {
        parent::moveFile($iNetwork, $iStation, self::toAcornPath($sEconetPathFrom), self::toAcornPath($sEconetPathTo));
    }

    /**
     * Rename/move a file identified by CP/M file paths.  Translates both paths
     * to Acorn form before delegating to Vfs::moveFile().
     */
    public static function moveFileCpm(int $iNetwork, int $iStation, string $sCpmFrom, string $sCpmTo): void
    {
        parent::moveFile($iNetwork, $iStation, self::toAcornFilePath($sCpmFrom), self::toAcornFilePath($sCpmTo));
    }

    public static function saveFile(int $iNetwork, int $iStation, string $sEconetPath, string $sData, int $iLoadAddr, int $iExecAddr): void
    {
        parent::saveFile($iNetwork, $iStation, self::toAcornPath($sEconetPath), $sData, $iLoadAddr, $iExecAddr);
    }

    public static function createFile(int $iNetwork, int $iStation, string $sEconetPath, int $iSize, int $iLoadAddr, int $iExecAddr): void
    {
        parent::createFile($iNetwork, $iStation, self::toAcornPath($sEconetPath), $iSize, $iLoadAddr, $iExecAddr);
    }

    public static function getFile(int $iNetwork, int $iStation, string $sEconetPath)
    {
        return parent::getFile($iNetwork, $iStation, self::toAcornPath($sEconetPath));
    }

    public static function getMeta(int $iNetwork, int $iStation, string $sEconetPath)
    {
        return parent::getMeta($iNetwork, $iStation, self::toAcornPath($sEconetPath));
    }

    public static function setMeta(int $iNetwork, int $iStation, string $sEconetPath, ?int $iLoad, ?int $iExec, ?int $iAccess): void
    {
        parent::setMeta($iNetwork, $iStation, self::toAcornPath($sEconetPath), $iLoad, $iExec, $iAccess);
    }

    public static function createFsHandle(int $iNetwork, int $iStation, string $sEconetPath, bool $bMustExist = true, bool $bReadOnly = true)
    {
        return parent::createFsHandle($iNetwork, $iStation, self::toAcornPath($sEconetPath), $bMustExist, $bReadOnly);
    }

    /**
     * Open a file identified by a CP/M file path (directory separators '\',
     * extension separator '.').  Translates to the correct Acorn path before
     * delegating to Vfs::createFsHandle(), preserving the '\' extension
     * separator in the stored Acorn filename.
     */
    public static function createFsHandleForFile(int $iNetwork, int $iStation, string $sCpmFilePath, bool $bMustExist = true, bool $bReadOnly = true)
    {
        return parent::createFsHandle($iNetwork, $iStation, self::toAcornFilePath($sCpmFilePath), $bMustExist, $bReadOnly);
    }

    public static function getSin(string $sCpmPath): int
    {
        return parent::getSin(self::toAcornPath($sCpmPath));
    }

    public static function getSinForFile(string $sCpmFilePath): int
    {
        return parent::getSin(self::toAcornFilePath($sCpmFilePath));
    }

    /**
     * Return the directory listing with each entry wrapped in a
     * CpmDirectoryEntry so that name separators are presented in CP/M style.
     *
     * The file handle passed in was already created against an Acorn path
     * (via createFsHandle above), so no path translation is needed here —
     * only the outbound names require conversion.
     *
     * @return CpmDirectoryEntry[]
     */
    public static function getDirectoryListing(object $oFd): array
    {
        return array_map(
            static fn(DirectoryEntry $oEntry) => CpmDirectoryEntry::wrap($oEntry),
            parent::getDirectoryListing($oFd)
        );
    }
}
