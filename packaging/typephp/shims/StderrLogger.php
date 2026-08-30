<?php

declare(strict_types=1);

namespace HomeLan\FileStore\Logging;

use Psr\Log\LoggerInterface;

/**
 * Minimal PSR-3 logger for the TypePHP (packaging/typephp) build.
 *
 * The interpreted server logs through Monolog, wired up by the Symfony
 * container / the Command classes. Monolog is large and heavily dynamic and is
 * not on the compile path, so the native binary constructs one of these
 * instead and passes it wherever a Psr\Log\LoggerInterface is required
 * (ServiceDispatcher, the handlers, ...). It writes one line per record to
 * stderr and honours a minimum level.
 *
 * Method signatures match the vendored psr/log (untyped $message,
 * `array()` default) exactly - TypePHP enforces LSP strictly.
 *
 * Compile-only - never autoloaded by the interpreted runtime.
 */
final class StderrLogger implements LoggerInterface
{
    /** @var array<string,int> PSR-3 level => severity (higher = more severe) */
    private const LEVELS = [
        'debug'     => 0,
        'info'      => 1,
        'notice'    => 2,
        'warning'   => 3,
        'error'     => 4,
        'critical'  => 5,
        'alert'     => 6,
        'emergency' => 7,
    ];

    private int $iMinLevel;

    public function __construct(string $sMinLevel = 'info')
    {
        $this->iMinLevel = self::LEVELS[$sMinLevel] ?? 1;
    }

    public function emergency($message, array $context = array()): void
    {
        $this->log('emergency', $message, $context);
    }

    public function alert($message, array $context = array()): void
    {
        $this->log('alert', $message, $context);
    }

    public function critical($message, array $context = array()): void
    {
        $this->log('critical', $message, $context);
    }

    public function error($message, array $context = array()): void
    {
        $this->log('error', $message, $context);
    }

    public function warning($message, array $context = array()): void
    {
        $this->log('warning', $message, $context);
    }

    public function notice($message, array $context = array()): void
    {
        $this->log('notice', $message, $context);
    }

    public function info($message, array $context = array()): void
    {
        $this->log('info', $message, $context);
    }

    public function debug($message, array $context = array()): void
    {
        $this->log('debug', $message, $context);
    }

    public function log($level, $message, array $context = array()): void
    {
        $sLevel = \is_string($level) ? $level : 'info';
        if ((self::LEVELS[$sLevel] ?? 1) < $this->iMinLevel) {
            return;
        }
        \fwrite(\STDERR, '[' . \strtoupper($sLevel) . '] ' . (string) $message . "\n");
    }
}
