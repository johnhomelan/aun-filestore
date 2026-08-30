<?php

/*
 * @group unit-tests
 *
 * Unit tests for HomeLan\FileStore\Services\Provider\Teletext\MosaicFontRegistry.
 *
 * Pure logic, no network/filesystem access.
 */

use PHPUnit\Framework\TestCase;
use HomeLan\FileStore\Services\Provider\Teletext\BlocksFont;
use HomeLan\FileStore\Services\Provider\Teletext\ChonkFont;
use HomeLan\FileStore\Services\Provider\Teletext\MosaicFontInterface;
use HomeLan\FileStore\Services\Provider\Teletext\MosaicFontRegistry;
use HomeLan\FileStore\Services\Provider\Teletext\SquatFont;
use HomeLan\FileStore\Services\Provider\Teletext\TitleFont;

include_once(__DIR__ . '/../../src/include/system.inc.php');

class MosaicFontRegistryTest extends TestCase
{
    protected MosaicFontRegistry $oRegistry;

    protected function setUp(): void
    {
        $this->oRegistry = new MosaicFontRegistry();
    }

    public function testBlocksIsRegistered(): void
    {
        $oFont = $this->oRegistry->getByName('blocks');

        $this->assertInstanceOf(MosaicFontInterface::class, $oFont);
        $this->assertInstanceOf(BlocksFont::class, $oFont);
    }

    public function testSquatIsRegistered(): void
    {
        $oFont = $this->oRegistry->getByName('squat');

        $this->assertInstanceOf(MosaicFontInterface::class, $oFont);
        $this->assertInstanceOf(SquatFont::class, $oFont);
    }

    public function testTitleIsRegistered(): void
    {
        $oFont = $this->oRegistry->getByName('title');

        $this->assertInstanceOf(MosaicFontInterface::class, $oFont);
        $this->assertInstanceOf(TitleFont::class, $oFont);
    }

    public function testChonkIsRegistered(): void
    {
        $oFont = $this->oRegistry->getByName('chonk');

        $this->assertInstanceOf(MosaicFontInterface::class, $oFont);
        $this->assertInstanceOf(ChonkFont::class, $oFont);
    }

    public function testUnknownFontNameReturnsNull(): void
    {
        $this->assertNull($this->oRegistry->getByName('does-not-exist'));
    }

    public function testBlocksFontRendersWords(): void
    {
        $oFont = $this->oRegistry->getByName('blocks');

        $aRows = $oFont->renderWord('HAT', 6);

        $this->assertCount(3, $aRows);
    }
}
