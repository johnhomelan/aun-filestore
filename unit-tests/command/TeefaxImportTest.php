<?php

/*
 * Unit tests for HomeLan\FileStore\Command\TeefaxImport.
 *
 * TeefaxImport downloads a tarball inside execute() via the protected
 * _downloadTarball() method. Tests override just that one method to
 * return a real, small, locally-built .tar.gz fixture (via PharData) —
 * no real network call is ever made — while everything else (extraction,
 * .tti conversion, atomic staging-dir install) runs for real against a
 * temp directory, matching S3UploadTest's "override just the external
 * boundary" pattern.
 */

include_once(__DIR__ . '/../../src/include/system.inc.php');

use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;
use HomeLan\FileStore\Command\TeefaxImport;

// ---------------------------------------------------------------------------
// Testable subclass — replaces _downloadTarball() with a stub
// ---------------------------------------------------------------------------
class TestableTeefaxImport extends TeefaxImport
{
    public string $stubTarball = '';
    public ?\Exception $throwOnDownload = null;
    public array $capDownloadUrls = [];

    protected function _downloadTarball(string $sUrl): string
    {
        $this->capDownloadUrls[] = $sUrl;
        if ($this->throwOnDownload) {
            throw $this->throwOnDownload;
        }
        return $this->stubTarball;
    }
}

// ---------------------------------------------------------------------------
// Test suite
// ---------------------------------------------------------------------------
class TeefaxImportTest extends TestCase
{
    private string $sStoreDir;

    protected function setUp(): void
    {
        $this->sStoreDir = sys_get_temp_dir() . '/teefax_import_test_' . uniqid();
        mkdir($this->sStoreDir, 0755, true);

        config::overrideValue('teletext_store_dir', $this->sStoreDir);
        config::overrideValue('teletext_teefax_channel', '9');
        config::overrideValue('teletext_teefax_source', 'https://example.invalid/teefax.tar.gz');
    }

    protected function tearDown(): void
    {
        config::resetValue('teletext_store_dir');
        config::resetValue('teletext_teefax_channel');
        config::resetValue('teletext_teefax_source');
        $this->_deleteDir($this->sStoreDir);
    }

