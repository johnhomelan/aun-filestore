<?php

/*
 * @group unit-tests
 *
 * Unit tests for HomeLan\FileStore\Services\Provider\Teletext\NewsPageComposer.
 *
 * Pure logic, no network/filesystem access — asserts exact buffer shape and
 * byte placement for known small inputs. Buffers are Storage-shaped: 25
 * rows of 40 bytes, padded to Storage::PAGE_SIZE.
 */

use PHPUnit\Framework\TestCase;
use HomeLan\FileStore\Services\Provider\Teletext\NewsPageComposer;
use HomeLan\FileStore\Services\Provider\Teletext\NewsFeedDefinitions;
use HomeLan\FileStore\Services\Provider\Teletext\Storage;

include_once(__DIR__ . '/../../src/include/system.inc.php');

class NewsPageComposerTest extends TestCase
{
    protected NewsPageComposer $oComposer;
    protected \DateTimeImmutable $oNow;

    protected function setUp(): void
    {
        $this->oComposer = new NewsPageComposer();
        $this->oNow = new \DateTimeImmutable('2026-08-22 16:44:00');
    }

    protected function _row(string $sBuffer, int $iRow): string
    {
        return substr($sBuffer, $iRow * 40, 40);
    }

    /** Strips teletext control bytes (0x00-0x1F) so plain substring assertions can be made against text content. */
    protected function _plain(string $sRow): string
    {
        return preg_replace('/[\x00-\x1f]/', '', $sRow) ?? '';
    }

    // -------------------------------------------------------------------------
    // Shared shape
    // -------------------------------------------------------------------------

    public function testIndexBuffersAreExactlyPageSize(): void
    {
        $aBuffers = $this->oComposer->composeIndex(
            '100',
            [['page' => '101', 'headline' => 'A story']],
            $this->oNow,
            'BBC NEWS',
            'BBC NEWS HEADLINES',
            NewsPageComposer::WHITE,
            NewsPageComposer::RED
        );

        $this->assertCount(1, $aBuffers);
        $this->assertSame(Storage::PAGE_SIZE, strlen($aBuffers[0]));
    }

    public function testStoryBuffersAreExactlyPageSize(): void
    {
        $aBuffers = $this->oComposer->composeStory(
            '101',
            'Headline',
            '22 August 2026, 16:44 BST',
            [['type' => 'paragraph', 'text' => 'Some body text.']],
            $this->oNow,
            'BBC NEWS'
        );

        $this->assertCount(1, $aBuffers);
        $this->assertSame(Storage::PAGE_SIZE, strlen($aBuffers[0]));
    }

    // -------------------------------------------------------------------------
    // Index masthead/banner — parameterized per feed
    // -------------------------------------------------------------------------

    public function testIndexMastheadAndBannerUseSuppliedTitleAndBannerText(): void
    {
        $aBuffers = $this->oComposer->composeIndex('100', [], $this->oNow, 'BBC NEWS', 'BBC NEWS HEADLINES', NewsPageComposer::WHITE, NewsPageComposer::RED);

        $this->assertStringContainsString('P100', $this->_plain($this->_row($aBuffers[0], 0)));
        $this->assertStringContainsString('BBC NEWS', $this->_plain($this->_row($aBuffers[0], 0)));
        $this->assertStringContainsString('BBC NEWS HEADLINES', $this->_plain($this->_row($aBuffers[0], 1)));
    }

    public function testEachRealFeedGetsADistinctMastheadAndBannerColour(): void
    {
        foreach (NewsFeedDefinitions::all() as $oFeed) {
            $aBuffers = $this->oComposer->composeIndex(
                '100',
                [],
                $this->oNow,
                $oFeed->sMastheadTitle,
                $oFeed->sBannerText,
                $oFeed->iBannerForeground,
                $oFeed->iBannerBackground
            );

            $sRow0 = $this->_plain($this->_row($aBuffers[0], 0));
            $sRow1 = $this->_row($aBuffers[0], 1);

            $this->assertStringContainsString($oFeed->sMastheadTitle, $sRow0, $oFeed->sKey . ' masthead title');
            $this->assertStringContainsString($oFeed->sBannerText, $this->_plain($sRow1), $oFeed->sKey . ' banner text');
            $this->assertStringContainsString(chr($oFeed->iBannerBackground), $sRow1, $oFeed->sKey . ' banner background byte');
            $this->assertStringContainsString(chr($oFeed->iBannerForeground), $sRow1, $oFeed->sKey . ' banner foreground byte');
            $this->assertStringContainsString(chr(0x0D), $sRow1, $oFeed->sKey . ' banner is double-height');
        }
    }

