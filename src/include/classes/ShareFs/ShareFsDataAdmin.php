<?php

namespace HomeLan\FileStore\ShareFs;

use HomeLan\FileStore\Encapsulation\EncapsulationAdminInterface;
use HomeLan\FileStore\Services\Provider\AdminEntity;
use HomeLan\FileStore\Vfs\Vfs;

class ShareFsDataAdmin implements EncapsulationAdminInterface
{
    public function getId(): string
    {
        return 'sharefsdata';
    }

    public function getName(): string
    {
        return 'ShareFS Data';
    }

    public function getDescription(): string
    {
        return "The ShareFS data protocol itself (UDP port 49171) - RFIND, ROPENIN, ROPENUP, "
             . "ROPENDIR, RCREATE, RCREATEDIR, RDELETE, RACCESS, RFREESPACE, RRENAME, RCLOSE, "
             . "RREAD, RWRITE, RREADDIR, RENSURE, RSETLENGTH, RSETINFO, RGETSEQPTR, RSETSEQPTR, "
             . "RZERO and RVERSION. Open handles are Vfs's own handle table, reused directly "
             . "rather than tracked again here.";
    }

    public function getStatus(): string
    {
        $iOpen = count(Vfs::getOpenHandles());
        return "{$iOpen} open " . ($iOpen === 1 ? 'handle' : 'handles');
    }

    public function getEntityTypes(): array
    {
        return ['handle' => 'Open Handles'];
    }

    public function getEntityFields(string $sType): array
    {
        return match ($sType) {
            'handle' => ['network' => 'int', 'station' => 'int', 'handle' => 'int', 'path' => 'string', 'type' => 'string'],
            default  => [],
        };
    }

    public function getEntities(string $sType): array
    {
        if ($sType !== 'handle') {
            return [];
        }

        return AdminEntity::createCollection($sType, $this->getEntityFields($sType), Vfs::getOpenHandles(), self::computeHandleId(...));
    }

    private static function _asString(mixed $mValue): string
    {
        return is_scalar($mValue) ? (string) $mValue : '';
    }

    /** @param array<string,mixed> $aRow */
    private static function computeHandleId(array $aRow): string
    {
        return self::_asString($aRow['network']) . '.' . self::_asString($aRow['station']) . '.' . self::_asString($aRow['handle']);
    }
}