    private function _deleteDir(string $sDir): void
    {
        if (!is_dir($sDir)) {
            return;
        }
        $oIt = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($sDir, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($oIt as $oFile) {
            $oFile->isDir() ? rmdir($oFile->getRealPath()) : unlink($oFile->getRealPath());
        }
        rmdir($sDir);
    }

    /** @param array<string, string> $aFiles relative path => file content */
    private function _buildFixtureTarball(array $aFiles): string
    {
        $sSrcDir = sys_get_temp_dir() . '/teefax_fixture_src_' . uniqid();
        mkdir($sSrcDir, 0755, true);
        foreach ($aFiles as $sName => $sContent) {
            $sPath = $sSrcDir . '/' . $sName;
            if (!is_dir(dirname($sPath))) {
                mkdir(dirname($sPath), 0755, true);
            }
            file_put_contents($sPath, $sContent);
        }

        $sTarPath = sys_get_temp_dir() . '/teefax_fixture_' . uniqid() . '.tar';
        $oPhar = new \PharData($sTarPath);
        $oPhar->buildFromDirectory($sSrcDir);
        $oPhar->compress(\Phar::GZ);
        $sGzPath = $sTarPath . '.gz';
        $sData = file_get_contents($sGzPath);

        unlink($sTarPath);
        unlink($sGzPath);
        $this->_deleteDir($sSrcDir);

        return $sData;
    }

    private function _run(TestableTeefaxImport $oCommand, array $aArgs = []): CommandTester
    {
        $oTester = new CommandTester($oCommand);
        $oTester->execute($aArgs);
        return $oTester;
    }

    // -------------------------------------------------------------------------
    // Validation
    // -------------------------------------------------------------------------

    public function testFailsWhenNoChannelConfigured(): void
    {
        config::overrideValue('teletext_teefax_channel', '');
        $oTester = $this->_run(new TestableTeefaxImport());

        $this->assertSame(1, $oTester->getStatusCode());
        $this->assertStringContainsString('channel', $oTester->getDisplay());
    }

    public function testFailsWhenChannelIsNotASingleDigit(): void
    {
        config::overrideValue('teletext_teefax_channel', '99');
        $oTester = $this->_run(new TestableTeefaxImport());
        $this->assertSame(1, $oTester->getStatusCode());
    }

    public function testFailsWhenNoSourceConfigured(): void
    {
        config::overrideValue('teletext_teefax_source', '');
        $oTester = $this->_run(new TestableTeefaxImport());
        $this->assertSame(1, $oTester->getStatusCode());
        $this->assertStringContainsString('source', $oTester->getDisplay());
    }

    public function testChannelOptionOverridesConfig(): void
    {
        $oCommand = new TestableTeefaxImport();
        $oCommand->stubTarball = $this->_buildFixtureTarball(['P100.tti' => "PN,10001\r\nOL,0,HELLO\r\n"]);
        $this->_run($oCommand, ['--channel' => '5']);

        $this->assertFileExists($this->sStoreDir . '/5/100.dat');
        $this->assertFileDoesNotExist($this->sStoreDir . '/9/100.dat');
    }

    public function testSourceOptionOverridesConfigAndIsPassedToDownload(): void
    {
        $oCommand = new TestableTeefaxImport();
        $oCommand->stubTarball = $this->_buildFixtureTarball(['P100.tti' => "PN,10001\r\nOL,0,HELLO\r\n"]);
        $this->_run($oCommand, ['--source' => 'https://example.invalid/other.tar.gz']);

        $this->assertSame(['https://example.invalid/other.tar.gz'], $oCommand->capDownloadUrls);
    }

    // -------------------------------------------------------------------------
    // Successful import
    // -------------------------------------------------------------------------

    public function testImportsASinglePage(): void
    {
        $oCommand = new TestableTeefaxImport();
        $oCommand->stubTarball = $this->_buildFixtureTarball([
            'P100.tti' => "PN,10001\r\nOL,0," . str_repeat('A', 40) . "\r\n",
        ]);
        $oTester = $this->_run($oCommand);

        $this->assertSame(0, $oTester->getStatusCode());
        $this->assertFileExists($this->sStoreDir . '/9/100.dat');
        $sData = file_get_contents($this->sStoreDir . '/9/100.dat');
        $this->assertSame(1024, strlen($sData));
        $this->assertSame(str_repeat('A', 40), substr($sData, 0, 40));
    }

    public function testImportsFromNestedDirectoriesInsideTheTarball(): void
    {
        $oCommand = new TestableTeefaxImport();
        $oCommand->stubTarball = $this->_buildFixtureTarball([
            'archive-master/P100.tti' => "PN,10001\r\nOL,0,MAIN\r\n",
        ]);
        $this->_run($oCommand);

        $this->assertFileExists($this->sStoreDir . '/9/100.dat');
    }

    public function testImportsMultipleFilesAndSubpages(): void
    {
        $oCommand = new TestableTeefaxImport();
        $oCommand->stubTarball = $this->_buildFixtureTarball([
            'P100.tti'     => "PN,10001\r\nOL,0,MAIN\r\n",
            'sub/P150.tti' => "PN,15001\r\nOL,0,FIRST\r\nPN,15002\r\nOL,0,SECOND\r\n",
        ]);
        $this->_run($oCommand);

        $this->assertFileExists($this->sStoreDir . '/9/100.dat');
        $this->assertFileExists($this->sStoreDir . '/9/150.dat');
        $this->assertFileExists($this->sStoreDir . '/9/150_2.dat');
    }

    public function testHexPageNumbersAreImported(): void
    {
        $oCommand = new TestableTeefaxImport();
        $oCommand->stubTarball = $this->_buildFixtureTarball(['P1B0.tti' => "PN,1b001\r\nOL,0,X\r\n"]);
        $this->_run($oCommand);

        $this->assertFileExists($this->sStoreDir . '/9/1B0.dat');
    }

    public function testWritesAnImportedMarkerFile(): void
    {
        $oCommand = new TestableTeefaxImport();
        $oCommand->stubTarball = $this->_buildFixtureTarball(['P100.tti' => "PN,10001\r\nOL,0,X\r\n"]);
        $this->_run($oCommand);

        $this->assertFileExists($this->sStoreDir . '/9/.imported');
    }

    public function testNonTtiFilesAreIgnored(): void
    {
        $oCommand = new TestableTeefaxImport();
        $oCommand->stubTarball = $this->_buildFixtureTarball([
            'P100.tti'  => "PN,10001\r\nOL,0,X\r\n",
            'README.md' => 'not a page file',
        ]);
        $this->_run($oCommand);

        $aFiles = scandir($this->sStoreDir . '/9');
        $this->assertNotContains('README.md', $aFiles);
    }

    public function testUnparsableTtiFileIsSkippedAndCounted(): void
    {
        $oCommand = new TestableTeefaxImport();
        $oCommand->stubTarball = $this->_buildFixtureTarball([
            'P100.tti' => "PN,10001\r\nOL,0,X\r\n",
            'Pbad.tti' => "NOTATAGATALL\r\n",
        ]);
        $oTester = $this->_run($oCommand);
        $this->assertStringContainsString('1 file(s) skipped', $oTester->getDisplay());
    }

    public function testSummaryReportsPageAndFileCounts(): void
    {
        $oCommand = new TestableTeefaxImport();
        $oCommand->stubTarball = $this->_buildFixtureTarball([
            'P100.tti' => "PN,10001\r\nOL,0,X\r\n",
            'P150.tti' => "PN,15001\r\nOL,0,X\r\nPN,15002\r\nOL,0,X\r\n",
        ]);
        $oTester = $this->_run($oCommand);
        $this->assertStringContainsString('3 page(s) imported from 2 file(s)', $oTester->getDisplay());
    }

    // -------------------------------------------------------------------------
    // Atomic install replaces old content
    // -------------------------------------------------------------------------

    public function testReimportReplacesPreviousContent(): void
    {
        mkdir($this->sStoreDir . '/9', 0755, true);
        file_put_contents($this->sStoreDir . '/9/OLD.dat', str_repeat('Z', 1024));

        $oCommand = new TestableTeefaxImport();
        $oCommand->stubTarball = $this->_buildFixtureTarball(['P100.tti' => "PN,10001\r\nOL,0,NEW\r\n"]);
        $this->_run($oCommand);

        $this->assertFileDoesNotExist($this->sStoreDir . '/9/OLD.dat');
        $this->assertFileExists($this->sStoreDir . '/9/100.dat');
    }

    public function testOtherChannelsAreUntouchedByAnImport(): void
    {
        mkdir($this->sStoreDir . '/3', 0755, true);
        file_put_contents($this->sStoreDir . '/3/200.dat', str_repeat('K', 1024));

        $oCommand = new TestableTeefaxImport();
        $oCommand->stubTarball = $this->_buildFixtureTarball(['P100.tti' => "PN,10001\r\nOL,0,X\r\n"]);
        $this->_run($oCommand);

        $this->assertFileExists($this->sStoreDir . '/3/200.dat');
    }

    public function testNoStagingOrBackupDirectoriesAreLeftBehindAfterSuccess(): void
    {
        $oCommand = new TestableTeefaxImport();
        $oCommand->stubTarball = $this->_buildFixtureTarball(['P100.tti' => "PN,10001\r\nOL,0,X\r\n"]);
        $this->_run($oCommand);

        $this->assertDirectoryDoesNotExist($this->sStoreDir . '/.teefax-staging-9');
        $this->assertDirectoryDoesNotExist($this->sStoreDir . '/.teefax-old-9');
    }

    // -------------------------------------------------------------------------
    // Dry run
    // -------------------------------------------------------------------------

    public function testDryRunWritesNothing(): void
    {
        $oCommand = new TestableTeefaxImport();
        $oCommand->stubTarball = $this->_buildFixtureTarball(['P100.tti' => "PN,10001\r\nOL,0,X\r\n"]);
        $oTester = $this->_run($oCommand, ['--dry-run' => true]);

        $this->assertSame(0, $oTester->getStatusCode());
        $this->assertStringContainsString('[dry-run]', $oTester->getDisplay());
        $this->assertDirectoryDoesNotExist($this->sStoreDir . '/9');
    }

    public function testDryRunStillReportsAccurateCounts(): void
    {
        $oCommand = new TestableTeefaxImport();
        $oCommand->stubTarball = $this->_buildFixtureTarball([
            'P100.tti' => "PN,10001\r\nOL,0,X\r\n",
            'P150.tti' => "PN,15001\r\nOL,0,X\r\nPN,15002\r\nOL,0,X\r\n",
        ]);
        $oTester = $this->_run($oCommand, ['--dry-run' => true]);
        $this->assertStringContainsString('Would write 3 page(s) from 2 file(s)', $oTester->getDisplay());
    }

    // -------------------------------------------------------------------------
    // Download failure
    // -------------------------------------------------------------------------

    public function testDownloadFailureReturnsFailureExitCode(): void
    {
        $oCommand = new TestableTeefaxImport();
        $oCommand->throwOnDownload = new \RuntimeException('connection refused');
        $oTester = $this->_run($oCommand);

        $this->assertSame(1, $oTester->getStatusCode());
        $this->assertStringContainsString('connection refused', $oTester->getDisplay());
    }

    public function testDownloadFailureLeavesNoStagingDirectoryBehind(): void
    {
        $oCommand = new TestableTeefaxImport();
        $oCommand->throwOnDownload = new \RuntimeException('boom');
        $this->_run($oCommand);

        $this->assertDirectoryDoesNotExist($this->sStoreDir . '/.teefax-staging-9');
    }

    public function testDownloadFailureDoesNotTouchExistingChannelContent(): void
    {
        mkdir($this->sStoreDir . '/9', 0755, true);
        file_put_contents($this->sStoreDir . '/9/OLD.dat', str_repeat('Z', 1024));

        $oCommand = new TestableTeefaxImport();
        $oCommand->throwOnDownload = new \RuntimeException('boom');
        $this->_run($oCommand);

        $this->assertFileExists($this->sStoreDir . '/9/OLD.dat');
    }
}
