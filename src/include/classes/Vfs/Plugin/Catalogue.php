<?php
namespace HomeLan\FileStore\Vfs\Plugin;

use HomeLan\FileStore\Vfs\Exception as VfsException;
use HomeLan\FileStore\Vfs\Vfs;
use HomeLan\FileStore\Vfs\DirectoryEntry;
use HomeLan\FileStore\Vfs\FileDescriptor;
use HomeLan\FileStore\Vfs\FilePath;
use HomeLan\FileStore\Authentication\User;
use config;

/**
 * Catalogue VFS plugin — serves read-only files described in a remotely-fetched JSON catalogue.
 *
 * Multiple catalogue URLs can each be mapped to a different Econet VFS subtree.
 * Configuration is via the config:: system:
 *
 *   vfs_plugin_catalogue_mappings        = JSON array of mapping objects (see README)
 *   vfs_plugin_catalogue_cache_dir       = local cache directory (default /var/lib/cache/aun/catalogue/)
 *   vfs_plugin_catalogue_reload_interval = default catalogue reload interval in seconds (default 3600)
 *
 * Each mapping object:
 *   {
 *     "econet_path":     "$.apps",
 *     "catalogue_url":   "https://example.com/myfiles",   // directory URL; index.json is appended automatically
 *     "reload_interval": 1800                             // optional, overrides global default
 *   }
 *
 * The catalogue is fetched from {catalogue_url}/index.json.
 * Relative file URLs in the catalogue are resolved against catalogue_url directly.
 *
 * Catalogue JSON format (index.json):
 *   {
 *     "files": {
 *       "game": {
 *         "version": 1,
 *         "md5sum":  "d41d8cd98f00b204e9800998ecf8427e",
 *         "load":    4294901760,
 *         "exec":    4294901760,
 *         "size":    1024,
 *         "url":     "https://example.com/files/game"
 *       },
 *       "utils.editor": { ... }
 *     }
 *   }
 *
 * File paths in the catalogue are relative to the mapping econet_path, using '.' as separator.
 * Directories are inferred from path prefixes — they do not need to be listed explicitly.
 *
 * @package corevfs
 * @author John Brown <john@home-lan.co.uk>
 *
 * @phpstan-type CatalogueMapping array{_idx:int,econet_path:string,catalogue_url:string,reload_interval:?int}
 * @phpstan-type CatalogueEntry array{version:string,load:?int,exec:?int,size:int,url:string}
 */
class Catalogue implements PluginInterface {

    protected static \Psr\Log\LoggerInterface $oLogger;
    protected static bool $bMultiuser = false;

    /** @var array<int,CatalogueMapping> */
    protected static array $aMappings = [];

    /**
     * Catalogue entries keyed by mapping index then relative file path.
     * @var array<int,array<string,CatalogueEntry>>
     */
    protected static array $aCatalogues = [];

    /**
     * Unix timestamp of last successful catalogue reload, keyed by mapping index.
     * @var array<int,int>
     */
    protected static array $aLastReloaded = [];

    protected static string $sCacheDir = '/var/lib/cache/aun/catalogue/';
    protected static int $iReloadInterval = 3600;

    /** @var array<int,array{data:string,pos:int}> */
    protected static array $aFileHandles = [];
    protected static int $iNextHandle = 1;

    /** Injected HTTP fetcher for testing: callable(string $sUrl): ?string */
    protected static ?\Closure $fHttpFetcher = null;

    // -------------------------------------------------------------------------
    // Lifecycle
    // -------------------------------------------------------------------------

