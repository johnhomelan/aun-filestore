<?php

/*
 * Unit tests for HomeLan\FileStore\Command\NewsImport.
 *
 * NewsImport downloads the RSS feed and each article's HTML inside
 * execute() via the protected _downloadFeed()/_downloadArticle() methods.
 * Tests override just those two methods to return fixture strings — no
 * real network call is ever made — while everything else (parsing,
 * extraction, composing, atomic staging-dir install) runs for real against
 * a temp directory, matching TeefaxImportTest's "override just the
 * external boundary" pattern.
 */

include_once(__DIR__ . '/../../src/include/system.inc.php');

use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;
use HomeLan\FileStore\Command\NewsImport;

// ---------------------------------------------------------------------------
// Testable subclass — replaces _downloadFeed()/_downloadArticle() with stubs
// ---------------------------------------------------------------------------
class TestableNewsImport extends NewsImport
{
    public string $stubFeed = '';
    /** @var array<string, string|\Throwable> */
    public array $articleFixtures = [];
    public array $capDownloadUrls = [];

    protected function _downloadFeed(string $sUrl): string
    {
        $this->capDownloadUrls[] = $sUrl;
        return $this->stubFeed;
    }

    protected function _downloadArticle(string $sUrl): string
    {
        $this->capDownloadUrls[] = $sUrl;
        $mFixture = $this->articleFixtures[$sUrl] ?? null;
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
class NewsImportTest extends TestCase
{
    private string $sStoreDir;

    protected function setUp(): void
    {
        $this->sStoreDir = sys_get_temp_dir() . '/news_import_test_' . uniqid();
        mkdir($this->sStoreDir, 0755, true);

        config::overrideValue('teletext_store_dir', $this->sStoreDir);
        config::overrideValue('teletext_news_bbc_channel', '2');
        config::overrideValue('teletext_news_bbc_source', 'https://example.invalid/bbc.xml');
        config::overrideValue('teletext_news_bbc_max_stories', 40);
        config::overrideValue('teletext_news_guardian_channel', '3');
        config::overrideValue('teletext_news_guardian_source', 'https://example.invalid/guardian.xml');
        config::overrideValue('teletext_news_guardian_max_stories', 40);
        config::overrideValue('teletext_news_sky_channel', '5');
        config::overrideValue('teletext_news_sky_source', 'https://example.invalid/sky.xml');
        config::overrideValue('teletext_news_sky_max_stories', 40);
    }

    protected function tearDown(): void
    {
        foreach (['bbc', 'guardian', 'sky'] as $sFeed) {
            config::resetValue('teletext_news_' . $sFeed . '_channel');
            config::resetValue('teletext_news_' . $sFeed . '_source');
            config::resetValue('teletext_news_' . $sFeed . '_max_stories');
        }
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

    /** @param array<int, array{title: string, link: string}> $aItems */
    private function _feedXml(array $aItems): string
    {
        $s = '<?xml version="1.0" encoding="UTF-8"?><rss version="2.0"><channel><title>Feed</title>';
        foreach ($aItems as $aItem) {
            $s .= '<item>'
                . '<title><![CDATA[' . $aItem['title'] . ']]></title>'
                . '<description><![CDATA[Summary.]]></description>'
                . '<link>' . $aItem['link'] . '</link>'
                . '<pubDate>Sat, 22 Aug 2026 19:28:14 GMT</pubDate>'
                . '</item>';
        }
        return $s . '</channel></rss>';
    }

    private function _articleHtml(string $sHeadline, string $sParagraph): string
    {
        return '<html><body><article>'
            . '<h1>' . $sHeadline . '</h1>'
            . '<time datetime="2026-08-22T15:44:57.188Z">22 August 2026, 16:44 BST</time>'
            . '<p class="ssrcss-1q0x1qg-Paragraph e1jhz7w10">' . $sParagraph . '</p>'
            . '</article></body></html>';
    }

    private function _run(TestableNewsImport $oCommand, array $aArgs = []): CommandTester
    {
        $oTester = new CommandTester($oCommand);
        $oTester->execute($aArgs);
        return $oTester;
    }

    // -------------------------------------------------------------------------
    // --feed validation
    // -------------------------------------------------------------------------

    public function testFailsWhenFeedOptionMissing(): void
    {
        $oTester = $this->_run(new TestableNewsImport());
        $this->assertSame(1, $oTester->getStatusCode());
    }

    public function testFailsWhenFeedIsUnknown(): void
    {
        $oTester = $this->_run(new TestableNewsImport(), ['--feed' => 'reuters']);
        $this->assertSame(1, $oTester->getStatusCode());
        $this->assertStringContainsString('Unknown --feed', $oTester->getDisplay());
    }

    // -------------------------------------------------------------------------
    // Each feed resolves its own config
    // -------------------------------------------------------------------------

    public function testEachFeedResolvesItsOwnChannelAndSource(): void
    {
        $aExpected = [
            'bbc'      => ['2', 'https://example.invalid/bbc.xml'],
            'guardian' => ['3', 'https://example.invalid/guardian.xml'],
            'sky'      => ['5', 'https://example.invalid/sky.xml'],
        ];

        foreach ($aExpected as $sFeed => [$sChannel, $sSource]) {
            $oCommand = new TestableNewsImport();
            $oCommand->stubFeed = $this->_feedXml([]);
            $this->_run($oCommand, ['--feed' => $sFeed]);

            $this->assertSame([$sSource], $oCommand->capDownloadUrls, $sFeed . ' source');
            $this->assertFileExists($this->sStoreDir . '/' . $sChannel . '/100.dat', $sFeed . ' channel');
        }
    }

    public function testChannelOptionOverridesConfig(): void
    {
        $oCommand = new TestableNewsImport();
        $oCommand->stubFeed = $this->_feedXml([['title' => 'Story one', 'link' => 'https://example.invalid/news/articles/aaa']]);
        $oCommand->articleFixtures['https://example.invalid/news/articles/aaa'] = $this->_articleHtml('Story one', 'Body text.');
        $this->_run($oCommand, ['--feed' => 'bbc', '--channel' => '9']);

        $this->assertFileExists($this->sStoreDir . '/9/100.dat');
        $this->assertFileDoesNotExist($this->sStoreDir . '/2/100.dat');
    }

    public function testSourceOptionOverridesConfigAndIsPassedToDownload(): void
    {
        $oCommand = new TestableNewsImport();
        $oCommand->stubFeed = $this->_feedXml([]);
        $this->_run($oCommand, ['--feed' => 'bbc', '--source' => 'https://example.invalid/other.xml']);

        $this->assertSame(['https://example.invalid/other.xml'], $oCommand->capDownloadUrls);
    }

    // -------------------------------------------------------------------------
    // Successful import (bbc as the representative feed; the per-source
    // extraction differences are covered by ArticleExtractorTest)
    // -------------------------------------------------------------------------

    public function testImportsIndexAndStoryPages(): void
    {
        $oCommand = new TestableNewsImport();
        $oCommand->stubFeed = $this->_feedXml([
            ['title' => 'First headline', 'link' => 'https://example.invalid/news/articles/aaa'],
            ['title' => 'Second headline', 'link' => 'https://example.invalid/news/articles/bbb'],
        ]);
        $oCommand->articleFixtures['https://example.invalid/news/articles/aaa'] = $this->_articleHtml('First headline', 'First body.');
        $oCommand->articleFixtures['https://example.invalid/news/articles/bbb'] = $this->_articleHtml('Second headline', 'Second body.');

        $oTester = $this->_run($oCommand, ['--feed' => 'bbc']);

        $this->assertSame(0, $oTester->getStatusCode());
        $this->assertFileExists($this->sStoreDir . '/2/100.dat');
        $this->assertFileExists($this->sStoreDir . '/2/101.dat');
        $this->assertFileExists($this->sStoreDir . '/2/102.dat');

        $sIndex = file_get_contents($this->sStoreDir . '/2/100.dat');
        $this->assertSame(1024, strlen($sIndex));
        $sIndexPlain = preg_replace('/[\x00-\x1f]/', '', $sIndex);
        $this->assertStringContainsString('First headline', $sIndexPlain);
        $this->assertStringContainsString('Second headline', $sIndexPlain);
        $this->assertStringContainsString('BBC NEWS', $sIndexPlain);

        $sStory = file_get_contents($this->sStoreDir . '/2/101.dat');
        $sStoryPlain = preg_replace('/[\x00-\x1f]/', '', $sStory);
        $this->assertStringContainsString('First headline', $sStoryPlain);
        $this->assertStringContainsString('First body.', $sStoryPlain);
    }

    public function testFailedArticleFetchIsSkippedNotFatal(): void
    {
        $oCommand = new TestableNewsImport();
        $oCommand->stubFeed = $this->_feedXml([
            ['title' => 'Broken story', 'link' => 'https://example.invalid/news/articles/broken'],
            ['title' => 'Working story', 'link' => 'https://example.invalid/news/articles/working'],
        ]);
        $oCommand->articleFixtures['https://example.invalid/news/articles/broken'] = new \RuntimeException('connection reset');
        $oCommand->articleFixtures['https://example.invalid/news/articles/working'] = $this->_articleHtml('Working story', 'Working body.');

        $oTester = $this->_run($oCommand, ['--feed' => 'bbc']);

        $this->assertSame(0, $oTester->getStatusCode());
        $this->assertStringContainsString('Skipping', $oTester->getDisplay());
        $this->assertStringContainsString('connection reset', $oTester->getDisplay());

        $this->assertFileExists($this->sStoreDir . '/2/101.dat');
        $this->assertFileDoesNotExist($this->sStoreDir . '/2/102.dat');

        $sIndexPlain = preg_replace('/[\x00-\x1f]/', '', file_get_contents($this->sStoreDir . '/2/100.dat'));
        $this->assertStringContainsString('Working story', $sIndexPlain);
        $this->assertStringNotContainsString('Broken story', $sIndexPlain);
    }

    public function testMaxStoriesCapsNumberOfStoriesProcessed(): void
    {
        config::overrideValue('teletext_news_bbc_max_stories', 1);
        $oCommand = new TestableNewsImport();
        $oCommand->stubFeed = $this->_feedXml([
            ['title' => 'Story one', 'link' => 'https://example.invalid/news/articles/one'],
            ['title' => 'Story two', 'link' => 'https://example.invalid/news/articles/two'],
        ]);
        $oCommand->articleFixtures['https://example.invalid/news/articles/one'] = $this->_articleHtml('Story one', 'Body.');

        $this->_run($oCommand, ['--feed' => 'bbc']);

        $this->assertFileExists($this->sStoreDir . '/2/101.dat');
        $this->assertFileDoesNotExist($this->sStoreDir . '/2/102.dat');
    }

    public function testDryRunWritesNothing(): void
    {
        $oCommand = new TestableNewsImport();
        $oCommand->stubFeed = $this->_feedXml([
            ['title' => 'Story one', 'link' => 'https://example.invalid/news/articles/one'],
        ]);
        $oCommand->articleFixtures['https://example.invalid/news/articles/one'] = $this->_articleHtml('Story one', 'Body.');

        $oTester = $this->_run($oCommand, ['--feed' => 'bbc', '--dry-run' => true]);

        $this->assertSame(0, $oTester->getStatusCode());
        $this->assertStringContainsString('[dry-run]', $oTester->getDisplay());
        $this->assertFileDoesNotExist($this->sStoreDir . '/2');
    }

    public function testWritesAnImportedMarkerFile(): void
    {
        $oCommand = new TestableNewsImport();
        $oCommand->stubFeed = $this->_feedXml([
            ['title' => 'Story one', 'link' => 'https://example.invalid/news/articles/one'],
        ]);
        $oCommand->articleFixtures['https://example.invalid/news/articles/one'] = $this->_articleHtml('Story one', 'Body.');

        $iBefore = time();
        $this->_run($oCommand, ['--feed' => 'bbc']);

        $this->assertFileExists($this->sStoreDir . '/2/.imported');
        $this->assertGreaterThanOrEqual($iBefore, (int) trim(file_get_contents($this->sStoreDir . '/2/.imported')));
    }

    public function testAtomicInstallReplacesPreviousContents(): void
    {
        mkdir($this->sStoreDir . '/2', 0755, true);
        file_put_contents($this->sStoreDir . '/2/999.dat', 'stale page');

        $oCommand = new TestableNewsImport();
        $oCommand->stubFeed = $this->_feedXml([
            ['title' => 'Story one', 'link' => 'https://example.invalid/news/articles/one'],
        ]);
        $oCommand->articleFixtures['https://example.invalid/news/articles/one'] = $this->_articleHtml('Story one', 'Body.');

        $this->_run($oCommand, ['--feed' => 'bbc']);

        $this->assertFileDoesNotExist($this->sStoreDir . '/2/999.dat');
        $this->assertFileExists($this->sStoreDir . '/2/101.dat');
    }
}
