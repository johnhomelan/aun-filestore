<?php

namespace HomeLan\FileStore\Command;
declare(ticks = 1);

include_once(__DIR__ . '/../../system.inc.php');

use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Command\Command;

use React\EventLoop\Factory as ReactFactory;

use HomeLan\FileStore\Services\ServiceDispatcher;
use HomeLan\FileStore\Services\Provider\SqlServer;
use HomeLan\FileStore\RemoteProvider\Client as RemoteProviderClient;
use HomeLan\FileStore\RemoteProvider\Host as RemoteProviderHost;
use HomeLan\FileStore\RemoteProvider\Exceptions\AuthenticationFailedException;

use config;
use Exception;

/**
 * sql-serverd hosts Services\Provider\SqlServer (see
 * docs/protocols/sql-server.md) over the Remote Provider Protocol (see
 * docs/protocols/remote-provider.md), structured identically to
 * ecosyslogd (docs/protocols/ecosyslog.md) - SqlServer runs entirely
 * unmodified from how it would run inside filestored itself; only the
 * transport underneath it (RemoteProvider\Client/Host instead of
 * ServiceDispatcher's normal PacketDispatcher/AUN/WebSocket path) differs.
 * Like ecosyslogd/dnsd/ntpd it has no transport of its own and always
 * connects to a filestored instance over the relay.
 */
#[\Symfony\Component\Console\Attribute\AsCommand(name: 'sql-serverd')]
class SqlServerd extends Command
{
    protected static string $defaultDescription = 'Start the SqlServer service';

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

        try {
            pcntl_signal(SIGCHLD, $this->sigHandler(...));
            pcntl_signal(SIGTERM, $this->sigHandler(...));
        } catch (Exception $oException) {
            $this->oLogger->debug($oException->getMessage());
            $this->oLogger->emergency('Un-able to initialize sql-serverd, shutting down.');
            exit(1);
        }

        $this->MainLoop();
        return \Symfony\Component\Console\Command\Command::SUCCESS;
    }

    private function MainLoop(): void
    {
        $oLoop = ReactFactory::create();

        $this->oLogger->info('sql-serverd: Using ' . $oLoop::class . ' as the primary event loop handler');

        $oServiceDispatcher = ServiceDispatcher::create($this->oLogger, [new SqlServer($this->oLogger)]);

        $oRelayClient = new RemoteProviderClient(
            $oLoop,
            $this->oLogger,
            'ws://' . config::getValueAsString('sql_serverd_remote_provider_relay_address'),
            config::getValueAsString('sql_serverd_remote_provider_relay_secret')
        );
        $oHost = new RemoteProviderHost($oRelayClient, $oServiceDispatcher, $this->oLogger);
        $oRelayClient->connect();

        // Mirrors Command\React's own two timers: a 1-second drain for reply/unsolicited output
        // (see Host::flush()) and a separate, configurable-interval one for housekeeping tasks
        // (SqlServer's own stale-connection sweep - see SqlServer::sweepStaleConnections()).
        $oLoop->addPeriodicTimer(1, function () use ($oHost) {
            $oHost->flush();
        });
        $oLoop->addPeriodicTimer(config::getValueAsFloat('housekeeping_interval'), function () use ($oServiceDispatcher) {
            $oServiceDispatcher->houseKeeping();
        });

        $this->oLogger->debug('sql-serverd: Starting primary loop.');
        try {
            $oLoop->run();
        } catch (AuthenticationFailedException $oException) {
            $this->oLogger->emergency('sql-serverd: ' . $oException->getMessage() . ', shutting down.');
            exit(1);
        }
    }

    protected function configure(): void
    {
        $sHelp = <<<EOF
Start the SqlServer service
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
                'Causes sql-serverd to daemonize and drop into the background, otherwise the process continues to run in the foreground'
            )->addOption(
                'pidfile',
                'p',
                InputOption::VALUE_OPTIONAL,
                'Cause sql-serverd to write the PID of the deamonized process to a file'
            )->setHelp($sHelp);
    }

    public function sigHandler(int $iSigno): void
    {
        switch ($iSigno) {
            case SIGTERM:
                $this->oLogger->info('Shutting down sql-serverd');
                break;
            case SIGCHLD:
            default:
                break;
        }
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
