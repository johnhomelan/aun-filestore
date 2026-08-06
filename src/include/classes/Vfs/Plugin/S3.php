<?php
namespace HomeLan\FileStore\Vfs\Plugin;

use HomeLan\FileStore\Vfs\Exception as VfsException;
use HomeLan\FileStore\Vfs\Vfs;
use HomeLan\FileStore\Vfs\DirectoryEntry;
use HomeLan\FileStore\Vfs\FileDescriptor;
use HomeLan\FileStore\Vfs\FilePath;
use Aws\S3\S3Client;
use Aws\S3\Exception\S3Exception;
use config;

/**
 * S3 VFS plugin — stores Econet files as S3 objects with .inf sidecars.
 *
 * Multiple bucket/prefix mappings are supported.  Each mapping covers a
 * subtree of the Econet VFS.  Configuration is via the config:: system:
 *
 *   vfs_plugin_s3_mappings = JSON array of mapping objects (see README)
 *
 * File data is held in a per-handle in-memory buffer.  The buffer is
 * uploaded to S3 on fsClose() if the handle is dirty.
 *
 * @package corevfs
 * @author John Brown <john@home-lan.co.uk>
*/
class S3 implements PluginInterface {

    protected static \Psr\Log\LoggerInterface $oLogger;
    protected static bool $bMultiuser = false;
    protected static array $aMappings = [];
    protected static array $aFileHandles = [];
    protected static int $iNextHandle = 1;

    // Injected S3 clients for testing (keyed by mapping econet_path)
    protected static array $aOverrideClients = [];

    public static function init(\Psr\Log\LoggerInterface $oLogger, bool $bMultiuser = false): void
    {
        self::$oLogger = $oLogger;
        self::$bMultiuser = $bMultiuser;
        $sMappings = config::getValue('vfs_plugin_s3_mappings');
        if (!empty($sMappings)) {
            self::$aMappings = json_decode($sMappings, true) ?? [];
        }
    }

    public static function houseKeeping(): void {}

