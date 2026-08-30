<?php

declare(strict_types=1);

/*
 * TypePHP binary-mode entry point for ecosyslogd - a de-dynamised port of
 * HomeLan\FileStore\Command\EcoSyslogd::MainLoop(). Built by
 * `make ecosyslog-typephp`.
 *
 * ecosyslogd hosts the EcoSyslog provider out-of-process: it connects to
 * filestored's Remote Provider Protocol relay, registers port 0xB6, and writes
 * every relayed Econet log record to the OS syslog. Monolog is replaced by
 * shims/SyslogLogger.php; the ReactPHP / Ratchet stack + vendor-patches are
 * reused wholesale. See PORTING-REACT.md.
 */

use HomeLan\FileStore\Logging\StderrLogger;
use HomeLan\FileStore\Logging\SyslogLogger;
use HomeLan\FileStore\RemoteProvider\Client as RemoteProviderClient;
use HomeLan\FileStore\RemoteProvider\Host as RemoteProviderHost;
use HomeLan\FileStore\Services\Provider\EcoSyslog;
use HomeLan\FileStore\Services\ServiceDispatcher;
use React\EventLoop\StreamSelectLoop;
use React\EventLoop\TimerInterface;

function main(int $argc, array $argv): void
{
    $sConfigDir = '';
    $bDaemonize = false;
    $sPidFile   = '';
    for ($i = 1; $i < $argc; $i++) {
        $sArg = $argv[$i];
        if (($sArg === '-c' || $sArg === '--config') && isset($argv[$i + 1])) {
            $sConfigDir = $argv[++$i];
        } elseif ($sArg === '-d' || $sArg === '--daemonize') {
            $bDaemonize = true;
        } elseif (($sArg === '-p' || $sArg === '--pidfile') && isset($argv[$i + 1])) {
            $sPidFile = $argv[++$i];
        }
    }
    if ($sConfigDir !== '') {
        \define('CONFIG_CONF_FILE_PATH', $sConfigDir);
    }
    if ($bDaemonize) {
        $iPid = \pcntl_fork();
        if ($iPid !== 0) {
            if ($sPidFile !== '') {
                \file_put_contents($sPidFile, (string) $iPid);
            }
            exit(0);
        }
    }

    $oLogger = new StderrLogger('info');
    $oLogger->info('ecosyslogd (TypePHP native build) starting');

    $oLoop = new StreamSelectLoop();
    $oLogger->info('core: event loop is ' . \get_class($oLoop));

    // Where relayed log records land - local syslog and/or a remote collector,
    // driven by ecosyslog_local_enabled / ecosyslog_remote_enabled.
    $oSyslog = new SyslogLogger('ecosyslog');

    $oDispatcher = ServiceDispatcher::create($oLogger, [new EcoSyslog($oSyslog)]);

    $oRelay = new RemoteProviderClient(
        $oLoop,
        $oLogger,
        'ws://' . config::getValueAsString('ecosyslog_remote_provider_relay_address'),
        config::getValueAsString('ecosyslog_remote_provider_relay_secret')
    );
    $oHost = new RemoteProviderHost($oRelay, $oDispatcher, $oLogger);
    $oRelay->connect();
    $oLogger->info('RemoteProvider relay client: connecting to ' . config::getValueAsString('ecosyslog_remote_provider_relay_address'));

    $oLoop->addPeriodicTimer(1.0, function (?TimerInterface $oTimer = null) use ($oHost): void {
        $oHost->flush();
    });
    $oLoop->addPeriodicTimer(config::getValueAsFloat('housekeeping_interval'), function (?TimerInterface $oTimer = null) use ($oDispatcher): void {
        $oDispatcher->houseKeeping();
    });

    $oLogger->info('core: entering primary loop');
    $oLoop->run();
    $oLogger->info('core: primary loop exited');
}
