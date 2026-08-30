<?php

/*
 * @group unit-tests
 *
 * Unit tests for HomeLan\FileStore\Services\Provider\Teletext\WeatherFeedParser.
 *
 * Pure logic, no network access — every test feeds a literal RSS fixture
 * string (matching the real BBC Weather 3-day forecast feed shape,
 * confirmed live at
 * https://weather-broker-cdn.api.bbci.co.uk/en/forecast/rss/3day/{id}
 * while building this feature) straight into parse().
 */

use PHPUnit\Framework\TestCase;
use HomeLan\FileStore\Services\Provider\Teletext\WeatherFeedParser;

include_once(__DIR__ . '/../../src/include/system.inc.php');

class WeatherFeedParserTest extends TestCase
{
    protected WeatherFeedParser $oParser;

    protected function setUp(): void
    {
        $this->oParser = new WeatherFeedParser();
    }

    protected function _item(string $sTitle, string $sDescription): string
    {
        return '<item>'
            . '<title>' . $sTitle . '</title>'
            . '<description>' . $sDescription . '</description>'
            . '</item>';
    }

    protected function _feed(array $aItemXmlBlocks): string
    {
        return '<?xml version="1.0" encoding="UTF-8"?><rss version="2.0"><channel><title>BBC Weather - Forecast for London, GB</title>'
            . implode('', $aItemXmlBlocks)
            . '</channel></rss>';
    }

    public function testEmptyFeedReturnsEmptyArray(): void
    {
        $this->assertSame([], $this->oParser->parse(''));
    }

    public function testMalformedXmlReturnsEmptyArray(): void
    {
        $this->assertSame([], $this->oParser->parse('<not-xml'));
    }

    public function testTonightItemWithNoMaximumTemperature(): void
    {
        $sXml = $this->_feed([
            $this->_item(
                'Tonight: Clear Sky, Minimum Temperature: 14&#176;C (57&#176;F)',
                'Minimum Temperature: 14&#176;C (57&#176;F), Wind Direction: north-easterly, Wind Speed: 6mph, Visibility: Very Good, Pressure: 1023mb, Humidity: 77%, UV Risk: 0, Pollution: Moderate, Sunset: 20:05 BST'
            ),
        ]);

        $aDays = $this->oParser->parse($sXml);

        $this->assertCount(1, $aDays);
        $this->assertSame('Tonight', $aDays[0]['day']);
        $this->assertSame('Clear Sky', $aDays[0]['condition']);
        $this->assertSame('14', $aDays[0]['minC']);
        $this->assertNull($aDays[0]['maxC']);
        $this->assertSame('north-easterly', $aDays[0]['windDir']);
        $this->assertSame('6mph', $aDays[0]['windSpeed']);
        $this->assertSame('77%', $aDays[0]['humidity']);
    }

    public function testFullDayItemWithMinimumAndMaximumTemperature(): void
    {
        $sXml = $this->_feed([
            $this->_item(
                'Monday: Sunny Intervals, Minimum Temperature: 15&#176;C (59&#176;F) Maximum Temperature: 22&#176;C (72&#176;F)',
                'Maximum Temperature: 22&#176;C (72&#176;F), Minimum Temperature: 15&#176;C (59&#176;F), Wind Direction: easterly, Wind Speed: 13mph, Visibility: Very Good, Pressure: 1020mb, Humidity: 58%, UV Risk: 6, Pollution: Moderate, Sunrise: 06:01 BST, Sunset: 20:03 BST'
            ),
        ]);

        $aDays = $this->oParser->parse($sXml);

        $this->assertCount(1, $aDays);
        $this->assertSame('Monday', $aDays[0]['day']);
        $this->assertSame('Sunny Intervals', $aDays[0]['condition']);
        $this->assertSame('15', $aDays[0]['minC']);
        $this->assertSame('22', $aDays[0]['maxC']);
        $this->assertSame('easterly', $aDays[0]['windDir']);
        $this->assertSame('13mph', $aDays[0]['windSpeed']);
        $this->assertSame('58%', $aDays[0]['humidity']);
    }

    public function testMultipleItemsReturnedInFeedOrder(): void
    {
        $sXml = $this->_feed([
            $this->_item(
                'Tonight: Clear Sky, Minimum Temperature: 14&#176;C (57&#176;F)',
                'Minimum Temperature: 14&#176;C (57&#176;F)'
            ),
            $this->_item(
                'Tuesday: Light Cloud, Minimum Temperature: 16&#176;C (61&#176;F) Maximum Temperature: 23&#176;C (73&#176;F)',
                'Maximum Temperature: 23&#176;C (73&#176;F), Minimum Temperature: 16&#176;C (61&#176;F)'
            ),
        ]);

        $aDays = $this->oParser->parse($sXml);

        $this->assertCount(2, $aDays);
        $this->assertSame('Tonight', $aDays[0]['day']);
        $this->assertSame('Tuesday', $aDays[1]['day']);
    }

    public function testItemWithNoTemperatureInTitleIsSkipped(): void
    {
        $sXml = $this->_feed([
            $this->_item('Not a forecast title', 'Some description'),
        ]);

        $this->assertSame([], $this->oParser->parse($sXml));
    }

    public function testMissingDescriptionFieldsAreNull(): void
    {
        $sXml = $this->_feed([
            $this->_item(
                'Tonight: Clear Sky, Minimum Temperature: 14&#176;C (57&#176;F)',
                'Minimum Temperature: 14&#176;C (57&#176;F)'
            ),
        ]);

        $aDays = $this->oParser->parse($sXml);

        $this->assertNull($aDays[0]['windDir']);
        $this->assertNull($aDays[0]['windSpeed']);
        $this->assertNull($aDays[0]['humidity']);
    }
}
