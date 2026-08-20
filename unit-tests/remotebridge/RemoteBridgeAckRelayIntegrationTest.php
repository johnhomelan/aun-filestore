<?php

/*
 * @group unit-tests
 *
 * End-to-end (loopback) test proving the full RemoteBridge ACK relay chain
 * works over the real wire protocol, not just that its pieces compile in
 * isolation — see docs/protocols/remote-bridge.md.
 *
 * Topology simulated, matching the doc's worked example exactly (two
 * ServiceDispatcher/Connection pairs wired back to back via
 * MockTcpConnection, exactly as ClientHandler/ServerHandler wire a real
 * Connection in production):
 *
 *   BBC Micro (net 2, stn 254) ─── [Instance X] === bridge === [Instance Y] ─── FileServer-like provider (net 1)
 *
 *   X physically serves network 2 (announces it via NETWORKS); Y serves
 *   network 1. Y's provider (AckChainMockProvider, standing in for
 *   FileServer) sends a reply block destined for (2, 254) and registers
 *   addAckEvent(2, 254, cb) — exactly as FileServer does after replying to a
 *   client reached via a bridge.
 *
 *   Y routes that block across the bridge as a real "SEND 2 254 ..." line.
 *   X's Connection accepts it (2 is one of X's own local networks) and, per
 *   Map::rememberAckRelay(), records that *this* connection asked for
 *   delivery to (2, 254) before handing the packet to X's local delivery
 *   path.
 *
 *   X's own local encapsulation later observes the genuine hardware-level
 *   Ack that real delivery to the BBC Micro provokes — tagged, as any real
 *   local ack is, with X's *own* served network number (2), not anything
 *   learned from a peer's NETWORKS announcement. X has no local
 *   addAckEvent for it (Y does), so ServiceDispatcher::ackEvents() falls
 *   through to RemoteBridgeMap::relayAckIfKnown(), which finds the entry
 *   rememberAckRelay() recorded moments earlier and sends "ACK 2 254"
 *   across the same connection the original SEND arrived on.
 *
 *   Y's Connection parses the ACK line and dispatches it into Y's own
 *   ServiceDispatcher, firing the originally-registered callback.
 */

include_once(__DIR__ . '/../../src/include/system.inc.php');
include_once(__DIR__ . '/MockTcpConnection.php');
include_once(__DIR__ . '/../support/AckChainMockProvider.php');
include_once(__DIR__ . '/../support/AckChainKickoffPacket.php');

use PHPUnit\Framework\TestCase;
use Monolog\Logger;
use Monolog\Handler\NullHandler;
use HomeLan\FileStore\RemoteBridge\Connection;
use HomeLan\FileStore\RemoteBridge\BridgePacket;
use HomeLan\FileStore\RemoteBridge\Map;
use HomeLan\FileStore\Services\ServiceDispatcher;
use HomeLan\FileStore\Encapsulation\EncapsulationInterface;
use HomeLan\FileStore\Messages\EconetPacket;

/** A minimal EncapsulationInterface double standing in for a real local
 *  (AUN/Piconet/WebSocket) handler's Ack, i.e. a genuine hardware-level ack
 *  received directly by an instance for one of its own local networks. */
class RawLocalAckPacket implements EncapsulationInterface
{
    public function __construct(private int $iSrcNet, private int $iSrcStn) {}

    public function getPort(): int { return 0; }
    public function getPacketType(): string { return 'Ack'; }
    public function getData(): string { return ''; }
    // A genuine local ack's own sequence (as reported by whichever real encapsulation
    // observed it) plays no part in what relayAckIfKnown() echoes onward — that comes
    // entirely from Map::rememberAckRelay()'s memory of the original SEND — so this stays null.
    public function getSequence(): ?int { return null; }
    public function decode(string $sBinaryString): void {}

    public function buildEconetPacket(): EconetPacket
    {
        $oPkt = new EconetPacket();
        $oPkt->setSourceNetwork($this->iSrcNet);
        $oPkt->setSourceStation($this->iSrcStn);
        $oPkt->setDestinationNetwork(0);
        $oPkt->setDestinationstation(0);
        $oPkt->setPort(0);
        $oPkt->setFlags(0);
        $oPkt->setData('');
        return $oPkt;
    }

    public function toString(): string { return "RawLocalAckPacket({$this->iSrcNet}.{$this->iSrcStn})"; }
}

class RemoteBridgeAckRelayIntegrationTest extends TestCase
{
    private Logger $oLogger;

    protected function setUp(): void
    {
        $this->oLogger = new Logger('test');
        $this->oLogger->pushHandler(new NullHandler());
        Map::reset();
    }

    protected function tearDown(): void
    {
        Map::reset();
    }