    public function testIndexListsEntries(): void
    {
        $aEntries = [
            ['page' => '101', 'headline' => 'First story headline'],
            ['page' => '102', 'headline' => 'Second story headline'],
        ];
        $aBuffers = $this->oComposer->composeIndex('100', $aEntries, $this->oNow, 'BBC NEWS', 'BBC NEWS HEADLINES', NewsPageComposer::WHITE, NewsPageComposer::RED);

        $sRow3 = $this->_plain($this->_row($aBuffers[0], 3));
        $sRow4 = $this->_plain($this->_row($aBuffers[0], 4));
        $this->assertStringContainsString('101', $sRow3);
        $this->assertStringContainsString('First story headline', $sRow3);
        $this->assertStringContainsString('102', $sRow4);
        $this->assertStringContainsString('Second story headline', $sRow4);
    }

    public function testIndexPaginatesAcrossSubpagesWhenMoreThanTwentyOneEntries(): void
    {
        $aEntries = [];
        for ($i = 1; $i <= 25; $i++) {
            $aEntries[] = ['page' => (string) (100 + $i), 'headline' => 'Story number ' . $i];
        }

        $aBuffers = $this->oComposer->composeIndex('100', $aEntries, $this->oNow, 'BBC NEWS', 'BBC NEWS HEADLINES', NewsPageComposer::WHITE, NewsPageComposer::RED);

        $this->assertCount(2, $aBuffers);
        $this->assertStringContainsString('Story number 1', $this->_plain($this->_row($aBuffers[0], 3)));
        $this->assertStringContainsString('Story number 22', $this->_plain($this->_row($aBuffers[1], 3)));
        $this->assertStringContainsString('Subpage 1/2', $this->_plain($this->_row($aBuffers[0], 24)));
        $this->assertStringContainsString('Subpage 2/2', $this->_plain($this->_row($aBuffers[1], 24)));
    }

    // -------------------------------------------------------------------------
    // Story
    // -------------------------------------------------------------------------

    public function testStoryHeadlineIsDoubleHeightOnFirstSubpage(): void
    {
        $aBuffers = $this->oComposer->composeStory('101', 'A Big Headline', null, [['type' => 'paragraph', 'text' => 'Body.']], $this->oNow, 'BBC NEWS');

        $sRow1 = $this->_row($aBuffers[0], 1);
        $this->assertStringContainsString(chr(0x0D), $sRow1);
        $this->assertStringContainsString('A Big Headline', $this->_plain($sRow1));
    }

    public function testStoryMastheadUsesSuppliedTitle(): void
    {
        foreach (NewsFeedDefinitions::all() as $oFeed) {
            $aBuffers = $this->oComposer->composeStory('101', 'Headline', null, [['type' => 'paragraph', 'text' => 'Body.']], $this->oNow, $oFeed->sMastheadTitle);

            $this->assertStringContainsString($oFeed->sMastheadTitle, $this->_plain($this->_row($aBuffers[0], 0)), $oFeed->sKey . ' story masthead');
        }
    }

    public function testStoryPublishedDateShown(): void
    {
        $aBuffers = $this->oComposer->composeStory('101', 'Headline', '22 August 2026, 16:44 BST', [['type' => 'paragraph', 'text' => 'Body.']], $this->oNow, 'BBC NEWS');

        $this->assertStringContainsString('22 August 2026, 16:44 BST', $this->_plain($this->_row($aBuffers[0], 5)));
    }

    public function testStoryNoPublishedDateLeavesBlankRow(): void
    {
        $aBuffers = $this->oComposer->composeStory('101', 'Headline', null, [['type' => 'paragraph', 'text' => 'Body.']], $this->oNow, 'BBC NEWS');

        $this->assertSame(str_repeat(' ', 40), $this->_row($aBuffers[0], 5));
    }

    public function testStoryBodyBlockTypesUseDistinctColours(): void
    {
        $aBlocks = [
            ['type' => 'heading', 'text' => 'Sub head'],
            ['type' => 'paragraph', 'text' => 'A paragraph.'],
            ['type' => 'list-item', 'text' => 'A bullet'],
            ['type' => 'quote', 'text' => 'A quotation'],
        ];
        $aBuffers = $this->oComposer->composeStory('101', 'Headline', null, $aBlocks, $this->oNow, 'BBC NEWS');
        $sBuffer = $aBuffers[0];

        $sHeadingRow = $this->_row($sBuffer, 6);
        $this->assertStringContainsString(chr(0x03), $sHeadingRow); // yellow
        $this->assertStringContainsString('Sub head', $this->_plain($sHeadingRow));

        $sParagraphRow = $this->_row($sBuffer, 8);
        $this->assertStringContainsString(chr(0x07), $sParagraphRow); // white
        $this->assertStringContainsString('A paragraph.', $this->_plain($sParagraphRow));

        $sListRow = $this->_row($sBuffer, 10);
        $this->assertStringContainsString(chr(0x02), $sListRow); // green
        $this->assertStringContainsString('- A bullet', $this->_plain($sListRow));

        $sQuoteRow = $this->_row($sBuffer, 12);
        $this->assertStringContainsString(chr(0x06), $sQuoteRow); // cyan
        $this->assertStringContainsString('> A quotation', $this->_plain($sQuoteRow));
    }

