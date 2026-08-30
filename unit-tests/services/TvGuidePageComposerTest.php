<?php

/*
 * @group unit-tests
 *
 * Unit tests for HomeLan\FileStore\Services\Provider\Teletext\TvGuidePageComposer.
 *
 * Pure logic, no network/filesystem access — asserts exact buffer shape and
 * byte placement for known small inputs. Buffers are Storage-shaped: 25
 * rows of 40 bytes, padded to Storage::PAGE_SIZE.
 */

use PHPUnit\Framework\TestCase;
use HomeLan\FileStore\Services\Provider\Teletext\TvGuidePageComposer;
use HomeLan\FileStore\Services\Provider\Teletext\Storage;

include_once(__DIR__ . '/../../src/include/system.inc.php');

class TvGuidePageComposerTest extends TestCase
{
    protected TvGuidePageComposer $oComposer;
    protected \DateTimeImmutable $oNow;

    protected function setUp(): void
    {
        $this->oComposer = new TvGuidePageComposer();
        $this->oNow = new \DateTimeImmutable('2026-08-26 09:00:00', new \DateTimeZone('UTC'));
    }

    protected function _row(string $sBuffer, int $iRow): string
    {
        return substr($sBuffer, $iRow * 40, 40);
    }

    /** Strips teletext control bytes (0x00-0x1f) so plain substring assertions can be made against text content. */
    protected function _plain(string $sRow): string
    {
        return preg_replace('/[\x00-\x1f]/', '', $sRow) ?? '';
    }

    protected function _event(string $sTime, string $sTitle): array
    {
        $iStart = \DateTimeImmutable::createFromFormat('Y-m-d H:i:s', '2026-08-26 ' . $sTime . ':00', new \DateTimeZone('UTC'))->getTimestamp();
        return ['lcn' => 1, 'start' => $iStart, 'stop' => $iStart + 1800, 'title' => $sTitle];
    }

    // -------------------------------------------------------------------------
    // Shared shape
    // -------------------------------------------------------------------------

    public function testIndexBufferIsExactlyPageSize(): void
    {
        $aBuffers = $this->oComposer->composeIndex('700', [['page' => '701', 'label' => 'BBC One']], $this->oNow);

        $this->assertCount(1, $aBuffers);
        $this->assertSame(Storage::PAGE_SIZE, strlen($aBuffers[0]));
    }

    public function testChannelPageBufferIsExactlyPageSize(): void
    {
        $aBuffers = $this->oComposer->composeChannelPage('701', 'BBC One', ['BBC', 'ONE'], [$this->_event('19:00', 'The News')], [], $this->oNow);

        $this->assertCount(1, $aBuffers);
        $this->assertSame(Storage::PAGE_SIZE, strlen($aBuffers[0]));
    }

    // -------------------------------------------------------------------------
    // Index
    // -------------------------------------------------------------------------

    public function testIndexMasthead(): void
    {
        $aBuffers = $this->oComposer->composeIndex('700', [], $this->oNow);

        $this->assertStringContainsString('P700', $this->_plain($this->_row($aBuffers[0], 0)));
        $this->assertStringContainsString('TV GUIDE', $this->_plain($this->_row($aBuffers[0], 0)));
    }

    public function testIndexTitleIsMosaicLettering(): void
    {
        $aBuffers = $this->oComposer->composeIndex('700', [], $this->oNow);

        // Rows 1-2 are the "TV GUIDE" chonk title - cyan G1 graphics
        // characters (0x10-0x17 colour-with-graphics range), not plain text.
        for ($iRow = 1; $iRow <= 2; $iRow++) {
            $sRow = $this->_row($aBuffers[0], $iRow);
            $this->assertStringContainsString(chr(0x10 + TvGuidePageComposer::CYAN), $sRow, "row $iRow sets cyan graphics colour");
        }
    }

    public function testIndexHasABlankLineAfterTheHeader(): void
    {
        $aBuffers = $this->oComposer->composeIndex('700', [['page' => '701', 'label' => 'BBC One']], $this->oNow);

        $this->assertSame(str_repeat(' ', 40), $this->_row($aBuffers[0], 3));
        $this->assertStringContainsString('BBC One', $this->_plain($this->_row($aBuffers[0], 4)));
    }

    public function testIndexListsChannelsWithPageNumberOnTheRight(): void
    {
        $aChannels = [
            ['page' => '701', 'label' => 'BBC One'],
            ['page' => '702', 'label' => 'BBC Two'],
        ];
        $aBuffers = $this->oComposer->composeIndex('700', $aChannels, $this->oNow);

        $sRow4 = $this->_row($aBuffers[0], 4);
        $sRow5 = $this->_row($aBuffers[0], 5);
        $sRow6 = $this->_row($aBuffers[0], 6);

        $iLabelPos = strpos($this->_plain($sRow4), 'BBC One');
        $iPagePos = strpos($this->_plain($sRow4), '701');
        $this->assertNotFalse($iLabelPos);
        $this->assertNotFalse($iPagePos);
        $this->assertGreaterThan($iLabelPos, $iPagePos, 'page number should sit to the right of the label');
        $this->assertSame(' ', substr($sRow4, -1), 'a blank space must follow the page number, not the hard right edge');

        $this->assertSame(str_repeat(' ', 40), $sRow5, 'blank line between channels');
        $this->assertStringContainsString('BBC Two', $this->_plain($sRow6));
        $this->assertStringContainsString('702', $sRow6);
    }

