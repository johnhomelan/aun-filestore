<?php

/**
 * @author John Brown <john@home-lan.co.uk>
 * @package core
 */
namespace HomeLan\FileStore\WebSocket;

use HomeLan\FileStore\Encapsulation\EncapsulationAdminInterface;
use HomeLan\FileStore\Services\Provider\AdminEntity;

class Admin implements EncapsulationAdminInterface
{
    public function getId(): string
    {
        return 'websocket';
    }

    public function getName(): string
    {
        return 'WebSocket (Browser clients)';
    }

    public function getDescription(): string
    {
        return "Carries Econet traffic over WebSocket connections from browser-based emulators.\n"
             . "Each connecting client is dynamically allocated a unique Econet network.station address "
             . "from one of the configured dynamic network ranges.";
    }

    public function getStatus(): string
    {
        $iClients = count(Map::getConnectedClients());
        $iRanges  = count(Map::getDynamicNetworkRanges());
        return "{$iClients} " . ($iClients === 1 ? 'client' : 'clients') . ' connected'
             . ', '
             . "{$iRanges} dynamic " . ($iRanges === 1 ? 'range' : 'ranges') . ' configured';
    }

    public function getEntityTypes(): array
    {
        return [
            'connection' => 'Connected Clients',
            'range'      => 'Dynamic Network Ranges',
        ];
    }

    public function getEntityFields(string $sType): array
    {
        return match ($sType) {
            'connection' => ['network' => 'int', 'station' => 'int'],
            'range'      => ['network' => 'int'],
            default      => [],
        };
    }

    public function getEntities(string $sType): array
    {
        return match ($sType) {
            'connection' => AdminEntity::createCollection(
                $sType,
                $this->getEntityFields($sType),
                Map::getConnectedClients(),
                fn($aRow) => $aRow['network'] . '.' . $aRow['station']
            ),
            'range' => AdminEntity::createCollection(
                $sType,
                $this->getEntityFields($sType),
                Map::getDynamicNetworkRanges(),
                null,
                'network'
            ),
            default => [],
        };
    }
}
