<?php

namespace HomeLan\FileStore\Services\Provider\Teletext;

/**
 * The single source of truth for every UK city WeatherImport fetches a
 * forecast for - the classic Ceefax weather-page town list. Consumed by
 * WeatherImport (Command) and WeatherPageComposer's index page.
 *
 * Unlike NewsFeedDefinitions (several distinct sources, one selected via
 * --feed), weather has exactly one source - the BBC's own forecast API -
 * so every location here is fetched in a single WeatherImport run rather
 * than one being chosen per invocation. Each location's BBC location ID
 * was confirmed live against
 * https://weather-broker-cdn.api.bbci.co.uk/en/forecast/rss/3day/{id}
 * while building this feature.
 */
final class WeatherLocations
{
	/**
	 * @return array<string, WeatherLocation>
	 */
	public static function all(): array
	{
		return [
			'london' => new WeatherLocation(sKey: 'london', sLabel: 'London', sBbcLocationId: '2643743'),
			'birmingham' => new WeatherLocation(sKey: 'birmingham', sLabel: 'Birmingham', sBbcLocationId: '2655603'),
			'manchester' => new WeatherLocation(sKey: 'manchester', sLabel: 'Manchester', sBbcLocationId: '2643123'),
			'glasgow' => new WeatherLocation(sKey: 'glasgow', sLabel: 'Glasgow', sBbcLocationId: '2648579'),
			'cardiff' => new WeatherLocation(sKey: 'cardiff', sLabel: 'Cardiff', sBbcLocationId: '2653822'),
			'belfast' => new WeatherLocation(sKey: 'belfast', sLabel: 'Belfast', sBbcLocationId: '2655984'),
			'edinburgh' => new WeatherLocation(sKey: 'edinburgh', sLabel: 'Edinburgh', sBbcLocationId: '2650225'),
			'newcastle' => new WeatherLocation(sKey: 'newcastle', sLabel: 'Newcastle', sBbcLocationId: '2641673'),
		];
	}

	public static function get(string $sKey): ?WeatherLocation
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
