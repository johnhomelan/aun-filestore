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

	/**
	 * Rows available to index content per subpage when the header is the
	 * plain double-height banner (rows 4-23, after the masthead, banner,
	 * and the blank row separating the banner from the first article
	 * title). A mosaic heading (see composeIndex()'s $aMosaicHeading and
	 * composeChannelIndex()) is itself 3 rows rather than the banner's 2,
	 * so its own blank separator pushes the body one row further down -
	 * see MOSAIC_HEADING_INDEX_BODY_ROWS.
	 */
	protected const int INDEX_BODY_ROWS = 20;

	/** Rows available to index content per subpage when the header is a mosaic heading - one fewer than INDEX_BODY_ROWS, see there. */
	protected const int MOSAIC_HEADING_INDEX_BODY_ROWS = self::INDEX_BODY_ROWS - 1;

	/** Word/font/colour for the channel-hub page's heading - see composeChannelIndex()/_hubHeadingRows(). */
	protected const string HUB_TITLE_WORD = 'BBC';
	protected const string HUB_TITLE_FONT = 'blocks';
	protected const int HUB_TITLE_COLOUR = self::WHITE;
	protected const string HUB_SUBTITLE_WORD = 'INDEX';
	protected const string HUB_SUBTITLE_FONT = 'chonk';
	protected const int HUB_SUBTITLE_COLOUR = self::CYAN;

	/**
	 * Trailing "column" reserved on every index title row for the page
	 * number: a blank gap byte, a colour byte, a 3-digit page number, then a
	 * trailing blank byte so the number doesn't sit flush against the hard
	 * right edge of the screen - kept blank (no title text) on wrapped
	 * continuation lines so the page number reads as its own separate
	 * column rather than text just trailing off the first line.
	 */
	protected const int INDEX_PAGE_FIELD_WIDTH = 6;

	/** Usable width for an index title line, including its own leading colour byte. */
	protected const int INDEX_TITLE_WIDTH = self::ROW_WIDTH - self::INDEX_PAGE_FIELD_WIDTH;

	/**
	 * $aMosaicHeading (BBC's own news index only - see NewsFeedDefinitions'
	 * $aIndexMosaicHeading and NewsImport) swaps the plain double-height
	 * colour-block banner (rows 1-2, plus row 3 as a blank spacer) for a
	 * two-word mosaic heading occupying the same 3 rows (see
	 * _mosaicHeadingRows()) - $sBannerText/$iBannerFg/$iBannerBg are then
	 * ignored. Left null (Guardian/Sky, and BBC's own default), the banner
	 * renders as before.
	 *
	 * @param array<int, array{page: string, headline: string, category?: string}> $aEntries
	 * @param ?array{word1: string, font1: string, colour1: int, word2: string, font2: string, colour2: int} $aMosaicHeading
	 * @return array<int, string>
	 */
	public function composeIndex(
		string $sPageNumber,
		array $aEntries,
		\DateTimeImmutable $oNow,
		string $sTitle,
		string $sBannerText,
		int $iBannerFg,
		int $iBannerBg,
		?array $aMosaicHeading = null
	): array {
		$iBudget = $aMosaicHeading !== null ? self::MOSAIC_HEADING_INDEX_BODY_ROWS : self::INDEX_BODY_ROWS;
		$aSubpageRows = $this->_packIndexBlocks($this->_indexBlocks($aEntries), $iBudget);
		$iTotal = count($aSubpageRows);

		$aBuffers = [];
		foreach ($aSubpageRows as $iIndex => $aBodyRows) {
			$aRows = [];
			$aRows[0] = $this->_masthead($sPageNumber, $sTitle, $oNow);
			if ($aMosaicHeading !== null) {
				[$aRows[1], $aRows[2], $aRows[3]] = $this->_mosaicHeadingRows(
					$aMosaicHeading['word1'],
					$aMosaicHeading['font1'],
					$aMosaicHeading['colour1'],
					$aMosaicHeading['word2'],
					$aMosaicHeading['font2'],
					$aMosaicHeading['colour2']
				);
				$aRows[4] = $this->_blankRow();
				$iBodyStart = 5;
			} else {
				[$aRows[1], $aRows[2]] = $this->_bannerRows($sBannerText, $iBannerFg, $iBannerBg);
				$aRows[3] = $this->_blankRow();
				$iBodyStart = 4;
			}

			$iRow = $iBodyStart;
			foreach ($aBodyRows as $sRow) {
				$aRows[$iRow] = $sRow;
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
	 * Composes the channel-hub page (page 100 on BBC's channel - see
	 * NewsImport) that links out to the other services sharing that channel
	 * (the real news index, now on page 101, and Weather's own index) rather
	 * than listing stories directly. Same masthead/body/pagination shape as
	 * composeIndex(), but with a mosaic "BBC INDEX" heading (see
	 * _hubHeadingRows()) in place of the double-height colour-block banner,
	 * since this page isn't any one source's own masthead banner - and, since
	 * there are only ever a handful of these entries (one per service on the
	 * channel, not one per story), each is rendered double-height (see
	 * _entryRows()'s $bDoubleHeight parameter) rather than composeIndex()'s
	 * normal single-height entry rows.
	 *
	 * @param array<int, array{page: string, headline: string, category?: string}> $aEntries
	 * @return array<int, string>
	 */
	public function composeChannelIndex(
		string $sPageNumber,
		array $aEntries,
		\DateTimeImmutable $oNow,
		string $sTitle
	): array {
		$aSubpageRows = $this->_packIndexBlocks($this->_indexBlocks($aEntries, true), self::MOSAIC_HEADING_INDEX_BODY_ROWS);
		$iTotal = count($aSubpageRows);

		$aBuffers = [];
		foreach ($aSubpageRows as $iIndex => $aBodyRows) {
			$aRows = [];
			$aRows[0] = $this->_masthead($sPageNumber, $sTitle, $oNow);
			[$aRows[1], $aRows[2], $aRows[3]] = $this->_mosaicHeadingRows(
				self::HUB_TITLE_WORD,
				self::HUB_TITLE_FONT,
				self::HUB_TITLE_COLOUR,
				self::HUB_SUBTITLE_WORD,
				self::HUB_SUBTITLE_FONT,
				self::HUB_SUBTITLE_COLOUR
			);
			$aRows[4] = $this->_blankRow();

			$iRow = 5;
			foreach ($aBodyRows as $sRow) {
				$aRows[$iRow] = $sRow;
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
		string $sTitle,
		int $iHeadlineFg,
		int $iHeadlineBg
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

		// Available text width for the double-height headline: ROW_WIDTH
		// minus the 4-byte coloured-background/height preamble and the
		// 1-byte left margin rendered in front of the text (see below).
		$iHeadlineWidth = self::ROW_WIDTH - 5;

		$sPlainHeadline = $this->_stripMarkers($sHeadline);
		$aHeadlineLines = $this->_wrapText($sPlainHeadline, $iHeadlineWidth);
		if (count($aHeadlineLines) > 2) {
			$aHeadlineLines = [$aHeadlineLines[0], rtrim(substr($aHeadlineLines[1], 0, $iHeadlineWidth - 3)) . '...'];
		}

		$aBuffers = [];
		foreach ($aPages as $iIndex => $aChunk) {
			$aRows = [];
			$aRows[0] = $this->_masthead($sPageNumber, $sTitle, $oNow);

			if ($iIndex === 0) {
				// Double-height glyphs are drawn across two physical screen
				// rows on real teletext hardware, so the coloured background
				// has to be re-asserted (text-free) on the row below each
				// headline line too, the same way _bannerRows fills its
				// second row - otherwise the block's bottom half would
				// revert to the default black background.
				$sPreamble = chr($iHeadlineBg) . chr(self::NEW_BACKGROUND) . chr($iHeadlineFg) . chr(self::DOUBLE_HEIGHT);
				$iRow = 1;
				foreach ([$aHeadlineLines[0] ?? '', $aHeadlineLines[1] ?? ''] as $sHLine) {
					if ($sHLine === '') {
						$aRows[$iRow] = $this->_blankRow();
						$aRows[$iRow + 1] = $this->_blankRow();
					} else {
						$aRows[$iRow] = $this->_fitRow($sPreamble . ' ' . $sHLine);
						$aRows[$iRow + 1] = $this->_fitRow(chr($iHeadlineBg) . chr(self::NEW_BACKGROUND));
					}
					$iRow += 2;
				}
				$aRows[5] = $sPublished !== null
					? $this->_fitRow(chr(self::CYAN) . $this->_truncate('Published: ' . $sPublished, 39))
					: $this->_blankRow();
				$iBodyStart = 6;
			} else {
				$aRows[1] = $this->_fitRow(chr(self::YELLOW) . $this->_truncate($sPlainHeadline, 39));
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

	/**
	 * Renders a two-word mosaic heading, e.g. the channel-hub page's "BBC" +
	 * "INDEX" (see composeChannelIndex()) or the BBC news index's own "BBC"
	 * + "NEWS" (see composeIndex()'s $aMosaicHeading): $sWord1 in $sFont1,
	 * $sWord2 in $sFont2 immediately after it on the same teletext rows,
	 * baseline-aligned - when the two fonts differ in row height (e.g.
	 * blocks' 3 rows vs chonk/title's 2), the shorter word starts one row
	 * group down so both words' bottoms line up, the way differently
	 * capitalled type would in print, leaving the shorter word's leading row
	 * slot(s) blank.
	 *
	 * @return array<int, string>
	 */
	protected function _mosaicHeadingRows(string $sWord1, string $sFont1, int $iColour1, string $sWord2, string $sFont2, int $iColour2): array
	{
		$oRegistry = new MosaicFontRegistry();
		$oFont1 = $oRegistry->getByName($sFont1);
		$oFont2 = $oRegistry->getByName($sFont2);
		if ($oFont1 === null || $oFont2 === null) {
			throw new \RuntimeException('MosaicFontRegistry is missing a font needed for a mosaic heading');
		}

		$aRows1 = $oFont1->renderWord($sWord1, $iColour1);
		$aRows2 = $oFont2->renderWord($sWord2, $iColour2);
		$iOffset = count($aRows1) - count($aRows2);

		$aRows = [];
		foreach ($aRows1 as $i => $sRow1) {
			$sRow2 = $i >= $iOffset ? $aRows2[$i - $iOffset] : '';
			$aRows[] = $this->_fitRow(' ' . $sRow1 . '  ' . $sRow2);
		}
		return $aRows;
	}

	/**
	 * Groups index entries into "blocks" - a category heading (when any
	 * entry carries a category) followed by its entries, each rendered as
	 * its own atomic group of rows - in first-appearance order. Entries with
	 * no category are bucketed under a "General" heading once any other
	 * entry has one; when none do, entries are returned flat with no
	 * headings at all, unchanged from the pre-category behaviour.
	 *
	 * $bDoubleHeight is threaded straight through to _entryRows() - see
	 * there - composeIndex() leaves it false (normal single-height story
	 * entries), composeChannelIndex() passes true (its handful of
	 * service-link entries are double-height).
	 *
	 * @param array<int, array{page: string, headline: string, category?: string}> $aEntries
	 * @return array<int, array{type: string, rows: array<int, string>}>
	 */
	protected function _indexBlocks(array $aEntries, bool $bDoubleHeight = false): array
	{
		$bAnyCategory = false;
		$aGroups = [];
		foreach ($aEntries as $aEntry) {
			$sCategory = trim((string) ($aEntry['category'] ?? ''));
			if ($sCategory !== '') {
				$bAnyCategory = true;
			}
			$aGroups[$sCategory][] = $aEntry;
		}

		$aBlocks = [];
		if (!$bAnyCategory) {
			foreach ($aEntries as $aEntry) {
				$aBlocks[] = ['type' => 'entry', 'rows' => $this->_entryRows($aEntry, $bDoubleHeight)];
			}
			return $aBlocks;
		}

		foreach ($aGroups as $sGroupKey => $aGroupEntries) {
			$sLabel = $sGroupKey === '' ? 'General' : $sGroupKey;
			$aBlocks[] = ['type' => 'heading', 'rows' => [$this->_fitRow(chr(self::YELLOW) . strtoupper($sLabel))]];
			foreach ($aGroupEntries as $aEntry) {
				$aBlocks[] = ['type' => 'entry', 'rows' => $this->_entryRows($aEntry, $bDoubleHeight)];
			}
		}
		return $aBlocks;
	}

	/**
	 * Packs index blocks into subpages by row budget (rather than a fixed
	 * entry count per subpage, since a wrapped title takes more rows than a
	 * one-line one) - a category heading is kept glued to the entry that
	 * follows it so a subpage never ends on an orphaned heading.
	 * $iPerSubpageBudget is the caller's own INDEX_BODY_ROWS or
	 * MOSAIC_HEADING_INDEX_BODY_ROWS, depending on which header shape it's
	 * packing body rows underneath - see composeIndex()/composeChannelIndex().
	 *
	 * @param array<int, array{type: string, rows: array<int, string>}> $aBlocks
	 * @return array<int, array<int, string>>
	 */
	protected function _packIndexBlocks(array $aBlocks, int $iPerSubpageBudget): array
	{
		$aSubpages = [];
		$aCurrent  = [];
		$iBudget   = $iPerSubpageBudget;

		foreach ($aBlocks as $i => $aBlock) {
			$iNeeded = count($aBlock['rows']);
			if ($aBlock['type'] === 'heading' && isset($aBlocks[$i + 1])) {
				$iNeeded += count($aBlocks[$i + 1]['rows']);
			}
			if ($aCurrent !== [] && $iNeeded > $iBudget) {
				$aSubpages[] = $aCurrent;
				$aCurrent = [];
				$iBudget  = $iPerSubpageBudget;
			}
			foreach ($aBlock['rows'] as $sRow) {
				$aCurrent[] = $sRow;
			}
			$iBudget -= count($aBlock['rows']);
		}
		if ($aCurrent !== [] || $aSubpages === []) {
			$aSubpages[] = $aCurrent;
		}
		return $aSubpages;
	}

	/**
	 * Renders one index entry as 2-3 rows: a title line (wrapped to a
	 * second line when it doesn't fit), then a blank spacer row. The
	 * rightmost INDEX_PAGE_FIELD_WIDTH bytes are reserved as the page
	 * number's own column on every title line - the page number itself is
	 * only placed there on the first line, and left blank (not title text)
	 * on a wrapped second line, so the column reads as a separate column
	 * rather than text running under the number.
	 *
	 * $bDoubleHeight (composeChannelIndex() only - see _indexBlocks())
	 * inserts DOUBLE_HEIGHT right after each title line's leading colour
	 * byte, covering the page-number field too since it follows on the same
	 * row. No background re-assertion is needed on the blank spacer row
	 * beneath it (unlike _bannerRows()'s double-height banner) - the text
	 * sits on the page's default black background throughout, so that
	 * already-blank row is a correct bottom half as-is.
	 *
	 * @param array{page: string, headline: string, category?: string} $aEntry
	 * @return array<int, string>
	 */
	protected function _entryRows(array $aEntry, bool $bDoubleHeight = false): array
	{
		// The DOUBLE_HEIGHT control byte occupies a 41st cell alongside the
		// leading colour byte both already budgeted for by "- 1" - so it
		// must shrink the available text width by one more column itself,
		// or the page-number field gets pushed past ROW_WIDTH and truncated
		// by _fitRow().
		$iTextWidth = self::INDEX_TITLE_WIDTH - 1 - ($bDoubleHeight ? 1 : 0);
		$aLines = $this->_wrapText($this->_stripMarkers($aEntry['headline']), $iTextWidth);
		if (count($aLines) > 2) {
			$aLines = [$aLines[0], rtrim(substr($aLines[1], 0, max(0, $iTextWidth - 3))) . '...'];
		}

		$sBlankPageField = str_repeat(' ', self::INDEX_PAGE_FIELD_WIDTH);
		$sPageField = ' ' . chr(self::CYAN) . str_pad(substr($aEntry['page'], 0, 3), 3, ' ', STR_PAD_LEFT) . ' ';
		$sHeightMarker = $bDoubleHeight ? chr(self::DOUBLE_HEIGHT) : '';

		$aRows = [];
		$aRows[] = chr(self::WHITE) . $sHeightMarker
			. str_pad($this->_truncate($aLines[0] ?? '', $iTextWidth), $iTextWidth, ' ')
			. $sPageField;

		if (($aLines[1] ?? '') !== '') {
			$aRows[] = chr(self::WHITE) . $sHeightMarker
				. str_pad($this->_truncate($aLines[1], $iTextWidth), $iTextWidth, ' ')
				. $sBlankPageField;
		}

		$aRows[] = $this->_blankRow();
		return $aRows;
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
