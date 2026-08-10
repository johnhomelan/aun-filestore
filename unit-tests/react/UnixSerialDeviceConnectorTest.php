<?php

/*
 * @group unit-tests
 *
 * Tests for UnixSerialDeviceConnector — the ReactPHP ConnectorInterface
 * implementation that opens a Unix serial device as a non-blocking stream
 * and configures it via stty.
 */

include_once(__DIR__ . '/../../src/include/system.inc.php');

use PHPUnit\Framework\TestCase;
use React\EventLoop\Loop;
use React\Promise\PromiseInterface;
use HomeLan\FileStore\React\UnixSerialDeviceConnector;

class UnixSerialDeviceConnectorTest extends TestCase
{
    private UnixSerialDeviceConnector $connector;

    protected function setUp(): void
    {
        $this->connector = new UnixSerialDeviceConnector(Loop::get());
    }

    /**
     * Pull the rejection reason out of a promise.
     *
     * react/promise v3 calls rejection handlers synchronously for
     * immediately-rejected promises (Promise\reject()), so this works
     * without running the event loop.
     */
    private function rejectReason(PromiseInterface $promise): \Throwable
    {
        $reason = null;
        $promise->then(
            function () { $this->fail('Expected a rejected promise but got a resolved one'); },
            function (\Throwable $e) use (&$reason) { $reason = $e; }
        );
        $this->assertNotNull($reason, 'Promise did not reject synchronously');
        return $reason;
    }

    public function testRejectsNonFileScheme(): void
    {
        // Only 'file://' is supported; any other scheme must reject immediately.
        $reason = $this->rejectReason($this->connector->connect('unix:///dev/ttyUSB0'));
        $this->assertInstanceOf(\InvalidArgumentException::class, $reason);
    }

    public function testRejectsNonExistentDevice(): void
    {
        // A path that cannot be opened by fopen() must produce a RuntimeException
        // whose message identifies the device.
        $reason = $this->rejectReason($this->connector->connect('file:///dev/ttyUSB_does_not_exist_99'));
        $this->assertInstanceOf(\RuntimeException::class, $reason);
        $this->assertStringContainsString('Unable to open', $reason->getMessage());
    }

    public function testRejectsWhenSttyFails(): void
    {
        // A regular file (not a tty) opens fine but stty exits non-zero because
        // it cannot apply termios settings to a non-tty fd. connect() must catch
        // the resulting RuntimeException from initSerialPort() and return a
        // rejected promise rather than propagating the exception.
        $sTmpPath = tempnam(sys_get_temp_dir(), 'serial_conn_test_');
        $this->assertNotFalse($sTmpPath, 'Could not create temp file for test');

        try {
            $reason = $this->rejectReason($this->connector->connect('file://' . $sTmpPath));
            $this->assertInstanceOf(\RuntimeException::class, $reason);
            // The message comes from either proc_open failure (stty not found) or
            // stty itself exiting with an error — both indicate initSerialPort failed.
            // PHPUnit 8 compatible: both code paths include "stty" in the message.
            $this->assertStringContainsString('stty', $reason->getMessage());
        } finally {
            @unlink($sTmpPath);
        }
    }

    public function testFileSchemeWithoutPrefixIsAccepted(): void
    {
        // Paths without a scheme are treated as 'file://' — the connector must
        // not reject the promise for a missing-scheme reason; any rejection here
        // comes from fopen/stty, not from URI validation.
        $reason = $this->rejectReason($this->connector->connect('/dev/ttyUSB_does_not_exist_99'));
        // RuntimeException (open failure) is expected; InvalidArgumentException is not.
        $this->assertNotInstanceOf(\InvalidArgumentException::class, $reason);
        $this->assertInstanceOf(\RuntimeException::class, $reason);
    }
}
