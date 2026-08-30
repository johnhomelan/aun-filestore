<?php

namespace HomeLan\FileStore\Services\Provider\Teletext;

/**
 * The "squat" mosaic lettering style - registered under that name in
 * MosaicFontRegistry - a shorter, tighter-packed sibling of BlocksFont:
 * glyphs are 6 sub-pixel rows tall (2 teletext rows) rather than 9 (3
 * rows), and letters sit directly against their own bordered box with no
 * top/bottom bar convention of their own - see AbstractMosaicFont for the
 * shared rendering/byte-packing logic every font uses.
 *
 * These glyphs were reverse-measured pixel-for-pixel off a real archived
 * teletext page carrying a "showreel" title in this style: channel 8,
 * page 140 (/var/lib/aun-filestore-teletext/8/140.dat), rows 1-2 (the 2nd
 * and 3rd lines down) - two teletext rows of white G1 graphics immediately
 * followed on both rows by a colour change into a separate (non-lettering)
 * icon, which is why only those two rows and the "showreel" span of each
 * were sampled. Column-by-column decoding of those two rows' mosaic bytes
 * found a run of 48 sub-pixel columns split into exactly 8 letter-widths
 * by single blank "gap" columns (7 gaps for "SHOWREEL"'s 8 letters) - the
 * same one-sub-pixel-column gap convention BlocksFont's own renderWord()
 * inserts between letters - which is what fixed each letter's own
 * boundaries and width (S/H/O/R are 5 wide, W is 8, E/L are 4; the two
 * E's decoded to byte-for-byte identical bitmaps, a cross-check that the
 * column split was right). The bottom sub-pixel row of every glyph came
 * back entirely blank in the source, kept here as an explicit trailing
 * margin row rather than trimmed, matching BlocksFont's own margin rows.
 *
 * The glyphs cover the full A-Z alphabet. The original seven - S, H, O,
 * W, R, E, L - are what "SHOWREEL" needed, reverse-measured as above. The
 * remaining nineteen (A, B, C, D, F, G, I, J, K, M, N, P, Q, T, U, V, X,
 * Y, Z) are hand-drawn to match that established look: thin, un-boxed
 * strokes at the same 5-content-row height (plus the trailing blank
 * margin row), each letter's own natural width rather than a forced
 * common one - narrow single-stroke letters (I) as little as 1 sub-pixel
 * wide, most letters 4-5 wide, W and M wider (8 and 7) to fit their extra
 * strokes.
 */
class SquatFont extends AbstractMosaicFont
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
		'S' => ['01111', '11000', '11111', '00011', '11110', '00000'],
		'H' => ['11011', '11011', '11111', '11011', '11011', '00000'],
		'O' => ['01110', '11011', '11011', '11011', '01110', '00000'],
		'W' => ['11011011', '11011011', '11011011', '11011011', '01111110', '00000000'],
		'R' => ['11110', '11011', '11110', '11011', '11011', '00000'],
		'E' => ['0111', '1100', '1111', '1100', '0111', '0000'],
		'L' => ['1100', '1100', '1100', '1100', '0111', '0000'],
		'A' => ['01110', '11011', '11111', '11011', '11011', '00000'],
		'B' => ['1110', '1001', '1110', '1001', '1110', '0000'],
		'C' => ['0111', '1000', '1000', '1000', '0111', '0000'],
		'D' => ['1110', '1001', '1001', '1001', '1110', '0000'],
		'F' => ['1111', '1000', '1110', '1000', '1000', '0000'],
		'G' => ['01111', '10000', '10011', '10001', '01111', '00000'],
		'I' => ['1', '1', '1', '1', '1', '0'],
		'J' => ['0011', '0001', '0001', '1001', '0110', '0000'],
		'K' => ['1001', '1010', '1100', '1010', '1001', '0000'],
		'M' => ['1000001', '1100011', '1010101', '1000001', '1000001', '0000000'],
		'N' => ['11001', '11101', '10101', '10111', '10011', '00000'],
		'P' => ['1110', '1001', '1110', '1000', '1000', '0000'],
		'Q' => ['01110', '11011', '11011', '11101', '01111', '00000'],
		'T' => ['11111', '00100', '00100', '00100', '00100', '00000'],
		'U' => ['11011', '11011', '11011', '11011', '01110', '00000'],
		'V' => ['10001', '10001', '01010', '01010', '00100', '00000'],
		'X' => ['10001', '01010', '00100', '01010', '10001', '00000'],
		'Y' => ['10001', '01010', '00100', '00100', '00100', '00000'],
		'Z' => ['11111', '00010', '00100', '01000', '11111', '00000'],
	];
}
