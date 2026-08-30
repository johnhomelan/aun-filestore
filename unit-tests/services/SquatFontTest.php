<?php

/*
 * @group unit-tests
 *
 * Unit tests for HomeLan\FileStore\Services\Provider\Teletext\SquatFont -
 * the "squat" mosaic font registered in MosaicFontRegistry (see
 * MosaicFontRegistryTest for the registry lookup itself). Covers the full
 * A-Z alphabet.
 *
 * Pure logic, no network/filesystem access.
 */

use PHPUnit\Framework\TestCase;
use HomeLan\FileStore\Services\Provider\Teletext\SquatFont;

include_once(__DIR__ . '/../../src/include/system.inc.php');

class SquatFontTest extends TestCase
{
    protected SquatFont $oFont;

    protected function setUp(): void
    {
        $this->oFont = new SquatFont();
    }

    public function testRenderWordReturnsTwoRowsForOneRowOfGlyphs(): void
    {
        // Every current glyph is 6 sub-pixel rows tall (2 teletext rows of
        // 3 sub-pixel rows each), regardless of word length.
        $aRows = $this->oFont->renderWord('HOWL', 7);

        $this->assertCount(2, $aRows);
    }

    public function testEveryRowStartsWithTheGraphicsColourByte(): void
    {
        $aRows = $this->oFont->renderWord('ROSE', 7);

        foreach ($aRows as $iRow => $sRow) {
            $this->assertSame(chr(0x10 + 7), $sRow[0], "row $iRow should start with the graphics-colour byte");
        }
    }

    public function testAllRenderedBytesAreValidG1MosaicCodesOrTheColourByte(): void
    {
        $aRows = $this->oFont->renderWord('SHOWREEL', 7);

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

        $this->oFont->renderWord('SHOWREEL1', 7);
    }

    public function testDifferentColoursProduceDifferentColourBytes(): void
    {
        $aWhite = $this->oFont->renderWord('ROSE', 7);
        $aRed = $this->oFont->renderWord('ROSE', 1);

        $this->assertSame(chr(0x10 + 7), $aWhite[0][0]);
        $this->assertSame(chr(0x10 + 1), $aRed[0][0]);
    }

    public function testLongerWordsProduceWiderRows(): void
    {
        $aShort = $this->oFont->renderWord('HE', 7);
        $aLong = $this->oFont->renderWord('SHOWREEL', 7);

        $this->assertGreaterThan(strlen($aShort[0]), strlen($aLong[0]));
    }
}
