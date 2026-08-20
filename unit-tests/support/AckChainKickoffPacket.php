<?php

/**
 * Minimal EncapsulationInterface double for the initial "request" packet
 * that kicks off an AckChainMockProvider chain in the per-encapsulation
 * ack-chain integration tests (AUN, Piconet, WebSocket, RemoteBridge).
 *
 * Delivered directly via ServiceDispatcher::inboundPacket() — each
 * encapsulation's own inbound wire handling for an initial Unicast request
 * is already covered by that encapsulation's own Handler tests, so this
 * double lets the ack-chain tests skip straight to what's actually under
 * test: the ack path.
 */

use HomeLan\FileStore\Encapsulation\EncapsulationInterface;
use HomeLan\FileStore\Messages\EconetPacket;

class AckChainKickoffPacket implements EncapsulationInterface
{
    public function __construct(private int $iPort, private int $iSrcNet, private int $iSrcStn) {}

    public function getPort(): int { return $this->iPort; }
    public function getPacketType(): string { return 'Unicast'; }
    public function getData(): string { return ''; }
    public function getSequence(): ?int { return null; }
    public function decode(string $sBinaryString): void {}

    public function buildEconetPacket(): EconetPacket
    {
        $oPkt = new EconetPacket();
        $oPkt->setSourceNetwork($this->iSrcNet);
        $oPkt->setSourceStation($this->iSrcStn);
        $oPkt->setDestinationNetwork(0);
        $oPkt->setDestinationstation(0);
        $oPkt->setPort($this->iPort);
        $oPkt->setFlags(0);
        $oPkt->setData('');
        return $oPkt;
    }

    public function toString(): string { return 'AckChainKickoffPacket'; }
}
