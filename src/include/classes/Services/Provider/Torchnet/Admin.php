<?php

/**
 * @author John Brown <john@home-lan.co.uk>
 * @package core
 */
namespace HomeLan\FileStore\Services\Provider\Torchnet;

use HomeLan\FileStore\Services\Provider\AdminInterface;
use HomeLan\FileStore\Services\Provider\AdminEntity;
use HomeLan\FileStore\Services\ServiceDispatcher;
use HomeLan\FileStore\Services\Provider\Torchnet;

class Admin implements AdminInterface
{
    private bool $bEnabled = true;

    public function __construct(private readonly Torchnet $oProvider)
    {
    }

    public function getName(): string
    {
        return 'TorchNet File Server';
    }

    public function getDescription(): string
    {
        return "Provides CP/M file services for Torch Communicator workstations over the TorchNet protocol (Econet ports 0x90 and 0x91).\nTranslates between CP/M 8+3 filenames and Acorn filesystem paths.";
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
        return $this->bEnabled ? 'On-line' : 'Disabled';
    }

    public function getEntityTypes(): array
    {
        return [
            'station' => 'Connected Stations',
            'handle'  => 'Open File Handles',
        ];
    }

    public function getEntityFields(string $sType): array
    {
        return match ($sType) {
            'station' => ['network' => 'int', 'station' => 'int', 'open_handles' => 'int'],
            'handle'  => ['network' => 'int', 'station' => 'int', 'handle' => 'int', 'path' => 'string'],
            default   => [],
        };
    }

    public function getEntities(string $sType): array
    {
        return match ($sType) {
            'station' => AdminEntity::createCollection(
                $sType,
                $this->getEntityFields($sType),
                $this->oProvider->getConnectedStations(),
                fn($aRow) => $aRow['network'] . '_' . $aRow['station']
            ),
            'handle' => AdminEntity::createCollection(
                $sType,
                $this->getEntityFields($sType),
                $this->oProvider->getOpenFileHandles(),
                fn($aRow) => $aRow['network'] . '_' . $aRow['station'] . '_' . $aRow['handle']
            ),
            default => [],
        };
    }

    public function getCommands(): array
    {
        return [];
    }
}
