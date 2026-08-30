<?php

declare(strict_types=1);

/*
 * Compile-only React\EventLoop\Factory for the TypePHP builds.
 *
 * The real vendor Factory::create() picks the "best available" loop by probing
 * for ext-event / ext-uv / ext-ev and instantiating ExtEventLoop / ExtUvLoop /
 * ExtEvLoop - none of which compile (those extensions are not in the linked
 * libphp, and the classes are not in `sources`). The native builds only ever
 * use StreamSelectLoop, so this returns that directly.
 *
 * This lets HomeLan\FileStore\Command\{Dnsd,Ntpd,…} (which call
 * `ReactFactory::create()`) be compiled and run as-is, instead of each
 * `main-*.php` re-implementing the loop wiring with `new StreamSelectLoop()`.
 *
 * Listed only in packaging/typephp/project*.yml `sources`, never autoloaded, so
 * the interpreted runtime uses the real vendor Factory.
 */

namespace React\EventLoop;

final class Factory
{
    public static function create(): StreamSelectLoop
    {
        return new StreamSelectLoop();
    }
}
