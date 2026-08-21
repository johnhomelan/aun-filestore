<?php

namespace HomeLan\FileStore\Command;
declare(ticks = 1);

include_once(__DIR__ . '/../../system.inc.php');

use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Command\Command;

use React\EventLoop\Factory as ReactFactory;

use HomeLan\FileStore\Ntp\Handler as NtpHandler;
use HomeLan\FileStore\RemoteSocket\Client as RemoteSocketClient;
use HomeLan\FileStore\RemoteSocket\Exceptions\AuthenticationFailedException;

use config;
use Exception;

/**
 * ntpd is a standalone NTP server that answers client requests from the host system clock (see
 * docs/protocols/ntp.md). Like dnsd, it has no transport of its own: it always receives its
 * traffic over a Remote Socket Protocol connection to a filestored instance's EconetA interface
 * (see docs/protocols/remote-socket.md and docs/NTPD.md).
 */
#[\Symfony\Component\Console\Attribute\AsCommand(name: 'ntpd')]
class Ntpd extends Command
{
    protected static string $defaultDescription = 'Start the NTP service';

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
            $this->oLogger->emergency('Un-able to initialize ntpd, shutting down.');
            exit(1);
        }

        $this->MainLoop();
        return \Symfony\Component\Console\Command\Command::SUCCESS;
    }

    private function MainLoop(): void
    {
        $oLoop = ReactFactory::create();

        $this->oLogger->info('ntpd: Using ' . $oLoop::class . ' as the primary event loop handler');

        $oHandler = new NtpHandler(
            $this->oLogger,
            config::getValueAsInt('ntp_stratum'),
            config::getValueAsString('ntp_reference_id')
        );

        $oRelayClient = new RemoteSocketClient(
            $oLoop,
            $this->oLogger,
            'ws://' . config::getValueAsString('ntp_remote_socket_relay_address'),
            config::getValueAsString('ntp_remote_socket_relay_secret')
        );
        $oRelayClient->connect();

        $oTransport = $oRelayClient->getTransport(config::getValueAsInt('ntp_port'));
        $oTransport->on('message', function (string $sMessage, string $sSrcAddress) use ($oHandler) {
            $oHandler->receive($sMessage, $sSrcAddress);
        });
        $oHandler->setSocket($oTransport);

        $this->oLogger->debug('ntpd: Starting primary loop.');
        try {
            $oLoop->run();
        } catch (AuthenticationFailedException $oException) {
            $this->oLogger->emergency('ntpd: ' . $oException->getMessage() . ', shutting down.');
            exit(1);
        }
    }

    protected function configure(): void
    {
        $sHelp = <<<EOF
Start the NTP service
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
                'Causes ntpd to daemonize and drop into the background, otherwise the process continues to run in the foreground'
            )->addOption(
                'pidfile',
                'p',
                InputOption::VALUE_OPTIONAL,
                'Cause ntpd to write the PID of the deamonized process to a file'
            )->setHelp($sHelp);
    }

    public function sigHandler(int $iSigno): void
    {
        switch ($iSigno) {
            case SIGTERM:
                $this->oLogger->info('Shutting down ntpd');
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
