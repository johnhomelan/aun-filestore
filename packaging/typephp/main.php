<?php

declare(strict_types=1);

/*
 * TypePHP binary-mode entry point for aun-filestored.
 *
 * This is a de-dynamised port of HomeLan\FileStore\Command\React::MainLoop()
 * (src/include/classes/Command/React.php) - the daemon's real event loop -
 * built to compile under swoole/typephp. It stands up:
 *
 *   - the ReactPHP StreamSelectLoop (native)
 *   - the AUN UDP listener via react/datagram (native)
 *   - the Ratchet WebSocket listener  IoServer(HttpServer(WsServer(Handler)))
 *   - the Remote Socket / Remote Provider Protocol relay WebSocket listeners
 *   - the encapsulation + ServiceDispatcher + every Services\Provider\*
 *   - the three housekeeping / reply-pump / AUN-retransmit periodic timers
 *
 * Everything on the Econet and WebSocket packet paths runs as compiled code.
 * See packaging/typephp/README.md and packaging/typephp/PORTING-REACT.md.
 *
 * NOT ported here: the Symfony admin UI, the Piconet serial interface, and the
 * outbound RemoteBridge client (needs react/dns). Those degrade gracefully -
 * their listeners simply are not started.
 */

use HomeLan\FileStore\Aun\Handler as AunHandler;
use HomeLan\FileStore\Aun\Map as AunMap;
use HomeLan\FileStore\Authentication\Plugins\AuthPluginFile;
use HomeLan\FileStore\Authentication\Plugins\AuthPluginLdap;
use HomeLan\FileStore\Authentication\Security;
use HomeLan\FileStore\Encapsulation\EncapsulationTypeMap;
use HomeLan\FileStore\Encapsulation\PacketDispatcher;
use HomeLan\FileStore\Logging\StderrLogger;
use HomeLan\FileStore\Piconet\Handler as PiconetHandler;
use HomeLan\FileStore\Piconet\Map as PiconetMap;
use HomeLan\FileStore\React\UnixSerialDeviceConnector;
use HomeLan\FileStore\Services\Provider\BeebTerm;
use HomeLan\FileStore\Services\Provider\Bridge;
use HomeLan\FileStore\Services\Provider\FileServer;
use HomeLan\FileStore\Services\Provider\IPv4;
use HomeLan\FileStore\Services\Provider\MaceMail;
use HomeLan\FileStore\Services\Provider\PrintServer;
use HomeLan\FileStore\Services\Provider\ProxyProvider;
use HomeLan\FileStore\Services\Provider\Teletext;
use HomeLan\FileStore\Services\Provider\Torchnet;
use HomeLan\FileStore\Services\Provider\Viewdata;
use HomeLan\FileStore\Services\ServiceDispatcher;
use HomeLan\FileStore\RemoteBridge\ClientHandler as RemoteBridgeClientHandler;
use HomeLan\FileStore\RemoteBridge\Map as RemoteBridgeMap;
use HomeLan\FileStore\RemoteBridge\ServerHandler as RemoteBridgeServerHandler;
use HomeLan\FileStore\RemoteProvider\RelayServer as RemoteProviderRelayServer;
use HomeLan\FileStore\RemoteSocket\RelayServer as RemoteSocketRelayServer;
use HomeLan\FileStore\Vfs\Vfs;
use HomeLan\FileStore\WebSocket\Handler as WebSocketHandler;
use HomeLan\FileStore\WebSocket\Map as WebSocketMap;
use Ratchet\Http\HttpServer;
use Ratchet\Server\IoServer;
use Ratchet\WebSocket\WsServer;
use React\Datagram\Socket as DatagramSocket;
use React\EventLoop\StreamSelectLoop;
use React\EventLoop\TimerInterface;
use React\Socket\TcpServer;

const AUN_PKT_DELAY = 0.04;

