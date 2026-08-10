<?php

/**
 * @author John Brown <john@home-lan.co.uk>
 * @package core
 */
namespace HomeLan\FileStore\Piconet;

use HomeLan\FileStore\Encapsulation\EncapsulationAdminInterface;
use HomeLan\FileStore\Services\Provider\AdminEntity;

class Admin implements EncapsulationAdminInterface
{
    public function getId(): string
    {
        return 'piconet';
    }

    public function getName(): string
    {
        return 'Piconet (EconetUSB serial)';
    }

    public function getDescription(): string
    {
        return "Carries Econet traffic over a physical Econet network via an EconetUSB serial adapter.\n"
             . "The piconet map lists the Econet network numbers that are reachable through the attached hardware. "
             . "Up to 8 networks can be registered.";
    }

    public function getStatus(): string
    {
        $iNetworks = count(Map::getNetworks());
        return "{$iNetworks} " . ($iNetworks === 1 ? 'network' : 'networks') . ' registered';
    }

    public function getEntityTypes(): array
    {
        return ['network' => 'Registered Networks'];
    }

    public function getEntityFields(string $sType): array
    {
        return match ($sType) {
            'network' => ['network' => 'int'],
            default   => [],
        };
    }

    public function getEntities(string $sType): array
    {
        return match ($sType) {
            'network' => AdminEntity::createCollection(
                $sType,
                $this->getEntityFields($sType),
                Map::getNetworks(),
                null,
                'network'
            ),
            default => [],
        };
    }
}
