<?php

namespace HomeLan\FileStore\Services\Provider\Teletext;

/**
 * One UK Freeview channel TvGuideImport writes a 2-day listing page for -
 * see TvGuideChannels for the concrete list and TvGuideImport (Command) for
 * how it's consumed.
 *
 * $iLcn is the Freeview logical channel number - TvGuideImport matches
 * TVHeadend's own reported channel number against this rather than any
 * channel *name*, since a TVHeadend instance's channel naming is entirely
 * up to how its owner configured it, but Freeview's LCN allocation is the
 * one thing every UK DVB-T receiver (including TVHeadend) agrees on.
 *
 * $aMosaicWords is a separate, alphabetic-only rendering of the channel's
 * name for its own listing page header (see TvGuidePageComposer -
 * ChonkFont has no digit glyphs, so e.g. "Channel 4" becomes ['CHANNEL',
 * 'FOUR'] here while $sLabel keeps the plain "Channel 4" text used
 * everywhere else, e.g. the index page's entry list).
 */
final class TvGuideChannel
{
	/**
	 * @param array<int, string> $aMosaicWords
	 */
	public function __construct(
		public readonly string $sKey,
		public readonly string $sLabel,
		public readonly int $iLcn,
		public readonly array $aMosaicWords,
	) {
	}
}