function main(int $argc, array $argv): void
{
    // --- args (subset of src/filestored's): -c <dir>, -d, -p <pidfile> ------
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
    $oLogger->info('aun-filestored (TypePHP native build) starting');

    $oLoop = new StreamSelectLoop();
    $oLogger->info('core: event loop is ' . \get_class($oLoop));

    Security::init($oLogger);
    // Security::_getAuthPlugins() gates AuthPlugin*::init() on
    // class_exists($name, false), which is always true for an AOT class, so the
    // file-backed user table would never load. Prime it explicitly.
    AuthPluginFile::init($oLogger);
    // Same for the LDAP backend (native via shims/ldap_openldap.cc), but only
    // when it is actually configured - init() binds the service account and
    // throws if the directory is unreachable.
    if (\in_array('ldap', \array_map('trim', \explode(',', config::getValueAsString('security_auth_plugins'))), true)) {
        AuthPluginLdap::init($oLogger);
    }
    Vfs::init($oLogger, config::getValueAsString('vfs_plugins'), config::getValueAsString('security_mode') === 'multiuser');
    WebSocketMap::init($oLogger);

    $oTypeMap    = EncapsulationTypeMap::create();
    $oDispatcher = PacketDispatcher::create($oTypeMap, $oLoop);

    $oServices = ServiceDispatcher::create($oLogger, [
        new FileServer($oLogger),
        new PrintServer($oLogger),
        new Bridge($oLogger),
        new IPv4($oLogger),
        new BeebTerm($oLogger),
        new Torchnet($oLogger),
        new MaceMail($oLogger),
        new Teletext($oLogger),
        new Viewdata($oLogger),
        // Ports reserved for out-of-process providers hosted over the Remote
        // Provider Protocol relay - same list as src/filestored. 0xB6 is
        // ecosyslogd's, 0xB7 is sql-serverd's; those run as separate processes.
        new ProxyProvider($oLogger, [0xB6, 0xB7]),
    ]);
    $oServices->start($oTypeMap, $oLoop);

    // --- AUN UDP listener ---------------------------------------------------
    $oAun = new AunHandler($oLogger, $oServices, $oDispatcher);
    AunMap::init($oLogger, $oAun);
    aun_filestored_start_aun($oLoop, $oAun, $oLogger);

    // --- Piconet serial interface ----------------------------------------
    // Compiled and wired, but the device open will fail with no hardware
    // attached; PiconetHandler then just reschedules the reconnect.
    $oPiconet = new PiconetHandler($oLogger, $oServices, $oDispatcher);
    $oPiconet->setLoop($oLoop);
    PiconetMap::init($oLogger, $oPiconet);
    aun_filestored_connect_piconet($oLoop, $oPiconet, $oLogger);

    // --- WebSocket listener (BBC-micro bridge) ----------------------------
    $oWsHandler = new WebSocketHandler($oLogger, $oServices, $oDispatcher);
    aun_filestored_start_ws(
        $oLoop,
        $oWsHandler,
        config::getValueAsString('websocket_listen_address') . ':' . config::getValueAsString('websocket_listen_port'),
        'WebSocket bridge',
        $oLogger
    );

    // --- Remote Socket Protocol relay ------------------------------------
    if (config::getValueAsBool('remote_socket_relay_enabled')) {
        $oProvider = $oServices->getServiceByPort(0xD2);
        if ($oProvider instanceof IPv4) {
            $oSocketRelay = new RemoteSocketRelayServer(
                $oLogger,
                config::getValueAsString('remote_socket_relay_secret'),
                $oProvider->injectRelayReply(...)
            );
            $oProvider->setRelayServer($oSocketRelay);
            aun_filestored_start_ws(
                $oLoop,
                $oSocketRelay,
                config::getValueAsString('remote_socket_relay_listen_address') . ':' . config::getValueAsString('remote_socket_relay_listen_port'),
                'RemoteSocket relay',
                $oLogger
            );
        }
    }

    // --- Remote Provider Protocol relay --------------------------------
    if (config::getValueAsBool('remote_provider_relay_enabled')) {
        $oProxy = null;
        foreach ($oServices->getServices() as $oService) {
            if ($oService instanceof ProxyProvider) {
                $oProxy = $oService;
                break;
            }
        }
        if ($oProxy instanceof ProxyProvider) {
            $oProviderRelay = new RemoteProviderRelayServer(
                $oLogger,
                config::getValueAsString('remote_provider_relay_secret'),
                $oProxy->injectReply(...),
                $oProxy->claimStreamPort(...)
            );
            $oProxy->setRelayServer($oProviderRelay);
            aun_filestored_start_ws(
                $oLoop,
                $oProviderRelay,
                config::getValueAsString('remote_provider_relay_listen_address') . ':' . config::getValueAsString('remote_provider_relay_listen_port'),
                'RemoteProvider relay',
                $oLogger
            );
        }
    }

    // --- Remote bridge (bridge-to-bridge TCP, feature-gated) ------------
    if (config::getValueAsBool('remote_bridge_enabled')) {
        RemoteBridgeMap::init($oLogger);
        foreach (RemoteBridgeMap::getServerEntries() as $aEntry) {
            (new RemoteBridgeServerHandler($oLogger, $oServices, $oDispatcher))->start($aEntry, $oLoop);
        }
        if (RemoteBridgeMap::getClientEntries() !== []) {
            // ClientHandler uses the async React\Socket\Connector (react/dns) -
            // compiled since Stage 6. Hostname bridge targets resolve natively.
            (new RemoteBridgeClientHandler($oLogger, $oServices, $oDispatcher, $oLoop))->start();
        }
        $oLogger->info('RemoteBridge: enabled');
    }

    // --- periodic timers (see React::MainLoop) --------------------------
    $oLoop->addPeriodicTimer(1.0, function (TimerInterface $oTimer) use ($oServices, $oDispatcher): void {
        foreach ($oServices->getReplies() as $oReply) {
            $oDispatcher->sendPacket($oReply);
        }
    });
    $oLoop->addPeriodicTimer(config::getValueAsFloat('housekeeping_interval'), function (TimerInterface $oTimer) use ($oServices): void {
        Security::houseKeeping();
        Vfs::houseKeeping();
        $oServices->houseKeeping();
    });
    $oLoop->addPeriodicTimer(AUN_PKT_DELAY, function (TimerInterface $oTimer) use ($oAun): void {
        $oAun->timer();
    });

    $oLogger->info('core: entering primary loop');
    $oLoop->run();
    $oLogger->info('core: primary loop exited');
}

