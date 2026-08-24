<?php

namespace HomeLan\FileStore\Services\Provider;

use HomeLan\FileStore\Services\ProviderInterface;
use HomeLan\FileStore\Services\ServiceDispatcher;
use HomeLan\FileStore\Messages\EconetPacket;
use Psr\Log\LogLevel;

/**
 * An Econet version of syslog - the sample provider for the Remote Provider Protocol (see
 * docs/protocols/remote-provider.md and docs/protocols/ecosyslog.md). Any station can transmit a
 * unicast packet to port 0xB6 to log a message; there is no reply, exactly like real UDP syslog,
 * which this class was deliberately kept simple enough to demonstrate without needing any of the
 * ACK'd stream machinery FileServer-style providers need.
 *
 * Wire format of the packet data: byte 0 is a syslog severity (0=Emergency..7=Debug), the rest is
 * the message text.
 *
 * Where the message ends up (local OS syslog, a remote syslog collector, or both) is entirely a
 * property of which Monolog handlers are on the $oLogger this class is constructed with - see
 * Command\EcoSyslogd, which is the only place that decision is made.
 */
class EcoSyslog implements ProviderInterface
{
    private const int SERVICE_PORT = 0xB6;

    /** @var array<int,string> */
    private const array SEVERITY_MAP = [
        0 => LogLevel::EMERGENCY,
        1 => LogLevel::ALERT,
        2 => LogLevel::CRITICAL,
        3 => LogLevel::ERROR,
        4 => LogLevel::WARNING,
        5 => LogLevel::NOTICE,
        6 => LogLevel::INFO,
        7 => LogLevel::DEBUG,
    ];

    private const string DEFAULT_SEVERITY = LogLevel::INFO;

    public function __construct(private readonly \Psr\Log\LoggerInterface $oLogger)
    {
    }

    public function getName(): string
    {
        return 'EcoSyslog';
    }

    public function getAdminInterface(): ?AdminInterface
    {
        return null;
    }

    public function getServicePorts(): array
    {
        return [self::SERVICE_PORT];
    }

    public function registerService(ServiceDispatcher $oServiceDispatcher): void
    {
    }

    public function unicastPacketIn(EconetPacket $oPacket): void
    {
        $this->logPacket($oPacket);
    }

    public function broadcastPacketIn(EconetPacket $oPacket): void
    {
        $this->logPacket($oPacket);
    }

    private function logPacket(EconetPacket $oPacket): void
    {
        $sData = (string) $oPacket->getData();
        if ($sData === '') {
            return;
        }

        $iSeverity = ord($sData[0]);
        $sMessage = substr($sData, 1);
        $sLevel = self::SEVERITY_MAP[$iSeverity] ?? self::DEFAULT_SEVERITY;

        $this->oLogger->log($sLevel, $sMessage, [
            'network' => $oPacket->getSourceNetwork(),
            'station' => $oPacket->getSourceStation(),
        ]);
    }

    public function getReplies(): array
    {
        // Fire-and-forget, like UDP syslog itself - nothing is ever queued here.
        return [];
    }

    public function getJobs(): array
    {
        return [];
    }
}