    public function testStoryPaginatesWhenBodyExceedsFirstSubpageCapacity(): void
    {
        $aBlocks = [];
        for ($i = 1; $i <= 20; $i++) {
            $aBlocks[] = ['type' => 'paragraph', 'text' => 'Paragraph number ' . $i . '.'];
        }

        $aBuffers = $this->oComposer->composeStory('101', 'Headline', null, $aBlocks, $this->oNow, 'BBC NEWS');

        $this->assertGreaterThan(1, count($aBuffers));
        $sRow1Continuation = $this->_row($aBuffers[1], 1);
        $this->assertStringNotContainsString(chr(0x0D), $sRow1Continuation);
        $this->assertStringContainsString('Headline', $this->_plain($sRow1Continuation));
        $this->assertStringContainsString('p 1/' . count($aBuffers), $this->_plain($this->_row($aBuffers[0], 24)));
    }

    public function testStorySingleSubpageHasNoFooterHint(): void
    {
        $aBuffers = $this->oComposer->composeStory('101', 'Headline', null, [['type' => 'paragraph', 'text' => 'Short.']], $this->oNow, 'BBC NEWS');

        $this->assertCount(1, $aBuffers);
        $this->assertStringNotContainsString('p 1/1', $this->_plain($this->_row($aBuffers[0], 24)));
    }

    public function testInlineStrongAndEmMarkersBecomeColourChanges(): void
    {
        $aBlocks = [['type' => 'paragraph', 'text' => "A \x01bold\x02 and \x03italic\x04 word."]];
        $aBuffers = $this->oComposer->composeStory('101', 'Headline', null, $aBlocks, $this->oNow, 'BBC NEWS');

        $sRow = $this->_row($aBuffers[0], 6);
        $this->assertStringContainsString(chr(0x03), $sRow); // yellow for strong
        $this->assertStringContainsString(chr(0x06), $sRow); // cyan for em
        $this->assertStringContainsString('bold', $this->_plain($sRow));
        $this->assertStringContainsString('italic', $this->_plain($sRow));
    }

    // -------------------------------------------------------------------------
    // Text sanitization — scraped article text is UTF-8 and commonly
    // contains typographic punctuation with no G0 representation; left
    // unhandled this corrupts mid-headline (seen live while verifying the
    // Guardian feed: a curly apostrophe broke "Gen Z's" into mojibake).
    // -------------------------------------------------------------------------

    public function testSmartQuotesDashesAndEllipsisAreTransliterated(): void
    {
        $sText = "It\xE2\x80\x99s the \xE2\x80\x9Cbest\xE2\x80\x9D result \xE2\x80\x94 so far\xE2\x80\xA6";
        $aBuffers = $this->oComposer->composeStory('101', 'Headline', null, [['type' => 'paragraph', 'text' => $sText]], $this->oNow, 'BBC NEWS');

        $sPlain = $this->_plain($this->_row($aBuffers[0], 6));
        $this->assertSame('It\'s the "best" result - so far...', trim($sPlain));
    }

    public function testPoundSignIsMappedToG0CodePoint(): void
    {
        $sText = "Costs \xC2\xA315bn in total.";
        $aBuffers = $this->oComposer->composeStory('101', 'Headline', null, [['type' => 'paragraph', 'text' => $sText]], $this->oNow, 'BBC NEWS');

        $sRow = $this->_row($aBuffers[0], 6);
        // £ is transliterated to the raw byte 0x23 - the G0 code point a
        // real teletext decoder renders as "£" - not left as multi-byte UTF-8.
        $this->assertStringContainsString(chr(0x23) . '15bn', $sRow);
    }

    public function testAccentedLatinCharactersAreTransliteratedToAscii(): void
    {
        $sText = "A caf\xC3\xA9 in Bj\xC3\xB6rk's na\xC3\xAFve home town.";
        $aBuffers = $this->oComposer->composeStory('101', 'Headline', null, [['type' => 'paragraph', 'text' => $sText]], $this->oNow, 'BBC NEWS');

        $sPlain = trim($this->_plain($this->_row($aBuffers[0], 6)));
        $this->assertMatchesRegularExpression('/^[\x20-\x7E]*$/', $sPlain);
        $this->assertStringContainsString('cafe', $sPlain);
    }

    public function testLongParagraphWrapsAcrossMultipleRows(): void
    {
        $sLongText = implode(' ', array_fill(0, 30, 'word'));
        $aBuffers = $this->oComposer->composeStory('101', 'Headline', null, [['type' => 'paragraph', 'text' => $sLongText]], $this->oNow, 'BBC NEWS');

        $sRow6 = $this->_plain($this->_row($aBuffers[0], 6));
        $sRow7 = $this->_plain($this->_row($aBuffers[0], 7));
        $this->assertNotSame('', trim($sRow6));
        $this->assertNotSame('', trim($sRow7));
        $this->assertLessThanOrEqual(40, strlen($this->_row($aBuffers[0], 6)));
    }
}
