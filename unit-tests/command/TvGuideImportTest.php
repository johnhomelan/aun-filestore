<?php

/*
 * Unit tests for HomeLan\FileStore\Command\TvGuideImport.
 *
 * TvGuideImport downloads a 2-day EPG grid from TVHeadend inside execute()
 * via the protected _downloadFeed() method. Tests override just that
 * method to return fixture strings — no real network call is ever made —
 * while everything else (parsing, grouping, composing, atomic staging-dir
 * install) runs for real against a temp directory, matching
 * WeatherImportTest's "override just the external boundary" pattern.
 * _downloadFeed() itself is stubbed away, so the Basic Auth header
 * construction (_httpHeaders()) is instead tested directly.
 */

include_once(__DIR__ . '/../../src/include/system.inc.php');

use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;
use HomeLan\FileStore\Command\TvGuideImport;
use HomeLan\FileStore\Services\Provider\Teletext\TvGuideChannels;

// ---------------------------------------------------------------------------
// Testable subclass — replaces _downloadFeed() with a stub, and now() with a
// fixed date so the requested EPG grid URL (which embeds today's midnight
// timestamp) is deterministic and can be registered as a fixture key.
// ---------------------------------------------------------------------------
class TestableTvGuideImport extends TvGuideImport
{
    public \DateTimeImmutable $stubNow;
    /** @var array<string, string|\Throwable> */
    public array $feedFixtures = [];
    public array $capDownloadUrls = [];

    public function __construct()
    {
        parent::__construct();
        $this->stubNow = new \DateTimeImmutable('2026-08-26 09:00:00', new \DateTimeZone('UTC'));
    }

    protected function now(): \DateTimeImmutable
    {
        return $this->stubNow;
    }

    protected function _downloadFeed(string $sUrl): string
    {
        $this->capDownloadUrls[] = $sUrl;
        $mFixture = $this->feedFixtures[$sUrl] ?? null;
        if ($mFixture instanceof \Throwable) {
            throw $mFixture;
        }
        if ($mFixture === null) {
            throw new \RuntimeException('No fixture registered for ' . $sUrl);
        }
        return $mFixture;
    }
}

// ---------------------------------------------------------------------------
// Test suite
// ---------------------------------------------------------------------------
class TvGuideImportTest extends TestCase
{
    private string $sStoreDir;

    protected function setUp(): void
    {
        $this->sStoreDir = sys_get_temp_dir() . '/tvguide_import_test_' . uniqid();
        mkdir($this->sStoreDir, 0755, true);

        config::overrideValue('teletext_store_dir', $this->sStoreDir);
        config::overrideValue('teletext_tvguide_channel', '4');
        config::overrideValue('teletext_tvguide_index_page', 700);
        config::overrideValue('teletext_tvguide_source', 'http://tvheadend.local:9981');
        config::overrideValue('teletext_tvguide_username', '');
        config::overrideValue('teletext_tvguide_password', '');
    }

