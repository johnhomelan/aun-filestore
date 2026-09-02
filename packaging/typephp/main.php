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
 * Everything the daemon does is compiled: the Econet + WebSocket packet paths,
 * both relay servers, the Piconet serial interface (wired - just idle with no
 * /dev/econet hardware), the outbound RemoteBridge client (react/dns compiled),
 * and the web admin UI (native dispatcher + build-time template transform, see
 * PORTING-REACT.md "Stage 10d"). A tpc `mode: bin` binary embeds no PHP
 * interpreter, so nothing runs interpreted - the pieces below are *replaced*,
 * not fallen back to:
 *   - Monolog            -> shims/StderrLogger.php (PSR-3 to stderr)
 *   - aws/aws-sdk-php    -> shims/aws_s3_client.php (SigV4 + curl; S3 VFS plugin)
 *   - ext-ldap           -> shims/ldap_openldap.cc (native, OpenLDAP client lib)
 *   - ext-pcntl / posix  -> shims/pcntl_posix.cc (fork + signals + a few calls)
 *   - smarty/smarty      -> shims/smarty_runtime.php + build-time template transform
 *   - Symfony HttpKernel / DI / Routing -> a fixed-route dispatcher
 *     (admin/dispatcher.php). No session cookie - the SessionCookie subscriber
 *     only set one nothing read.
 *   - Symfony HttpFoundation -> the REAL component (Response side compiled
 *     verbatim bar two hand-patched files); only Request + BinaryFileResponse
 *     stay in shims/symfony_httpfoundation.php.
 */

use HomeLan\FileStore\Aun\Handler as AunHandler;
use HomeLan\FileStore\Aun\Map as AunMap;
use HomeLan\FileStore\Authentication\Plugins\AuthPluginFile;
use HomeLan\FileStore\Authentication\Plugins\AuthPluginL3Password;
use HomeLan\FileStore\Authentication\Plugins\AuthPluginLdap;
use HomeLan\FileStore\Authentication\Plugins\AuthPluginMdfsPassword;
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
use Psr\Http\Message\ServerRequestInterface;
use Ratchet\Http\HttpServer;
use Ratchet\Server\IoServer;
use Ratchet\WebSocket\WsServer;
use React\Datagram\Socket as DatagramSocket;
use React\EventLoop\StreamSelectLoop;
use React\EventLoop\TimerInterface;
use React\Http\HttpServer as AdminHttpServer;
use React\Http\Message\Response as AdminHttpResponse;
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
    // class_exists($name, false), which is always true for an AOT class, so no
    // plugin's user table would ever load. Prime each configured one explicitly.
    AuthPluginFile::init($oLogger);
    $aAuthPlugins = \array_map('trim', \explode(',', config::getValueAsString('security_auth_plugins')));
    // LDAP (native via shims/ldap_openldap.cc) binds the service account and
    // throws if the directory is unreachable - only touch it when configured.
    if (\in_array('ldap', $aAuthPlugins, true)) {
        AuthPluginLdap::init($oLogger);
    }
    // Level-3 / MDFS password-file backends. init() logs and returns (does not
    // throw) if the file is absent, so gate on the plugin being configured to
    // keep that off the startup log otherwise.
    if (\in_array('l3password', $aAuthPlugins, true)) {
        AuthPluginL3Password::init($oLogger);
    }
    if (\in_array('mdfspassword', $aAuthPlugins, true)) {
        AuthPluginMdfsPassword::init($oLogger);
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

    // --- Admin web UI (transport only for now - see packaging/typephp's
    // admin HTTP UI plan, Stage 10a) ---------------------------------------
    aun_filestored_start_admin_http(
        $oLoop,
        config::getValueAsString('webadmin_listen_address') . ':' . config::getValueAsString('webadmin_listen_port'),
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
    // A Throwable raised inside a React stream / timer callback unwinds straight
    // out of run(). The AOT runtime makes this reachable in ways the interpreter
    // does not - e.g. a TypeError from fclose() in DuplexResourceStream::close()
    // on a peer socket that was abnormally reset, which is_resource() still
    // reports as live under tpc (see packaging/typephp/PORTING-REACT.md). A
    // single bad client connection must not take filestored down: log it and
    // re-enter run(). StreamSelectLoop keeps its stream/timer state across the
    // unwind, and the offending stream is already removeReadStream()'d before
    // close() throws, so re-entry resumes cleanly; a clean shutdown via stop()
    // still returns from run() normally and breaks the loop.
    //
    // (This wrapper is filestored-only. The pawl-client daemons - sql-serverd,
    // ecosyslogd, dnsd, ntpd - deliberately let AuthenticationFailedException
    // propagate out of run() so a bad shared secret stays fatal.)
    $iLoopFaults  = 0;
    $iLastFaultAt = 0;
    while (true) {
        try {
            $oLoop->run();
            break;
        } catch (\Throwable $oError) {
            $iNow         = \time();
            $iLoopFaults  = ($iNow === $iLastFaultAt) ? $iLoopFaults + 1 : 1;
            $iLastFaultAt = $iNow;
            $oLogger->error(
                'core: recovered from ' . \get_class($oError) . ' in event loop: '
                . $oError->getMessage() . ' (' . $oError->getFile() . ':' . $oError->getLine() . ')'
            );
            // Backstop against a corrupted loop state spinning the CPU: many
            // faults inside the same wall-clock second is not transient.
            if ($iLoopFaults > 100) {
                $oLogger->emergency('core: event loop faulting in a tight spin (' . $iLoopFaults . '/s); aborting');
                throw $oError;
            }
        }
    }
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
 * Stand up the plain (non-WebSocket) admin HTTP listener on $sAddr, via the
 * real react/http HttpServer (proven natively compilable - see
 * packaging/typephp/PORTING-REACT.md Stage 10a - independent of
 * Ratchet\Http\HttpServer above, which only ever does the WS upgrade
 * handshake). Transport only for now: the interpreted daemon's Admin\Kernel
 * (Symfony HttpKernel + Smarty) is not ported (see main.php's file header) -
 * every request gets a plain placeholder response rather than a connection
 * refused, until Stage 10b/10c/10d land the real admin app behind it.
 */
function aun_filestored_start_admin_http(StreamSelectLoop $oLoop, string $sAddr, StderrLogger $oLogger): void
{
    $oTransport = new TcpServer($sAddr, $oLoop);
    $oHttp = new AdminHttpServer($oLoop, function (ServerRequestInterface $oRequest) use ($oLogger): AdminHttpResponse {
        $oLogger->info('Admin HTTP: ' . $oRequest->getMethod() . ' ' . $oRequest->getUri()->getPath());
        return \HomeLan\FileStore\Admin\Native\Dispatcher::handle($oRequest);
    });
    $oHttp->listen($oTransport);
    $oLogger->info("Admin HTTP: listening on {$sAddr}");
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
