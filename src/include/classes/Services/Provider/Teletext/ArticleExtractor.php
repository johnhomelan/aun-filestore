<?php

namespace HomeLan\FileStore\Services\Provider\Teletext;

/**
 * Extracts a headline, published date, and an ordered list of body blocks
 * from a news article page's raw HTML, per an ArticleProfile describing
 * that source's markup conventions (see ArticleProfile and
 * NewsFeedDefinitions).
 *
 * Pure logic - no network access - unit tested directly against literal
 * HTML fixtures, the same way TeefaxTtiParser is tested against literal
 * .tti fixtures.
 *
 * Generalises what was originally BbcNewsArticleExtractor (BBC-only) once
 * the Guardian and Sky feeds were added - see the news-import plan for the
 * live inspection of all three sources' markup that shaped this.
 */
class ArticleExtractor
{
	/**
	 * @return array{headline: string, published: ?string, blocks: array<int, array{type: string, text: string}>}
	 */
	public function extract(string $sHtml, ArticleProfile $oProfile): array
	{
		$oPrevious = libxml_use_internal_errors(true);
		$oDoc = new \DOMDocument();
		try {
			$oDoc->loadHTML('<?xml encoding="utf-8">' . $sHtml);
		} finally {
			libxml_use_internal_errors($oPrevious);
		}

		$oXPath = new \DOMXPath($oDoc);

		$oContainer = $this->_findContainer($oXPath, $oProfile->aContainerXPaths);
		if ($oContainer === null) {
			return ['headline' => '', 'published' => null, 'blocks' => []];
		}

		$sHeadline = '';
		$oH1 = $this->_item($this->_query($oXPath, './/h1', $oContainer), 0);
		if ($oH1 !== null) {
			$sHeadline = trim($this->_inlineText($oH1));
		}

		$sPublished = $this->_published($oXPath, $oContainer, $oProfile);

		$aBlocks = [];
		$oNodes = $this->_query($oXPath, $this->_bodyXPath($oXPath, $oContainer, $oProfile), $oContainer);

		foreach ($oNodes as $oCandidate) {
			if (!($oCandidate instanceof \DOMElement)) {
				continue;
			}
			$oNode = $oCandidate;
			$sType = match ($oNode->nodeName) {
				'h2', 'h3'   => 'heading',
				'p'          => 'paragraph',
				'li'         => 'list-item',
				'blockquote' => 'quote',
				default      => 'paragraph',
			};

			$sText = trim($this->_inlineText($oNode));
			if ($sText === '') {
				continue;
			}
			$sLower = strtolower($sText);

			if ($sType === 'heading' && $this->_matches($sLower, $oProfile->aStopHeadingsExact, $oProfile->aStopHeadingPrefixes)) {
				break;
			}
			if ($sType === 'paragraph' && $this->_matches($sLower, $oProfile->aSkipParagraphsExact, $oProfile->aSkipParagraphPrefixes)) {
				continue;
			}

			$aBlocks[] = ['type' => $sType, 'text' => $sText];
		}

		return ['headline' => $sHeadline, 'published' => $sPublished, 'blocks' => $aBlocks];
	}

	/**
	 * The \DOMNode / \DOMElement / \DOMNodeList type hints are given in PHPDoc
	 * only, not natively: the TypePHP AOT compiler segfaults at call time when a
	 * PHP-Dom node object is passed through a parameter or return typed as one of
	 * those internal classes (\DOMXPath and \DOMDocument are fine). PHPDoc keeps
	 * PHPStan's view intact. See packaging/typephp/PORTING-REACT.md.
	 *
	 * @param array<int, string> $aXPaths
	 * @return \DOMNode|null
	 */
	protected function _findContainer(\DOMXPath $oXPath, array $aXPaths)
	{
		foreach ($aXPaths as $sXPath) {
			$oNode = $this->_item($this->_query($oXPath, $sXPath), 0);
			if ($oNode !== null) {
				return $oNode;
			}
		}
		return null;
	}

	/**
	 * @param \DOMNode $oContainer
	 */
	protected function _bodyXPath(\DOMXPath $oXPath, $oContainer, ArticleProfile $oProfile): string
	{
		$sParagraphXPath = $oProfile->sParagraphStrategy === 'class-substring'
			? ".//p[contains(@class,'" . $oProfile->sParagraphClassSubstring . "')]"
			: $this->_majorityClassXPath($oXPath, $oContainer, 'p');

		$sHeadingXPath = $oProfile->sHeadingStrategy === 'class-substring'
			? ".//h2[contains(@class,'" . $oProfile->sHeadingClassSubstring . "')] | .//h3[contains(@class,'" . $oProfile->sHeadingClassSubstring . "')]"
			: './/h2 | .//h3';

		return $sParagraphXPath . ' | ' . $sHeadingXPath
			. " | .//li[not(contains(@class,'MetadataStripItem')) and not(contains(@class,'LinkItem'))]"
			. ' | .//blockquote';
	}

