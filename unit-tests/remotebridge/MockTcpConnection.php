<?php

/**
 * Minimal mock of React\Socket\ConnectionInterface for RemoteBridge unit tests.
 */
class MockTcpConnection implements \React\Socket\ConnectionInterface
{
    public array $aWritten = [];
    public bool $bClosed = false;

    public function write($sData): bool { $this->aWritten[] = $sData; return true; }
    public function end($sData = null): void { if ($sData !== null) $this->aWritten[] = $sData; $this->bClosed = true; }
    public function close(): void { $this->bClosed = true; }
    public function pause(): void {}
    public function resume(): void {}
    public function pipe(\React\Stream\WritableStreamInterface $o, array $opts = []): \React\Stream\WritableStreamInterface { return $o; }
    public function getLocalAddress(): ?string { return '127.0.0.1:8765'; }
    public function getRemoteAddress(): ?string { return '127.0.0.1:54321'; }
    public function isReadable(): bool { return !$this->bClosed; }
    public function isWritable(): bool { return !$this->bClosed; }

    private array $aListeners = [];
    public function on($sEvent, callable $fn): void { $this->aListeners[$sEvent][] = $fn; }
    public function once($sEvent, callable $fn): void { $this->aListeners[$sEvent][] = $fn; }
    public function removeListener($sEvent, callable $fn): void {}
    public function removeAllListeners($sEvent = null): void {}
    public function listeners($sEvent = null): array { return $this->aListeners[$sEvent] ?? []; }
    public function emit($sEvent, array $args = []): void { foreach ($this->aListeners[$sEvent] ?? [] as $fn) { $fn(...$args); } }

    public function allWritten(): string { return implode('', $this->aWritten); }

    /** Returns trimmed non-empty lines from all writes */
    public function writtenLines(): array
    {
        return array_values(array_filter(array_map('trim', explode("\n", $this->allWritten()))));
    }
}
