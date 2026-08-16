<?php
namespace HomeLan\FileStore\Vfs\Plugin;

use HomeLan\FileStore\Vfs\Exception as VfsException;
use HomeLan\FileStore\Vfs\Vfs;
use HomeLan\FileStore\Vfs\DirectoryEntry;
use HomeLan\FileStore\Vfs\FileDescriptor;
use HomeLan\FileStore\Vfs\FilePath;
use Aws\S3\S3Client;
use Aws\S3\Exception\S3Exception;
use HomeLan\FileStore\Authentication\User;
use config;

/**
 * S3 VFS plugin — stores Econet files as S3 objects with .inf sidecars.
 *
 * Multiple bucket/prefix mappings are supported.  Each mapping covers a
 * subtree of the Econet VFS.  Configuration is via the config:: system:
 *
 *   vfs_plugin_s3_mappings     = JSON array of mapping objects (see README)
 *   vfs_plugin_s3_cache_dir    = local cache directory (default /var/lib/cache/aun/s3/)
 *
 * Read file handles are served from a local disk cache when possible.
 * The cache is keyed as {cache_dir}/{bucket}/{s3_key}.
 * Opening a write handle or calling a write method invalidates the cached copy.
 * While a write handle is open for a key, read handles bypass the cache and
 * fetch the current content directly from S3.
 *
 * @package corevfs
 * @author John Brown <john@home-lan.co.uk>
 *
 * @phpstan-type S3Mapping array{econet_path:string,bucket:string,prefix:string,region:string,key:string,secret:string,endpoint:string,write_enabled:bool}
*/
class S3 implements PluginInterface {

    protected static \Psr\Log\LoggerInterface $oLogger;
    protected static bool $bMultiuser = false;

    /** @var array<int,S3Mapping> */
    protected static array $aMappings = [];

    /** @var array<int,array{data:string,pos:int,key:string,bucket:string,mapping:S3Mapping,dirty:bool,readonly:bool}> */
    protected static array $aFileHandles = [];
    protected static int $iNextHandle = 1;
    protected static string $sCacheDir = '/var/lib/cache/aun/s3/';

    // Keys with open write handles: "{bucket}:{s3key}" => open-handle count
    /** @var array<string,int> */
    protected static array $aOpenWriteKeys = [];

    // Injected S3 clients for testing (keyed by mapping econet_path)
    /** @var array<string,S3Client|S3ClientContract> */
    protected static array $aOverrideClients = [];

    public static function init(\Psr\Log\LoggerInterface $oLogger, bool $bMultiuser = false): void
    {
        self::$oLogger = $oLogger;
        self::$bMultiuser = $bMultiuser;
        $sMappings = config::getValueAsString('vfs_plugin_s3_mappings');
        if (!empty($sMappings)) {
            self::$aMappings = self::decodeMappingsJson($sMappings);
        }
        $sCacheDir = config::getValueAsString('vfs_plugin_s3_cache_dir');
        self::$sCacheDir = !empty($sCacheDir) ? rtrim($sCacheDir, '/') . '/' : '/var/lib/cache/aun/s3/';
    }

    /**
     * Decodes and validates a vfs_plugin_s3_mappings-style JSON string, e.g.
     * for tools (like S3Upload) that need the same mappings outside of a
     * running VFS instance.
     *
     * @return array<int,S3Mapping>
     */
    public static function decodeMappingsJson(string $sJson): array
    {
        return self::_normalizeMappings(json_decode($sJson, true));
    }

