<?php

/*
 * @group unit-tests
 *
 * Unit tests for HomeLan\FileStore\Services\Provider\Teletext\BlocksFont -
 * the "blocks" mosaic font registered in MosaicFontRegistry (see
 * MosaicFontRegistryTest for the registry lookup itself).
 *
 * Pure logic, no network/filesystem access.
 */

use PHPUnit\Framework\TestCase;
use HomeLan\FileStore\Services\Provider\Teletext\BlocksFont;

include_once(__DIR__ . '/../../src/include/system.inc.php');

class BlocksFontTest extends TestCase
{
    protected BlocksFont $oFont;

    protected function setUp(): void
    {
        $this->oFont = new BlocksFont();
    }

    public function testRenderWordReturnsThreeRowsForOneRowOfGlyphs(): void
    {
        // Every current glyph is 9 sub-pixel rows tall (3 teletext rows of 3
        // sub-pixel rows each), regardless of word length.
        $aRows = $this->oFont->renderWord('HAT', 6);

        $this->assertCount(3, $aRows);
    }

    public function testEveryRowStartsWithTheGraphicsColourByte(): void
    {
        $aRows = $this->oFont->renderWord('WET', 6);

        foreach ($aRows as $iRow => $sRow) {
            $this->assertSame(chr(0x10 + 6), $sRow[0], "row $iRow should start with the graphics-colour byte");
        }
    }

    public function testAllRenderedBytesAreValidG1MosaicCodesOrTheColourByte(): void
    {
        $aRows = $this->oFont->renderWord('WEATHER', 6);

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

        $this->oFont->renderWord('WEATHER1', 6);
    }

    public function testDifferentColoursProduceDifferentColourBytes(): void
    {
        $aCyan = $this->oFont->renderWord('HAT', 6);
        $aRed = $this->oFont->renderWord('HAT', 1);

        $this->assertSame(chr(0x10 + 6), $aCyan[0][0]);
        $this->assertSame(chr(0x10 + 1), $aRed[0][0]);
    }

    public function testLongerWordsProduceWiderRows(): void
    {
        $aShort = $this->oFont->renderWord('HA', 6);
        $aLong = $this->oFont->renderWord('WEATHER', 6);

        $this->assertGreaterThan(strlen($aShort[0]), strlen($aLong[0]));
    }
}
