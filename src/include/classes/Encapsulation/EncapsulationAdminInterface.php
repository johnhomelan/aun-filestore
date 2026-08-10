<?php

/**
 * @author John Brown <john@home-lan.co.uk>
 * @package core
 */
namespace HomeLan\FileStore\Encapsulation;

use HomeLan\FileStore\Services\Provider\AdminEntity;

/**
 * Interface implemented by every encapsulation admin class.
 *
 * Mirrors the AdminInterface used by service providers, but without
 * enable/disable operations — encapsulation types are controlled by
 * hardware and configuration, not runtime toggles.
 */
interface EncapsulationAdminInterface
{
    /** Short machine-safe key used in URLs, e.g. 'aun', 'websocket'. */
    public function getId(): string;

    public function getName(): string;

    public function getDescription(): string;

    /**
     * Human-readable status line, e.g. "3 host mappings, 1 subnet mapping".
     */
    public function getStatus(): string;

    /**
     * Returns ['typeKey' => 'Human Label', ...] for each entity type exposed.
     *
     * @return array<string, string>
     */
    public function getEntityTypes(): array;

    /**
     * Returns ['fieldName' => 'fieldType', ...] for the given entity type.
     *
     * @return array<string, string>
     */
    public function getEntityFields(string $sType): array;

    /**
     * Returns an array of AdminEntity objects for the given entity type.
     *
     * @return AdminEntity[]
     */
    public function getEntities(string $sType): array;
}
