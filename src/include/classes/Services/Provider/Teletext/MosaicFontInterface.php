<?php

namespace HomeLan\FileStore\Services\Provider\Teletext;

/**
 * A named mosaic lettering style - implementations render a word as G1
 * mosaic block characters, one teletext row string per group of 3
 * sub-pixel rows, so composers (WeatherPageComposer today) can pick a
 * style by name via MosaicFontRegistry instead of depending on one
 * concrete font directly.
 */
interface MosaicFontInterface
{
	/**
	 * @return list<string> one teletext row string per group of 3 sub-pixel rows
	 * @throws \InvalidArgumentException if $sWord contains a letter with no defined glyph
	 */
	public function renderWord(string $sWord, int $iColour): array;
}
