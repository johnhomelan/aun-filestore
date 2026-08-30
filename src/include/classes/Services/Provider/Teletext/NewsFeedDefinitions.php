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
				iHeadlineForeground: NewsPageComposer::WHITE,
				iHeadlineBackground: NewsPageComposer::RED,
				// BBC publishes a separate RSS feed per news section (see
				// bbc.co.uk/news/10628494) - fetching each and tagging its
				// stories with the section they came from is what drives the
				// index page's category grouping (see NewsPageComposer and
				// NewsImport::_fetchItems()). Ordered specific-to-generic so
				// a story that appears in both a section feed and the
				// generic front page only keeps its specific section - the
				// generic "Top Stories" feed is listed last purely as a
				// catch-all for anything not yet in a section feed.
				aCategoryFeeds: [
					'UK' => 'https://feeds.bbci.co.uk/news/uk/rss.xml',
					'World' => 'https://feeds.bbci.co.uk/news/world/rss.xml',
					'Politics' => 'https://feeds.bbci.co.uk/news/politics/rss.xml',
					'Business' => 'https://feeds.bbci.co.uk/news/business/rss.xml',
					'Technology' => 'https://feeds.bbci.co.uk/news/technology/rss.xml',
					'Science & Environment' => 'https://feeds.bbci.co.uk/news/science_and_environment/rss.xml',
					'Health' => 'https://feeds.bbci.co.uk/news/health/rss.xml',
					'Entertainment & Arts' => 'https://feeds.bbci.co.uk/news/entertainment_and_arts/rss.xml',
					'Education & Family' => 'https://feeds.bbci.co.uk/news/education/rss.xml',
					'Top Stories' => 'https://feeds.bbci.co.uk/news/rss.xml',
				],
				// BBC shares channel 2 with Weather (see
				// config.inc.php's teletext_weather_channel /
				// teletext_weather_index_page) - this puts a channel-hub
				// page on 100 linking to both, pushing BBC's own story
				// index to 101 (see NewsImport). Guardian/Sky are alone on
				// their own channels, so they leave this empty and keep
				// their index on 100.
				aChannelIndexEntries: [
					['page' => '101', 'headline' => 'News'],
					['page' => '600', 'headline' => 'Weather'],
					['page' => '700', 'headline' => 'TV Guide'],
				],
				// Replaces the plain "BBC NEWS HEADLINES" double-height
				// banner on BBC's own story index (page 101 - see
				// $aChannelIndexEntries above) with a mosaic heading: "BBC"
				// in the bold "blocks" font, "NEWS" immediately after it in
				// the "title" font (TitleFont - reverse-measured from an
				// archived page whose own heading reads "NEWS", see its
				// docblock) in BBC's own red.
				aIndexMosaicHeading: [
					'word1' => 'BBC',
					'font1' => 'blocks',
					'colour1' => NewsPageComposer::WHITE,
					'word2' => 'NEWS',
					'font2' => 'title',
					'colour2' => NewsPageComposer::RED,
				],
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
				iHeadlineForeground: NewsPageComposer::WHITE,
				iHeadlineBackground: NewsPageComposer::BLUE,
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
				// Unlike the banner, a story headline's background must never
				// be black (it needs to read as a distinct coloured block on
				// the article page) - red-with-white keeps Sky's own red
				// identity while still being a filled, non-black block.
				iHeadlineForeground: NewsPageComposer::WHITE,
				iHeadlineBackground: NewsPageComposer::RED,
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
