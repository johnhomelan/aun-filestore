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
    private LoopInterface $loop;

    public function __construct(?LoopInterface $loop = null)
    {
        $this->loop = $loop ?: Loop::get();
    }

    public function connect($path)
    {
        if (!str_contains($path, '://')) {
            $path = 'file://' . $path;
        } elseif (!str_starts_with($path, 'file://')) {
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
        // PHP has no native termios API. FFI provides one but requires the
        // php-ffi extension to be enabled. stty via proc_open is the only
        // remaining option; using the array form means no shell is spawned and
        // there is no injection risk regardless of the device path.
        //
        // The flags mirror what cfmakeraw + B115200 would configure via libc:
        //   raw    — disables input/output processing, canonical mode, and echo
        //   -echo  — belt-and-braces: suppresses echo of received bytes back to
        //            the sender (without this every Pico response is echoed back
        //            down the wire, corrupting the protocol)
        //   115200 — baud rate (virtual on USB CDC devices, set for completeness)
        //   cs8    — 8-bit characters
        //   clocal — ignore modem status lines (required for USB CDC / ttyACM)
        $proc = proc_open(
            ['stty', '-F', $devicePath, '115200', 'raw', '-echo', 'cs8', 'clocal'],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $aPipes
        );
        if ($proc === false) {
            throw new \RuntimeException(
                'Unable to launch stty to configure serial port "' . $devicePath . '"'
            );
        }
        fclose($aPipes[1]);
        $sErr = stream_get_contents($aPipes[2]);
        fclose($aPipes[2]);
        $iExit = proc_close($proc);
        if ($iExit !== 0) {
            throw new \RuntimeException(
                'stty failed to configure "' . $devicePath . '"' .
                ($sErr !== '' ? ': ' . trim($sErr) : '')
            );
        }
    }
}