    public static function init(\Psr\Log\LoggerInterface $oLogger, bool $bMultiuser = false): void
    {
        self::$oLogger    = $oLogger;
        self::$bMultiuser = $bMultiuser;

        $sMappings = config::getValueAsString('vfs_plugin_catalogue_mappings');
        self::$aMappings = !empty($sMappings) ? self::_normalizeMappings(json_decode($sMappings, true)) : [];

        $sCacheDir = config::getValueAsString('vfs_plugin_catalogue_cache_dir');
        self::$sCacheDir = !empty($sCacheDir) ? rtrim($sCacheDir, '/') . '/' : '/var/lib/cache/aun/catalogue/';

        $iInterval = config::getValueAsInt('vfs_plugin_catalogue_reload_interval');
        self::$iReloadInterval = !empty($iInterval) ? $iInterval : 3600;

        foreach (self::$aMappings as $aMapping) {
            self::_loadCatalogue($aMapping);
        }
    }

    /**
     * Safely stringifies/intifies a value read from decoded JSON (json_decode()
     * gives back `mixed`, so callers can't assume a field is the scalar type
     * the JSON was expected to contain).
     */
    private static function _asString(mixed $mValue): string
    {
        return is_scalar($mValue) ? (string) $mValue : '';
    }

    private static function _asInt(mixed $mValue): int
    {
        return is_scalar($mValue) ? (int) $mValue : 0;
    }

    /**
     * Validates and normalizes the decoded vfs_plugin_catalogue_mappings JSON
     * into a uniformly-shaped list (plus the injected _idx used to key
     * $aCatalogues/$aLastReloaded) — entries missing econet_path are dropped.
     *
     * @return array<int,CatalogueMapping>
     */
    private static function _normalizeMappings(mixed $mDecoded): array
    {
        $aResult = [];
        if (!is_array($mDecoded)) {
            return $aResult;
        }
        $iIdx = 0;
        foreach ($mDecoded as $mEntry) {
            if (!is_array($mEntry) || !isset($mEntry['econet_path'])) {
                continue;
            }
            $aResult[] = [
                '_idx'            => $iIdx,
                'econet_path'     => self::_asString($mEntry['econet_path']),
                'catalogue_url'   => isset($mEntry['catalogue_url']) ? self::_asString($mEntry['catalogue_url']) : '',
                'reload_interval' => isset($mEntry['reload_interval']) ? self::_asInt($mEntry['reload_interval']) : null,
            ];
            $iIdx++;
        }
        return $aResult;
    }

    /**
     * Validates and normalizes a catalogue's decoded "files" object.
     *
     * @return array<string,CatalogueEntry>
     */
    private static function _normalizeCatalogueFiles(mixed $mFiles): array
    {
        $aResult = [];
        if (!is_array($mFiles)) {
            return $aResult;
        }
        foreach ($mFiles as $mRelPath => $mEntry) {
            if (!is_string($mRelPath) || !is_array($mEntry)) {
                continue;
            }
            $aResult[$mRelPath] = [
                'version' => isset($mEntry['version']) ? self::_asString($mEntry['version']) : '',
                'load'    => isset($mEntry['load']) ? self::_asInt($mEntry['load']) : null,
                'exec'    => isset($mEntry['exec']) ? self::_asInt($mEntry['exec']) : null,
                'size'    => isset($mEntry['size']) ? self::_asInt($mEntry['size']) : 0,
                'url'     => isset($mEntry['url']) ? self::_asString($mEntry['url']) : '',
            ];
        }
        return $aResult;
    }

    public static function houseKeeping(): void
    {
        $iNow = time();
        foreach (self::$aMappings as $i => $aMapping) {
            $iInterval = (int) ($aMapping['reload_interval'] ?? self::$iReloadInterval);
            $iLast     = self::$aLastReloaded[$i] ?? 0;
            if (($iNow - $iLast) >= $iInterval) {
                self::_loadCatalogue(self::$aMappings[$i]);
            }
        }
    }

    /**
     * Inject an HTTP fetcher callable for testing.
     * The callable receives a URL string and returns the response body or null on failure.
     */
    public static function setHttpFetcher(callable $fFetcher): void
    {
        self::$fHttpFetcher = \Closure::fromCallable($fFetcher);
    }

