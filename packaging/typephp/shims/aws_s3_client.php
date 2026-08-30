<?php

declare(strict_types=1);

/*
 * Compile-only stand-in for the two AWS SDK classes the S3 VFS plugin
 * (Vfs/Plugin/S3.php) references: `Aws\S3\S3Client` and
 * `Aws\S3\Exception\S3Exception`.
 *
 * `aws/aws-sdk-php` + `guzzlehttp/guzzle` are large and heavily dynamic and are
 * not on the compile path, so S3.php would transpile with those two symbols
 * unresolved (a run-time class lookup that fails the moment `S3` is enabled in
 * `vfs_plugins`). This file replaces them with a minimal native client written
 * against functions the tpc-linked libphp already has - `curl_*` for transport,
 * `hash` / `hash_hmac` for AWS Signature V4, `simplexml_load_string` for the
 * ListObjectsV2 response - so S3.php compiles and runs unchanged.
 *
 * Only the surface S3.php actually calls is implemented:
 *   doesObjectExist / getObject / putObject / deleteObject / copyObject /
 *   listObjectsV2, and a bare S3Exception (only getMessage() is ever used).
 * No pagination, multipart, streaming or presigning.
 *
 * Listed only in packaging/typephp/project*.yml `sources`, never autoloaded, so
 * a normal (interpreted) run uses the real AWS SDK and this file is never in
 * scope - same model as shims/ldap_classes.php.
 */

namespace Aws\S3\Exception {

    class S3Exception extends \RuntimeException
    {
    }
}

namespace Aws\S3 {

    use Aws\S3\Exception\S3Exception;

    final class S3Client
    {
        private string $sRegion;
        private string $sKey;
        private string $sSecret;
        private string $sEndpoint;   // '' => real AWS (virtual-hosted-style)
        private bool $bPathStyle;

        /** @param array<string,mixed> $aConfig */
        public function __construct(array $aConfig)
        {
            $this->sRegion   = self::_cfgStr($aConfig, 'region', 'us-east-1');
            $this->sEndpoint = \rtrim(self::_cfgStr($aConfig, 'endpoint', ''), '/');
            $this->bPathStyle = !empty($aConfig['use_path_style_endpoint']) || $this->sEndpoint !== '';

            $this->sKey    = '';
            $this->sSecret = '';
            if (isset($aConfig['credentials']) && \is_array($aConfig['credentials'])) {
                $this->sKey    = self::_cfgStr($aConfig['credentials'], 'key', '');
                $this->sSecret = self::_cfgStr($aConfig['credentials'], 'secret', '');
            }
        }

        public function doesObjectExist(string $sBucket, string $sKey): bool
        {
            $aResp = $this->_send('HEAD', $sBucket, $sKey, '', [], []);
            return $aResp['status'] === 200;
        }

        /**
         * @param array<string,mixed> $aArgs
         * @return array<string,mixed>
         */
        public function getObject(array $aArgs): array
        {
            $sBucket = self::_argStr($aArgs, 'Bucket');
            $sKey    = self::_argStr($aArgs, 'Key');
            $aResp   = $this->_send('GET', $sBucket, $sKey, '', [], []);
            $this->_assert2xx($aResp['status'], 'getObject', $sBucket . '/' . $sKey);
            return ['Body' => $aResp['body']];
        }

        /**
         * @param array<string,mixed> $aArgs
         * @return array<string,mixed>
         */
        public function putObject(array $aArgs): array
        {
            $sBucket = self::_argStr($aArgs, 'Bucket');
            $sKey    = self::_argStr($aArgs, 'Key');
            $sBody   = isset($aArgs['Body']) && \is_string($aArgs['Body']) ? $aArgs['Body'] : '';
            $aResp   = $this->_send('PUT', $sBucket, $sKey, $sBody, [], []);
            $this->_assert2xx($aResp['status'], 'putObject', $sBucket . '/' . $sKey);
            return [];
        }

        /**
         * @param array<string,mixed> $aArgs
         * @return array<string,mixed>
         */
        public function deleteObject(array $aArgs): array
        {
            $sBucket = self::_argStr($aArgs, 'Bucket');
            $sKey    = self::_argStr($aArgs, 'Key');
            $aResp   = $this->_send('DELETE', $sBucket, $sKey, '', [], []);
            if ($aResp['status'] !== 204) {
                $this->_assert2xx($aResp['status'], 'deleteObject', $sBucket . '/' . $sKey);
            }
            return [];
        }

        /**
         * @param array<string,mixed> $aArgs
         * @return array<string,mixed>
         */
        public function copyObject(array $aArgs): array
        {
            $sBucket = self::_argStr($aArgs, 'Bucket');
            $sKey    = self::_argStr($aArgs, 'Key');
            $sSource = self::_argStr($aArgs, 'CopySource');   // "bucket/key"
            if ($sSource === '' || $sSource[0] !== '/') {
                $sSource = '/' . $sSource;
            }
            $aResp = $this->_send('PUT', $sBucket, $sKey, '', ['x-amz-copy-source' => $sSource], []);
            $this->_assert2xx($aResp['status'], 'copyObject', $sSource . ' -> ' . $sBucket . '/' . $sKey);
            return [];
        }

