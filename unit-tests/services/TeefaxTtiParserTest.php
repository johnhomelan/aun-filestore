<?php

/*
 * @group unit-tests
 *
 * Unit tests for HomeLan\FileStore\Services\Provider\Teletext\TeefaxTtiParser.
 *
 * Pure logic, no filesystem/network access — every test feeds a literal
 * .tti fixture string straight into parse().
 */

use PHPUnit\Framework\TestCase;
use HomeLan\FileStore\Services\Provider\Teletext\TeefaxTtiParser;
use HomeLan\FileStore\Services\Provider\Teletext\Storage;

include_once(__DIR__ . '/../../src/include/system.inc.php');

class TeefaxTtiParserTest extends TestCase
{
    protected TeefaxTtiParser $oParser;

    protected function setUp(): void
    {
        $this->oParser = new TeefaxTtiParser();
    }

    protected function _tti(array $aLines): string
    {
        return implode("\r\n", $aLines) . "\r\n";
    }

    // -------------------------------------------------------------------------
    // Basic shape
    // -------------------------------------------------------------------------

    public function testEmptyContentReturnsEmptyArray(): void
    {
        $this->assertSame([], $this->oParser->parse(''));
    }

    public function testContentWithNoPnLineReturnsEmptyArray(): void
    {
        $sContent = $this->_tti(['OL,0,HELLO']);
        $this->assertSame([], $this->oParser->parse($sContent));
    }

    public function testSinglePageBasicParsing(): void
    {
        $sContent = $this->_tti(['PN,10001', 'OL,0,HEADER', 'OL,1,BODY ROW']);
        $aPages = $this->oParser->parse($sContent);

        $this->assertCount(1, $aPages);
        $this->assertSame(1, $aPages[0]['magazine']);
        $this->assertSame('100', $aPages[0]['page']);
        $this->assertSame(1, $aPages[0]['subpage']);
    }

    public function testBufferIsAlwaysThePageSizeConstant(): void
    {
        $sContent = $this->_tti(['PN,10001', 'OL,0,HELLO']);
        $aPages = $this->oParser->parse($sContent);
        $this->assertSame(Storage::PAGE_SIZE, strlen($aPages[0]['buffer']));
    }

    public function testMissingRowsAreSpaceFilled(): void
    {
        $sContent = $this->_tti(['PN,10001', 'OL,0,HEADER']);
        $sBuffer = $this->oParser->parse($sContent)[0]['buffer'];

        // Row 1 (offset 40-79) was never supplied — should be 40 spaces.
        $this->assertSame(str_repeat(' ', 40), substr($sBuffer, 40, 40));
    }

    public function testRowContentAppearsAtCorrectOffset(): void
    {
        $sContent = $this->_tti(['PN,10001', 'OL,3,' . str_repeat('X', 40)]);
        $sBuffer = $this->oParser->parse($sContent)[0]['buffer'];

        $this->assertSame(str_repeat('X', 40), substr($sBuffer, 3 * 40, 40));
        $this->assertSame(str_repeat(' ', 40), substr($sBuffer, 0, 40));
    }

    public function testRowLongerThan40CharsIsTruncated(): void
    {
        $sContent = $this->_tti(['PN,10001', 'OL,0,' . str_repeat('Y', 60)]);
        $sBuffer = $this->oParser->parse($sContent)[0]['buffer'];
        $this->assertSame(str_repeat('Y', 40), substr($sBuffer, 0, 40));
    }

    public function testRowShorterThan40CharsIsSpacePadded(): void
    {
        $sContent = $this->_tti(['PN,10001', 'OL,0,HI']);
        $sBuffer = $this->oParser->parse($sContent)[0]['buffer'];
        $this->assertSame('HI' . str_repeat(' ', 38), substr($sBuffer, 0, 40));
    }

