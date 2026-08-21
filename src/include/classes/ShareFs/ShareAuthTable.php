<?php

namespace HomeLan\FileStore\ShareFs;

/**
 * Tracks which (client IP, share name) pairs have successfully passed Access+ authentication
 * for a protected share, matching andrewtimmins/riscos-access-server's src/accessplus.c
 * (sfs_auth_add/sfs_auth_check): a 10-minute sliding window, refreshed on every successful
 * check, keyed by IP address alone (not IP:port - the reference server keys purely by source
 * address).
 *
 * Real Access+ has no per-user login concept at all - see AccessPlusHandler and
 * docs/protocols/sharefs.md. This answers exactly one question: "is this IP currently allowed
 * to touch this protected share", nothing more.
 */
class ShareAuthTable
{
    private const int EXPIRY_SECONDS = 600;

    /** @var array<string, int> "ip|SHARENAME" => unix expiry timestamp */
    private static array $aEntries = [];

    public static function add(string $sClientIp, string $sShareName): void
    {
        self::$aEntries[self::key($sClientIp, $sShareName)] = time() + self::EXPIRY_SECONDS;
    }

    public static function check(string $sClientIp, string $sShareName): bool
    {
        $sKey = self::key($sClientIp, $sShareName);
        if (!isset(self::$aEntries[$sKey])) {
            return false;
        }
        if (self::$aEntries[$sKey] <= time()) {
            unset(self::$aEntries[$sKey]);
            return false;
        }
        self::$aEntries[$sKey] = time() + self::EXPIRY_SECONDS;
        return true;
    }

    public static function houseKeeping(): void
    {
        $iNow = time();
        foreach (self::$aEntries as $sKey => $iExpiry) {
            if ($iExpiry <= $iNow) {
                unset(self::$aEntries[$sKey]);
            }
        }
    }

    /** @return list<array{ip:string, share:string, expires:int}> */
    public static function getEntries(): array
    {
        $aReturn = [];
        foreach (self::$aEntries as $sKey => $iExpiry) {
            [$sIp, $sShareName] = explode('|', $sKey, 2);
            $aReturn[] = ['ip' => $sIp, 'share' => $sShareName, 'expires' => $iExpiry];
        }
        return $aReturn;
    }

    private static function key(string $sClientIp, string $sShareName): string
    {
        return $sClientIp . '|' . strtoupper($sShareName);
    }

    /** Reset all state — used only by unit tests. */
    public static function reset(): void
    {
        self::$aEntries = [];
    }
}
