<?php

namespace HomeLan\FileStore\RemoteProvider;

use HomeLan\FileStore\RemoteProvider\Messages\Frame;
use HomeLan\FileStore\RemoteProvider\AckRelayMap;
use HomeLan\FileStore\Messages\EconetPacket;
use Ratchet\MessageComponentInterface;
use Ratchet\ConnectionInterface;

/**
 * The server (listening) side of the Remote Provider Protocol - see
 * docs/protocols/remote-provider.md. Accepts WebSocket connections from remote provider hosts,
 * authenticates them via a shared secret, and lets them register interest in individual Econet
 * port numbers out of the superset Services\Provider\ProxyProvider has statically reserved.
 *
 * `relayInbound()` is called by ProxyProvider when ServiceDispatcher routes it a packet, to
 * forward it out to whichever connection is registered for that port; a `packet` frame received
 * back from a connection is always a reply (or unsolicited/async output) from the remote
 * provider, handed to the $fInjectReply callback to be queued onto ProxyProvider's own reply
 * buffer exactly as if it had built the EconetPacket itself.
 *
 * Only one connection may be registered for a given port at a time - a second register for an
 * already-claimed port gets register_fail, matching ServiceDispatcher::addService()'s own "port
 * already in use" behaviour for local port claims, and RemoteSocket\RelayServer's identical rule
 * one layer down.
 *
 * `claim_stream` frames (see docs/protocols/remote-provider.md § Stream Claims) let a connection
 * ask for a dynamically-allocated stream port via the $fClaimStreamPort callback, registered on
 * success exactly like an explicit `register`; every `packet` frame relayed out is also
 * remembered in AckRelayMap so a real ack the connection's send provokes can be relayed back as
 * an `ack` frame (see docs/protocols/remote-provider.md § Ack Relay).
 */
class RelayServer implements MessageComponentInterface
{
    /** @var array<int, ConnectionInterface> port => connection */
    private array $aRegistrations = [];

    /** @var array<int, array{authenticated:bool, ports:list<int>}> spl_object_id(connection) => state */
    private array $aConnectionState = [];

    /**
     * @param callable(EconetPacket $oPacket): void $fInjectReply
     * @param callable(int $iTimeout): ?int $fClaimStreamPort Claims a stream port on the local
     *        ServiceDispatcher on the caller's behalf (see docs/protocols/remote-provider.md §
     *        Stream Claims) - returns the allocated port, or null if none were free.
     */
    public function __construct(
        private readonly \Psr\Log\LoggerInterface $oLogger,
        private readonly string $sSecret,
        private readonly mixed $fInjectReply,
        private readonly mixed $fClaimStreamPort,
    ) {
    }

    public function onOpen(ConnectionInterface $oConnection): void
    {
        $this->aConnectionState[spl_object_id($oConnection)] = ['authenticated' => false, 'ports' => []];
    }

    public function onMessage(ConnectionInterface $oConnection, $sMessage): void
    {
        try {
            $oFrame = Frame::decode((string) $sMessage);
        } catch (\Exception $oException) {
            $this->oLogger->warning('RemoteProvider: discarding malformed frame: ' . $oException->getMessage());
            return;
        }

        $iId = spl_object_id($oConnection);
        $aState = $this->aConnectionState[$iId] ?? null;
        if ($aState === null) {
            return;
        }

        if (!$aState['authenticated']) {
            if ($oFrame->getType() === Frame::TYPE_HELLO) {
                $this->handleHello($oConnection, $oFrame);
            }
            return;
        }

        match ($oFrame->getType()) {
            Frame::TYPE_REGISTER     => $this->handleRegister($oConnection, $oFrame),
            Frame::TYPE_PACKET       => $this->handlePacket($oConnection, $oFrame),
            Frame::TYPE_CLAIM_STREAM => $this->handleClaimStream($oConnection, $oFrame),
            Frame::TYPE_PING         => $oConnection->send(Frame::pong()->encode()),
            default                  => $this->oLogger->debug("RemoteProvider: ignoring frame type \"{$oFrame->getType()}\""),
        };
    }

    private function handleHello(ConnectionInterface $oConnection, Frame $oFrame): void
    {
        if (!in_array(Frame::PROTOCOL_VERSION, $oFrame->getVersions(), true)) {
            $oConnection->send(Frame::versionReject([Frame::PROTOCOL_VERSION])->encode());
            $oConnection->close();
            return;
        }
        if (!hash_equals($this->sSecret, $oFrame->getSecret())) {
            $this->oLogger->warning('RemoteProvider: rejecting connection with an incorrect secret');
            $oConnection->send(Frame::authFail()->encode());
            $oConnection->close();
            return;
        }

        $this->aConnectionState[spl_object_id($oConnection)]['authenticated'] = true;
        $oConnection->send(Frame::helloOk(Frame::PROTOCOL_VERSION)->encode());
    }