/**
 * Bind the AUN UDP socket and route inbound datagrams to the handler.
 *
 * react/datagram's Factory is bypassed - it eagerly builds a react/dns resolver
 * in its constructor, which this build omits. A fixed local bind wants the raw
 * stream_socket_server() anyway (see PORTING-REACT.md Stage 2).
 */
function aun_filestored_start_aun(StreamSelectLoop $oLoop, AunHandler $oAun, StderrLogger $oLogger): void
{
    $sAddr = config::getValueAsString('aun_listen_address') . ':' . config::getValueAsString('aun_listen_port');
    $rSock = @\stream_socket_server('udp://' . $sAddr, $iErrno, $sErr, \STREAM_SERVER_BIND);
    if ($rSock === false) {
        $oLogger->error("AUN: cannot bind {$sAddr}: {$sErr}");
        return;
    }
    $oSock = new DatagramSocket($oLoop, $rSock);
    $oSock->on('message', function (string $sMsg, string $sPeer, DatagramSocket $oS) use ($oAun): void {
        $mLocal = $oS->getLocalAddress();
        $oAun->receive($sMsg, $sPeer, \is_string($mLocal) ? $mLocal : '');
    });
    $oAun->setSocket($oSock);
    $oLogger->info("AUN: listening on {$sAddr}");
}

/**
 * Stand up a Ratchet WebSocket listener on $sAddr for the given component.
 * $oComponent is a Ratchet\MessageComponentInterface (WebSocket\Handler or a
 * relay RelayServer).
 */
function aun_filestored_start_ws(StreamSelectLoop $oLoop, object $oComponent, string $sAddr, string $sLabel, StderrLogger $oLogger): void
{
    $oTransport = new TcpServer($sAddr, $oLoop);
    new IoServer(new HttpServer(new WsServer($oComponent)), $oTransport, $oLoop);
    $oLogger->info("{$sLabel}: listening on {$sAddr}");
}

/**
 * Open the Piconet serial device and route its stream to the PiconetHandler,
 * retrying on failure - a de-dynamised port of Command\React::piconetService().
 * With no serial hardware present the fopen()/stty step fails and the handler
 * just reschedules; the code path still compiles and runs.
 */
function aun_filestored_connect_piconet(StreamSelectLoop $oLoop, PiconetHandler $oPiconet, StderrLogger $oLogger): void
{
    // Optional Timer arg: PiconetHandler::scheduleReconnect() re-arms this as an
    // addTimer() callback, which the loop invokes with a TimerInterface;
    // it is also called directly here with no args. Strict arg-count needs both.
    $fConnect = function (?TimerInterface $oTimer = null) use ($oLoop, $oPiconet, $oLogger): void {
        $sDevice = 'file://' . config::getValueAsString('piconet_device');
        $oConnector = new UnixSerialDeviceConnector($oLoop);
        $oConnector->connect($sDevice)->then(
            function (\React\Socket\ConnectionInterface $oConnection) use ($oPiconet): void {
                $oPiconet->onOpen($oConnection);
                $oPiconet->onConnect();
                $oConnection->on('data', function (string $sMessage) use ($oPiconet): void {
                    $oPiconet->onMessage($sMessage);
                });
                $oConnection->on('close', function () use ($oPiconet): void {
                    $oPiconet->onClose();
                });
                $oConnection->on('error', function (\Exception $oErr) use ($oPiconet): void {
                    $oPiconet->onError($oErr);
                });
            },
            function (\Throwable $oErr) use ($oPiconet, $oLogger): void {
                $oLogger->info('Piconet: device open failed (' . $oErr->getMessage() . '); will retry');
                $oPiconet->scheduleReconnect();
            }
        );
    };

    $oPiconet->setReconnectCallback($fConnect);
    $fConnect();
}
