<?php

namespace HomeLan\FileStore\Services\Provider\Teletext;

/**
 * The "blocks" mosaic lettering style - registered under that name in
 * MosaicFontRegistry - renders a word as G1 mosaic block lettering, each
 * letter a thin stroke in the caller's chosen colour on the plain black
 * background. See AbstractMosaicFont for the shared rendering/byte-packing
 * logic this and every other font uses - this class supplies only the
 * glyph table and the 9-sub-pixel-row glyph height.
 *
 * The glyphs cover the full A-Z alphabet. The original six - W, E, A, T,
 * H, R - are what WeatherPageComposer's "WEATHER" title needed, and most
 * of their strokes were reverse-measured pixel-for-pixel off a real
 * archived Ceefax page's own "WEATHER" logo (using its regular
 * alphanumeric text elsewhere on the page to establish an exact,
 * unambiguous cell size, then sampling the logo itself against that grid -
 * see WeatherPageComposer's git history for the derivation and
 * cross-checks); "T" is hand-drawn instead, in the same style, because it
 * sat right at an ambiguous boundary between two letters sharing a
 * continuous border with no clean gap between them in the source. The
 * remaining letters (B, C, D, F, G, I, J, K, L, M, N, O, P, Q, S, U, V, X,
 * Y, Z) are hand-drawn to match that established look: each glyph sits in
 * its own bordered box (unbroken foreground on the top row, bottom row,
 * and left/right columns), the same 9x9 size as W/E/A/H/R, with the
 * letter's own shape picked out inside that border by which interior
 * sub-pixels are foreground versus background.
 */
class BlocksFont extends AbstractMosaicFont
{
	protected const int GLYPH_SUBPIXEL_ROWS = 9;

	/**
	 * Each glyph is a 9-sub-pixel-row bitmap ('1' = lit in the caller's
	 * chosen foreground colour, '0' = background) - 3 rows per teletext
	 * row (top/middle/bottom third), 3 teletext rows tall. Width varies
	 * per letter - the source drew most of these at 9 sub-pixels wide, but
	 * "T" at 10 - see AbstractMosaicFont::renderWord() for how that's
	 * handled without forcing a common width.
	 *
	 * @var array<string, array<int, string>>
	 */
	protected array $aGlyphs = [
		'W' => ['000000000', '111111111', '100101001', '100101001', '100101001', '100101001', '100000001', '111111111', '000000000'],
		'E' => ['000000000', '011111111', '010000001', '010011111', '010000011', '010011111', '010000001', '011111111', '000000000'],
		'A' => ['000000000', '011111111', '010000001', '010011001', '010000001', '010011001', '010011001', '011111111', '000000000'],
		'T' => ['0000000000', '1111111111', '1000000001', '1111001111', '1111001111', '1111001111', '1111001111', '1111111111', '0000000000'],
		'H' => ['000000000', '011111111', '010011001', '010011001', '010000001', '010011001', '010011001', '011111111', '000000000'],
		'R' => ['000000000', '011111111', '010000001', '010011001', '010000011', '010011001', '010011001', '011111111', '000000000'],
		'B' => ['000000000', '011111111', '010000001', '010011101', '010000001', '010011101', '010000001', '011111111', '000000000'],
		'C' => ['000000000', '011111111', '010000001', '010011111', '010011111', '010011111', '010000001', '011111111', '000000000'],
		'D' => ['000000000', '011111111', '010000001', '010011101', '010011101', '010011101', '010000001', '011111111', '000000000'],
		'F' => ['000000000', '011111111', '010000001', '010011111', '010000011', '010011111', '010011111', '011111111', '000000000'],
		'G' => ['000000000', '011111111', '010000001', '010011111', '010010001', '010011001', '010000001', '011111111', '000000000'],
		'I' => ['000000000', '011111111', '010000001', '011100111', '011100111', '011100111', '010000001', '011111111', '000000000'],
		'J' => ['000000000', '011111111', '011000011', '011110011', '011110011', '011110011', '011100111', '011111111', '000000000'],
		'K' => ['000000000', '011111111', '010011001', '010010011', '010000111', '010000111', '010010011', '011111111', '000000000'],
		'L' => ['000000000', '011111111', '010011111', '010011111', '010011111', '010011111', '010000001', '011111111', '000000000'],
		'M' => ['000000000', '011111111', '010000001', '010100101', '010100101', '010100101', '010100101', '011111111', '000000000'],
		'N' => ['000000000', '011111111', '010000001', '010011001', '010011001', '010011001', '010011001', '011111111', '000000000'],
		'O' => ['000000000', '011111111', '010000001', '010011001', '010011001', '010011001', '010000001', '011111111', '000000000'],
		'P' => ['000000000', '011111111', '010000001', '010011101', '010000001', '010011111', '010011111', '011111111', '000000000'],
		'Q' => ['000000000', '011111111', '010000011', '010011011', '010011011', '010011011', '010000001', '011111111', '000000000'],
		'S' => ['000000000', '011111111', '010000001', '010011111', '010000001', '011111001', '010000001', '011111111', '000000000'],
		'U' => ['000000000', '011111111', '010011001', '010011001', '010011001', '010011001', '010000001', '011111111', '000000000'],
		'V' => ['000000000', '011111111', '010111101', '010011001', '010011001', '011000011', '011100111', '011111111', '000000000'],
		'X' => ['000000000', '011111111', '010111101', '011011011', '011100111', '011011011', '010111101', '011111111', '000000000'],
		'Y' => ['000000000', '011111111', '010111101', '010011001', '011000011', '011100111', '011100111', '011111111', '000000000'],
		'Z' => ['000000000', '011111111', '010000001', '011111001', '011000011', '010011111', '010000001', '011111111', '000000000'],
	];
}
