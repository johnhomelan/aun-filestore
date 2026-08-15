<?php

/**
 * @author John Brown <john@home-lan.co.uk>
 * @package core
 */
namespace HomeLan\FileStore\RemoteBridge;

use HomeLan\FileStore\Encapsulation\EncapsulationAdminInterface;
use HomeLan\FileStore\Services\Provider\AdminEntity;

class Admin implements EncapsulationAdminInterface
{
    private static function _asString(mixed $mValue): string
    {
        return is_scalar($mValue) ? (string) $mValue : '';
    }

    public function getId(): string
    {
        return 'remotebridge';
    }

    public function getName(): string
    {
        return 'Remote Bridge';
    }

    public function getDescription(): string
    {
        return "Tunnels Econet traffic between two aun-filestore instances over TCP.\n"
             . "Each side can be configured as a server (listening for incoming connections) "
             . "or a client (connecting to a remote server). Both sides exchange network "
             . "reachability information after authentication so that the routing tables "
             . "on each end are kept up to date.";
    }

    public function getStatus(): string
    {
        $iLive = count(Map::getKnownNetworks());
        return "{$iLive} " . ($iLive === 1 ? 'network' : 'networks') . ' reachable via live connections';
    }

    public function getEntityTypes(): array
    {
        return [
            'connection' => 'Live Connections',
            'server'     => 'Server Entries',
            'client'     => 'Client Entries',
        ];
    }

    public function getEntityFields(string $sType): array
    {
        return match ($sType) {
            'connection' => ['network' => 'int'],
            'server'     => ['port' => 'int', 'networks' => 'string'],
            'client'     => ['host' => 'string', 'port' => 'int', 'networks' => 'string'],
            default      => [],
        };
    }

    public function getEntities(string $sType): array
    {
        switch ($sType) {
            case 'connection':
                $aRows = array_map(
                    fn(int $iNet): array => ['network' => $iNet],
                    Map::getKnownNetworks()
                );
                return AdminEntity::createCollection($sType, $this->getEntityFields($sType), $aRows, null, 'network');

            case 'server':
                $aRows = array_map(
                    fn(array $aEntry): array => [
                        'port'     => $aEntry['port'],
                        'networks' => implode(', ', $aEntry['networks']),
                    ],
                    Map::getServerEntries()
                );
                return AdminEntity::createCollection(
                    $sType,
                    $this->getEntityFields($sType),
                    $aRows,
                    fn(array $aRow): string => self::_asString($aRow['port'])
                );

            case 'client':
                $aRows = array_map(
                    fn(array $aEntry): array => [
                        'host'     => $aEntry['host'],
                        'port'     => $aEntry['port'],
                        'networks' => implode(', ', $aEntry['networks']),
                    ],
                    Map::getClientEntries()
                );
                return AdminEntity::createCollection(
                    $sType,
                    $this->getEntityFields($sType),
                    $aRows,
                    fn(array $aRow): string => self::_asString($aRow['host']) . ':' . self::_asString($aRow['port'])
                );
        }
        return [];
    }
}
