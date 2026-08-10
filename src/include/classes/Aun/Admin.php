<?php

/**
 * @author John Brown <john@home-lan.co.uk>
 * @package core
 */
namespace HomeLan\FileStore\Aun;

use HomeLan\FileStore\Encapsulation\EncapsulationAdminInterface;
use HomeLan\FileStore\Services\Provider\AdminEntity;

class Admin implements EncapsulationAdminInterface
{
    public function getId(): string
    {
        return 'aun';
    }

    public function getName(): string
    {
        return 'AUN (Econet over UDP/IP)';
    }

    public function getDescription(): string
    {
        return "Carries Econet traffic over standard UDP/IP networks.\n"
             . "Two mapping modes are supported:\n"
             . "  Host mapping — a specific IP address (optionally with UDP port) maps to one Econet network.station pair.\n"
             . "  Subnet mapping — an entire /24 subnet maps to an Econet network number; the last octet becomes the station number.";
    }

    public function getStatus(): string
    {
        $iHosts   = count(Map::getHostMappings());
        $iSubnets = count(Map::getSubnetMappings());
        return "{$iHosts} host " . ($iHosts === 1 ? 'mapping' : 'mappings')
             . ', '
             . "{$iSubnets} subnet " . ($iSubnets === 1 ? 'mapping' : 'mappings');
    }

    public function getEntityTypes(): array
    {
        return [
            'host'   => 'Host Mappings',
            'subnet' => 'Subnet Mappings',
        ];
    }

    public function getEntityFields(string $sType): array
    {
        return match ($sType) {
            'host'   => ['network' => 'int', 'station' => 'int', 'ip' => 'string'],
            'subnet' => ['network' => 'int', 'subnet' => 'string'],
            default  => [],
        };
    }

    public function getEntities(string $sType): array
    {
        return match ($sType) {
            'host' => AdminEntity::createCollection(
                $sType,
                $this->getEntityFields($sType),
                Map::getHostMappings(),
                null,
                'ip'
            ),
            'subnet' => AdminEntity::createCollection(
                $sType,
                $this->getEntityFields($sType),
                Map::getSubnetMappings(),
                null,
                'network'
            ),
            default => [],
        };
    }
}