    /**
     * Validates and normalizes the decoded vfs_plugin_s3_mappings JSON into a
     * uniformly-shaped list — entries missing the required econet_path/bucket
     * fields are dropped, and every optional field is given its default so
     * the rest of the class never has to guess at a mapping's shape.
     *
     * @return array<int,S3Mapping>
     */
    private static function _normalizeMappings(mixed $mDecoded): array
    {
        $aResult = [];
        if (!is_array($mDecoded)) {
            return $aResult;
        }
        foreach ($mDecoded as $mEntry) {
            if (!is_array($mEntry) || !isset($mEntry['econet_path'], $mEntry['bucket'])) {
                continue;
            }
            $aResult[] = [
                'econet_path'   => self::_asString($mEntry['econet_path']),
                'bucket'        => self::_asString($mEntry['bucket']),
                'prefix'        => isset($mEntry['prefix']) ? self::_asString($mEntry['prefix']) : '',
                'region'        => isset($mEntry['region']) ? self::_asString($mEntry['region']) : 'us-east-1',
                'key'           => isset($mEntry['key']) ? self::_asString($mEntry['key']) : '',
                'secret'        => isset($mEntry['secret']) ? self::_asString($mEntry['secret']) : '',
                'endpoint'      => isset($mEntry['endpoint']) ? self::_asString($mEntry['endpoint']) : '',
                'write_enabled' => !empty($mEntry['write_enabled']),
            ];
        }
        return $aResult;
    }

    public static function houseKeeping(): void {}

    /**
     * Safely stringifies a value read from an AWS Result/StubS3Client array
     * (S3 SDK response fields are typed `mixed` since \Aws\Result is a
     * generic ArrayAccess). A Stringable object (e.g. the SDK's stream Body)
     * is cast via __toString(); anything else non-scalar becomes ''.
     */
    private static function _asString(mixed $mValue): string
    {
        if (is_scalar($mValue)) {
            return (string) $mValue;
        }
        if (is_object($mValue) && method_exists($mValue, '__toString')) {
            return (string) $mValue;
        }
        return '';
    }

    private static function _asInt(mixed $mValue): int
    {
        return is_scalar($mValue) ? (int) $mValue : 0;
    }

    /**
     * Inject a mock/stub S3Client for a specific mapping (used in tests).
     */
    public static function setS3Client(string $sEconetPath, S3Client|S3ClientContract $oClient): void
    {
        self::$aOverrideClients[$sEconetPath] = $oClient;
    }

    /**
     * Reset all static state (used in tests).
     */
    public static function reset(): void
    {
        self::$aOverrideClients = [];
        self::$aMappings = [];
        self::$aFileHandles = [];
        self::$iNextHandle = 1;
        self::$aOpenWriteKeys = [];
        self::$sCacheDir = '/var/lib/cache/aun/s3/';
    }

    // -------------------------------------------------------------------------
    // Write-access guard
    // -------------------------------------------------------------------------

    /** @param S3Mapping $aMapping */
    protected static function _isMappingWritable(array $aMapping): bool
    {
        return !empty($aMapping['write_enabled']);
    }

    /** @param S3Mapping $aMapping */
    protected static function _assertMappingWritable(array $aMapping): void
    {
        if (!self::_isMappingWritable($aMapping)) {
            throw new VfsException("S3 mapping '" . $aMapping['econet_path'] . "' is read-only", true);
        }
    }

    // -------------------------------------------------------------------------
    // Path helpers
    // -------------------------------------------------------------------------

    /** @return ?S3Mapping */
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

    /** @param S3Mapping $aMapping */
    protected static function _econetToS3Key(string $sEconetPath, array $aMapping): string
    {
        $sMappingPath = rtrim($aMapping['econet_path'], '.');
        $sRelative = substr($sEconetPath, strlen($sMappingPath));
        $sRelative = ltrim($sRelative, '.');
        $sS3Relative = str_replace('.', '/', $sRelative);
        $sPrefix = rtrim($aMapping['prefix'], '/');
        if ($sS3Relative === '') {
            return $sPrefix;
        }
        return ($sPrefix !== '' ? $sPrefix . '/' : '') . $sS3Relative;
    }

    /** @param S3Mapping $aMapping */
    protected static function _econetDirToS3Prefix(string $sEconetPath, array $aMapping): string
    {
        $sKey = self::_econetToS3Key($sEconetPath, $aMapping);
        if ($sKey === '') {
            return '';
        }
        return rtrim($sKey, '/') . '/';
    }

