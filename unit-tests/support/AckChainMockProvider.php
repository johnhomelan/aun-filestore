<?php

/**
 * Shared ProviderInterface test double mirroring FileServer's block-by-block,
 * ack-driven continuation pattern: send a block, register addAckEvent() for
 * the client's (network, station), and on ack send the next block and
 * re-register — repeating until the configured number of blocks is sent, at
 * which point a final "done" reply is queued instead.
 *
 * Used across the AUN/Piconet/WebSocket/RemoteBridge encapsulation test
 * suites to prove that a real Econet-level ack, arriving via that
 * encapsulation's own handler, actually reaches
 * ServiceDispatcher::ackEvents() and drives the chain all the way to
 * completion — not just that ackEvents() itself fires in isolation.
 */

use HomeLan\FileStore\Services\ProviderInterface;
use HomeLan\FileStore\Services\ServiceDispatcher;
use HomeLan\FileStore\Services\Provider\AdminInterface;
use HomeLan\FileStore\Messages\EconetPacket;

class AckChainMockProvider implements ProviderInterface
{
    private ?ServiceDispatcher $oServiceDispatcher = null;
    private int $iBlocksSent = 0;
    private bool $bComplete = false;

    /** @var EconetPacket[] */
    private array $aReplies = [];

    public function __construct(
        private readonly int $iPort = 0x99,
        private readonly int $iTotalBlocks = 3,
    ) {}

    public function getName(): string { return 'AckChainMockProvider'; }
    public function getAdminInterface(): ?AdminInterface { return null; }
    public function getJobs(): array { return []; }
    public function getServicePorts(): array { return [$this->iPort]; }

    public function registerService(ServiceDispatcher $oServiceDispatcher): void
    {
        $this->oServiceDispatcher = $oServiceDispatcher;
    }

    /** Starts the block-by-block transfer to whichever station sent $oPacket. */
    public function unicastPacketIn(EconetPacket $oPacket): void
    {
        $this->sendNextBlock($oPacket->getSourceNetwork(), $oPacket->getSourceStation());
    }

    public function broadcastPacketIn(EconetPacket $oPacket): void
    {
    }

    public function getReplies(): array
    {
        $aOut = $this->aReplies;
        $this->aReplies = [];
        return $aOut;
    }

    /** How many blocks (including the final "done" marker) have been sent so far. */
    public function getBlocksSent(): int
    {
        return $this->iBlocksSent;
    }

    /** True once the full chain has run to completion (all blocks acked). */
    public function isComplete(): bool
    {
        return $this->bComplete;
    }

    private function sendNextBlock(int $iNetwork, int $iStation): void
    {
        $this->iBlocksSent++;

        $oPkt = new EconetPacket();
        $oPkt->setDestinationNetwork($iNetwork);
        $oPkt->setDestinationstation($iStation);
        $oPkt->setPort($this->iPort);
        $oPkt->setFlags(0);
        $oPkt->setData("block{$this->iBlocksSent}");
        $this->aReplies[] = $oPkt;

        if ($this->iBlocksSent >= $this->iTotalBlocks) {
            $this->bComplete = true;
            return;
        }

        $this->oServiceDispatcher->addAckEvent($iNetwork, $iStation, function () use ($iNetwork, $iStation) {
            $this->sendNextBlock($iNetwork, $iStation);
        });
    }
}
