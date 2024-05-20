<?php

namespace HomeLan\FileStore\React; 

use React\EventLoop\Loop;
use React\EventLoop\LoopInterface;
use React\Promise;
use React\Socket\ConnectorInterface;
use React\Socket\Connection;
use InvalidArgumentException;
use RuntimeException;

/**
 * Unix domain socket connector
 *
 * Unix domain sockets use atomic operations, so we can as well emulate
 * async behavior.
 */
final class UnixDeviceConnector implements ConnectorInterface
{
    private $loop;

    public function __construct(LoopInterface $loop = null)
    {
        $this->loop = $loop ?: Loop::get();
    }

    public function connect($path)
    {
        if (\strpos($path, '://') === false) {
            $path = 'file://' . $path;
        } elseif (\substr($path, 0, 7) !== 'file://') {
            return Promise\reject(new \InvalidArgumentException(
                'Given URI "' . $path . '" is invalid (EINVAL)',
                \defined('SOCKET_EINVAL') ? \SOCKET_EINVAL : (\defined('PCNTL_EINVAL') ? \PCNTL_EINVAL : 22)
            ));
        }

        $resource = @\fopen($path, "w+b");

        if (!$resource) {
            return Promise\reject(new \RuntimeException(
                'Unable to open unix device "' . $path ,
                1
            ));
        }
	//stream_set_blocking($resource, false);
        $connection = new Connection($resource, $this->loop);
        //$connection->unix = true;

        return Promise\resolve($connection);
    }
}
