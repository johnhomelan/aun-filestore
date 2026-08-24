<?php

namespace HomeLan\FileStore\Services\Provider\ProxyProvider;

use HomeLan\FileStore\Services\Provider\AdminInterface;
use HomeLan\FileStore\Services\Provider\AdminEntity;
use HomeLan\FileStore\Services\ServiceDispatcher;
use HomeLan\FileStore\Services\Provider\ProxyProvider;

/**
 * Lists, per reserved port (remote_provider_ports), whether a remote provider process is
 * currently connected and registered for it - see docs/protocols/remote-provider.md.
 */
class Admin implements AdminInterface
{
    private bool $bEnabled = true;

    public function __construct(private readonly ProxyProvider $oProvider)
    {
    }

    public function getName(): string
    {
        return 'Remote Provider Relay';
    }

    public function getDescription(): string
    {
        return 'Relays Econet packets on reserved ports to provider processes connected over the Remote Provider Protocol.';
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
        return ['ports' => 'Reserved Ports'];
    }

    public function getEntityFields(string $sType): array
    {
        return match ($sType) {
            'ports' => ['port' => 'int', 'status' => 'string'],
            default => [],
        };
    }

    public function getEntities(string $sType): array
    {
        if ($sType !== 'ports') {
            return [];
        }
        $aRegistered = $this->oProvider->getRegisteredPorts();
        $aRows = array_map(
            static fn (int $iPort): array => [
                'port'   => $iPort,
                'status' => in_array($iPort, $aRegistered, true) ? 'Remote provider connected' : 'No remote provider registered',
            ],
            $this->oProvider->getServicePorts(),
        );
        return AdminEntity::createCollection($sType, $this->getEntityFields($sType), $aRows, null, 'port');
    }

    public function getCommands(): array
    {
        return [];
    }
}
