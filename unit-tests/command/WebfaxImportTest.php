<?php

/*
 * Unit tests for HomeLan\FileStore\Command\WebfaxImport.
 *
 * Structured identically to TeefaxImportTest — WebfaxImport downloads a
 * tarball inside execute() via the protected _downloadTarball() method.
 * Tests override just that one method to return a real, small, locally
 * built .tar.gz fixture (via PharData) — no real network call is ever
 * made — while everything else (extraction, .tti conversion, atomic
 * staging-dir install) runs for real against a temp directory. The one
 * thing this suite adds over TeefaxImportTest is --service selection,
 * since Webfax 1 and Webfax 2 are independent sources with their own
 * channel/source config, unlike Teefax's single source.
 */

include_once(__DIR__ . '/../../src/include/system.inc.php');

use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;
use HomeLan\FileStore\Command\WebfaxImport;

// ---------------------------------------------------------------------------
// Testable subclass — replaces _downloadTarball() with a stub
// ---------------------------------------------------------------------------
class TestableWebfaxImport extends WebfaxImport
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
class WebfaxImportTest extends TestCase
{
    private string $sStoreDir;

    protected function setUp(): void
    {
        $this->sStoreDir = sys_get_temp_dir() . '/webfax_import_test_' . uniqid();
        mkdir($this->sStoreDir, 0755, true);

        config::overrideValue('teletext_store_dir', $this->sStoreDir);
        config::overrideValue('teletext_webfax_webfax1_channel', '9');
        config::overrideValue('teletext_webfax_webfax1_source', 'https://example.invalid/webfax1.tar.gz');
        config::overrideValue('teletext_webfax_webfax2_channel', '8');
        config::overrideValue('teletext_webfax_webfax2_source', 'https://example.invalid/webfax2.tar.gz');
    }

