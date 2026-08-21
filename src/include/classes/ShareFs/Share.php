<?php

namespace HomeLan\FileStore\ShareFs;

/**
 * A single ShareFS share: a name clients mount, and the point in the existing VFS tree it
 * exposes.
 *
 * Attribute names and behaviour verified against a real, working implementation
 * (andrewtimmins/riscos-access-server, src/config.h's SFS_ATTR_* flags): shares carry
 * independent boolean attributes, not a single mutually-exclusive state.
 *
 * `readonly` and `hidden` are parsed but never actually enforced by that reference server
 * (grep its source: SFS_ATTR_READONLY and SFS_ATTR_HIDDEN are set from config and never
 * checked again anywhere). This implementation enforces both for real - `hidden` suppresses
 * Freeway broadcast, `readonly` rejects write-type ShareFsHandler commands - since a config
 * option that silently does nothing would be worse than not having it. `protected` is the one
 * attribute the reference server does act on (skips it from the general broadcast, gates it
 * behind a successful Access+ share-password check), and is matched here exactly.
 */
class Share
{
    public function __construct(
        private readonly string $sName,
        private readonly string $sVfsPath,
        private readonly bool $bProtected = false,
        private readonly bool $bReadOnly = false,
        private readonly bool $bHidden = false,
        private readonly string $sPassword = '',
    ) {
    }

    public function getName(): string
    {
        return $this->sName;
    }

    public function getVfsPath(): string
    {
        return $this->sVfsPath;
    }

    public function isProtected(): bool
    {
        return $this->bProtected;
    }

    public function isReadOnly(): bool
    {
        return $this->bReadOnly;
    }

    public function isHidden(): bool
    {
        return $this->bHidden;
    }

    public function getPassword(): string
    {
        return $this->sPassword;
    }

    /** Whether this share should appear in Freeway ADD broadcasts. */
    public function isAdvertised(): bool
    {
        return !$this->bProtected && !$this->bHidden;
    }
}
