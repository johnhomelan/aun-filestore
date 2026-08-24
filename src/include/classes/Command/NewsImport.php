<?php
namespace HomeLan\FileStore\Command;

include_once(__DIR__ . '/../../system.inc.php');

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use HomeLan\FileStore\Services\Provider\Teletext\NewsFeedDefinitions;
use HomeLan\FileStore\Services\Provider\Teletext\NewsFeedDefinition;
use HomeLan\FileStore\Services\Provider\Teletext\NewsFeedParser;
use HomeLan\FileStore\Services\Provider\Teletext\ArticleExtractor;
use HomeLan\FileStore\Services\Provider\Teletext\NewsPageComposer;
use config;

/**
 * Downloads one configured RSS news feed (BBC/Guardian/Sky - see
 * NewsFeedDefinitions) and turns it into this project's own
 * `{channel}/{page}.dat` / `{page}_{subpage}.dat` page store: a styled
 * index on page 100 (with as many subpages as needed to list every story)
 * and one page per story starting at 101 (with as many subpages as needed
 * to hold its full body text), ready for the Teletext service provider to
 * serve directly.
 *
 * Which feed to import is selected with --feed (bbc|guardian|sky), each
 * with its own channel/source/max-stories config - see
 * src/include/config.inc.php's teletext_news_{feed}_* keys. A feed can
 * define several real section feeds of its own instead of one plain source
 * (currently just BBC - see NewsFeedDefinitions::$aCategoryFeeds); when it
 * does, _fetchItems() downloads every section feed and tags each story with
 * the section it came from, which is what drives the category-grouped
 * index page (see NewsPageComposer). --source still overrides that with a
 * single ad-hoc feed, same as for a source with no sections of its own.
 * Structured the same way as TeefaxImport - see
 * src/include/classes/Command/TeefaxImport.php - right down to the atomic
 * staging-dir install, but with one important difference: a single
 * article's fetch/extraction failing is logged and skipped rather than
 * failing the whole run, since a source's live site being flaky for one
 * story must not block the rest of that feed's news from refreshing.
 *
 * Normally launched as a detached background process by Teletext's own
 * housekeeping check (see Teletext::checkNewsRefresh()) rather than run by
 * hand, but safe to run interactively too.
 *
 * Usage:
 *   news-import --feed=bbc --config=/etc/aun-filestored
*/
#[\Symfony\Component\Console\Attribute\AsCommand(name: 'news-import', description: 'Download a configured RSS news feed and convert it into a channel page store')]
class NewsImport extends Command
{
    protected function configure(): void
    {
        $this
            ->addOption('config', 'c', InputOption::VALUE_OPTIONAL, 'Path to config directory', null)
            ->addOption('feed', null, InputOption::VALUE_REQUIRED, 'Which feed to import (' . implode('|', NewsFeedDefinitions::keys()) . ')')
            ->addOption('channel', null, InputOption::VALUE_OPTIONAL, 'Channel to import into (overrides teletext_news_{feed}_channel)', null)
            ->addOption('source', null, InputOption::VALUE_OPTIONAL, 'RSS feed URL to import from (overrides teletext_news_{feed}_source)', null)
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Download and parse but do not write anything');
    }

