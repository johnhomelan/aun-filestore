<?php

namespace HomeLan\FileStore\ShareFs;

/**
 * RISC OS filetype/timestamp/attribute conversions used by the ShareFS data protocol.
 *
 * Verified against andrewtimmins/riscos-access-server's src/riscos.c and src/names.c - see
 * docs/protocols/sharefs.md for the wider context.
 */
class RiscOsMeta
{
    /** Seconds between the RISC OS epoch (1900-01-01) and the Unix epoch (1970-01-01). */
    public const int EPOCH_DIFF_SECONDS = 2208988800;

    public const int FILETYPE_DATA = 0xFFD;
    public const int FILETYPE_TEXT = 0xFFF;
    public const int FILETYPE_DIR  = 0x1000;

    public static function unixTimeToCentiseconds(int $iUnixTime): int
    {
        return ($iUnixTime + self::EPOCH_DIFF_SECONDS) * 100;
    }

    public static function centisecondsToUnixTime(int $iCentiseconds): int
    {
        return intdiv($iCentiseconds, 100) - self::EPOCH_DIFF_SECONDS;
    }

    public static function makeLoadAddr(int $iFiletype, int $iCentiseconds): int
    {
        return (0xFFF00000 | (($iFiletype & 0xFFF) << 8) | (($iCentiseconds >> 32) & 0xFF)) & 0xFFFFFFFF;
    }

    public static function makeExecAddr(int $iCentiseconds): int
    {
        return $iCentiseconds & 0xFFFFFFFF;
    }

    public static function filetypeFromLoadAddr(int $iLoad): int
    {
        if (($iLoad & 0xFFF00000) !== 0xFFF00000) {
            return self::FILETYPE_DATA;
        }
        return ($iLoad >> 8) & 0xFFF;
    }

    /**
     * Reconstructs the RISC OS centisecond timestamp a (load, exec) pair encodes, for the
     * high byte of a filetyped load address only - returns null for an untyped load address,
     * since there is no timestamp encoded in that case.
     */
    public static function centisecondsFromLoadExec(int $iLoad, int $iExec): ?int
    {
        if (($iLoad & 0xFFF00000) !== 0xFFF00000) {
            return null;
        }
        return (($iLoad & 0xFF) << 32) | $iExec;
    }

    /** A ",xxx" filetype suffix on a name, or null if absent/not valid hex. */
    public static function filetypeFromSuffix(string $sName): ?int
    {
        if (preg_match('/,([0-9a-fA-F]{3})$/', $sName, $aMatches)) {
            return (int) hexdec($aMatches[1]);
        }
        return null;
    }

    public static function stripTypeSuffix(string $sName): string
    {
        return preg_replace('/,[0-9a-fA-F]{3}$/', '', $sName) ?? $sName;
    }

    public static function appendTypeSuffix(string $sName, int $iFiletype): string
    {
        return sprintf('%s,%03x', self::stripTypeSuffix($sName), $iFiletype & 0xFFF);
    }

    /**
     * Maps this codebase's Econet-style DirectoryEntry access byte (1=public read, 2=public
     * write, 4=owner read, 8=owner write, 16=locked, 32=dir) onto ShareFS attribute bits
     * (0x01=R owner-read, 0x02=W owner-write, 0x08=L locked, 0x10=r public-read,
     * 0x20=w public-write). The two bit layouts don't correspond directly - there is no
     * separate "owner" identity in a ShareFS session the way Acorn's own FileServer access
     * byte assumes, so owner-read is always asserted and owner-write tracks "not locked".
     */
    public static function econetAccessToShareFsAttrs(int $iEconetAccess): int
    {
        $iAttrs = 0x01;
        if (($iEconetAccess & 16) !== 0) {
            $iAttrs |= 0x08;
        } else {
            $iAttrs |= 0x02;
        }
        if (($iEconetAccess & 1) !== 0) {
            $iAttrs |= 0x10;
        }
        if (($iEconetAccess & 2) !== 0) {
            $iAttrs |= 0x20;
        }
        return $iAttrs;
    }
}
