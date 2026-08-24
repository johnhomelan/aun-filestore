<?php

namespace HomeLan\FileStore\Services\Provider;

use HomeLan\FileStore\Services\ProviderInterface;
use HomeLan\FileStore\Services\ServiceDispatcher;
use HomeLan\FileStore\Services\Exception as ServiceException;
use HomeLan\FileStore\Messages\EconetPacket;
use HomeLan\FileStore\RemoteProvider\RelayServer;
use HomeLan\FileStore\RemoteProvider\Messages\Frame;

/**
 * Occupies a statically-configured superset of Econet ports (remote_provider_ports) on behalf of
 * whichever provider process registers for them over the Remote Provider Protocol relay - see
 * docs/protocols/remote-provider.md. ServiceDispatcher::addService() requires ports to be fixed
 * at startup, so this class exists purely to satisfy that up front; which of its reserved ports
 * are actually backed by a live remote connection is decided later, dynamically, by RelayServer's
 * own registration table.
 *
 * A port with no remote connection currently registered for it behaves exactly like a port
 * nothing has claimed at all - packets are dropped silently, the same tolerance ServiceDispatcher
 * already has elsewhere.
 *
 * claimStreamPort() additionally lets a remote connection claim a dynamically-allocated stream
 * port (see docs/protocols/remote-provider.md § Stream Claims) - unlike the statically-configured
 * ports above, these are bound at runtime via ServiceDispatcher::claimStreamPort() and released
 * again by sweepExpiredStreamPorts() once ServiceDispatcher's own expiry frees them.
 */
class ProxyProvider implements ProviderInterface
{
    /** @var array<int,EconetPacket> */
    private array $aReplies = [];

    private ?RelayServer $oRelayServer = null;

    private ?ServiceDispatcher $oServiceDispatcher = null;

    /** @var list<int> stream ports claimed via claimStreamPort(), swept by sweepExpiredStreamPorts() */
    private array $aClaimedStreamPorts = [];

    /** @param list<int> $aPorts */
    public function __construct(
        private readonly \Psr\Log\LoggerInterface $oLogger,
        private readonly array $aPorts,
    ) {
    }

    public function setRelayServer(RelayServer $oRelayServer): void
    {
        $this->oRelayServer = $oRelayServer;
    }

    public function getName(): string
    {
        return 'Remote Provider Relay';
    }

    public function getAdminInterface(): ?AdminInterface
    {
        return new ProxyProvider\Admin($this);
    }

    public function getServicePorts(): array
    {
        return $this->aPorts;
    }

    public function registerService(ServiceDispatcher $oServiceDispatcher): void
    {
        $this->oServiceDispatcher = $oServiceDispatcher;
        $oServiceDispatcher->addHousingKeepingTask($this->sweepExpiredStreamPorts(...));
    }

    /**
     * Passed as the $fClaimStreamPort callback to RelayServer's constructor - claims a stream
     * port on the real ServiceDispatcher for whichever remote connection asked (see
     * docs/protocols/remote-provider.md § Stream Claims). Returns null (rather than letting
     * ServiceDispatcher::claimStreamPort()'s exception propagate) if none are free - RelayServer
     * turns that into a stream_claim_failed reply.
     */
    public function claimStreamPort(int $iTimeout): ?int
    {
        if ($this->oServiceDispatcher === null) {
            return null;
        }
        try {
            $iPort = $this->oServiceDispatcher->claimStreamPort($this, $iTimeout);
        } catch (ServiceException) {
            return null;
        }
        $this->aClaimedStreamPorts[] = $iPort;
        return $iPort;
    }

    /**
     * Releases this provider's claimed-port bookkeeping (and the matching RelayServer
     * registration) for any stream port ServiceDispatcher::houseKeeping() has already expired
     * out from under us - without this, a later claim reusing the same port number could route
     * to the stale connection that held it before. Registered as a housekeeping task in
     * registerService().
     *
     * Runs one houseKeeping() cycle behind ServiceDispatcher's own expiry: houseKeeping() calls
     * every registered task (this one included) before it frees expired stream ports itself, so
     * a port that just expired is still bound to $this the first time this runs, and only shows
     * up as gone on the next cycle. Harmless - it only widens the window during which a reused
     * port number could theoretically be misrouted from "instant" to "one housekeeping_interval",
     * the same order of magnitude as other timing tolerances already in this codebase.
     */
    private function sweepExpiredStreamPorts(): void
    {
        if ($this->oServiceDispatcher === null) {
            return;
        }
        $aStillClaimed = [];
        foreach ($this->aClaimedStreamPorts as $iPort) {
            if ($this->oServiceDispatcher->getServiceByPort($iPort) === $this) {
                $aStillClaimed[] = $iPort;
            } else {
                $this->oRelayServer?->releaseStreamPort($iPort);
            }
        }
        $this->aClaimedStreamPorts = $aStillClaimed;
    }

    public function unicastPacketIn(EconetPacket $oPacket): void
    {
        $this->relay(Frame::KIND_UNICAST, $oPacket);
    }

    public function broadcastPacketIn(EconetPacket $oPacket): void
    {
        $this->relay(Frame::KIND_BROADCAST, $oPacket);
    }

    private function relay(string $sKind, EconetPacket $oPacket): void
    {
        if ($this->oRelayServer === null || !$this->oRelayServer->relayInbound($sKind, $oPacket)) {
            $this->oLogger->debug("ProxyProvider: no remote provider registered for port {$oPacket->getPort()}, dropping");
        }
    }

    /**
     * Passed as the $fInjectReply callback to RelayServer's constructor - called whenever a
     * `packet` frame comes back from a remote provider, whether it's a reply to a packet just
     * relayed to it or something it sent unprompted (e.g. an async broadcast). Either way it just
     * joins the normal reply buffer, drained like any other provider's via getReplies().
     */
    public function injectReply(EconetPacket $oPacket): void
    {
        $this->aReplies[] = $oPacket;
    }

    public function getReplies(): array
    {
        $aReplies = $this->aReplies;
        $this->aReplies = [];
        return $aReplies;
    }

    public function getJobs(): array
    {
        return [];
    }

    /** @return list<int> for admin display */
    public function getRegisteredPorts(): array
    {
        return $this->oRelayServer?->getRegisteredPorts() ?? [];
    }
}