    public function testRowNumbersAboveTwentyFourAreIgnored(): void
    {
        $sContent = $this->_tti(['PN,10001', 'OL,26,' . str_repeat('Z', 40)]);
        $aPages = $this->oParser->parse($sContent);
        // No content byte anywhere should be 'Z' — row 26 never gets stored.
        $this->assertStringNotContainsString('Z', $aPages[0]['buffer']);
    }

    // -------------------------------------------------------------------------
    // Control code decoding
    // -------------------------------------------------------------------------

    public function testControlCodeLiteralPassthrough(): void
    {
        $sRow = chr(0x01) . str_repeat(' ', 39);
        $sContent = $this->_tti(['PN,10001', 'OL,0,' . $sRow]);
        $sBuffer = $this->oParser->parse($sContent)[0]['buffer'];
        $this->assertSame(0x01, ord($sBuffer[0]));
    }

    public function testControlCodePlus0x80Translation(): void
    {
        $sRow = chr(0x81) . str_repeat(' ', 39);
        $sContent = $this->_tti(['PN,10001', 'OL,0,' . $sRow]);
        $sBuffer = $this->oParser->parse($sContent)[0]['buffer'];
        $this->assertSame(0x01, ord($sBuffer[0]));
    }

    public function testControlCodeEscapeTranslation(): void
    {
        // ESC ($1B) followed by the control code with bit 6 set: 0x01|0x40 = 0x41 ('A').
        $sRow = chr(0x1B) . chr(0x41) . str_repeat(' ', 38);
        $sContent = $this->_tti(['PN,10001', 'OL,0,' . $sRow]);
        $sBuffer = $this->oParser->parse($sContent)[0]['buffer'];
        $this->assertSame(0x01, ord($sBuffer[0]));
    }

    public function testEscapeSequenceConsumesTwoInputBytesForOneOutputByte(): void
    {
        // 39 'A's plus one ESC-pair (2 raw bytes) = 41 raw bytes in the file,
        // but must still decode to exactly 40 output bytes, with the escape
        // pair being the very last output column.
        $sRow = str_repeat('A', 39) . chr(0x1B) . chr(0x41);
        $sContent = $this->_tti(['PN,10001', 'OL,0,' . $sRow]);
        $sBuffer = $this->oParser->parse($sContent)[0]['buffer'];
        $sRowOut = substr($sBuffer, 0, 40);

        $this->assertSame(40, strlen($sRowOut));
        $this->assertSame(str_repeat('A', 39), substr($sRowOut, 0, 39));
        $this->assertSame(0x01, ord($sRowOut[39]));
    }

    // -------------------------------------------------------------------------
    // Page/magazine/subpage numbering
    // -------------------------------------------------------------------------

    public function testHexPageDigitsAreParsedAndUppercased(): void
    {
        $sContent = $this->_tti(['PN,1b001', 'OL,0,HELLO']);
        $aPages = $this->oParser->parse($sContent);
        $this->assertSame('1B0', $aPages[0]['page']);
    }

    public function testSubpageZeroIsNormalisedToOne(): void
    {
        $sContent = $this->_tti(['PN,10000', 'OL,0,HELLO']);
        $aPages = $this->oParser->parse($sContent);
        $this->assertSame(1, $aPages[0]['subpage']);
    }

    public function testExplicitSubpageIsPreserved(): void
    {
        $sContent = $this->_tti(['PN,10003', 'OL,0,HELLO']);
        $aPages = $this->oParser->parse($sContent);
        $this->assertSame(3, $aPages[0]['subpage']);
    }

    public function testMagazineEightIsAccepted(): void
    {
        $sContent = $this->_tti(['PN,81001', 'OL,0,HELLO']);
        $aPages = $this->oParser->parse($sContent);
        $this->assertSame(8, $aPages[0]['magazine']);
        $this->assertSame('810', $aPages[0]['page']);
    }

    // -------------------------------------------------------------------------
    // Carousels (multiple PN blocks in one file)
    // -------------------------------------------------------------------------

