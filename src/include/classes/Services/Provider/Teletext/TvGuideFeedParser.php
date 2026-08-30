<?php

namespace HomeLan\FileStore\Services\Provider\Teletext;

/**
 * Parses a TVHeadend `/api/epg/events/grid` JSON response
 * (`{"entries": [...], "totalCount": N}`) into a flat list of EPG events,
 * then groups that list against the fixed TvGuideChannels list, split into
 * "today"/"tomorrow" buckets per channel.
 *
 * Pure logic - no network access - unit tested directly against literal
 * JSON fixture strings, the same way WeatherFeedParser is tested against
 * literal RSS fixtures.
 *
 * Field names (`channelNumber`, `start`, `stop`, `title`) match TVHeadend's
 * documented HTTP API shape, but there is no live instance to confirm this
 * against from this project - verify against a real response (see
 * TvGuideImport's `--dry-run`) and adjust here if a particular TVHeadend
 * version reports these differently.
 */
class TvGuideFeedParser
{
	/**
	 * @return array<int, array{lcn: int, start: int, stop: int, title: string}>
	 */
	public function parse(string $sJson): array
	{
		$mDecoded = json_decode($sJson, true);
		if (!is_array($mDecoded) || !isset($mDecoded['entries']) || !is_array($mDecoded['entries'])) {
			return [];
		}

		$aEvents = [];
		foreach ($mDecoded['entries'] as $aEntry) {
			if (!is_array($aEntry)) {
				continue;
			}
			$mChannelNumber = $aEntry['channelNumber'] ?? null;
			$mStart = $aEntry['start'] ?? null;
			$mStop = $aEntry['stop'] ?? null;
			$mTitle = $aEntry['title'] ?? null;
			if (!is_numeric($mChannelNumber) || !is_numeric($mStart) || !is_numeric($mStop) || !is_string($mTitle) || $mTitle === '') {
				continue;
			}

			// TVHeadend can report a channel number with a decimal
			// sub-channel suffix (e.g. "1.0") - floor() to the whole LCN,
			// which is what TvGuideChannels::$iLcn matches against.
			$aEvents[] = [
				'lcn'   => (int) floor((float) $mChannelNumber),
				'start' => (int) $mStart,
				'stop'  => (int) $mStop,
				'title' => $mTitle,
			];
		}
		return $aEvents;
	}

	/**
	 * Buckets a flat event list into "today"/"tomorrow" (relative to
	 * $oToday's own date, local midnight to local midnight) per channel in
	 * $aChannels - an event outside that 48-hour window is dropped, and
	 * every channel gets an entry (with possibly-empty buckets) even if no
	 * event matched its LCN, so the caller doesn't need to special-case a
	 * channel with nothing on.
	 *
	 * @param array<int, array{lcn: int, start: int, stop: int, title: string}> $aEvents
	 * @param array<string, TvGuideChannel> $aChannels
	 * @return array<string, array{today: array<int, array{lcn: int, start: int, stop: int, title: string}>, tomorrow: array<int, array{lcn: int, start: int, stop: int, title: string}>}>
	 */
	public function groupByChannel(array $aEvents, array $aChannels, \DateTimeImmutable $oToday): array
	{
		$iTodayStart = $oToday->setTime(0, 0, 0)->getTimestamp();
		$iTomorrowStart = $iTodayStart + 86400;
		$iDayAfterStart = $iTodayStart + 172800;

		$aGrouped = [];
		foreach ($aChannels as $sKey => $oChannel) {
			$aToday = [];
			$aTomorrow = [];
			foreach ($aEvents as $aEvent) {
				if ($aEvent['lcn'] !== $oChannel->iLcn) {
					continue;
				}
				if ($aEvent['start'] < $iTodayStart || $aEvent['start'] >= $iDayAfterStart) {
					continue;
				}
				if ($aEvent['start'] < $iTomorrowStart) {
					$aToday[] = $aEvent;
				} else {
					$aTomorrow[] = $aEvent;
				}
			}
			usort($aToday, fn (array $a, array $b): int => $a['start'] <=> $b['start']);
			usort($aTomorrow, fn (array $a, array $b): int => $a['start'] <=> $b['start']);
			$aGrouped[$sKey] = ['today' => $aToday, 'tomorrow' => $aTomorrow];
		}

		return $aGrouped;
	}
}
