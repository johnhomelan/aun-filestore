<?php
namespace HomeLan\FileStore\Command;

include_once(__DIR__ . '/../../system.inc.php');

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Aws\S3\S3Client;
use Aws\S3\Exception\S3Exception;
use config;

/**
 * Uploads local files (with .inf sidecars) to a configured S3 VFS target.
 *
 * This utility writes directly to S3 via its own S3Client and therefore
 * bypasses the S3 VFS plugin's per-mapping write_enabled flag.  It always
 * has write access regardless of how the mapping is configured for the
 * file server.
 *
 * Source files follow the same .inf convention as the LocalFile VFS plugin:
 * each file DATA has an optional sidecar DATA.inf containing
 * "TAPE file LLLLLLLL EEEEEEEE" (hex load and exec addresses).
 *
 * Usage:
 *   s3upload --mapping=$.s3files --config=/etc/aun-filestored /local/path
*/
class S3Upload extends Command
{
    protected static $defaultName = 's3upload';
    protected static $defaultDescription = 'Upload local files to a configured S3 VFS target';

    protected function configure(): void
    {
        $this->setName('s3upload')
            ->setDescription('Upload local files (with .inf sidecars) to an S3 VFS mapping')
            ->addArgument('source', InputArgument::REQUIRED, 'Local directory or file to upload')
            ->addOption('mapping', 'm', InputOption::VALUE_REQUIRED, 'Econet VFS path of the S3 mapping to upload into (e.g. $.s3files)')
            ->addOption('config', 'c', InputOption::VALUE_OPTIONAL, 'Path to config directory', null)
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Show what would be uploaded without actually uploading');
    }

    protected function execute(InputInterface $oInput, OutputInterface $oOutput): int
    {
        if ($oInput->getOption('config') !== null) {
            safe_define('CONFIG_CONF_FILE_PATH', $oInput->getOption('config'));
        }

        $sMappingPath = $oInput->getOption('mapping');
        $sSource      = $oInput->getArgument('source');
        $bDryRun      = (bool) $oInput->getOption('dry-run');

        if (empty($sMappingPath)) {
            $oOutput->writeln('<error>--mapping is required</error>');
            return Command::FAILURE;
        }

        $sMappingsJson = config::getValue('vfs_plugin_s3_mappings');
        if (empty($sMappingsJson)) {
            $oOutput->writeln('<error>vfs_plugin_s3_mappings is not configured</error>');
            return Command::FAILURE;
        }

        $aMappings = json_decode($sMappingsJson, true) ?? [];
        $aMapping  = null;
        foreach ($aMappings as $aM) {
            if (rtrim($aM['econet_path'], '.') === rtrim($sMappingPath, '.')) {
                $aMapping = $aM;
                break;
            }
        }

        if ($aMapping === null) {
            $oOutput->writeln('<error>No S3 mapping found for: ' . $sMappingPath . '</error>');
            return Command::FAILURE;
        }

        $oClient = $this->_buildClient($aMapping);
        $sBucket = $aMapping['bucket'];
        $sPrefix = rtrim($aMapping['prefix'] ?? '', '/');

        if (!file_exists($sSource)) {
            $oOutput->writeln('<error>Source path does not exist: ' . $sSource . '</error>');
            return Command::FAILURE;
        }

        $aFiles = is_dir($sSource) ? $this->_scanDir($sSource) : [$sSource];
        $iUploaded = 0;

        foreach ($aFiles as $sLocalPath) {
            if (strtolower(substr($sLocalPath, -4)) === '.inf') {
                continue;
            }
            $sRelative = ltrim(substr($sLocalPath, strlen(rtrim($sSource, '/'))), '/');
            $sS3Key    = ($sPrefix !== '' ? $sPrefix . '/' : '') . str_replace(DIRECTORY_SEPARATOR, '/', $sRelative);

            $iLoadAddr = 0xFFFF0000;
            $iExecAddr = 0xFFFF0000;
            $sInfPath  = $sLocalPath . '.inf';
            if (file_exists($sInfPath)) {
                $sInf     = file_get_contents($sInfPath);
                $aMatches = [];
                if (preg_match('/^TAPE file ([0-9a-fA-F]+) ([0-9a-fA-F]+)/', $sInf, $aMatches) > 0) {
                    $iLoadAddr = hexdec($aMatches[1]);
                    $iExecAddr = hexdec($aMatches[2]);
                }
            }

            $sInfContent = "TAPE file " . str_pad(dechex($iLoadAddr), 8, "0", STR_PAD_LEFT)
                . " " . str_pad(dechex($iExecAddr), 8, "0", STR_PAD_LEFT);

            if ($bDryRun) {
                $oOutput->writeln('[dry-run] Would upload: ' . $sLocalPath . ' → s3://' . $sBucket . '/' . $sS3Key);
                $oOutput->writeln('[dry-run] Would upload: (inf) → s3://' . $sBucket . '/' . $sS3Key . '.inf');
                $iUploaded++;
                continue;
            }

            try {
                $oClient->putObject([
                    'Bucket'     => $sBucket,
                    'Key'        => $sS3Key,
                    'SourceFile' => $sLocalPath,
                ]);
                $oClient->putObject([
                    'Bucket' => $sBucket,
                    'Key'    => $sS3Key . '.inf',
                    'Body'   => $sInfContent,
                ]);
                $oOutput->writeln('Uploaded: ' . $sLocalPath . ' → s3://' . $sBucket . '/' . $sS3Key);
                $iUploaded++;
            } catch (S3Exception $e) {
                $oOutput->writeln('<error>Failed to upload ' . $sLocalPath . ': ' . $e->getMessage() . '</error>');
                return Command::FAILURE;
            }
        }

        $oOutput->writeln($iUploaded . ' file(s) ' . ($bDryRun ? 'would be ' : '') . 'uploaded.');
        return Command::SUCCESS;
    }

    protected function _buildClient(array $aMapping): S3Client
    {
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

    private function _scanDir(string $sDir): array
    {
        $aFiles  = [];
        $aItems  = scandir($sDir);
        foreach ($aItems as $sItem) {
            if ($sItem === '.' || $sItem === '..') {
                continue;
            }
            $sPath = $sDir . DIRECTORY_SEPARATOR . $sItem;
            if (is_dir($sPath)) {
                $aFiles = array_merge($aFiles, $this->_scanDir($sPath));
            } else {
                $aFiles[] = $sPath;
            }
        }
        return $aFiles;
    }
}
