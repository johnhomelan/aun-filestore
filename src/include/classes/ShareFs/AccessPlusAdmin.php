<?php

namespace HomeLan\FileStore\ShareFs;

use HomeLan\FileStore\Encapsulation\EncapsulationAdminInterface;
use HomeLan\FileStore\Services\Provider\AdminEntity;

class AccessPlusAdmin implements EncapsulationAdminInterface
{
    public function getId(): string
    {
        return 'accessplus';
    }

    public function getName(): string
    {
        return 'Access+';
    }

    public function getDescription(): string
    {
        return "Authenticates clients against a Protected share's own password (UDP port "
             . "32771) - there is no per-user login. A client folds the share's password into "
             . "a PIN and sends it; a match records that client's IP as authenticated for that "
             . "share for a sliding 10-minute window.";
    }

    public function getStatus(): string
    {
        $iActive = count(ShareAuthTable::getEntries());
        return "{$iActive} active " . ($iActive === 1 ? 'authentication' : 'authentications');
    }

    public function getEntityTypes(): array
    {
        return ['authentication' => 'Active Authentications'];
    }

    public function getEntityFields(string $sType): array
    {
        return match ($sType) {
            'authentication' => ['ip' => 'string', 'share' => 'string', 'expires' => 'datetime'],
            default          => [],
        };
    }

    public function getEntities(string $sType): array
    {
        if ($sType !== 'authentication') {
            return [];
        }

        return AdminEntity::createCollection($sType, $this->getEntityFields($sType), ShareAuthTable::getEntries(), self::computeEntryId(...));
    }

    private static function _asString(mixed $mValue): string
    {
        return is_scalar($mValue) ? (string) $mValue : '';
    }

    /** @param array<string,mixed> $aRow */
    private static function computeEntryId(array $aRow): string
    {
        return self::_asString($aRow['ip']) . '|' . self::_asString($aRow['share']);
    }
}
