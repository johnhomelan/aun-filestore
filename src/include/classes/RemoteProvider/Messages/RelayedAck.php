<?php

namespace HomeLan\FileStore\RemoteProvider\Messages;

use HomeLan\FileStore\Encapsulation\EncapsulationInterface;
use HomeLan\FileStore\Messages\EconetPacket;

/**
 * A synthetic EncapsulationInterface standing in for a real Ack packet that never reached this
 * process - see ServiceDispatcher::fireAckEvent() and docs/protocols/remote-provider.md § Ack
 * Relay. Only network/station/sequence are known (relayed from filestored via an `ack` frame -
 * see AckRelayMap); every other field is a placeholder matching what a real Ack packet's would
 * be (port 0, no data - see WebSocket\JsonPacket::buildAck()'s own Ack encoding).
 *
 * decode() is never called on this - it exists purely to satisfy the interface.
*/
class RelayedAck implements EncapsulationInterface
{
    public function __construct(
        private readonly int $iNetwork,
        private readonly int $iStation,
        private readonly ?int $iSeq,
    ) {
    }

    public function getPort(): int
    {
        return 0;
    }

    public function getPacketType(): string
    {
        return 'Ack';
    }

    public function getSequence(): ?int
    {
        return $this->iSeq;
    }

    public function getData(): string
    {
        return '';
    }

    public function decode(string $sBinaryString): void
    {
    }

    public function buildEconetPacket(): EconetPacket
    {
        $oPacket = new EconetPacket();
        $oPacket->setSourceNetwork($this->iNetwork);
        $oPacket->setSourceStation($this->iStation);
        return $oPacket;
    }

    public function asString(): string
    {
        return "RelayedAck net={$this->iNetwork} stn={$this->iStation} seq=" . ($this->iSeq ?? 'null');
    }
}
