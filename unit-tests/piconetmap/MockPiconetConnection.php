<?php

/**
 * Mock React\Socket\ConnectionInterface for Piconet Handler unit tests.
 *
 * Unlike a standard PHPUnit mock, this class exposes a real php://temp $stream
 * property so that the Handler's fwrite($conn->stream, ...) calls are captured
 * and can be asserted.  write() calls (the React interface method used in
 * onClose()) are collected separately in $aWritten.
 */
class MockPiconetConnection implements \React\Socket\ConnectionInterface
{
    /** Real PHP stream resource — satisfies fwrite() / stream_set_write_buffer(). */
    public mixed $stream;

    /** Captures write($data) calls (React interface). */
    public array $aWritten = [];

    public bool $bClosed = false;

    public function __construct()
    {
        $this->stream = fopen('php://temp', 'r+');
    }

    public function __destruct()
    {
        if (is_resource($this->stream)) {
            fclose($this->stream);
        }
    }

    // -------------------------------------------------------------------------
    // React\Socket\ConnectionInterface
    // -------------------------------------------------------------------------

    public function write($data): bool { $this->aWritten[] = $data; return true; }
    public function end($data = null): void { if ($data !== null) { $this->aWritten[] = $data; } $this->bClosed = true; }
    public function close(): void { $this->bClosed = true; }
    public function pause(): void {}
    public function resume(): void {}
    public function pipe(\React\Stream\WritableStreamInterface $o, array $opts = []): \React\Stream\WritableStreamInterface { return $o; }
    public function getLocalAddress(): ?string { return '127.0.0.1:8765'; }
    public function getRemoteAddress(): ?string { return '127.0.0.1:54321'; }
    public function isReadable(): bool { return !$this->bClosed; }
    public function isWritable(): bool { return !$this->bClosed; }

    private array $aListeners = [];
    public function on($event, callable $fn): void { $this->aListeners[$event][] = $fn; }
    public function once($event, callable $fn): void { $this->aListeners[$event][] = $fn; }
    public function removeListener($event, callable $fn): void {}
    public function removeAllListeners($event = null): void {}
    public function listeners($event = null): array { return $this->aListeners[$event] ?? []; }
    public function emit($event, array $args = []): void
    {
        foreach ($this->aListeners[$event] ?? [] as $fn) {
            $fn(...$args);
        }
    }

    // -------------------------------------------------------------------------
    // Inspection helpers
    // -------------------------------------------------------------------------

    /** Return all data fwrite()-d to the stream (reads from start each time). */
    public function getStreamContent(): string
    {
        rewind($this->stream);
        return stream_get_contents($this->stream);
    }

    /** Return all data passed to the React write() method. */
    public function allWritten(): string
    {
        return implode('', $this->aWritten);
    }
}