    /**
     * Inject a mock/stub S3Client for a specific mapping (used in tests).
     */
    public static function setS3Client(string $sEconetPath, mixed $oClient): void
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
    }

    // -------------------------------------------------------------------------
    // Write-access guard
    // -------------------------------------------------------------------------

    protected static function _isMappingWritable(array $aMapping): bool
    {
        return !empty($aMapping['write_enabled']);
    }

    protected static function _assertMappingWritable(array $aMapping): void
    {
        if (!self::_isMappingWritable($aMapping)) {
            throw new VfsException("S3 mapping '" . $aMapping['econet_path'] . "' is read-only", true);
        }
    }

    // -------------------------------------------------------------------------
    // Path helpers
    // -------------------------------------------------------------------------

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

    protected static function _econetToS3Key(string $sEconetPath, array $aMapping): string
    {
        $sMappingPath = rtrim($aMapping['econet_path'], '.');
        $sRelative = substr($sEconetPath, strlen($sMappingPath));
        $sRelative = ltrim($sRelative, '.');
        $sS3Relative = str_replace('.', '/', $sRelative);
        $sPrefix = rtrim($aMapping['prefix'] ?? '', '/');
        if ($sS3Relative === '') {
            return $sPrefix;
        }
        return ($sPrefix !== '' ? $sPrefix . '/' : '') . $sS3Relative;
    }

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

    protected static function _getS3Client(array $aMapping): object
    {
        if (isset(self::$aOverrideClients[$aMapping['econet_path']])) {
            return self::$aOverrideClients[$aMapping['econet_path']];
        }
        $aConfig = [
            'region'  => $aMapping['region'] ?? 'us-east-1',
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
    // PluginInterface implementation
    // -------------------------------------------------------------------------

    public static function _buildFiledescriptorFromEconetPath($oUser, FilePath $oEconetPath, $bMustExist, $bReadOnly): FileDescriptor
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
            $oResult = $oClient->getObject(['Bucket' => $sBucket, 'Key' => $sKey]);
            $sData = (string) $oResult['Body'];
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
            'HomeLan\FileStore\Vfs\Plugin\S3',
            $oUser,
            $sKey,
            $sFullPath,
            $iHandle,
            $iEconetHandle,
            $bExists,
            false
        );
    }

    public static function _getAccessMode($iGid, $iUid, $iMode): string
    {
        return 'wr/wr';
    }

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

        foreach ($oResult['CommonPrefixes'] ?? [] as $aCommonPrefix) {
            $sDirKey  = rtrim($aCommonPrefix['Prefix'], '/');
            $sDirName = basename($sDirKey);
            if (!array_key_exists($sDirName, $aDirectoryListing)) {
                $aDirectoryListing[$sDirName] = new DirectoryEntry(
                    $sDirName, $sDirName,
                    'HomeLan\FileStore\Vfs\Plugin\S3',
                    null, null, 0,
                    $sEconetPath . '.' . $sDirName,
                    time(), $sAccess, true
                );
            }
        }

        foreach ($oResult['Contents'] ?? [] as $aObject) {
            $sObjKey  = $aObject['Key'];
            if ($sObjKey === $sPrefix) {
                continue;
            }
            $sFileName = basename($sObjKey);
            if (strtolower(substr($sFileName, -4)) === '.inf') {
                continue;
            }
            if (!array_key_exists($sFileName, $aDirectoryListing)) {
                $iSize  = (int) ($aObject['Size'] ?? 0);
                $iCTime = isset($aObject['LastModified']) ? $aObject['LastModified']->getTimestamp() : time();
                $aDirectoryListing[$sFileName] = new DirectoryEntry(
                    str_replace('.', '/', $sFileName),
                    $sFileName,
                    'HomeLan\FileStore\Vfs\Plugin\S3',
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
                    if (preg_match('/^TAPE file ([0-9a-fA-F]+) ([0-9a-fA-F]+)/', (string) $oInf['Body'], $aMatches) > 0) {
                        $aDirectoryListing[$sFileName]->setLoadAddr(hexdec($aMatches[1]));
                        $aDirectoryListing[$sFileName]->setExecAddr(hexdec($aMatches[2]));
                    }
                } catch (S3Exception $e) {
                    // No .inf sidecar is normal
                }
            }
        }

        return $aDirectoryListing;
    }

    public static function createDirectory($oUser, FilePath $oPath): bool
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

    public static function deleteFile($oUser, FilePath $oEconetPath): bool
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
            if ($oClient->doesObjectExist($sBucket, $sKey . '.inf')) {
                $oClient->deleteObject(['Bucket' => $sBucket, 'Key' => $sKey . '.inf']);
            }
            return true;
        } catch (S3Exception $e) {
            self::$oLogger->debug("S3: deleteFile failed: " . $e->getMessage());
            return false;
        }
    }

    public static function moveFile($oUser, FilePath $oEconetPathFrom, FilePath $oEconetPathTo): bool
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

    public static function saveFile($oUser, FilePath $oEconetPath, string $sData, int $iLoadAddr, int $iExecAddr): bool
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
            $sInf = "TAPE file " . str_pad(dechex($iLoadAddr), 8, "0", STR_PAD_LEFT) . " " . str_pad(dechex($iExecAddr), 8, "0", STR_PAD_LEFT);
            $oClient->putObject(['Bucket' => $sBucket, 'Key' => $sKey . '.inf', 'Body' => $sInf]);
            return true;
        } catch (S3Exception $e) {
            self::$oLogger->debug("S3: saveFile failed: " . $e->getMessage());
            return false;
        }
    }

    public static function createFile($oUser, FilePath $oEconetPath, int $iSize, int $iLoadAddr, int $iExecAddr): bool
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
            $sInf = "TAPE file " . str_pad(dechex($iLoadAddr), 8, "0", STR_PAD_LEFT) . " " . str_pad(dechex($iExecAddr), 8, "0", STR_PAD_LEFT);
            $oClient->putObject(['Bucket' => $sBucket, 'Key' => $sKey . '.inf', 'Body' => $sInf]);
            return true;
        } catch (S3Exception $e) {
            self::$oLogger->debug("S3: createFile failed: " . $e->getMessage());
            return false;
        }
    }

    public static function getFile($oUser, FilePath $oEconetPath): string
    {
        $sFullPath = $oEconetPath->getFilePath();
        $aMapping  = self::_findMapping($sFullPath);
        if ($aMapping === null) {
            throw new VfsException("No S3 mapping for path " . $sFullPath);
        }
        $sKey    = self::_econetToS3Key($sFullPath, $aMapping);
        $sBucket = $aMapping['bucket'];
        $oClient = self::_getS3Client($aMapping);
        try {
            $oResult = $oClient->getObject(['Bucket' => $sBucket, 'Key' => $sKey]);
            return (string) $oResult['Body'];
        } catch (S3Exception $e) {
            throw new VfsException("No such file: " . $sFullPath);
        }
    }

    public static function setMeta(string $sEconetPath, ?int $iLoad, ?int $iExec, int $iAccess): void
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
                if (preg_match('/^TAPE file ([0-9a-fA-F]+) ([0-9a-fA-F]+)/', (string) $oResult['Body'], $aMatches) > 0) {
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

    public static function fsFtell($oUser, $fLocalHandle): int
    {
        if (!isset(self::$aFileHandles[$fLocalHandle])) {
            throw new VfsException("Invalid file handle");
        }
        return self::$aFileHandles[$fLocalHandle]['pos'];
    }

    public static function fsFStat($oUser, $fLocalHandle): array
    {
        if (!isset(self::$aFileHandles[$fLocalHandle])) {
            throw new VfsException("Invalid file handle");
        }
        $iSize = strlen(self::$aFileHandles[$fLocalHandle]['data']);
        return [
            'size' => $iSize,
            7      => $iSize,
        ];
    }

    public static function isEof($oUser, $fLocalHandle): bool
    {
        if (!isset(self::$aFileHandles[$fLocalHandle])) {
            return true;
        }
        $oHandle = self::$aFileHandles[$fLocalHandle];
        return $oHandle['pos'] >= strlen($oHandle['data']);
    }

    public static function setPos($oUser, $fLocalHandle, $iPos): int
    {
        if (!isset(self::$aFileHandles[$fLocalHandle])) {
            throw new VfsException("Invalid file handle");
        }
        self::$aFileHandles[$fLocalHandle]['pos'] = (int) $iPos;
        return 0;
    }

    public static function read($oUser, $fLocalHandle, $iLength): string
    {
        if (!isset(self::$aFileHandles[$fLocalHandle])) {
            throw new VfsException("Invalid file handle");
        }
        $oHandle =& self::$aFileHandles[$fLocalHandle];
        $sChunk   = substr($oHandle['data'], $oHandle['pos'], $iLength);
        $oHandle['pos'] += strlen($sChunk);
        return $sChunk;
    }

    public static function write($oUser, $fLocalHandle, $sData): int
    {
        if (!isset(self::$aFileHandles[$fLocalHandle])) {
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

    public static function fsClose($oUser, $fLocalHandle): bool
    {
        if (!isset(self::$aFileHandles[$fLocalHandle])) {
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
            } catch (S3Exception $e) {
                self::$oLogger->debug("S3: fsClose write-back failed: " . $e->getMessage());
            }
        }
        unset(self::$aFileHandles[$fLocalHandle]);
        return true;
    }
}
