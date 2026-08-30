<?php

declare(strict_types=1);

/*
 * TypePHP binary-mode entry point for sharefsd.
 *
 * A de-dynamised port of HomeLan\FileStore\Command\ShareFsd::MainLoop() - the
 * ShareFS / Acorn Level-4 / AccessPlus / Freeway daemon. Built by
 * `make sharefs-typephp` (packaging/typephp/project.sharefsd.yml), reusing the
 * whole vendored ReactPHP / Ratchet stack + vendor-patches from the filestored
 * build. See packaging/typephp/PORTING-REACT.md "Stage 7".
 *
 * NOT ported: the ShareFS Symfony admin UI (ShareFs\Admin\Kernel).
 */

use HomeLan\FileStore\Authentication\Plugins\AuthPluginFile;
use HomeLan\FileStore\Authentication\Plugins\AuthPluginLdap;
use HomeLan\FileStore\Authentication\Security;
use HomeLan\FileStore\Logging\StderrLogger;
use HomeLan\FileStore\RemoteSocket\Client as RemoteSocketClient;
use HomeLan\FileStore\ShareFs\AccessPlusHandler;
use HomeLan\FileStore\ShareFs\FreewayHandler;
use HomeLan\FileStore\ShareFs\Messages\FreewayPacket;
use HomeLan\FileStore\ShareFs\ShareAuthTable;
use HomeLan\FileStore\ShareFs\ShareFsHandler;
use HomeLan\FileStore\ShareFs\ShareList;
use HomeLan\FileStore\Vfs\Vfs;
use React\Datagram\Socket as DatagramSocket;
use React\Datagram\SocketInterface as DatagramSocketInterface;
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
    $oLogger->info('sharefsd (TypePHP native build) starting');

    Vfs::init($oLogger, config::getValueAsString('vfs_plugins'), config::getValueAsString('security_mode') === 'multiuser');
    Security::init($oLogger);
    // Security::_getAuthPlugins() only calls AuthPlugin*::init() when
    // class_exists($name, false) is false - which is never true for an AOT
    // class (registered at module init), so the file-backed plugin's user
    // table would never load. Prime it explicitly.
    AuthPluginFile::init($oLogger);
    // Same for the LDAP backend (native via shims/ldap_openldap.cc), but only
    // when it is actually configured - init() binds the service account and
    // throws if the directory is unreachable.
    if (\in_array('ldap', \array_map('trim', \explode(',', config::getValueAsString('security_auth_plugins'))), true)) {
        AuthPluginLdap::init($oLogger);
    }
    ShareList::init($oLogger);

    // Real ShareFS/Access+ has no per-client login - every operation runs as one
    // fixed service identity, logged in once here (see Command\ShareFsd).
    $iSvcNet = config::getValueAsInt('sharefs_service_network');
    $iSvcStn = config::getValueAsInt('sharefs_service_station');
    if (!Security::login($iSvcNet, $iSvcStn, config::getValueAsString('sharefs_service_username'), config::getValueAsString('sharefs_service_password'))) {
        $oLogger->emergency('sharefsd: cannot log in as the configured service identity, shutting down');
        exit(1);
    }

    $oLoop = new StreamSelectLoop();
    $oLogger->info('core: event loop is ' . \get_class($oLoop));

    $oRelayClient = null;
    if (config::getValueAsBool('sharefs_remote_socket_relay_enabled')) {
        $oRelayClient = new RemoteSocketClient(
            $oLoop,
            $oLogger,
            'ws://' . config::getValueAsString('sharefs_remote_socket_relay_address'),
            config::getValueAsString('sharefs_remote_socket_relay_secret')
        );
        $oRelayClient->connect();
        $oLogger->info('RemoteSocket relay client: connecting to ' . config::getValueAsString('sharefs_remote_socket_relay_address'));
    }

    $oFreeway = new FreewayHandler($oLogger);
    aun_sharefsd_bind($oLoop, $oRelayClient, config::getValueAsInt('sharefs_freeway_port'), 'Freeway',
        function (string $m, string $p) use ($oFreeway): void { $oFreeway->receive($m, $p); },
        function (DatagramSocketInterface $s) use ($oFreeway): void { $oFreeway->setSocket($s); }, $oLogger);

    $oAccessPlus = new AccessPlusHandler($oLogger);
    aun_sharefsd_bind($oLoop, $oRelayClient, config::getValueAsInt('sharefs_accessplus_port'), 'AccessPlus',
        function (string $m, string $p) use ($oAccessPlus): void { $oAccessPlus->receive($m, $p); },
        function (DatagramSocketInterface $s) use ($oAccessPlus): void { $oAccessPlus->setSocket($s); }, $oLogger);

    $oShareFs = new ShareFsHandler($oLogger);
    aun_sharefsd_bind($oLoop, $oRelayClient, config::getValueAsInt('sharefs_sharefsdata_port'), 'ShareFS-data',
        function (string $m, string $p) use ($oShareFs): void { $oShareFs->receive($m, $p); },
        function (DatagramSocketInterface $s) use ($oShareFs): void { $oShareFs->setSocket($s); }, $oLogger);

    $oLoop->addPeriodicTimer(FreewayPacket::DEFAULT_BROADCAST_INTERVAL, function (TimerInterface $oTimer) use ($oFreeway): void {
        $oFreeway->broadcast();
    });
    $oLoop->addPeriodicTimer(config::getValueAsFloat('housekeeping_interval'), function (TimerInterface $oTimer) use ($oShareFs): void {
        ShareAuthTable::houseKeeping();
        $oShareFs->houseKeeping();
        Vfs::houseKeeping();
    });

    $oLogger->info('core: entering primary loop');
    $oLoop->run();
    $oLogger->info('core: primary loop exited');
}

/**
 * Bind one ShareFS UDP service - either a real local socket via
 * stream_socket_server() + React\Datagram\Socket (Factory bypassed, as in the
 * filestored build), or a relayed transport from the Remote Socket client.
 *
 * @param callable(string,string):void          $fOnMessage
 * @param callable(DatagramSocketInterface):void $fSetSocket
 */
function aun_sharefsd_bind(
    StreamSelectLoop $oLoop,
    ?RemoteSocketClient $oRelayClient,
    int $iPort,
    string $sLabel,
    callable $fOnMessage,
    callable $fSetSocket,
    StderrLogger $oLogger
): void {
    if ($oRelayClient !== null) {
        $oTransport = $oRelayClient->getTransport($iPort);
        $oTransport->on('message', function (string $sMsg, string $sPeer, mixed $mSock = null) use ($fOnMessage): void {
            $fOnMessage($sMsg, $sPeer);
        });
        $fSetSocket($oTransport);
        $oLogger->info("{$sLabel}: using relayed transport for local port {$iPort}");
        return;
    }

    $sAddr = config::getValueAsString('sharefs_listen_address') . ':' . $iPort;
    $rSock = @\stream_socket_server('udp://' . $sAddr, $iErrno, $sErr, \STREAM_SERVER_BIND);
    if ($rSock === false) {
        $oLogger->error("{$sLabel}: cannot bind {$sAddr}: {$sErr}");
        return;
    }
    $oSock = new DatagramSocket($oLoop, $rSock);
    $oSock->on('message', function (string $sMsg, string $sPeer, DatagramSocket $oS) use ($fOnMessage): void {
        $fOnMessage($sMsg, $sPeer);
    });
    $fSetSocket($oSock);
    $oLogger->info("{$sLabel}: listening on {$sAddr}");
}