    public function testMultiplePnLinesProduceMultipleResults(): void
    {
        $sContent = $this->_tti([
            'PN,10001', 'OL,0,FIRST SUBPAGE',
            'PN,10002', 'OL,0,SECOND SUBPAGE',
        ]);
        $aPages = $this->oParser->parse($sContent);

        $this->assertCount(2, $aPages);
        $this->assertSame(1, $aPages[0]['subpage']);
        $this->assertSame(2, $aPages[1]['subpage']);
    }

    public function testCarouselResultsAreInFileOrder(): void
    {
        $sContent = $this->_tti([
            'PN,10003', 'OL,0,C',
            'PN,10001', 'OL,0,A',
        ]);
        $aPages = $this->oParser->parse($sContent);
        $this->assertSame(3, $aPages[0]['subpage']);
        $this->assertSame(1, $aPages[1]['subpage']);
    }

    public function testRowsDoNotLeakBetweenSubpagesInACarousel(): void
    {
        $sContent = $this->_tti([
            'PN,10001', 'OL,0,' . str_repeat('A', 40),
            'PN,10002', 'OL,1,' . str_repeat('B', 40),
        ]);
        $aPages = $this->oParser->parse($sContent);

        // Second page's row 0 was never set by its own PN block.
        $this->assertSame(str_repeat(' ', 40), substr($aPages[1]['buffer'], 0, 40));
        $this->assertSame(str_repeat('B', 40), substr($aPages[1]['buffer'], 40, 40));
    }

    // -------------------------------------------------------------------------
    // Robustness
    // -------------------------------------------------------------------------

    public function testIgnoredTagsDoNotBreakParsing(): void
    {
        $sContent = $this->_tti([
            'DE,Some description',
            'PN,10001',
            'CT,8,T',
            'PS,8010',
            'SC,0001',
            'OL,0,CONTENT',
            'FL,100,101,102,103,104,105',
            'RE,0',
            'PF,0,0',
        ]);
        $aPages = $this->oParser->parse($sContent);
        $this->assertCount(1, $aPages);
        $this->assertSame('CONTENT' . str_repeat(' ', 33), substr($aPages[0]['buffer'], 0, 40));
    }

    public function testMalformedPnLineIsSkippedUntilTheNextValidOne(): void
    {
        $sContent = $this->_tti([
            'PN,XXXXX',
            'OL,0,SHOULD NOT APPEAR',
            'PN,10001',
            'OL,0,SHOULD APPEAR',
        ]);
        $aPages = $this->oParser->parse($sContent);
        $this->assertCount(1, $aPages);
        $this->assertStringContainsString('SHOULD APPEAR', $aPages[0]['buffer']);
    }

    public function testLineWithNoCommaIsIgnored(): void
    {
        $sContent = $this->_tti(['PN,10001', 'NOTATAG', 'OL,0,HELLO']);
        $aPages = $this->oParser->parse($sContent);
        $this->assertCount(1, $aPages);
        $this->assertStringContainsString('HELLO', $aPages[0]['buffer']);
    }

    public function testLfOnlyLineEndingsAreHandled(): void
    {
        $sContent = "PN,10001\nOL,0,HELLO\n";
        $aPages = $this->oParser->parse($sContent);
        $this->assertCount(1, $aPages);
        $this->assertStringContainsString('HELLO', $aPages[0]['buffer']);
    }

    public function testOlLinesBeforeAnyPnAreIgnored(): void
    {
        $sContent = $this->_tti(['OL,0,ORPHAN', 'PN,10001', 'OL,1,REAL']);
        $aPages = $this->oParser->parse($sContent);
        $this->assertCount(1, $aPages);
        $this->assertStringNotContainsString('ORPHAN', $aPages[0]['buffer']);
        $this->assertStringContainsString('REAL', $aPages[0]['buffer']);
    }
}
