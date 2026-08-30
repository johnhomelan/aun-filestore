<?php

namespace HomeLan\FileStore\Services\Provider\Teletext;

use HomeLan\FileStore\Services\Provider\AdminInterface;
use HomeLan\FileStore\Services\Provider\AdminEntity;
use HomeLan\FileStore\Services\ServiceDispatcher;
use HomeLan\FileStore\Services\Provider\Teletext as TeletextProvider;

class Admin implements AdminInterface
{
	private bool $bEnabled = true;

	public function __construct(private readonly TeletextProvider $oProvider)
	{
	}

	public function getName(): string
	{
		return "Teletext";
	}

	public function getDescription(): string
	{
		return "Econet teletext server (see docs/protocols/teletext.md).\nPages are served from a local directory of pre-saved 1K Mode 7 screen dumps, one directory per channel and one file per page — this server never receives or saves pages itself, populate the store directory directly.";
	}

	public function isDisabled(): bool
	{
		return !$this->bEnabled;
	}

	public function setDisabled(): void
	{
		ServiceDispatcher::create()->disableService($this->oProvider);
		$this->bEnabled = false;
	}

	public function setEnabled(): void
	{
		ServiceDispatcher::create()->enableService($this->oProvider);
		$this->bEnabled = true;
	}

	public function getStatus(): string
	{
		if (!$this->bEnabled) {
			return "Disabled";
		}
		return $this->oProvider->isServiceActive() ? "On-line" : "Suspended (toggled off by a client)";
	}

	/**
	 * @return array<string,string>
	*/
	public function getEntityTypes(): array
	{
		return ['channels' => 'Channels'];
	}

	/**
	 * @return array<string,string>
	*/
	public function getEntityFields(string $sType): array
	{
		return match ($sType) {
			'channels' => ['channel' => 'string', 'page_count' => 'int'],
			default    => [],
		};
	}

	/**
	 * @return array<int,AdminEntity>
	*/
	public function getEntities(string $sType): array
	{
		return match ($sType) {
			'channels' => AdminEntity::createCollection($sType, $this->getEntityFields($sType), $this->oProvider->getChannelSummaries(), fn(array $aRow): mixed => $aRow['channel']),
			default    => [],
		};
	}

	/**
	 * @return array<int,array{label:string,url:string}>
	*/
	public function getCommands(): array
	{
		$aCommands = [
			['label' => 'Browse Pages', 'url' => '/service/teletext/browse'],
			['label' => 'Refresh Teefax Now', 'url' => '/service/teletext/teefax-refresh'],
			['label' => 'Refresh Weather Now', 'url' => '/service/teletext/weather-refresh'],
			['label' => 'Refresh TV Guide Now', 'url' => '/service/teletext/tvguide-refresh'],
		];
		foreach (NewsFeedDefinitions::all() as $oFeed) {
			$aCommands[] = [
				'label' => 'Refresh ' . $oFeed->sLabel . ' Now',
				'url'   => '/service/teletext/news-refresh?feed=' . urlencode($oFeed->sKey),
			];
		}
		foreach (WebfaxSourceDefinitions::all() as $oService) {
			$aCommands[] = [
				'label' => 'Refresh ' . $oService->sLabel . ' Now',
				'url'   => '/service/teletext/webfax-refresh?service=' . urlencode($oService->sKey),
			];
		}
		return $aCommands;
	}
}
