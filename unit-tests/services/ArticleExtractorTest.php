<?php

/*
 * @group unit-tests
 *
 * Unit tests for HomeLan\FileStore\Services\Provider\Teletext\ArticleExtractor.
 *
 * Pure logic, no network access — every test feeds a literal HTML fixture
 * straight into extract(), against the real ArticleProfiles configured in
 * NewsFeedDefinitions (BBC/Guardian/Sky), each modelled on markup verified
 * live while designing the news-import feature (Sky's could not be
 * verified live — see the plan — so its fixture only exercises the
 * best-effort profile's own documented behaviour).
 */

use PHPUnit\Framework\TestCase;
use HomeLan\FileStore\Services\Provider\Teletext\ArticleExtractor;
use HomeLan\FileStore\Services\Provider\Teletext\NewsFeedDefinitions;

include_once(__DIR__ . '/../../src/include/system.inc.php');

class ArticleExtractorTest extends TestCase
{
    protected ArticleExtractor $oExtractor;

    protected function setUp(): void
    {
        $this->oExtractor = new ArticleExtractor();
    }

    protected function _page(string $sArticleInner, string $sHead = ''): string
    {
        return '<html><head>' . $sHead . '</head><body><article>' . $sArticleInner . '</article></body></html>';
    }

    // -------------------------------------------------------------------------
    // Shape / no-container
    // -------------------------------------------------------------------------

    public function testNoContainerFoundReturnsEmpty(): void
    {
        $aResult = $this->oExtractor->extract(
            '<html><body><p>no article here</p></body></html>',
            NewsFeedDefinitions::get('bbc')->oArticleProfile
        );

        $this->assertSame('', $aResult['headline']);
        $this->assertNull($aResult['published']);
        $this->assertSame([], $aResult['blocks']);
    }

    // -------------------------------------------------------------------------
    // BBC profile (class-substring paragraphs/headings, time-element date)
    // -------------------------------------------------------------------------

    public function testBbcExtractsHeadlinePublishedAndParagraphs(): void
    {
        $sHtml = $this->_page(
            '<h1 class="ssrcss-bh9yn6-Heading e10rt3ze0">Carney calls tariffs a miscalculation</h1>'
            . '<time datetime="2026-08-22T15:44:57.188Z">22 August 2026, 16:44 BST</time>'
            . '<p class="ssrcss-1q0x1qg-Paragraph e1jhz7w10">First paragraph of the story.</p>'
            . '<p class="ssrcss-1q0x1qg-Paragraph e1jhz7w10">Second paragraph of the story.</p>'
        );

        $aResult = $this->oExtractor->extract($sHtml, NewsFeedDefinitions::get('bbc')->oArticleProfile);

        $this->assertSame('Carney calls tariffs a miscalculation', $aResult['headline']);
        $this->assertSame('22 August 2026, 16:44 BST', $aResult['published']);
        $this->assertSame(
            [
                ['type' => 'paragraph', 'text' => 'First paragraph of the story.'],
                ['type' => 'paragraph', 'text' => 'Second paragraph of the story.'],
            ],
            $aResult['blocks']
        );
    }

    public function testBbcExtractsSubheadingsListsAndQuotes(): void
    {
        $sHtml = $this->_page(
            '<h1 class="ssrcss-bh9yn6-Heading e10rt3ze0">Headline</h1>'
            . '<p class="ssrcss-1q0x1qg-Paragraph e1jhz7w10">Intro paragraph.</p>'
            . '<h2 class="ssrcss-89o2pv-Heading e10rt3ze0">A subheading</h2>'
            . '<p class="ssrcss-1q0x1qg-Paragraph e1jhz7w10">More detail.</p>'
            . '<ul><li>First point</li><li>Second point</li></ul>'
            . '<blockquote>Something someone said.</blockquote>'
        );

        $aResult = $this->oExtractor->extract($sHtml, NewsFeedDefinitions::get('bbc')->oArticleProfile);

        $this->assertSame(
            [
                ['type' => 'paragraph', 'text' => 'Intro paragraph.'],
                ['type' => 'heading', 'text' => 'A subheading'],
                ['type' => 'paragraph', 'text' => 'More detail.'],
                ['type' => 'list-item', 'text' => 'First point'],
                ['type' => 'list-item', 'text' => 'Second point'],
                ['type' => 'quote', 'text' => 'Something someone said.'],
            ],
            $aResult['blocks']
        );
    }

    public function testBbcPreservesStrongAndEmAsPrivateMarkers(): void
    {
        $sHtml = $this->_page(
            '<h1>Headline</h1>'
            . '<p class="ssrcss-1q0x1qg-Paragraph e1jhz7w10">A <strong>bold</strong> and <em>italic</em> word.</p>'
        );

        $aResult = $this->oExtractor->extract($sHtml, NewsFeedDefinitions::get('bbc')->oArticleProfile);

        $this->assertSame("A \x01bold\x02 and \x03italic\x04 word.", $aResult['blocks'][0]['text']);
    }

