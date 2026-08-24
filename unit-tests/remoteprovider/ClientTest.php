<?php

/*
 * @group unit-tests
 *
 * Tests for Client, the connecting side of the Remote Provider Protocol (see
 * docs/protocols/remote-provider.md), used by ecosyslogd (and any future provider host) to
 * receive Econet packets relayed from ports it has registered on filestored's ServiceDispatcher:
 *   - registerPorts() before hello_ok is not sent immediately
 *   - registerPorts() after hello_ok is sent straight away
 *   - every previously-requested port is re-registered on a second hello_ok (i.e. after a
 *     reconnect)
 *   - a `packet` frame is emitted as a 'packet' event with a correctly-built EconetPacket
 *   - sendPacket() sends a `packet` frame
 *   - claimStreamPort() sends a claim_stream frame and resolves/rejects its promise on the
 *     matching stream_claimed/stream_claim_failed reply, or times out if neither arrives
 *   - an `ack` frame is emitted as an 'ack' event
 *   - a malformed frame is discarded without throwing
 *   - an auth_fail frame makes handleFrame() throw AuthenticationFailedException
 *
 * The real network connection (Ratchet\Client\Connector) is not exercised here; connect() itself
 * is left untested (it is a thin wrapper around that library), and TestableRemoteProviderClient
 * overrides rawSend() so tests can inspect what would have gone out over the socket without one
 * actually existing.
 */

include_once(__DIR__.'/../../src/include/system.inc.php');

use PHPUnit\Framework\TestCase;
use Monolog\Logger;
use Monolog\Handler\NullHandler;
use HomeLan\FileStore\RemoteProvider\Client;
use HomeLan\FileStore\RemoteProvider\Messages\Frame;
use HomeLan\FileStore\RemoteProvider\Exceptions\AuthenticationFailedException;
use HomeLan\FileStore\Messages\EconetPacket;

class TestableRemoteProviderClient extends Client
{
    /** @var list<string> */
    public array $aSent = [];

    protected function rawSend(string $sEncoded): void
    {
        $this->aSent[] = $sEncoded;
    }

    /** @return list<Frame> */
    public function decodedSentFrames(): array
    {
        return array_map(static fn (string $sJson): Frame => Frame::decode($sJson), $this->aSent);
    }
}

class RemoteProviderClientTest extends TestCase
{
    private Logger $oLogger;

    private \React\EventLoop\LoopInterface $oLoop;

    protected function setUp(): void
    {
        $this->oLogger = new Logger('test');
        $this->oLogger->pushHandler(new NullHandler());
    }

    private function buildClient(): TestableRemoteProviderClient
    {
        $this->oLoop = \React\EventLoop\Factory::create();
        return new TestableRemoteProviderClient($this->oLoop, $this->oLogger, 'ws://127.0.0.1:8092', 'test-secret');
    }

    public function testRegistrationBeforeHelloOkIsNotSentImmediately(): void
    {
        $oClient = $this->buildClient();
        $oClient->registerPorts([182]);
        $this->assertEmpty($oClient->aSent);
    }

    public function testRegistrationIsFlushedOnHelloOk(): void
    {
        $oClient = $this->buildClient();
        $oClient->registerPorts([182]);
        $oClient->handleFrame(Frame::helloOk(Frame::PROTOCOL_VERSION)->encode());

        $aFrames = $oClient->decodedSentFrames();
        $oRegister = $aFrames[count($aFrames) - 1];
        $this->assertSame(Frame::TYPE_REGISTER, $oRegister->getType());
        $this->assertSame([182], $oRegister->getPorts());
    }

    public function testRegistrationAfterHelloOkIsSentImmediately(): void
    {
        $oClient = $this->buildClient();
        $oClient->handleFrame(Frame::helloOk(Frame::PROTOCOL_VERSION)->encode());
        $oClient->registerPorts([183]);

        $aFrames = $oClient->decodedSentFrames();
        $oRegister = $aFrames[count($aFrames) - 1];
        $this->assertSame(Frame::TYPE_REGISTER, $oRegister->getType());
        $this->assertSame([183], $oRegister->getPorts());
    }

    public function testMultiplePortsAreMergedIntoOneRegistration(): void
    {
        $oClient = $this->buildClient();
        $oClient->registerPorts([182]);
        $oClient->registerPorts([183]);
        $oClient->handleFrame(Frame::helloOk(Frame::PROTOCOL_VERSION)->encode());

        $aRegisterFrames = array_values(array_filter($oClient->decodedSentFrames(), static fn (Frame $oFrame): bool => $oFrame->getType() === Frame::TYPE_REGISTER));
        $this->assertCount(1, $aRegisterFrames);
        $aPorts = $aRegisterFrames[0]->getPorts();
        sort($aPorts);
        $this->assertSame([182, 183], $aPorts);
    }

    public function testAllPortsAreReRegisteredOnASecondHelloOk(): void
    {
        // Simulates a reconnect: connect, register a port, then a second hello_ok arrives (as it
        // would after Client reconnects and re-authenticates) without any new registerPorts()
        // call in between.
        $oClient = $this->buildClient();
        $oClient->registerPorts([182]);
        $oClient->handleFrame(Frame::helloOk(Frame::PROTOCOL_VERSION)->encode());
        $oClient->aSent = []; // discard the first registration, only interested in the reconnect

        $oClient->handleFrame(Frame::helloOk(Frame::PROTOCOL_VERSION)->encode());

        $aRegisterFrames = array_values(array_filter(
            $oClient->decodedSentFrames(),
            static fn (Frame $oFrame): bool => $oFrame->getType() === Frame::TYPE_REGISTER,
        ));
        $this->assertCount(1, $aRegisterFrames);
        $this->assertSame([182], $aRegisterFrames[0]->getPorts());
    }

