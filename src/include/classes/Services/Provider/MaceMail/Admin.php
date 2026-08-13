<?php

namespace HomeLan\FileStore\Services\Provider\MaceMail;

use HomeLan\FileStore\Services\Provider\AdminInterface;
use HomeLan\FileStore\Services\Provider\AdminEntity;
use HomeLan\FileStore\Services\ServiceDispatcher;
use HomeLan\FileStore\Services\Provider\MaceMail as MaceMailProvider;

class Admin implements AdminInterface
{
	private bool $bEnabled = true;

	public function __construct(private readonly MaceMailProvider $oProvider)
	{
	}

	public function getName(): string
	{
		return "MaceMail";
	}

	public function getDescription(): string
	{
		return "Electronic mail server for the 1985 MaceMail Econet client.\nUser identity and authentication come from the existing filestore user database — this page only provisions which mail slot number each filestore user answers to.";
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
		return $this->bEnabled ? "On-line" : "Disabled";
	}

	public function getEntityTypes(): array
	{
		return ['slots' => 'Mail Slots', 'online' => 'Currently Online'];
	}

	public function getEntityFields(string $sType): array
	{
		return match ($sType) {
			'slots'  => ['slot' => 'int', 'username' => 'string', 'online' => 'bool', 'last_used' => 'string', 'store_mask' => 'int'],
			'online' => ['username' => 'string', 'network' => 'int', 'station' => 'int'],
			default  => [],
		};
	}

	public function getEntities(string $sType): array
	{
		return match ($sType) {
			'slots'  => AdminEntity::createCollection($sType, $this->getEntityFields($sType), $this->oProvider->getRegisteredSlots(), fn($aRow) => $aRow['slot']),
			'online' => AdminEntity::createCollection($sType, $this->getEntityFields($sType), $this->oProvider->getOnlineMailUsers(), fn($aRow) => $aRow['username']),
			default  => [],
		};
	}

	public function getCommands(): array
	{
		return [
			['label' => 'Assign Mail Slot', 'url' => '/service/macemail/slots/assign'],
			['label' => 'Unassign Mail Slot', 'url' => '/service/macemail/slots/unassign'],
			['label' => 'Force Logoff', 'url' => '/service/macemail/logoff'],
			['label' => 'Send System Message', 'url' => '/service/macemail/broadcast'],
		];
	}
}
