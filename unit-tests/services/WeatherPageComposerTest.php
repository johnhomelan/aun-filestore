<?php

/*
 * @group unit-tests
 *
 * Unit tests for HomeLan\FileStore\Services\Provider\Teletext\WeatherPageComposer.
 *
 * Pure logic, no network/filesystem access — asserts exact buffer shape and
 * byte placement for known small inputs. Buffers are Storage-shaped: 25
 * rows of 40 bytes, padded to Storage::PAGE_SIZE.
 */

use PHPUnit\Framework\TestCase;
use HomeLan\FileStore\Services\Provider\Teletext\WeatherPageComposer;
use HomeLan\FileStore\Services\Provider\Teletext\Storage;

include_once(__DIR__ . '/../../src/include/system.inc.php');

class WeatherPageComposerTest extends TestCase
{
    protected WeatherPageComposer $oComposer;
    protected \DateTimeImmutable $oNow;

    protected function setUp(): void
    {
        $this->oComposer = new WeatherPageComposer();
        $this->oNow = new \DateTimeImmutable('2026-08-24 09:00:00');
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

    protected function _day(string $sDay, string $sCondition, ?string $minC = '10', ?string $maxC = '18', ?string $windDir = 'westerly', ?string $windSpeed = '10mph', ?string $humidity = '60%'): array
    {
        return [
            'day' => $sDay,
            'condition' => $sCondition,
            'minC' => $minC,
            'maxC' => $maxC,
            'windDir' => $windDir,
            'windSpeed' => $windSpeed,
            'humidity' => $humidity,
        ];
    }

    // -------------------------------------------------------------------------
    // Shared shape
    // -------------------------------------------------------------------------

    public function testIndexBufferIsExactlyPageSize(): void
    {
        $aBuffers = $this->oComposer->composeIndex('100', [['page' => '101', 'label' => 'London']], $this->oNow);

        $this->assertCount(1, $aBuffers);
        $this->assertSame(Storage::PAGE_SIZE, strlen($aBuffers[0]));
    }

    public function testLocationBufferIsExactlyPageSize(): void
    {
        $aBuffers = $this->oComposer->composeLocationPage('101', 'London', [$this->_day('Tonight', 'Clear Sky')], $this->oNow);

        $this->assertCount(1, $aBuffers);
        $this->assertSame(Storage::PAGE_SIZE, strlen($aBuffers[0]));
    }

    // -------------------------------------------------------------------------
    // Index
    // -------------------------------------------------------------------------

    public function testIndexMasthead(): void
    {
        $aBuffers = $this->oComposer->composeIndex('100', [], $this->oNow);

        $this->assertStringContainsString('P100', $this->_plain($this->_row($aBuffers[0], 0)));
        $this->assertStringContainsString('WEATHER', $this->_plain($this->_row($aBuffers[0], 0)));
    }

    public function testIndexTitleIsMosaicLettering(): void
    {
        $aBuffers = $this->oComposer->composeIndex('100', [], $this->oNow);

        // Rows 1-3 are the "WEATHER" mosaic title - cyan G1 graphics
        // characters (0x10-0x17 colour-with-graphics range), not plain text.
        for ($iRow = 1; $iRow <= 3; $iRow++) {
            $sRow = $this->_row($aBuffers[0], $iRow);
            $this->assertStringContainsString(chr(0x10 + WeatherPageComposer::CYAN), $sRow, "row $iRow sets cyan graphics colour");
        }
    }

    public function testIndexSunBandUsesBlueBackground(): void
    {
        $aBuffers = $this->oComposer->composeIndex('100', [], $this->oNow);

        // Rows 4-7 are the sun graphic's blue-background band.
        $sRow4 = $this->_row($aBuffers[0], 4);
        $this->assertStringContainsString(chr(0x1D), $sRow4, 'sun band sets a new background');
    }

    public function testIndexHasABlankLineAfterTheHeader(): void
    {
        $aLocations = [['page' => '101', 'label' => 'London']];
        $aBuffers = $this->oComposer->composeIndex('100', $aLocations, $this->oNow);

        // Row 8 is the blank spacer between the sun graphic (rows 4-7) and
        // the first location entry (row 9).
        $this->assertSame(str_repeat(' ', 40), $this->_row($aBuffers[0], 8));
        $this->assertStringContainsString('London', $this->_plain($this->_row($aBuffers[0], 9)));
    }

    public function testIndexListsLocationsWithPageNumberOnTheRight(): void
    {
        $aLocations = [
            ['page' => '101', 'label' => 'London'],
            ['page' => '102', 'label' => 'Glasgow'],
        ];
        $aBuffers = $this->oComposer->composeIndex('100', $aLocations, $this->oNow);

        $sRow9 = $this->_row($aBuffers[0], 9);
        $sRow10 = $this->_row($aBuffers[0], 10);
        $sRow11 = $this->_row($aBuffers[0], 11);

        $iLabelPos = strpos($this->_plain($sRow9), 'London');
        $iPagePos = strpos($this->_plain($sRow9), '101');
        $this->assertNotFalse($iLabelPos);
        $this->assertNotFalse($iPagePos);
        $this->assertGreaterThan($iLabelPos, $iPagePos, 'page number should sit to the right of the label');
        $this->assertSame(' ', substr($sRow9, -1), 'a blank space must follow the page number, not the hard right edge');

        $this->assertSame(str_repeat(' ', 40), $sRow10, 'blank line between locations');
        $this->assertStringContainsString('Glasgow', $this->_plain($sRow11));
        $this->assertStringContainsString('102', $sRow11);
    }

    public function testIndexPaginatesAcrossSubpagesWhenLocationsDontFit(): void
    {
        // 15 body rows are available per subpage (rows 9-23) and each
        // location takes 2, so only 7 fit per subpage - the real
        // WeatherLocations list (8 towns) already overflows a single one.
        $aLocations = [];
        for ($i = 1; $i <= 8; $i++) {
            $aLocations[] = ['page' => (string) (600 + $i), 'label' => 'Town' . $i];
        }

        $aBuffers = $this->oComposer->composeIndex('600', $aLocations, $this->oNow);

        $this->assertCount(2, $aBuffers);
        $this->assertStringContainsString('Town1', $this->_plain($aBuffers[0]));
        $this->assertStringNotContainsString('Town8', $this->_plain($aBuffers[0]));
        $this->assertStringContainsString('Town8', $this->_plain($aBuffers[1]));
        $this->assertStringContainsString('Subpage 1/2', $this->_plain($this->_row($aBuffers[0], 24)));
        $this->assertStringContainsString('Subpage 2/2', $this->_plain($this->_row($aBuffers[1], 24)));
    }

    // -------------------------------------------------------------------------
    // Location page
    // -------------------------------------------------------------------------

    public function testLocationMastheadShowsPageAndTitle(): void
    {
        $aBuffers = $this->oComposer->composeLocationPage('101', 'Edinburgh', [$this->_day('Tonight', 'Clear Sky')], $this->oNow);

        $this->assertStringContainsString('P101', $this->_plain($this->_row($aBuffers[0], 0)));
        $this->assertStringContainsString('WEATHER', $this->_plain($this->_row($aBuffers[0], 0)));
    }

    public function testLocationHeadingIsMosaicLettering(): void
    {
        $aBuffers = $this->oComposer->composeLocationPage('101', 'Edinburgh', [$this->_day('Tonight', 'Clear Sky')], $this->oNow);

        // Rows 1-2 are the location label rendered in the "chonk" font -
        // white G1 graphics characters (0x10-0x17 colour-with-graphics
        // range), not plain text.
        for ($iRow = 1; $iRow <= 2; $iRow++) {
            $sRow = $this->_row($aBuffers[0], $iRow);
            $this->assertStringContainsString(chr(0x10 + WeatherPageComposer::WHITE), $sRow, "row $iRow sets white graphics colour");
        }
    }

    public function testLocationPageShowsDayAndConditionInYellow(): void
    {
        $aBuffers = $this->oComposer->composeLocationPage('101', 'London', [$this->_day('Monday', 'Sunny Intervals')], $this->oNow);

        $sRow4 = $this->_row($aBuffers[0], 4);
        $this->assertStringContainsString('Monday', $this->_plain($sRow4));
        $this->assertStringContainsString(chr(0x03), $sRow4); // yellow condition
        $this->assertStringContainsString('Sunny Intervals', $this->_plain($sRow4));
    }

    public function testLocationPageShowsLowAndHighTemperatureAndWind(): void
    {
        $aDay = $this->_day('Monday', 'Sunny Intervals', minC: '15', maxC: '22', windDir: 'easterly', windSpeed: '13mph', humidity: '58%');
        $aBuffers = $this->oComposer->composeLocationPage('101', 'London', [$aDay], $this->oNow);

        // Humidity is deliberately not asserted here - Low/High/Wind alone
        // already fill the 39-character row budget, so it's the part that
        // gets truncated away, not a bug.
        $sPlain = $this->_plain($this->_row($aBuffers[0], 5));
        $this->assertStringContainsString('Low 15C', $sPlain);
        $this->assertStringContainsString('High 22C', $sPlain);
        $this->assertStringContainsString('Wind easterly 13mph', $sPlain);
    }

    public function testLocationPageShowsHumidityWhenRowHasRoom(): void
    {
        $aDay = $this->_day('Tonight', 'Clear Sky', minC: '14', maxC: null, windDir: null, windSpeed: null, humidity: '77%');
        $aBuffers = $this->oComposer->composeLocationPage('101', 'London', [$aDay], $this->oNow);

        $this->assertStringContainsString('Humidity 77%', $this->_plain($this->_row($aBuffers[0], 5)));
    }

    public function testLocationPageOmitsHighWhenMaxTemperatureIsNull(): void
    {
        $aDay = $this->_day('Tonight', 'Clear Sky', minC: '14', maxC: null);
        $aBuffers = $this->oComposer->composeLocationPage('101', 'London', [$aDay], $this->oNow);

        $sPlain = $this->_plain($this->_row($aBuffers[0], 5));
        $this->assertStringContainsString('Low 14C', $sPlain);
        $this->assertStringNotContainsString('High', $sPlain);
    }

    public function testLocationPageHasABlankLineBetweenDays(): void
    {
        $aDays = [$this->_day('Tonight', 'Clear Sky'), $this->_day('Monday', 'Sunny Intervals')];
        $aBuffers = $this->oComposer->composeLocationPage('101', 'London', $aDays, $this->oNow);

        $this->assertSame(str_repeat(' ', 40), $this->_row($aBuffers[0], 6));
        $this->assertStringContainsString('Monday', $this->_plain($this->_row($aBuffers[0], 7)));
    }
}
