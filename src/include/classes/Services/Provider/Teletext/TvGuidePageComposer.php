<?php

namespace HomeLan\FileStore\Services\Provider\Teletext;

/**
 * Renders a 2-day (today/tomorrow) UK Freeview TV listing (from
 * TvGuideFeedParser/TvGuideChannels) into raw teletext page buffers - 25
 * rows of 40 bytes each, padded to Storage::PAGE_SIZE - the same shape
 * NewsPageComposer and WeatherPageComposer already produce and
 * Storage/Teletext already serve.
 *
 * Pure logic - no network or filesystem access - unit tested directly
 * against known small inputs, asserting exact buffer bytes.
 *
 * Deliberately self-contained rather than sharing a base class with
 * NewsPageComposer/WeatherPageComposer (this project has no shared composer
 * base - see WeatherPageComposer's own docblock for why) - the row-buffer
 * conventions (colour bytes, mosaic-font headings, the
 * page-number-on-the-right index column, row-budget subpage packing with a
 * heading glued to the block that follows it) are duplicated on purpose to
 * keep each composer's small differences independently readable.
 */
class TvGuidePageComposer
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

	/** Name/colour of the MosaicFontRegistry entry used for both the index's "TV GUIDE" title and each channel page's own channel-name title - see _mosaicWordsRows(). */
	protected const string TITLE_FONT = 'chonk';
	protected const int TITLE_COLOUR = self::CYAN;

	/** Trailing "column" reserved on every index row for the page number: a blank gap byte, a colour byte, a 3-digit page number, then a trailing blank byte so the number doesn't sit flush against the hard right edge of the screen. */
	protected const int INDEX_PAGE_FIELD_WIDTH = 6;

	/** Rows available per subpage (rows 4-23) on both composeIndex() and composeChannelPage() - both share the same 4-row header shape: masthead, the chonk title's 2 rows, and the blank spacer that always follows a header. */
	protected const int BODY_ROWS = 20;

	/**
	 * @param array<int, array{page: string, label: string}> $aChannelEntries
	 * @return array<int, string>
	 */
	public function composeIndex(string $sPageNumber, array $aChannelEntries, \DateTimeImmutable $oNow): array
	{
		$aEntryRowGroups = [];
		foreach ($aChannelEntries as $aChannelEntry) {
			$aEntryRowGroups[] = [$this->_indexEntryRow($aChannelEntry), $this->_blankRow()];
		}
		$aSubpageRows = $this->_packRowGroups($aEntryRowGroups);
		$iTotal = count($aSubpageRows);

		$aBuffers = [];
		foreach ($aSubpageRows as $iIndex => $aBodyRows) {
			$aRows = [];
			$aRows[0] = $this->_masthead($sPageNumber, 'TV GUIDE', $oNow);
			[$aRows[1], $aRows[2]] = $this->_mosaicWordsRows(['TV', 'GUIDE']);
			$aRows[3] = $this->_blankRow();

			$iRow = 4;
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
	 * Composes one channel's own listing page: masthead, the channel name in
	 * chonk mosaic lettering (see $aMosaicWords - TvGuideChannels supplies an
	 * alphabetic-only rendering since ChonkFont has no digit glyphs), then a
	 * dense "TODAY"/"TOMORROW" listing - one row per programme (time +
	 * title, no per-row spacer, unlike composeIndex()'s entries), each day's
	 * heading kept glued to the entry that follows it so a subpage never
	 * ends on an orphaned heading, with one blank row separating the two
	 * days.
	 *
	 * @param array<int, string> $aMosaicWords
	 * @param array<int, array{lcn: int, start: int, stop: int, title: string}> $aTodayEvents
	 * @param array<int, array{lcn: int, start: int, stop: int, title: string}> $aTomorrowEvents
	 * @return array<int, string>
	 */
	public function composeChannelPage(string $sPageNumber, string $sChannelLabel, array $aMosaicWords, array $aTodayEvents, array $aTomorrowEvents, \DateTimeImmutable $oNow): array
	{
		$aBlocks = [];
		$aBlocks[] = ['type' => 'heading', 'rows' => [$this->_headingRow('TODAY')]];
		foreach ($aTodayEvents as $aEvent) {
			$aBlocks[] = ['type' => 'entry', 'rows' => [$this->_eventRow($aEvent, $oNow)]];
		}
		$aBlocks[] = ['type' => 'spacer', 'rows' => [$this->_blankRow()]];
		$aBlocks[] = ['type' => 'heading', 'rows' => [$this->_headingRow('TOMORROW')]];
		foreach ($aTomorrowEvents as $aEvent) {
			$aBlocks[] = ['type' => 'entry', 'rows' => [$this->_eventRow($aEvent, $oNow)]];
		}

		$aSubpageRows = $this->_packBlocks($aBlocks);
		$iTotal = count($aSubpageRows);

		$aBuffers = [];
		foreach ($aSubpageRows as $iIndex => $aBodyRows) {
			$aRows = [];
			$aRows[0] = $this->_masthead($sPageNumber, 'TV GUIDE', $oNow);
			[$aRows[1], $aRows[2]] = $this->_mosaicWordsRows($aMosaicWords);
			$aRows[3] = $this->_blankRow();

			$iRow = 4;
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

	// -------------------------------------------------------------------------
	// Row builders
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

	/**
	 * Renders one or two words in TITLE_FONT (chonk), joined with a gap when
	 * there are two, and centred within ROW_WIDTH - used for both the index
	 * page's "TV GUIDE" title and each channel page's own channel-name
	 * title. Unlike NewsPageComposer::_mosaicHeadingRows() (which joins two
	 * *different* fonts of differing row heights and so needs a baseline
	 * offset), both words here always come from the same font, so the row
	 * counts already match - no offset needed.
	 *
	 * @param array<int, string> $aWords
	 * @return array<int, string>
	 */
	protected function _mosaicWordsRows(array $aWords): array
	{
		$oFont = (new MosaicFontRegistry())->getByName(self::TITLE_FONT);
		if ($oFont === null) {
			throw new \RuntimeException('MosaicFontRegistry has no font named "' . self::TITLE_FONT . '"');
		}

		if (count($aWords) === 1) {
			$aRows = $oFont->renderWord($aWords[0], self::TITLE_COLOUR);
		} else {
			$aRows1 = $oFont->renderWord($aWords[0], self::TITLE_COLOUR);
			$aRows2 = $oFont->renderWord($aWords[1], self::TITLE_COLOUR);
			$aRows = [];
			foreach ($aRows1 as $i => $sRow1) {
				$aRows[] = $sRow1 . '  ' . ($aRows2[$i] ?? '');
			}
		}

		foreach ($aRows as $i => $s) {
			$iLeadPad = max(0, intdiv(self::ROW_WIDTH - strlen($s), 2));
			$aRows[$i] = $this->_fitRow(str_repeat(' ', $iLeadPad) . $s);
		}
		return $aRows;
	}

	/** @param array{page: string, label: string} $aChannelEntry */
	protected function _indexEntryRow(array $aChannelEntry): string
	{
		$iTextWidth = self::ROW_WIDTH - self::INDEX_PAGE_FIELD_WIDTH - 1;
		$sPageField = ' ' . chr(self::CYAN) . str_pad(substr($aChannelEntry['page'], 0, 3), 3, ' ', STR_PAD_LEFT) . ' ';
		return chr(self::WHITE)
			. str_pad($this->_truncate($aChannelEntry['label'], $iTextWidth), $iTextWidth, ' ')
			. $sPageField;
	}

	protected function _headingRow(string $sText): string
	{
		return $this->_fitRow(chr(self::YELLOW) . $sText);
	}

	/** @param array{lcn: int, start: int, stop: int, title: string} $aEvent */
	protected function _eventRow(array $aEvent, \DateTimeImmutable $oNow): string
	{
		$sTime = (new \DateTimeImmutable('@' . $aEvent['start']))->setTimezone($oNow->getTimezone())->format('H:i');
		$iTitleWidth = self::ROW_WIDTH - 1 - strlen($sTime) - 1;
		$s = chr(self::WHITE) . $sTime . ' ' . $this->_truncate($aEvent['title'], $iTitleWidth);
		return $this->_fitRow($s);
	}

	protected function _blankRow(): string
	{
		return str_repeat(' ', self::ROW_WIDTH);
	}

	// -------------------------------------------------------------------------
	// Subpage row-budget packing
	// -------------------------------------------------------------------------

	/**
	 * Packs pre-rendered [entry-row, blank-spacer-row] groups (see
	 * composeIndex()) into subpages by row budget - the same approach as
	 * WeatherPageComposer::_packIndexEntries(), simplified since a channel
	 * entry's own row count never varies.
	 *
	 * @param array<int, array{0: string, 1: string}> $aRowGroups
	 * @return array<int, array<int, string>>
	 */
	protected function _packRowGroups(array $aRowGroups): array
	{
		$aSubpages = [];
		$aCurrent  = [];
		$iBudget   = self::BODY_ROWS;

		foreach ($aRowGroups as $aRows) {
			if ($aCurrent !== [] && count($aRows) > $iBudget) {
				$aSubpages[] = $aCurrent;
				$aCurrent = [];
				$iBudget  = self::BODY_ROWS;
			}
			foreach ($aRows as $sRow) {
				$aCurrent[] = $sRow;
			}
			$iBudget -= count($aRows);
		}
		// Every $aRows group is a fixed 2-element tuple, so $aCurrent is
		// only empty here when $aRowGroups itself was empty - in which case
		// $aSubpages is empty too, and this still needs to produce that one
		// empty-body subpage, the same as WeatherPageComposer::_packIndexEntries().
		$aSubpages[] = $aCurrent;
		return $aSubpages;
	}

	/**
	 * Packs composeChannelPage()'s TODAY/TOMORROW blocks into subpages by
	 * row budget - the same "heading glued to the single block that follows
	 * it" approach as NewsPageComposer::_packIndexBlocks(), reimplemented
	 * locally per this project's established per-composer duplication
	 * convention (see class docblock).
	 *
	 * @param array<int, array{type: string, rows: array<int, string>}> $aBlocks
	 * @return array<int, array<int, string>>
	 */
	protected function _packBlocks(array $aBlocks): array
	{
		$aSubpages = [];
		$aCurrent  = [];
		$iBudget   = self::BODY_ROWS;

		foreach ($aBlocks as $i => $aBlock) {
			$iNeeded = count($aBlock['rows']);
			if ($aBlock['type'] === 'heading' && isset($aBlocks[$i + 1])) {
				$iNeeded += count($aBlocks[$i + 1]['rows']);
			}
			if ($aCurrent !== [] && $iNeeded > $iBudget) {
				$aSubpages[] = $aCurrent;
				$aCurrent = [];
				$iBudget  = self::BODY_ROWS;
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

	// -------------------------------------------------------------------------
	// Helpers - identical in behaviour to WeatherPageComposer's/
	// NewsPageComposer's (see there for the reasoning: no G0 representation
	// for typographic punctuation).
	// -------------------------------------------------------------------------

	protected function _truncate(string $s, int $iWidth): string
	{
		$s = $this->_sanitize($s);
		return $iWidth <= 0 ? '' : substr($s, 0, $iWidth);
	}

	protected function _sanitize(string $s): string
	{
		$s = strtr($s, [
			"\xE2\x80\x98" => "'",       // '
			"\xE2\x80\x99" => "'",       // '
			"\xE2\x80\x9C" => '"',       // "
			"\xE2\x80\x9D" => '"',       // "
			"\xE2\x80\x93" => '-',       // -
			"\xE2\x80\x94" => '-',       // -
			"\xE2\x80\xA6" => '...',     // ...
			"\xC2\xA0"     => ' ',       // non-breaking space
		]);
		if (function_exists('iconv')) {
			$sAscii = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $s);
			if ($sAscii !== false) {
				$s = $sAscii;
			}
		}
		return preg_replace('/[^\x20-\x7E]/', '', $s) ?? $s;
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