    protected function execute(InputInterface $oInput, OutputInterface $oOutput): int
    {
        $mConfigOption = $oInput->getOption('config');
        if ($mConfigOption !== null) {
            safe_define('CONFIG_CONF_FILE_PATH', is_scalar($mConfigOption) ? (string) $mConfigOption : '');
        }

        $mFeed = $oInput->getOption('feed');
        $sFeedKey = is_scalar($mFeed) ? (string) $mFeed : '';
        $oFeed = NewsFeedDefinitions::get($sFeedKey);
        if ($oFeed === null) {
            $oOutput->writeln('<error>Unknown --feed "' . $sFeedKey . '" (must be one of ' . implode(', ', NewsFeedDefinitions::keys()) . ')</error>');
            return Command::FAILURE;
        }

        $mChannel    = $oInput->getOption('channel');
        $mSource     = $oInput->getOption('source');
        $sChannel    = is_scalar($mChannel) ? (string) $mChannel : config::getValueAsString('teletext_news_' . $oFeed->sConfigPrefix . '_channel');
        $sSource     = is_scalar($mSource) ? (string) $mSource : config::getValueAsString('teletext_news_' . $oFeed->sConfigPrefix . '_source');
        $sStoreDir   = config::getValueAsString('teletext_store_dir');
        $iMaxStories = config::getValueAsInt('teletext_news_' . $oFeed->sConfigPrefix . '_max_stories');
        $bDryRun     = (bool) $oInput->getOption('dry-run');

        if (!preg_match('/^[0-9]$/', $sChannel)) {
            $oOutput->writeln('<error>No valid channel configured (teletext_news_' . $oFeed->sConfigPrefix . '_channel or --channel, must be a single digit 0-9)</error>');
            return Command::FAILURE;
        }
        if ($sSource === '') {
            $oOutput->writeln('<error>No source URL configured (teletext_news_' . $oFeed->sConfigPrefix . '_source or --source)</error>');
            return Command::FAILURE;
        }

        $sStagingDir = $sStoreDir . '/.news-staging-' . $sChannel;

        try {
            $aItems = $this->_fetchItems($oFeed, $mSource, $sSource, $oOutput);
            if ($iMaxStories > 0 && count($aItems) > $iMaxStories) {
                $aItems = array_slice($aItems, 0, $iMaxStories);
            }

            $this->_deleteDir($sStagingDir);
            if (!$bDryRun) {
                $this->_makeDir($sStagingDir);
            }

            $oExtractor = new ArticleExtractor();
            $oComposer  = new NewsPageComposer();
            $oNow       = $this->now();

            $aIndexEntries   = [];
            $iPageNumber     = 101;
            $iPagesWritten   = 0;
            $iStoriesSkipped = 0;

            foreach ($aItems as $aItem) {
                $sPage = (string) $iPageNumber;
                try {
                    $oOutput->writeln('Fetching ' . $aItem['link'] . ' ...');
                    $aArticle = $oExtractor->extract($this->_downloadArticle($aItem['link']), $oFeed->oArticleProfile);
                    if ($aArticle['blocks'] === []) {
                        throw new \RuntimeException('No body content extracted');
                    }
                    $sHeadline = $aArticle['headline'] !== '' ? $aArticle['headline'] : $aItem['title'];
                    $aBuffers  = $oComposer->composeStory(
                        $sPage,
                        $sHeadline,
                        $aArticle['published'],
                        $aArticle['blocks'],
                        $oNow,
                        $oFeed->sMastheadTitle,
                        $oFeed->iHeadlineForeground,
                        $oFeed->iHeadlineBackground
                    );
                } catch (\Throwable $e) {
                    $oOutput->writeln('<comment>Skipping ' . $aItem['link'] . ': ' . $e->getMessage() . '</comment>');
                    $iStoriesSkipped++;
                    continue;
                }

                $iPagesWritten += $this->_writeBuffers($sStagingDir, $sPage, $aBuffers, $bDryRun);
                $aIndexEntries[] = ['page' => $sPage, 'headline' => $sHeadline, 'category' => $aItem['category'] ?? ''];
                $iPageNumber++;
            }

            $aIndexBuffers = $oComposer->composeIndex('100', $aIndexEntries, $oNow, $oFeed->sMastheadTitle, $oFeed->sBannerText, $oFeed->iBannerForeground, $oFeed->iBannerBackground);
            $iPagesWritten += $this->_writeBuffers($sStagingDir, '100', $aIndexBuffers, $bDryRun);

            if ($bDryRun) {
                $oOutput->writeln('[dry-run] Would write ' . $iPagesWritten . ' page(s) for ' . count($aIndexEntries) . ' stor(y/ies), ' . $iStoriesSkipped . ' skipped.');
                return Command::SUCCESS;
            }

            $this->_putFileContents($sStagingDir . '/.imported', (string) time());

            $oOutput->writeln('Installing into ' . $sStoreDir . '/' . $sChannel . ' ...');
            $this->_installChannel($sStoreDir, $sChannel, $sStagingDir);

            $oOutput->writeln($iPagesWritten . ' page(s) written for ' . count($aIndexEntries) . ' stor(y/ies), ' . $iStoriesSkipped . ' skipped.');
            return Command::SUCCESS;
        } catch (\Throwable $e) {
            $oOutput->writeln('<error>News import (' . $sFeedKey . ') failed: ' . $e->getMessage() . '</error>');
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
     * Atomically installs a fully-populated staging directory as the live
     * channel directory - identical to TeefaxImport::_installChannel().
    */
    protected function _installChannel(string $sStoreDir, string $sChannel, string $sStagingDir): void
    {
        $sLiveDir = $sStoreDir . '/' . $sChannel;
        $sOldDir  = $sStoreDir . '/.news-old-' . $sChannel;

        $this->_deleteDir($sOldDir);
        if ($this->_isDir($sLiveDir)) {
            $this->_renameDir($sLiveDir, $sOldDir);
        }
        $this->_renameDir($sStagingDir, $sLiveDir);
        $this->_deleteDir($sOldDir);
    }

    /**
     * Downloads and parses either one plain feed (--source override, or a
     * feed with no category feeds of its own - Guardian/Sky) or, for a feed
     * like BBC that defines $aCategoryFeeds, every one of those section
     * feeds - tagging each story with the section it was downloaded from
     * (overriding whatever <category> tag, if any, NewsFeedParser found in
     * the XML itself, since the section a story was fetched from is the
     * more reliable signal). A story appearing in more than one section
     * feed is kept only once, under whichever section's feed is listed
     * first in NewsFeedDefinition::$aCategoryFeeds.
     *
     * @return array<int, array{title: string, link: string, pubDate: ?string, category: string}>
     */
    protected function _fetchItems(NewsFeedDefinition $oFeed, mixed $mSourceOption, string $sSource, OutputInterface $oOutput): array
    {
        if ($mSourceOption !== null || $oFeed->aCategoryFeeds === []) {
            $oOutput->writeln('Downloading ' . $sSource . ' ...');
            return (new NewsFeedParser())->parse($this->_downloadFeed($sSource), $oFeed->sLinkFilterPattern);
        }

        $aItems = [];
        $aSeenLinks = [];
        foreach ($oFeed->aCategoryFeeds as $sCategory => $sUrl) {
            $oOutput->writeln('Downloading ' . $sCategory . ' (' . $sUrl . ') ...');
            foreach ((new NewsFeedParser())->parse($this->_downloadFeed($sUrl), $oFeed->sLinkFilterPattern) as $aItem) {
                if (isset($aSeenLinks[$aItem['link']])) {
                    continue;
                }
                $aSeenLinks[$aItem['link']] = true;
                $aItem['category'] = $sCategory;
                $aItems[] = $aItem;
            }
        }
        return $aItems;
    }

    // -------------------------------------------------------------------------
    // I/O wrappers - _downloadFeed()/_downloadArticle() are the only ones
    // overridden in tests (the genuinely-external parts); everything else
    // runs for real against temp directories, matching TeefaxImportTest's
    // "override just the external boundary" pattern.
    // -------------------------------------------------------------------------

    protected function _downloadFeed(string $sUrl): string
    {
        return $this->_fetch($sUrl);
    }

    protected function _downloadArticle(string $sUrl): string
    {
        return $this->_fetch($sUrl);
    }

    protected function _fetch(string $sUrl): string
    {
        $rContext = stream_context_create([
            'http' => ['timeout' => 30, 'header' => 'User-Agent: aun-filestored news-import'],
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

    protected function _isDir(string $sPath): bool
    {
        return is_dir($sPath);
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
