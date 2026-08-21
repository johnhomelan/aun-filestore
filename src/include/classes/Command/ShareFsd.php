<?php

namespace HomeLan\FileStore\Command;
declare(ticks = 1);

include_once(__DIR__ . '/../../system.inc.php');

use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Command\Command;

use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use React\EventLoop\LoopInterface;
use React\EventLoop\Factory as ReactFactory;
use React\Datagram\Factory as DatagramFactory;

use HomeLan\FileStore\ShareFs\ShareList;
use HomeLan\FileStore\ShareFs\ShareAuthTable;
use HomeLan\FileStore\ShareFs\FreewayHandler;
use HomeLan\FileStore\ShareFs\AccessPlusHandler;
use HomeLan\FileStore\ShareFs\ShareFsHandler;
use HomeLan\FileStore\ShareFs\Messages\FreewayPacket;
use HomeLan\FileStore\ShareFs\Admin\Kernel as ShareFsAdminKernel;
use HomeLan\FileStore\Vfs\Vfs;
use HomeLan\FileStore\Authentication\Security;
use HomeLan\FileStore\RemoteSocket\Client as RemoteSocketClient;
use HomeLan\FileStore\RemoteSocket\Exceptions\AuthenticationFailedException;

use config;
use Exception;

/**
 * sharefsd is the main loop of the standalone ShareFS/Access+ daemon. It runs its own
 * ReactPHP event loop and its own UDP sockets (Freeway, Access+, ShareFS data) - deliberately
 * not routed through ServiceDispatcher/PacketDispatcher, since ShareFS is a UDP/IP-native
 * protocol suite with no Econet transport of its own (see docs/protocols/sharefs.md). It
 * reuses the Vfs, Security, and config classes exactly as filestored's Command\React does, but
 * is otherwise fully independent: a separate OS process with its own static Vfs/Security
 * state. Real ShareFS/Access+ has no per-client login at all, so every operation runs as one
 * fixed service identity logged in here at startup - see docs/SHAREFSD.md.
 */
#[\Symfony\Component\Console\Attribute\AsCommand(name: 'sharefsd')]
class ShareFsd extends Command
{
    protected static string $defaultDescription = 'Start the ShareFS/Access+ services';

