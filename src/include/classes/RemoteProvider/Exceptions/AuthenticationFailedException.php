<?php

namespace HomeLan\FileStore\RemoteProvider\Exceptions;

/**
 * Thrown by Client::handleFrame() when the relay server rejects our shared secret - see
 * docs/protocols/remote-provider.md#authentication-failure. A wrong secret won't fix itself on
 * retry, so callers should catch this around their event loop's run() and exit rather than
 * reconnecting.
 */
class AuthenticationFailedException extends \Exception
{
}
