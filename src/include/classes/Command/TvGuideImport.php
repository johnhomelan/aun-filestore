<?php
namespace HomeLan\FileStore\Command;

include_once(__DIR__ . '/../../system.inc.php');

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use HomeLan\FileStore\Services\Provider\Teletext\TvGuideChannels;
use HomeLan\FileStore\Services\Provider\Teletext\TvGuideFeedParser;
use HomeLan\FileStore\Services\Provider\Teletext\TvGuidePageComposer;
use config;

/**
 * Downloads a 2-day (today/tomorrow) EPG from the user's own TVHeadend
 * instance (`/api/epg/events/grid`) and turns it into this project's own
 * `{channel}/{page}.dat` / `{page}_{subpage}.dat` page store: a styled
 * index on teletext_tvguide_index_page (default 700) and one page per UK
 * Freeview channel (see TvGuideChannels) starting the page after it (701
 * by default), ready for the Teletext service provider to serve directly.
 *
 * Unlike NewsImport (several distinct sources, one selected via --feed),
 * TV Guide has a single source with a fixed channel list, so every run
 * processes every channel from one shared EPG fetch - closer in shape to
 * WeatherImport, which this class is modeled directly on. Structured the
 * same way as both: build a fully-populated staging directory first, then
 * install atomically per-file. Unlike Weather/News though, there's no
 * per-item network fetch to fail independently here (one shared EPG grid
 * fetch covers every channel) - a channel with nothing matching its LCN in
 * the response simply gets an empty today/tomorrow listing rather than
 * being skipped; only the single whole-run fetch failing is fatal, the same
 * way a failed feed download is fatal to NewsImport.
 *
 * TVHeadend access uses HTTP Basic Auth when teletext_tvguide_username/
 * _password are configured (see _httpHeaders()) - many TVHeadend instances
 * put their HTTP API behind the same access control as their own web UI.
 *
 * _installChannel() matches WeatherImport's/NewsImport's atomic per-file
 * install: it only ever writes or overwrites individual page files into the
 * live channel directory (each a plain rename(), atomic per file) and never
 * deletes the directory or anything already in it - so a channel dropped
 * from TvGuideChannels in some future edit is left in place rather than
 * removed, until some later run's own output happens to overwrite that same
 * page number again.
 *
 * Normally launched as a detached background process by Teletext's own
 * housekeeping check (see Teletext::checkTvGuideRefresh()) rather than run
 * by hand, but safe to run interactively too.
 *
 * Usage:
 *   tv-guide-import --config=/etc/aun-filestored
*/
#[\Symfony\Component\Console\Attribute\AsCommand(name: 'tv-guide-import', description: 'Download a 2-day UK Freeview TV listing from TVHeadend and convert it into a channel page store')]
class TvGuideImport extends Command
{
    protected function configure(): void
    {
        $this
            ->addOption('config', 'c', InputOption::VALUE_OPTIONAL, 'Path to config directory', null)
            ->addOption('channel', null, InputOption::VALUE_OPTIONAL, 'Channel to import into (overrides teletext_tvguide_channel)', null)
            ->addOption('index-page', null, InputOption::VALUE_OPTIONAL, 'Index page number (overrides teletext_tvguide_index_page); channel pages start on the page after it', null)
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Download and parse but do not write anything');
    }