    protected function tearDown(): void
    {
        config::resetValue('teletext_store_dir');
        config::resetValue('teletext_webfax_webfax1_channel');
        config::resetValue('teletext_webfax_webfax1_source');
        config::resetValue('teletext_webfax_webfax2_channel');
        config::resetValue('teletext_webfax_webfax2_source');
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
        $sSrcDir = sys_get_temp_dir() . '/webfax_fixture_src_' . uniqid();
        mkdir($sSrcDir, 0755, true);
        foreach ($aFiles as $sName => $sContent) {
            $sPath = $sSrcDir . '/' . $sName;
            if (!is_dir(dirname($sPath))) {
                mkdir(dirname($sPath), 0755, true);
            }
            file_put_contents($sPath, $sContent);
        }

        $sTarPath = sys_get_temp_dir() . '/webfax_fixture_' . uniqid() . '.tar';
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

    private function _run(TestableWebfaxImport $oCommand, array $aArgs = []): CommandTester
    {
        $oTester = new CommandTester($oCommand);
        $oTester->execute($aArgs + ['--service' => 'webfax1']);
        return $oTester;
    }

    // -------------------------------------------------------------------------
    // Validation
    // -------------------------------------------------------------------------

    public function testFailsWhenNoServiceGiven(): void
    {
        $oTester = new CommandTester(new TestableWebfaxImport());
        $oTester->execute([]);

        $this->assertSame(1, $oTester->getStatusCode());
        $this->assertStringContainsString('service', $oTester->getDisplay());
    }

    public function testFailsWhenServiceIsUnknown(): void
    {
        $oTester = new CommandTester(new TestableWebfaxImport());
        $oTester->execute(['--service' => 'nonsense']);

        $this->assertSame(1, $oTester->getStatusCode());
        $this->assertStringContainsString('Unknown --service', $oTester->getDisplay());
    }

    public function testFailsWhenNoChannelConfigured(): void
    {
        config::overrideValue('teletext_webfax_webfax1_channel', '');
        $oTester = $this->_run(new TestableWebfaxImport());

        $this->assertSame(1, $oTester->getStatusCode());
        $this->assertStringContainsString('channel', $oTester->getDisplay());
    }

    public function testFailsWhenChannelIsNotASingleDigit(): void
    {
        config::overrideValue('teletext_webfax_webfax1_channel', '99');
        $oTester = $this->_run(new TestableWebfaxImport());
        $this->assertSame(1, $oTester->getStatusCode());
    }

    public function testFailsWhenNoSourceConfigured(): void
    {
        config::overrideValue('teletext_webfax_webfax1_source', '');
        $oTester = $this->_run(new TestableWebfaxImport());
        $this->assertSame(1, $oTester->getStatusCode());
        $this->assertStringContainsString('source', $oTester->getDisplay());
    }

    public function testChannelOptionOverridesConfig(): void
    {
        $oCommand = new TestableWebfaxImport();
        $oCommand->stubTarball = $this->_buildFixtureTarball(['P100.tti' => "PN,10001\r\nOL,0,HELLO\r\n"]);
        $this->_run($oCommand, ['--channel' => '5']);

        $this->assertFileExists($this->sStoreDir . '/5/100.dat');
        $this->assertFileDoesNotExist($this->sStoreDir . '/9/100.dat');
    }

    public function testSourceOptionOverridesConfigAndIsPassedToDownload(): void
    {
        $oCommand = new TestableWebfaxImport();
        $oCommand->stubTarball = $this->_buildFixtureTarball(['P100.tti' => "PN,10001\r\nOL,0,HELLO\r\n"]);
        $this->_run($oCommand, ['--source' => 'https://example.invalid/other.tar.gz']);

        $this->assertSame(['https://example.invalid/other.tar.gz'], $oCommand->capDownloadUrls);
    }

    // -------------------------------------------------------------------------
    // --service selects which config keys are used
    // -------------------------------------------------------------------------

    public function testWebfax2ServiceUsesItsOwnChannelAndSource(): void
    {
        $oCommand = new TestableWebfaxImport();
        $oCommand->stubTarball = $this->_buildFixtureTarball(['P100.tti' => "PN,10001\r\nOL,0,X\r\n"]);
        $oTester = new CommandTester($oCommand);
        $oTester->execute(['--service' => 'webfax2']);

        $this->assertSame(0, $oTester->getStatusCode());
        $this->assertSame(['https://example.invalid/webfax2.tar.gz'], $oCommand->capDownloadUrls);
        $this->assertFileExists($this->sStoreDir . '/8/100.dat');
        $this->assertFileDoesNotExist($this->sStoreDir . '/9/100.dat');
    }

    // -------------------------------------------------------------------------
    // Successful import
    // -------------------------------------------------------------------------

    public function testImportsASinglePage(): void
    {
        $oCommand = new TestableWebfaxImport();
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

    public function testImportsMultipleFilesAndSubpages(): void
    {
        $oCommand = new TestableWebfaxImport();
        $oCommand->stubTarball = $this->_buildFixtureTarball([
            'P100.tti' => "PN,10001\r\nOL,0,MAIN\r\n",
            'P150.tti' => "PN,15001\r\nOL,0,FIRST\r\nPN,15002\r\nOL,0,SECOND\r\n",
        ]);
        $this->_run($oCommand);

        $this->assertFileExists($this->sStoreDir . '/9/100.dat');
        $this->assertFileExists($this->sStoreDir . '/9/150.dat');
        $this->assertFileExists($this->sStoreDir . '/9/150_2.dat');
    }

    public function testWritesAnImportedMarkerFile(): void
    {
        $oCommand = new TestableWebfaxImport();
        $oCommand->stubTarball = $this->_buildFixtureTarball(['P100.tti' => "PN,10001\r\nOL,0,X\r\n"]);
        $this->_run($oCommand);

        $this->assertFileExists($this->sStoreDir . '/9/.imported');
    }

    public function testSummaryReportsPageAndFileCounts(): void
    {
        $oCommand = new TestableWebfaxImport();
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

        $oCommand = new TestableWebfaxImport();
        $oCommand->stubTarball = $this->_buildFixtureTarball(['P100.tti' => "PN,10001\r\nOL,0,NEW\r\n"]);
        $this->_run($oCommand);

        $this->assertFileDoesNotExist($this->sStoreDir . '/9/OLD.dat');
        $this->assertFileExists($this->sStoreDir . '/9/100.dat');
    }

    public function testNoStagingOrBackupDirectoriesAreLeftBehindAfterSuccess(): void
    {
        $oCommand = new TestableWebfaxImport();
        $oCommand->stubTarball = $this->_buildFixtureTarball(['P100.tti' => "PN,10001\r\nOL,0,X\r\n"]);
        $this->_run($oCommand);

        $this->assertDirectoryDoesNotExist($this->sStoreDir . '/.webfax-staging-9');
        $this->assertDirectoryDoesNotExist($this->sStoreDir . '/.webfax-old-9');
    }

    // -------------------------------------------------------------------------
    // Dry run
    // -------------------------------------------------------------------------

    public function testDryRunWritesNothing(): void
    {
        $oCommand = new TestableWebfaxImport();
        $oCommand->stubTarball = $this->_buildFixtureTarball(['P100.tti' => "PN,10001\r\nOL,0,X\r\n"]);
        $oTester = $this->_run($oCommand, ['--dry-run' => true]);

        $this->assertSame(0, $oTester->getStatusCode());
        $this->assertStringContainsString('[dry-run]', $oTester->getDisplay());
        $this->assertDirectoryDoesNotExist($this->sStoreDir . '/9');
    }

    // -------------------------------------------------------------------------
    // Download failure
    // -------------------------------------------------------------------------

    public function testDownloadFailureReturnsFailureExitCode(): void
    {
        $oCommand = new TestableWebfaxImport();
        $oCommand->throwOnDownload = new \RuntimeException('connection refused');
        $oTester = $this->_run($oCommand);

        $this->assertSame(1, $oTester->getStatusCode());
        $this->assertStringContainsString('connection refused', $oTester->getDisplay());
    }

    public function testDownloadFailureDoesNotTouchExistingChannelContent(): void
    {
        mkdir($this->sStoreDir . '/9', 0755, true);
        file_put_contents($this->sStoreDir . '/9/OLD.dat', str_repeat('Z', 1024));

        $oCommand = new TestableWebfaxImport();
        $oCommand->throwOnDownload = new \RuntimeException('boom');
        $this->_run($oCommand);

        $this->assertFileExists($this->sStoreDir . '/9/OLD.dat');
    }
}