    public function testPacketFrameIsEmittedAsAPacketEvent(): void
    {
        $oClient = $this->buildClient();
        $oClient->handleFrame(Frame::helloOk(Frame::PROTOCOL_VERSION)->encode());

        $aReceived = [];
        $oClient->on('packet', function (string $sKind, EconetPacket $oPacket) use (&$aReceived): void {
            $aReceived[] = [$sKind, $oPacket];
        });

        $oClient->handleFrame(Frame::packet(Frame::KIND_UNICAST, 0, 42, 0, 254, 182, 0x80, "\x06hello")->encode());

        $this->assertCount(1, $aReceived);
        [$sKind, $oPacket] = $aReceived[0];
        $this->assertSame(Frame::KIND_UNICAST, $sKind);
        $this->assertSame(0, $oPacket->getSourceNetwork());
        $this->assertSame(42, $oPacket->getSourceStation());
        $this->assertSame(254, $oPacket->getDestinationStation());
        $this->assertSame(182, $oPacket->getPort());
        $this->assertSame(0x80, $oPacket->getFlags());
        $this->assertSame("\x06hello", $oPacket->getData());
    }

    public function testSendPacketSendsAPacketFrame(): void
    {
        $oClient = $this->buildClient();
        $oPacket = new EconetPacket();
        $oPacket->setSourceNetwork(0);
        $oPacket->setSourceStation(254);
        $oPacket->setDestinationNetwork(0);
        $oPacket->setDestinationStation(42);
        $oPacket->setPort(182);
        $oPacket->setFlags(0);
        $oPacket->setData('reply payload');

        $oClient->sendPacket(Frame::KIND_UNICAST, $oPacket);

        [$oFrame] = $oClient->decodedSentFrames();
        $this->assertSame(Frame::TYPE_PACKET, $oFrame->getType());
        $this->assertSame(254, $oFrame->getSrcStn());
        $this->assertSame(42, $oFrame->getDstStn());
        $this->assertSame(182, $oFrame->getPort());
        $this->assertSame('reply payload', $oFrame->getPayload());
    }

    public function testClaimStreamPortSendsAClaimStreamFrame(): void
    {
        $oClient = $this->buildClient();
        $oClient->claimStreamPort(60);

        [$oFrame] = $oClient->decodedSentFrames();
        $this->assertSame(Frame::TYPE_CLAIM_STREAM, $oFrame->getType());
        $this->assertSame(60, $oFrame->getTimeout());
        $this->assertNotSame('', $oFrame->getRequestId());
    }

    public function testStreamClaimedResolvesWithTheAllocatedPort(): void
    {
        $oClient = $this->buildClient();
        $oPromise = $oClient->claimStreamPort(60);
        [$oSent] = $oClient->decodedSentFrames();

        $mResolved = null;
        $oPromise->then(function (int $iPort) use (&$mResolved): void {
            $mResolved = $iPort;
        });

        $oClient->handleFrame(Frame::streamClaimed($oSent->getRequestId(), 30)->encode());

        $this->assertSame(30, $mResolved);
    }

    public function testStreamClaimFailedRejectsThePromise(): void
    {
        $oClient = $this->buildClient();
        $oPromise = $oClient->claimStreamPort(60);
        [$oSent] = $oClient->decodedSentFrames();

        $mRejectedWith = null;
        $oPromise->then(null, function (\Throwable $oError) use (&$mRejectedWith): void {
            $mRejectedWith = $oError;
        });

        $oClient->handleFrame(Frame::streamClaimFailed($oSent->getRequestId(), 'no free stream ports')->encode());

        $this->assertInstanceOf(\Throwable::class, $mRejectedWith);
        $this->assertStringContainsString('no free stream ports', $mRejectedWith->getMessage());
    }

    public function testClaimStreamPortTimesOutIfNoReplyArrives(): void
    {
        $oClient = $this->buildClient();
        $oPromise = $oClient->claimStreamPort(0);

        $mRejectedWith = null;
        $oPromise->then(null, function (\Throwable $oError) use (&$mRejectedWith): void {
            $mRejectedWith = $oError;
        });

        $this->oLoop->run();

        $this->assertInstanceOf(\Throwable::class, $mRejectedWith);
    }

    public function testAckFrameIsEmittedAsAnAckEvent(): void
    {
        $oClient = $this->buildClient();

        $aReceived = [];
        $oClient->on('ack', function (int $iNetwork, int $iStation, ?int $iSeq) use (&$aReceived): void {
            $aReceived[] = [$iNetwork, $iStation, $iSeq];
        });

        $oClient->handleFrame(Frame::ack(0, 42, 99)->encode());

        $this->assertSame([[0, 42, 99]], $aReceived);
    }

    public function testMalformedFrameIsDiscardedWithoutThrowing(): void
    {
        $oClient = $this->buildClient();
        $oClient->handleFrame('not json at all');
        $this->addToAssertionCount(1);
    }

    public function testAuthFailThrowsAuthenticationFailedException(): void
    {
        $oClient = $this->buildClient();
        $this->expectException(AuthenticationFailedException::class);
        $oClient->handleFrame(Frame::authFail()->encode());
    }
}
