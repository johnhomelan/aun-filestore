<?php

namespace HomeLan\FileStore\Services\Provider\Teletext;

/**
 * Looks up a MosaicFontInterface implementation by name - "blocks"
 * (BlocksFont), "squat" (SquatFont), "title" (TitleFont) and "chonk"
 * (ChonkFont) today - without composers needing to know the concrete
 * class. Built eagerly in the constructor rather than config-driven,
 * since the set of fonts is fixed in code, not something a deployment
 * configures - see PrinterRegistry for the closest existing name ->
 * instance registry in this codebase.
 */
class MosaicFontRegistry
{
	/** @var array<string, MosaicFontInterface> keyed by font name */
	private array $aFonts = [];

	public function __construct()
	{
		$this->aFonts['blocks'] = new BlocksFont();
		$this->aFonts['squat'] = new SquatFont();
		$this->aFonts['title'] = new TitleFont();
		$this->aFonts['chonk'] = new ChonkFont();
	}

	public function getByName(string $sName): ?MosaicFontInterface
	{
		return $this->aFonts[$sName] ?? null;
	}
}
