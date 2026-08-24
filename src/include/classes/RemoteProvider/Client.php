<?php

namespace HomeLan\FileStore\RemoteProvider;

use HomeLan\FileStore\RemoteProvider\Messages\Frame;
use HomeLan\FileStore\RemoteProvider\Exceptions\AuthenticationFailedException;
use HomeLan\FileStore\Messages\EconetPacket;
use Evenement\EventEmitter;
use Ratchet\Client\Connector;
use Ratchet\Client\WebSocket;
use Ratchet\RFC6455\Messaging\MessageInterface;
use React\EventLoop\LoopInterface;
use React\Promise\Deferred;
use React\Promise\PromiseInterface;

/**
 * The connecting side of the Remote Provider Protocol (see docs/protocols/remote-provider.md) -
 * used by a standalone provider host (e.g. ecosyslogd) to receive Econet packets relayed from
 * ports it has registered on filestored's ServiceDispatcher, and send replies (or unsolicited
 * output) back through it. Reconnects automatically if the connection to the relay server drops,
 * exactly like RemoteSocket\Client one layer down.
 *
 * Emits a 'packet' event (string $sKind, EconetPacket $oPacket) for each `packet` frame received,
 * and an 'ack' event (int $iNetwork, int $iStation, ?int $iSeq) for each `ack` frame - Host
 * subscribes to both to dispatch into the local ServiceDispatcher.
 *
 * claimStreamPort() is this protocol's only request/response exchange - every other frame is
 * fire-and-forget - so it is the only place Client needs to correlate a reply frame back to a
 * specific caller, via a requestId => Deferred table.
 *
 * If the relay server rejects the shared secret, handleFrame() throws
 * AuthenticationFailedException - since that happens from inside the event loop's own dispatch
 * (a WebSocket 'message' event), the exception propagates out through the caller's
 * $oLoop->run(), not out of connect() itself. Callers should wrap $oLoop->run() accordingly.
 */
class Client extends EventEmitter
{
    private const RECONNECT_DELAY_SECONDS = 5;

    private bool $bAuthenticated = false;

    /** @var list<int> */
    private array $aDesiredPorts = [];

    /**
     * requestId => the claimStreamPort() call awaiting a reply, and the timer that gives up on
     * it if nothing comes back. requestId is generated as "req-<n>", not a bare number - a
     * purely numeric string key would be silently normalised to an int by PHP's own array key
     * coercion, which this docblock's type would then no longer accurately describe.
     *
     * @var array<string, array{deferred: Deferred<int>, timer: \React\EventLoop\TimerInterface}>
     */
    private array $aPendingStreamClaims = [];

    private int $iNextRequestId = 0;

    private ?WebSocket $oConnection = null;

    public function __construct(
        private readonly LoopInterface $oLoop,
        private readonly \Psr\Log\LoggerInterface $oLogger,
        private readonly string $sUrl,
        private readonly string $sSecret,
    ) {
    }

    public function connect(): void
    {
        $oConnector = new Connector($this->oLoop);
        $oConnector->__invoke($this->sUrl)->then(
            function (mixed $mConnection): void {
                if (!$mConnection instanceof WebSocket) {
                    return;
                }
                $this->oConnection = $mConnection;
                $mConnection->on('message', function (MessageInterface $oMessage): void {
                    $this->handleFrame((string) $oMessage);
                });
                $mConnection->on('close', function (): void {
                    $this->onDisconnected();
                });
                $this->rawSend(Frame::hello($this->sSecret)->encode());
            },
            function (\Throwable $oError): void {
                $this->oLogger->warning('RemoteProvider\Client: connection to ' . $this->sUrl . ' failed: ' . $oError->getMessage());
                $this->scheduleReconnect();
            }
        );
    }

    private function onDisconnected(): void
    {
        $this->oLogger->warning('RemoteProvider\Client: disconnected from ' . $this->sUrl);
        $this->oConnection = null;
        $this->bAuthenticated = false;

        // A stream claim can't survive a reconnect - the port, if one was even allocated before
        // the drop, may already have gone to someone else by the time we're back. Reject rather
        // than leave the caller waiting forever.
        foreach ($this->aPendingStreamClaims as $aPending) {
            $this->oLoop->cancelTimer($aPending['timer']);
            $aPending['deferred']->reject(new \Exception('RemoteProvider\Client: disconnected while awaiting a stream claim'));
        }
        $this->aPendingStreamClaims = [];

        $this->scheduleReconnect();
    }

    private function scheduleReconnect(): void
    {
        $this->oLoop->addTimer(self::RECONNECT_DELAY_SECONDS, function (): void {
            $this->connect();
        });
    }

    /**
     * Declares the Econet ports this host wants relayed. Safe to call before the connection to
     * the relay server is established - registration is (re-)sent on every successful handshake,
     * see onHelloOk().
     *
     * @param list<int> $aPorts
     */
    public function registerPorts(array $aPorts): void
    {
        $this->aDesiredPorts = array_values(array_unique([...$this->aDesiredPorts, ...$aPorts]));
        if ($this->bAuthenticated) {
            $this->sendRegister();
        }
    }

    private function sendRegister(): void
    {
        if ($this->aDesiredPorts !== []) {
            $this->rawSend(Frame::register($this->aDesiredPorts)->encode());
        }
    }