    private function handleRegister(ConnectionInterface $oConnection, Frame $oFrame): void
    {
        $iId = spl_object_id($oConnection);
        foreach ($oFrame->getPorts() as $iPort) {
            if (isset($this->aRegistrations[$iPort])) {
                $oConnection->send(Frame::registerFail($iPort, 'port already in use')->encode());
                continue;
            }
            $this->aRegistrations[$iPort] = $oConnection;
            $this->aConnectionState[$iId]['ports'][] = $iPort;
            $this->oLogger->info("RemoteProvider: registered port {$iPort}");
            $oConnection->send(Frame::registerOk($iPort)->encode());
        }
    }

    private function handlePacket(ConnectionInterface $oConnection, Frame $oFrame): void
    {
        $oPacket = self::frameToPacket($oFrame);

        // Broadcasts (destination station 255) don't get acked by any single station, so
        // there is nothing to remember for AckRelayMap - see docs/protocols/remote-provider.md
        // § Ack Relay.
        if ($oPacket->getDestinationStation() !== 255) {
            AckRelayMap::rememberAckRelay($oPacket->getDestinationNetwork(), $oPacket->getDestinationStation(), $oConnection, $oPacket->getSequence());
        }

        ($this->fInjectReply)($oPacket);
    }

    private function handleClaimStream(ConnectionInterface $oConnection, Frame $oFrame): void
    {
        $iPort = ($this->fClaimStreamPort)($oFrame->getTimeout());
        if ($iPort === null) {
            $oConnection->send(Frame::streamClaimFailed($oFrame->getRequestId(), 'no free stream ports')->encode());
            return;
        }

        $this->aRegistrations[$iPort] = $oConnection;
        $this->aConnectionState[spl_object_id($oConnection)]['ports'][] = $iPort;
        $this->oLogger->info("RemoteProvider: claimed stream port {$iPort}");
        $oConnection->send(Frame::streamClaimed($oFrame->getRequestId(), $iPort)->encode());
    }

    /**
     * Drops a stream port's registration once ProxyProvider notices ServiceDispatcher's own
     * expiry (houseKeeping()) has freed it - see docs/protocols/remote-provider.md § Stream
     * Claims. Without this, a later claim reusing the same port number could otherwise route to
     * the stale connection that held it before.
     */
    public function releaseStreamPort(int $iPort): void
    {
        unset($this->aRegistrations[$iPort]);
    }

    public function onClose(ConnectionInterface $oConnection): void
    {
        $iId = spl_object_id($oConnection);
        $aState = $this->aConnectionState[$iId] ?? null;
        if ($aState !== null) {
            foreach ($aState['ports'] as $iPort) {
                unset($this->aRegistrations[$iPort]);
            }
        }
        unset($this->aConnectionState[$iId]);
    }

    public function onError(ConnectionInterface $oConnection, \Exception $oException): void
    {
        $this->oLogger->warning('RemoteProvider: connection error: ' . $oException->getMessage());
        $oConnection->close();
    }

    /**
     * Called by ProxyProvider when ServiceDispatcher routes it a packet. Returns true if a
     * registered connection received it, false if nothing is registered for the packet's port -
     * the caller should drop the packet silently in that case, matching this codebase's existing
     * behaviour for ports nothing handles.
     */
    public function relayInbound(string $sKind, EconetPacket $oPacket): bool
    {
        $oConnection = $this->aRegistrations[$oPacket->getPort()] ?? null;
        if ($oConnection === null) {
            return false;
        }
        $oConnection->send(self::packetToFrame($sKind, $oPacket)->encode());
        return true;
    }

    /** @return list<int> for admin display */
    public function getRegisteredPorts(): array
    {
        return array_keys($this->aRegistrations);
    }

    private static function packetToFrame(string $sKind, EconetPacket $oPacket): Frame
    {
        return Frame::packet(
            $sKind,
            (int) $oPacket->getSourceNetwork(),
            (int) $oPacket->getSourceStation(),
            $oPacket->getDestinationNetwork(),
            $oPacket->getDestinationStation(),
            $oPacket->getPort(),
            $oPacket->getFlags(),
            (string) $oPacket->getData(),
        );
    }

    private static function frameToPacket(Frame $oFrame): EconetPacket
    {
        $oPacket = new EconetPacket();
        $oPacket->setSourceNetwork($oFrame->getSrcNet());
        $oPacket->setSourceStation($oFrame->getSrcStn());
        $oPacket->setDestinationNetwork($oFrame->getDstNet());
        $oPacket->setDestinationStation($oFrame->getDstStn());
        $oPacket->setPort($oFrame->getPort());
        $oPacket->setFlags($oFrame->getFlags());
        $oPacket->setData($oFrame->getPayload());
        return $oPacket;
    }
}
