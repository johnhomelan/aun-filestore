<?php

declare(strict_types=1);

/*
 * TypePHP binary-mode entry point for dnsd. Built by `make dns-typephp`.
 *
 * This no longer re-implements the daemon body: the real command class,
 * HomeLan\FileStore\Command\Dnsd, is compiled (staged copy with the file-scope
 * system.inc.php include stripped) and run directly here. The Symfony Console
 * base class + ReactPHP loop factory it needs are compile-only shims
 * (packaging/typephp/shims/{symfony_console,react_eventloop_factory,
 * safe_define,console_runtime}.php). See PORTING-REACT.md.
 *
 * dnsd receives DNS queries relayed over the Remote Socket Protocol from a
 * filestored instance's IPv4 service (it never binds UDP directly).
 */

use HomeLan\FileStore\Cli\ArgvInput;
use HomeLan\FileStore\Cli\StdoutOutput;
use HomeLan\FileStore\Command\Dnsd;
use HomeLan\FileStore\Logging\StderrLogger;

function main(int $argc, array $argv): void
{
    $oLogger = new StderrLogger('info');
    $oLogger->info('dnsd (TypePHP native build) starting');

    $iExit = (new Dnsd($oLogger))->run(
        new ArgvInput(\array_slice($argv, 1)),
        new StdoutOutput()
    );
    exit($iExit);
}
