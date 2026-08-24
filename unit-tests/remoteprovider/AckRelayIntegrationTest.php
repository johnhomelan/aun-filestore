<?php

/*
 * @group unit-tests
 *
 * End-to-end (loopback) test proving the filestored-side half of the ACK relay chain works
 * through the real ServiceDispatcher::ackEvents() entry point, not just that AckRelayMap's own
 * methods compile in isolation (see RelayServerTest for those) - see
 * docs/protocols/remote-provider.md § Ack Relay. Mirrors
 * unit-tests/remotebridge/RemoteBridgeAckRelayIntegrationTest.php for the sibling mechanism.
 *
 * Topology: a real ServiceDispatcher with a real ProxyProvider/RelayServer pair, standing in for
 * filestored. A remote provider (never actually instantiated here - see ClientTest/HostTest for
 * that half) has, via ProxyProvider::relay()/RelayServer::relayInbound(), had a request forwarded
 * to it for (net 0, stn 42); it replies, which RelayServer relays out (remembering the ack via
 * AckRelayMap - handlePacket() does this whenever ProxyProvider::injectReply() is invoked, so a
 * direct injectReply() call here stands in for "the remote provider's reply arrived"). A genuine
 * local ack for that station then arrives at $oDispatcher->ackEvents(), which must relay an `ack`
 * frame back across the same connection.
 */

include_once(__DIR__ . '/../../src/include/system.inc.php');

use PHPUnit\Framework\TestCase;
use Monolog\Logger;
use Monolog\Handler\NullHandler;
use Ratchet\ConnectionInterface;
use HomeLan\FileStore\RemoteProvider\RelayServer;
use HomeLan\FileStore\RemoteProvider\AckRelayMap;
use HomeLan\FileStore\RemoteProvider\Messages\Frame;
use HomeLan\FileStore\Services\Provider\ProxyProvider;
use HomeLan\FileStore\Services\ServiceDispatcher;
use HomeLan\FileStore\Encapsulation\EncapsulationInterface;
use HomeLan\FileStore\Messages\EconetPacket;

#[\AllowDynamicProperties]
class AckRelayFakeConnection implements ConnectionInterface
{
    /** @var list<string> */
    public array $aSent = [];

    public function send($data)
    {
        $this->aSent[] = (string) $data;
        return $this;
    }

    public function close(): void
    {
    }

    /** @return list<Frame> */
    public function decodedFrames(): array
    {
        return array_map(static fn (string $sJson): Frame => Frame::decode($sJson), $this->aSent);
    }
}

/** A minimal EncapsulationInterface double standing in for a real local (AUN/Piconet/WebSocket)
 *  handler's Ack, i.e. a genuine hardware-level ack for filestored's own local traffic. */
class AckRelayRawLocalAck implements EncapsulationInterface
{
    public function __construct(private readonly int $iSrcNet, private readonly int $iSrcStn, private readonly ?int $iSeq)
    {
    }

    public function getPort(): int
    {
        return 0;
    }

    public function getPacketType(): string
    {
        return 'Ack';
    }

    public function getData(): string
    {
        return '';
    }

    public function getSequence(): ?int
    {
        return $this->iSeq;
    }

    public function decode(string $sBinaryString): void
    {
    }

    public function buildEconetPacket(): EconetPacket
    {
        $oPacket = new EconetPacket();
        $oPacket->setSourceNetwork($this->iSrcNet);
        $oPacket->setSourceStation($this->iSrcStn);
        $oPacket->setPort(0);
        $oPacket->setFlags(0);
        $oPacket->setData('');
        return $oPacket;
    }

    public function toString(): string
    {
        return "AckRelayRawLocalAck({$this->iSrcNet}.{$this->iSrcStn})";
    }
}

class AckRelayIntegrationTest extends TestCase
{
    private Logger $oLogger;

    protected function setUp(): void
    {
        $this->oLogger = new Logger('test');
        $this->oLogger->pushHandler(new NullHandler());
        AckRelayMap::reset();
    }

    protected function tearDown(): void
    {
        AckRelayMap::reset();
    }

    public function testRealLocalAckIsRelayedToTheConnectionAWaitingReplyWentOutOver(): void
    {
        $oProxyProvider = new ProxyProvider($this->oLogger, [182]);
        $oDispatcher = new ServiceDispatcher($this->oLogger, [$oProxyProvider]);

        $oRelayServer = new RelayServer(
            $this->oLogger,
            'secret',
            $oProxyProvider->injectReply(...),
            $oProxyProvider->claimStreamPort(...),
        );
        $oProxyProvider->setRelayServer($oRelayServer);

        $oConn = new AckRelayFakeConnection();
        $oRelayServer->onOpen($oConn);
        $oRelayServer->onMessage($oConn, Frame::hello('secret')->encode());
        $oRelayServer->onMessage($oConn, Frame::register([182])->encode());

        // The remote provider's reply to (net 0, stn 42), sequence 99, arriving back over the
        // connection - handlePacket() remembers the ack relay for it.
        $oRelayServer->onMessage($oConn, Frame::packet(Frame::KIND_UNICAST, 0, 254, 0, 42, 182, 0, 'block 1')->encode());

        // A genuine hardware-level ack for that station now arrives at filestored's own
        // ServiceDispatcher - nothing local is registered for it (the callback lives in the
        // remote process), so this must fall through to AckRelayMap.
        $oDispatcher->ackEvents(new AckRelayRawLocalAck(0, 42, 99));

        $aAckFrames = array_values(array_filter($oConn->decodedFrames(), static fn (Frame $oFrame): bool => $oFrame->getType() === Frame::TYPE_ACK));
        $this->assertCount(1, $aAckFrames);
        $this->assertSame(0, $aAckFrames[0]->getNet());
        $this->assertSame(42, $aAckFrames[0]->getStn());
    }

    public function testAckForAStationNothingWasRelayedToIsNotForwarded(): void
    {
        $oProxyProvider = new ProxyProvider($this->oLogger, [182]);
        $oDispatcher = new ServiceDispatcher($this->oLogger, [$oProxyProvider]);
        new RelayServer($this->oLogger, 'secret', $oProxyProvider->injectReply(...), $oProxyProvider->claimStreamPort(...));

        // No packet was ever relayed for (net 5, stn 5) - this must be a no-op, not an error.
        $oDispatcher->ackEvents(new AckRelayRawLocalAck(5, 5, null));
        $this->addToAssertionCount(1);
    }
}
