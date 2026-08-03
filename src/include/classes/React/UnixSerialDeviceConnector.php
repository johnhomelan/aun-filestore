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
final class UnixSerialDeviceConnector implements ConnectorInterface
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
                 22
            ));
        }

        $resource = @\fopen($path, "w+b");

        if (!$resource) {
            return Promise\reject(new \RuntimeException(
                'Unable to open unix device "' . $path ,
                1
            ));
        }

        $devicePath = \substr($path, 7); // strip 'file://'
        try {
            $this->initSerialPort($devicePath);
        } catch (\RuntimeException $e) {
            \fclose($resource);
            return Promise\reject($e);
        }

        $connection = new Connection($resource, $this->loop);

        return Promise\resolve($connection);
    }

    private function initSerialPort(string $devicePath): void
    {
        // Mirror the Node.js driver's SerialPort config: 115200 baud, raw 8N1, no echo.
        // 'raw' disables all kernel line-discipline processing so the text protocol
        // (CR/LF delimited commands) passes through unmodified.
        $cmd = 'stty -F ' . \escapeshellarg($devicePath) . ' 115200 raw cs8 -cstopb -parenb -echo 2>&1';
        \exec($cmd, $output, $returnCode);
        if ($returnCode !== 0) {
            throw new \RuntimeException(
                'Failed to configure serial port "' . $devicePath . '": ' . \implode(' ', $output)
            );
        }
    }
}
