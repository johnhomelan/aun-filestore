<?php

namespace HomeLan\FileStore\Services\Provider\Teletext;

/**
 * Renders news content (from NewsFeedParser/ArticleExtractor) into raw
 * teletext page buffers - 25 rows of 40 bytes each, padded to
 * Storage::PAGE_SIZE, exactly the shape TeefaxTtiParser already produces
 * and Storage/Teletext already serve.
 *
 * Pure logic - no network or filesystem access - unit tested directly
 * against known small inputs, asserting exact buffer bytes.
 *
 * Control codes used are Teletext Level 1 (SAA5050), the same table
 * teletext-render.js decodes for the admin "Browse Pages" viewer:
 * 0x00-0x07 alpha colour (black/red/green/yellow/blue/magenta/cyan/white),
 * 0x0C/0x0D normal/double height, 0x1C/0x1D black/new(=fg) background.
 * Every row re-asserts the colour (and height) it needs at column 0, so it
 * decodes correctly on its own regardless of what the previous row left set
 * - matching how TeefaxTtiParser already treats rows independently.
 *
 * The masthead title/banner text and colours are supplied by the caller
 * (see NewsFeedDefinitions) so each news source gets its own look - there
 * is no verified archival reference for any of these (see the news-import
 * plan), so all three are a stylistic approximation, not pixel-exact
 * reproductions.
 */
class NewsPageComposer
{
	public const int ROW_COUNT  = 25;
	public const int ROW_WIDTH  = 40;
	public const int PAGE_SIZE  = Storage::PAGE_SIZE;

	public const int BLACK   = 0x00;
	public const int RED     = 0x01;
	public const int GREEN   = 0x02;
	public const int YELLOW  = 0x03;
	public const int BLUE    = 0x04;
	public const int MAGENTA = 0x05;
	public const int CYAN    = 0x06;
	public const int WHITE   = 0x07;

	protected const int NORMAL_HEIGHT = 0x0C;
	protected const int DOUBLE_HEIGHT = 0x0D;
	protected const int NEW_BACKGROUND = 0x1D;

	/** Body rows available on subpage 1 (rows 6-23, after the double-height headline + byline). */
	protected const int FIRST_SUBPAGE_BODY_ROWS = 18;

	/** Body rows available on continuation subpages (rows 3-23, after a single-height headline recap). */
	protected const int CONT_SUBPAGE_BODY_ROWS = 21;

	/** Headline entries per index subpage (rows 3-23, after the masthead + double-height banner). */
	protected const int INDEX_ENTRIES_PER_SUBPAGE = 21;

	/**
	 * @param array<int, array{page: string, headline: string}> $aEntries
	 * @return array<int, string>
	 */
	public function composeIndex(
		string $sPageNumber,
		array $aEntries,
		\DateTimeImmutable $oNow,
		string $sTitle,
		string $sBannerText,
		int $iBannerFg,
		int $iBannerBg
	): array {
		$aChunks = array_chunk($aEntries, self::INDEX_ENTRIES_PER_SUBPAGE);
		if ($aChunks === []) {
			$aChunks = [[]];
		}
		$iTotal = count($aChunks);

		$aBuffers = [];
		foreach ($aChunks as $iIndex => $aChunk) {
			$aRows = [];
			$aRows[0] = $this->_masthead($sPageNumber, $sTitle, $oNow);
			[$aRows[1], $aRows[2]] = $this->_bannerRows($sBannerText, $iBannerFg, $iBannerBg);

			$iRow = 3;
			foreach ($aChunk as $aEntry) {
				$aRows[$iRow] = $this->_entryRow($aEntry);
				$iRow++;
			}
			for (; $iRow <= 23; $iRow++) {
				$aRows[$iRow] = $this->_blankRow();
			}
			$aRows[24] = $iTotal > 1
				? $this->_fitRow(chr(self::WHITE) . 'Subpage ' . ($iIndex + 1) . '/' . $iTotal)
				: $this->_blankRow();

			$aBuffers[] = $this->_assemble($aRows);
		}
		return $aBuffers;
	}

