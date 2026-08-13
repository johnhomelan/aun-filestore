<?php
namespace HomeLan\FileStore\Command;

include_once(__DIR__ . '/../../system.inc.php');

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use HomeLan\FileStore\Services\Provider\Teletext\TeefaxTtiParser;
use config;

/**
 * Downloads the Teefax teletext archive (a GitHub mirror of the
 * teastop.plus.com SVN repository — see docs/protocols/teletext.md) and
 * converts every `.tti` page file it contains into this project's own
 * `{channel}/{page}.dat` / `{page}_{subpage}.dat` page store, ready for
 * the Teletext service provider to serve directly.
 *
 * Normally launched as a detached background process by Teletext's own
 * housekeeping check (see Teletext::checkTeefaxRefresh()) rather than run
 * by hand, but safe to run interactively too.
 *
 * Usage:
 *   teefax-import --config=/etc/aun-filestored
*/
class TeefaxImport extends Command
{
    protected static $defaultName = 'teefax-import';
    protected static $defaultDescription = 'Download and convert the Teefax teletext archive into a channel page store';

    protected function configure(): void
    {
        $this->setName('teefax-import')
            ->setDescription('Download and convert the Teefax teletext archive into a channel page store')
            ->addOption('config', 'c', InputOption::VALUE_OPTIONAL, 'Path to config directory', null)
            ->addOption('channel', null, InputOption::VALUE_OPTIONAL, 'Channel to import into (overrides teletext_teefax_channel)', null)
            ->addOption('source', null, InputOption::VALUE_OPTIONAL, 'Tarball URL to import from (overrides teletext_teefax_source)', null)
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Download and parse but do not write anything');
    }

    protected function execute(InputInterface $oInput, OutputInterface $oOutput): int
    {
        if ($oInput->getOption('config') !== null) {
            safe_define('CONFIG_CONF_FILE_PATH', $oInput->getOption('config'));
        }

        $sChannel  = (string) ($oInput->getOption('channel') ?? config::getValue('teletext_teefax_channel'));
        $sSource   = (string) ($oInput->getOption('source') ?? config::getValue('teletext_teefax_source'));
        $sStoreDir = (string) config::getValue('teletext_store_dir');
        $bDryRun   = (bool) $oInput->getOption('dry-run');

        if (!preg_match('/^[0-9]$/', $sChannel)) {
            $oOutput->writeln('<error>No valid channel configured (teletext_teefax_channel or --channel, must be a single digit 0-9)</error>');
            return Command::FAILURE;
        }
        if ($sSource === '') {
            $oOutput->writeln('<error>No source URL configured (teletext_teefax_source or --source)</error>');
            return Command::FAILURE;
        }

        $sWorkDir     = sys_get_temp_dir() . '/teefax-import-' . uniqid();
        $sTarballPath = $sWorkDir . '.tar.gz';
        $sStagingDir  = $sStoreDir . '/.teefax-staging-' . $sChannel;

        try {
            $oOutput->writeln('Downloading ' . $sSource . ' ...');
            $this->_putFileContents($sTarballPath, $this->_downloadTarball($sSource));

            $oOutput->writeln('Extracting ...');
            $this->_makeDir($sWorkDir);
            $this->_extractTarball($sTarballPath, $sWorkDir);

            $oOutput->writeln('Converting .tti files ...');
            $aTtiFiles = $this->_findTtiFiles($sWorkDir);
            $oParser   = new TeefaxTtiParser();

            $this->_deleteDir($sStagingDir);
            if (!$bDryRun) {
                $this->_makeDir($sStagingDir);
            }

            $iPagesWritten = 0;
            $iFilesSkipped = 0;
            foreach ($aTtiFiles as $sTtiPath) {
                $sContent = $this->_getFileContents($sTtiPath);
                $aPages   = $sContent === false ? [] : $oParser->parse($sContent);
                if (empty($aPages)) {
                    $iFilesSkipped++;
                    continue;
                }
                foreach ($aPages as $aPage) {
                    $sFilename = $aPage['subpage'] <= 1
                        ? $aPage['page'] . '.dat'
                        : $aPage['page'] . '_' . $aPage['subpage'] . '.dat';
                    if (!$bDryRun) {
                        $this->_putFileContents($sStagingDir . '/' . $sFilename, $aPage['buffer']);
                    }
                    $iPagesWritten++;
                }
            }

            if ($bDryRun) {
                $oOutput->writeln('[dry-run] Would write ' . $iPagesWritten . ' page(s) from ' . count($aTtiFiles) . ' file(s), ' . $iFilesSkipped . ' file(s) skipped.');
                return Command::SUCCESS;
            }

            $this->_putFileContents($sStagingDir . '/.imported', (string) time());

            $oOutput->writeln('Installing into ' . $sStoreDir . '/' . $sChannel . ' ...');
            $this->_installChannel($sStoreDir, $sChannel, $sStagingDir);

            $oOutput->writeln($iPagesWritten . ' page(s) imported from ' . count($aTtiFiles) . ' file(s), ' . $iFilesSkipped . ' file(s) skipped.');
            return Command::SUCCESS;
        } catch (\Throwable $e) {
            $oOutput->writeln('<error>Teefax import failed: ' . $e->getMessage() . '</error>');
            return Command::FAILURE;
        } finally {
            $this->_deleteFile($sTarballPath);
            $this->_deleteDir($sWorkDir);
            $this->_deleteDir($sStagingDir);
        }
    }

