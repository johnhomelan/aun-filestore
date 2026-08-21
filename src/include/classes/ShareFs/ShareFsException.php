<?php

namespace HomeLan\FileStore\ShareFs;

/**
 * A ShareFsHandler command failure that should be reported to the client with a specific
 * POSIX errno, rather than the generic EIO used for unexpected failures.
 */
class ShareFsException extends \Exception
{
    public function __construct(private readonly int $iErrno, string $sMessage)
    {
        parent::__construct($sMessage);
    }

    public function getErrno(): int
    {
        return $this->iErrno;
    }
}