    /** Reset all static state (used in tests). */
    public static function reset(): void
    {
        self::$aMappings      = [];
        self::$aCatalogues    = [];
        self::$aLastReloaded  = [];
        self::$sCacheDir      = '/var/lib/cache/aun/catalogue/';
        self::$iReloadInterval = 3600;
        self::$aFileHandles   = [];
        self::$iNextHandle    = 1;
        self::$fHttpFetcher   = null;
    }

    // -------------------------------------------------------------------------
    // URL helpers
    // -------------------------------------------------------------------------

    /**
     * Resolve a file URL that may be relative to the catalogue directory URL.
     * Absolute URLs (containing "://") are returned unchanged.
     * Relative URLs are joined directly to $sCatalogueUrl (which is the directory,
     * not the index.json path).
     */
    protected static function _resolveFileUrl(string $sFileUrl, string $sCatalogueUrl): string
    {
        if (str_contains($sFileUrl, '://')) {
            return $sFileUrl;
        }
        return rtrim($sCatalogueUrl, '/') . '/' . $sFileUrl;
    }

    // -------------------------------------------------------------------------
    // Catalogue management
    // -------------------------------------------------------------------------

    protected static function _fetchUrl(string $sUrl): ?string
    {
        if (self::$fHttpFetcher !== null) {
            $mResult = (self::$fHttpFetcher)($sUrl);
            return is_string($mResult) ? $mResult : null;
        }
        $oContext = stream_context_create(['http' => ['timeout' => 10, 'method' => 'GET']]);
        $sData = @file_get_contents($sUrl, false, $oContext);
        return ($sData !== false) ? $sData : null;
    }

    /** @param CatalogueMapping $aMapping */
    protected static function _loadCatalogue(array $aMapping): void
    {
        $iIdx          = $aMapping['_idx'];
        $sEconetPath   = $aMapping['econet_path'];
        $sCatalogueUrl = $aMapping['catalogue_url'];
        if (empty($sCatalogueUrl)) {
            self::$oLogger->debug("Catalogue: no catalogue_url for mapping " . $sEconetPath);
            return;
        }

        $sIndexUrl = rtrim($sCatalogueUrl, '/') . '/index.json';
        self::$oLogger->debug("Catalogue: fetching catalogue from " . $sIndexUrl);
        $sJson = self::_fetchUrl($sIndexUrl);
        if ($sJson === null) {
            self::$oLogger->debug("Catalogue: failed to fetch catalogue from " . $sIndexUrl);
            return;
        }

        $aData = json_decode($sJson, true);
        if (!is_array($aData) || !isset($aData['files'])) {
            self::$oLogger->debug("Catalogue: invalid JSON from " . $sIndexUrl);
            return;
        }

        $aFiles = self::_normalizeCatalogueFiles($aData['files']);
        self::$aCatalogues[$iIdx]   = $aFiles;
        self::$aLastReloaded[$iIdx] = time();
        self::$oLogger->debug("Catalogue: loaded " . count($aFiles) . " entries for " . $sEconetPath);

        self::_checkVersionUpdates($aMapping);
    }

    /**
     * Invalidate cached files whose on-disk version tag differs from the freshly-loaded catalogue.
     */
    /** @param CatalogueMapping $aMapping */
    protected static function _checkVersionUpdates(array $aMapping): void
    {
        $iIdx    = $aMapping['_idx'];
        $aFiles  = self::$aCatalogues[$iIdx] ?? [];

        foreach ($aFiles as $sRelPath => $aEntry) {
            $sVerPath = self::_getCacheVersionPath($aMapping, $sRelPath);
            if (is_file($sVerPath)) {
                $sCachedVersion = (string) file_get_contents($sVerPath);
                $sNewVersion    = $aEntry['version'];
                if ($sCachedVersion !== $sNewVersion) {
                    self::_invalidateCache($aMapping, $sRelPath);
                }
            }
        }
    }

