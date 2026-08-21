<?php

namespace HomeLan\FileStore\RemoteSocket;

use HomeLan\FileStore\RemoteSocket\Messages\Frame;
use Ratchet\MessageComponentInterface;
use Ratchet\ConnectionInterface;

/**
 * The server (listening) side of the Remote Socket Protocol - see docs/protocols/remote-socket.md.
 *
 * Accepts WebSocket connections from relay clients (e.g. sharefsd), authenticates them via a
 * shared secret, and lets them register interest in (protocol, port) pairs. `relayInbound()` is
 * called by whichever local component owns a network interface (currently
 * Services\Provider\IPv4, for EconetA) when a packet arrives addressed to that interface on a
 * registered port; a `data` frame received back from a client is always a reply, handed to the
 * $fInjectReply callback to be sent back out on the interface it originally arrived on.
 *
 * Only one client may be registered for a given (protocol, port) at a time - a second register
 * for an already-claimed pair gets register_fail, matching ServiceDispatcher::addService()'s
 * existing "port already in use" behaviour for local port claims.
 */
class RelayServer implements MessageComponentInterface
{
    /** @var array<string, ConnectionInterface> "protocol.port" => connection */
    private array $aRegistrations = [];

    /** @var array<int, array{authenticated:bool, services:list<string>}> spl_object_id(connection) => state */
    private array $aConnectionState = [];

    /**
     * @param callable(string $sLocalAddr, int $iLocalPort, string $sRemoteAddr, int $iRemotePort, string $sPayload): void $fInjectReply
     */
    public function __construct(
        private readonly \Psr\Log\LoggerInterface $oLogger,
        private readonly string $sSecret,
        private readonly mixed $fInjectReply,
    ) {
    }

    public function onOpen(ConnectionInterface $oConnection): void
    {
        $this->aConnectionState[spl_object_id($oConnection)] = ['authenticated' => false, 'services' => []];
    }

    public function onMessage(ConnectionInterface $oConnection, $sMessage): void
    {
        try {
            $oFrame = Frame::decode((string) $sMessage);
        } catch (\Exception $oException) {
            $this->oLogger->warning('RemoteSocket: discarding malformed frame: ' . $oException->getMessage());
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
            Frame::TYPE_REGISTER => $this->handleRegister($oConnection, $oFrame),
            Frame::TYPE_DATA      => $this->handleData($oFrame),
            Frame::TYPE_PING      => $oConnection->send(Frame::pong()->encode()),
            default               => $this->oLogger->debug("RemoteSocket: ignoring frame type \"{$oFrame->getType()}\""),
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
            $this->oLogger->warning('RemoteSocket: rejecting connection with an incorrect secret');
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
        foreach ($oFrame->getServices() as $aService) {
            $sKey = self::key($aService['protocol'], $aService['port']);
            if (isset($this->aRegistrations[$sKey])) {
                $oConnection->send(Frame::registerFail($aService['protocol'], $aService['port'], 'port already in use')->encode());
                continue;
            }
            $this->aRegistrations[$sKey] = $oConnection;
            $this->aConnectionState[$iId]['services'][] = $sKey;
            $this->oLogger->info("RemoteSocket: registered {$sKey}");
            $oConnection->send(Frame::registerOk($aService['protocol'], $aService['port'])->encode());
        }
    }

    private function handleData(Frame $oFrame): void
    {
        ($this->fInjectReply)($oFrame->getLocalAddr(), $oFrame->getLocalPort(), $oFrame->getRemoteAddr(), $oFrame->getRemotePort(), $oFrame->getPayload());
    }

    public function onClose(ConnectionInterface $oConnection): void
    {
        $iId = spl_object_id($oConnection);
        $aState = $this->aConnectionState[$iId] ?? null;
        if ($aState !== null) {
            foreach ($aState['services'] as $sKey) {
                unset($this->aRegistrations[$sKey]);
            }
        }
        unset($this->aConnectionState[$iId]);
    }

    public function onError(ConnectionInterface $oConnection, \Exception $oException): void
    {
        $this->oLogger->warning('RemoteSocket: connection error: ' . $oException->getMessage());
        $oConnection->close();
    }

    /**
     * Called by whichever component owns the interface a packet arrived on. Returns true if a
     * registered client received it, false if nothing is registered for (protocol, port) - the
     * caller should drop the packet silently in that case, matching this codebase's existing
     * behaviour for protocols nothing handles.
     */
    public function relayInbound(string $sProtocol, int $iLocalPort, string $sLocalAddr, string $sRemoteAddr, int $iRemotePort, string $sPayload): bool
    {
        $oConnection = $this->aRegistrations[self::key($sProtocol, $iLocalPort)] ?? null;
        if ($oConnection === null) {
            return false;
        }
        $oConnection->send(Frame::data($sProtocol, $sLocalAddr, $iLocalPort, $sRemoteAddr, $iRemotePort, $sPayload)->encode());
        return true;
    }

    /** @return list<array{protocol:string, port:string}> for admin display */
    public function getRegistrations(): array
    {
        $aReturn = [];
        foreach (array_keys($this->aRegistrations) as $sKey) {
            [$sProtocol, $sPort] = explode('.', $sKey, 2);
            $aReturn[] = ['protocol' => $sProtocol, 'port' => $sPort];
        }
        return $aReturn;
    }

    private static function key(string $sProtocol, int $iPort): string
    {
        return strtolower($sProtocol) . '.' . $iPort;
    }
}
