<?php

namespace HomeLan\FileStore\ShareFs;

use HomeLan\FileStore\ShareFs\Messages\FreewayPacket;
use React\Datagram\SocketInterface;
use config;

/**
 * Broadcasts Freeway share availability (UDP port 32770). Matches
 * andrewtimmins/riscos-access-server's actual behaviour: shares are announced by periodically
 * re-broadcasting the same "available" message on a timer, unprompted - this reference server
 * never listens for or replies to a discovery request on this port at all (protected shares
 * are discovered separately, via a successful Access+ share-key match - see
 * AccessPlusHandler). `receive()` exists only to log what a client sends here, matching that.
 *
 * Protected and hidden shares are never broadcast here - see Share::isAdvertised().
 */
class FreewayHandler
{
    private SocketInterface $oSocket;

    public function __construct(private readonly \Psr\Log\LoggerInterface $oLogger)
    {
    }

    public function setSocket(SocketInterface $oSocket): void
    {
        $this->oSocket = $oSocket;
    }

    public function receive(string $sMessage, string $sSrcAddress): void
    {
        $this->oLogger->debug("ShareFs Freeway: {$sSrcAddress} sent " . strlen($sMessage) . ' bytes (not acted on)');
    }

    /** Called on a periodic timer to (re-)announce every advertised share. */
    public function broadcast(): void
    {
        $sBroadcast = config::getValueAsString('sharefs_freeway_broadcast_address') . ':' . config::getValueAsString('sharefs_freeway_port');
        foreach (ShareList::getAdvertisedShares() as $oShare) {
            $this->oLogger->debug("ShareFs Freeway: broadcasting share {$oShare->getName()}");
            $oPacket = new FreewayPacket(FreewayPacket::TYPE_DISC, FreewayPacket::MINOR_AVAILABLE, $oShare->getName());
            $this->oSocket->send($oPacket->encode(), $sBroadcast);
        }
    }
}