    // -------------------------------------------------------------------------
    // Path helpers
    // -------------------------------------------------------------------------

    /** @return ?CatalogueMapping */
    protected static function _findMapping(string $sEconetPath): ?array
    {
        foreach (self::$aMappings as $aMapping) {
            $sMappingPath = rtrim($aMapping['econet_path'], '.');
            if ($sEconetPath === $sMappingPath || str_starts_with($sEconetPath, $sMappingPath . '.')) {
                return $aMapping;
            }
        }
        return null;
    }

    /** @param CatalogueMapping $aMapping */
    protected static function _econetToRelative(string $sEconetPath, array $aMapping): string
    {
        $sMappingPath = rtrim($aMapping['econet_path'], '.');
        if ($sEconetPath === $sMappingPath) {
            return '';
        }
        return substr($sEconetPath, strlen($sMappingPath) + 1);
    }

    /**
     * @param CatalogueMapping $aMapping
     * @return ?CatalogueEntry
     */
    protected static function _getCatalogueEntry(array $aMapping, string $sRelPath): ?array
    {
        $aFiles = self::$aCatalogues[$aMapping['_idx']] ?? [];
        return $aFiles[$sRelPath] ?? null;
    }

    // -------------------------------------------------------------------------
    // Local disk cache helpers
    // -------------------------------------------------------------------------

    /** @param CatalogueMapping $aMapping */
    protected static function _getCacheSlug(array $aMapping): string
    {
        return md5($aMapping['catalogue_url'] !== '' ? $aMapping['catalogue_url'] : $aMapping['econet_path']);
    }

    /** @param CatalogueMapping $aMapping */
    protected static function _getCacheFilePath(array $aMapping, string $sRelPath): string
    {
        return self::$sCacheDir . self::_getCacheSlug($aMapping) . '/' . str_replace('.', '/', $sRelPath);
    }

    /** @param CatalogueMapping $aMapping */
    protected static function _getCacheVersionPath(array $aMapping, string $sRelPath): string
    {
        return self::_getCacheFilePath($aMapping, $sRelPath) . '.ver';
    }

    /** @param CatalogueMapping $aMapping */
    protected static function _loadFromCache(array $aMapping, string $sRelPath): ?string
    {
        $sCachePath = self::_getCacheFilePath($aMapping, $sRelPath);
        $sVerPath   = self::_getCacheVersionPath($aMapping, $sRelPath);

        if (!is_file($sCachePath)) {
            return null;
        }

        $aEntry = self::_getCatalogueEntry($aMapping, $sRelPath);
        if ($aEntry !== null && is_file($sVerPath)) {
            $sCachedVersion = file_get_contents($sVerPath);
            if ($sCachedVersion !== $aEntry['version']) {
                @unlink($sCachePath);
                @unlink($sVerPath);
                self::$oLogger->debug("Catalogue: stale cache for " . $sRelPath . " (version mismatch)");
                return null;
            }
        }

        $sData = file_get_contents($sCachePath);
        if ($sData === false) {
            return null;
        }

        self::$oLogger->debug("Catalogue: cache hit for " . $sRelPath);
        return $sData;
    }

    /** @param CatalogueMapping $aMapping */
    protected static function _saveToCache(array $aMapping, string $sRelPath, string $sData, string $sVersion): void
    {
        $sCachePath = self::_getCacheFilePath($aMapping, $sRelPath);
        $sVerPath   = self::_getCacheVersionPath($aMapping, $sRelPath);
        $sDir = dirname($sCachePath);

        if (!is_dir($sDir) && !@mkdir($sDir, 0755, true)) {
            self::$oLogger->debug("Catalogue: unable to create cache dir " . $sDir);
            return;
        }
        if (@file_put_contents($sCachePath, $sData) === false) {
            self::$oLogger->debug("Catalogue: unable to write cache file " . $sCachePath);
            return;
        }
        @file_put_contents($sVerPath, $sVersion);
        self::$oLogger->debug("Catalogue: cached " . $sRelPath . " version " . $sVersion);
    }

