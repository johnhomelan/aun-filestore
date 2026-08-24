<?php

namespace HomeLan\FileStore\RemoteProvider;

use HomeLan\FileStore\RemoteProvider\Messages\Frame;
use Ratchet\ConnectionInterface;

/**
 * Relays a real Econet-level ack back to whichever Remote Provider Protocol connection most
 * recently sent a `packet` frame destined for that (network, station) - see
 * docs/protocols/remote-provider.md § Ack Relay. Mirrors RemoteBridge\Map's own
 * rememberAckRelay()/relayAckIfKnown() pair (see docs/protocols/remote-bridge.md) for exactly
 * the same reason: ServiceDispatcher::ackEvents() calls both, unconditionally, without needing
 * to know anything about bridge peers or remote provider hosts specifically.
 *
 * This is what lets a remotely-hosted provider's own addAckEvent() callback - registered,
 * completely unmodified, on its own local ServiceDispatcher instance - ever actually fire: the
 * real ack physically arrives at filestored, not at the remote process, so it has to be
 * forwarded across.
 *
 * Single-hop, most-recent-sender-wins, same as RemoteBridge\Map: a later packet to the same
 * station overwrites an earlier pending entry, and a fired entry is not cleared afterwards -
 * there is at most one outstanding remotely-hosted send per station in practice (a station only
 * has one open stream at a time).
*/
class AckRelayMap
{
    /** @var array<string, array{conn: ConnectionInterface, seq: int}> "net.stn" => entry */
    private static array $aPendingAckRelay = [];

    /**
     * Records that a reply relayed to a remote provider host (network, station being where it
     * is going) went out over $oConnection with sequence $iSeq - called by RelayServer for
     * every `packet` frame it relays, so that when filestored's own encapsulation later
     * observes the genuine hardware-level ack that delivery provokes, relayAckIfKnown() knows
     * which connection to forward it to.
    */
    public static function rememberAckRelay(int $iNetwork, int $iStation, ConnectionInterface $oConnection, int $iSeq): void
    {
        self::$aPendingAckRelay["{$iNetwork}.{$iStation}"] = ['conn' => $oConnection, 'seq' => $iSeq];
    }

    /**
     * Sends an `ack` frame to whichever connection most recently sent a reply to (network,
     * station), if any - called from ServiceDispatcher::ackEvents(). A no-op, returning false,
     * if this station never had a reply relayed to it - the overwhelmingly common case, since
     * most acks are for genuinely local traffic.
     *
     * @return bool True if a connection for this station was found (an `ack` frame was sent).
    */
    public static function relayAckIfKnown(int $iNetwork, int $iStation, ?int $iSeq): bool
    {
        $aEntry = self::$aPendingAckRelay["{$iNetwork}.{$iStation}"] ?? null;
        if ($aEntry === null) {
            return false;
        }
        $aEntry['conn']->send(Frame::ack($iNetwork, $iStation, $iSeq ?? $aEntry['seq'])->encode());
        return true;
    }

    /** Test-only: clears all remembered state between test cases. */
    public static function reset(): void
    {
        self::$aPendingAckRelay = [];
    }
}
