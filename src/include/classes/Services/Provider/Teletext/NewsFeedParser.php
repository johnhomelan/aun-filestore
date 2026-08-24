<?php

namespace HomeLan\FileStore\Services\Provider\Teletext;

/**
 * Parses an RSS 2.0 news feed (BBC/Guardian/Sky - see NewsFeedDefinitions)
 * into a flat list of story items.
 *
 * Pure logic - no network access - so it can be unit tested directly
 * against literal RSS fixture strings, the same way TeefaxTtiParser is
 * tested against literal .tti fixtures.
 *
 * Only `<item>`s whose `<link>` path matches the caller-supplied filter
 * pattern are returned - each source has RSS entries that don't carry an
 * extractable article body (BBC's `/news/videos/...`, live pages, ...), so
 * the pattern is what tells this apart per source; see
 * NewsFeedDefinitions for each feed's pattern.
 */
class NewsFeedParser
{
	/**
	 * @return array<int, array{title: string, link: string, pubDate: ?string, category: string}>
	 */
	public function parse(string $sXml, string $sLinkFilterPattern): array
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

		$aItems = [];
		$aSeenLinks = [];
		foreach ($oXml->xpath('//item') ?: [] as $oItem) {
			$sLink = trim((string) $oItem->link);
			if ($sLink === '' || preg_match($sLinkFilterPattern, $this->_path($sLink)) !== 1) {
				continue;
			}
			if (isset($aSeenLinks[$sLink])) {
				continue;
			}
			$aSeenLinks[$sLink] = true;

			$sTitle = trim((string) $oItem->title);
			if ($sTitle === '') {
				continue;
			}

			$sPubDate = trim((string) $oItem->pubDate);

			// RSS 2.0 allows multiple <category> elements per item; this
			// project only groups the index by a single category per story,
			// so the first non-empty one wins.
			$sCategory = '';
			foreach ($oItem->category as $oCategory) {
				$sCategory = trim((string) $oCategory);
				if ($sCategory !== '') {
					break;
				}
			}

			$aItems[] = [
				'title'    => $sTitle,
				'link'     => $sLink,
				'pubDate'  => $sPubDate === '' ? null : $sPubDate,
				'category' => $sCategory,
			];
		}

		return $aItems;
	}

	protected function _path(string $sUrl): string
	{
		return (string) (parse_url($sUrl, PHP_URL_PATH) ?? '');
	}
}