    public function testAckReceivedByXIsRelayedToYAndFiresYsRegisteredCallback(): void
    {
        Map::init($this->oLogger, '');

        $oServicesY = new ServiceDispatcher($this->oLogger, []);
        $oServicesX = new ServiceDispatcher($this->oLogger, []);

        $oXTcp = new MockTcpConnection();
        $oYTcp = new MockTcpConnection();

        // Pinned to 1.1 (no <seq> field) — see testMultiBlockAckChainCompletesAcrossTheBridgeWith1_2
        // below for the 1.2 sequence-carrying equivalent of this same scenario.
        // X physically serves network 2 (the BBC Micro's network).
        $oConnXtoY = new Connection(
            $this->oLogger, $oXTcp, 'server', 'secret', [2],
            static function (BridgePacket $p) {},
            static function (BridgePacket $p) {},
            ['1.0', '1.1'],
        );
        // Y serves network 1 (where the file-server-like provider lives).
        $oConnYtoX = new Connection(
            $this->oLogger, $oYTcp, 'client', 'secret', [1],
            static function (BridgePacket $p) {},
            function (BridgePacket $oAckPkt) use ($oServicesY) {
                $oServicesY->inboundPacket($oAckPkt);
            },
            ['1.0', '1.1'],
        );

        // Standard 4-message handshake, plus the 5th relay delivering X's
        // NETWORKS announcement to Y (queued during the 4th step).
        $oConnXtoY->onData($oYTcp->allWritten()); $oYTcp->aWritten = [];
        $oConnYtoX->onData($oXTcp->allWritten()); $oXTcp->aWritten = [];
        $oConnXtoY->onData($oYTcp->allWritten()); $oYTcp->aWritten = [];
        $oConnYtoX->onData($oXTcp->allWritten()); $oXTcp->aWritten = [];
        $oConnXtoY->onData($oYTcp->allWritten()); $oYTcp->aWritten = [];

        $this->assertSame('AUTHENTICATED', $oConnXtoY->getState());
        $this->assertSame('AUTHENTICATED', $oConnYtoX->getState());
        $this->assertSame('1.1', $oConnXtoY->getProtocolVersion());

        // Y's provider sends a reply block to the remote client at (2, 254),
        // registering addAckEvent(2, 254) exactly as FileServer does.
        $bFired = false;
        $oReceivedPkt = null;
        $oPkt = new EconetPacket();
        $oPkt->setDestinationNetwork(2);
        $oPkt->setDestinationstation(254);
        $oPkt->setPort(0x99);
        $oPkt->setFlags(0);
        $oPkt->setData('block1');
        $oServicesY->addAckEvent(2, 254, $oPkt->getSequence(), function ($p) use (&$bFired, &$oReceivedPkt) {
            $bFired = true;
            $oReceivedPkt = $p;
        });

        // Routed across the bridge as a real SEND — this is what populates
        // X's Map::rememberAckRelay() entry for (2, 254).
        $oConnYtoX->send($oPkt);
        $oConnXtoY->onData($oYTcp->allWritten()); $oYTcp->aWritten = [];

        // X's own local encapsulation now receives the genuine hardware ack
        // from that station, tagged with X's own served network (2). X has
        // no local addAckEvent for it.
        $oServicesX->inboundPacket(new RawLocalAckPacket(2, 254));

        // That must have written an "ACK 2 254" line onto X's side of the wire.
        $this->assertSame(['ACK 2 254'], $oXTcp->writtenLines());

        // Deliver it to Y's connection object.
        $oConnYtoX->onData($oXTcp->allWritten());

        $this->assertTrue($bFired, 'Y\'s addAckEvent callback was not fired via the bridge relay');
        $this->assertNotNull($oReceivedPkt);
        $oEco = $oReceivedPkt->buildEconetPacket();
        $this->assertSame(2, $oEco->getSourceNetwork());
        $this->assertSame(254, $oEco->getSourceStation());
    }