    // -------------------------------------------------------------------------
    // S3 client factory
    // -------------------------------------------------------------------------

    /** @param S3Mapping $aMapping */
    protected static function _getS3Client(array $aMapping): S3Client|S3ClientContract
    {
        if (isset(self::$aOverrideClients[$aMapping['econet_path']])) {
            return self::$aOverrideClients[$aMapping['econet_path']];
        }
        $aConfig = [
            'region'  => $aMapping['region'],
            'version' => 'latest',
        ];
        if (!empty($aMapping['key']) && !empty($aMapping['secret'])) {
            $aConfig['credentials'] = [
                'key'    => $aMapping['key'],
                'secret' => $aMapping['secret'],
            ];
        }
        if (!empty($aMapping['endpoint'])) {
            $aConfig['endpoint']                = $aMapping['endpoint'];
            $aConfig['use_path_style_endpoint'] = true;
        }
        return new S3Client($aConfig);
    }

    // -------------------------------------------------------------------------
    // Local disk cache helpers
    // -------------------------------------------------------------------------

    protected static function _getCacheFilePath(string $sBucket, string $sKey): string
    {
        return self::$sCacheDir . $sBucket . '/' . $sKey;
    }

    /**
     * Load a file from the local cache.  Returns null on cache miss.
     */
    protected static function _loadFromCache(string $sBucket, string $sKey): ?string
    {
        $sPath = self::_getCacheFilePath($sBucket, $sKey);
        if (!is_file($sPath)) {
            return null;
        }
        self::$oLogger->debug("S3 cache: hit " . $sPath);
        $sData = file_get_contents($sPath);
        return ($sData !== false) ? $sData : null;
    }

    /**
     * Write a file to the local cache.  Silently skips on I/O failure.
     */
    protected static function _saveToCache(string $sBucket, string $sKey, string $sData): void
    {
        $sPath = self::_getCacheFilePath($sBucket, $sKey);
        $sDir  = dirname($sPath);
        if (!is_dir($sDir) && !@mkdir($sDir, 0755, true)) {
            self::$oLogger->debug("S3 cache: unable to create cache dir " . $sDir);
            return;
        }
        if (@file_put_contents($sPath, $sData) === false) {
            self::$oLogger->debug("S3 cache: unable to write cache file " . $sPath);
            return;
        }
        self::$oLogger->debug("S3 cache: stored " . $sPath);
    }

    /**
     * Remove a file from the local cache.  No-op if not cached.
     */
    protected static function _invalidateCache(string $sBucket, string $sKey): void
    {
        $sPath = self::_getCacheFilePath($sBucket, $sKey);
        if (is_file($sPath)) {
            @unlink($sPath);
            self::$oLogger->debug("S3 cache: invalidated " . $sPath);
        }
    }

    // -------------------------------------------------------------------------
    // Write-handle tracking — prevents caching while a write handle is live
    // -------------------------------------------------------------------------

    protected static function _hasOpenWriteHandle(string $sBucket, string $sKey): bool
    {
        return isset(self::$aOpenWriteKeys[$sBucket . ':' . $sKey]);
    }

    protected static function _addWriteHandle(string $sBucket, string $sKey): void
    {
        $sLock = $sBucket . ':' . $sKey;
        self::$aOpenWriteKeys[$sLock] = (self::$aOpenWriteKeys[$sLock] ?? 0) + 1;
    }

    protected static function _removeWriteHandle(string $sBucket, string $sKey): void
    {
        $sLock = $sBucket . ':' . $sKey;
        if (isset(self::$aOpenWriteKeys[$sLock])) {
            self::$aOpenWriteKeys[$sLock]--;
            if (self::$aOpenWriteKeys[$sLock] <= 0) {
                unset(self::$aOpenWriteKeys[$sLock]);
            }
        }
    }

    // -------------------------------------------------------------------------
    // PluginInterface implementation
    // -------------------------------------------------------------------------

