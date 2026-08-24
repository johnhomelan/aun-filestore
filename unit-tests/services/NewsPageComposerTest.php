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
            'BBC NEWS',
            NewsPageComposer::WHITE,
            NewsPageComposer::RED
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

        // Row 3 is the blank line between the page title (banner) and the
        // first article title. Each entry is then a title row followed by
        // its own blank spacer row, so the second entry starts two rows
        // after the first, not immediately below it.
        $sRow3 = $this->_row($aBuffers[0], 3);
        $sRow4 = $this->_plain($this->_row($aBuffers[0], 4));
        $sRow5 = $this->_row($aBuffers[0], 5);
        $sRow6 = $this->_plain($this->_row($aBuffers[0], 6));
        $this->assertSame(str_repeat(' ', 40), $sRow3, 'blank line between page title and first article title');
        $this->assertStringContainsString('First story headline', $sRow4);
        $this->assertStringContainsString('101', $sRow4);
        $this->assertSame(str_repeat(' ', 40), $sRow5, 'blank line between article titles');
        $this->assertStringContainsString('Second story headline', $sRow6);
        $this->assertStringContainsString('102', $sRow6);
    }

    public function testIndexEntryPageNumberIsOnTheRight(): void
    {
        $aEntries = [['page' => '101', 'headline' => 'Short']];
        $aBuffers = $this->oComposer->composeIndex('100', $aEntries, $this->oNow, 'BBC NEWS', 'BBC NEWS HEADLINES', NewsPageComposer::WHITE, NewsPageComposer::RED);

        $sRow4 = $this->_row($aBuffers[0], 4);
        $iHeadlinePos = strpos($this->_plain($sRow4), 'Short');
        $iPagePos = strpos($this->_plain($sRow4), '101');
        $this->assertNotFalse($iHeadlinePos);
        $this->assertNotFalse($iPagePos);
        $this->assertGreaterThan($iHeadlinePos, $iPagePos, 'page number should sit to the right of the title');
        $this->assertGreaterThanOrEqual(35, $iPagePos, 'page number should be right-aligned in its own column');
    }

    public function testIndexEntryTitleWrapsWithoutTextUnderThePageNumber(): void
    {
        $sLongHeadline = 'A very long headline that will not fit onto a single teletext row at all';
        $aEntries = [['page' => '101', 'headline' => $sLongHeadline]];
        $aBuffers = $this->oComposer->composeIndex('100', $aEntries, $this->oNow, 'BBC NEWS', 'BBC NEWS HEADLINES', NewsPageComposer::WHITE, NewsPageComposer::RED);

        $sRow4 = $this->_row($aBuffers[0], 4);
        $sRow5 = $this->_row($aBuffers[0], 5);
        $sRow6 = $this->_row($aBuffers[0], 6);

        // Row 4 carries the page number in the last 5 columns.
        $this->assertStringContainsString('101', $this->_plain(substr($sRow4, -5)));
        // Row 5 is the wrapped second title line - its last 5 columns (the
        // page number's own column) must stay blank, not carry title text.
        $this->assertSame(str_repeat(' ', 5), substr($sRow5, -5), 'no title text under the page number column');
        $this->assertNotSame('', trim($this->_plain($sRow5)), 'wrapped title text is still present');
        // Row 6 is the blank spacer between this entry and the next.
        $this->assertSame(str_repeat(' ', 40), $sRow6);
    }

    public function testIndexEntriesCanBeGroupedByCategoryWithYellowHeadings(): void
    {
        $aEntries = [
            ['page' => '101', 'headline' => 'Election called', 'category' => 'Politics'],
            ['page' => '102', 'headline' => 'Budget announced', 'category' => 'Business'],
            ['page' => '103', 'headline' => 'Second politics story', 'category' => 'Politics'],
        ];
        $aBuffers = $this->oComposer->composeIndex('100', $aEntries, $this->oNow, 'BBC NEWS', 'BBC NEWS HEADLINES', NewsPageComposer::WHITE, NewsPageComposer::RED);
        $sBuffer = $aBuffers[0];

        $sRow4 = $this->_row($sBuffer, 4);
        $this->assertStringContainsString(chr(NewsPageComposer::YELLOW), $sRow4);
        $this->assertStringContainsString('POLITICS', $this->_plain($sRow4));

        $sRow5 = $this->_plain($this->_row($sBuffer, 5));
        $this->assertStringContainsString('Election called', $sRow5);

        $sPlainWhole = $this->_plain($sBuffer);
        $this->assertStringContainsString('BUSINESS', $sPlainWhole);
        $this->assertStringContainsString('Budget announced', $sPlainWhole);
        $this->assertStringContainsString('Second politics story', $sPlainWhole);
    }

    public function testIndexEntriesWithNoCategoryGetNoHeadings(): void
    {
        $aEntries = [
            ['page' => '101', 'headline' => 'First story headline'],
            ['page' => '102', 'headline' => 'Second story headline'],
        ];
        $aBuffers = $this->oComposer->composeIndex('100', $aEntries, $this->oNow, 'BBC NEWS', 'BBC NEWS HEADLINES', NewsPageComposer::WHITE, NewsPageComposer::RED);

        $this->assertStringContainsString('First story headline', $this->_plain($this->_row($aBuffers[0], 4)));
    }

    public function testIndexPaginatesAcrossSubpagesWhenMoreThanTenEntriesPerSubpage(): void
    {
        $aEntries = [];
        for ($i = 1; $i <= 25; $i++) {
            $aEntries[] = ['page' => (string) (100 + $i), 'headline' => 'Story number ' . $i];
        }

        $aBuffers = $this->oComposer->composeIndex('100', $aEntries, $this->oNow, 'BBC NEWS', 'BBC NEWS HEADLINES', NewsPageComposer::WHITE, NewsPageComposer::RED);

        // Each entry now takes a title row plus a blank spacer row, so only
        // 10 entries (20 of the 21 available body rows) fit per subpage.
        $this->assertCount(3, $aBuffers);
        $this->assertStringContainsString('101', $this->_plain($aBuffers[0]));
        $this->assertStringNotContainsString('111', $this->_plain($aBuffers[0]));
        $this->assertStringContainsString('111', $this->_plain($aBuffers[1]));
        $this->assertStringContainsString('125', $this->_plain($aBuffers[2]));
        $this->assertStringContainsString('Subpage 1/3', $this->_plain($this->_row($aBuffers[0], 24)));
        $this->assertStringContainsString('Subpage 3/3', $this->_plain($this->_row($aBuffers[2], 24)));
    }

    // -------------------------------------------------------------------------
    // Story
    // -------------------------------------------------------------------------

    public function testStoryHeadlineIsDoubleHeightOnFirstSubpage(): void
    {
        $aBuffers = $this->oComposer->composeStory('101', 'A Big Headline', null, [['type' => 'paragraph', 'text' => 'Body.']], $this->oNow, 'BBC NEWS', NewsPageComposer::WHITE, NewsPageComposer::RED);

        $sRow1 = $this->_row($aBuffers[0], 1);
        $this->assertStringContainsString(chr(0x0D), $sRow1);
        $this->assertStringContainsString('A Big Headline', $this->_plain($sRow1));
    }

    public function testStoryHeadlineHasAColouredNonBlackBackground(): void
    {
        foreach (NewsFeedDefinitions::all() as $oFeed) {
            $aBuffers = $this->oComposer->composeStory(
                '101',
                'A Big Headline',
                null,
                [['type' => 'paragraph', 'text' => 'Body.']],
                $this->oNow,
                $oFeed->sMastheadTitle,
                $oFeed->iHeadlineForeground,
                $oFeed->iHeadlineBackground
            );

            $sRow1 = $this->_row($aBuffers[0], 1);
            $sRow2 = $this->_row($aBuffers[0], 2);
            $this->assertNotSame(NewsPageComposer::BLACK, $oFeed->iHeadlineBackground, $oFeed->sKey . ' headline background must not be black');
            $this->assertStringContainsString(chr($oFeed->iHeadlineBackground), $sRow1, $oFeed->sKey . ' headline row background byte');
            $this->assertStringContainsString(chr(0x1D), $sRow1, $oFeed->sKey . ' headline row sets a new background');
            // The band continues onto the row below (the double-height
            // glyph's lower half on real hardware), so it also carries the
            // background colour rather than reverting to plain blank.
            $this->assertStringContainsString(chr($oFeed->iHeadlineBackground), $sRow2, $oFeed->sKey . ' headline background band continues');
        }
    }

    public function testStoryMastheadUsesSuppliedTitle(): void
    {
        foreach (NewsFeedDefinitions::all() as $oFeed) {
            $aBuffers = $this->oComposer->composeStory('101', 'Headline', null, [['type' => 'paragraph', 'text' => 'Body.']], $this->oNow, $oFeed->sMastheadTitle, $oFeed->iHeadlineForeground, $oFeed->iHeadlineBackground);

            $this->assertStringContainsString($oFeed->sMastheadTitle, $this->_plain($this->_row($aBuffers[0], 0)), $oFeed->sKey . ' story masthead');
        }
    }

    public function testStoryPublishedDateShown(): void
    {
        $aBuffers = $this->oComposer->composeStory('101', 'Headline', '22 August 2026, 16:44 BST', [['type' => 'paragraph', 'text' => 'Body.']], $this->oNow, 'BBC NEWS', NewsPageComposer::WHITE, NewsPageComposer::RED);

        $this->assertStringContainsString('22 August 2026, 16:44 BST', $this->_plain($this->_row($aBuffers[0], 5)));
    }

    public function testStoryNoPublishedDateLeavesBlankRow(): void
    {
        $aBuffers = $this->oComposer->composeStory('101', 'Headline', null, [['type' => 'paragraph', 'text' => 'Body.']], $this->oNow, 'BBC NEWS', NewsPageComposer::WHITE, NewsPageComposer::RED);

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
        $aBuffers = $this->oComposer->composeStory('101', 'Headline', null, $aBlocks, $this->oNow, 'BBC NEWS', NewsPageComposer::WHITE, NewsPageComposer::RED);
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

        $aBuffers = $this->oComposer->composeStory('101', 'Headline', null, $aBlocks, $this->oNow, 'BBC NEWS', NewsPageComposer::WHITE, NewsPageComposer::RED);

        $this->assertGreaterThan(1, count($aBuffers));
        $sRow1Continuation = $this->_row($aBuffers[1], 1);
        $this->assertStringNotContainsString(chr(0x0D), $sRow1Continuation);
        $this->assertStringContainsString(chr(NewsPageComposer::YELLOW), $sRow1Continuation, 'repeated title on a continuation subpage is yellow');
        $this->assertStringContainsString('Headline', $this->_plain($sRow1Continuation));
        $this->assertStringContainsString('p 1/' . count($aBuffers), $this->_plain($this->_row($aBuffers[0], 24)));
    }

    public function testStorySingleSubpageHasNoFooterHint(): void
    {
        $aBuffers = $this->oComposer->composeStory('101', 'Headline', null, [['type' => 'paragraph', 'text' => 'Short.']], $this->oNow, 'BBC NEWS', NewsPageComposer::WHITE, NewsPageComposer::RED);

        $this->assertCount(1, $aBuffers);
        $this->assertStringNotContainsString('p 1/1', $this->_plain($this->_row($aBuffers[0], 24)));
    }

    public function testInlineStrongAndEmMarkersBecomeColourChanges(): void
    {
        $aBlocks = [['type' => 'paragraph', 'text' => "A \x01bold\x02 and \x03italic\x04 word."]];
        $aBuffers = $this->oComposer->composeStory('101', 'Headline', null, $aBlocks, $this->oNow, 'BBC NEWS', NewsPageComposer::WHITE, NewsPageComposer::RED);

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
        $aBuffers = $this->oComposer->composeStory('101', 'Headline', null, [['type' => 'paragraph', 'text' => $sText]], $this->oNow, 'BBC NEWS', NewsPageComposer::WHITE, NewsPageComposer::RED);

        $sPlain = $this->_plain($this->_row($aBuffers[0], 6));
        $this->assertSame('It\'s the "best" result - so far...', trim($sPlain));
    }

    public function testPoundSignIsMappedToG0CodePoint(): void
    {
        $sText = "Costs \xC2\xA315bn in total.";
        $aBuffers = $this->oComposer->composeStory('101', 'Headline', null, [['type' => 'paragraph', 'text' => $sText]], $this->oNow, 'BBC NEWS', NewsPageComposer::WHITE, NewsPageComposer::RED);

        $sRow = $this->_row($aBuffers[0], 6);
        // £ is transliterated to the raw byte 0x23 - the G0 code point a
        // real teletext decoder renders as "£" - not left as multi-byte UTF-8.
        $this->assertStringContainsString(chr(0x23) . '15bn', $sRow);
    }

    public function testAccentedLatinCharactersAreTransliteratedToAscii(): void
    {
        $sText = "A caf\xC3\xA9 in Bj\xC3\xB6rk's na\xC3\xAFve home town.";
        $aBuffers = $this->oComposer->composeStory('101', 'Headline', null, [['type' => 'paragraph', 'text' => $sText]], $this->oNow, 'BBC NEWS', NewsPageComposer::WHITE, NewsPageComposer::RED);

        $sPlain = trim($this->_plain($this->_row($aBuffers[0], 6)));
        $this->assertMatchesRegularExpression('/^[\x20-\x7E]*$/', $sPlain);
        $this->assertStringContainsString('cafe', $sPlain);
    }

    public function testLongParagraphWrapsAcrossMultipleRows(): void
    {
        $sLongText = implode(' ', array_fill(0, 30, 'word'));
        $aBuffers = $this->oComposer->composeStory('101', 'Headline', null, [['type' => 'paragraph', 'text' => $sLongText]], $this->oNow, 'BBC NEWS', NewsPageComposer::WHITE, NewsPageComposer::RED);

        $sRow6 = $this->_plain($this->_row($aBuffers[0], 6));
        $sRow7 = $this->_plain($this->_row($aBuffers[0], 7));
        $this->assertNotSame('', trim($sRow6));
        $this->assertNotSame('', trim($sRow7));
        $this->assertLessThanOrEqual(40, strlen($this->_row($aBuffers[0], 6)));
    }
}
