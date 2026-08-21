<?php

namespace HomeLan\FileStore\ShareFs;

use config;

/**
 * Parses the ShareFS share list file and holds the resulting Share definitions.
 *
 * Unlike the encapsulation Map classes (Aun\Map, WebSocket\Map, Piconet\Map,
 * RemoteBridge\Map), this has nothing to do with translating an Econet network/station
 * address to an encapsulation-specific address - ShareFS has no Econet transport at all (see
 * docs/protocols/sharefs.md). This is just the configured list of shares.
 *
 * Share list file format:
 *   SHARE <name> <econet_vfs_path> [attribute,attribute,...] [password]
 *
 * Attributes (comma-separated, all optional): protected, readonly, hidden - see Share's own
 * docblock for what each does. `password` is required if and only if `protected` is present.
 */
class ShareList
{
    /** @var array<string, Share> Share name (uppercase) => Share */
    private static array $aShares = [];

    public static function init(\Psr\Log\LoggerInterface $oLogger, ?string $sListContent = null): void
    {
        self::$aShares = [];

        if ($sListContent === null) {
            $sFile = config::getValueAsString('sharefs_share_list_file');
            if (!file_exists($sFile)) {
                $oLogger->info("ShareFs: share list file not found ({$sFile}), no shares configured");
                return;
            }
            $sListContent = (string) file_get_contents($sFile);
        }

        foreach (explode("\n", $sListContent) as $sLine) {
            $sLine = trim($sLine);
            if ($sLine === '' || $sLine[0] === '#') {
                continue;
            }

            $oShare = self::parseLine($sLine, $oLogger);
            if ($oShare !== null) {
                self::$aShares[strtoupper($oShare->getName())] = $oShare;
                $oLogger->debug("ShareFs: share entry name={$oShare->getName()} path={$oShare->getVfsPath()}");
            }
        }
    }

    private static function parseLine(string $sLine, \Psr\Log\LoggerInterface $oLogger): ?Share
    {
        $aTokens = preg_split('/\s+/', $sLine) ?: [];
        if (count($aTokens) < 3 || strtoupper($aTokens[0]) !== 'SHARE') {
            $oLogger->warning("ShareFs: unrecognised share list line: {$sLine}");
            return null;
        }

        $sName = strtoupper($aTokens[1]);
        $sPath = $aTokens[2];
        $aAttributes = isset($aTokens[3]) ? explode(',', strtolower($aTokens[3])) : [];
        $bProtected = in_array('protected', $aAttributes, true);
        $bReadOnly = in_array('readonly', $aAttributes, true);
        $bHidden = in_array('hidden', $aAttributes, true);
        $sPassword = $aTokens[4] ?? '';

        if ($bProtected && $sPassword === '') {
            $oLogger->warning("ShareFs: share \"{$sName}\" is protected but has no password, skipping: {$sLine}");
            return null;
        }

        return new Share($sName, $sPath, $bProtected, $bReadOnly, $bHidden, $sPassword);
    }

    public static function getShare(string $sName): ?Share
    {
        return self::$aShares[strtoupper($sName)] ?? null;
    }

    /** @return Share[] */
    public static function getShares(): array
    {
        return array_values(self::$aShares);
    }

    /** @return Share[] Shares that should appear in Freeway ADD broadcasts. */
    public static function getAdvertisedShares(): array
    {
        return array_values(array_filter(
            self::$aShares,
            static fn(Share $oShare): bool => $oShare->isAdvertised()
        ));
    }

    /** @return Share[] Protected shares - candidates for an Access+ share-key match. */
    public static function getProtectedShares(): array
    {
        return array_values(array_filter(
            self::$aShares,
            static fn(Share $oShare): bool => $oShare->isProtected()
        ));
    }

    /** Reset all state — used only by unit tests. */
    public static function reset(): void
    {
        self::$aShares = [];
    }
}
