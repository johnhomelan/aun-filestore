<?php

namespace HomeLan\FileStore\RemoteSocket;

use HomeLan\FileStore\RemoteSocket\Messages\Frame;
use HomeLan\FileStore\RemoteSocket\Exceptions\AuthenticationFailedException;
use Ratchet\Client\Connector;
use Ratchet\Client\WebSocket;
use Ratchet\RFC6455\Messaging\MessageInterface;
use React\EventLoop\LoopInterface;
use React\EventLoop\TimerInterface;

/**
 * The connecting side of the Remote Socket Protocol (see docs/protocols/remote-socket.md) -
 * used by sharefsd to receive UDP traffic relayed from an EconetA interface owned by
 * filestored, and send replies back through it. Reconnects automatically if the connection to
 * the relay server drops.
 *
 * A caller obtains a HomeLan\FileStore\RemoteSocket\RelayedUdpTransport per local port via
 * getTransport(), and gives it to a handler exactly as it would a real React\Datagram\Socket.
 *
 * If the relay server rejects the shared secret, handleFrame() throws
 * AuthenticationFailedException - since that happens from inside the event loop's own dispatch
 * (a WebSocket 'message' event), the exception propagates out through the caller's
 * $oLoop->run(), not out of connect() itself. Callers should wrap $oLoop->run() accordingly.
 */
class Client
{
    private const RECONNECT_DELAY_SECONDS = 5;

    private bool $bAuthenticated = false;

    /** @var array<int, RelayedUdpTransport> local port => transport */
    private array $aTransports = [];

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
        // Pass a plaintext React connector: the relay URL is ws:// (never wss://),
        // and this keeps the default Connector from pulling in SecureConnector +
        // its openssl dependency (which matters for the ahead-of-time build - see
        // packaging/typephp).
        $oConnector = new Connector($this->oLoop, new \React\Socket\Connector(['tls' => false], $this->oLoop));
        $oConnector->__invoke($this->sUrl)->then(
            function (mixed $mConnection): void {
                if (!$mConnection instanceof WebSocket) {
                    return;
                }
                $this->oConnection = $mConnection;
                // pawl's WebSocket emits 'message' as [$msg, $conn] and 'close'
                // as [$code, $reason, $conn]; declare the trailing params even
                // though they are unused (strict arg-count under TypePHP).
                $mConnection->on('message', function (MessageInterface $oMessage, mixed $mConn = null): void {
                    $this->handleFrame((string) $oMessage);
                });
                $mConnection->on('close', function (mixed $mCode = null, mixed $mReason = null, mixed $mConn = null): void {
                    $this->onDisconnected();
                });
                $this->rawSend(Frame::hello($this->sSecret)->encode());
            },
            function (\Throwable $oError): void {
                $this->oLogger->warning('RemoteSocket\Client: connection to ' . $this->sUrl . ' failed: ' . $oError->getMessage());
                $this->scheduleReconnect();
            }
        );
    }

    private function onDisconnected(): void
    {
        $this->oLogger->warning('RemoteSocket\Client: disconnected from ' . $this->sUrl);
        $this->oConnection = null;
        $this->bAuthenticated = false;
        $this->scheduleReconnect();
    }

    private function scheduleReconnect(): void
    {
        $this->oLoop->addTimer(self::RECONNECT_DELAY_SECONDS, function (?TimerInterface $oTimer = null): void {
            $this->connect();
        });
    }

    /**
     * Gets (creating and registering it if needed) the transport for a local UDP port. Safe to
     * call before the connection to the relay server is established - see onHelloOk(), which
     * registers it once authenticated.
     */
    public function getTransport(int $iLocalPort): RelayedUdpTransport
    {
        if (!isset($this->aTransports[$iLocalPort])) {
            $this->aTransports[$iLocalPort] = new RelayedUdpTransport($this, $iLocalPort);
            if ($this->bAuthenticated) {
                $this->sendRegister($iLocalPort);
            }
        }
        return $this->aTransports[$iLocalPort];
    }

    private function sendRegister(int $iLocalPort): void
    {
        $this->rawSend(Frame::register([['protocol' => 'UDP', 'port' => $iLocalPort]])->encode());
    }

    public function sendData(string $sLocalAddr, int $iLocalPort, string $sRemoteAddr, int $iRemotePort, string $sPayload): void
    {
        $this->rawSend(Frame::data('UDP', $sLocalAddr, $iLocalPort, $sRemoteAddr, $iRemotePort, $sPayload)->encode());
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
            $this->oLogger->warning('RemoteSocket\Client: discarding malformed frame: ' . $oException->getMessage());
            return;
        }

        match ($oFrame->getType()) {
            Frame::TYPE_HELLO_OK => $this->onHelloOk(),
            Frame::TYPE_VERSION_REJECT => $this->oLogger->emergency('RemoteSocket\Client: server rejected our protocol version'),
            Frame::TYPE_AUTH_FAIL => throw new AuthenticationFailedException('RemoteSocket\Client: server rejected our shared secret'),
            Frame::TYPE_REGISTER_OK => $this->oLogger->info("RemoteSocket\Client: registered {$oFrame->getProtocol()}/{$oFrame->getPort()}"),
            Frame::TYPE_REGISTER_FAIL => $this->oLogger->warning("RemoteSocket\Client: registration for {$oFrame->getProtocol()}/{$oFrame->getPort()} failed: {$oFrame->getReason()}"),
            Frame::TYPE_DATA => $this->onData($oFrame),
            Frame::TYPE_PONG => null,
            default => $this->oLogger->debug("RemoteSocket\Client: ignoring frame type \"{$oFrame->getType()}\""),
        };
    }

    /**
     * Registers every port a caller has asked for via getTransport(), on every successful
     * handshake (initial connection and any later reconnection alike).
     */
    private function onHelloOk(): void
    {
        $this->bAuthenticated = true;
        foreach (array_keys($this->aTransports) as $iLocalPort) {
            $this->sendRegister($iLocalPort);
        }
    }

    private function onData(Frame $oFrame): void
    {
        $oTransport = $this->aTransports[$oFrame->getLocalPort()] ?? null;
        $oTransport?->deliver($oFrame->getLocalAddr(), $oFrame->getRemoteAddr(), $oFrame->getRemotePort(), $oFrame->getPayload());
    }
}
