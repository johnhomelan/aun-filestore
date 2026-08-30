<?php

namespace HomeLan\FileStore\Services\Provider\Teletext;

/**
 * Parses one BBC Weather 3-day forecast RSS feed
 * (https://weather-broker-cdn.api.bbci.co.uk/en/forecast/rss/3day/{location-id})
 * into a flat list of day entries, in feed order (typically "Tonight" then
 * two full days).
 *
 * Pure logic - no network access - unit tested directly against literal
 * RSS fixture strings, the same way NewsFeedParser is tested.
 *
 * BBC packs each item's data two ways that both need parsing:
 *  - <title> is prose: "{Day}: {Condition}, Minimum Temperature: 14°C
 *    (57°F)[ Maximum Temperature: 22°C (72°F)]" - the trailing Maximum
 *    Temperature clause is absent on the first "Tonight" item, since
 *    there's no more daytime high left to report.
 *  - <description> is a comma-separated "Key: Value" list carrying the
 *    same temperatures plus wind/humidity/etc - parsed generically into a
 *    map and picked apart for just the fields WeatherPageComposer renders.
 */
class WeatherFeedParser
{
	/**
	 * @return array<int, array{day: string, condition: string, minC: ?string, maxC: ?string, windDir: ?string, windSpeed: ?string, humidity: ?string}>
	 */
	public function parse(string $sXml): array
	{
		$oPrevious = libxml_use_internal_errors(true);
		try {
			$oXml = simplexml_load_string($sXml);
		} finally {
			libxml_use_internal_errors($oPrevious);
		}
		if ($oXml === false) {
			return [];
		}

		$aDays = [];
		foreach ($oXml->xpath('//item') ?: [] as $oItem) {
			$sTitle = trim((string) $oItem->title);
			if ($sTitle === '' || preg_match('/^([^:]+):\s*(.+?),\s*Minimum Temperature:/', $sTitle, $aMatch) !== 1) {
				continue;
			}

			$aFields = $this->_parseDescription(trim((string) $oItem->description));
			$aDays[] = [
				'day'       => trim($aMatch[1]),
				'condition' => trim($aMatch[2]),
				'minC'      => $this->_celsius($aFields['Minimum Temperature'] ?? null),
				'maxC'      => $this->_celsius($aFields['Maximum Temperature'] ?? null),
				'windDir'   => $aFields['Wind Direction'] ?? null,
				'windSpeed' => $aFields['Wind Speed'] ?? null,
				'humidity'  => $aFields['Humidity'] ?? null,
			];
		}

		return $aDays;
	}

	/**
	 * @return array<string, string>
	 */
	protected function _parseDescription(string $sDescription): array
	{
		$aFields = [];
		foreach (explode(', ', $sDescription) as $sPart) {
			$aKeyValue = explode(': ', $sPart, 2);
			if (count($aKeyValue) === 2) {
				$aFields[trim($aKeyValue[0])] = trim($aKeyValue[1]);
			}
		}
		return $aFields;
	}

	protected function _celsius(?string $sValue): ?string
	{
		if ($sValue === null || preg_match('/^(-?\d+)\xC2\xB0C/', $sValue, $aMatch) !== 1) {
			return null;
		}
		return $aMatch[1];
	}
}
