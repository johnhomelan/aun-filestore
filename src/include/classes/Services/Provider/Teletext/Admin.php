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

	public function getEntityTypes(): array
	{
		return ['channels' => 'Channels'];
	}

	public function getEntityFields(string $sType): array
	{
		return match ($sType) {
			'channels' => ['channel' => 'string', 'page_count' => 'int'],
			default    => [],
		};
	}

	public function getEntities(string $sType): array
	{
		return match ($sType) {
			'channels' => AdminEntity::createCollection($sType, $this->getEntityFields($sType), $this->oProvider->getChannelSummaries(), fn($aRow) => $aRow['channel']),
			default    => [],
		};
	}

	public function getCommands(): array
	{
		return [
			['label' => 'Refresh Teefax Now', 'url' => '/service/teletext/teefax-refresh'],
		];
	}
}
