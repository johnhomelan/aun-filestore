<?php

namespace HomeLan\FileStore\Ntp;

use HomeLan\FileStore\Ntp\Messages\NtpMessage;
use React\Datagram\SocketInterface;

/**
 * Answers NTP client requests using the host system clock as the time source - see
 * docs/protocols/ntp.md. Given its traffic source through setSocket(), exactly like Dns\Handler
 * - see docs/NTPD.md for why ntpd always receives its traffic over a Remote Socket Protocol
 * relay rather than a socket bound to a real UDP port.
 */
class Handler
{
    /**
     * log2 seconds - advertises roughly 15ms clock precision. Deliberately not a tighter value:
     * the reply's own timestamps are only as accurate as the moment PHP reads the system clock,
     * and the whole request/reply round trip already crosses a WebSocket relay and Econet.
     */
    private const PRECISION = -6;

    private SocketInterface $oSocket;

    public function __construct(
        private readonly \Psr\Log\LoggerInterface $oLogger,
        private readonly int $iStratum,
        private readonly string $sReferenceId,
    ) {
    }

    public function setSocket(SocketInterface $oSocket): void
    {
        $this->oSocket = $oSocket;
    }

    public function receive(string $sMessage, string $sSrcAddress): void
    {
        $fReceiveTime = microtime(true);

        try {
            $oRequest = NtpMessage::decodeRequest($sMessage);
        } catch (\Exception $oException) {
            $this->oLogger->debug("Ntp: dropping malformed request from {$sSrcAddress}: " . $oException->getMessage());
            return;
        }

        if ($oRequest->getMode() !== NtpMessage::MODE_CLIENT) {
            $this->oLogger->debug("Ntp: ignoring request from {$sSrcAddress} with mode {$oRequest->getMode()}");
            return;
        }

        $sResponse = $oRequest->encodeResponse($this->iStratum, $this->sReferenceId, self::PRECISION, $fReceiveTime, microtime(true));
        $this->oSocket->send($sResponse, $sSrcAddress);
    }
}
