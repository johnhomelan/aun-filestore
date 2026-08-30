<?php

namespace HomeLan\FileStore\Services\Provider\Teletext;

/**
 * Shared rendering logic for every MosaicFontInterface implementation -
 * turning a word into G1 mosaic teletext bytes from a per-letter sub-pixel
 * bitmap - factored out once BlocksFont got a sibling (SquatFont) that
 * needs the exact same byte-packing but its own glyph table and its own
 * glyph height (GLYPH_SUBPIXEL_ROWS), which is why that constant and
 * $aGlyphs are the only things a concrete font overrides.
 *
 * A concrete font's GLYPH_SUBPIXEL_ROWS must be a multiple of 3 - each
 * mosaic byte packs 3 sub-pixel rows (top/middle/bottom third of one
 * teletext row), and renderWord() chunks the assembled bitmap into that
 * many groups.
 */
abstract class AbstractMosaicFont implements MosaicFontInterface
{
	/** Sets the current colour AND switches into G1 mosaic graphics mode (0x10-0x17 - a separate control-code range from the 0x00-0x07 alpha colour set, per teletext-render.js's decoder). */
	protected const int GRAPHICS_COLOUR_BASE = 0x10;

	/** Base byte for a G1 contiguous mosaic character - bit 5 is always set; bits 0,1,2,3,4,6 hold the six sub-cell pixels (see _mosaicChar()). */
	protected const int MOSAIC_BASE = 0x20;

	/** Sub-pixel rows per glyph - must be a multiple of 3; overridden per concrete font. */
	protected const int GLYPH_SUBPIXEL_ROWS = 9;

	/**
	 * Each glyph is a GLYPH_SUBPIXEL_ROWS-tall bitmap ('1' = lit in the
	 * caller's chosen foreground colour, '0' = background). Width varies
	 * per letter - a letter's own width is never rounded up to a common
	 * value or to a whole number of mosaic cells, see renderWord().
	 *
	 * @var array<string, array<int, string>>
	 */
	protected array $aGlyphs = [];

	/**
	 * The glyph height renderWord() works to. Reads GLYPH_SUBPIXEL_ROWS by
	 * default, so every concrete font keeps declaring its height as that
	 * constant and nothing about their behaviour changes - this exists only
	 * so a caller holding a glyph table that isn't baked into a class (the
	 * teletext-font-editor's draft font, previewing unsaved edits) can carry
	 * its height per-instance and still render through this exact code path
	 * rather than a reimplementation of it.
	 */
	protected function _subpixelRows(): int
	{
		return static::GLYPH_SUBPIXEL_ROWS;
	}

	/**
	 * Renders $sWord as G1 mosaic lettering in $iColour, one teletext row
	 * string per group of 3 sub-pixel rows - un-padded and un-centred:
	 * fitting/centring the result within a page's own row width is the
	 * caller's job (see WeatherPageComposer::_titleRows()).
	 *
	 * Builds the whole word as one continuous per-sub-pixel-row bit string
	 * (each letter's own row plus a 1-sub-pixel blank gap, concatenated)
	 * *before* choosing any mosaic character - _subpixelRowsToMosaic()
	 * only chunks that finished string into cells and picks each cell's
	 * G1 code afterwards, so a letter's own width is never rounded up to
	 * an even cell-aligned width. A cell can still end up straddling two
	 * letters (or a letter and its gap) - that's fine, it's just resolved
	 * to whatever G1 code matches those 6 sub-pixels, the same as within a
	 * single letter.
	 *
	 * @return list<string> one teletext row string per group of 3 sub-pixel rows
	 * @throws \InvalidArgumentException if $sWord contains a letter with no defined glyph
	 */
	public function renderWord(string $sWord, int $iColour): array
	{
		foreach (str_split($sWord) as $cLetter) {
			if (!isset($this->aGlyphs[$cLetter])) {
				throw new \InvalidArgumentException(static::class . ' has no glyph for "' . $cLetter . '"');
			}
		}

		$iRows = $this->_subpixelRows();
		$aSubpixelRows = array_fill(0, $iRows, '');
		for ($iLetter = 0, $iLen = strlen($sWord); $iLetter < $iLen; $iLetter++) {
			$aGlyph = $this->aGlyphs[$sWord[$iLetter]];
			for ($iRow = 0; $iRow < $iRows; $iRow++) {
				$aSubpixelRows[$iRow] .= $aGlyph[$iRow];
				if ($iLetter < $iLen - 1) {
					$aSubpixelRows[$iRow] .= '0';
				}
			}
		}

		$aRows = [];
		for ($iGroup = 0; $iGroup * 3 < $iRows; $iGroup++) {
			$i0 = $iGroup * 3;
			$aRows[] = $this->_subpixelRowsToMosaic($aSubpixelRows[$i0], $aSubpixelRows[$i0 + 1], $aSubpixelRows[$i0 + 2], $iColour);
		}
		return $aRows;
	}

	/**
	 * Turns 3 equal-length sub-pixel-row bit strings (the top/middle/bottom
	 * third of one teletext row) into that row's worth of G1 mosaic
	 * characters. Chunks 2 sub-pixel columns at a time regardless of where
	 * any letter's own boundary fell when the strings were built; an odd
	 * total width is padded with one trailing blank sub-pixel column so
	 * every cell has a full pair to encode.
	 */
	protected function _subpixelRowsToMosaic(string $sTop, string $sMid, string $sBot, int $iColour): string
	{
		$iWidth = max(strlen($sTop), strlen($sMid), strlen($sBot));
		if ($iWidth % 2 !== 0) {
			$iWidth++;
		}
		$sTop = str_pad($sTop, $iWidth, '0');
		$sMid = str_pad($sMid, $iWidth, '0');
		$sBot = str_pad($sBot, $iWidth, '0');

		$s = chr(static::GRAPHICS_COLOUR_BASE + $iColour);
		for ($c = 0; $c < $iWidth; $c += 2) {
			$s .= $this->_mosaicChar(
				$sTop[$c] === '1', $sTop[$c + 1] === '1',
				$sMid[$c] === '1', $sMid[$c + 1] === '1',
				$sBot[$c] === '1', $sBot[$c + 1] === '1'
			);
		}
		return $s;
	}

	/**
	 * Encodes one G1 contiguous mosaic character from its six sub-cell
	 * pixels (top-left, top-right, middle-left, middle-right, bottom-left,
	 * bottom-right) - the same bit layout (0,1,2,3,4,6 of the byte, bit 5
	 * always set) teletext-render.js's decoder documents and decodes.
	 */
	protected function _mosaicChar(bool $bTopLeft, bool $bTopRight, bool $bMidLeft, bool $bMidRight, bool $bBottomLeft, bool $bBottomRight): string
	{
		$i = static::MOSAIC_BASE
			| ($bTopLeft ? 0x01 : 0)
			| ($bTopRight ? 0x02 : 0)
			| ($bMidLeft ? 0x04 : 0)
			| ($bMidRight ? 0x08 : 0)
			| ($bBottomLeft ? 0x10 : 0)
			| ($bBottomRight ? 0x40 : 0);
		return chr($i);
	}
}
