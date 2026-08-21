<?php

namespace HomeLan\FileStore\RemoteSocket\Exceptions;

/**
 * Thrown by Client when the relay server rejects its shared secret (an auth_fail frame - see
 * docs/protocols/remote-socket.md). Callers should treat this as fatal.
 */
class AuthenticationFailedException extends \Exception
{

}
