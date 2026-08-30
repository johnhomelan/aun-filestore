<?php

namespace HomeLan\FileStore\Services\Provider\Teletext;

/**
 * Renders BBC Weather forecast data (from WeatherFeedParser) into raw
 * teletext page buffers - 25 rows of 40 bytes each, padded to
 * Storage::PAGE_SIZE - the same shape NewsPageComposer and TeefaxTtiParser
 * already produce and Storage/Teletext already serve.
 *
 * Pure logic - no network or filesystem access - unit tested directly
 * against known small inputs, asserting exact buffer bytes.
 *
 * Deliberately self-contained rather than sharing a base class with
 * NewsPageComposer (this project has no shared composer base today - see
 * NewsPageComposer/TeefaxTtiParser, which don't share one either) - the
 * row-buffer conventions (colour bytes, mosaic-font headings, the
 * page-number-on-the-right index column, row-budget subpage packing) are
 * duplicated on purpose to keep each composer's small differences (weather
 * has a fixed 8-location list with no wrapping or category grouping, unlike
 * news's category-grouped index) independently readable.
 */
class WeatherPageComposer
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

	protected const int NEW_BACKGROUND = 0x1D;

	/** Sets the current colour AND switches into G1 mosaic graphics mode (0x10-0x17 - a separate control-code range from the 0x00-0x07 alpha colour set, per teletext-render.js's decoder) - _sunRows() uses this directly; the "WEATHER" title's own mosaic bytes come from a MosaicFontRegistry font instead. */
	protected const int GRAPHICS_COLOUR_BASE = 0x10;

	/** Name of the MosaicFontRegistry entry used for the "WEATHER" title - see _titleRows(). */
	protected const string TITLE_FONT = 'blocks';

	/** Name/colour of the MosaicFontRegistry entry used for each location page's own heading (the location's label) - see _locationTitleRows(). */
	protected const string LOCATION_TITLE_FONT = 'chonk';
	protected const int LOCATION_TITLE_COLOUR = self::WHITE;

	/** Trailing "column" reserved on every index row for the page number: a blank gap byte, a colour byte, a 3-digit page number, then a trailing blank byte so the number doesn't sit flush against the hard right edge of the screen. */
	protected const int INDEX_PAGE_FIELD_WIDTH = 6;

	/** Rows available to index content per subpage: rows 9-23, after the masthead, "WEATHER" title, sun graphic, and the blank row separating the header from the first location entry. */
	protected const int INDEX_BODY_ROWS = 15;

	/**
	 * @param array<int, array{page: string, label: string}> $aLocations
	 * @return array<int, string>
	 */
	public function composeIndex(string $sPageNumber, array $aLocations, \DateTimeImmutable $oNow): array
	{
		$aEntryRowGroups = [];
		foreach ($aLocations as $aLocation) {
			$aEntryRowGroups[] = [$this->_indexEntryRow($aLocation), $this->_blankRow()];
		}
		$aSubpageRows = $this->_packIndexEntries($aEntryRowGroups);
		$iTotal = count($aSubpageRows);

		$aBuffers = [];
		foreach ($aSubpageRows as $iIndex => $aBodyRows) {
			$aRows = [];
			$aRows[0] = $this->_masthead($sPageNumber, 'WEATHER', $oNow);
			[$aRows[1], $aRows[2], $aRows[3]] = $this->_titleRows();
			[$aRows[4], $aRows[5], $aRows[6], $aRows[7]] = $this->_sunRows();
			$aRows[8] = $this->_blankRow();

			$iRow = 9;
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
	 * @param array<int, array{day: string, condition: string, minC: ?string, maxC: ?string, windDir: ?string, windSpeed: ?string, humidity: ?string}> $aForecastDays
	 * @return array<int, string>
	 */
	public function composeLocationPage(string $sPageNumber, string $sLocationLabel, array $aForecastDays, \DateTimeImmutable $oNow): array
	{
		$aRows = [];
		$aRows[0] = $this->_masthead($sPageNumber, 'WEATHER', $oNow);
		[$aRows[1], $aRows[2]] = $this->_locationTitleRows($sLocationLabel);
		$aRows[3] = $this->_blankRow();

		$iRow = 4;
		foreach ($aForecastDays as $aDay) {
			if ($iRow > 22) {
				break;
			}
			$aRows[$iRow] = $this->_dayConditionRow($aDay);
			$aRows[$iRow + 1] = $this->_dayDetailRow($aDay);
			$aRows[$iRow + 2] = $this->_blankRow();
			$iRow += 3;
		}
		for (; $iRow <= 24; $iRow++) {
			$aRows[$iRow] = $this->_blankRow();
		}

		return [$this->_assemble($aRows)];
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
	 * The index header's "WEATHER" title, spelled out in mosaic block
	 * lettering (cyan) across 3 normal-height teletext rows (9 sub-pixel
	 * rows) on the default black background, using the "blocks" font (see
	 * TITLE_FONT) looked up via MosaicFontRegistry - see BlocksFont for the
	 * glyphs and how a word's rendered, and its docblock for where these
	 * particular strokes came from and how the letters' widths were
	 * cross-checked.
	 *
	 * @return array{0: string, 1: string, 2: string}
	 */
	protected function _titleRows(): array
	{
		$oFont = (new MosaicFontRegistry())->getByName(self::TITLE_FONT);
		if ($oFont === null) {
			throw new \RuntimeException('MosaicFontRegistry has no font named "' . self::TITLE_FONT . '"');
		}
		$aRows = $oFont->renderWord('WEATHER', self::CYAN);
		foreach ($aRows as $i => $s) {
			$iLeadPad = max(0, intdiv(self::ROW_WIDTH - strlen($s), 2));
			$aRows[$i] = $this->_fitRow(str_repeat(' ', $iLeadPad) . $s);
		}
		if (count($aRows) !== 3) {
			throw new \RuntimeException(self::TITLE_FONT . ' font rendered ' . count($aRows) . ' rows for "WEATHER", expected 3');
		}
		return $aRows;
	}

	/**
	 * The sun graphic below the "WEATHER" title: a blue-background band
	 * (using NEW_BACKGROUND's bg=fg trick) carrying the sun's own yellow
	 * mosaic pixels - both decoded
	 * byte-for-byte from the same archived page as _titleRows() (see its
	 * docblock for how/why that's reliable), copied verbatim as raw mosaic
	 * byte sequences rather than round-tripped through bit arrays like the
	 * lettering, since here there's no cross-row alignment to get right -
	 * each row's yellow segment is self-contained.
	 *
	 * Row 3 here is genuinely blank in the source (not a gap this project
	 * introduced) - the icon's own pixels only occupy rows 1, 2 and a
	 * single accent cell in row 4.
	 *
	 * @return array{0: string, 1: string, 2: string, 3: string}
	 */
	protected function _sunRows(): array
	{
		$sBluePrefix = chr(self::GRAPHICS_COLOUR_BASE + self::BLUE) . chr(self::NEW_BACKGROUND);
		$sYellow = chr(self::GRAPHICS_COLOUR_BASE + self::YELLOW);

		return [
			$this->_fitRow($sBluePrefix . '   ' . $sYellow . "#/?'! "),
			$this->_fitRow($sBluePrefix . '  ' . $sYellow . '0!  b   !0 '),
			$this->_fitRow($sBluePrefix),
			$this->_fitRow($sBluePrefix . '   ' . $sYellow . "   `   "),
		];
	}

	/**
	 * Each location page's own heading: its label (e.g. "London",
	 * "Newcastle") spelled out in mosaic block lettering using the "chonk"
	 * font (see LOCATION_TITLE_FONT) via MosaicFontRegistry, centred across
	 * chonk's 2 teletext rows on the default black background - the same
	 * centring approach as _titleRows()'s "WEATHER" title (blocks font, 3
	 * rows), just a caller-supplied word instead of a fixed one.
	 *
	 * @return array{0: string, 1: string}
	 */
	protected function _locationTitleRows(string $sLabel): array
	{
		$oFont = (new MosaicFontRegistry())->getByName(self::LOCATION_TITLE_FONT);
		if ($oFont === null) {
			throw new \RuntimeException('MosaicFontRegistry has no font named "' . self::LOCATION_TITLE_FONT . '"');
		}
		$aRows = $oFont->renderWord(strtoupper($sLabel), self::LOCATION_TITLE_COLOUR);
		foreach ($aRows as $i => $s) {
			$iLeadPad = max(0, intdiv(self::ROW_WIDTH - strlen($s), 2));
			$aRows[$i] = $this->_fitRow(str_repeat(' ', $iLeadPad) . $s);
		}
		if (count($aRows) !== 2) {
			throw new \RuntimeException(self::LOCATION_TITLE_FONT . ' font rendered ' . count($aRows) . ' rows for "' . $sLabel . '", expected 2');
		}
		return $aRows;
	}

	/** @param array{page: string, label: string} $aLocation */
	protected function _indexEntryRow(array $aLocation): string
	{
		$iTextWidth = self::ROW_WIDTH - self::INDEX_PAGE_FIELD_WIDTH - 1;
		$sPageField = ' ' . chr(self::CYAN) . str_pad(substr($aLocation['page'], 0, 3), 3, ' ', STR_PAD_LEFT) . ' ';
		return chr(self::WHITE)
			. str_pad($this->_truncate($aLocation['label'], $iTextWidth), $iTextWidth, ' ')
			. $sPageField;
	}

	/**
	 * Packs pre-rendered location entries (each a fixed 2-row [title,
	 * blank-spacer] pair - see composeIndex()) into subpages - the same
	 * intent as NewsPageComposer::_packIndexBlocks()'s row-budget packing,
	 * but since a location entry's own row count never varies (no title
	 * wrapping, no category headings to keep glued to their following entry)
	 * this reduces to a fixed number of whole entries per subpage:
	 * INDEX_BODY_ROWS rows / 2 rows per entry.
	 *
	 * Always returns at least one subpage - an empty one when there are no
	 * locations at all - so composeIndex() still emits a page.
	 *
	 * @param array<int, array{0: string, 1: string}> $aEntryRowGroups
	 * @return non-empty-list<list<string>>
	 */
	protected function _packIndexEntries(array $aEntryRowGroups): array
	{
		$iEntriesPerSubpage = max(1, intdiv(self::INDEX_BODY_ROWS, 2));

		$aSubpages = [];
		foreach (array_chunk($aEntryRowGroups, $iEntriesPerSubpage) as $aChunk) {
			$aRows = [];
			foreach ($aChunk as $aEntryRows) {
				foreach ($aEntryRows as $sRow) {
					$aRows[] = $sRow;
				}
			}
			$aSubpages[] = $aRows;
		}
		if ($aSubpages === []) {
			$aSubpages[] = [];
		}
		return $aSubpages;
	}

	/** @param array{day: string, condition: string, minC: ?string, maxC: ?string, windDir: ?string, windSpeed: ?string, humidity: ?string} $aDay */
	protected function _dayConditionRow(array $aDay): string
	{
		$s = chr(self::WHITE) . $this->_truncate($aDay['day'], 20) . ': ' . chr(self::YELLOW);
		$s .= $this->_truncate($aDay['condition'], self::ROW_WIDTH - strlen($s));
		return $this->_fitRow($s);
	}

	/** @param array{day: string, condition: string, minC: ?string, maxC: ?string, windDir: ?string, windSpeed: ?string, humidity: ?string} $aDay */
	protected function _dayDetailRow(array $aDay): string
	{
		$aParts = [];
		if ($aDay['minC'] !== null) {
			$aParts[] = 'Low ' . $aDay['minC'] . 'C';
		}
		if ($aDay['maxC'] !== null) {
			$aParts[] = 'High ' . $aDay['maxC'] . 'C';
		}
		if ($aDay['windDir'] !== null || $aDay['windSpeed'] !== null) {
			$aParts[] = trim('Wind ' . ($aDay['windDir'] ?? '') . ' ' . ($aDay['windSpeed'] ?? ''));
		}
		if ($aDay['humidity'] !== null) {
			$aParts[] = 'Humidity ' . $aDay['humidity'];
		}

		return $this->_fitRow(chr(self::CYAN) . $this->_truncate(implode('  ', $aParts), self::ROW_WIDTH - 1));
	}

	protected function _blankRow(): string
	{
		return str_repeat(' ', self::ROW_WIDTH);
	}

	// -------------------------------------------------------------------------
	// Helpers - identical in behaviour to NewsPageComposer's (see there for
	// the reasoning: no G0 representation for typographic punctuation).
	// -------------------------------------------------------------------------

	protected function _truncate(string $s, int $iWidth): string
	{
		$s = $this->_sanitize($s);
		return $iWidth <= 0 ? '' : substr($s, 0, $iWidth);
	}

	protected function _sanitize(string $s): string
	{
		$s = strtr($s, [
			"\xE2\x80\x93" => '-', // –
			"\xE2\x80\x94" => '-', // —
			"\xC2\xB0"     => 'deg', // ° (shouldn't reach here - WeatherFeedParser already strips it into bare numbers - but stay safe if a raw value slips through)
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