    public function testBbcStopsAtRelatedTopicsHeading(): void
    {
        $sHtml = $this->_page(
            '<h1>Headline</h1>'
            . '<p class="ssrcss-1q0x1qg-Paragraph e1jhz7w10">Real body text.</p>'
            . '<h2 class="ssrcss-89o2pv-Heading e10rt3ze0">Related topics</h2>'
            . '<li>Canada</li>'
            . '<li>United States</li>'
        );

        $aResult = $this->oExtractor->extract($sHtml, NewsFeedDefinitions::get('bbc')->oArticleProfile);

        $this->assertSame([['type' => 'paragraph', 'text' => 'Real body text.']], $aResult['blocks']);
    }

    public function testBbcSkipsVideoCaptionBoilerplate(): void
    {
        $sHtml = $this->_page(
            '<h1>Headline</h1>'
            . '<p class="ssrcss-1q0x1qg-Paragraph e1jhz7w10">This video can not be played</p>'
            . '<p class="ssrcss-1q0x1qg-Paragraph e1jhz7w10">Watch: something happened</p>'
            . '<p class="ssrcss-1q0x1qg-Paragraph e1jhz7w10">Actual story text.</p>'
        );

        $aResult = $this->oExtractor->extract($sHtml, NewsFeedDefinitions::get('bbc')->oArticleProfile);

        $this->assertSame([['type' => 'paragraph', 'text' => 'Actual story text.']], $aResult['blocks']);
    }

    public function testBbcExcludesMetadataStripAndLinkItemListElements(): void
    {
        $sHtml = $this->_page(
            '<h1>Headline</h1>'
            . '<li class="ssrcss-sf7vdp-MetadataStripItem eh44mf01">Published 22 August 2026</li>'
            . '<p class="ssrcss-1q0x1qg-Paragraph e1jhz7w10">Real body text.</p>'
            . '<li class="ssrcss-qzx51b-LinkItem e3eyuya1">Related link headline</li>'
        );

        $aResult = $this->oExtractor->extract($sHtml, NewsFeedDefinitions::get('bbc')->oArticleProfile);

        $this->assertSame([['type' => 'paragraph', 'text' => 'Real body text.']], $aResult['blocks']);
    }

    // -------------------------------------------------------------------------
    // Guardian profile (majority-class paragraphs, any h2/h3, meta-tag date)
    // -------------------------------------------------------------------------

    public function testGuardianExtractsViaMajorityClassAndMetaDate(): void
    {
        $sHtml = $this->_page(
            '<h1>Plug-in solar: could a low-cost panel cut your bills?</h1>'
            . '<p class="dcr-1s160rg">First real paragraph.</p>'
            . '<h2 class="dcr-7d9sx6">How the kits work</h2>'
            . '<p class="dcr-1s160rg">Second real paragraph.</p>'
            . '<p class="dcr-caption">A picture caption, different class, should be excluded.</p>',
            '<meta property="article:published_time" content="2026-08-22T09:00:03.000Z"/>'
        );

        $aResult = $this->oExtractor->extract($sHtml, NewsFeedDefinitions::get('guardian')->oArticleProfile);

        $this->assertSame('Plug-in solar: could a low-cost panel cut your bills?', $aResult['headline']);
        $this->assertSame('22 August 2026, 09:00', $aResult['published']);
        $this->assertSame(
            [
                ['type' => 'paragraph', 'text' => 'First real paragraph.'],
                ['type' => 'heading', 'text' => 'How the kits work'],
                ['type' => 'paragraph', 'text' => 'Second real paragraph.'],
            ],
            $aResult['blocks']
        );
    }

    public function testGuardianMissingMetaDateIsNull(): void
    {
        $sHtml = $this->_page('<h1>Headline</h1><p class="dcr-1s160rg">Some text.</p>');

        $aResult = $this->oExtractor->extract($sHtml, NewsFeedDefinitions::get('guardian')->oArticleProfile);

        $this->assertNull($aResult['published']);
    }

    // -------------------------------------------------------------------------
    // Sky profile (best-effort: container fallback + majority-class)
    // -------------------------------------------------------------------------

    public function testSkyExtractsFromArticleContainer(): void
    {
        $sHtml = $this->_page(
            '<h1>Some Sky headline</h1>'
            . '<time datetime="2026-08-22T13:00:00Z">22 August 2026</time>'
            . '<p class="sdc-article-body__paragraph">First paragraph.</p>'
            . '<p class="sdc-article-body__paragraph">Second paragraph.</p>'
        );

        $aResult = $this->oExtractor->extract($sHtml, NewsFeedDefinitions::get('sky')->oArticleProfile);

        $this->assertSame('Some Sky headline', $aResult['headline']);
        $this->assertSame('22 August 2026', $aResult['published']);
        $this->assertSame(
            [
                ['type' => 'paragraph', 'text' => 'First paragraph.'],
                ['type' => 'paragraph', 'text' => 'Second paragraph.'],
            ],
            $aResult['blocks']
        );
    }

    public function testSkyFallsBackToArticleBodyContainerWhenNoArticleTag(): void
    {
        $sHtml = '<html><body><div class="page-wrapper">'
            . '<div class="article-body">'
            . '<h1>Fallback headline</h1>'
            . '<p class="body-text">Fallback paragraph.</p>'
            . '</div></div></body></html>';

        $aResult = $this->oExtractor->extract($sHtml, NewsFeedDefinitions::get('sky')->oArticleProfile);

        $this->assertSame('Fallback headline', $aResult['headline']);
        $this->assertSame([['type' => 'paragraph', 'text' => 'Fallback paragraph.']], $aResult['blocks']);
    }
}
