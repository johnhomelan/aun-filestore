<?php

namespace HomeLan\FileStore\Services\Provider\Teletext;

/**
 * The "chonk" mosaic lettering style - registered under that name in
 * MosaicFontRegistry - a rounded, evenly-weighted sibling of SquatFont and
 * TitleFont: glyphs are 6 sub-pixel rows tall (2 teletext rows, the same
 * height as both), built from soft rounded corners (see "O"/"D"/"G" below)
 * rather than SquatFont's thin strokes or TitleFont's hard right angles.
 * See AbstractMosaicFont for the shared rendering/byte-packing logic every
 * font uses.
 *
 * These glyphs were reverse-measured pixel-for-pixel off eight real
 * archived page headings that use this style, each a row 1-2 mosaic word
 * in white after the masthead, decoded and split into letters the same
 * way as SquatFont/TitleFont (the single blank sub-pixel column
 * renderWord() already inserts between letters, with a wider multi-column
 * gap between separate words):
 *  - channel 7 page 140 (/var/lib/aun-filestore-teletext/7/140.dat): "CLEAN FEED" (top left, before a separate icon graphic)
 *  - channel 7 page 160 (/var/lib/aun-filestore-teletext/7/160.dat): "WEATHER"
 *  - channel 7 page 120 (/var/lib/aun-filestore-teletext/7/120.dat): "ON THIS DAY"
 *  - channel 7 page 190 (/var/lib/aun-filestore-teletext/7/190.dat): "ENGINEERING"
 *  - channel 7 page 200 (/var/lib/aun-filestore-teletext/7/200.dat): "TV GUIDE"
 *  - channel 8 page 200 (/var/lib/aun-filestore-teletext/8/200.dat): "RADIO GUIDE"
 *  - channel 8 page 300 (/var/lib/aun-filestore-teletext/8/300.dat): "SPORT"
 *  - channel 8 page 120 (/var/lib/aun-filestore-teletext/8/120.dat): "REWIND"
 * Between them these words repeat several letters many times over, and
 * every repeat decoded byte-for-byte identically to its other occurrences
 * - "E" across all six of its occurrences, "N"/"G"/"I"/"D"/"R"/"T"/"O"/"W"
 * across two to four occurrences each - which is strong cross-validation
 * that the per-word column segmentation was right.
 *
 * Only the letters these eight words needed (A, C, D, E, F, G, H, I, L, N,
 * O, P, R, S, T, U, V, W, Y) are defined so far - extend $aGlyphs with
 * more as other composers need them, the same as BlocksFont, SquatFont
 * and TitleFont all started small too.
 *
 * X is the one exception: it's hand-drawn (a symmetric diagonal cross,
 * matching the 5-wide box most other glyphs use) rather than reverse-measured
 * from an archived page, since none of the eight source words above contain
 * it - added for NewsPageComposer's "BBC INDEX" channel-hub heading (see
 * NewsPageComposer::_hubHeadingRows()), the same "hand-drawn to match the
 * established look" approach BlocksFont already used for its own
 * non-source letters.
 */
class ChonkFont extends AbstractMosaicFont
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
		'A' => ['01110', '11011', '11111', '11011', '11011', '00000'],
		'C' => ['01110', '11011', '11000', '11011', '01110', '00000'],
		'D' => ['11110', '11011', '11011', '11011', '11110', '00000'],
		'E' => ['0111', '1100', '1111', '1100', '0111', '0000'],
		'F' => ['0111', '1100', '1111', '1100', '1100', '0000'],
		'G' => ['01111', '11000', '11011', '11011', '01110', '00000'],
		'H' => ['11011', '11011', '11111', '11011', '11011', '00000'],
		'I' => ['11', '11', '11', '11', '11', '00'],
		'L' => ['1100', '1100', '1100', '1100', '0111', '0000'],
		'N' => ['01110', '11011', '11011', '11011', '11011', '00000'],
		'O' => ['01110', '11011', '11011', '11011', '01110', '00000'],
		'P' => ['11110', '11011', '11110', '11000', '11000', '00000'],
		'R' => ['11110', '11011', '11110', '11011', '11011', '00000'],
		'S' => ['01111', '11000', '11111', '00011', '11110', '00000'],
		'T' => ['1111', '0110', '0110', '0110', '0110', '0000'],
		'U' => ['11011', '11011', '11011', '11011', '01110', '00000'],
		'V' => ['11011', '11011', '11011', '01110', '00100', '00000'],
		'W' => ['11011011', '11011011', '11011011', '11011011', '01111110', '00000000'],
		'X' => ['11011', '01010', '00100', '11011', '11010', '00000'],
		'Y' => ['11011', '11011', '01111', '00011', '11110', '00000'],
		'B' => ['11110', '11011', '11111', '11011', '11110', '00000'],
		'J' => ['01111', '00011', '00011', '11011', '01111', '00000'],
		'K' => ['11011', '11010', '11100', '11110', '11011', '00000'],
		'M' => ['01111110', '11011011', '11011011', '11011011', '11011011', '00000000'],
	];
}
