<?php

/*
 * @group unit-tests
 *
 * Tests for Host, the glue between a Client and a local ServiceDispatcher instance (see
 * docs/protocols/remote-provider.md):
 *   - the constructor registers every hosted provider's ports with the client
 *   - an inbound unicast 'packet' event is dispatched to the matching provider's
 *     unicastPacketIn(), and its queued replies are sent back as `packet` frames
 *   - an inbound broadcast 'packet' event calls broadcastPacketIn() instead
 *   - a packet for a port nothing hosts is dropped without error
 *   - flush() drains every hosted provider's getReplies(), independent of dispatch()
 *   - a reply addressed to station 255 is sent with kind "broadcast"
 *   - an 'ack' event from the client fires the matching addAckEvent() callback registered on
 *     the local ServiceDispatcher
 *   - claimStreamPort() resolves with the allocated port and binds it on the local
 *     ServiceDispatcher, so a subsequent packet for it dispatches to the right provider
 */

include_once(__DIR__.'/../../src/include/system.inc.php');

use PHPUnit\Framework\TestCase;
use Monolog\Logger;
use Monolog\Handler\NullHandler;
use HomeLan\FileStore\RemoteProvider\Client;
use HomeLan\FileStore\RemoteProvider\Host;
use HomeLan\FileStore\RemoteProvider\Messages\Frame;
use HomeLan\FileStore\Services\ServiceDispatcher;
use HomeLan\FileStore\Services\ProviderInterface;
use HomeLan\FileStore\Services\Provider\AdminInterface;
use HomeLan\FileStore\Messages\EconetPacket;

class HostTestClient extends Client
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

class HostTestStubProvider implements ProviderInterface
{
    /** @var list<EconetPacket> */
    public array $aUnicastCalls = [];
    /** @var list<EconetPacket> */
    public array $aBroadcastCalls = [];
    /** @var list<EconetPacket> */
    public array $aQueuedReplies = [];

    public function __construct(private readonly array $aPorts)
    {
    }

    public function getName(): string
    {
        return 'stub';
    }

    public function getAdminInterface(): ?AdminInterface
    {
        return null;
    }

    public function unicastPacketIn(EconetPacket $oPacket): void
    {
        $this->aUnicastCalls[] = $oPacket;
    }

    public function broadcastPacketIn(EconetPacket $oPacket): void
    {
        $this->aBroadcastCalls[] = $oPacket;
    }

    public function getServicePorts(): array
    {
        return $this->aPorts;
    }

    public function registerService(ServiceDispatcher $oServiceDispatcher): void
    {
    }

    public function getJobs(): array
    {
        return [];
    }

    public function getReplies(): array
    {
        $aReplies = $this->aQueuedReplies;
        $this->aQueuedReplies = [];
        return $aReplies;
    }
}

class HostTest extends TestCase
{
    private Logger $oLogger;

    protected function setUp(): void
    {
        $this->oLogger = new Logger('test');
        $this->oLogger->pushHandler(new NullHandler());
    }

    private function buildClient(): HostTestClient
    {
        $oLoop = \React\EventLoop\Factory::create();
        return new HostTestClient($oLoop, $this->oLogger, 'ws://127.0.0.1:8092', 'test-secret');
    }

    private function samplePacket(int $iPort, int $iDstStn = 254): EconetPacket
    {
        $oPacket = new EconetPacket();
        $oPacket->setSourceNetwork(0);
        $oPacket->setSourceStation(42);
        $oPacket->setDestinationNetwork(0);
        $oPacket->setDestinationStation($iDstStn);
        $oPacket->setPort($iPort);
        $oPacket->setData('payload');
        return $oPacket;
    }

    public function testConstructorRegistersAllHostedProvidersPorts(): void
    {
        $oClient = $this->buildClient();
        $oDispatcher = new ServiceDispatcher($this->oLogger, [new HostTestStubProvider([182]), new HostTestStubProvider([183])]);
        new Host($oClient, $oDispatcher, $this->oLogger);

        $oClient->handleFrame(Frame::helloOk(Frame::PROTOCOL_VERSION)->encode());

        $aRegisterFrames = array_values(array_filter($oClient->decodedSentFrames(), static fn (Frame $oFrame): bool => $oFrame->getType() === Frame::TYPE_REGISTER));
        $this->assertCount(1, $aRegisterFrames);
        $aPorts = $aRegisterFrames[0]->getPorts();
        sort($aPorts);
        $this->assertSame([182, 183], $aPorts);
    }

    public function testUnicastPacketIsDispatchedToTheMatchingProvider(): void
    {
        $oClient = $this->buildClient();
        $oProvider = new HostTestStubProvider([182]);
        $oDispatcher = new ServiceDispatcher($this->oLogger, [$oProvider]);
        new Host($oClient, $oDispatcher, $this->oLogger);

        $oClient->handleFrame(Frame::packet(Frame::KIND_UNICAST, 0, 42, 0, 254, 182, 0, 'payload')->encode());

        $this->assertCount(1, $oProvider->aUnicastCalls);
        $this->assertEmpty($oProvider->aBroadcastCalls);
        $this->assertSame(42, $oProvider->aUnicastCalls[0]->getSourceStation());
    }

