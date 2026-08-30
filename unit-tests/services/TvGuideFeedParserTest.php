<?php

/*
 * @group unit-tests
 *
 * Unit tests for HomeLan\FileStore\Services\Provider\Teletext\TvGuideFeedParser.
 *
 * Pure logic, no network access — every test feeds a literal JSON fixture
 * string (matching TVHeadend's documented `/api/epg/events/grid` response
 * shape) straight into parse()/groupByChannel().
 */

use PHPUnit\Framework\TestCase;
use HomeLan\FileStore\Services\Provider\Teletext\TvGuideFeedParser;
use HomeLan\FileStore\Services\Provider\Teletext\TvGuideChannel;

include_once(__DIR__ . '/../../src/include/system.inc.php');

class TvGuideFeedParserTest extends TestCase
{
    protected TvGuideFeedParser $oParser;

    protected function setUp(): void
    {
        $this->oParser = new TvGuideFeedParser();
    }

    /**
     * @param array<int, array<string, mixed>> $aEntries
     */
    protected function _grid(array $aEntries): string
    {
        return json_encode(['entries' => $aEntries, 'totalCount' => count($aEntries)]);
    }

    /**
     * @return array<string, mixed>
     */
    protected function _entry(int|string $mChannelNumber, int $iStart, int $iStop, string $sTitle): array
    {
        return [
            'channelNumber' => $mChannelNumber,
            'start'         => $iStart,
            'stop'          => $iStop,
            'title'         => $sTitle,
        ];
    }

    // -------------------------------------------------------------------------
    // parse()
    // -------------------------------------------------------------------------

    public function testEmptyJsonReturnsEmptyArray(): void
    {
        $this->assertSame([], $this->oParser->parse(''));
    }

    public function testMalformedJsonReturnsEmptyArray(): void
    {
        $this->assertSame([], $this->oParser->parse('{not json'));
    }

    public function testMissingEntriesKeyReturnsEmptyArray(): void
    {
        $this->assertSame([], $this->oParser->parse(json_encode(['totalCount' => 0])));
    }

    public function testParsesBasicFields(): void
    {
        $sJson = $this->_grid([$this->_entry(1, 1_750_000_000, 1_750_003_600, 'The News')]);

        $aEvents = $this->oParser->parse($sJson);

        $this->assertCount(1, $aEvents);
        $this->assertSame(1, $aEvents[0]['lcn']);
        $this->assertSame(1_750_000_000, $aEvents[0]['start']);
        $this->assertSame(1_750_003_600, $aEvents[0]['stop']);
        $this->assertSame('The News', $aEvents[0]['title']);
    }

    public function testDecimalChannelNumberIsFlooredToTheLcn(): void
    {
        $sJson = $this->_grid([$this->_entry('3.0', 1_750_000_000, 1_750_003_600, 'ITV Show')]);

        $aEvents = $this->oParser->parse($sJson);

        $this->assertSame(3, $aEvents[0]['lcn']);
    }

    public function testEntryMissingATitleIsSkipped(): void
    {
        $aEntry = $this->_entry(1, 1_750_000_000, 1_750_003_600, '');
        $sJson = $this->_grid([$aEntry]);

        $this->assertSame([], $this->oParser->parse($sJson));
    }

    public function testEntryWithNonNumericChannelNumberIsSkipped(): void
    {
        $aEntry = $this->_entry('n/a', 1_750_000_000, 1_750_003_600, 'Something');
        $sJson = $this->_grid([$aEntry]);

        $this->assertSame([], $this->oParser->parse($sJson));
    }

    public function testMultipleEntriesAreAllParsed(): void
    {
        $sJson = $this->_grid([
            $this->_entry(1, 1_750_000_000, 1_750_003_600, 'First'),
            $this->_entry(2, 1_750_003_600, 1_750_007_200, 'Second'),
        ]);

        $aEvents = $this->oParser->parse($sJson);

        $this->assertCount(2, $aEvents);
        $this->assertSame('First', $aEvents[0]['title']);
        $this->assertSame('Second', $aEvents[1]['title']);
    }

    // -------------------------------------------------------------------------
    // groupByChannel()
    // -------------------------------------------------------------------------

    /** @return array<string, TvGuideChannel> */
    protected function _channels(): array
    {
        return [
            'bbc-one' => new TvGuideChannel(sKey: 'bbc-one', sLabel: 'BBC One', iLcn: 1, aMosaicWords: ['BBC', 'ONE']),
            'itv1'    => new TvGuideChannel(sKey: 'itv1', sLabel: 'ITV1', iLcn: 3, aMosaicWords: ['ITV']),
        ];
    }

