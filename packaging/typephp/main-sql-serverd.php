<?php

declare(strict_types=1);

/*
 * TypePHP binary-mode entry point for sql-serverd. Built by `make sql-typephp`.
 *
 * Like the dnsd / ntpd builds, this does not re-implement the daemon body: the
 * real command class, HomeLan\FileStore\Command\SqlServerd, is compiled (staged
 * copy with the file-scope system.inc.php include stripped) and run directly.
 * The Symfony Console base + ReactPHP loop factory it needs are compile-only
 * shims (packaging/typephp/shims/{symfony_console,react_eventloop_factory,
 * safe_define,console_runtime}.php). See PORTING-REACT.md.
 *
 * sql-serverd hosts Services\Provider\SqlServer over the Remote Provider
 * Protocol (structured identically to ecosyslogd); it always connects to a
 * filestored instance over the relay and has no transport of its own.
 *
 * Engines: SQLite, PostgreSQL, MySQL/MariaDB - the Containerfile builds the
 * libphp tpc links with all three PDO drivers. DatabaseRegistry rejects an
 * engine whose driver is not loaded with a clear error.
 */

use HomeLan\FileStore\Authentication\Plugins\AuthPluginFile;
use HomeLan\FileStore\Authentication\Security;
use HomeLan\FileStore\Cli\ArgvInput;
use HomeLan\FileStore\Cli\StdoutOutput;
use HomeLan\FileStore\Command\SqlServerd;
use HomeLan\FileStore\Logging\StderrLogger;

function main(int $argc, array $argv): void
{
    $oLogger = new StderrLogger('info');
    $oLogger->info('sql-serverd (TypePHP native build) starting');

    // SqlServer authenticates BBC clients with Security::login()/getUser(); the
    // command class itself never calls Security::init(), and
    // Security::_getAuthPlugins() cannot auto-init an AOT class (class_exists()
    // is always true for one), so prime the file-backed plugin here.
    Security::init($oLogger);
    AuthPluginFile::init($oLogger);

    $iExit = (new SqlServerd($oLogger))->run(
        new ArgvInput(\array_slice($argv, 1)),
        new StdoutOutput()
    );
    exit($iExit);
}
