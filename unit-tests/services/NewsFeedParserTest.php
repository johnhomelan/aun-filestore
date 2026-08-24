<?php

/*
 * @group unit-tests
 *
 * Unit tests for HomeLan\FileStore\Services\Provider\Teletext\NewsFeedParser.
 *
 * Pure logic, no network access — every test feeds a literal RSS fixture
 * string straight into parse(), exercising the link-filter patterns used
 * for each real feed (see NewsFeedDefinitions).
 */

use PHPUnit\Framework\TestCase;
use HomeLan\FileStore\Services\Provider\Teletext\NewsFeedParser;
use HomeLan\FileStore\Services\Provider\Teletext\NewsFeedDefinitions;

include_once(__DIR__ . '/../../src/include/system.inc.php');

class NewsFeedParserTest extends TestCase
{
    protected NewsFeedParser $oParser;

    protected function setUp(): void
    {
        $this->oParser = new NewsFeedParser();
    }

    protected function _item(string $sTitle, string $sLink, ?string $sPubDate = 'Sat, 22 Aug 2026 19:28:14 GMT', array $aCategories = []): string
    {
        $sPubDateTag = $sPubDate === null ? '' : '<pubDate>' . $sPubDate . '</pubDate>';
        $sCategoryTags = implode('', array_map(fn (string $s) => '<category>' . $s . '</category>', $aCategories));
        return '<item>'
            . '<title><![CDATA[' . $sTitle . ']]></title>'
            . '<description><![CDATA[Some description]]></description>'
            . '<link>' . $sLink . '</link>'
            . $sPubDateTag
            . $sCategoryTags
            . '</item>';
    }

    protected function _feed(array $aItemXmlBlocks): string
    {
        return '<?xml version="1.0" encoding="UTF-8"?><rss version="2.0"><channel><title>Feed</title>'
            . implode('', $aItemXmlBlocks)
            . '</channel></rss>';
    }

    public function testEmptyFeedReturnsEmptyArray(): void
    {
        $this->assertSame([], $this->oParser->parse('', '#.#'));
    }

    public function testMalformedXmlReturnsEmptyArray(): void
    {
        $this->assertSame([], $this->oParser->parse('<not-xml', '#.#'));
    }

    public function testDeduplicatesByLink(): void
    {
        $sXml = $this->_feed([
            $this->_item('First mention', 'https://www.bbc.co.uk/news/articles/abc123'),
            $this->_item('Second mention', 'https://www.bbc.co.uk/news/articles/abc123'),
        ]);

        $aItems = $this->oParser->parse($sXml, NewsFeedDefinitions::get('bbc')->sLinkFilterPattern);

        $this->assertCount(1, $aItems);
        $this->assertSame('First mention', $aItems[0]['title']);
    }

    public function testMissingPubDateIsNull(): void
    {
        $sXml = $this->_feed([
            $this->_item('No date here', 'https://www.bbc.co.uk/news/articles/nodate', null),
        ]);

        $aItems = $this->oParser->parse($sXml, NewsFeedDefinitions::get('bbc')->sLinkFilterPattern);

        $this->assertCount(1, $aItems);
        $this->assertNull($aItems[0]['pubDate']);
    }

    public function testSkipsItemsWithEmptyTitleOrLink(): void
    {
        $sXml = $this->_feed([
            $this->_item('', 'https://www.bbc.co.uk/news/articles/emptytitle'),
        ]);

        $this->assertSame([], $this->oParser->parse($sXml, NewsFeedDefinitions::get('bbc')->sLinkFilterPattern));
    }

    // -------------------------------------------------------------------------
    // Category
    // -------------------------------------------------------------------------

    public function testCategoryIsCaptured(): void
    {
        $sXml = $this->_feed([
            $this->_item('A story', 'https://www.bbc.co.uk/news/articles/cat1', aCategories: ['Politics']),
        ]);

        $aItems = $this->oParser->parse($sXml, NewsFeedDefinitions::get('bbc')->sLinkFilterPattern);

        $this->assertSame('Politics', $aItems[0]['category']);
    }

    public function testFirstNonEmptyCategoryWinsWhenMultiplePresent(): void
    {
        $sXml = $this->_feed([
            $this->_item('A story', 'https://www.bbc.co.uk/news/articles/cat2', aCategories: ['', 'World', 'Politics']),
        ]);

        $aItems = $this->oParser->parse($sXml, NewsFeedDefinitions::get('bbc')->sLinkFilterPattern);

        $this->assertSame('World', $aItems[0]['category']);
    }

    public function testMissingCategoryIsEmptyString(): void
    {
        $sXml = $this->_feed([
            $this->_item('A story', 'https://www.bbc.co.uk/news/articles/nocat'),
        ]);

        $aItems = $this->oParser->parse($sXml, NewsFeedDefinitions::get('bbc')->sLinkFilterPattern);

        $this->assertSame('', $aItems[0]['category']);
    }

    // -------------------------------------------------------------------------
    // BBC's link filter
    // -------------------------------------------------------------------------

    public function testBbcFiltersOutNonArticleLinks(): void
    {
        $sXml = $this->_feed([
            $this->_item('A video', 'https://www.bbc.co.uk/news/videos/c4gxgpqzdz6o'),
            $this->_item('A live page', 'https://www.bbc.co.uk/news/live/cx272np7vgyo'),
            $this->_item('A real story', 'https://www.bbc.co.uk/news/articles/cx272np7vgyo'),
        ]);

        $aItems = $this->oParser->parse($sXml, NewsFeedDefinitions::get('bbc')->sLinkFilterPattern);

        $this->assertCount(1, $aItems);
        $this->assertSame('A real story', $aItems[0]['title']);
    }

    // -------------------------------------------------------------------------
    // Guardian's link filter (date-slug path pattern)
    // -------------------------------------------------------------------------

    public function testGuardianFiltersToDateSlugArticleLinks(): void
    {
        $sXml = $this->_feed([
            $this->_item('Section front', 'https://www.theguardian.com/uk'),
            $this->_item('Homepage', 'https://www.theguardian.com'),
            $this->_item('A real story', 'https://www.theguardian.com/money/2026/aug/22/plug-in-solar-cost-panel-cut-bills-balcony'),
        ]);

        $aItems = $this->oParser->parse($sXml, NewsFeedDefinitions::get('guardian')->sLinkFilterPattern);

        $this->assertCount(1, $aItems);
        $this->assertSame('A real story', $aItems[0]['title']);
    }

    // -------------------------------------------------------------------------
    // Sky's link filter (/story/ prefix)
    // -------------------------------------------------------------------------

    public function testSkyFiltersToStoryLinks(): void
    {
        $sXml = $this->_feed([
            $this->_item('Home page', 'https://news.sky.com/home'),
            $this->_item('A real story', 'https://news.sky.com/story/some-headline-13575681'),
        ]);

        $aItems = $this->oParser->parse($sXml, NewsFeedDefinitions::get('sky')->sLinkFilterPattern);

        $this->assertCount(1, $aItems);
        $this->assertSame('A real story', $aItems[0]['title']);
    }
}
