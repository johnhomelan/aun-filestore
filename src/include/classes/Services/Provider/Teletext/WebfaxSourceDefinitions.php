<?php

namespace HomeLan\FileStore\Services\Provider\Teletext;

/**
 * The single source of truth for every Webfax teletext archive this
 * project imports - one place to add a third service later. Consumed by
 * WebfaxImport (Command), Teletext's housekeeping-driven refresh triggers,
 * Admin's "Refresh ... Now" buttons, and TeletextController's shared
 * refresh action.
 *
 * Webfax 1 and Webfax 2 are independent services (separate GitHub repos,
 * each a flat archive of `P{page}.tti` files on branch `main`) rather than
 * subpages of one feed, so - like NewsFeedDefinitions - each gets its own
 * key, channel and source. Unlike News, there's no page composition here:
 * both repos already contain complete MRG `.tti` pages in the same format
 * Teefax uses (verified against live fixtures from both repos), so
 * WebfaxImport reuses TeefaxTtiParser as-is rather than needing a parser of
 * its own.
 */
final class WebfaxSourceDefinitions
{
	/**
	 * @return array<string, WebfaxSourceDefinition>
	 */
	public static function all(): array
	{
		return [
			'webfax1' => new WebfaxSourceDefinition(
				sKey: 'webfax1',
				sLabel: 'Webfax 1',
				sConfigPrefix: 'webfax1',
				sDefaultChannel: '7',
				sDefaultSource: 'https://github.com/Webfax-Teletext/Webfax-Teletext/archive/refs/heads/main.tar.gz',
				iDefaultRefreshInterval: 86400,
			),
			'webfax2' => new WebfaxSourceDefinition(
				sKey: 'webfax2',
				sLabel: 'Webfax 2',
				sConfigPrefix: 'webfax2',
				sDefaultChannel: '8',
				sDefaultSource: 'https://github.com/Webfax-Teletext/Webfax2-Teletext/archive/refs/heads/main.tar.gz',
				iDefaultRefreshInterval: 86400,
			),
		];
	}

	public static function get(string $sKey): ?WebfaxSourceDefinition
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
