<?php

namespace HomeLan\FileStore\Command;
declare(ticks = 1);

include_once(__DIR__ . '/../../system.inc.php');

use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Command\Command;

use React\EventLoop\Factory as ReactFactory;

use HomeLan\FileStore\Dns\HostsFile;
use HomeLan\FileStore\Dns\Handler as DnsHandler;
use HomeLan\FileStore\Dns\Forwarder as DnsForwarder;
use HomeLan\FileStore\Dns\DomainFilter;
use HomeLan\FileStore\RemoteSocket\Client as RemoteSocketClient;
use HomeLan\FileStore\RemoteSocket\Exceptions\AuthenticationFailedException;

use config;
use Exception;

/**
 * dnsd is a standalone DNS server that answers queries from a Unix-style hosts file, optionally
 * forwarding whatever it can't answer to an external DNS server (see docs/protocols/dns.md). It
 * has no transport of its own: unlike sharefsd, which can either bind real UDP sockets or use a
 * relay, dnsd always receives its traffic over a Remote Socket Protocol connection to a
 * filestored instance's EconetA interface (see docs/protocols/remote-socket.md and
 * docs/DNSD.md) - unrestricted UDP port 53 is meaningless for a server that only ever exists to
 * answer Econet clients anyway.
 */
#[\Symfony\Component\Console\Attribute\AsCommand(name: 'dnsd')]
class Dnsd extends Command
{
    protected static string $defaultDescription = 'Start the DNS service';

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

        HostsFile::init($this->oLogger);

        try {
            pcntl_signal(SIGCHLD, $this->sigHandler(...));
            pcntl_signal(SIGTERM, $this->sigHandler(...));
        } catch (Exception $oException) {
            $this->oLogger->debug($oException->getMessage());
            $this->oLogger->emergency('Un-able to initialize dnsd, shutting down.');
            exit(1);
        }

        $this->MainLoop();
        return \Symfony\Component\Console\Command\Command::SUCCESS;
    }

    private function MainLoop(): void
    {
        $oLoop = ReactFactory::create();

        $this->oLogger->info('dnsd: Using ' . $oLoop::class . ' as the primary event loop handler');

        $oForwarder = null;
        $oDomainFilter = null;
        if (config::getValueAsBool('dns_forwarder_enabled')) {
            $oForwarder = new DnsForwarder(
                $oLoop,
                $this->oLogger,
                config::getValueAsString('dns_forwarder_address'),
                config::getValueAsFloat('dns_forwarder_timeout')
            );
            $oDomainFilter = new DomainFilter(config::getValueAsString('dns_forwarder_allowed_domains'));
        }

        $oHandler = new DnsHandler($this->oLogger, $oForwarder, $oDomainFilter);

        $oRelayClient = new RemoteSocketClient(
            $oLoop,
            $this->oLogger,
            'ws://' . config::getValueAsString('dns_remote_socket_relay_address'),
            config::getValueAsString('dns_remote_socket_relay_secret')
        );
        $oRelayClient->connect();

        $oTransport = $oRelayClient->getTransport(config::getValueAsInt('dns_port'));
        $oTransport->on('message', function (string $sMessage, string $sSrcAddress) use ($oHandler) {
            $oHandler->receive($sMessage, $sSrcAddress);
        });
        $oHandler->setSocket($oTransport);

        $this->oLogger->debug('dnsd: Starting primary loop.');
        try {
            $oLoop->run();
        } catch (AuthenticationFailedException $oException) {
            $this->oLogger->emergency('dnsd: ' . $oException->getMessage() . ', shutting down.');
            exit(1);
        }
    }

    protected function configure(): void
    {
        $sHelp = <<<EOF
Start the DNS service
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
                'Causes dnsd to daemonize and drop into the background, otherwise the process continues to run in the foreground'
            )->addOption(
                'pidfile',
                'p',
                InputOption::VALUE_OPTIONAL,
                'Cause dnsd to write the PID of the deamonized process to a file'
            )->setHelp($sHelp);
    }

    public function sigHandler(int $iSigno): void
    {
        switch ($iSigno) {
            case SIGTERM:
                $this->oLogger->info('Shutting down dnsd');
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
