<?php
namespace HomeLan\FileStore\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Builds a tar archive from a local directory, including an index.json catalogue
 * file compatible with the Catalogue VFS plugin.
 *
 * The archive contains all files in the supplied directory (excluding .inf sidecars)
 * plus an index.json at the archive root that lists every file with its:
 *   - version number
 *   - md5sum
 *   - load and exec addresses (read from .inf sidecars)
 *   - size in bytes
 *   - url (relative to index.json — use the filename/subpath as-is)
 *
 * If an existing tar is supplied via --existing-tar, the index.json from it is used
 * to carry version numbers forward:
 *   - Same md5 as previous → same version
 *   - Different md5 → version incremented by 1
 *   - New file (not in previous index) → version = 1
 *
 * Usage:
 *   mkcatarchive [--output=<path>] [--existing-tar=<path>] <source>
 */
class MakeCatalogueArchive extends Command
{
    protected static $defaultName = 'mkcatarchive';
    protected static $defaultDescription = 'Build a Catalogue VFS tar archive with index.json';

    protected function configure(): void
    {
        $this->setName('mkcatarchive')
            ->setDescription('Build a tar archive with an index.json catalogue for use with the Catalogue VFS plugin')
            ->addArgument('source', InputArgument::REQUIRED, 'Path to the source directory')
            ->addOption('output', 'o', InputOption::VALUE_REQUIRED, 'Output tar file path (default: <dirname>.tar)')
            ->addOption('existing-tar', 'e', InputOption::VALUE_REQUIRED, 'Existing tar to read previous version numbers from');
    }

