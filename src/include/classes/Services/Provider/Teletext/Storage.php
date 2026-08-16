<?php

namespace HomeLan\FileStore\Services\Provider\Teletext;

/**
 * Native, file-based storage for the Teletext service provider.
 *
 * This is deliberately independent of the Vfs/Vfs\Plugin layer — Teletext
 * owns a directory tree of its own (configured via teletext_store_dir) and
 * talks to it directly with plain PHP filesystem calls.
 *
 * Unlike MaceMail's Storage, this is read-only: the wire protocol has no
 * operation that lets a client save a page, so populating pages (e.g. from
 * a real teletext receiver's capture, or by hand) is entirely outside this
 * server's own remit — the same division of responsibility PiEconetBridge's
 * disk-backed teletext server uses.
 *
 * Layout:
 *   {base}/{channel}/{page}.dat        — subpage 1 (the default/no-subpage-requested case)
 *   {base}/{channel}/{page}_{N}.dat    — subpage N (N > 1), a raw 1024-byte Mode 7 screen dump each
 *
 * A page with only one subpage needs just the plain `{page}.dat` file —
 * the `_{N}` suffix only appears once a page actually has more than one
 * subpage.
 *
 * Every method here is the boundary that provider unit tests mock out
 * (`Mockery::mock(Storage::class)`) so that Teletext's own tests never
 * touch a real filesystem.
 *
 * @package core
 */
class Storage
{
	public const PAGE_SIZE = 1024;

	public function __construct(protected readonly string $sBaseDir)
	{
	}

	/**
	 * Returns the raw 1024-byte contents of a page/subpage, or null if it
	 * doesn't exist. Subpage defaults to 1 (the plain `{page}.dat` file).
	 */
	public function getPage(string $sChannel, string $sPage, int $iSubpage = 1): ?string
	{
		$sPath = $this->_pagePath($sChannel, $sPage, $iSubpage);
		if (!$this->fileExists($sPath)) {
			return null;
		}
		$sData = $this->getFileContents($sPath);
		return $sData === false ? null : $sData;
	}

	public function pageExists(string $sChannel, string $sPage, int $iSubpage = 1): bool
	{
		return $this->fileExists($this->_pagePath($sChannel, $sPage, $iSubpage));
	}

	/**
	 * Returns every channel that has a directory under the store, sorted.
	 *
	 * @return array<int, string>
	 */
	public function getChannels(): array
	{
		if (!$this->isDir($this->sBaseDir)) {
			return [];
		}
		$aChannels = array_filter($this->scanDir($this->sBaseDir), fn(string $sEntry) => $sEntry !== '.' && $sEntry !== '..' && $this->isDir($this->sBaseDir . '/' . $sEntry));
		sort($aChannels);
		return $aChannels;
	}

	/**
	 * Returns every distinct page number stored under a channel (regardless
	 * of how many subpages each has), sorted.
	 *
	 * @return array<int, string>
	 */
	public function getPages(string $sChannel): array
	{
		$aPages = [];
		foreach ($this->_scanPageFiles($sChannel) as [$sPage, $iSubpage]) {
			$aPages[] = $sPage;
		}
		$aPages = array_values(array_unique($aPages));
		sort($aPages);
		return $aPages;
	}

	/**
	 * Returns every subpage number stored for a given page, sorted
	 * numerically. Empty if the page doesn't exist at all.
	 *
	 * @return array<int, int>
	 */
	public function getSubpages(string $sChannel, string $sPage): array
	{
		$aSubpages = [];
		foreach ($this->_scanPageFiles($sChannel) as [$sFilePage, $iSubpage]) {
			if ($sFilePage === $sPage) {
				$aSubpages[] = $iSubpage;
			}
		}
		sort($aSubpages);
		return $aSubpages;
	}

	protected function _pagePath(string $sChannel, string $sPage, int $iSubpage): string
	{
		$sSuffix = $iSubpage <= 1 ? '' : '_' . $iSubpage;
		return $this->sBaseDir . '/' . $sChannel . '/' . $sPage . $sSuffix . '.dat';
	}

	/**
	 * Scans a channel directory and returns [pageNumber, subpageNumber]
	 * pairs for every `.dat` file found.
	 *
	 * @return array<int, array{0: string, 1: int}>
	 */
	protected function _scanPageFiles(string $sChannel): array
	{
		$sDir = $this->sBaseDir . '/' . $sChannel;
		if (!$this->isDir($sDir)) {
			return [];
		}
		$aReturn = [];
		foreach ($this->scanDir($sDir) as $sEntry) {
			if (preg_match('/^([0-9]+)(?:_([0-9]+))?\.dat$/', $sEntry, $aMatches)) {
				$aReturn[] = [$aMatches[1], isset($aMatches[2]) ? (int) $aMatches[2] : 1];
			}
		}
		return $aReturn;
	}

	// -------------------------------------------------------------------------
	// Low-level filesystem access — the only methods that touch real disk
	// -------------------------------------------------------------------------

	protected function fileExists(string $sPath): bool
	{
		return file_exists($sPath);
	}

	protected function isDir(string $sPath): bool
	{
		return is_dir($sPath);
	}

	protected function getFileContents(string $sPath): string|false
	{
		return file_get_contents($sPath);
	}

	/**
	 * @return array<int, string>
	 */
	protected function scanDir(string $sPath): array
	{
		$aEntries = scandir($sPath);
		return $aEntries === false ? [] : array_values(array_diff($aEntries, ['.', '..']));
	}
}