    /** @param CatalogueMapping $aMapping */
    protected static function _invalidateCache(array $aMapping, string $sRelPath): void
    {
        $sCachePath = self::_getCacheFilePath($aMapping, $sRelPath);
        $sVerPath   = self::_getCacheVersionPath($aMapping, $sRelPath);
        if (is_file($sCachePath)) {
            @unlink($sCachePath);
            @unlink($sVerPath);
            self::$oLogger->debug("Catalogue: invalidated cache for " . $sRelPath);
        }
    }

    // -------------------------------------------------------------------------
    // PluginInterface — file handle operations
    // -------------------------------------------------------------------------

    public static function _buildFiledescriptorFromEconetPath(User $oUser, FilePath $oEconetPath, bool $bMustExist, bool $bReadOnly,bool $bDirectory=false): FileDescriptor
    {
        $sFullPath = $oEconetPath->getFilePath();
        $aMapping  = self::_findMapping($sFullPath);
        if ($aMapping === null) {
            throw new VfsException("No catalogue mapping for path " . $sFullPath);
        }

        if (!$bReadOnly) {
            throw new VfsException("Catalogue plugin is read-only", true);
        }

        $sRelPath = self::_econetToRelative($sFullPath, $aMapping);
        $aEntry   = self::_getCatalogueEntry($aMapping, $sRelPath);

        if ($bMustExist && $aEntry === null) {
            throw new VfsException("No such file in catalogue: " . $sFullPath);
        }

        $sData = '';
        if ($aEntry !== null) {
            $sCached = self::_loadFromCache($aMapping, $sRelPath);
            if ($sCached !== null) {
                $sData = $sCached;
            } else {
                $sFileUrl = $aEntry['url'];
                if (empty($sFileUrl)) {
                    throw new VfsException("No URL configured for file: " . $sFullPath);
                }
                $sResolvedUrl = self::_resolveFileUrl($sFileUrl, $aMapping['catalogue_url']);
                $sFetched = self::_fetchUrl($sResolvedUrl);
                if ($sFetched === null) {
                    throw new VfsException("Failed to fetch file from URL: " . $sResolvedUrl);
                }
                $sData = $sFetched;
                self::_saveToCache($aMapping, $sRelPath, $sData, $aEntry['version']);
            }
        }

        $iHandle = self::$iNextHandle++;
        self::$aFileHandles[$iHandle] = ['data' => $sData, 'pos' => 0];

        $iEconetHandle = Vfs::getFreeFileHandleID($oUser);
        return new FileDescriptor(
            self::$oLogger,
            \HomeLan\FileStore\Vfs\Plugin\Catalogue::class,
            $oUser,
            $sRelPath,
            $sFullPath,
            $iHandle,
            $iEconetHandle,
            ($aEntry !== null),
            false,
            $bMustExist,
            $bReadOnly
        );
    }

    public static function _getAccessMode(int $iGid, int $iUid, int $iMode): string
    {
        return 'r/r';
    }

    // -------------------------------------------------------------------------
    // PluginInterface — directory listing
    // -------------------------------------------------------------------------