    public function testAckNotRelayedWhenPeerNegotiatedOnly1_0(): void
    {
        Map::init($this->oLogger, '');

        $oServicesX = new ServiceDispatcher($this->oLogger, []);

        $oXTcp = new MockTcpConnection();
        $oYTcp = new MockTcpConnection();

        $oConnXtoY = new Connection(
            $this->oLogger, $oXTcp, 'server', 'secret', [2],
            static function (BridgePacket $p) {},
            static function (BridgePacket $p) {},
        );
        $oConnYtoX = new Connection(
            $this->oLogger, $oYTcp, 'client', 'secret', [1],
            static function (BridgePacket $p) {},
            static function (BridgePacket $p) {},
            ['1.0'],
        );

        $oConnXtoY->onData($oYTcp->allWritten()); $oYTcp->aWritten = [];
        $oConnYtoX->onData($oXTcp->allWritten()); $oXTcp->aWritten = [];
        $oConnXtoY->onData($oYTcp->allWritten()); $oYTcp->aWritten = [];
        $oConnYtoX->onData($oXTcp->allWritten()); $oXTcp->aWritten = [];
        $oConnXtoY->onData($oYTcp->allWritten()); $oYTcp->aWritten = [];

        $this->assertSame('1.0', $oConnXtoY->getProtocolVersion());

        // Still route a real SEND so relayAckIfKnown() actually finds a
        // pending-relay entry to attempt — proving sendAck() itself (not
        // just an absent entry) is what silently no-ops for a 1.0 peer.
        $oPkt = new EconetPacket();
        $oPkt->setDestinationNetwork(2);
        $oPkt->setDestinationstation(254);
        $oPkt->setPort(0x99);
        $oPkt->setFlags(0);
        $oConnYtoX->send($oPkt);
        $oConnXtoY->onData($oYTcp->allWritten()); $oYTcp->aWritten = [];

        $oServicesX->inboundPacket(new RawLocalAckPacket(2, 254));

        $this->assertSame([], $oXTcp->writtenLines());
    }

    // -------------------------------------------------------------------------
    // Multi-block chain — proves the relay isn't just a single-shot callback,
    // it correctly drives a FileServer-style ack-driven continuation (the
    // shared AckChainMockProvider) across the bridge to completion.
    // -------------------------------------------------------------------------

    public function testMultiBlockAckChainCompletesAcrossTheBridge(): void
    {
        Map::init($this->oLogger, '');

        $oProviderY = new AckChainMockProvider(0x99, 3);
        $oServicesY = new ServiceDispatcher($this->oLogger, [$oProviderY]);
        $oServicesX = new ServiceDispatcher($this->oLogger, []);

        $oXTcp = new MockTcpConnection();
        $oYTcp = new MockTcpConnection();

        // Pinned to 1.1 (no <seq> field) — see testMultiBlockAckChainCompletesAcrossTheBridgeWith1_2
        // below for the 1.2 sequence-carrying equivalent of this same scenario.
        $oConnXtoY = new Connection(
            $this->oLogger, $oXTcp, 'server', 'secret', [2],
            static function (BridgePacket $p) {},
            static function (BridgePacket $p) {},
            ['1.0', '1.1'],
        );
        $oConnYtoX = new Connection(
            $this->oLogger, $oYTcp, 'client', 'secret', [1],
            static function (BridgePacket $p) {},
            function (BridgePacket $oAckPkt) use ($oServicesY) {
                $oServicesY->inboundPacket($oAckPkt);
            },
            ['1.0', '1.1'],
        );

        // Handshake, plus the 5th relay delivering X's NETWORKS announcement to Y.
        $oConnXtoY->onData($oYTcp->allWritten()); $oYTcp->aWritten = [];
        $oConnYtoX->onData($oXTcp->allWritten()); $oXTcp->aWritten = [];
        $oConnXtoY->onData($oYTcp->allWritten()); $oYTcp->aWritten = [];
        $oConnYtoX->onData($oXTcp->allWritten()); $oXTcp->aWritten = [];
        $oConnXtoY->onData($oYTcp->allWritten()); $oYTcp->aWritten = [];

        // Kick off the chain on Y: block 1 sent, addAckEvent(2, 254) registered.
        // (The initial request is injected directly, standing in for the
        // client's opening request having already been bridge-delivered to
        // Y's local service — that inbound leg is covered elsewhere; see
        // AckChainKickoffPacket.)
        $oServicesY->inboundPacket(new AckChainKickoffPacket(0x99, 2, 254));
        $this->assertSame(1, $oProviderY->getBlocksSent());

        // Route block 1 across the bridge for real, so X's
        // Map::rememberAckRelay() entry for (2, 254) actually gets populated.
        // (ServiceDispatcher::inboundPacket() already drained the provider's
        // own reply buffer into its own queue, so pull from there.)
        foreach ($oServicesY->getReplies() as $oReply) {
            $oConnYtoX->send($oReply);
        }
        $oConnXtoY->onData($oYTcp->allWritten()); $oYTcp->aWritten = [];

        // Real ack #1 arrives at X's local hardware and gets relayed to Y.
        $oServicesX->inboundPacket(new RawLocalAckPacket(2, 254));
        $this->assertSame(['ACK 2 254'], $oXTcp->writtenLines());
        $oConnYtoX->onData($oXTcp->allWritten());
        $oXTcp->aWritten = [];

        $this->assertSame(2, $oProviderY->getBlocksSent(), 'relayed ack #1 must have driven block 2');
        $this->assertFalse($oProviderY->isComplete());

        // Route block 2 across the bridge, same as block 1.
        foreach ($oProviderY->getReplies() as $oReply) {
            $oConnYtoX->send($oReply);
        }
        $oConnXtoY->onData($oYTcp->allWritten()); $oYTcp->aWritten = [];

        // Real ack #2 — completes the chain.
        $oServicesX->inboundPacket(new RawLocalAckPacket(2, 254));
        $this->assertSame(['ACK 2 254'], $oXTcp->writtenLines());
        $oConnYtoX->onData($oXTcp->allWritten());

        $this->assertSame(3, $oProviderY->getBlocksSent());
        $this->assertTrue($oProviderY->isComplete(), 'chain must be complete after the final relayed ack');
    }