    public function sendPacket(string $sKind, EconetPacket $oPacket): void
    {
        $this->rawSend(Frame::packet(
            $sKind,
            (int) $oPacket->getSourceNetwork(),
            (int) $oPacket->getSourceStation(),
            $oPacket->getDestinationNetwork(),
            $oPacket->getDestinationStation(),
            $oPacket->getPort(),
            $oPacket->getFlags(),
            (string) $oPacket->getData(),
        )->encode());
    }

    /**
     * Asks the relay server to claim a stream port on this connection's behalf (see
     * ServiceDispatcher::claimStreamPort() and docs/protocols/remote-provider.md § Stream
     * Claims). Resolves with the allocated port, or rejects if the server has none free, the
     * connection drops before a reply arrives, or nothing came back within $iTimeout seconds -
     * that timeout only bounds this call; the port itself, once claimed, has its own separate
     * lease that Host re-binds via ServiceDispatcher::bindStreamPort().
     */
    /** @return PromiseInterface<int> */
    public function claimStreamPort(int $iTimeout = 60): PromiseInterface
    {
        $sRequestId = 'req-' . $this->iNextRequestId++;
        /** @var Deferred<int> $oDeferred */
        $oDeferred = new Deferred();

        $oTimer = $this->oLoop->addTimer($iTimeout, function () use ($sRequestId): void {
            $aPending = $this->aPendingStreamClaims[$sRequestId] ?? null;
            if ($aPending !== null) {
                unset($this->aPendingStreamClaims[$sRequestId]);
                $aPending['deferred']->reject(new \Exception('RemoteProvider\Client: timed out waiting for a stream claim reply'));
            }
        });
        $this->aPendingStreamClaims[$sRequestId] = ['deferred' => $oDeferred, 'timer' => $oTimer];

        $this->rawSend(Frame::claimStream($sRequestId, $iTimeout)->encode());
        return $oDeferred->promise();
    }

    protected function rawSend(string $sEncoded): void
    {
        $this->oConnection?->send($sEncoded);
    }

    public function handleFrame(string $sJson): void
    {
        try {
            $oFrame = Frame::decode($sJson);
        } catch (\Exception $oException) {
            $this->oLogger->warning('RemoteProvider\Client: discarding malformed frame: ' . $oException->getMessage());
            return;
        }

        match ($oFrame->getType()) {
            Frame::TYPE_HELLO_OK => $this->onHelloOk(),
            Frame::TYPE_VERSION_REJECT => $this->oLogger->emergency('RemoteProvider\Client: server rejected our protocol version'),
            Frame::TYPE_AUTH_FAIL => throw new AuthenticationFailedException('RemoteProvider\Client: server rejected our shared secret'),
            Frame::TYPE_REGISTER_OK => $this->oLogger->info("RemoteProvider\Client: registered port {$oFrame->getPort()}"),
            Frame::TYPE_REGISTER_FAIL => $this->oLogger->warning("RemoteProvider\Client: registration for port {$oFrame->getPort()} failed: {$oFrame->getReason()}"),
            Frame::TYPE_PACKET => $this->onPacket($oFrame),
            Frame::TYPE_STREAM_CLAIMED => $this->onStreamClaimed($oFrame),
            Frame::TYPE_STREAM_CLAIM_FAILED => $this->onStreamClaimFailed($oFrame),
            Frame::TYPE_ACK => $this->emit('ack', [$oFrame->getNet(), $oFrame->getStn(), $oFrame->getSeq()]),
            Frame::TYPE_PONG => null,
            default => $this->oLogger->debug("RemoteProvider\Client: ignoring frame type \"{$oFrame->getType()}\""),
        };
    }

    private function onHelloOk(): void
    {
        $this->bAuthenticated = true;
        $this->sendRegister();
    }

    private function onPacket(Frame $oFrame): void
    {
        $oPacket = new EconetPacket();
        $oPacket->setSourceNetwork($oFrame->getSrcNet());
        $oPacket->setSourceStation($oFrame->getSrcStn());
        $oPacket->setDestinationNetwork($oFrame->getDstNet());
        $oPacket->setDestinationStation($oFrame->getDstStn());
        $oPacket->setPort($oFrame->getPort());
        $oPacket->setFlags($oFrame->getFlags());
        $oPacket->setData($oFrame->getPayload());
        $this->emit('packet', [$oFrame->getKind(), $oPacket]);
    }

    private function onStreamClaimed(Frame $oFrame): void
    {
        $this->settleStreamClaim($oFrame->getRequestId(), function (Deferred $oDeferred) use ($oFrame): void {
            $oDeferred->resolve($oFrame->getPort());
        });
    }

    private function onStreamClaimFailed(Frame $oFrame): void
    {
        $this->settleStreamClaim($oFrame->getRequestId(), function (Deferred $oDeferred) use ($oFrame): void {
            $oDeferred->reject(new \Exception("RemoteProvider\\Client: stream claim failed: {$oFrame->getReason()}"));
        });
    }

    private function settleStreamClaim(string $sRequestId, callable $fSettle): void
    {
        $aPending = $this->aPendingStreamClaims[$sRequestId] ?? null;
        if ($aPending === null) {
            // Already timed out (or a reply for a request we never made - a wire bug on the
            // server's side) - nothing to settle.
            return;
        }
        unset($this->aPendingStreamClaims[$sRequestId]);
        $this->oLoop->cancelTimer($aPending['timer']);
        $fSettle($aPending['deferred']);
    }
}
