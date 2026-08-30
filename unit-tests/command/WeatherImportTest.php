<?php

/*
 * Unit tests for HomeLan\FileStore\Command\WeatherImport.
 *
 * WeatherImport downloads BBC Weather's 3-day forecast for every location
 * in WeatherLocations inside execute() via the protected _downloadFeed()
 * method. Tests override just that method to return fixture strings — no
 * real network call is ever made — while everything else (parsing,
 * composing, atomic staging-dir install) runs for real against a temp
 * directory, matching NewsImportTest's "override just the external
 * boundary" pattern.
 */

include_once(__DIR__ . '/../../src/include/system.inc.php');

use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;
use HomeLan\FileStore\Command\WeatherImport;
use HomeLan\FileStore\Services\Provider\Teletext\WeatherLocations;

// ---------------------------------------------------------------------------
// Testable subclass — replaces _downloadFeed() with a stub
// ---------------------------------------------------------------------------
class TestableWeatherImport extends WeatherImport
{
    /** @var array<string, string|\Throwable> */
    public array $feedFixtures = [];
    public array $capDownloadUrls = [];

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
class WeatherImportTest extends TestCase
{
    private string $sStoreDir;

    protected function setUp(): void
    {
        $this->sStoreDir = sys_get_temp_dir() . '/weather_import_test_' . uniqid();
        mkdir($this->sStoreDir, 0755, true);

        config::overrideValue('teletext_store_dir', $this->sStoreDir);
        config::overrideValue('teletext_weather_channel', '2');
        config::overrideValue('teletext_weather_index_page', 600);
    }