    /**
     * Protocol 1.2: the same scenario as testMultiBlockAckChainCompletesAcrossTheBridge, but with
     * both sides negotiating the default (1.2+) version set, so SEND and ACK carry the real
     * sequence number end-to-end. Proves two things a bare "ACK <net> <stn>" line can't: the
     * negotiated wire format actually includes <seq>, and — the point of the whole feature — a
     * stray relayed ack for the wrong sequence does not fire Y's registered callback, while the
     * correct one still does.
     */
    public function testStraySequenceOverTheBridgeDoesNotFireYsCallbackButCorrectOneDoes(): void
    {
        Map::init($this->oLogger, '');

        $oProviderY = new AckChainMockProvider(0x99, 3);
        $oServicesY = new ServiceDispatcher($this->oLogger, [$oProviderY]);
        $oServicesX = new ServiceDispatcher($this->oLogger, []);

        $oXTcp = new MockTcpConnection();
        $oYTcp = new MockTcpConnection();

        // No $aSupportedVersions override — both sides advertise the full default set, so this
        // negotiates the highest version they share (1.2).
        $oConnXtoY = new Connection(
            $this->oLogger, $oXTcp, 'server', 'secret', [2],
            static function (BridgePacket $p) {},
            static function (BridgePacket $p) {},
        );
        $oConnYtoX = new Connection(
            $this->oLogger, $oYTcp, 'client', 'secret', [1],
            static function (BridgePacket $p) {},
            function (BridgePacket $oAckPkt) use ($oServicesY) {
                $oServicesY->inboundPacket($oAckPkt);
            },
        );

        $oConnXtoY->onData($oYTcp->allWritten()); $oYTcp->aWritten = [];
        $oConnYtoX->onData($oXTcp->allWritten()); $oXTcp->aWritten = [];
        $oConnXtoY->onData($oYTcp->allWritten()); $oYTcp->aWritten = [];
        $oConnYtoX->onData($oXTcp->allWritten()); $oXTcp->aWritten = [];
        $oConnXtoY->onData($oYTcp->allWritten()); $oYTcp->aWritten = [];

        $this->assertSame('1.2', $oConnXtoY->getProtocolVersion());
        $this->assertSame('1.2', $oConnYtoX->getProtocolVersion());

        // Kick off the chain on Y: block 1 sent, addAckEvent(2, 254, seq) registered.
        $oServicesY->inboundPacket(new AckChainKickoffPacket(0x99, 2, 254));
        $this->assertSame(1, $oProviderY->getBlocksSent());
        $iRealSeq = $oProviderY->getLastSentSeq();
        $this->assertNotNull($iRealSeq);

        // Route block 1 across the bridge for real — on a 1.2 connection the SEND line itself
        // now carries $iRealSeq, which is what populates X's Map::rememberAckRelay() entry.
        foreach ($oServicesY->getReplies() as $oReply) {
            $oConnYtoX->send($oReply);
        }
        $sSendLine = $oYTcp->writtenLines()[0] ?? '';
        $this->assertStringContainsString(" {$iRealSeq} ", $sSendLine, 'SEND line must carry the sequence number on a 1.2 connection');
        $oConnXtoY->onData($oYTcp->allWritten()); $oYTcp->aWritten = [];

        // X's own local hardware ack arrives; the relayed ACK line must now include the
        // remembered sequence number.
        $oServicesX->inboundPacket(new RawLocalAckPacket(2, 254));
        $this->assertSame(["ACK 2 254 {$iRealSeq}"], $oXTcp->writtenLines());
        $oXTcp->aWritten = [];

        // A stray relayed ack for the wrong sequence — simulating a duplicate/delayed relay,
        // or simply a differently-behaving peer — must not advance Y's chain.
        $oConnYtoX->onData("ACK 2 254 " . ($iRealSeq + 1000) . "\n");
        $this->assertSame(1, $oProviderY->getBlocksSent(), 'a mismatched-sequence relayed ack must not advance the chain');
        $this->assertFalse($oProviderY->isComplete());

        // The real relayed ack, arriving after the stray one, must still drive the chain.
        $oConnYtoX->onData("ACK 2 254 {$iRealSeq}\n");
        $this->assertSame(2, $oProviderY->getBlocksSent(), 'the correct-sequence relayed ack must still advance the chain');
    }
}
