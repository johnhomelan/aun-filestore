<?php

namespace HomeLan\FileStore\RemoteSocket;

use Evenement\EventEmitter;
use React\Datagram\SocketInterface;

/**
 * Stands in for a React\Datagram\Socket wherever a ShareFS handler (FreewayHandler,
 * AccessPlusHandler, ShareFsHandler) is given one to send/receive on - see
 * docs/protocols/remote-socket.md. One instance represents a single local UDP port; a single
 * Client can own several, one per ShareFS/Access+/Freeway port.
 *
 * A handler only ever calls send($data, $remoteAddress) and listens for 'message' - both of
 * which this class supports, so no handler code needs to know whether it is talking to a real
 * socket or a relay.
 */
class RelayedUdpTransport extends EventEmitter implements SocketInterface
{
    /** @var array<string, string> "remoteAddr:remotePort" => localAddr, the interface a given peer's traffic arrived on */
    private array $aPeerLocalAddr = [];

    public function __construct(
        private readonly Client $oClient,
        private readonly int $iLocalPort,
    ) {
    }

    /**
     * Called by Client when a `data` frame for this port arrives from the relay server.
     */
    public function deliver(string $sLocalAddr, string $sRemoteAddr, int $iRemotePort, string $sPayload): void
    {
        $this->aPeerLocalAddr["{$sRemoteAddr}:{$iRemotePort}"] = $sLocalAddr;
        $this->emit('message', [$sPayload, "{$sRemoteAddr}:{$iRemotePort}", $this]);
    }

    /**
     * SocketInterface::send() leaves both parameters untyped, so this stays untyped too -
     * narrowing them would violate contravariance against the interface.
     *
     * @param mixed $data
     * @param mixed $remoteAddress
     */
    public function send($data, $remoteAddress = null): void
    {
        if (!is_string($data) || !is_string($remoteAddress) || !str_contains($remoteAddress, ':')) {
            return;
        }
        [$sRemoteAddr, $sRemotePortStr] = explode(':', $remoteAddress, 2);
        $sLocalAddr = $this->aPeerLocalAddr[$remoteAddress] ?? null;
        if ($sLocalAddr === null) {
            // No interface known for this peer yet (we have never received from them over this
            // transport) - mirrors IPv4::injectRelayReply()'s own silent drop on an unknown peer.
            return;
        }
        $this->oClient->sendData($sLocalAddr, $this->iLocalPort, $sRemoteAddr, (int) $sRemotePortStr, $data);
    }

    public function close(): void
    {
    }

    public function end(): void
    {
    }

    public function resume(): void
    {
    }

    public function pause(): void
    {
    }

    public function getLocalAddress(): ?string
    {
        return null;
    }

    public function getRemoteAddress(): ?string
    {
        return null;
    }
}
