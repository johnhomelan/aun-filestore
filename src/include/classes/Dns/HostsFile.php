<?php

namespace HomeLan\FileStore\Dns;

use config;

/**
 * Parses a Unix-style hosts file (`<ip> <name> [alias...]`, `#` comments) and answers lookups
 * for it. Loaded once at daemon startup, the same as ShareFs\ShareList - see docs/DNSD.md.
 *
 * IPv4 addresses only - dnsd is IPv4-only throughout (see docs/protocols/dns.md), since its
 * only client, EconetA, has no IPv6 support at all. An IPv6 address in the hosts file is
 * rejected the same way any other malformed line is.
 */
class HostsFile
{
    /** @var array<string, list<string>> lowercased name => list of IPv4 addresses */
    private static array $aHosts = [];

    /** @var array<string, list<string>> normalised IP => list of primary hostnames (a line's first name), for PTR lookups */
    private static array $aReverse = [];

    public static function init(\Psr\Log\LoggerInterface $oLogger, ?string $sFileContent = null): void
    {
        self::$aHosts = [];
        self::$aReverse = [];

        if ($sFileContent === null) {
            $sFile = config::getValueAsString('dns_hosts_file');
            if (!file_exists($sFile)) {
                $oLogger->info("Dns: hosts file not found ({$sFile}), no hosts configured");
                return;
            }
            $sFileContent = (string) file_get_contents($sFile);
        }

        foreach (explode("\n", $sFileContent) as $sLine) {
            self::parseLine($sLine, $oLogger);
        }
    }

    private static function parseLine(string $sLine, \Psr\Log\LoggerInterface $oLogger): void
    {
        $iHashPos = strpos($sLine, '#');
        if ($iHashPos !== false) {
            $sLine = substr($sLine, 0, $iHashPos);
        }
        $sLine = trim($sLine);
        if ($sLine === '') {
            return;
        }

        $aTokens = preg_split('/\s+/', $sLine) ?: [];
        if (count($aTokens) < 2) {
            $oLogger->warning("Dns: unrecognised hosts file line: {$sLine}");
            return;
        }

        $sIp = array_shift($aTokens);
        if (filter_var($sIp, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
            if (filter_var($sIp, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false) {
                $oLogger->warning("Dns: ignoring IPv6 hosts file line (dnsd is IPv4-only): {$sLine}");
            } else {
                $oLogger->warning("Dns: invalid IP address in hosts file line: {$sLine}");
            }
            return;
        }

        foreach ($aTokens as $sName) {
            self::$aHosts[strtolower($sName)][] = $sIp;
        }

        // The line's first name is the canonical/primary one, used for reverse lookups - the
        // same convention glibc's "files" NSS module uses for /etc/hosts.
        self::$aReverse[$sIp][] = $aTokens[0];
    }

    /** @return list<string> */
    public static function lookup(string $sName): array
    {
        return self::$aHosts[self::normaliseName($sName)] ?? [];
    }

    public static function isKnownName(string $sName): bool
    {
        return array_key_exists(self::normaliseName($sName), self::$aHosts);
    }

    /** @return list<string> the primary hostname(s) for this IP, if any */
    public static function reverseLookup(string $sIp): array
    {
        return self::$aReverse[$sIp] ?? [];
    }

    private static function normaliseName(string $sName): string
    {
        return strtolower(rtrim($sName, '.'));
    }

    /** Reset all state — used only by unit tests. */
    public static function reset(): void
    {
        self::$aHosts = [];
        self::$aReverse = [];
    }
}
