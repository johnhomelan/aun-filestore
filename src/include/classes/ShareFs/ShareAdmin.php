<?php

namespace HomeLan\FileStore\ShareFs;

use HomeLan\FileStore\Encapsulation\EncapsulationAdminInterface;
use HomeLan\FileStore\Services\Provider\AdminEntity;

class ShareAdmin implements EncapsulationAdminInterface
{
    public function getId(): string
    {
        return 'shares';
    }

    public function getName(): string
    {
        return 'ShareFS Shares';
    }

    public function getDescription(): string
    {
        return "Shares are named entry points into the existing VFS tree, exposed to ShareFS "
             . "clients over the ShareFS data protocol (port 49171). Each carries independent "
             . "attributes: Protected shares require a successful Access+ share-password check "
             . "before use and are never advertised via Freeway; Hidden shares are mountable by "
             . "name but not advertised; Read-only shares reject write-type commands.";
    }

    public function getStatus(): string
    {
        $iTotal = count(ShareList::getShares());
        $iAdvertised = count(ShareList::getAdvertisedShares());
        return "{$iTotal} " . ($iTotal === 1 ? 'share' : 'shares') . " configured, {$iAdvertised} advertised";
    }

    public function getEntityTypes(): array
    {
        return ['share' => 'Shares'];
    }

    public function getEntityFields(string $sType): array
    {
        return match ($sType) {
            'share' => ['name' => 'string', 'path' => 'string', 'protected' => 'string', 'readOnly' => 'string', 'hidden' => 'string'],
            default => [],
        };
    }

    public function getEntities(string $sType): array
    {
        if ($sType !== 'share') {
            return [];
        }

        $aRows = array_map(
            static fn(Share $oShare): array => [
                'name'      => $oShare->getName(),
                'path'      => $oShare->getVfsPath(),
                'protected' => $oShare->isProtected() ? 'yes' : 'no',
                'readOnly'  => $oShare->isReadOnly() ? 'yes' : 'no',
                'hidden'    => $oShare->isHidden() ? 'yes' : 'no',
            ],
            ShareList::getShares()
        );

        return AdminEntity::createCollection($sType, $this->getEntityFields($sType), $aRows, null, 'name');
    }
}
