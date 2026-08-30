<?php

/*
 * @group unit-tests
 *
 * Unit tests for HomeLan\FileStore\Services\Provider\Teletext\ChonkFont -
 * the "chonk" mosaic font registered in MosaicFontRegistry (see
 * MosaicFontRegistryTest for the registry lookup itself). Only
 * A/C/D/E/F/G/H/I/L/N/O/P/R/S/T/U/V/W/X/Y are defined so far, so test words
 * are limited to those.
 *
 * Pure logic, no network/filesystem access.
 */

use PHPUnit\Framework\TestCase;
use HomeLan\FileStore\Services\Provider\Teletext\ChonkFont;

include_once(__DIR__ . '/../../src/include/system.inc.php');

class ChonkFontTest extends TestCase
{
    protected ChonkFont $oFont;

    protected function setUp(): void
    {
        $this->oFont = new ChonkFont();
    }

    public function testRenderWordReturnsTwoRowsForOneRowOfGlyphs(): void
    {
        // Every current glyph is 6 sub-pixel rows tall (2 teletext rows of
        // 3 sub-pixel rows each), regardless of word length.
        $aRows = $this->oFont->renderWord('SPORT', 7);

        $this->assertCount(2, $aRows);
    }

    public function testEveryRowStartsWithTheGraphicsColourByte(): void
    {
        $aRows = $this->oFont->renderWord('WEATHER', 7);

        foreach ($aRows as $iRow => $sRow) {
            $this->assertSame(chr(0x10 + 7), $sRow[0], "row $iRow should start with the graphics-colour byte");
        }
    }

    public function testAllRenderedBytesAreValidG1MosaicCodesOrTheColourByte(): void
    {
        $aRows = $this->oFont->renderWord('ENGINEERING', 7);

        foreach ($aRows as $sRow) {
            for ($i = 1; $i < strlen($sRow); $i++) {
                $iCode = ord($sRow[$i]);
                $bValidMosaic = ($iCode >= 0x20 && $iCode <= 0x3F) || ($iCode >= 0x60 && $iCode <= 0x7F);
                $this->assertTrue($bValidMosaic, sprintf('byte 0x%02X at offset %d is not a valid G1 mosaic code', $iCode, $i));
            }
        }
    }

    public function testUnknownLetterThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->oFont->renderWord('SPORT1', 7);
    }

    public function testDifferentColoursProduceDifferentColourBytes(): void
    {
        $aWhite = $this->oFont->renderWord('REWIND', 7);
        $aRed = $this->oFont->renderWord('REWIND', 1);

        $this->assertSame(chr(0x10 + 7), $aWhite[0][0]);
        $this->assertSame(chr(0x10 + 1), $aRed[0][0]);
    }

    public function testLongerWordsProduceWiderRows(): void
    {
        $aShort = $this->oFont->renderWord('TV', 7);
        $aLong = $this->oFont->renderWord('ENGINEERING', 7);

        $this->assertGreaterThan(strlen($aShort[0]), strlen($aLong[0]));
    }

    public function testIndexRendersWithoutErrorIncludingTheHandDrawnX(): void
    {
        $aRows = $this->oFont->renderWord('INDEX', 7);

        $this->assertCount(2, $aRows);
        foreach ($aRows as $sRow) {
            $this->assertSame(chr(0x10 + 7), $sRow[0]);
        }
    }
}