    public function __construct(private readonly \Psr\Log\LoggerInterface $oLogger)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $oInput, OutputInterface $oOutput): int
    {
        $bDaemonize = false;
        $sPidFile = '';

        $mConfigOption = $oInput->getOption('config');
        $sConfigOption = is_scalar($mConfigOption) ? (string) $mConfigOption : '';
        $this->oLogger->info('Config input is ' . $sConfigOption);
        if ($mConfigOption !== null) {
            safe_define('CONFIG_CONF_FILE_PATH', $sConfigOption);
        }
        if ($oInput->getOption('pidfile') !== null) {
            $mPidFile = $oInput->getOption('pidfile');
            $sPidFile = is_scalar($mPidFile) ? (string) $mPidFile : '';
        }
        if ($oInput->getOption('daemonize') !== null) {
            $bDaemonize = true;
        }

        if ($bDaemonize) {
            $this->daemonize($sPidFile);
        }

        Vfs::init($this->oLogger, config::getValueAsString('vfs_plugins'), config::getValueAsString('security_mode') == 'multiuser');
        Security::init($this->oLogger);
        ShareList::init($this->oLogger);

        // Real ShareFS/Access+ has no per-client login (see docs/protocols/sharefs.md) - every
        // operation runs as this one fixed identity, logged in once here.
        $iServiceNetwork = config::getValueAsInt('sharefs_service_network');
        $iServiceStation = config::getValueAsInt('sharefs_service_station');
        if (!Security::login($iServiceNetwork, $iServiceStation, config::getValueAsString('sharefs_service_username'), config::getValueAsString('sharefs_service_password'))) {
            $this->oLogger->emergency('sharefsd: unable to log in as the configured service identity (sharefs_service_username/sharefs_service_password), shutting down.');
            exit(1);
        }

        try {
            pcntl_signal(SIGCHLD, $this->sigHandler(...));
            pcntl_signal(SIGTERM, $this->sigHandler(...));
        } catch (Exception $oException) {
            $this->oLogger->debug($oException->getMessage());
            $this->oLogger->emergency('Un-able to initialize sharefsd, shutting down.');
            exit(1);
        }

        $this->MainLoop();
        return \Symfony\Component\Console\Command\Command::SUCCESS;
    }

    private function MainLoop(): void
    {
        $oLoop = ReactFactory::create();
        $oLogger = $this->oLogger;

        $this->oLogger->info('sharefsd: Using ' . $oLoop::class . ' as the primary event loop handler');

        $oRelayClient = null;
        if (config::getValueAsBool('sharefs_remote_socket_relay_enabled')) {
            $oRelayClient = new RemoteSocketClient(
                $oLoop,
                $this->oLogger,
                'ws://' . config::getValueAsString('sharefs_remote_socket_relay_address'),
                config::getValueAsString('sharefs_remote_socket_relay_secret')
            );
            $oRelayClient->connect();
        }

        $oFreewayHandler = $this->freewayService($oLoop, $oRelayClient);
        $this->accessPlusService($oLoop, $oRelayClient);
        $oShareFsHandler = $this->shareFsDataService($oLoop, $oRelayClient);
        $this->adminService($oLoop);

        $oLoop->addPeriodicTimer(FreewayPacket::DEFAULT_BROADCAST_INTERVAL, function () use ($oFreewayHandler) {
            $oFreewayHandler->broadcast();
        });

        $oLoop->addPeriodicTimer(config::getValueAsFloat('housekeeping_interval'), function () use ($oLogger, $oShareFsHandler) {
            $oLogger->debug('sharefsd: Running house keeping tasks');
            ShareAuthTable::houseKeeping();
            $oShareFsHandler->houseKeeping();
            Vfs::houseKeeping();
        });

        $this->oLogger->debug('sharefsd: Starting primary loop.');
        try {
            $oLoop->run();
        } catch (AuthenticationFailedException $oException) {
            $this->oLogger->emergency('sharefsd: ' . $oException->getMessage() . ', shutting down.');
            exit(1);
        }
    }

    protected function configure(): void
    {
        $sHelp = <<<EOF
Start the ShareFS/Access+ services
EOF;

        parent::configure();
        $this
            ->addOption(
                'config',
                'c',
                InputOption::VALUE_OPTIONAL,
                'Provides the path to the config directory to be used (any files ending in .conf will be read from this directory)',
                null
            )->addOption(
                'daemonize',
                'd',
                InputOption::VALUE_OPTIONAL,
                'Causes sharefsd to daemonize and drop into the background, otherwise the process continues to run in the foreground'
            )->addOption(
                'pidfile',
                'p',
                InputOption::VALUE_OPTIONAL,
                'Cause sharefsd to write the PID of the deamonized process to a file'
            )->setHelp($sHelp);
    }

    public function sigHandler(int $iSigno): void
    {
        switch ($iSigno) {
            case SIGTERM:
                $this->oLogger->info('Shutting down sharefsd');
                break;
            case SIGCHLD:
            default:
                break;
        }
    }

    private function freewayService(LoopInterface $oLoop, ?RemoteSocketClient $oRelayClient): FreewayHandler
    {
        $oHandler = new FreewayHandler($this->oLogger);
        $iPort = config::getValueAsInt('sharefs_freeway_port');

        if ($oRelayClient !== null) {
            $oTransport = $oRelayClient->getTransport($iPort);
            $oTransport->on('message', function (string $sMessage, string $sSrcAddress) use ($oHandler) {
                $oHandler->receive($sMessage, $sSrcAddress);
            });
            $oHandler->setSocket($oTransport);
            return $oHandler;
        }

        $oDatagramFactory = new DatagramFactory($oLoop);
        $mServerPromise = $oDatagramFactory->createServer(
            config::getValueAsString('sharefs_listen_address') . ':' . $iPort
        );
        if ($mServerPromise instanceof \React\Promise\PromiseInterface) {
            $mServerPromise->then(function (mixed $mServer) use ($oHandler) {
                if (!$mServer instanceof \React\Datagram\Socket) {
                    return;
                }
                $mServer->on('message', function (string $sMessage, string $sSrcAddress) use ($oHandler) {
                    $oHandler->receive($sMessage, $sSrcAddress);
                });
                $oHandler->setSocket($mServer);
            });
        }
        return $oHandler;
    }

    private function accessPlusService(LoopInterface $oLoop, ?RemoteSocketClient $oRelayClient): AccessPlusHandler
    {
        $oHandler = new AccessPlusHandler($this->oLogger);
        $iPort = config::getValueAsInt('sharefs_accessplus_port');

        if ($oRelayClient !== null) {
            $oTransport = $oRelayClient->getTransport($iPort);
            $oTransport->on('message', function (string $sMessage, string $sSrcAddress) use ($oHandler) {
                $oHandler->receive($sMessage, $sSrcAddress);
            });
            $oHandler->setSocket($oTransport);
            return $oHandler;
        }

        $oDatagramFactory = new DatagramFactory($oLoop);
        $mServerPromise = $oDatagramFactory->createServer(
            config::getValueAsString('sharefs_listen_address') . ':' . $iPort
        );
        if ($mServerPromise instanceof \React\Promise\PromiseInterface) {
            $mServerPromise->then(function (mixed $mServer) use ($oHandler) {
                if (!$mServer instanceof \React\Datagram\Socket) {
                    return;
                }
                $mServer->on('message', function (string $sMessage, string $sSrcAddress) use ($oHandler) {
                    $oHandler->receive($sMessage, $sSrcAddress);
                });
                $oHandler->setSocket($mServer);
            });
        }
        return $oHandler;
    }

    private function shareFsDataService(LoopInterface $oLoop, ?RemoteSocketClient $oRelayClient): ShareFsHandler
    {
        $oHandler = new ShareFsHandler($this->oLogger);
        $iPort = config::getValueAsInt('sharefs_sharefsdata_port');

        if ($oRelayClient !== null) {
            $oTransport = $oRelayClient->getTransport($iPort);
            $oTransport->on('message', function (string $sMessage, string $sSrcAddress) use ($oHandler) {
                $oHandler->receive($sMessage, $sSrcAddress);
            });
            $oHandler->setSocket($oTransport);
            return $oHandler;
        }

        $oDatagramFactory = new DatagramFactory($oLoop);
        $mServerPromise = $oDatagramFactory->createServer(
            config::getValueAsString('sharefs_listen_address') . ':' . $iPort
        );
        if ($mServerPromise instanceof \React\Promise\PromiseInterface) {
            $mServerPromise->then(function (mixed $mServer) use ($oHandler) {
                if (!$mServer instanceof \React\Datagram\Socket) {
                    return;
                }
                $mServer->on('message', function (string $sMessage, string $sSrcAddress) use ($oHandler) {
                    $oHandler->receive($sMessage, $sSrcAddress);
                });
                $oHandler->setSocket($mServer);
            });
        }
        return $oHandler;
    }

    /**
     * Sets up sharefsd's own admin web interface - a separate Symfony micro-app from
     * filestored's (see ShareFs\Admin\Kernel), listening on its own configured port so both
     * daemons' admin UIs can run side by side on the same host.
     */
    public function adminService(LoopInterface $oLoop): void
    {
        $oKernel = new ShareFsAdminKernel('prod', false);
        $oLogger = $this->oLogger;
        $callback = function (\Psr\Http\Message\ServerRequestInterface $oRequest) use ($oKernel, $oLogger) {
            $sMethod = $oRequest->getMethod();
            $aHeaders = $oRequest->getHeaders();
            $sContent = $oRequest->getBody();
            $oLogger->info('sharefsd admin page request ' . $oRequest->getUri()->getPath());

            $sfRequest = new \Symfony\Component\HttpFoundation\Request(
                $oRequest->getQueryParams(),
                [],
                [],
                $oRequest->getCookieParams(),
                $oRequest->getUploadedFiles(),
                [],
                $sContent
            );
            $sfRequest->setMethod($sMethod);
            $sfRequest->headers->replace($aHeaders);
            $sfRequest->server->set('REQUEST_URI', $oRequest->getUri()->getPath());
            if (isset($aHeaders['Host'])) {
                $sfRequest->server->set('SERVER_NAME', explode(':', (string) $aHeaders['Host'][0]));
            }

            try {
                $sfResponse = $oKernel->handle($sfRequest);
            } catch (NotFoundHttpException) {
                $oLogger->info('sharefsd admin page not found (' . $oRequest->getUri()->getPath() . ')');
                return new \React\Http\Message\Response(
                    404,
                    [],
                    'Page "' . $oRequest->getUri()->getPath() . '" not found.'
                );
            } catch (\Exception $oException) {
                $oLogger->info('Error: ' . $oException->getMessage());
                throw $oException;
            }
            $sResponseContent = $sfResponse->getContent();
            $aResponseHeaders = array_map(
                static fn(array $aValues): array => array_values(array_filter($aValues, static fn($mValue) => $mValue !== null)),
                $sfResponse->headers->all()
            );
            $oResponse = new \React\Http\Message\Response(
                200,
                $aResponseHeaders,
                $sResponseContent === false ? '' : $sResponseContent
            );
            $oKernel->terminate($sfRequest, $sfResponse);
            return $oResponse;
        };

        $oHttpSocket = new \React\Socket\Server(
            config::getValueAsString('sharefs_webadmin_listen_address') . ':' . config::getValueAsString('sharefs_webadmin_listen_port'),
            $oLoop
        );
        $oHttpServer = new \React\Http\HttpServer($callback);
        $oLogger->info('sharefsd admin service is running on ' . config::getValueAsString('sharefs_webadmin_listen_address') . ':' . config::getValueAsString('sharefs_webadmin_listen_port'));
        $oHttpServer->listen($oHttpSocket);
    }

    public function daemonize(string $sPidFile): void
    {
        $iPid = pcntl_fork();
        if ($iPid != 0) {
            if ($sPidFile != '') {
                file_put_contents($sPidFile, $iPid);
            }
            exit(0);
        } else {
            fclose(STDIN);
            fclose(STDOUT);
            fclose(STDERR);
        }
    }
}
