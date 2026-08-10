<?php

/**
 * @author John Brown <john@home-lan.co.uk>
 * @package core
 */
namespace HomeLan\FileStore\Services\Provider\Bridge;

use HomeLan\FileStore\Services\Provider\AdminInterface;
use HomeLan\FileStore\Services\Provider\AdminEntity;
use HomeLan\FileStore\Services\ServiceDispatcher;
use HomeLan\FileStore\Services\Provider\Bridge;

class Admin implements AdminInterface
{
    private bool $bEnabled = true;

    public function __construct(private readonly Bridge $oProvider)
    {
    }

    public function getName(): string
    {
        return 'Econet Bridge';
    }

    public function getDescription(): string
    {
        return "Implements the Econet bridge protocol (EC_BR_QUERY / EC_BR_NETKNOWN).\n"
             . "Learns remote networks from peer bridges and answers station queries about network reachability.";
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
            'remote' => 'Remote Networks',
            'local'  => 'Local Networks',
        ];
    }

    public function getEntityFields(string $sType): array
    {
        return match ($sType) {
            'remote' => ['network' => 'int', 'via' => 'string'],
            'local'  => ['network' => 'int', 'via' => 'string'],
            default  => [],
        };
    }

    public function getEntities(string $sType): array
    {
        return match ($sType) {
            'remote' => AdminEntity::createCollection(
                $sType,
                $this->getEntityFields($sType),
                $this->oProvider->getRemoteNetworks(),
                null,
                'network'
            ),
            'local' => AdminEntity::createCollection(
                $sType,
                $this->getEntityFields($sType),
                $this->oProvider->getLocalKnownNetworks(),
                null,
                'network'
            ),
            default => [],
        };
    }

    public function getCommands(): array
    {
        return [];
    }
}
