<?php

declare(strict_types=1);

namespace HomeLan\FileStore\Logging;

use Psr\Log\LoggerInterface;

/**
 * PSR-3 logger that writes to the OS syslog for the TypePHP (packaging/typephp)
 * build of ecosyslogd.
 *
 * The interpreted ecosyslogd builds a Monolog\Logger with SyslogHandler /
 * SyslogUdpHandler (see Command\EcoSyslogd) and hands it to
 * Services\Provider\EcoSyslog. Monolog is not on the compile path, so the native
 * binary passes one of these instead. It honours:
 *
 *   ecosyslog_local_enabled   - openlog()/syslog() to the local daemon
 *   ecosyslog_remote_enabled  - a plain RFC 3164 UDP line to
 *   ecosyslog_remote_host / _port / _facility
 *
 * Method signatures match the vendored psr/log (untyped $message, `array()`
 * default) exactly - TypePHP enforces LSP strictly.
 *
 * Compile-only: never autoloaded by the interpreted runtime.
 */
final class SyslogLogger implements LoggerInterface
{
    /** PSR-3 level => syslog priority (RFC 5424 severity). */
    private const PRIORITY = [
        'emergency' => \LOG_EMERG,
        'alert'     => \LOG_ALERT,
        'critical'  => \LOG_CRIT,
        'error'     => \LOG_ERR,
        'warning'   => \LOG_WARNING,
        'notice'    => \LOG_NOTICE,
        'info'      => \LOG_INFO,
        'debug'     => \LOG_DEBUG,
    ];

    /** Facility name (LOG_LOCAL0 ...) => constant, for the local openlog(). */
    private const FACILITY = [
        'LOG_USER'   => \LOG_USER,
        'LOG_DAEMON' => \LOG_DAEMON,
        'LOG_LOCAL0' => \LOG_LOCAL0,
        'LOG_LOCAL1' => \LOG_LOCAL1,
        'LOG_LOCAL2' => \LOG_LOCAL2,
        'LOG_LOCAL3' => \LOG_LOCAL3,
        'LOG_LOCAL4' => \LOG_LOCAL4,
        'LOG_LOCAL5' => \LOG_LOCAL5,
        'LOG_LOCAL6' => \LOG_LOCAL6,
        'LOG_LOCAL7' => \LOG_LOCAL7,
    ];

    private bool $bLocal;
    private bool $bRemote;
    private string $sIdent;
    private int $iFacility;
    private string $sRemoteHost;
    private int $iRemotePort;
    /** @var resource|null */
    private $rRemote = null;

    public function __construct(string $sIdent = 'ecosyslog')
    {
        $this->sIdent      = $sIdent;
        $this->bLocal      = \config::getValueAsBool('ecosyslog_local_enabled');
        $this->bRemote     = \config::getValueAsBool('ecosyslog_remote_enabled');
        $sFacility         = \config::getValueAsString('ecosyslog_remote_facility');
        $this->iFacility   = self::FACILITY[$sFacility] ?? \LOG_LOCAL0;
        $this->sRemoteHost = \config::getValueAsString('ecosyslog_remote_host');
        $this->iRemotePort = \config::getValueAsInt('ecosyslog_remote_port');

        if ($this->bLocal) {
            \openlog($this->sIdent, \LOG_PID, $this->iFacility);
        }
        if ($this->bRemote && $this->sRemoteHost !== '') {
            $mSock = @\stream_socket_client('udp://' . $this->sRemoteHost . ':' . $this->iRemotePort);
            $this->rRemote = $mSock === false ? null : $mSock;
        }
    }

    public function emergency($message, array $context = array()): void { $this->log('emergency', $message, $context); }
    public function alert($message, array $context = array()): void     { $this->log('alert', $message, $context); }
    public function critical($message, array $context = array()): void  { $this->log('critical', $message, $context); }
    public function error($message, array $context = array()): void     { $this->log('error', $message, $context); }
    public function warning($message, array $context = array()): void   { $this->log('warning', $message, $context); }
    public function notice($message, array $context = array()): void    { $this->log('notice', $message, $context); }
    public function info($message, array $context = array()): void      { $this->log('info', $message, $context); }
    public function debug($message, array $context = array()): void     { $this->log('debug', $message, $context); }

    public function log($level, $message, array $context = array()): void
    {
        $sLevel    = \is_string($level) ? $level : 'info';
        $iPriority = self::PRIORITY[$sLevel] ?? \LOG_INFO;
        $sText     = (string) $message;

        if ($this->bLocal) {
            \syslog($iPriority, $sText);
        }
        if ($this->rRemote !== null) {
            // RFC 3164: <PRI>Mmm dd hh:mm:ss host ident: message
            $iPri = $this->iFacility + $iPriority;
            $sLine = '<' . $iPri . '>' . \date('M j H:i:s') . ' ' . \gethostname() . ' ' . $this->sIdent . ': ' . $sText;
            @\fwrite($this->rRemote, $sLine);
        }
    }
}