    public static function _buildFiledescriptorFromEconetPath(User $oUser, FilePath $oEconetPath, bool $bMustExist, bool $bReadOnly): FileDescriptor
    {
        $sFullPath = $oEconetPath->getFilePath();
        $aMapping = self::_findMapping($sFullPath);
        if ($aMapping === null) {
            throw new VfsException("No S3 mapping for path " . $sFullPath);
        }

        $sKey    = self::_econetToS3Key($sFullPath, $aMapping);
        $sBucket = $aMapping['bucket'];
        $oClient = self::_getS3Client($aMapping);

        // Readonly mappings always open handles as readonly, regardless of caller intent
        if (!self::_isMappingWritable($aMapping)) {
            $bReadOnly = true;
        }

        $bExists = $oClient->doesObjectExist($sBucket, $sKey);

        if ($bMustExist && !$bExists) {
            throw new VfsException("No such file");
        }

        $sData = '';
        if ($bExists) {
            if ($bReadOnly && !self::_hasOpenWriteHandle($sBucket, $sKey)) {
                // Read handle with no active write handle — use local cache.
                $sCached = self::_loadFromCache($sBucket, $sKey);
                if ($sCached !== null) {
                    $sData = $sCached;
                } else {
                    $oResult = $oClient->getObject(['Bucket' => $sBucket, 'Key' => $sKey]);
                    $sData   = self::_asString($oResult['Body']);
                    self::_saveToCache($sBucket, $sKey, $sData);
                }
            } else {
                // Write handle, or a read handle while a write handle is live:
                // bypass cache and fetch directly from S3.
                $oResult = $oClient->getObject(['Bucket' => $sBucket, 'Key' => $sKey]);
                $sData   = self::_asString($oResult['Body']);
            }
        }

        if (!$bReadOnly) {
            // Invalidate any stale cached copy and register this write handle.
            self::_invalidateCache($sBucket, $sKey);
            self::_addWriteHandle($sBucket, $sKey);
        }

        $iHandle = self::$iNextHandle++;
        self::$aFileHandles[$iHandle] = [
            'data'     => $sData,
            'pos'      => 0,
            'key'      => $sKey,
            'bucket'   => $sBucket,
            'mapping'  => $aMapping,
            'dirty'    => false,
            'readonly' => (bool) $bReadOnly,
        ];

        $iEconetHandle = Vfs::getFreeFileHandleID($oUser);
        return new FileDescriptor(
            self::$oLogger,
            \HomeLan\FileStore\Vfs\Plugin\S3::class,
            $oUser,
            $sKey,
            $sFullPath,
            $iHandle,
            $iEconetHandle,
            $bExists,
            false,
            $bMustExist,
            $bReadOnly
        );
    }

    public static function _getAccessMode(int $iGid, int $iUid, int $iMode): string
    {
        return 'wr/wr';
    }

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

        $sPrefix = self::_econetDirToS3Prefix($sEconetPath, $aMapping);
        $sBucket = $aMapping['bucket'];
        $oClient = self::_getS3Client($aMapping);

        try {
            $oResult = $oClient->listObjectsV2([
                'Bucket'    => $sBucket,
                'Prefix'    => $sPrefix,
                'Delimiter' => '/',
            ]);
        } catch (S3Exception $e) {
            self::$oLogger->debug("S3: listObjectsV2 failed: " . $e->getMessage());
            return $aDirectoryListing;
        }

        $sAccess = self::_isMappingWritable($aMapping) ? 'wr/wr' : 'r/r';

        $mCommonPrefixes = $oResult['CommonPrefixes'] ?? [];
        foreach (is_array($mCommonPrefixes) ? $mCommonPrefixes : [] as $mCommonPrefix) {
            if (!is_array($mCommonPrefix) || !isset($mCommonPrefix['Prefix'])) {
                continue;
            }
            $sDirKey  = rtrim(self::_asString($mCommonPrefix['Prefix']), '/');
            $sDirName = basename($sDirKey);
            if (!array_key_exists($sDirName, $aDirectoryListing)) {
                $aDirectoryListing[$sDirName] = new DirectoryEntry(
                    $sDirName, $sDirName,
                    \HomeLan\FileStore\Vfs\Plugin\S3::class,
                    null, null, 0,
                    $sEconetPath . '.' . $sDirName,
                    time(), $sAccess, true
                );
            }
        }

