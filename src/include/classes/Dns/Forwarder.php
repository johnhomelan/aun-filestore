<?php

namespace HomeLan\FileStore\Dns;

use React\Datagram\Factory as DatagramFactory;
use React\Datagram\SocketInterface;
use React\EventLoop\LoopInterface;
use React\EventLoop\TimerInterface;
use React\Promise\Deferred;
use React\Promise\PromiseInterface;

use function React\Promise\reject;

/**
 * Forwards a query HostsFile can't answer to an external DNS server, asynchronously over the
 * ReactPHP event loop - see docs/protocols/dns.md → "Forwarding to an external server". One
 * outbound UDP association to the configured upstream is kept open for the daemon's lifetime;
 * concurrent forwarded queries are demultiplexed by a transaction ID this class assigns itself,
 * independent of whichever ID the original Econet client picked - two different clients
 * choosing the same ID can never collide upstream.
 */
class Forwarder
{
    private ?SocketInterface $oSocket = null;

    /** @var array<int, array{deferred: Deferred<mixed>, timer: TimerInterface}> our own forwarding id => pending state */
    private array $aPending = [];

    private int $iNextId = 0;

    public function __construct(
        private readonly LoopInterface $oLoop,
        private readonly \Psr\Log\LoggerInterface $oLogger,
        string $sUpstreamAddress,
        private readonly float $fTimeout,
    ) {
        $mPromise = $this->createUpstreamConnectionPromise($sUpstreamAddress);
        if (!$mPromise instanceof PromiseInterface) {
            return;
        }
        $mPromise->then(
            function (mixed $mSocket): void {
                if (!$mSocket instanceof SocketInterface) {
                    return;
                }
                $this->oSocket = $mSocket;
                $mSocket->on('message', function (string $sMessage): void {
                    $this->handleReply($sMessage);
                });
            },
            function (\Throwable $oError) use ($sUpstreamAddress): void {
                $this->oLogger->warning("Dns\\Forwarder: unable to connect to upstream {$sUpstreamAddress}: " . $oError->getMessage());
            }
        );
    }

    /**
     * react/datagram's Factory::createClient() has no declared return type, so this can't be
     * typed any more strongly than mixed without asserting something PHPStan can't verify -
     * the instanceof checks above and in tests do the real narrowing.
     */
    protected function createUpstreamConnectionPromise(string $sUpstreamAddress): mixed
    {
        return (new DatagramFactory($this->oLoop))->createClient($sUpstreamAddress);
    }

    /**
     * Forwards a raw query packet, exactly as received from the client, to the upstream
     * server. Resolves with the raw response packet - still carrying this class's own
     * forwarding id in its first two bytes, not the original client's query id, it's the
     * caller's job to put that back before relaying the response on - or rejects if there's no
     * upstream connection, or it doesn't reply within the configured timeout.
     *
     * @return PromiseInterface<mixed>
     */
    public function forward(string $sQueryPacket): PromiseInterface
    {
        $oSocket = $this->oSocket;
        if ($oSocket === null) {
            return reject(new \Exception('Dns\Forwarder: no upstream connection'));
        }
        if (strlen($sQueryPacket) < 2) {
            return reject(new \Exception('Dns\Forwarder: query packet too short to forward'));
        }

        $iForwardId = $this->allocateId();
        $sUpstreamPacket = pack('n', $iForwardId) . substr($sQueryPacket, 2);

        $oDeferred = new Deferred();
        $oTimer = $this->oLoop->addTimer($this->fTimeout, function () use ($iForwardId): void {
            $this->fail($iForwardId, 'timed out waiting for a reply');
        });
        $this->aPending[$iForwardId] = ['deferred' => $oDeferred, 'timer' => $oTimer];

        $oSocket->send($sUpstreamPacket);

        return $oDeferred->promise();
    }

    private function allocateId(): int
    {
        do {
            $iId = $this->iNextId;
            $this->iNextId = ($this->iNextId + 1) & 0xFFFF;
        } while (array_key_exists($iId, $this->aPending));
        return $iId;
    }

    private function handleReply(string $sMessage): void
    {
        if (strlen($sMessage) < 2) {
            return;
        }
        $aId = unpack('nid', substr($sMessage, 0, 2));
        $iId = $aId !== false && is_int($aId['id']) ? $aId['id'] : -1;

        $aEntry = $this->aPending[$iId] ?? null;
        if ($aEntry === null) {
            return; // late, or duplicate, reply for something we've already timed out
        }

        $this->oLoop->cancelTimer($aEntry['timer']);
        unset($this->aPending[$iId]);
        $aEntry['deferred']->resolve($sMessage);
    }

    private function fail(int $iId, string $sReason): void
    {
        $aEntry = $this->aPending[$iId] ?? null;
        if ($aEntry === null) {
            return;
        }
        unset($this->aPending[$iId]);
        $aEntry['deferred']->reject(new \Exception("Dns\\Forwarder: {$sReason}"));
    }
}
