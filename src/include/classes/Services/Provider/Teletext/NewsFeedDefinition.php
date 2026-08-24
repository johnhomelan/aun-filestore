<?php

namespace HomeLan\FileStore\Services\Provider\Teletext;

/**
 * Everything needed to import one RSS news feed into its own teletext
 * channel - see NewsFeedDefinitions for the concrete BBC/Guardian/Sky
 * instances and NewsImport (Command) for how it's consumed.
 *
 * A source with more than one real-world category feed (currently just
 * BBC) lists them in $aCategoryFeeds, keyed by the category label to tag
 * their stories with (see NewsPageComposer's category-grouped index) - see
 * NewsImport::_fetchItems() for how that's merged/deduplicated across
 * multiple downloads. Left empty, a feed is downloaded from
 * $sDefaultSource/--source as a single ungrouped list, same as before.
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
		public readonly int $iHeadlineForeground,
		public readonly int $iHeadlineBackground,
		public readonly array $aCategoryFeeds = [],
	) {
	}
}
