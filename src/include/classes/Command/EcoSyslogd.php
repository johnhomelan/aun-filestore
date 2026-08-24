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
use HomeLan\FileStore\Services\Provider\EcoSyslog;
use HomeLan\FileStore\RemoteProvider\Client as RemoteProviderClient;
use HomeLan\FileStore\RemoteProvider\Host as RemoteProviderHost;
use HomeLan\FileStore\RemoteProvider\Exceptions\AuthenticationFailedException;

use Monolog\Logger;
use Monolog\Handler\SyslogHandler;
use Monolog\Handler\SyslogUdpHandler;

use config;
use Exception;

/**
 * ecosyslogd is the sample Remote Provider Protocol host (see docs/protocols/remote-provider.md
 * and docs/protocols/ecosyslog.md): it hosts Services\Provider\EcoSyslog, an Econet version of
 * syslog, entirely unmodified from how it would run inside filestored itself - only the
 * transport underneath it (RemoteProvider\Client/Host instead of ServiceDispatcher's normal
 * PacketDispatcher/AUN/WebSocket path) differs. Like dnsd/ntpd it has no transport of its own and
 * always connects to a filestored instance over the relay.
 */
#[\Symfony\Component\Console\Attribute\AsCommand(name: 'ecosyslogd')]
class EcoSyslogd extends Command
{
    protected static string $defaultDescription = 'Start the EcoSyslog service';

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
            $this->oLogger->emergency('Un-able to initialize ecosyslogd, shutting down.');
            exit(1);
        }

        $this->MainLoop();
        return \Symfony\Component\Console\Command\Command::SUCCESS;
    }

    private function MainLoop(): void
    {
        $oLoop = ReactFactory::create();

        $this->oLogger->info('ecosyslogd: Using ' . $oLoop::class . ' as the primary event loop handler');

        // A logger dedicated to relayed log traffic, separate from $this->oLogger (which logs
        // this daemon's own lifecycle - "connected to relay", "reconnecting", etc.). Which
        // handlers end up on it is entirely what decides where received messages are stored.
        $oEcoLogger = new Logger('ecosyslog');
        if (config::getValueAsBool('ecosyslog_local_enabled')) {
            $oEcoLogger->pushHandler(new SyslogHandler('ecosyslog'));
        }
        if (config::getValueAsBool('ecosyslog_remote_enabled')) {
            $oEcoLogger->pushHandler(new SyslogUdpHandler(
                config::getValueAsString('ecosyslog_remote_host'),
                config::getValueAsInt('ecosyslog_remote_port'),
                $this->resolveFacility(config::getValueAsString('ecosyslog_remote_facility')),
            ));
        }

        $oServiceDispatcher = ServiceDispatcher::create($this->oLogger, [new EcoSyslog($oEcoLogger)]);

        $oRelayClient = new RemoteProviderClient(
            $oLoop,
            $this->oLogger,
            'ws://' . config::getValueAsString('ecosyslog_remote_provider_relay_address'),
            config::getValueAsString('ecosyslog_remote_provider_relay_secret')
        );
        $oHost = new RemoteProviderHost($oRelayClient, $oServiceDispatcher, $this->oLogger);
        $oRelayClient->connect();

        // Mirrors Command\React's own two timers: a 1-second drain for reply/unsolicited output
        // (see Host::flush()) and a separate, configurable-interval one for housekeeping tasks.
        $oLoop->addPeriodicTimer(1, function () use ($oHost) {
            $oHost->flush();
        });
        $oLoop->addPeriodicTimer(config::getValueAsFloat('housekeeping_interval'), function () use ($oServiceDispatcher) {
            $oServiceDispatcher->houseKeeping();
        });

        $this->oLogger->debug('ecosyslogd: Starting primary loop.');
        try {
            $oLoop->run();
        } catch (AuthenticationFailedException $oException) {
            $this->oLogger->emergency('ecosyslogd: ' . $oException->getMessage() . ', shutting down.');
            exit(1);
        }
    }

    /** Falls back to LOG_LOCAL0 if the configured name isn't a real syslog facility constant. */
    private function resolveFacility(string $sName): int
    {
        if (defined($sName)) {
            $mValue = constant($sName);
            if (is_int($mValue)) {
                return $mValue;
            }
        }
        $this->oLogger->warning("ecosyslogd: \"{$sName}\" is not a recognised syslog facility constant, using LOG_LOCAL0");
        return LOG_LOCAL0;
    }

    protected function configure(): void
    {
        $sHelp = <<<EOF
Start the EcoSyslog service
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
                'Causes ecosyslogd to daemonize and drop into the background, otherwise the process continues to run in the foreground'
            )->addOption(
                'pidfile',
                'p',
                InputOption::VALUE_OPTIONAL,
                'Cause ecosyslogd to write the PID of the deamonized process to a file'
            )->setHelp($sHelp);
    }

    public function sigHandler(int $iSigno): void
    {
        switch ($iSigno) {
            case SIGTERM:
                $this->oLogger->info('Shutting down ecosyslogd');
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
