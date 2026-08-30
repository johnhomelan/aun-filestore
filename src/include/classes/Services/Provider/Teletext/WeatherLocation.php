<?php

namespace HomeLan\FileStore\Services\Provider\Teletext;

/**
 * One UK city WeatherImport fetches a forecast for - see WeatherLocations
 * for the concrete list and WeatherImport (Command) for how it's consumed.
 */
final class WeatherLocation
{
	public function __construct(
		public readonly string $sKey,
		public readonly string $sLabel,
		public readonly string $sBbcLocationId,
	) {
	}
}