    protected function tearDown(): void
    {
        config::resetValue('teletext_tvguide_channel');
        config::resetValue('teletext_tvguide_index_page');
        config::resetValue('teletext_tvguide_source');
        config::resetValue('teletext_tvguide_username');
        config::resetValue('teletext_tvguide_password');
        config::resetValue('teletext_store_dir');
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

    private function _expectedUrl(TestableTvGuideImport $oCommand): string
    {
        $iTodayStart = $oCommand->stubNow->setTime(0, 0, 0)->getTimestamp();
        return 'http://tvheadend.local:9981/api/epg/events/grid?start=' . $iTodayStart . '&limit=10000&sort=start&dir=ASC';
    }

    /** @param array<int, array<string, mixed>> $aEntries */
    private function _gridJson(array $aEntries): string
    {
        return json_encode(['entries' => $aEntries, 'totalCount' => count($aEntries)]);
    }

    private function _run(TestableTvGuideImport $oCommand, array $aArgs = []): CommandTester
    {
        $oTester = new CommandTester($oCommand);
        $oTester->execute($aArgs);
        return $oTester;
    }

    private function _commandWithEmptyGrid(): TestableTvGuideImport
    {
        $oCommand = new TestableTvGuideImport();
        $oCommand->feedFixtures[$this->_expectedUrl($oCommand)] = $this->_gridJson([]);
        return $oCommand;
    }

    // -------------------------------------------------------------------------
    // Channel resolution
    // -------------------------------------------------------------------------

    public function testFailsWhenNoValidChannelConfigured(): void
    {
        config::overrideValue('teletext_tvguide_channel', 'x');
        $oCommand = $this->_commandWithEmptyGrid();

        $oTester = $this->_run($oCommand);

        $this->assertSame(1, $oTester->getStatusCode());
        $this->assertStringContainsString('No valid channel configured', $oTester->getDisplay());
    }

    public function testChannelOptionOverridesConfig(): void
    {
        $oCommand = $this->_commandWithEmptyGrid();

        $this->_run($oCommand, ['--channel' => '9']);

        $this->assertFileExists($this->sStoreDir . '/9/700.dat');
        $this->assertFileDoesNotExist($this->sStoreDir . '/4/700.dat');
    }

    // -------------------------------------------------------------------------
    // Index page resolution
    // -------------------------------------------------------------------------

    public function testFailsWhenIndexPageIsOutOfRange(): void
    {
        config::overrideValue('teletext_tvguide_index_page', 50);
        $oCommand = $this->_commandWithEmptyGrid();

        $oTester = $this->_run($oCommand);

        $this->assertSame(1, $oTester->getStatusCode());
        $this->assertStringContainsString('No valid index page configured', $oTester->getDisplay());
    }

    public function testIndexPageOptionOverridesConfig(): void
    {
        $oCommand = $this->_commandWithEmptyGrid();

        $this->_run($oCommand, ['--index-page' => '300']);

        $this->assertFileExists($this->sStoreDir . '/4/300.dat');
        $this->assertFileExists($this->sStoreDir . '/4/301.dat');
        $this->assertFileDoesNotExist($this->sStoreDir . '/4/700.dat');
    }

    // -------------------------------------------------------------------------
    // Source resolution
    // -------------------------------------------------------------------------

    public function testFailsWhenSourceIsNotConfigured(): void
    {
        config::overrideValue('teletext_tvguide_source', '');
        $oCommand = new TestableTvGuideImport();

        $oTester = $this->_run($oCommand);

        $this->assertSame(1, $oTester->getStatusCode());
        $this->assertStringContainsString('No source configured', $oTester->getDisplay());
    }

    public function testRequestsTheExpectedEpgGridUrl(): void
    {
        $oCommand = $this->_commandWithEmptyGrid();

        $this->_run($oCommand);

        $this->assertSame([$this->_expectedUrl($oCommand)], $oCommand->capDownloadUrls);
    }

    // -------------------------------------------------------------------------
    // Successful import
    // -------------------------------------------------------------------------

    public function testImportsIndexAndOnePagePerChannel(): void
    {
        $oCommand = $this->_commandWithEmptyGrid();

        $oTester = $this->_run($oCommand);

        $this->assertSame(0, $oTester->getStatusCode());
        $iChannelCount = count(TvGuideChannels::all());
        $this->assertFileExists($this->sStoreDir . '/4/700.dat');
        for ($i = 0; $i < $iChannelCount; $i++) {
            $this->assertFileExists($this->sStoreDir . '/4/' . (701 + $i) . '.dat');
        }
    }

    public function testChannelWithMatchingEventsShowsThemOnItsPage(): void
    {
        $oCommand = new TestableTvGuideImport();
        $iTodayStart = $oCommand->stubNow->setTime(0, 0, 0)->getTimestamp();
        $oCommand->feedFixtures[$this->_expectedUrl($oCommand)] = $this->_gridJson([
            ['channelNumber' => 1, 'start' => $iTodayStart + 3600, 'stop' => $iTodayStart + 5400, 'title' => 'Breakfast Show'],
        ]);

        $this->_run($oCommand);

        // BBC One is TvGuideChannels' first entry (LCN 1), so its page is
        // the first one after the index (701).
        $sPagePlain = preg_replace('/[\x00-\x1f]/', '', file_get_contents($this->sStoreDir . '/4/701.dat'));
        $this->assertStringContainsString('Breakfast Show', $sPagePlain);
    }

    public function testIndexListsEveryChannelLabel(): void
    {
        $oCommand = $this->_commandWithEmptyGrid();

        $this->_run($oCommand);

        $sIndexPlain = preg_replace('/[\x00-\x1f]/', '', file_get_contents($this->sStoreDir . '/4/700.dat'));
        $sIndexSubpage2 = $this->sStoreDir . '/4/700_2.dat';
        if (file_exists($sIndexSubpage2)) {
            $sIndexPlain .= preg_replace('/[\x00-\x1f]/', '', file_get_contents($sIndexSubpage2));
        }
        foreach (TvGuideChannels::all() as $oChannel) {
            $this->assertStringContainsString($oChannel->sLabel, $sIndexPlain);
        }
    }

    public function testWholeRunFailsWhenTheSharedFetchFails(): void
    {
        $oCommand = new TestableTvGuideImport();
        $oCommand->feedFixtures[$this->_expectedUrl($oCommand)] = new \RuntimeException('connection reset');

        $oTester = $this->_run($oCommand);

        $this->assertSame(1, $oTester->getStatusCode());
        $this->assertStringContainsString('connection reset', $oTester->getDisplay());
        $this->assertFileDoesNotExist($this->sStoreDir . '/4/700.dat');
    }

    public function testDryRunWritesNothing(): void
    {
        $oCommand = $this->_commandWithEmptyGrid();

        $oTester = $this->_run($oCommand, ['--dry-run' => true]);

        $this->assertSame(0, $oTester->getStatusCode());
        $this->assertStringContainsString('[dry-run]', $oTester->getDisplay());
        $this->assertFileDoesNotExist($this->sStoreDir . '/4');
    }

    public function testWritesAnImportedMarkerFile(): void
    {
        $oCommand = $this->_commandWithEmptyGrid();

        $iBefore = time();
        $this->_run($oCommand);

        $this->assertFileExists($this->sStoreDir . '/4/.imported');
        $this->assertGreaterThanOrEqual($iBefore, (int) trim(file_get_contents($this->sStoreDir . '/4/.imported')));
    }

    public function testInstallOverwritesAPageThisRunRegenerates(): void
    {
        mkdir($this->sStoreDir . '/4', 0755, true);
        file_put_contents($this->sStoreDir . '/4/701.dat', 'stale page');

        $oCommand = $this->_commandWithEmptyGrid();
        $this->_run($oCommand);

        $sPage = file_get_contents($this->sStoreDir . '/4/701.dat');
        $this->assertNotSame('stale page', $sPage);
    }

    public function testInstallDoesNotDeleteAPageThisRunDoesNotRegenerate(): void
    {
        mkdir($this->sStoreDir . '/4', 0755, true);
        file_put_contents($this->sStoreDir . '/4/999.dat', 'stale page');

        $oCommand = $this->_commandWithEmptyGrid();
        $this->_run($oCommand);

        $this->assertFileExists($this->sStoreDir . '/4/999.dat');
        $this->assertSame('stale page', file_get_contents($this->sStoreDir . '/4/999.dat'));
    }

    // -------------------------------------------------------------------------
    // HTTP Basic Auth header construction (_httpHeaders(), tested directly
    // since _downloadFeed() itself is stubbed away above)
    // -------------------------------------------------------------------------

    public function testNoAuthorizationHeaderWhenCredentialsNotConfigured(): void
    {
        config::overrideValue('teletext_tvguide_username', '');
        config::overrideValue('teletext_tvguide_password', '');
        $oCommand = new TvGuideImport();

        $oMethod = new ReflectionMethod(TvGuideImport::class, '_httpHeaders');
        $oMethod->setAccessible(true);
        $sHeaders = $oMethod->invoke($oCommand);

        $this->assertStringContainsString('User-Agent:', $sHeaders);
        $this->assertStringNotContainsString('Authorization:', $sHeaders);
    }

    public function testAuthorizationHeaderIncludedWhenCredentialsConfigured(): void
    {
        config::overrideValue('teletext_tvguide_username', 'admin');
        config::overrideValue('teletext_tvguide_password', 'secret');
        $oCommand = new TvGuideImport();

        $oMethod = new ReflectionMethod(TvGuideImport::class, '_httpHeaders');
        $oMethod->setAccessible(true);
        $sHeaders = $oMethod->invoke($oCommand);

        $this->assertStringContainsString('Authorization: Basic ' . base64_encode('admin:secret'), $sHeaders);
    }
}