    protected function tearDown(): void
    {
        config::resetValue('teletext_weather_channel');
        config::resetValue('teletext_weather_index_page');
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

    private function _forecastXml(string $sDay, string $sCondition, string $sMinC): string
    {
        return '<?xml version="1.0" encoding="UTF-8"?><rss version="2.0"><channel><title>BBC Weather</title>'
            . '<item><title>' . $sDay . ': ' . $sCondition . ', Minimum Temperature: ' . $sMinC . '&#176;C (' . $sMinC . '&#176;F)</title>'
            . '<description>Minimum Temperature: ' . $sMinC . '&#176;C (' . $sMinC . '&#176;F)</description></item>'
            . '</channel></rss>';
    }

    /** @return array<string, string> a fixture registered for every WeatherLocations URL */
    private function _fixtureForEveryLocation(string $sDay = 'Tonight', string $sCondition = 'Clear Sky', string $sMinC = '14'): array
    {
        $aFixtures = [];
        foreach (WeatherLocations::all() as $oLocation) {
            $aFixtures['https://weather-broker-cdn.api.bbci.co.uk/en/forecast/rss/3day/' . $oLocation->sBbcLocationId]
                = $this->_forecastXml($sDay, $sCondition, $sMinC);
        }
        return $aFixtures;
    }

    private function _run(TestableWeatherImport $oCommand, array $aArgs = []): CommandTester
    {
        $oTester = new CommandTester($oCommand);
        $oTester->execute($aArgs);
        return $oTester;
    }

    // -------------------------------------------------------------------------
    // Channel resolution
    // -------------------------------------------------------------------------

    public function testFailsWhenNoValidChannelConfigured(): void
    {
        config::overrideValue('teletext_weather_channel', 'x');
        $oCommand = new TestableWeatherImport();
        $oCommand->feedFixtures = $this->_fixtureForEveryLocation();

        $oTester = $this->_run($oCommand);

        $this->assertSame(1, $oTester->getStatusCode());
        $this->assertStringContainsString('No valid channel configured', $oTester->getDisplay());
    }

    public function testChannelOptionOverridesConfig(): void
    {
        $oCommand = new TestableWeatherImport();
        $oCommand->feedFixtures = $this->_fixtureForEveryLocation();

        $this->_run($oCommand, ['--channel' => '9']);

        $this->assertFileExists($this->sStoreDir . '/9/600.dat');
        $this->assertFileDoesNotExist($this->sStoreDir . '/2/600.dat');
    }

    // -------------------------------------------------------------------------
    // Index page resolution
    // -------------------------------------------------------------------------

    public function testFailsWhenIndexPageIsOutOfRange(): void
    {
        config::overrideValue('teletext_weather_index_page', 50);
        $oCommand = new TestableWeatherImport();
        $oCommand->feedFixtures = $this->_fixtureForEveryLocation();

        $oTester = $this->_run($oCommand);

        $this->assertSame(1, $oTester->getStatusCode());
        $this->assertStringContainsString('No valid index page configured', $oTester->getDisplay());
    }

    public function testIndexPageOptionOverridesConfig(): void
    {
        $oCommand = new TestableWeatherImport();
        $oCommand->feedFixtures = $this->_fixtureForEveryLocation();

        $this->_run($oCommand, ['--index-page' => '300']);

        $this->assertFileExists($this->sStoreDir . '/2/300.dat');
        $this->assertFileExists($this->sStoreDir . '/2/301.dat');
        $this->assertFileDoesNotExist($this->sStoreDir . '/2/600.dat');
    }

    // -------------------------------------------------------------------------
    // Successful import
    // -------------------------------------------------------------------------

    public function testImportsIndexAndOnePagePerLocation(): void
    {
        $oCommand = new TestableWeatherImport();
        $oCommand->feedFixtures = $this->_fixtureForEveryLocation('Monday', 'Sunny Intervals', '18');

        $oTester = $this->_run($oCommand);

        $this->assertSame(0, $oTester->getStatusCode());
        $iLocationCount = count(WeatherLocations::all());
        $this->assertFileExists($this->sStoreDir . '/2/600.dat');
        for ($i = 0; $i < $iLocationCount; $i++) {
            $this->assertFileExists($this->sStoreDir . '/2/' . (601 + $i) . '.dat');
        }

        // The 8-location list no longer fits a single index subpage now that
        // a blank line always separates the header from the first entry
        // (see WeatherPageComposer::INDEX_BODY_ROWS) - it overflows onto a
        // second subpage (600_2.dat), so every location is looked up across
        // both rather than assumed to fit on 600.dat alone.
        $sIndexPlain = preg_replace('/[\x00-\x1f]/', '', file_get_contents($this->sStoreDir . '/2/600.dat'));
        $sIndexSubpage2 = $this->sStoreDir . '/2/600_2.dat';
        if (file_exists($sIndexSubpage2)) {
            $sIndexPlain .= preg_replace('/[\x00-\x1f]/', '', file_get_contents($sIndexSubpage2));
        }
        foreach (WeatherLocations::all() as $oLocation) {
            $this->assertStringContainsString($oLocation->sLabel, $sIndexPlain);
        }

        $sStoryPlain = preg_replace('/[\x00-\x1f]/', '', file_get_contents($this->sStoreDir . '/2/601.dat'));
        $this->assertStringContainsString('Monday', $sStoryPlain);
        $this->assertStringContainsString('Sunny Intervals', $sStoryPlain);
    }

    public function testAllLocationUrlsAreDownloaded(): void
    {
        $oCommand = new TestableWeatherImport();
        $oCommand->feedFixtures = $this->_fixtureForEveryLocation();

        $this->_run($oCommand);

        $aExpectedUrls = array_map(
            fn ($oLocation) => 'https://weather-broker-cdn.api.bbci.co.uk/en/forecast/rss/3day/' . $oLocation->sBbcLocationId,
            array_values(WeatherLocations::all())
        );
        $this->assertSame($aExpectedUrls, $oCommand->capDownloadUrls);
    }

    public function testFailedLocationFetchIsSkippedNotFatal(): void
    {
        $oCommand = new TestableWeatherImport();
        $oCommand->feedFixtures = $this->_fixtureForEveryLocation();
        $aLocations = WeatherLocations::all();
        $sFirstUrl = 'https://weather-broker-cdn.api.bbci.co.uk/en/forecast/rss/3day/' . reset($aLocations)->sBbcLocationId;
        $oCommand->feedFixtures[$sFirstUrl] = new \RuntimeException('connection reset');

        $oTester = $this->_run($oCommand);

        $this->assertSame(0, $oTester->getStatusCode());
        $this->assertStringContainsString('Skipping', $oTester->getDisplay());
        $this->assertStringContainsString('connection reset', $oTester->getDisplay());

        // The failed location leaves no gap - the next successfully
        // fetched location still becomes page 601.
        $this->assertFileExists($this->sStoreDir . '/2/601.dat');
        $iLocationCount = count($aLocations);
        $this->assertFileDoesNotExist($this->sStoreDir . '/2/' . (600 + $iLocationCount) . '.dat');
    }

    public function testDryRunWritesNothing(): void
    {
        $oCommand = new TestableWeatherImport();
        $oCommand->feedFixtures = $this->_fixtureForEveryLocation();

        $oTester = $this->_run($oCommand, ['--dry-run' => true]);

        $this->assertSame(0, $oTester->getStatusCode());
        $this->assertStringContainsString('[dry-run]', $oTester->getDisplay());
        $this->assertFileDoesNotExist($this->sStoreDir . '/2');
    }

    public function testWritesAnImportedMarkerFile(): void
    {
        $oCommand = new TestableWeatherImport();
        $oCommand->feedFixtures = $this->_fixtureForEveryLocation();

        $iBefore = time();
        $this->_run($oCommand);

        $this->assertFileExists($this->sStoreDir . '/2/.imported');
        $this->assertGreaterThanOrEqual($iBefore, (int) trim(file_get_contents($this->sStoreDir . '/2/.imported')));
    }

    public function testInstallOverwritesAPageThisRunRegenerates(): void
    {
        mkdir($this->sStoreDir . '/2', 0755, true);
        file_put_contents($this->sStoreDir . '/2/601.dat', 'stale page');

        $oCommand = new TestableWeatherImport();
        $oCommand->feedFixtures = $this->_fixtureForEveryLocation('Monday', 'Sunny Intervals', '18');

        $this->_run($oCommand);

        $sPage = file_get_contents($this->sStoreDir . '/2/601.dat');
        $this->assertNotSame('stale page', $sPage);
        $this->assertStringContainsString('Sunny Intervals', preg_replace('/[\x00-\x1f]/', '', $sPage));
    }

    public function testInstallDoesNotDeleteAPageThisRunDoesNotRegenerate(): void
    {
        // A previously-installed page number this run has no location for
        // (e.g. its forecast failed to fetch/parse) must be left in place,
        // not deleted - see WeatherImport's class docblock and
        // _installChannel().
        mkdir($this->sStoreDir . '/2', 0755, true);
        file_put_contents($this->sStoreDir . '/2/999.dat', 'stale page');

        $oCommand = new TestableWeatherImport();
        $oCommand->feedFixtures = $this->_fixtureForEveryLocation();

        $this->_run($oCommand);

        $this->assertFileExists($this->sStoreDir . '/2/999.dat');
        $this->assertSame('stale page', file_get_contents($this->sStoreDir . '/2/999.dat'));
        $this->assertFileExists($this->sStoreDir . '/2/601.dat');
    }
}