        $mContents = $oResult['Contents'] ?? [];
        foreach (is_array($mContents) ? $mContents : [] as $mObject) {
            if (!is_array($mObject) || !isset($mObject['Key'])) {
                continue;
            }
            $sObjKey  = self::_asString($mObject['Key']);
            if ($sObjKey === $sPrefix) {
                continue;
            }
            $sFileName = basename($sObjKey);
            if (strtolower(substr($sFileName, -4)) === '.inf') {
                continue;
            }
            if (!array_key_exists($sFileName, $aDirectoryListing)) {
                $iSize  = self::_asInt($mObject['Size'] ?? 0);
                $iCTime = ($mObject['LastModified'] ?? null) instanceof \DateTimeInterface ? $mObject['LastModified']->getTimestamp() : time();
                $aDirectoryListing[$sFileName] = new DirectoryEntry(
                    str_replace('.', '/', $sFileName),
                    $sFileName,
                    \HomeLan\FileStore\Vfs\Plugin\S3::class,
                    null, null,
                    $iSize,
                    $sEconetPath . '.' . $sFileName,
                    $iCTime, $sAccess, false
                );
            }
            if (is_null($aDirectoryListing[$sFileName]->getExecAddr())) {
                try {
                    $oInf = $oClient->getObject(['Bucket' => $sBucket, 'Key' => $sObjKey . '.inf']);
                    $aMatches = [];
                    if (preg_match('/^TAPE file ([0-9a-fA-F]+) ([0-9a-fA-F]+)/', self::_asString($oInf['Body']), $aMatches) > 0) {
                        $aDirectoryListing[$sFileName]->setLoadAddr((int) hexdec($aMatches[1]));
                        $aDirectoryListing[$sFileName]->setExecAddr((int) hexdec($aMatches[2]));
                    }
                } catch (S3Exception) {
                    // No .inf sidecar is normal
                }
            }
        }

