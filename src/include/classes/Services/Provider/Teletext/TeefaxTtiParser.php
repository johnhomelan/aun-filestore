<?php

namespace HomeLan\FileStore\Services\Provider\Teletext;

/**
 * Parses MRG Systems ".tti" teletext page files (as used by the Teefax
 * archive and most modern teletext editing/broadcast tools, e.g. VBIT2)
 * into raw {@see Storage}-shaped page buffers.
 *
 * This is pure logic — no filesystem or network access — so it can be
 * unit tested directly against literal `.tti` fixture strings. Converting
 * one whole Teefax checkout into on-disk pages (iterating files, writing
 * them out under `{channel}/{page}.dat` / `{page}_{subpage}.dat`) is the
 * importer command's job, not this class's.
 *
 * Format summary (see docs/protocols/teletext.md for citations): a `.tti`
 * file is a sequence of CRLF-terminated lines, each a two-letter tag
 * followed by comma-separated arguments. This parser only interprets two
 * tags:
 *
 *   PN,mppss   Page number: m = magazine (1 digit, 1-8), pp = page number
 *              (2 hex digits), ss = subpage (2 decimal digits, 00 meaning
 *              "the only/default subpage" the same way this project's own
 *              wire protocol treats subpage 0). Starts a new page — an
 *              already-open page is flushed to the results first, so one
 *              file containing several PN lines (a subpage carousel, or
 *              even unrelated pages) yields one result per PN.
 *   OL,row,data  Output line: content for one screen row (0 = header,
 *              1-24 = body). Rows 25 and up are enhancement/linking
 *              packets, not part of the raw screen buffer this project
 *              stores, and are ignored, along with every other tag
 *              (DE, CT, SC, PS, FL, RE, PF, ...) — none of them carry
 *              on-screen content.
 *
 * Row data can embed teletext control codes (0x00-0x1F) three different
 * ways (all decoded back to a literal 0x00-0x1F byte in the output
 * buffer, matching what a real `*SAVE` of Mode 7 screen memory contains):
 * literally, with bit 7 set (0x80-0x9F), or as a two-byte viewdata-style
 * escape (0x1B followed by the code with bit 6 set).
 */
class TeefaxTtiParser
{
	public const ROW_COUNT = 25;
	public const ROW_WIDTH = 40;

	/**
	 * @return array<int, array{magazine: int, page: string, subpage: int, buffer: string}>
	 */
	public function parse(string $sContent): array
	{
		$aResults = [];
		$aRows    = [];
		$iMagazine = null;
		$sPage     = null;
		$iSubpage  = null;

		foreach (preg_split('/\r\n|\n|\r/', $sContent) as $sLine) {
			if ($sLine === '') {
				continue;
			}
			$iComma = strpos($sLine, ',');
			if ($iComma === false) {
				continue;
			}
			$sTag  = substr($sLine, 0, $iComma);
			$sRest = substr($sLine, $iComma + 1);

			if ($sTag === 'PN') {
				if ($sPage !== null) {
					$aResults[] = $this->_buildPage($iMagazine, $sPage, $iSubpage, $aRows);
				}
				if (preg_match('/^([1-8])([0-9A-Fa-f]{2})([0-9]{2})/', $sRest, $aMatches)) {
					$iMagazine = (int) $aMatches[1];
					$sPage     = strtoupper($aMatches[2]);
					$iSubpage  = max(1, (int) $aMatches[3]);
					$aRows     = [];
				} else {
					// Malformed PN — drop until the next valid one.
					$sPage = null;
				}
				continue;
			}

			if ($sTag === 'OL' && $sPage !== null) {
				$iComma2 = strpos($sRest, ',');
				if ($iComma2 === false) {
					continue;
				}
				$iRow = (int) substr($sRest, 0, $iComma2);
				if ($iRow >= 0 && $iRow < self::ROW_COUNT) {
					$aRows[$iRow] = $this->_decodeRow(substr($sRest, $iComma2 + 1));
				}
				continue;
			}

			// Every other tag (DE, CT, SC, PS, FL, RE, PF, ...) is metadata
			// that doesn't affect the raw screen buffer — ignored.
		}

		if ($sPage !== null) {
			$aResults[] = $this->_buildPage($iMagazine, $sPage, $iSubpage, $aRows);
		}

		return $aResults;
	}

	/**
	 * @param array<int, string> $aRows
	 * @return array{magazine: int, page: string, subpage: int, buffer: string}
	 */
	protected function _buildPage(int $iMagazine, string $sPage, int $iSubpage, array $aRows): array
	{
		$sBuffer = '';
		for ($i = 0; $i < self::ROW_COUNT; $i++) {
			$sBuffer .= $aRows[$i] ?? str_repeat(' ', self::ROW_WIDTH);
		}
		return [
			'magazine' => $iMagazine,
			'page'     => $iMagazine . $sPage,
			'subpage'  => $iSubpage,
			'buffer'   => str_pad($sBuffer, Storage::PAGE_SIZE, "\0"),
		];
	}

	/**
	 * Decodes one row's raw file bytes into exactly 40 screen bytes,
	 * reversing whichever of the three control-code encodings was used
	 * (see class docblock), space-padding or truncating to fit.
	 */
	protected function _decodeRow(string $sRow): string
	{
		$sOutput = '';
		$iLen    = strlen($sRow);
		$i       = 0;
		while ($i < $iLen && strlen($sOutput) < self::ROW_WIDTH) {
			$iByte = ord($sRow[$i]);
			if ($iByte === 0x1B && $i + 1 < $iLen) {
				$sOutput .= chr(ord($sRow[$i + 1]) & 0x1F);
				$i += 2;
			} elseif ($iByte >= 0x80 && $iByte <= 0x9F) {
				$sOutput .= chr($iByte & 0x1F);
				$i += 1;
			} else {
				$sOutput .= chr($iByte);
				$i += 1;
			}
		}
		return str_pad(substr($sOutput, 0, self::ROW_WIDTH), self::ROW_WIDTH, ' ');
	}
}