    protected function execute(InputInterface $oInput, OutputInterface $oOutput): int
    {
        $mConfigOption = $oInput->getOption('config');
        if ($mConfigOption !== null) {
            safe_define('CONFIG_CONF_FILE_PATH', is_scalar($mConfigOption) ? (string) $mConfigOption : '');
        }

        $mChannel    = $oInput->getOption('channel');
        $sChannel    = is_scalar($mChannel) ? (string) $mChannel : config::getValueAsString('teletext_tvguide_channel');
        $mIndexPage  = $oInput->getOption('index-page');
        $iIndexPage  = is_scalar($mIndexPage) ? (int) $mIndexPage : config::getValueAsInt('teletext_tvguide_index_page');
        $sSource     = config::getValueAsString('teletext_tvguide_source');
        $sStoreDir   = config::getValueAsString('teletext_store_dir');
        $bDryRun     = (bool) $oInput->getOption('dry-run');

        if (!preg_match('/^[0-9]$/', $sChannel)) {
            $oOutput->writeln('<error>No valid channel configured (teletext_tvguide_channel or --channel, must be a single digit 0-9)</error>');
            return Command::FAILURE;
        }
        if ($iIndexPage < 100 || $iIndexPage > 998) {
            $oOutput->writeln('<error>No valid index page configured (teletext_tvguide_index_page or --index-page, must be a 3-digit page number 100-998)</error>');
            return Command::FAILURE;
        }
        if ($sSource === '') {
            $oOutput->writeln('<error>No source configured (teletext_tvguide_source, e.g. http://tvheadend.local:9981)</error>');
            return Command::FAILURE;
        }

        $sStagingDir = $sStoreDir . '/.tvguide-staging-' . $sChannel;

        try {
            $oNow = $this->now();
            $iTodayStart = $oNow->setTime(0, 0, 0)->getTimestamp();
            $sUrl = rtrim($sSource, '/') . '/api/epg/events/grid?start=' . $iTodayStart . '&limit=10000&sort=start&dir=ASC';

            $oOutput->writeln('Downloading ' . $sUrl . ' ...');
            $sJson = $this->_downloadFeed($sUrl);

            $oParser   = new TvGuideFeedParser();
            $aChannels = TvGuideChannels::all();
            $aGrouped  = $oParser->groupByChannel($oParser->parse($sJson), $aChannels, $oNow);

            $this->_deleteDir($sStagingDir);
            if (!$bDryRun) {
                $this->_makeDir($sStagingDir);
            }

            $oComposer = new TvGuidePageComposer();

            $sIndexPage    = (string) $iIndexPage;
            $aIndexEntries = [];
            $iPageNumber   = $iIndexPage + 1;
            $iPagesWritten = 0;

            foreach ($aChannels as $sKey => $oChannel) {
                $sPage = (string) $iPageNumber;
                $aBuckets = $aGrouped[$sKey] ?? ['today' => [], 'tomorrow' => []];
                $aBuffers = $oComposer->composeChannelPage(
                    $sPage,
                    $oChannel->sLabel,
                    $oChannel->aMosaicWords,
                    $aBuckets['today'],
                    $aBuckets['tomorrow'],
                    $oNow
                );

                $iPagesWritten += $this->_writeBuffers($sStagingDir, $sPage, $aBuffers, $bDryRun);
                $aIndexEntries[] = ['page' => $sPage, 'label' => $oChannel->sLabel];
                $iPageNumber++;
            }

            $aIndexBuffers = $oComposer->composeIndex($sIndexPage, $aIndexEntries, $oNow);
            $iPagesWritten += $this->_writeBuffers($sStagingDir, $sIndexPage, $aIndexBuffers, $bDryRun);

            if ($bDryRun) {
                $oOutput->writeln('[dry-run] Would write ' . $iPagesWritten . ' page(s) for ' . count($aIndexEntries) . ' channel(s).');
                return Command::SUCCESS;
            }

            $this->_putFileContents($sStagingDir . '/.imported', (string) time());

            $oOutput->writeln('Installing into ' . $sStoreDir . '/' . $sChannel . ' ...');
            $this->_installChannel($sStoreDir, $sChannel, $sStagingDir);

            $oOutput->writeln($iPagesWritten . ' page(s) written for ' . count($aIndexEntries) . ' channel(s).');
            return Command::SUCCESS;
        } catch (\Throwable $e) {
            $oOutput->writeln('<error>TV guide import failed: ' . $e->getMessage() . '</error>');
            return Command::FAILURE;
        } finally {
            $this->_deleteDir($sStagingDir);
        }
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
    // directories, matching WeatherImportTest's "override just the external
    // boundary" pattern.
    // -------------------------------------------------------------------------

    protected function _downloadFeed(string $sUrl): string
    {
        $rContext = stream_context_create([
            'http' => ['timeout' => 30, 'header' => $this->_httpHeaders()],
        ]);
        $sData = @file_get_contents($sUrl, false, $rContext);
        if ($sData === false) {
            // file_get_contents() populates this magic local on a failed
            // (non-2xx) response too - it's the one place a 401 from a
            // bad/missing Authorization header is distinguishable from any
            // other download failure (DNS, connection refused, timeout,
            // 404, 500, ...), which the bare false return otherwise
            // collapses into a single generic error. Must be read here,
            // in the same scope as the file_get_contents() call. The
            // wrapper only creates it once an HTTP response arrived, so on a
            // transport-level failure it is absent - the `?? null` off an
            // undefined local is what covers that case.
            $sStatus = $http_response_header[0] ?? null;
            throw new \RuntimeException('Failed to download ' . $sUrl . (is_string($sStatus) ? ' (' . $sStatus . ')' : ''));
        }
        return $sData;
    }

    /**
     * Builds the request header string sent with every fetch: a User-Agent
     * line always, plus an HTTP Basic Auth Authorization line when
     * teletext_tvguide_username/_password are configured. Kept separate
     * from _downloadFeed() itself so the Authorization line's construction
     * is unit-testable without a live server.
     */
    protected function _httpHeaders(): string
    {
        $aHeaders = ['User-Agent: aun-filestored tv-guide-import'];
        $sUsername = config::getValueAsString('teletext_tvguide_username');
        $sPassword = config::getValueAsString('teletext_tvguide_password');
        if ($sUsername !== '' || $sPassword !== '') {
            $aHeaders[] = 'Authorization: Basic ' . base64_encode($sUsername . ':' . $sPassword);
        }
        return implode("\r\n", $aHeaders);
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
