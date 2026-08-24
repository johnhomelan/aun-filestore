<?php

namespace HomeLan\FileStore\Services\Provider\Teletext;

/**
 * The single source of truth for every RSS news feed this project imports
 * into teletext - one place to add a fourth feed later. Consumed by
 * NewsImport (Command), Teletext's housekeeping-driven refresh triggers,
 * Admin's "Refresh ... Now" buttons, and TeletextController's shared
 * refresh action.
 *
 * Config keys per feed follow `teletext_news_{sConfigPrefix}_channel` /
 * `_source` / `_refresh_interval` / `_max_stories` - see
 * src/include/config.inc.php for the defaults below.
 *
 * Masthead styling (title/banner text and colours) is a stylistic
 * approximation for each source - there's no verified archival reference
 * for a teletext service any of these outlets actually ran (see the
 * news-import plan). BBC's is unchanged from the original bbcnews-import
 * work; Guardian/Sky are new, chosen to be visually distinct from BBC's
 * red and from each other.
 *
 * Guardian's and Sky's ArticleProfiles use majority-class paragraph/heading
 * detection rather than a fixed class substring, because - unlike BBC's
 * stable `Paragraph`/`Heading` class-name suffixes - both sources' CSS-in-JS
 * classes are opaque hashes that change on every deploy (verified live for
 * Guardian; Sky's article markup could not be verified from this project's
 * development sandbox - see the plan - so its profile is best-effort and
 * needs validating wherever news.sky.com is actually reachable).
 */
final class NewsFeedDefinitions
{
	/**
	 * @return array<string, NewsFeedDefinition>
	 */
	public static function all(): array
	{
		return [
			'bbc' => new NewsFeedDefinition(
				sKey: 'bbc',
				sLabel: 'BBC News',
				sConfigPrefix: 'bbc',
				sDefaultChannel: '2',
				sDefaultSource: 'https://feeds.bbci.co.uk/news/rss.xml',
				iDefaultRefreshInterval: 1800,
				iDefaultMaxStories: 40,
				sLinkFilterPattern: '#/news/articles/#',
				oArticleProfile: new ArticleProfile(
					aContainerXPaths: ['//article'],
					sParagraphStrategy: 'class-substring',
					sParagraphClassSubstring: 'Paragraph',
					sHeadingStrategy: 'class-substring',
					sHeadingClassSubstring: 'Heading',
					aStopHeadingsExact: ['related topics', 'more on this story', 'related internet links'],
					aStopHeadingPrefixes: ['to play this video'],
					aSkipParagraphsExact: ['this video can not be played'],
					aSkipParagraphPrefixes: ['watch:'],
					sPublishedStrategy: 'time-element',
				),
				sMastheadTitle: 'BBC NEWS',
				sBannerText: 'BBC NEWS HEADLINES',
				iBannerForeground: NewsPageComposer::WHITE,
				iBannerBackground: NewsPageComposer::RED,
			),
			'guardian' => new NewsFeedDefinition(
				sKey: 'guardian',
				sLabel: 'The Guardian',
				sConfigPrefix: 'guardian',
				sDefaultChannel: '3',
				sDefaultSource: 'https://www.theguardian.com/uk/rss',
				iDefaultRefreshInterval: 1800,
				iDefaultMaxStories: 40,
				sLinkFilterPattern: '#/\d{4}/[a-z]{3}/\d{2}/#i',
				oArticleProfile: new ArticleProfile(
					aContainerXPaths: ['//article'],
					sParagraphStrategy: 'majority-class',
					sParagraphClassSubstring: '',
					sHeadingStrategy: 'any',
					sHeadingClassSubstring: '',
					aStopHeadingsExact: [],
					aStopHeadingPrefixes: [],
					aSkipParagraphsExact: [],
					aSkipParagraphPrefixes: [],
					sPublishedStrategy: 'meta-tag',
					sPublishedMetaProperty: 'article:published_time',
				),
				sMastheadTitle: 'GUARDIAN',
				sBannerText: 'THE GUARDIAN',
				iBannerForeground: NewsPageComposer::WHITE,
				iBannerBackground: NewsPageComposer::BLUE,
			),
			'sky' => new NewsFeedDefinition(
				sKey: 'sky',
				sLabel: 'Sky News',
				sConfigPrefix: 'sky',
				sDefaultChannel: '5',
				sDefaultSource: 'https://feeds.skynews.com/feeds/rss/home.xml',
				iDefaultRefreshInterval: 1800,
				iDefaultMaxStories: 40,
				sLinkFilterPattern: '#/story/#',
				oArticleProfile: new ArticleProfile(
					aContainerXPaths: ['//article', "//div[contains(@class,'article-body')]", "//div[@data-component='article-body']"],
					sParagraphStrategy: 'majority-class',
					sParagraphClassSubstring: '',
					sHeadingStrategy: 'any',
					sHeadingClassSubstring: '',
					aStopHeadingsExact: [],
					aStopHeadingPrefixes: [],
					aSkipParagraphsExact: [],
					aSkipParagraphPrefixes: [],
					sPublishedStrategy: 'time-element',
				),
				sMastheadTitle: 'SKY NEWS',
				sBannerText: 'SKY NEWS HEADLINES',
				// Black is the screen's own default background, so a
				// white-on-black block wouldn't read as a distinct banner
				// the way BBC's red and Guardian's blue blocks do - red
				// double-height text against the ambient black background
				// stays distinctive (and closer to Sky's actual branding)
				// without needing a filled colour block.
				iBannerForeground: NewsPageComposer::RED,
				iBannerBackground: NewsPageComposer::BLACK,
			),
		];
	}

	public static function get(string $sKey): ?NewsFeedDefinition
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
