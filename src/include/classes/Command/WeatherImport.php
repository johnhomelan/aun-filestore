<?php
namespace HomeLan\FileStore\Command;

include_once(__DIR__ . '/../../system.inc.php');

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use HomeLan\FileStore\Services\Provider\Teletext\WeatherLocations;
use HomeLan\FileStore\Services\Provider\Teletext\WeatherLocation;
use HomeLan\FileStore\Services\Provider\Teletext\WeatherFeedParser;
use HomeLan\FileStore\Services\Provider\Teletext\WeatherPageComposer;
use config;

/**
 * Downloads BBC Weather's 3-day forecast RSS feed (undocumented, but
 * confirmed live at
 * https://weather-broker-cdn.api.bbci.co.uk/en/forecast/rss/3day/{id})
 * for every configured UK city — see WeatherLocations — and turns it into
 * this project's own `{channel}/{page}.dat` / `{page}_{subpage}.dat` page
 * store: a styled index on teletext_weather_index_page (default 600, so it
 * sits after BBC News's own pages on the shared channel - see
 * NewsFeedDefinitions's aChannelIndexEntries for the BBC News channel-hub
 * page that links to it) and one page per city starting the page after it
 * (601 by default), ready for the Teletext service provider to serve
 * directly.
 *
 * Unlike NewsImport (several distinct sources, one selected via --feed),
 * weather has a single source with a fixed location list, so every run
 * processes every location — closer in shape to TeefaxImport. Structured
 * the same way as both: build a fully-populated staging directory first,
 * and a single city's fetch/parse failing is logged and skipped rather than
 * failing the whole run, since one city's forecast being unavailable must
 * not block the rest from refreshing.
 *
 * _installChannel() differs from TeefaxImport's whole-directory swap
 * though (see NewsImport, which shares this same approach): it only ever
 * writes or overwrites individual page files into the live channel
 * directory (each a plain rename(), atomic per file) and never deletes the
 * directory or anything already in it - so a city dropped from this run
 * (its forecast failing to fetch/parse) is left in place rather than
 * removed, until some later run's own output happens to overwrite that same
 * page number again.
 *
 * Normally launched as a detached background process by Teletext's own
 * housekeeping check (see Teletext::checkWeatherRefresh()) rather than run
 * by hand, but safe to run interactively too.
 *
 * Usage:
 *   weather-import --config=/etc/aun-filestored
*/
#[\Symfony\Component\Console\Attribute\AsCommand(name: 'weather-import', description: 'Download BBC Weather forecasts and convert them into a channel page store')]
class WeatherImport extends Command
{
    protected const string BBC_FORECAST_URL = 'https://weather-broker-cdn.api.bbci.co.uk/en/forecast/rss/3day/';

    protected function configure(): void
    {
        $this
            ->addOption('config', 'c', InputOption::VALUE_OPTIONAL, 'Path to config directory', null)
            ->addOption('channel', null, InputOption::VALUE_OPTIONAL, 'Channel to import into (overrides teletext_weather_channel)', null)
            ->addOption('index-page', null, InputOption::VALUE_OPTIONAL, 'Index page number (overrides teletext_weather_index_page); city pages start on the page after it', null)
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Download and parse but do not write anything');
    }

    protected function execute(InputInterface $oInput, OutputInterface $oOutput): int
    {
        $mConfigOption = $oInput->getOption('config');
        if ($mConfigOption !== null) {
            safe_define('CONFIG_CONF_FILE_PATH', is_scalar($mConfigOption) ? (string) $mConfigOption : '');
        }

        $mChannel    = $oInput->getOption('channel');
        $sChannel    = is_scalar($mChannel) ? (string) $mChannel : config::getValueAsString('teletext_weather_channel');
        $mIndexPage  = $oInput->getOption('index-page');
        $iIndexPage  = is_scalar($mIndexPage) ? (int) $mIndexPage : config::getValueAsInt('teletext_weather_index_page');
        $sStoreDir   = config::getValueAsString('teletext_store_dir');
        $bDryRun     = (bool) $oInput->getOption('dry-run');

        if (!preg_match('/^[0-9]$/', $sChannel)) {
            $oOutput->writeln('<error>No valid channel configured (teletext_weather_channel or --channel, must be a single digit 0-9)</error>');
            return Command::FAILURE;
        }
        if ($iIndexPage < 100 || $iIndexPage > 998) {
            $oOutput->writeln('<error>No valid index page configured (teletext_weather_index_page or --index-page, must be a 3-digit page number 100-998)</error>');
            return Command::FAILURE;
        }

        $sStagingDir = $sStoreDir . '/.weather-staging-' . $sChannel;

        try {
            $this->_deleteDir($sStagingDir);
            if (!$bDryRun) {
                $this->_makeDir($sStagingDir);
            }

            $oParser   = new WeatherFeedParser();
            $oComposer = new WeatherPageComposer();
            $oNow      = $this->now();

            $sIndexPage        = (string) $iIndexPage;
            $aIndexEntries     = [];
            $iPageNumber       = $iIndexPage + 1;
            $iPagesWritten     = 0;
            $iLocationsSkipped = 0;

            foreach (WeatherLocations::all() as $oLocation) {
                $sPage = (string) $iPageNumber;
                try {
                    $sUrl = $this->_locationUrl($oLocation);
                    $oOutput->writeln('Downloading ' . $oLocation->sLabel . ' (' . $sUrl . ') ...');
                    $aForecastDays = $oParser->parse($this->_downloadFeed($sUrl));
                    if ($aForecastDays === []) {
                        throw new \RuntimeException('No forecast data parsed');
                    }
                    $aBuffers = $oComposer->composeLocationPage($sPage, $oLocation->sLabel, $aForecastDays, $oNow);
                } catch (\Throwable $e) {
                    $oOutput->writeln('<comment>Skipping ' . $oLocation->sLabel . ': ' . $e->getMessage() . '</comment>');
                    $iLocationsSkipped++;
                    continue;
                }

                $iPagesWritten += $this->_writeBuffers($sStagingDir, $sPage, $aBuffers, $bDryRun);
                $aIndexEntries[] = ['page' => $sPage, 'label' => $oLocation->sLabel];
                $iPageNumber++;
            }

            $aIndexBuffers = $oComposer->composeIndex($sIndexPage, $aIndexEntries, $oNow);
            $iPagesWritten += $this->_writeBuffers($sStagingDir, $sIndexPage, $aIndexBuffers, $bDryRun);

            if ($bDryRun) {
                $oOutput->writeln('[dry-run] Would write ' . $iPagesWritten . ' page(s) for ' . count($aIndexEntries) . ' location(s), ' . $iLocationsSkipped . ' skipped.');
                return Command::SUCCESS;
            }

            $this->_putFileContents($sStagingDir . '/.imported', (string) time());

            $oOutput->writeln('Installing into ' . $sStoreDir . '/' . $sChannel . ' ...');
            $this->_installChannel($sStoreDir, $sChannel, $sStagingDir);

            $oOutput->writeln($iPagesWritten . ' page(s) written for ' . count($aIndexEntries) . ' location(s), ' . $iLocationsSkipped . ' skipped.');
            return Command::SUCCESS;
        } catch (\Throwable $e) {
            $oOutput->writeln('<error>Weather import failed: ' . $e->getMessage() . '</error>');
            return Command::FAILURE;
        } finally {
            $this->_deleteDir($sStagingDir);
        }
    }

