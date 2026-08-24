<?php

/*
 * @group unit-tests
 *
 * Tests for RelayServer, the listening side of the Remote Provider Protocol
 * (see docs/protocols/remote-provider.md):
 *   - hello handshake: version accept/reject, secret accept/reject
 *   - register: accept, and reject on a port already claimed by another connection
 *   - relayInbound(): dispatches a `packet` frame to the registered connection, or reports
 *     nothing was registered
 *   - a `packet` frame received from a client invokes the injected reply callback
 *   - claim_stream: success gets stream_claimed and registers the allocated port; failure gets
 *     stream_claim_failed
 *   - a `packet` frame relayed to a real station (not a broadcast) remembers an ack relay for
 *     it; a broadcast reply does not
 *   - ping is answered with pong
 *   - onClose() releases that connection's registrations
 */

include_once(__DIR__.'/../../src/include/system.inc.php');

use PHPUnit\Framework\TestCase;
use Monolog\Logger;
use Monolog\Handler\NullHandler;
use Ratchet\ConnectionInterface;
use HomeLan\FileStore\RemoteProvider\RelayServer;
use HomeLan\FileStore\RemoteProvider\AckRelayMap;
use HomeLan\FileStore\RemoteProvider\Messages\Frame;
use HomeLan\FileStore\Messages\EconetPacket;

#[\AllowDynamicProperties]
class FakeProviderRelayConnection implements ConnectionInterface
{
    /** @var list<string> */
    public array $aSent = [];
    public bool $bClosed = false;

    public function send($data)
    {
        $this->aSent[] = (string) $data;
        return $this;
    }

    public function close(): void
    {
        $this->bClosed = true;
    }

    /** @return list<Frame> */
    public function decodedFrames(): array
    {
        return array_map(static fn (string $sJson): Frame => Frame::decode($sJson), $this->aSent);
    }
}

class RemoteProviderRelayServerTest extends TestCase
{
    private const SECRET = 'correct-horse-battery-staple';

    private Logger $oLogger;

    protected function setUp(): void
    {
        $this->oLogger = new Logger('test');
        $this->oLogger->pushHandler(new NullHandler());
        AckRelayMap::reset();
    }

    /** @var list<EconetPacket> */
    private array $aInjectedReplies = [];

    private function buildServer(?callable $fClaimStreamPort = null): RelayServer
    {
        $this->aInjectedReplies = [];
        return new RelayServer(
            $this->oLogger,
            self::SECRET,
            function (EconetPacket $oPacket): void {
                $this->aInjectedReplies[] = $oPacket;
            },
            $fClaimStreamPort ?? fn (int $iTimeout): ?int => null,
        );
    }

    private function authedConnection(RelayServer $oServer): FakeProviderRelayConnection
    {
        $oConn = new FakeProviderRelayConnection();
        $oServer->onOpen($oConn);
        $oServer->onMessage($oConn, Frame::hello(self::SECRET)->encode());
        return $oConn;
    }

    private function samplePacket(int $iPort = 182): EconetPacket
    {
        $oPacket = new EconetPacket();
        $oPacket->setSourceNetwork(0);
        $oPacket->setSourceStation(42);
        $oPacket->setDestinationNetwork(0);
        $oPacket->setDestinationStation(254);
        $oPacket->setPort($iPort);
        $oPacket->setFlags(0x80);
        $oPacket->setData("\x06hello");
        return $oPacket;
    }

    // -----------------------------------------------------------------------
    // Handshake
    // -----------------------------------------------------------------------

    public function testHelloWithCorrectSecretAndVersionGetsHelloOk(): void
    {
        $oServer = $this->buildServer();
        $oConn = new FakeProviderRelayConnection();
        $oServer->onOpen($oConn);
        $oServer->onMessage($oConn, Frame::hello(self::SECRET)->encode());

        [$oReply] = $oConn->decodedFrames();
        $this->assertSame(Frame::TYPE_HELLO_OK, $oReply->getType());
        $this->assertFalse($oConn->bClosed);
    }

    public function testHelloWithWrongSecretGetsAuthFailAndCloses(): void
    {
        $oServer = $this->buildServer();
        $oConn = new FakeProviderRelayConnection();
        $oServer->onOpen($oConn);
        $oServer->onMessage($oConn, Frame::hello('wrong-secret')->encode());

        [$oReply] = $oConn->decodedFrames();
        $this->assertSame(Frame::TYPE_AUTH_FAIL, $oReply->getType());
        $this->assertTrue($oConn->bClosed);
    }

