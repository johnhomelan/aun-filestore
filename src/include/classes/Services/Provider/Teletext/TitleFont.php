<?php

namespace HomeLan\FileStore\Services\Provider\Teletext;

/**
 * The "title" mosaic lettering style - registered under that name in
 * MosaicFontRegistry - a bold, solid-stroke sibling of BlocksFont and
 * SquatFont: glyphs are 6 sub-pixel rows tall (2 teletext rows, the same
 * height as SquatFont) built from thick, fully-solid strokes rather than
 * SquatFont's thinner lines. See AbstractMosaicFont for the shared
 * rendering/byte-packing logic every font uses.
 *
 * These glyphs were reverse-measured pixel-for-pixel off two real archived
 * page headings that use this style: channel 7 page 300
 * (/var/lib/aun-filestore-teletext/7/300.dat), whose row 1-2 mosaic reads
 * "NEWS" after a shared page-icon graphic and a colour change into red at
 * byte offset 11, and channel 7 page 105
 * (/var/lib/aun-filestore-teletext/7/105.dat), whose row 1-2 mosaic reads
 * "BUSINESS" after the identical icon/colour-change prefix. Both pages'
 * icon+colour-change prefix bytes (offsets 0-11) are shared page furniture,
 * not lettering, and were excluded from sampling. As with SquatFont, each
 * letter's own boundary and width came from the single blank sub-pixel
 * column renderWord() already inserts between letters. "N" and "E" decoded
 * byte-for-byte identically between the two source words, and "S" decoded
 * identically across all three of its occurrences in "BUSINESS" (4
 * sub-pixels wide) - "NEWS" itself has an "S" one column narrower, which
 * doesn't match the other three and isn't used, the same kind of one-off
 * source inconsistency as BlocksFont's oddly-wide "T".
 *
 * The glyphs cover the full A-Z alphabet. The original seven - B, E, I,
 * N, S, U, W - are what "NEWS"/"BUSINESS" needed, reverse-measured as
 * above. The remaining nineteen (A, C, D, F, G, H, J, K, L, M, O, P, Q,
 * R, T, V, X, Y, Z) are hand-drawn to match that established look: bold,
 * fully-solid strokes built from the same small vocabulary of row shapes
 * already visible above (a solid full-width bar, a hollow "sides only"
 * row, and one-sided partial bars for corners/crossbars), each letter's
 * own natural width rather than a forced common one - most 4 wide, a few
 * (T, V, X, Y) 5 wide and M 7 wide to fit their extra strokes, matching
 * the existing I/W's already-uneven widths.
 */
class TitleFont extends AbstractMosaicFont
{
	protected const int GLYPH_SUBPIXEL_ROWS = 6;

	/**
	 * Each glyph is a 6-sub-pixel-row bitmap ('1' = lit in the caller's
	 * chosen foreground colour, '0' = background) - 3 rows per teletext
	 * row, 2 teletext rows tall. Width varies per letter - see the class
	 * docblock for how each letter's own width was established.
	 *
	 * @var array<string, array<int, string>>
	 */
	protected array $aGlyphs = [
		'N' => ['1111', '1001', '1001', '1001', '1001', '0000'],
		'E' => ['1111', '1000', '1111', '1000', '1111', '0000'],
		'W' => ['10010010', '10010010', '10010010', '10010010', '11111110', '00000000'],
		'S' => ['1111', '1000', '1111', '0001', '1111', '0000'],
		'B' => ['1111', '1001', '1110', '1001', '1111', '0000'],
		'U' => ['1001', '1001', '1001', '1001', '1111', '0000'],
		'I' => ['1', '1', '1', '1', '1', '0'],
		'A' => ['0110', '1001', '1111', '1001', '1001', '0000'],
		'C' => ['0111', '1000', '1000', '1000', '0111', '0000'],
		'D' => ['1110', '1001', '1001', '1001', '1110', '0000'],
		'F' => ['1111', '1000', '1110', '1000', '1000', '0000'],
		'G' => ['0111', '1000', '1011', '1001', '0111', '0000'],
		'H' => ['1001', '1001', '1111', '1001', '1001', '0000'],
		'J' => ['0011', '0001', '0001', '1001', '0110', '0000'],
		'K' => ['1001', '1010', '1100', '1010', '1001', '0000'],
		'L' => ['1000', '1000', '1000', '1000', '1111', '0000'],
		'M' => ['1000001', '1100011', '1010101', '1000001', '1000001', '0000000'],
		'O' => ['0110', '1001', '1001', '1001', '0110', '0000'],
		'P' => ['1110', '1001', '1110', '1000', '1000', '0000'],
		'Q' => ['0110', '1001', '1001', '1011', '0111', '0000'],
		'R' => ['1110', '1001', '1110', '1010', '1001', '0000'],
		'T' => ['11111', '00100', '00100', '00100', '00100', '00000'],
		'V' => ['10001', '10001', '01010', '01010', '00100', '00000'],
		'X' => ['10001', '01010', '00100', '01010', '10001', '00000'],
		'Y' => ['10001', '01010', '00100', '00100', '00100', '00000'],
		'Z' => ['1111', '0010', '0100', '1000', '1111', '0000'],
	];
}