    protected function _locationUrl(WeatherLocation $oLocation): string
    {
        return self::BBC_FORECAST_URL . $oLocation->sBbcLocationId;
    }

    /**
     * @param array<int, string> $aBuffers
     */
    protected function _writeBuffers(string $sStagingDir, string $sPage, array $aBuffers, bool $bDryRun): int
    {
        $iCount = 0;
        foreach ($aBuffers as $i => $sBuffer) {
            $iSubpage  = $i + 1;
            $sFilename = $iSubpage <= 1 ? $sPage . '.dat' : $sPage . '_' . $iSubpage . '.dat';
            if (!$bDryRun) {
                $this->_putFileContents($sStagingDir . '/' . $sFilename, $sBuffer);
            }
            $iCount++;
        }
        return $iCount;
    }

    /**
     * Installs a fully-populated staging directory into the live channel
     * directory one file at a time - each a plain rename() (atomic per
     * file, same filesystem), which for a name that already exists in the
     * live directory just replaces that one page. See the class docblock:
     * unlike TeefaxImport::_installChannel()'s whole-directory swap, this
     * never deletes the live directory or removes anything from it that
     * this run didn't itself produce a replacement for.
    */
    protected function _installChannel(string $sStoreDir, string $sChannel, string $sStagingDir): void
    {
        $sLiveDir = $sStoreDir . '/' . $sChannel;
        $this->_makeDir($sLiveDir);
        foreach ($this->_scanDir($sStagingDir) as $sEntry) {
            $this->_renameDir($sStagingDir . '/' . $sEntry, $sLiveDir . '/' . $sEntry);
        }
    }

    // -------------------------------------------------------------------------
    // I/O wrappers - _downloadFeed() is the only one overridden in tests (the
    // genuinely-external part); everything else runs for real against temp
    // directories, matching NewsImportTest's "override just the external
    // boundary" pattern.
    // -------------------------------------------------------------------------

    protected function _downloadFeed(string $sUrl): string
    {
        $rContext = stream_context_create([
            'http' => ['timeout' => 30, 'header' => 'User-Agent: aun-filestored weather-import'],
        ]);
        $sData = file_get_contents($sUrl, false, $rContext);
        if ($sData === false) {
            throw new \RuntimeException('Failed to download ' . $sUrl);
        }
        return $sData;
    }

    /**
     * @return array<int, string>
    */
    protected function _scanDir(string $sPath): array
    {
        $aEntries = scandir($sPath);
        return $aEntries === false ? [] : array_values(array_diff($aEntries, ['.', '..']));
    }

    protected function _makeDir(string $sPath): void
    {
        if (!is_dir($sPath) && !@mkdir($sPath, 0755, true) && !is_dir($sPath)) {
            throw new \RuntimeException('Failed to create directory ' . $sPath);
        }
    }

    protected function _putFileContents(string $sPath, string $sData): void
    {
        $this->_makeDir(dirname($sPath));
        if (@file_put_contents($sPath, $sData) === false) {
            throw new \RuntimeException('Failed to write file ' . $sPath);
        }
    }

    protected function _renameDir(string $sFrom, string $sTo): void
    {
        rename($sFrom, $sTo);
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

    protected function now(): \DateTimeImmutable
    {
        return new \DateTimeImmutable();
    }
}