    public function testHelloWithUnsupportedVersionGetsVersionRejectAndCloses(): void
    {
        $oServer = $this->buildServer();
        $oConn = new FakeProviderRelayConnection();
        $oServer->onOpen($oConn);
        $oServer->onMessage($oConn, Frame::hello(self::SECRET, ['9.9'])->encode());

        [$oReply] = $oConn->decodedFrames();
        $this->assertSame(Frame::TYPE_VERSION_REJECT, $oReply->getType());
        $this->assertTrue($oConn->bClosed);
    }

    public function testRegisterBeforeHelloIsIgnored(): void
    {
        $oServer = $this->buildServer();
        $oConn = new FakeProviderRelayConnection();
        $oServer->onOpen($oConn);
        $oServer->onMessage($oConn, Frame::register([182])->encode());

        $this->assertEmpty($oConn->decodedFrames());
    }

    // -----------------------------------------------------------------------
    // Registration
    // -----------------------------------------------------------------------

    public function testRegisterGetsRegisterOk(): void
    {
        $oServer = $this->buildServer();
        $oConn = $this->authedConnection($oServer);
        $oServer->onMessage($oConn, Frame::register([182])->encode());

        $aFrames = $oConn->decodedFrames();
        $oReply = $aFrames[count($aFrames) - 1];
        $this->assertSame(Frame::TYPE_REGISTER_OK, $oReply->getType());
        $this->assertSame(182, $oReply->getPort());
    }

    public function testSecondRegistrationForSamePortFails(): void
    {
        $oServer = $this->buildServer();
        $oConnA = $this->authedConnection($oServer);
        $oServer->onMessage($oConnA, Frame::register([182])->encode());

        $oConnB = $this->authedConnection($oServer);
        $oServer->onMessage($oConnB, Frame::register([182])->encode());

        $aFrames = $oConnB->decodedFrames();
        $oReply = $aFrames[count($aFrames) - 1];
        $this->assertSame(Frame::TYPE_REGISTER_FAIL, $oReply->getType());
    }

    public function testRegistrationIsFreedOnClose(): void
    {
        $oServer = $this->buildServer();
        $oConnA = $this->authedConnection($oServer);
        $oServer->onMessage($oConnA, Frame::register([182])->encode());
        $oServer->onClose($oConnA);

        $oConnB = $this->authedConnection($oServer);
        $oServer->onMessage($oConnB, Frame::register([182])->encode());

        $aFrames = $oConnB->decodedFrames();
        $oReply = $aFrames[count($aFrames) - 1];
        $this->assertSame(Frame::TYPE_REGISTER_OK, $oReply->getType());
    }

    // -----------------------------------------------------------------------
    // Relay dispatch
    // -----------------------------------------------------------------------

    public function testRelayInboundReturnsFalseWhenNothingRegistered(): void
    {
        $oServer = $this->buildServer();
        $bDelivered = $oServer->relayInbound(Frame::KIND_UNICAST, $this->samplePacket());
        $this->assertFalse($bDelivered);
    }

    public function testRelayInboundDeliversPacketFrameToRegisteredConnection(): void
    {
        $oServer = $this->buildServer();
        $oConn = $this->authedConnection($oServer);
        $oServer->onMessage($oConn, Frame::register([182])->encode());

        $bDelivered = $oServer->relayInbound(Frame::KIND_UNICAST, $this->samplePacket());
        $this->assertTrue($bDelivered);

        $aFrames = $oConn->decodedFrames();
        $oData = $aFrames[count($aFrames) - 1];
        $this->assertSame(Frame::TYPE_PACKET, $oData->getType());
        $this->assertSame(Frame::KIND_UNICAST, $oData->getKind());
        $this->assertSame(0, $oData->getSrcNet());
        $this->assertSame(42, $oData->getSrcStn());
        $this->assertSame(254, $oData->getDstStn());
        $this->assertSame(182, $oData->getPort());
        $this->assertSame(0x80, $oData->getFlags());
        $this->assertSame("\x06hello", $oData->getPayload());
    }

    public function testPacketFrameFromClientInvokesInjectedReplyCallback(): void
    {
        $oServer = $this->buildServer();
        $oConn = $this->authedConnection($oServer);

        $oServer->onMessage($oConn, Frame::packet(Frame::KIND_UNICAST, 0, 254, 0, 42, 182, 0, 'reply payload')->encode());

        $this->assertCount(1, $this->aInjectedReplies);
        $this->assertSame(0, $this->aInjectedReplies[0]->getSourceNetwork());
        $this->assertSame(254, $this->aInjectedReplies[0]->getSourceStation());
        $this->assertSame(42, $this->aInjectedReplies[0]->getDestinationStation());
        $this->assertSame(182, $this->aInjectedReplies[0]->getPort());
        $this->assertSame('reply payload', $this->aInjectedReplies[0]->getData());
    }