    /**
     * @param array<string,DirectoryEntry> $aDirectoryListing
     * @return array<string,DirectoryEntry>
     */
    public static function getDirectoryListing(string $sEconetPath, array $aDirectoryListing): array
    {
        $aMapping = self::_findMapping($sEconetPath);
        if ($aMapping === null) {
            return $aDirectoryListing;
        }

        $aFiles   = self::$aCatalogues[$aMapping['_idx']] ?? [];
        $sRelPath = self::_econetToRelative($sEconetPath, $aMapping);
        $sPrefix  = ($sRelPath === '') ? '' : $sRelPath . '.';

        $aSeenDirs = [];
        foreach ($aFiles as $sKey => $aEntry) {
            // Skip entries that are not under the requested path.
            if ($sPrefix !== '' && !str_starts_with($sKey, $sPrefix)) {
                continue;
            }

            $sEntryRel = ($sPrefix === '') ? $sKey : substr($sKey, strlen($sPrefix));
            $iDot = strpos($sEntryRel, '.');

            if ($iDot !== false) {
                // File is inside a subdirectory — emit the subdirectory entry.
                $sDirName = substr($sEntryRel, 0, $iDot);
                if (!isset($aSeenDirs[$sDirName]) && !array_key_exists($sDirName, $aDirectoryListing)) {
                    $aSeenDirs[$sDirName] = true;
                    $aDirectoryListing[$sDirName] = new DirectoryEntry(
                        $sDirName, $sDirName,
                        \HomeLan\FileStore\Vfs\Plugin\Catalogue::class,
                        null, null, 0,
                        $sEconetPath . '.' . $sDirName,
                        time(), 'r/r', true
                    );
                }
            } else {
                // Direct child file.
                if (!array_key_exists($sEntryRel, $aDirectoryListing)) {
                    $aDirectoryListing[$sEntryRel] = new DirectoryEntry(
                        $sEntryRel, $sEntryRel,
                        \HomeLan\FileStore\Vfs\Plugin\Catalogue::class,
                        $aEntry['load'],
                        $aEntry['exec'],
                        $aEntry['size'],
                        $sEconetPath . '.' . $sEntryRel,
                        time(), 'r/r', false
                    );
                }
            }
        }

        return $aDirectoryListing;
    }

    // -------------------------------------------------------------------------
    // PluginInterface — write operations (all refused with hard exceptions)
    // -------------------------------------------------------------------------

    public static function createDirectory(User $oUser, FilePath $oPath): bool
    {
        if (self::_findMapping($oPath->getFilePath()) === null) {
            return false;
        }
        throw new VfsException("Catalogue plugin is read-only", true);
    }

    public static function deleteFile(User $oUser, FilePath $oEconetPath): bool
    {
        if (self::_findMapping($oEconetPath->getFilePath()) === null) {
            return false;
        }
        throw new VfsException("Catalogue plugin is read-only", true);
    }

    public static function moveFile(User $oUser, FilePath $oEconetPathFrom, FilePath $oEconetPathTo): bool
    {
        if (self::_findMapping($oEconetPathFrom->getFilePath()) === null) {
            return false;
        }
        throw new VfsException("Catalogue plugin is read-only", true);
    }

    public static function saveFile(User $oUser, FilePath $oEconetPath, string $sData, int $iLoadAddr, int $iExecAddr): bool
    {
        if (self::_findMapping($oEconetPath->getFilePath()) === null) {
            return false;
        }
        throw new VfsException("Catalogue plugin is read-only", true);
    }

    public static function createFile(User $oUser, FilePath $oEconetPath, int $iSize, int $iLoadAddr, int $iExecAddr): bool
    {
        if (self::_findMapping($oEconetPath->getFilePath()) === null) {
            return false;
        }
        throw new VfsException("Catalogue plugin is read-only", true);
    }

    // -------------------------------------------------------------------------
    // PluginInterface — getFile / setMeta
    // -------------------------------------------------------------------------

