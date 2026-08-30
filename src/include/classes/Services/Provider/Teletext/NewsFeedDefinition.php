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
 *
 * A feed that shares its channel with another teletext service (currently
 * just BBC, sharing channel 2 with Weather) lists that channel's other
 * destinations in $aChannelIndexEntries - entries shaped for
 * NewsPageComposer::composeChannelIndex(), e.g. `['page' => '600',
 * 'headline' => 'Weather']`. When non-empty, NewsImport writes this feed's
 * own story index one page later (101 instead of 100) and puts a
 * composeChannelIndex() hub page - listing this feed's own index alongside
 * every other $aChannelIndexEntries destination - on page 100 instead. Left
 * empty (Guardian, Sky - each alone on their own channel), NewsImport keeps
 * the original single-index-at-100 layout.
 *
 * $aIndexMosaicHeading (currently just BBC) swaps that feed's own index page
 * banner for a two-word mosaic heading - shaped for
 * NewsPageComposer::composeIndex()'s $aMosaicHeading, e.g. `['word1' =>
 * 'BBC', 'font1' => 'blocks', 'colour1' => NewsPageComposer::WHITE, 'word2'
 * => 'NEWS', 'font2' => 'title', 'colour2' => NewsPageComposer::RED]`. Left
 * null (Guardian, Sky), the index keeps the plain $sBannerText banner.
 */
final class NewsFeedDefinition
{
	/**
	 * @param array<string, string>                                                                                $aCategoryFeeds       category label => RSS feed URL
	 * @param list<array{page: string, headline: string, category?: string}>                                        $aChannelIndexEntries entries for NewsPageComposer::composeChannelIndex()
	 * @param array{word1: string, font1: string, colour1: int, word2: string, font2: string, colour2: int}|null    $aIndexMosaicHeading  heading for NewsPageComposer::composeIndex()'s $aMosaicHeading
	 */
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
		public readonly array $aChannelIndexEntries = [],
		public readonly ?array $aIndexMosaicHeading = null,
	) {
	}
}