	/**
	 * @param array<int, array{type: string, text: string}> $aBlocks
	 * @return array<int, string>
	 */
	public function composeStory(
		string $sPageNumber,
		string $sHeadline,
		?string $sPublished,
		array $aBlocks,
		\DateTimeImmutable $oNow,
		string $sTitle
	): array {
		$aBodyLines = $this->_bodyLines($aBlocks);

		$aPages = [];
		$aRemaining = $aBodyLines;
		$bFirst = true;
		do {
			$iCap = $bFirst ? self::FIRST_SUBPAGE_BODY_ROWS : self::CONT_SUBPAGE_BODY_ROWS;
			$aPages[] = array_splice($aRemaining, 0, $iCap);
			$bFirst = false;
		} while ($aRemaining !== []);
		$iTotal = count($aPages);

		$sPlainHeadline = $this->_stripMarkers($sHeadline);
		$aHeadlineLines = $this->_wrapText($sPlainHeadline, 39);
		if (count($aHeadlineLines) > 2) {
			$aHeadlineLines = [$aHeadlineLines[0], rtrim(substr($aHeadlineLines[1], 0, 36)) . '...'];
		}

		$aBuffers = [];
		foreach ($aPages as $iIndex => $aChunk) {
			$aRows = [];
			$aRows[0] = $this->_masthead($sPageNumber, $sTitle, $oNow);

			if ($iIndex === 0) {
				$iRow = 1;
				foreach ([$aHeadlineLines[0] ?? '', $aHeadlineLines[1] ?? ''] as $sHLine) {
					$aRows[$iRow] = $sHLine === ''
						? $this->_blankRow()
						: $this->_fitRow(chr(self::WHITE) . chr(self::DOUBLE_HEIGHT) . $sHLine);
					$aRows[$iRow + 1] = $this->_blankRow();
					$iRow += 2;
				}
				$aRows[5] = $sPublished !== null
					? $this->_fitRow(chr(self::CYAN) . $this->_truncate('Published: ' . $sPublished, 39))
					: $this->_blankRow();
				$iBodyStart = 6;
			} else {
				$aRows[1] = $this->_fitRow(chr(self::WHITE) . $this->_truncate($sPlainHeadline, 39));
				$aRows[2] = $this->_blankRow();
				$iBodyStart = 3;
			}

			$iRow = $iBodyStart;
			foreach ($aChunk as $sLine) {
				$aRows[$iRow] = $sLine;
				$iRow++;
			}
			for (; $iRow <= 23; $iRow++) {
				$aRows[$iRow] = $this->_blankRow();
			}
			$aRows[24] = $iTotal > 1
				? $this->_fitRow(chr(self::WHITE) . 'p ' . ($iIndex + 1) . '/' . $iTotal)
				: $this->_blankRow();

			$aBuffers[] = $this->_assemble($aRows);
		}
		return $aBuffers;
	}

	// -------------------------------------------------------------------------
	// Shared row builders
	// -------------------------------------------------------------------------

	protected function _masthead(string $sPage, string $sTitle, \DateTimeImmutable $oNow): string
	{
		$sDate = $oNow->format('D d M');
		$s = chr(self::WHITE) . 'P' . $sPage;
		$s = str_pad($s, 8, ' ');
		$s .= chr(self::YELLOW) . $sTitle;
		$s = str_pad($s, max(strlen($s), self::ROW_WIDTH - 1 - strlen($sDate)), ' ');
		$s .= chr(self::WHITE) . $sDate;
		return $this->_fitRow($s);
	}

	/** @return array{0: string, 1: string} */
	protected function _bannerRows(string $sText, int $iFg, int $iBg): array
	{
		$sPreamble = chr($iBg) . chr(self::NEW_BACKGROUND) . chr($iFg) . chr(self::DOUBLE_HEIGHT);
		$sRow1 = $this->_fitRow($sPreamble . ' ' . $sText);
		$sRow2 = $this->_fitRow(chr($iBg) . chr(self::NEW_BACKGROUND));
		return [$sRow1, $sRow2];
	}

