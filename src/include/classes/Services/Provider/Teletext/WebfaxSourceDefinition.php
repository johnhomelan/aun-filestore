<?php

namespace HomeLan\FileStore\Services\Provider\Teletext;

/**
 * Everything needed to import one Webfax teletext archive into its own
 * channel - see WebfaxSourceDefinitions for the concrete Webfax 1/Webfax 2
 * instances and WebfaxImport (Command) for how it's consumed.
 *
 * Config keys per source follow `teletext_webfax_{sConfigPrefix}_channel` /
 * `_source` / `_refresh_interval` - see src/include/config.inc.php for the
 * defaults below.
 */
final class WebfaxSourceDefinition
{
	public function __construct(
		public readonly string $sKey,
		public readonly string $sLabel,
		public readonly string $sConfigPrefix,
		public readonly string $sDefaultChannel,
		public readonly string $sDefaultSource,
		public readonly int $iDefaultRefreshInterval,
	) {
	}
}
