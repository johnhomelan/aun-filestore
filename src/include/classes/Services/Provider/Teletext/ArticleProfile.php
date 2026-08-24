<?php

namespace HomeLan\FileStore\Services\Provider\Teletext;

/**
 * Describes how to extract body content from one news source's article
 * page markup - see ArticleExtractor. Each RSS feed in NewsFeedDefinitions
 * carries its own profile, since BBC/Guardian/Sky's HTML structures differ
 * in ways that can't be captured by a single fixed set of selectors (BBC's
 * classes carry a stable semantic substring; Guardian's/Sky's hashed
 * classes don't, so those need dynamic majority-class detection instead -
 * see the bbcnews/news-import plan for the live inspection that established
 * this).
 */
final class ArticleProfile
{
	/**
	 * @param array<int, string> $aContainerXPaths XPath expressions tried in order to find the body-scope element; the first match wins.
	 * @param string $sParagraphStrategy 'class-substring' (match $sParagraphClassSubstring) or 'majority-class' (whichever class is most common among <p> in scope)
	 * @param string $sHeadingStrategy 'class-substring' (match $sHeadingClassSubstring) or 'any' (every h2/h3 in scope)
	 * @param array<int, string> $aStopHeadingsExact lowercase exact-match heading text that marks the end of genuine body content
	 * @param array<int, string> $aStopHeadingPrefixes lowercase prefixes with the same effect
	 * @param array<int, string> $aSkipParagraphsExact lowercase exact-match paragraph text that is boilerplate, not story text
	 * @param array<int, string> $aSkipParagraphPrefixes lowercase prefixes with the same effect
	 * @param string $sPublishedStrategy 'time-element' (first <time datetime> in scope) or 'meta-tag' (a <meta property=/name=$sPublishedMetaProperty> in <head>)
	 */
	public function __construct(
		public readonly array $aContainerXPaths,
		public readonly string $sParagraphStrategy,
		public readonly string $sParagraphClassSubstring,
		public readonly string $sHeadingStrategy,
		public readonly string $sHeadingClassSubstring,
		public readonly array $aStopHeadingsExact,
		public readonly array $aStopHeadingPrefixes,
		public readonly array $aSkipParagraphsExact,
		public readonly array $aSkipParagraphPrefixes,
		public readonly string $sPublishedStrategy,
		public readonly string $sPublishedMetaProperty = '',
	) {
	}
}
