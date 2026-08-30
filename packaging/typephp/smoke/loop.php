<?php

declare(strict_types=1);

use React\EventLoop\StreamSelectLoop;
use React\EventLoop\TimerInterface;

/**
 * PORTING-REACT.md Stage 1 smoke test: drive a native StreamSelectLoop with two
 * timers and confirm the periodic one fired ~10 times before the one-shot timer
 * stopped the loop.
 */
function main(int $argc, array $argv): void
{
    $oLoop  = new StreamSelectLoop();
    $iTicks = 0;

    $oLoop->addPeriodicTimer(0.1, function (TimerInterface $oTimer) use (&$iTicks): void {
        $iTicks++;
    });

    $oLoop->addTimer(1.05, function (TimerInterface $oTimer) use ($oLoop): void {
        $oLoop->stop();
    });

    $oLoop->run();

    echo "loop stopped after {$iTicks} periodic ticks\n";
    echo (($iTicks >= 8 && $iTicks <= 12) ? "PASS" : "FAIL") . "\n";
}
