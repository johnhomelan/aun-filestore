<?php

namespace HomeLan\FileStore\Services\Provider\Teletext;

/**
 * The single source of truth for every UK Freeview channel TvGuideImport
 * fetches a 2-day (today/tomorrow) listing for - consumed by TvGuideImport
 * (Command) and TvGuidePageComposer's index page.
 *
 * Unlike NewsFeedDefinitions (several distinct sources, one selected via
 * --feed), TV Guide has exactly one source (the user's own TVHeadend
 * instance) covering every channel listed here in a single run, the same
 * shape as WeatherLocations.
 *
 * $iLcn values are standard-ish UK Freeview logical channel numbers - a
 * starting point, not independently verified against any real TVHeadend
 * instance (this project has none to check against). Verify/adjust these
 * against your own instance's reported channel numbers before relying on
 * the import matching correctly - the same "starting list, easily edited"
 * status WeatherLocations' 8 cities had when that feature was built.
 */
final class TvGuideChannels
{
	/**
	 * @return array<string, TvGuideChannel>
	 */
	public static function all(): array
	{
		return [
			'bbc-one'   => new TvGuideChannel(sKey: 'bbc-one', sLabel: 'BBC One', iLcn: 1, aMosaicWords: ['BBC', 'ONE']),
			'bbc-two'   => new TvGuideChannel(sKey: 'bbc-two', sLabel: 'BBC Two', iLcn: 2, aMosaicWords: ['BBC', 'TWO']),
			'itv1'      => new TvGuideChannel(sKey: 'itv1', sLabel: 'ITV1', iLcn: 3, aMosaicWords: ['ITV']),
			'channel4'  => new TvGuideChannel(sKey: 'channel4', sLabel: 'Channel 4', iLcn: 4, aMosaicWords: ['CHANNEL', 'FOUR']),
			'channel5'  => new TvGuideChannel(sKey: 'channel5', sLabel: 'Channel 5', iLcn: 5, aMosaicWords: ['CHANNEL', 'FIVE']),
			'itv2'      => new TvGuideChannel(sKey: 'itv2', sLabel: 'ITV2', iLcn: 6, aMosaicWords: ['ITV', 'TWO']),
			'bbc-three' => new TvGuideChannel(sKey: 'bbc-three', sLabel: 'BBC Three', iLcn: 7, aMosaicWords: ['BBC', 'THREE']),
			'e4'        => new TvGuideChannel(sKey: 'e4', sLabel: 'E4', iLcn: 8, aMosaicWords: ['E', 'FOUR']),
			'bbc-four'  => new TvGuideChannel(sKey: 'bbc-four', sLabel: 'BBC Four', iLcn: 9, aMosaicWords: ['BBC', 'FOUR']),
			'more4'     => new TvGuideChannel(sKey: 'more4', sLabel: 'More4', iLcn: 10, aMosaicWords: ['MORE', 'FOUR']),
			'film4'     => new TvGuideChannel(sKey: 'film4', sLabel: 'Film4', iLcn: 11, aMosaicWords: ['FILM', 'FOUR']),
			'dave'      => new TvGuideChannel(sKey: 'dave', sLabel: 'Dave', iLcn: 12, aMosaicWords: ['DAVE']),
			'really'    => new TvGuideChannel(sKey: 'really', sLabel: 'Really', iLcn: 13, aMosaicWords: ['REALLY']),
			'drama'     => new TvGuideChannel(sKey: 'drama', sLabel: 'Drama', iLcn: 14, aMosaicWords: ['DRAMA']),
			'yesterday' => new TvGuideChannel(sKey: 'yesterday', sLabel: 'Yesterday', iLcn: 15, aMosaicWords: ['YESTERDAY']),
			'5usa'      => new TvGuideChannel(sKey: '5usa', sLabel: '5USA', iLcn: 16, aMosaicWords: ['FIVE', 'USA']),
		];
	}

	public static function get(string $sKey): ?TvGuideChannel
	{
		return self::all()[$sKey] ?? null;
	}

	/**
	 * @return array<int, string>
	 */
	public static function keys(): array
	{
		return array_keys(self::all());
	}
}