	/**
	 * Dynamically detects "the body paragraph class" as whichever class
	 * value is most common among the given tag inside $oContainer, then
	 * returns an XPath matching only elements with exactly that class.
	 * Used for sources (Guardian, Sky) whose CSS-in-JS class names are
	 * hashed per-deploy and so have no stable semantic substring to match,
	 * unlike BBC's.
	 *
	 * @param \DOMNode $oContainer
	 */
	protected function _majorityClassXPath(\DOMXPath $oXPath, $oContainer, string $sTag): string
	{
		$aCounts = [];
		foreach ($this->_query($oXPath, './/' . $sTag, $oContainer) as $oNode) {
			if (!($oNode instanceof \DOMElement)) {
				continue;
			}
			$sClass = trim($oNode->getAttribute('class'));
			if ($sClass === '') {
				continue;
			}
			$aCounts[$sClass] = ($aCounts[$sClass] ?? 0) + 1;
		}
		if ($aCounts === []) {
			return './/' . $sTag . "[@class='']";
		}
		arsort($aCounts);
		$sMajorityClass = (string) array_key_first($aCounts);
		return './/' . $sTag . "[@class='" . $sMajorityClass . "']";
	}

	/**
	 * @param array<int, string> $aExact
	 * @param array<int, string> $aPrefixes
	 */
	protected function _matches(string $sLower, array $aExact, array $aPrefixes): bool
	{
		if (in_array($sLower, $aExact, true)) {
			return true;
		}
		foreach ($aPrefixes as $sPrefix) {
			if (str_starts_with($sLower, $sPrefix)) {
				return true;
			}
		}
		return false;
	}

	/**
	 * @param \DOMNode $oContainer
	 */
	protected function _published(\DOMXPath $oXPath, $oContainer, ArticleProfile $oProfile): ?string
	{
		if ($oProfile->sPublishedStrategy === 'time-element') {
			$oTime = $this->_item($this->_query($oXPath, './/time[@datetime]', $oContainer), 0);
			return $oTime !== null ? (trim($oTime->textContent) ?: null) : null;
		}

		$oMeta = $this->_item($this->_query($oXPath, "//meta[@property='" . $oProfile->sPublishedMetaProperty . "']/@content"), 0)
			?? $this->_item($this->_query($oXPath, "//meta[@name='" . $oProfile->sPublishedMetaProperty . "']/@content"), 0);
		if ($oMeta === null) {
			return null;
		}
		$sRaw = trim($oMeta->nodeValue ?? '');
		if ($sRaw === '') {
			return null;
		}
		try {
			return (new \DateTimeImmutable($sRaw))->format('j F Y, H:i');
		} catch (\Throwable) {
			return $sRaw;
		}
	}

	/**
	 * DOMXPath::query() is typed to also return false on an invalid
	 * expression; every call site here immediately treats the result as a
	 * real node list, so that's narrowed away in one place instead of
	 * repeating the check at each call site.
	 *
	 * @param \DOMNode|null $oContextNode
	 * @return \DOMNodeList<\DOMNameSpaceNode|\DOMNode>
	 */
	protected function _query(\DOMXPath $oXPath, string $sExpression, $oContextNode = null)
	{
		$oResult = $oContextNode !== null ? $oXPath->query($sExpression, $oContextNode) : $oXPath->query($sExpression);
		if ($oResult === false) {
			throw new \RuntimeException('Invalid XPath expression: ' . $sExpression);
		}
		return $oResult;
	}

	/**
	 * DOMNodeList::item() is typed to also return DOMNameSpaceNode, which
	 * only happens on a namespace:: axis query - never used here. Narrows
	 * back to plain DOMNode (DOMElement/DOMAttr/... all extend it).
	 *
	 * @param \DOMNodeList<\DOMNameSpaceNode|\DOMNode> $oList
	 * @return \DOMNode|null
	 */
	protected function _item($oList, int $iIndex)
	{
		$oNode = $oList->item($iIndex);
		return $oNode instanceof \DOMNode ? $oNode : null;
	}

	/**
	 * Flattens an element's inline content to plain text, preserving
	 * <strong>/<b> and <em>/<i> spans as private markers ("\x01".."\x02" for
	 * strong, "\x03".."\x04" for em) that NewsPageComposer turns into
	 * colour-change control codes. <a> is unwrapped to its visible text
	 * (href dropped); <br> forces a line break; everything else not
	 * recognised is still descended into so its own text is captured.
	 *
	 * @param \DOMNode $oNode
	 */
	protected function _inlineText($oNode): string
	{
		$sOut = '';
		foreach ($oNode->childNodes as $oChild) {
			if ($oChild instanceof \DOMText) {
				$sOut .= preg_replace('/\s+/', ' ', $oChild->wholeText);
				continue;
			}
			if (!($oChild instanceof \DOMElement)) {
				continue;
			}
			switch (strtolower($oChild->nodeName)) {
				case 'br':
					$sOut .= "\n";
					break;
				case 'strong':
				case 'b':
					$sOut .= "\x01" . $this->_inlineText($oChild) . "\x02";
					break;
				case 'em':
				case 'i':
					$sOut .= "\x03" . $this->_inlineText($oChild) . "\x04";
					break;
				case 'script':
				case 'style':
					break;
				default:
					$sOut .= $this->_inlineText($oChild);
					break;
			}
		}
		return $sOut;
	}
}