	/** @param array{page: string, headline: string} $aEntry */
	protected function _entryRow(array $aEntry): string
	{
		$s = chr(self::CYAN) . str_pad($aEntry['page'], 3) . ' ' . chr(self::WHITE);
		$s .= $this->_truncate($aEntry['headline'], self::ROW_WIDTH - strlen($s));
		return $this->_fitRow($s);
	}

	protected function _blankRow(): string
	{
		return str_repeat(' ', self::ROW_WIDTH);
	}

	// -------------------------------------------------------------------------
	// Body block layout
	// -------------------------------------------------------------------------

	/**
	 * @param array<int, array{type: string, text: string}> $aBlocks
	 * @return array<int, string>
	 */
	protected function _bodyLines(array $aBlocks): array
	{
		$aLines = [];
		foreach ($aBlocks as $i => $aBlock) {
			if ($i > 0) {
				$aLines[] = $this->_blankRow();
			}
			foreach ($this->_blockLines($aBlock) as $sRow) {
				$aLines[] = $sRow;
			}
		}
		return $aLines;
	}

	/**
	 * @param array{type: string, text: string} $aBlock
	 * @return array<int, string>
	 */
	protected function _blockLines(array $aBlock): array
	{
		[$iColour, $sFirstPrefix, $sContPrefix] = match ($aBlock['type']) {
			'heading'   => [self::YELLOW, '', ''],
			'list-item' => [self::GREEN, '- ', '  '],
			'quote'     => [self::CYAN, '> ', '  '],
			default     => [self::WHITE, '', ''],
		};

		$iWidth = self::ROW_WIDTH - 1 - strlen($sFirstPrefix);
		$aWrapped = $this->_wrapText($aBlock['text'], $iWidth);

		$aRows = [];
		foreach ($aWrapped as $i => $sLine) {
			$sPrefix = $i === 0 ? $sFirstPrefix : $sContPrefix;
			$aRows[] = $this->_renderTextRow($sLine, $iColour, $sPrefix);
		}
		return $aRows;
	}

	/**
	 * Renders one already-wrapped line (which may still contain the
	 * "\x01".."\x04" strong/em markers ArticleExtractor embeds) into an
	 * exact ROW_WIDTH-byte row, translating markers into colour-change
	 * control codes. A marker that would overflow the fixed-width row is
	 * simply dropped rather than allowed to push real text off the end -
	 * losing an isolated colour toggle right at a line's edge is a
	 * cosmetic-only, rare edge case.
	 */
	protected function _renderTextRow(string $sLine, int $iBaseColour, string $sPrefix): string
	{
		$s = chr($iBaseColour) . $sPrefix;
		$iBudget = self::ROW_WIDTH - strlen($s);
		$iUsed = 0;
		$aColourStack = [$iBaseColour];

		for ($i = 0, $iLen = strlen($sLine); $i < $iLen; $i++) {
			$cChar = $sLine[$i];
			switch ($cChar) {
				case "\x01":
					$aColourStack[] = self::YELLOW;
					if ($iUsed < $iBudget) {
						$s .= chr(self::YELLOW);
						$iUsed++;
					}
					break;
				case "\x02":
				case "\x04":
					if (count($aColourStack) > 1) {
						array_pop($aColourStack);
					}
					if ($iUsed < $iBudget) {
						$s .= chr($aColourStack[count($aColourStack) - 1]);
						$iUsed++;
					}
					break;
				case "\x03":
					$aColourStack[] = self::CYAN;
					if ($iUsed < $iBudget) {
						$s .= chr(self::CYAN);
						$iUsed++;
					}
					break;
				default:
					if ($iUsed < $iBudget) {
						$s .= $cChar;
						$iUsed++;
					}
					break;
			}
		}

		return $this->_fitRow($s);
	}

	// -------------------------------------------------------------------------
	// Word-wrap - counts only visible (non-marker) characters against the
	// column width; markers are accounted for separately, at render time,
	// by _renderTextRow().
	// -------------------------------------------------------------------------

