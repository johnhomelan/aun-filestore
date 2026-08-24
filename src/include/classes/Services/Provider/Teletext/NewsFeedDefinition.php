<?php

namespace HomeLan\FileStore\Services\Provider\Teletext;

/**
 * Everything needed to import one RSS news feed into its own teletext
 * channel - see NewsFeedDefinitions for the concrete BBC/Guardian/Sky
 * instances and NewsImport (Command) for how it's consumed.
 */
final class NewsFeedDefinition
{
	public function __construct(
		public readonly string $sKey,
		public readonly string $sLabel,
		public readonly string $sConfigPrefix,
		public readonly string $sDefaultChannel,
		public readonly string $sDefaultSource,
		public readonly int $iDefaultRefreshInterval,
		public readonly int $iDefaultMaxStories,
		public readonly string $sLinkFilterPattern,
		public readonly ArticleProfile $oArticleProfile,
		public readonly string $sMastheadTitle,
		public readonly string $sBannerText,
		public readonly int $iBannerForeground,
		public readonly int $iBannerBackground,
	) {
	}
}