    public function testEventsAreBucketedByMatchingLcn(): void
    {
        $oToday = new \DateTimeImmutable('2026-06-15 00:00:00', new \DateTimeZone('UTC'));
        $iTodayStart = $oToday->getTimestamp();
        $aEvents = [
            ['lcn' => 1, 'start' => $iTodayStart + 3600, 'stop' => $iTodayStart + 7200, 'title' => 'BBC One Show'],
            ['lcn' => 3, 'start' => $iTodayStart + 3600, 'stop' => $iTodayStart + 7200, 'title' => 'ITV Show'],
            ['lcn' => 99, 'start' => $iTodayStart + 3600, 'stop' => $iTodayStart + 7200, 'title' => 'Unlisted channel'],
        ];

        $aGrouped = $this->oParser->groupByChannel($aEvents, $this->_channels(), $oToday);

        $this->assertCount(1, $aGrouped['bbc-one']['today']);
        $this->assertSame('BBC One Show', $aGrouped['bbc-one']['today'][0]['title']);
        $this->assertCount(1, $aGrouped['itv1']['today']);
        $this->assertSame('ITV Show', $aGrouped['itv1']['today'][0]['title']);
    }

    public function testEveryChannelIsPresentEvenWithNoMatchingEvents(): void
    {
        $oToday = new \DateTimeImmutable('2026-06-15 00:00:00', new \DateTimeZone('UTC'));

        $aGrouped = $this->oParser->groupByChannel([], $this->_channels(), $oToday);

        $this->assertSame(['today' => [], 'tomorrow' => []], $aGrouped['bbc-one']);
        $this->assertSame(['today' => [], 'tomorrow' => []], $aGrouped['itv1']);
    }

    public function testEventsAreSplitIntoTodayAndTomorrowByLocalMidnight(): void
    {
        $oToday = new \DateTimeImmutable('2026-06-15 12:00:00', new \DateTimeZone('UTC'));
        $iTodayStart = $oToday->setTime(0, 0, 0)->getTimestamp();
        $aEvents = [
            ['lcn' => 1, 'start' => $iTodayStart + 3600, 'stop' => $iTodayStart + 7200, 'title' => 'Today morning'],
            ['lcn' => 1, 'start' => $iTodayStart + 86400 + 3600, 'stop' => $iTodayStart + 86400 + 7200, 'title' => 'Tomorrow morning'],
        ];

        $aGrouped = $this->oParser->groupByChannel($aEvents, $this->_channels(), $oToday);

        $this->assertCount(1, $aGrouped['bbc-one']['today']);
        $this->assertSame('Today morning', $aGrouped['bbc-one']['today'][0]['title']);
        $this->assertCount(1, $aGrouped['bbc-one']['tomorrow']);
        $this->assertSame('Tomorrow morning', $aGrouped['bbc-one']['tomorrow'][0]['title']);
    }

    public function testEventsOutsideThe48HourWindowAreDropped(): void
    {
        $oToday = new \DateTimeImmutable('2026-06-15 00:00:00', new \DateTimeZone('UTC'));
        $iTodayStart = $oToday->getTimestamp();
        $aEvents = [
            ['lcn' => 1, 'start' => $iTodayStart - 3600, 'stop' => $iTodayStart, 'title' => 'Yesterday'],
            ['lcn' => 1, 'start' => $iTodayStart + 172800, 'stop' => $iTodayStart + 176400, 'title' => 'Day after tomorrow'],
        ];

        $aGrouped = $this->oParser->groupByChannel($aEvents, $this->_channels(), $oToday);

        $this->assertSame([], $aGrouped['bbc-one']['today']);
        $this->assertSame([], $aGrouped['bbc-one']['tomorrow']);
    }

    public function testBucketedEventsAreSortedByStartTime(): void
    {
        $oToday = new \DateTimeImmutable('2026-06-15 00:00:00', new \DateTimeZone('UTC'));
        $iTodayStart = $oToday->getTimestamp();
        $aEvents = [
            ['lcn' => 1, 'start' => $iTodayStart + 7200, 'stop' => $iTodayStart + 10800, 'title' => 'Later'],
            ['lcn' => 1, 'start' => $iTodayStart + 3600, 'stop' => $iTodayStart + 7200, 'title' => 'Earlier'],
        ];

        $aGrouped = $this->oParser->groupByChannel($aEvents, $this->_channels(), $oToday);

        $this->assertSame('Earlier', $aGrouped['bbc-one']['today'][0]['title']);
        $this->assertSame('Later', $aGrouped['bbc-one']['today'][1]['title']);
    }
}