        /**
         * @param array<string,mixed> $aArgs
         * @return array{Contents:array<int,array<string,mixed>>,CommonPrefixes:array<int,array<string,string>>}
         */
        public function listObjectsV2(array $aArgs): array
        {
            $sBucket = self::_argStr($aArgs, 'Bucket');
            $aQuery  = ['list-type' => '2'];
            $sPrefix = self::_argStr($aArgs, 'Prefix');
            if ($sPrefix !== '') {
                $aQuery['prefix'] = $sPrefix;
            }
            $sDelim = self::_argStr($aArgs, 'Delimiter');
            if ($sDelim !== '') {
                $aQuery['delimiter'] = $sDelim;
            }
            $aResp = $this->_send('GET', $sBucket, '', '', [], $aQuery);
            $this->_assert2xx($aResp['status'], 'listObjectsV2', $sBucket);
            return self::_parseListXml($aResp['body']);
        }

        // --- AWS Signature V4 + transport ---------------------------------

        /**
         * @param array<string,string> $aExtraHeaders  extra signed headers (name lower-case)
         * @param array<string,string> $aQuery         query params (raw, unsorted, unencoded)
         * @return array{status:int,body:string}
         */
        private function _send(string $sMethod, string $sBucket, string $sKey, string $sBody, array $aExtraHeaders, array $aQuery): array
        {
            $sScheme = 'https';
            if ($this->sEndpoint !== '') {
                $aUrl = \parse_url($this->sEndpoint);
                if (\is_array($aUrl)) {
                    if (isset($aUrl['scheme']) && \is_string($aUrl['scheme'])) {
                        $sScheme = $aUrl['scheme'];
                    }
                    $sHost = isset($aUrl['host']) && \is_string($aUrl['host']) ? $aUrl['host'] : $this->sEndpoint;
                    if (isset($aUrl['port'])) {
                        $sHost .= ':' . $aUrl['port'];
                    }
                } else {
                    $sHost = $this->sEndpoint;
                }
            } elseif ($this->bPathStyle) {
                $sHost = 's3.' . $this->sRegion . '.amazonaws.com';
            } else {
                $sHost = $sBucket . '.s3.' . $this->sRegion . '.amazonaws.com';
            }

            if ($this->sEndpoint !== '' || $this->bPathStyle) {
                $sCanonPath = '/' . $sBucket . ($sKey !== '' ? '/' . $sKey : '');
            } else {
                $sCanonPath = '/' . $sKey;
            }
            $sCanonUri = self::_encodePath($sCanonPath);

            $aQueryKeys = \array_keys($aQuery);
            \sort($aQueryKeys);
            $aQueryPairs = [];
            foreach ($aQueryKeys as $sQk) {
                $aQueryPairs[] = \rawurlencode($sQk) . '=' . \rawurlencode($aQuery[$sQk]);
            }
            $sCanonQuery = \implode('&', $aQueryPairs);

            $sAmzDate   = \gmdate('Ymd\THis\Z');
            $sDateStamp = \gmdate('Ymd');
            $sPayloadHash = \hash('sha256', $sBody);

            $aSigned = [
                'host'                 => $sHost,
                'x-amz-content-sha256' => $sPayloadHash,
                'x-amz-date'           => $sAmzDate,
            ];
            foreach ($aExtraHeaders as $sHn => $sHv) {
                $aSigned[\strtolower($sHn)] = $sHv;
            }
            \ksort($aSigned);

            $sCanonHeaders = '';
            $aSignedNames  = [];
            foreach ($aSigned as $sHn => $sHv) {
                $sCanonHeaders .= $sHn . ':' . \trim($sHv) . "\n";
                $aSignedNames[] = $sHn;
            }
            $sSignedHeaders = \implode(';', $aSignedNames);

            $sCanonRequest = $sMethod . "\n" . $sCanonUri . "\n" . $sCanonQuery . "\n"
                . $sCanonHeaders . "\n" . $sSignedHeaders . "\n" . $sPayloadHash;

            $sScope = $sDateStamp . '/' . $this->sRegion . '/s3/aws4_request';
            $sStringToSign = "AWS4-HMAC-SHA256\n" . $sAmzDate . "\n" . $sScope . "\n"
                . \hash('sha256', $sCanonRequest);

            $aCurlHeaders = [
                'Host: ' . $sHost,
                'x-amz-content-sha256: ' . $sPayloadHash,
                'x-amz-date: ' . $sAmzDate,
                'Expect:',
            ];
            foreach ($aExtraHeaders as $sHn => $sHv) {
                $aCurlHeaders[] = $sHn . ': ' . $sHv;
            }

            if ($this->sKey !== '' && $this->sSecret !== '') {
                $sSigningKey = \hash_hmac('sha256', 'aws4_request',
                    \hash_hmac('sha256', 's3',
                        \hash_hmac('sha256', $this->sRegion,
                            \hash_hmac('sha256', $sDateStamp, 'AWS4' . $this->sSecret, true),
                            true),
                        true),
                    true);
                $sSignature = \hash_hmac('sha256', $sStringToSign, $sSigningKey);
                $aCurlHeaders[] = 'Authorization: AWS4-HMAC-SHA256 '
                    . 'Credential=' . $this->sKey . '/' . $sScope . ', '
                    . 'SignedHeaders=' . $sSignedHeaders . ', '
                    . 'Signature=' . $sSignature;
            }

            $sUrl = $sScheme . '://' . $sHost . $sCanonUri;
            if ($sCanonQuery !== '') {
                $sUrl .= '?' . $sCanonQuery;
            }

            $oCh = \curl_init($sUrl);
            \curl_setopt($oCh, \CURLOPT_RETURNTRANSFER, true);
            \curl_setopt($oCh, \CURLOPT_HTTPHEADER, $aCurlHeaders);
            \curl_setopt($oCh, \CURLOPT_TIMEOUT, 30);
            \curl_setopt($oCh, \CURLOPT_CONNECTTIMEOUT, 10);
            if ($sMethod === 'HEAD') {
                \curl_setopt($oCh, \CURLOPT_NOBODY, true);
            } else {
                \curl_setopt($oCh, \CURLOPT_CUSTOMREQUEST, $sMethod);
            }
            if ($sMethod === 'PUT') {
                \curl_setopt($oCh, \CURLOPT_POSTFIELDS, $sBody);
            }

            $mResp   = \curl_exec($oCh);
            $iStatus = (int) \curl_getinfo($oCh, \CURLINFO_HTTP_CODE);
            \curl_close($oCh);

            if ($mResp === false || $iStatus === 0) {
                throw new S3Exception('S3 ' . $sMethod . ' ' . $sUrl . ': transport failure');
            }
            return ['status' => $iStatus, 'body' => \is_string($mResp) ? $mResp : ''];
        }