        return $aDirectoryListing;
    }

    public static function createDirectory(User $oUser, FilePath $oPath): bool
    {
        $sFullPath = $oPath->getFilePath();
        $aMapping  = self::_findMapping($sFullPath);
        if ($aMapping === null) {
            return false;
        }
        self::_assertMappingWritable($aMapping);
        $sKey    = self::_econetToS3Key($sFullPath, $aMapping) . '/';
        $sBucket = $aMapping['bucket'];
        $oClient = self::_getS3Client($aMapping);
        try {
            $oClient->putObject(['Bucket' => $sBucket, 'Key' => $sKey, 'Body' => '']);
            return true;
        } catch (S3Exception $e) {
            self::$oLogger->debug("S3: createDirectory failed: " . $e->getMessage());
            return false;
        }
    }

    public static function deleteFile(User $oUser, FilePath $oEconetPath): bool
    {
        $sFullPath = $oEconetPath->getFilePath();
        $aMapping  = self::_findMapping($sFullPath);
        if ($aMapping === null) {
            return false;
        }
        self::_assertMappingWritable($aMapping);
        $sKey    = self::_econetToS3Key($sFullPath, $aMapping);
        $sBucket = $aMapping['bucket'];
        $oClient = self::_getS3Client($aMapping);
        try {
            if (!$oClient->doesObjectExist($sBucket, $sKey)) {
                return false;
            }
            $oClient->deleteObject(['Bucket' => $sBucket, 'Key' => $sKey]);
            self::_invalidateCache($sBucket, $sKey);
            if ($oClient->doesObjectExist($sBucket, $sKey . '.inf')) {
                $oClient->deleteObject(['Bucket' => $sBucket, 'Key' => $sKey . '.inf']);
            }
            return true;
        } catch (S3Exception $e) {
            self::$oLogger->debug("S3: deleteFile failed: " . $e->getMessage());
            return false;
        }
    }

    public static function moveFile(User $oUser, FilePath $oEconetPathFrom, FilePath $oEconetPathTo): bool
    {
        $sFromPath  = $oEconetPathFrom->getFilePath();
        $sToPath    = $oEconetPathTo->getFilePath();
        $aMappingFrom = self::_findMapping($sFromPath);
        $aMappingTo   = self::_findMapping($sToPath);
        if ($aMappingFrom === null || $aMappingTo === null) {
            return false;
        }
        self::_assertMappingWritable($aMappingFrom);
        self::_assertMappingWritable($aMappingTo);
        $sFromKey     = self::_econetToS3Key($sFromPath, $aMappingFrom);
        $sToKey       = self::_econetToS3Key($sToPath, $aMappingTo);
        $sBucketFrom  = $aMappingFrom['bucket'];
        $sBucketTo    = $aMappingTo['bucket'];
        $oClient      = self::_getS3Client($aMappingFrom);
        try {
            $oClient->copyObject([
                'Bucket'     => $sBucketTo,
                'Key'        => $sToKey,
                'CopySource' => $sBucketFrom . '/' . $sFromKey,
            ]);
            $oClient->deleteObject(['Bucket' => $sBucketFrom, 'Key' => $sFromKey]);
            self::_invalidateCache($sBucketFrom, $sFromKey);
            self::_invalidateCache($sBucketTo, $sToKey);
            if ($oClient->doesObjectExist($sBucketFrom, $sFromKey . '.inf')) {
                $oClient->copyObject([
                    'Bucket'     => $sBucketTo,
                    'Key'        => $sToKey . '.inf',
                    'CopySource' => $sBucketFrom . '/' . $sFromKey . '.inf',
                ]);
                $oClient->deleteObject(['Bucket' => $sBucketFrom, 'Key' => $sFromKey . '.inf']);
            }
            return true;
        } catch (S3Exception $e) {
            self::$oLogger->debug("S3: moveFile failed: " . $e->getMessage());
            return false;
        }
    }

    public static function saveFile(User $oUser, FilePath $oEconetPath, string $sData, int $iLoadAddr, int $iExecAddr): bool
    {
        $sFullPath = $oEconetPath->getFilePath();
        $aMapping  = self::_findMapping($sFullPath);
        if ($aMapping === null) {
            return false;
        }
        self::_assertMappingWritable($aMapping);
        $sKey    = self::_econetToS3Key($sFullPath, $aMapping);
        $sBucket = $aMapping['bucket'];
        $oClient = self::_getS3Client($aMapping);
        try {
            $oClient->putObject(['Bucket' => $sBucket, 'Key' => $sKey, 'Body' => $sData]);
            self::_invalidateCache($sBucket, $sKey);
            $sInf = "TAPE file " . str_pad(dechex($iLoadAddr), 8, "0", STR_PAD_LEFT) . " " . str_pad(dechex($iExecAddr), 8, "0", STR_PAD_LEFT);
            $oClient->putObject(['Bucket' => $sBucket, 'Key' => $sKey . '.inf', 'Body' => $sInf]);
            return true;
        } catch (S3Exception $e) {
            self::$oLogger->debug("S3: saveFile failed: " . $e->getMessage());
            return false;
        }
    }

    public static function createFile(User $oUser, FilePath $oEconetPath, int $iSize, int $iLoadAddr, int $iExecAddr): bool
    {
        $sFullPath = $oEconetPath->getFilePath();
        $aMapping  = self::_findMapping($sFullPath);
        if ($aMapping === null) {
            return false;
        }
        self::_assertMappingWritable($aMapping);
        $sKey    = self::_econetToS3Key($sFullPath, $aMapping);
        $sBucket = $aMapping['bucket'];
        $oClient = self::_getS3Client($aMapping);
        try {
            $oClient->putObject(['Bucket' => $sBucket, 'Key' => $sKey, 'Body' => str_repeat("\x00", $iSize)]);
            self::_invalidateCache($sBucket, $sKey);
            $sInf = "TAPE file " . str_pad(dechex($iLoadAddr), 8, "0", STR_PAD_LEFT) . " " . str_pad(dechex($iExecAddr), 8, "0", STR_PAD_LEFT);
            $oClient->putObject(['Bucket' => $sBucket, 'Key' => $sKey . '.inf', 'Body' => $sInf]);
            return true;
        } catch (S3Exception $e) {
            self::$oLogger->debug("S3: createFile failed: " . $e->getMessage());
            return false;
        }
    }

    public static function getFile(User $oUser, FilePath $oEconetPath): string
    {
        $sFullPath = $oEconetPath->getFilePath();
        $aMapping  = self::_findMapping($sFullPath);
        if ($aMapping === null) {
            throw new VfsException("No S3 mapping for path " . $sFullPath);
        }
        $sKey    = self::_econetToS3Key($sFullPath, $aMapping);
        $sBucket = $aMapping['bucket'];
        $oClient = self::_getS3Client($aMapping);

        if (!self::_hasOpenWriteHandle($sBucket, $sKey)) {
            $sCached = self::_loadFromCache($sBucket, $sKey);
            if ($sCached !== null) {
                return $sCached;
            }
        }

        try {
            $oResult = $oClient->getObject(['Bucket' => $sBucket, 'Key' => $sKey]);
            $sData   = self::_asString($oResult['Body']);
            if (!self::_hasOpenWriteHandle($sBucket, $sKey)) {
                self::_saveToCache($sBucket, $sKey, $sData);
            }
            return $sData;
        } catch (S3Exception) {
            throw new VfsException("No such file: " . $sFullPath);
        }
    }

    public static function setMeta(string $sEconetPath, ?int $iLoad, ?int $iExec, ?int $iAccess): void
    {
        $aMapping = self::_findMapping($sEconetPath);
        if ($aMapping === null) {
            return;
        }
        self::_assertMappingWritable($aMapping);
        $sKey    = self::_econetToS3Key($sEconetPath, $aMapping);
        $sBucket = $aMapping['bucket'];
        $oClient = self::_getS3Client($aMapping);

        $aMeta = ['load' => 'ffff0000', 'exec' => 'ffff0000'];
        try {
            if ($oClient->doesObjectExist($sBucket, $sKey . '.inf')) {
                $oResult = $oClient->getObject(['Bucket' => $sBucket, 'Key' => $sKey . '.inf']);
                $aMatches = [];
                if (preg_match('/^TAPE file ([0-9a-fA-F]+) ([0-9a-fA-F]+)/', self::_asString($oResult['Body']), $aMatches) > 0) {
                    $aMeta = ['load' => $aMatches[1], 'exec' => $aMatches[2]];
                }
            }
        } catch (S3Exception $e) {
            // Use defaults
        }

        if (!is_null($iLoad)) {
            $aMeta['load'] = str_pad(dechex($iLoad), 8, '0', STR_PAD_LEFT);
        }
        if (!is_null($iExec)) {
            $aMeta['exec'] = str_pad(dechex($iExec), 8, '0', STR_PAD_LEFT);
        }

        try {
            $oClient->putObject([
                'Bucket' => $sBucket,
                'Key'    => $sKey . '.inf',
                'Body'   => "TAPE file " . $aMeta['load'] . " " . $aMeta['exec'],
            ]);
        } catch (S3Exception $e) {
            self::$oLogger->debug("S3: setMeta failed: " . $e->getMessage());
        }
    }

    // -------------------------------------------------------------------------
    // Handle-based I/O (in-memory buffer, flushed on close)
    // -------------------------------------------------------------------------

    public static function fsFtell(User $oUser, mixed $fLocalHandle): int
    {
        if (!is_int($fLocalHandle) || !isset(self::$aFileHandles[$fLocalHandle])) {
            throw new VfsException("Invalid file handle");
        }
        return self::$aFileHandles[$fLocalHandle]['pos'];
    }

    public static function fsFStat(User $oUser, mixed $fLocalHandle): array
    {
        if (!is_int($fLocalHandle) || !isset(self::$aFileHandles[$fLocalHandle])) {
            throw new VfsException("Invalid file handle");
        }
        $iSize = strlen(self::$aFileHandles[$fLocalHandle]['data']);
        return [
            'size' => $iSize,
            7      => $iSize,
        ];
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
        self::$aFileHandles[$fLocalHandle]['pos'] = (int) $iPos;
        return 0;
    }

    public static function read(User $oUser, mixed $fLocalHandle, int $iLength): string
    {
        if (!is_int($fLocalHandle) || !isset(self::$aFileHandles[$fLocalHandle])) {
            throw new VfsException("Invalid file handle");
        }
        $oHandle =& self::$aFileHandles[$fLocalHandle];
        $sChunk   = substr($oHandle['data'], $oHandle['pos'], $iLength);
        $oHandle['pos'] += strlen($sChunk);
        return $sChunk;
    }

    public static function write(User $oUser, mixed $fLocalHandle, string $sData): int
    {
        if (!is_int($fLocalHandle) || !isset(self::$aFileHandles[$fLocalHandle])) {
            throw new VfsException("Invalid file handle");
        }
        $oHandle =& self::$aFileHandles[$fLocalHandle];
        if ($oHandle['readonly']) {
            throw new VfsException("File handle is read-only", true);
        }
        $sData    = (string) $sData;
        $iPos     = $oHandle['pos'];
        $iDataLen = strlen($sData);
        if ($iPos > strlen($oHandle['data'])) {
            $oHandle['data'] = str_pad($oHandle['data'], $iPos, "\x00");
        }
        $oHandle['data']  = substr_replace($oHandle['data'], $sData, $iPos, $iDataLen);
        $oHandle['pos']  += $iDataLen;
        $oHandle['dirty'] = true;
        return $iDataLen;
    }

    public static function setExt(User $oUser, mixed $fLocalHandle, int $iExt): void
    {
        if (!is_int($fLocalHandle) || !isset(self::$aFileHandles[$fLocalHandle])) {
            throw new VfsException("Invalid file handle");
        }
        $oHandle =& self::$aFileHandles[$fLocalHandle];
        if ($oHandle['readonly']) {
            throw new VfsException("File handle is read-only", true);
        }
        $oHandle['data']  = substr($oHandle['data'], 0, $iExt);
        if (strlen($oHandle['data']) < $iExt) {
            $oHandle['data'] = str_pad($oHandle['data'], $iExt, "\x00");
        }
        $oHandle['dirty'] = true;
    }

    public static function fsLock(User $oUser, mixed $fLocalHandle, bool $bExclusive): void {}

    public static function fsUnlock(User $oUser, mixed $fLocalHandle): void {}

    public static function fsClose(User $oUser, mixed $fLocalHandle): bool
    {
        if (!is_int($fLocalHandle) || !isset(self::$aFileHandles[$fLocalHandle])) {
            return false;
        }
        $oHandle = self::$aFileHandles[$fLocalHandle];
        if ($oHandle['dirty'] && !$oHandle['readonly']) {
            $oClient = self::_getS3Client($oHandle['mapping']);
            try {
                $oClient->putObject([
                    'Bucket' => $oHandle['bucket'],
                    'Key'    => $oHandle['key'],
                    'Body'   => $oHandle['data'],
                ]);
                // Ensure no stale cache remains after a write-back.
                self::_invalidateCache($oHandle['bucket'], $oHandle['key']);
            } catch (S3Exception $e) {
                self::$oLogger->debug("S3: fsClose write-back failed: " . $e->getMessage());
            }
        }
        if (!$oHandle['readonly']) {
            self::_removeWriteHandle($oHandle['bucket'], $oHandle['key']);
        }
        unset(self::$aFileHandles[$fLocalHandle]);
        return true;
    }
}