    // -----------------------------------------------------------------------
    // Stream claims
    // -----------------------------------------------------------------------

    public function testClaimStreamSuccessGetsStreamClaimedAndRegistersThePort(): void
    {
        $oServer = $this->buildServer(fn (int $iTimeout): ?int => 30);
        $oConn = $this->authedConnection($oServer);

        $oServer->onMessage($oConn, Frame::claimStream('req-1', 60)->encode());

        $aFrames = $oConn->decodedFrames();
        $oReply = $aFrames[count($aFrames) - 1];
        $this->assertSame(Frame::TYPE_STREAM_CLAIMED, $oReply->getType());
        $this->assertSame('req-1', $oReply->getRequestId());
        $this->assertSame(30, $oReply->getPort());

        // The claimed port is registered exactly as an explicit register() would have.
        $bDelivered = $oServer->relayInbound(Frame::KIND_UNICAST, $this->samplePacket(30));
        $this->assertTrue($bDelivered);
    }

    public function testClaimStreamFailureGetsStreamClaimFailed(): void
    {
        $oServer = $this->buildServer(fn (int $iTimeout): ?int => null);
        $oConn = $this->authedConnection($oServer);

        $oServer->onMessage($oConn, Frame::claimStream('req-2', 60)->encode());

        $aFrames = $oConn->decodedFrames();
        $oReply = $aFrames[count($aFrames) - 1];
        $this->assertSame(Frame::TYPE_STREAM_CLAIM_FAILED, $oReply->getType());
        $this->assertSame('req-2', $oReply->getRequestId());
        $this->assertNotSame('', $oReply->getReason());
    }

    public function testReleaseStreamPortDropsTheRegistration(): void
    {
        $oServer = $this->buildServer(fn (int $iTimeout): ?int => 30);
        $oConn = $this->authedConnection($oServer);

        $oServer->onMessage($oConn, Frame::claimStream('req-3', 60)->encode());
        $oServer->releaseStreamPort(30);

        $bDelivered = $oServer->relayInbound(Frame::KIND_UNICAST, $this->samplePacket(30));
        $this->assertFalse($bDelivered);
    }

    // -----------------------------------------------------------------------
    // Ack relay
    // -----------------------------------------------------------------------

    public function testRelayingAPacketToARealStationRemembersAnAckRelay(): void
    {
        $oServer = $this->buildServer();
        $oConn = $this->authedConnection($oServer);

        // dstNet=0, dstStn=42 - a real station, not a broadcast.
        $oServer->onMessage($oConn, Frame::packet(Frame::KIND_UNICAST, 0, 254, 0, 42, 182, 0, 'block 1')->encode());

        $this->assertTrue(AckRelayMap::relayAckIfKnown(0, 42, 99));
        $aFrames = $oConn->decodedFrames();
        $oAck = $aFrames[count($aFrames) - 1];
        $this->assertSame(Frame::TYPE_ACK, $oAck->getType());
        $this->assertSame(0, $oAck->getNet());
        $this->assertSame(42, $oAck->getStn());
        $this->assertSame(99, $oAck->getSeq());
    }

    public function testRelayingABroadcastReplyDoesNotRememberAnAckRelay(): void
    {
        $oServer = $this->buildServer();
        $oConn = $this->authedConnection($oServer);

        // dstStn=255 - a broadcast, nobody to ack it.
        $oServer->onMessage($oConn, Frame::packet(Frame::KIND_BROADCAST, 0, 254, 0, 255, 182, 0, 'broadcast')->encode());

        $this->assertFalse(AckRelayMap::relayAckIfKnown(0, 255, null));
    }

    // -----------------------------------------------------------------------
    // Heartbeat
    // -----------------------------------------------------------------------

    public function testPingGetsPong(): void
    {
        $oServer = $this->buildServer();
        $oConn = $this->authedConnection($oServer);
        $oServer->onMessage($oConn, Frame::ping()->encode());

        $aFrames = $oConn->decodedFrames();
        $oReply = $aFrames[count($aFrames) - 1];
        $this->assertSame(Frame::TYPE_PONG, $oReply->getType());
    }

    // -----------------------------------------------------------------------
    // Malformed input
    // -----------------------------------------------------------------------

    public function testMalformedFrameIsDiscardedWithoutError(): void
    {
        $oServer = $this->buildServer();
        $oConn = new FakeProviderRelayConnection();
        $oServer->onOpen($oConn);
        $oServer->onMessage($oConn, 'not json at all');

        $this->assertEmpty($oConn->decodedFrames());
        $this->assertFalse($oConn->bClosed);
    }
}