    public static function getFile(User $oUser, FilePath $oEconetPath): string
    {
        $sFullPath = $oEconetPath->getFilePath();
        $aMapping  = self::_findMapping($sFullPath);
        if ($aMapping === null) {
            throw new VfsException("No catalogue mapping for path " . $sFullPath);
        }

        $sRelPath = self::_econetToRelative($sFullPath, $aMapping);
        $aEntry   = self::_getCatalogueEntry($aMapping, $sRelPath);
        if ($aEntry === null) {
            throw new VfsException("No such file in catalogue: " . $sFullPath);
        }

        $sCached = self::_loadFromCache($aMapping, $sRelPath);
        if ($sCached !== null) {
            return $sCached;
        }

        $sFileUrl = $aEntry['url'];
        if (empty($sFileUrl)) {
            throw new VfsException("No URL configured for file: " . $sFullPath);
        }
        $sResolvedUrl = self::_resolveFileUrl($sFileUrl, $aMapping['catalogue_url']);
        $sData = self::_fetchUrl($sResolvedUrl);
        if ($sData === null) {
            throw new VfsException("Failed to fetch file from URL: " . $sResolvedUrl);
        }
        self::_saveToCache($aMapping, $sRelPath, $sData, $aEntry['version']);
        return $sData;
    }

    public static function setMeta(string $sEconetPath, ?int $iLoad, ?int $iExec, ?int $iAccess): void
    {
        // Read-only plugin — silently ignore.
    }

    // -------------------------------------------------------------------------
    // PluginInterface — handle-based I/O
    // -------------------------------------------------------------------------

    public static function fsFtell(User $oUser, mixed $fLocalHandle): int
    {
        if (!is_int($fLocalHandle) || !isset(self::$aFileHandles[$fLocalHandle])) {
            throw new VfsException("Invalid file handle");
        }
        return self::$aFileHandles[$fLocalHandle]['pos'];
    }

    /** @return array<int|string,int> */
    public static function fsFStat(User $oUser, mixed $fLocalHandle): array
    {
        if (!is_int($fLocalHandle) || !isset(self::$aFileHandles[$fLocalHandle])) {
            throw new VfsException("Invalid file handle");
        }
        $iSize = strlen(self::$aFileHandles[$fLocalHandle]['data']);
        return ['size' => $iSize, 7 => $iSize];
    }

    public static function isEof(User $oUser, mixed $fLocalHandle): bool
    {
        if (!is_int($fLocalHandle) || !isset(self::$aFileHandles[$fLocalHandle])) {
            return true;
        }
        $oHandle = self::$aFileHandles[$fLocalHandle];
        return $oHandle['pos'] >= strlen($oHandle['data']);
    }

    public static function setPos(User $oUser, mixed $fLocalHandle, int $iPos): int
    {
        if (!is_int($fLocalHandle) || !isset(self::$aFileHandles[$fLocalHandle])) {
            throw new VfsException("Invalid file handle");
        }
        self::$aFileHandles[$fLocalHandle]['pos'] = $iPos;
        return 0;
    }

    public static function read(User $oUser, mixed $fLocalHandle, int $iLength): string
    {
        if (!is_int($fLocalHandle) || !isset(self::$aFileHandles[$fLocalHandle])) {
            throw new VfsException("Invalid file handle");
        }
        $oHandle =& self::$aFileHandles[$fLocalHandle];
        $sChunk  = substr($oHandle['data'], $oHandle['pos'], $iLength);
        $oHandle['pos'] += strlen($sChunk);
        return $sChunk;
    }

    public static function write(User $oUser, mixed $fLocalHandle, string $sData): int
    {
        if (!is_int($fLocalHandle) || !isset(self::$aFileHandles[$fLocalHandle])) {
            throw new VfsException("Invalid file handle");
        }
        throw new VfsException("Catalogue plugin is read-only", true);
    }

    public static function setExt(User $oUser, mixed $fLocalHandle, int $iExt): void
    {
        throw new VfsException("Catalogue plugin is read-only", true);
    }

    public static function fsLock(User $oUser, mixed $fLocalHandle, bool $bExclusive): void {}

    public static function fsUnlock(User $oUser, mixed $fLocalHandle): void {}

    public static function fsClose(User $oUser, mixed $fLocalHandle): bool
    {
        if (!is_int($fLocalHandle) || !isset(self::$aFileHandles[$fLocalHandle])) {
            return false;
        }
        unset(self::$aFileHandles[$fLocalHandle]);
        return true;
    }
}