        private function _assert2xx(int $iStatus, string $sOp, string $sWhat): void
        {
            if ($iStatus < 200 || $iStatus >= 300) {
                throw new S3Exception($sOp . ' ' . $sWhat . ': HTTP ' . $iStatus);
            }
        }

        private static function _encodePath(string $sPath): string
        {
            $aParts = \explode('/', $sPath);
            foreach ($aParts as $iIdx => $sPart) {
                $aParts[$iIdx] = \rawurlencode($sPart);
            }
            return \implode('/', $aParts);
        }

        /**
         * @return array{Contents:array<int,array<string,mixed>>,CommonPrefixes:array<int,array<string,string>>}
         */
        private static function _parseListXml(string $sXml): array
        {
            $aContents = [];
            $aCommon   = [];

            // Drop namespace declarations so SimpleXML element access needs no
            // ns juggling (the response uses one default namespace).
            $sClean = \preg_replace('/\s+xmlns(:\w+)?="[^"]*"/', '', $sXml);
            $sUse   = \is_string($sClean) ? $sClean : $sXml;

            try {
                $oXml = \simplexml_load_string($sUse);
            } catch (\Throwable) {
                return ['Contents' => $aContents, 'CommonPrefixes' => $aCommon];
            }
            if ($oXml === false) {
                return ['Contents' => $aContents, 'CommonPrefixes' => $aCommon];
            }

            foreach ($oXml->Contents as $oEntry) {
                $aContents[] = [
                    'Key'          => (string) $oEntry->Key,
                    'Size'         => (int) $oEntry->Size,
                    'LastModified' => self::_parseDate((string) $oEntry->LastModified),
                ];
            }
            foreach ($oXml->CommonPrefixes as $oEntry) {
                $aCommon[] = ['Prefix' => (string) $oEntry->Prefix];
            }
            return ['Contents' => $aContents, 'CommonPrefixes' => $aCommon];
        }

        private static function _parseDate(string $sRaw): ?\DateTimeImmutable
        {
            if ($sRaw === '') {
                return null;
            }
            try {
                return new \DateTimeImmutable($sRaw);
            } catch (\Throwable) {
                return null;
            }
        }

        /**
         * @param array<string,mixed> $aArr
         */
        private static function _argStr(array $aArr, string $sKey): string
        {
            return isset($aArr[$sKey]) && \is_scalar($aArr[$sKey]) ? (string) $aArr[$sKey] : '';
        }

        /**
         * @param array<string,mixed> $aArr
         */
        private static function _cfgStr(array $aArr, string $sKey, string $sDefault): string
        {
            return isset($aArr[$sKey]) && \is_string($aArr[$sKey]) ? $aArr[$sKey] : $sDefault;
        }
    }
}