    protected function execute(InputInterface $oInput, OutputInterface $oOutput): int
    {
        $sSource = rtrim((string) $oInput->getArgument('source'), DIRECTORY_SEPARATOR);
        if (!is_dir($sSource)) {
            $oOutput->writeln('<error>Source path is not a directory: ' . $sSource . '</error>');
            return Command::FAILURE;
        }

        $sOutputPath  = $oInput->getOption('output') ?? (basename($sSource) . '.tar');
        $sExistingTar = $oInput->getOption('existing-tar');

        // Load previous index for version tracking.
        $aOldFiles = [];
        if ($sExistingTar !== null) {
            $aOldFiles = $this->_loadOldIndex($sExistingTar, $oOutput);
        }

        // Discover all non-.inf files in the source tree.
        $aSourceFiles = $this->_scanDir($sSource);

        if (count($aSourceFiles) === 0) {
            $oOutput->writeln('<comment>No files found in: ' . $sSource . '</comment>');
        }

        // Build the catalogue entries and collect tar content.
        $aCatalogueFiles = [];
        foreach ($aSourceFiles as $sLocalPath) {
            $sRelPath    = ltrim(substr($sLocalPath, strlen($sSource)), DIRECTORY_SEPARATOR);
            $sEconetKey  = str_replace(DIRECTORY_SEPARATOR, '.', $sRelPath);
            $sUrl        = str_replace(DIRECTORY_SEPARATOR, '/', $sRelPath);
            [$iLoad, $iExec] = $this->_readInf($sLocalPath);
            $sMd5        = (string) md5_file($sLocalPath);
            $sVersion    = $this->_determineVersion($sEconetKey, $sMd5, $aOldFiles);

            $aCatalogueFiles[$sEconetKey] = [
                'version' => $sVersion,
                'md5sum'  => $sMd5,
                'load'    => $iLoad,
                'exec'    => $iExec,
                'size'    => (int) filesize($sLocalPath),
                'url'     => $sUrl,
            ];
        }

        $sIndexJson = json_encode(['files' => $aCatalogueFiles], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";

        // Create the tar archive.
        if (file_exists($sOutputPath)) {
            unlink($sOutputPath);
        }

        try {
            $oTar = new \PharData($sOutputPath);
            foreach ($aSourceFiles as $sLocalPath) {
                $sRelPath    = ltrim(substr($sLocalPath, strlen($sSource)), DIRECTORY_SEPARATOR);
                $sArchivePath = str_replace(DIRECTORY_SEPARATOR, '/', $sRelPath);
                $oTar->addFile($sLocalPath, $sArchivePath);
                $oOutput->writeln('  adding: ' . $sArchivePath);
            }
            $oTar->addFromString('index.json', $sIndexJson);
            $oOutput->writeln('  adding: index.json');
        } catch (\Exception $e) {
            $oOutput->writeln('<error>Failed to create archive: ' . $e->getMessage() . '</error>');
            return Command::FAILURE;
        }

        $oOutput->writeln('Created ' . $sOutputPath . ' (' . count($aCatalogueFiles) . ' file(s)).');
        return Command::SUCCESS;
    }

    // -------------------------------------------------------------------------
    // Internal helpers
    // -------------------------------------------------------------------------

    private function _loadOldIndex(string $sTarPath, OutputInterface $oOutput): array
    {
        if (!file_exists($sTarPath)) {
            $oOutput->writeln('<comment>Existing tar not found: ' . $sTarPath . ' — starting fresh</comment>');
            return [];
        }

        $sTempDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'mkcatarchive_' . uniqid();
        mkdir($sTempDir, 0700, true);

        try {
            $oTar = new \PharData($sTarPath);
            $oTar->extractTo($sTempDir, 'index.json', true);
        } catch (\Exception $e) {
            $oOutput->writeln('<comment>Could not read index.json from existing tar: ' . $e->getMessage() . ' — starting fresh</comment>');
            $this->_rmDir($sTempDir);
            return [];
        }

        $sIndexPath = $sTempDir . DIRECTORY_SEPARATOR . 'index.json';
        $aData = [];
        if (file_exists($sIndexPath)) {
            $aData = json_decode((string) file_get_contents($sIndexPath), true) ?? [];
        } else {
            $oOutput->writeln('<comment>No index.json in existing tar — starting fresh</comment>');
        }

        $this->_rmDir($sTempDir);
        return $aData['files'] ?? [];
    }

    private function _determineVersion(string $sEconetKey, string $sMd5, array $aOldFiles): int
    {
        if (!isset($aOldFiles[$sEconetKey])) {
            return 1;
        }
        $aOld = $aOldFiles[$sEconetKey];
        if (($aOld['md5sum'] ?? '') === $sMd5) {
            return max(1, (int) ($aOld['version'] ?? 1));
        }
        return max(1, (int) ($aOld['version'] ?? 0)) + 1;
    }

    private function _readInf(string $sFilePath): array
    {
        $sInfPath  = $sFilePath . '.inf';
        $iLoad     = 0xFFFF0000;
        $iExec     = 0xFFFF0000;
        if (file_exists($sInfPath)) {
            $aMatches = [];
            if (preg_match('/^TAPE file ([0-9a-fA-F]+) ([0-9a-fA-F]+)/i', (string) file_get_contents($sInfPath), $aMatches)) {
                $iLoad = (int) hexdec($aMatches[1]);
                $iExec = (int) hexdec($aMatches[2]);
            }
        }
        return [$iLoad, $iExec];
    }

    private function _scanDir(string $sDir): array
    {
        $aFiles = [];
        foreach (scandir($sDir) as $sItem) {
            if ($sItem === '.' || $sItem === '..') {
                continue;
            }
            $sPath = $sDir . DIRECTORY_SEPARATOR . $sItem;
            if (is_dir($sPath)) {
                $aFiles = array_merge($aFiles, $this->_scanDir($sPath));
            } elseif (strtolower(substr($sItem, -4)) !== '.inf') {
                $aFiles[] = $sPath;
            }
        }
        return $aFiles;
    }

    private function _rmDir(string $sDir): void
    {
        if (!is_dir($sDir)) {
            return;
        }
        $oIt = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($sDir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($oIt as $oItem) {
            $oItem->isDir() ? rmdir($oItem->getRealPath()) : unlink($oItem->getRealPath());
        }
        rmdir($sDir);
    }
}