    /**
     * Atomically installs a fully-populated staging directory as the live
     * channel directory: the running Teletext provider only ever sees
     * either the old content or the new content, never a half-written mix
     * — `rename()` on a directory is atomic on the same filesystem.
    */
    protected function _installChannel(string $sStoreDir, string $sChannel, string $sStagingDir): void
    {
        $sLiveDir = $sStoreDir . '/' . $sChannel;
        $sOldDir  = $sStoreDir . '/.teefax-old-' . $sChannel;

        $this->_deleteDir($sOldDir);
        if ($this->_isDir($sLiveDir)) {
            $this->_renameDir($sLiveDir, $sOldDir);
        }
        $this->_renameDir($sStagingDir, $sLiveDir);
        $this->_deleteDir($sOldDir);
    }

    /**
     * @return array<int, string>
    */
    protected function _findTtiFiles(string $sDir): array
    {
        $aFiles = [];
        foreach ($this->_scanDir($sDir) as $sEntry) {
            $sPath = $sDir . '/' . $sEntry;
            if ($this->_isDir($sPath)) {
                $aFiles = array_merge($aFiles, $this->_findTtiFiles($sPath));
            } elseif (strtolower(substr($sEntry, -4)) === '.tti') {
                $aFiles[] = $sPath;
            }
        }
        return $aFiles;
    }

    // -------------------------------------------------------------------------
    // I/O wrappers — _downloadTarball() is the only one overridden in tests
    // (it's the sole genuinely-external part); everything else runs for
    // real against temp directories so extraction/conversion/atomic-swap
    // logic is exercised properly, matching S3Upload's _buildClient()
    // pattern.
    // -------------------------------------------------------------------------

    protected function _downloadTarball(string $sUrl): string
    {
        $rContext = stream_context_create([
            'http' => ['timeout' => 60, 'header' => 'User-Agent: aun-filestored teefax-import'],
        ]);
        $sData = file_get_contents($sUrl, false, $rContext);
        if ($sData === false) {
            throw new \RuntimeException('Failed to download ' . $sUrl);
        }
        return $sData;
    }

    protected function _extractTarball(string $sTarballPath, string $sDestDir): void
    {
        $oPhar = new \PharData($sTarballPath);
        $oPhar->extractTo($sDestDir, null, true);
    }

    /**
     * @return array<int, string>
    */
    protected function _scanDir(string $sPath): array
    {
        $aEntries = scandir($sPath);
        return $aEntries === false ? [] : array_values(array_diff($aEntries, ['.', '..']));
    }

    protected function _isDir(string $sPath): bool
    {
        return is_dir($sPath);
    }

    protected function _makeDir(string $sPath): void
    {
        if (!is_dir($sPath)) {
            mkdir($sPath, 0755, true);
        }
    }

    protected function _getFileContents(string $sPath): string|false
    {
        return file_get_contents($sPath);
    }

    protected function _putFileContents(string $sPath, string $sData): void
    {
        $this->_makeDir(dirname($sPath));
        file_put_contents($sPath, $sData);
    }

    protected function _renameDir(string $sFrom, string $sTo): void
    {
        rename($sFrom, $sTo);
    }

    protected function _deleteFile(string $sPath): void
    {
        if (file_exists($sPath)) {
            unlink($sPath);
        }
    }

    protected function _deleteDir(string $sPath): void
    {
        if (!is_dir($sPath)) {
            return;
        }
        foreach ($this->_scanDir($sPath) as $sEntry) {
            $sEntryPath = $sPath . '/' . $sEntry;
            if (is_dir($sEntryPath) && !is_link($sEntryPath)) {
                $this->_deleteDir($sEntryPath);
            } else {
                unlink($sEntryPath);
            }
        }
        rmdir($sPath);
    }
}