    public function testIndexPaginatesAcrossSubpagesWhenChannelsDontFit(): void
    {
        // 20 body rows are available per subpage (rows 4-23) and each
        // channel takes 2, so only 10 fit per subpage.
        $aChannels = [];
        for ($i = 1; $i <= 16; $i++) {
            $aChannels[] = ['page' => (string) (700 + $i), 'label' => 'Channel' . $i];
        }

        $aBuffers = $this->oComposer->composeIndex('700', $aChannels, $this->oNow);

        $this->assertCount(2, $aBuffers);
        $this->assertStringContainsString('Channel1', $this->_plain($aBuffers[0]));
        $this->assertStringNotContainsString('Channel16', $this->_plain($aBuffers[0]));
        $this->assertStringContainsString('Channel16', $this->_plain($aBuffers[1]));
        $this->assertStringContainsString('Subpage 1/2', $this->_plain($this->_row($aBuffers[0], 24)));
        $this->assertStringContainsString('Subpage 2/2', $this->_plain($this->_row($aBuffers[1], 24)));
    }

    // -------------------------------------------------------------------------
    // Channel page
    // -------------------------------------------------------------------------

    public function testChannelPageMastheadShowsPageAndRunningTitle(): void
    {
        $aBuffers = $this->oComposer->composeChannelPage('701', 'BBC One', ['BBC', 'ONE'], [], [], $this->oNow);

        $this->assertStringContainsString('P701', $this->_plain($this->_row($aBuffers[0], 0)));
        $this->assertStringContainsString('TV GUIDE', $this->_plain($this->_row($aBuffers[0], 0)));
    }

    public function testChannelPageHeadingIsMosaicLettering(): void
    {
        $aBuffers = $this->oComposer->composeChannelPage('701', 'BBC One', ['BBC', 'ONE'], [], [], $this->oNow);

        for ($iRow = 1; $iRow <= 2; $iRow++) {
            $sRow = $this->_row($aBuffers[0], $iRow);
            $this->assertStringContainsString(chr(0x10 + TvGuidePageComposer::CYAN), $sRow, "row $iRow sets cyan graphics colour");
        }
    }

    public function testChannelPageHasABlankLineAfterTheHeader(): void
    {
        $aBuffers = $this->oComposer->composeChannelPage('701', 'BBC One', ['BBC', 'ONE'], [$this->_event('19:00', 'The News')], [], $this->oNow);

        $this->assertSame(str_repeat(' ', 40), $this->_row($aBuffers[0], 3));
    }

    public function testChannelPageShowsTodayAndTomorrowHeadingsAndEvents(): void
    {
        $aToday = [$this->_event('19:00', 'The News')];
        $aTomorrow = [$this->_event('20:00', 'The Film')];
        $aBuffers = $this->oComposer->composeChannelPage('701', 'BBC One', ['BBC', 'ONE'], $aToday, $aTomorrow, $this->oNow);

        $sPlain = $this->_plain($aBuffers[0]);
        $this->assertStringContainsString('TODAY', $sPlain);
        $this->assertStringContainsString('19:00', $sPlain);
        $this->assertStringContainsString('The News', $sPlain);
        $this->assertStringContainsString('TOMORROW', $sPlain);
        $this->assertStringContainsString('20:00', $sPlain);
        $this->assertStringContainsString('The Film', $sPlain);
    }

    public function testChannelPageEventListingIsDenseWithNoBlankRowBetweenEntries(): void
    {
        $aToday = [$this->_event('19:00', 'First Show'), $this->_event('19:30', 'Second Show')];
        $aBuffers = $this->oComposer->composeChannelPage('701', 'BBC One', ['BBC', 'ONE'], $aToday, [], $this->oNow);

        // Row 4 is the TODAY heading, row 5 the first event, row 6 the
        // second event immediately below it - no blank spacer row between.
        $this->assertStringContainsString('TODAY', $this->_plain($this->_row($aBuffers[0], 4)));
        $this->assertStringContainsString('First Show', $this->_plain($this->_row($aBuffers[0], 5)));
        $this->assertStringContainsString('Second Show', $this->_plain($this->_row($aBuffers[0], 6)));
    }

    public function testChannelPagePaginatesWithHeadingGluedToItsFirstEntry(): void
    {
        // 20 body rows available (rows 4-23): TODAY heading + 19 events
        // exactly fills subpage 1, so TOMORROW's heading (with nothing
        // following it on subpage 1) must move to subpage 2 rather than
        // being left orphaned at the bottom of subpage 1.
        $aToday = [];
        for ($i = 1; $i <= 19; $i++) {
            $aToday[] = $this->_event(sprintf('%02d:00', $i % 24), 'Show ' . $i);
        }
        $aBuffers = $this->oComposer->composeChannelPage('701', 'BBC One', ['BBC', 'ONE'], $aToday, [], $this->oNow);

        $this->assertCount(2, $aBuffers);
        $this->assertStringNotContainsString('TOMORROW', $this->_plain($aBuffers[0]));
        $this->assertStringContainsString('TOMORROW', $this->_plain($aBuffers[1]));
    }
}