	/**
	 * @return array<int, string>
	 */
	protected function _wrapText(string $sText, int $iWidth): array
	{
		$iWidth = max(1, $iWidth);
		$aLines = [];
		foreach (explode("\n", $this->_sanitize($sText)) as $sSegment) {
			$aWords = preg_split('/\s+/', trim($sSegment)) ?: [];
			$sCurrent = '';
			foreach ($aWords as $sWord) {
				if ($sWord === '') {
					continue;
				}
				$sTry = $sCurrent === '' ? $sWord : $sCurrent . ' ' . $sWord;
				if ($this->_visibleLength($sTry) > $iWidth && $sCurrent !== '') {
					$aLines[] = $sCurrent;
					$sCurrent = $sWord;
				} else {
					$sCurrent = $sTry;
				}
			}
			$aLines[] = $sCurrent;
		}
		return $aLines;
	}

	protected function _visibleLength(string $s): int
	{
		return strlen(str_replace(["\x01", "\x02", "\x03", "\x04"], '', $s));
	}

	protected function _stripMarkers(string $s): string
	{
		return str_replace(["\x01", "\x02", "\x03", "\x04"], '', $s);
	}

	protected function _truncate(string $s, int $iWidth): string
	{
		$s = $this->_stripMarkers($this->_sanitize($s));
		return $iWidth <= 0 ? '' : substr($s, 0, $iWidth);
	}

	/**
	 * Scraped article text is UTF-8 and full of typographic punctuation
	 * (curly quotes, en/em dashes, ellipsis characters, £ signs) that has no
	 * representation in Teletext's 7-bit G0 character set - left as-is, a
	 * multi-byte UTF-8 sequence gets split across single-byte buffer cells
	 * and renders as mojibake (seen live while verifying Guardian import:
	 * a curly apostrophe corrupted "Gen Z's" mid-headline). This transliterates
	 * the common cases to their closest G0-representable byte first - notably
	 * £ becomes byte 0x23, the G0 code point that a real teletext decoder
	 * (and this project's own teletext-render.js) renders as "£", per the
	 * English/UK G0 national-option substitutions documented there - then
	 * lets iconv transliterate any remaining accented Latin characters
	 * (café, naïve, ...) to a plain-ASCII approximation, then drops anything
	 * left that still isn't printable ASCII (the marker bytes \x01-\x04 are
	 * exempted so ArticleExtractor's strong/em spans keep working).
	 *
	 * One accepted side effect: a genuine literal "#" in source text also
	 * becomes byte 0x23 and so would itself display as "£" on a real
	 * decoder - the English G0 set has no code point that means both, and
	 * this project follows the same tradeoff real Ceefax services did (£
	 * appears constantly in news copy; a literal "#" essentially never
	 * does).
	 */
	protected function _sanitize(string $s): string
	{
		$s = strtr($s, [
			"\xE2\x80\x98" => "'",       // ‘
			"\xE2\x80\x99" => "'",       // ’
			"\xE2\x80\x9C" => '"',       // “
			"\xE2\x80\x9D" => '"',       // ”
			"\xE2\x80\x93" => '-',       // –
			"\xE2\x80\x94" => '-',       // —
			"\xE2\x80\xA6" => '...',     // …
			"\xC2\xA0"     => ' ',       // non-breaking space
			"\xC2\xA3"     => chr(0x23), // £ -> G0 0x23
		]);
		if (function_exists('iconv')) {
			$sAscii = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $s);
			if ($sAscii !== false) {
				$s = $sAscii;
			}
		}
		return preg_replace('/[^\x01-\x04\x20-\x7E]/', '', $s) ?? $s;
	}

	protected function _fitRow(string $s): string
	{
		return substr(str_pad($s, self::ROW_WIDTH, ' '), 0, self::ROW_WIDTH);
	}

	/**
	 * @param array<int, string> $aRows
	 */
	protected function _assemble(array $aRows): string
	{
		$s = '';
		for ($i = 0; $i < self::ROW_COUNT; $i++) {
			$s .= $aRows[$i] ?? $this->_blankRow();
		}
		return str_pad($s, self::PAGE_SIZE, "\0");
	}
}
