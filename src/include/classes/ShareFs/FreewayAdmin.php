<?php

namespace HomeLan\FileStore\ShareFs;

use HomeLan\FileStore\Encapsulation\EncapsulationAdminInterface;
use HomeLan\FileStore\Services\Provider\AdminEntity;

class FreewayAdmin implements EncapsulationAdminInterface
{
    public function getId(): string
    {
        return 'freeway';
    }

    public function getName(): string
    {
        return 'Freeway Discovery';
    }

    public function getDescription(): string
    {
        return "Periodically re-broadcasts every advertised (non-Protected, non-Hidden) share "
             . "on UDP port 32770, unprompted - this server does not listen for or reply to "
             . "discovery requests on this port. Protected shares are announced separately, "
             . "only on a successful Access+ share-password match - see Access+.";
    }

    public function getStatus(): string
    {
        $iAdvertised = count(ShareList::getAdvertisedShares());
        return "{$iAdvertised} " . ($iAdvertised === 1 ? 'share' : 'shares') . ' advertised';
    }

    public function getEntityTypes(): array
    {
        return ['advertisement' => 'Advertised Shares'];
    }

    public function getEntityFields(string $sType): array
    {
        return match ($sType) {
            'advertisement' => ['share' => 'string', 'type' => 'string'],
            default         => [],
        };
    }

    public function getEntities(string $sType): array
    {
        if ($sType !== 'advertisement') {
            return [];
        }

        $aRows = array_map(
            static fn(Share $oShare): array => ['share' => $oShare->getName(), 'type' => 'Disc'],
            ShareList::getAdvertisedShares()
        );

        return AdminEntity::createCollection($sType, $this->getEntityFields($sType), $aRows, null, 'share');
    }
}
