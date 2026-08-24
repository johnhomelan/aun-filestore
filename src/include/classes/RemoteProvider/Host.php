<?php

namespace HomeLan\FileStore\RemoteProvider;

use HomeLan\FileStore\RemoteProvider\Messages\Frame;
use HomeLan\FileStore\Messages\EconetPacket;
use HomeLan\FileStore\Services\ServiceDispatcher;
use HomeLan\FileStore\Services\ProviderInterface;
use React\Promise\PromiseInterface;

/**
 * Runs one or more HomeLan\FileStore\Services\ProviderInterface implementations against a
 * Client's relayed traffic instead of a real Econet transport - see
 * docs/protocols/remote-provider.md. This is what lets an existing provider class run entirely
 * unmodified in its own process: it is registered with a real ServiceDispatcher instance exactly
 * as filestored would register it, so housekeeping tasks, admin enable/disable
 * (ServiceDispatcher::create()->disableService()/enableService()) and getServiceByPort() all
 * keep working - only the transport underneath (PacketDispatcher/AUN/WebSocket) is swapped out
 * for this class.
 *
 * `dispatch()` is the per-call drain, mirroring ServiceDispatcher::inboundPacket()'s own
 * immediate getReplies() drain after each *PacketIn() call. `flush()` is the periodic drain,
 * mirroring the 1-second getReplies() timer in Command\React - it catches unsolicited/async
 * output a provider queues outside of handling an inbound packet (e.g. a housekeeping-driven
 * broadcast), which dispatch() alone would never see.
 *
 * claimStreamPort() and the 'ack' subscription are what let a hosted provider do ACK'd
 * multi-packet streaming (GETBYTES/PUTBYTES-style flow control) via its completely unmodified
 * own calls to $oServiceDispatcher->claimStreamPort()/addAckEvent() - see
 * docs/protocols/remote-provider.md § Stream Claims and § Ack Relay.
 */
class Host
{
    public function __construct(
        private readonly Client $oClient,
        private readonly ServiceDispatcher $oServiceDispatcher,
        private readonly \Psr\Log\LoggerInterface $oLogger,
    ) {
        $aPorts = [];
        foreach ($this->oServiceDispatcher->getServices() as $oProvider) {
            $aPorts = [...$aPorts, ...$oProvider->getServicePorts()];
        }
        $this->oClient->registerPorts($aPorts);

        $this->oClient->on('packet', function (string $sKind, EconetPacket $oPacket): void {
            $this->dispatch($sKind, $oPacket);
        });

        $this->oClient->on('ack', function (int $iNetwork, int $iStation, ?int $iSeq): void {
            $this->oServiceDispatcher->fireAckEvent($iNetwork, $iStation, $iSeq);
        });
    }

    /**
     * Claims a stream port for $oProvider (see ServiceDispatcher::claimStreamPort()) via the
     * relay server's real ServiceDispatcher instance, then binds the exact same port number on
     * this process's own local ServiceDispatcher (ServiceDispatcher::bindStreamPort()) so
     * dispatch() routes traffic for it here. Resolves with the allocated port; rejects if the
     * server has none free or the claim otherwise fails - see Client::claimStreamPort().
     */
    /** @return PromiseInterface<int> */
    public function claimStreamPort(ProviderInterface $oProvider, int $iTimeout = 60): PromiseInterface
    {
        return $this->oClient->claimStreamPort($iTimeout)->then(function (int $iPort) use ($oProvider, $iTimeout): int {
            $this->oServiceDispatcher->bindStreamPort($iPort, $oProvider, $iTimeout);
            return $iPort;
        });
    }

    private function dispatch(string $sKind, EconetPacket $oPacket): void
    {
        $oProvider = $this->oServiceDispatcher->getServiceByPort($oPacket->getPort());
        if ($oProvider === null) {
            $this->oLogger->debug("RemoteProvider\Host: no provider registered for port {$oPacket->getPort()}, dropping");
            return;
        }

        if ($sKind === Frame::KIND_BROADCAST) {
            $oProvider->broadcastPacketIn($oPacket);
        } else {
            $oProvider->unicastPacketIn($oPacket);
        }

        foreach ($oProvider->getReplies() as $oReply) {
            $this->sendReply($oReply);
        }
    }

    /**
     * Drains every hosted provider's getReplies(), for output not tied to handling an inbound
     * packet - see class docblock. Callers should run this on a periodic timer.
     */
    public function flush(): void
    {
        foreach ($this->oServiceDispatcher->getServices() as $oProvider) {
            foreach ($oProvider->getReplies() as $oReply) {
                $this->sendReply($oReply);
            }
        }
    }

    private function sendReply(EconetPacket $oPacket): void
    {
        $sKind = $oPacket->getDestinationStation() === 255 ? Frame::KIND_BROADCAST : Frame::KIND_UNICAST;
        $this->oClient->sendPacket($sKind, $oPacket);
    }
}