    public function testBroadcastPacketCallsBroadcastPacketIn(): void
    {
        $oClient = $this->buildClient();
        $oProvider = new HostTestStubProvider([182]);
        $oDispatcher = new ServiceDispatcher($this->oLogger, [$oProvider]);
        new Host($oClient, $oDispatcher, $this->oLogger);

        $oClient->handleFrame(Frame::packet(Frame::KIND_BROADCAST, 0, 42, 0, 255, 182, 0, 'payload')->encode());

        $this->assertCount(1, $oProvider->aBroadcastCalls);
        $this->assertEmpty($oProvider->aUnicastCalls);
    }

    public function testRepliesQueuedDuringDispatchAreSentBack(): void
    {
        $oClient = $this->buildClient();
        $oProvider = new HostTestStubProvider([182]);
        $oDispatcher = new ServiceDispatcher($this->oLogger, [$oProvider]);
        new Host($oClient, $oDispatcher, $this->oLogger);

        $oReply = $this->samplePacket(182, 42);
        $oProvider->aQueuedReplies = [$oReply];

        $oClient->handleFrame(Frame::packet(Frame::KIND_UNICAST, 0, 42, 0, 254, 182, 0, 'payload')->encode());

        $aPacketFrames = array_values(array_filter($oClient->decodedSentFrames(), static fn (Frame $oFrame): bool => $oFrame->getType() === Frame::TYPE_PACKET));
        $this->assertCount(1, $aPacketFrames);
        $this->assertSame(Frame::KIND_UNICAST, $aPacketFrames[0]->getKind());
        $this->assertSame(42, $aPacketFrames[0]->getDstStn());
    }

    public function testReplyToStation255IsSentAsBroadcastKind(): void
    {
        $oClient = $this->buildClient();
        $oProvider = new HostTestStubProvider([182]);
        $oDispatcher = new ServiceDispatcher($this->oLogger, [$oProvider]);
        new Host($oClient, $oDispatcher, $this->oLogger);

        $oProvider->aQueuedReplies = [$this->samplePacket(182, 255)];
        $oClient->handleFrame(Frame::packet(Frame::KIND_UNICAST, 0, 42, 0, 254, 182, 0, 'payload')->encode());

        $aPacketFrames = array_values(array_filter($oClient->decodedSentFrames(), static fn (Frame $oFrame): bool => $oFrame->getType() === Frame::TYPE_PACKET));
        $this->assertSame(Frame::KIND_BROADCAST, $aPacketFrames[0]->getKind());
    }

    public function testPacketForUnknownPortIsDroppedWithoutError(): void
    {
        $oClient = $this->buildClient();
        $oProvider = new HostTestStubProvider([182]);
        $oDispatcher = new ServiceDispatcher($this->oLogger, [$oProvider]);
        new Host($oClient, $oDispatcher, $this->oLogger);

        $oClient->handleFrame(Frame::packet(Frame::KIND_UNICAST, 0, 42, 0, 254, 199, 0, 'payload')->encode());

        $this->assertEmpty($oProvider->aUnicastCalls);
        $this->assertEmpty(array_filter($oClient->decodedSentFrames(), static fn (Frame $oFrame): bool => $oFrame->getType() === Frame::TYPE_PACKET));
    }

    public function testFlushSendsRepliesQueuedOutsideOfDispatch(): void
    {
        $oClient = $this->buildClient();
        $oProvider = new HostTestStubProvider([182]);
        $oDispatcher = new ServiceDispatcher($this->oLogger, [$oProvider]);
        $oHost = new Host($oClient, $oDispatcher, $this->oLogger);

        // Simulates a housekeeping-driven async push, queued with no inbound packet involved.
        $oProvider->aQueuedReplies = [$this->samplePacket(182, 42)];
        $oHost->flush();

        $aPacketFrames = array_values(array_filter($oClient->decodedSentFrames(), static fn (Frame $oFrame): bool => $oFrame->getType() === Frame::TYPE_PACKET));
        $this->assertCount(1, $aPacketFrames);
    }

    public function testAckEventFiresTheMatchingAddAckEventCallback(): void
    {
        $oClient = $this->buildClient();
        $oProvider = new HostTestStubProvider([182]);
        $oDispatcher = new ServiceDispatcher($this->oLogger, [$oProvider]);
        new Host($oClient, $oDispatcher, $this->oLogger);

        $bFired = false;
        $oDispatcher->addAckEvent(0, 42, 99, function () use (&$bFired): void {
            $bFired = true;
        });

        $oClient->handleFrame(Frame::ack(0, 42, 99)->encode());

        $this->assertTrue($bFired);
    }

    public function testClaimStreamPortResolvesAndBindsThePortLocally(): void
    {
        $oClient = $this->buildClient();
        $oProvider = new HostTestStubProvider([182]);
        $oDispatcher = new ServiceDispatcher($this->oLogger, [$oProvider]);
        $oHost = new Host($oClient, $oDispatcher, $this->oLogger);

        $oPromise = $oHost->claimStreamPort($oProvider, 30);
        $aClaimFrames = array_values(array_filter($oClient->decodedSentFrames(), static fn (Frame $oFrame): bool => $oFrame->getType() === Frame::TYPE_CLAIM_STREAM));
        $this->assertCount(1, $aClaimFrames);

        $mResolved = null;
        $oPromise->then(function (int $iPort) use (&$mResolved): void {
            $mResolved = $iPort;
        });

        $oClient->handleFrame(Frame::streamClaimed($aClaimFrames[0]->getRequestId(), 20)->encode());

        $this->assertSame(20, $mResolved);
        $this->assertSame($oProvider, $oDispatcher->getServiceByPort(20));
    }
}
