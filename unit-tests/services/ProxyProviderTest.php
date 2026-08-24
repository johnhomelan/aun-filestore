<?php

/*
 * @group unit-tests
 *
 * Tests for Services\Provider\ProxyProvider's stream-claim support (see
 * docs/protocols/remote-provider.md § Stream Claims):
 *   - claimStreamPort() delegates to the real ServiceDispatcher and returns the allocated port
 *   - claimStreamPort() returns null (rather than throwing) before registerService() has run,
 *     or once every stream port is exhausted
 *   - sweepExpiredStreamPorts(), run as a housekeeping task, releases RelayServer's registration
 *     for a port ServiceDispatcher's own expiry has already freed - one houseKeeping() cycle
 *     behind, as documented on the method itself
 *
 * The main relay path (unicastPacketIn/broadcastPacketIn/injectReply/getReplies) is already
 * covered end-to-end by unit-tests/remoteprovider/RelayServerTest.php and
 * AckRelayIntegrationTest.php, which exercise a real ProxyProvider wired to a real RelayServer.
 */

include_once(__DIR__.'/../../src/include/system.inc.php');

use PHPUnit\Framework\TestCase;
use Monolog\Logger;
use Monolog\Handler\NullHandler;
use Ratchet\ConnectionInterface;
use HomeLan\FileStore\Services\Provider\ProxyProvider;
use HomeLan\FileStore\Services\ServiceDispatcher;
use HomeLan\FileStore\RemoteProvider\RelayServer;
use HomeLan\FileStore\RemoteProvider\Messages\Frame;

#[\AllowDynamicProperties]
class ProxyProviderTestConnection implements ConnectionInterface
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

class ProxyProviderTest extends TestCase
{
    private Logger $oLogger;

    protected function setUp(): void
    {
        $this->oLogger = new Logger('test');
        $this->oLogger->pushHandler(new NullHandler());
    }

    public function testClaimStreamPortReturnsNullBeforeRegisterServiceHasRun(): void
    {
        $oProvider = new ProxyProvider($this->oLogger, [182]);
        $this->assertNull($oProvider->claimStreamPort(60));
    }

    public function testClaimStreamPortReturnsAnAllocatedPort(): void
    {
        $oProvider = new ProxyProvider($this->oLogger, [182]);
        new ServiceDispatcher($this->oLogger, [$oProvider]);

        $iPort = $oProvider->claimStreamPort(60);
        $this->assertGreaterThanOrEqual(20, $iPort);
        $this->assertLessThan(40, $iPort);
    }

    public function testClaimStreamPortReturnsNullOnceExhausted(): void
    {
        $oProvider = new ProxyProvider($this->oLogger, [182]);
        $oDispatcher = new ServiceDispatcher($this->oLogger, [$oProvider]);

        for ($i = 0; $i < ServiceDispatcher::MAX_STREAMS; $i++) {
            $this->assertIsInt($oProvider->claimStreamPort(60));
        }

        $this->assertNull($oProvider->claimStreamPort(60));
    }

    public function testSweepReleasesAnExpiredClaimsRelayServerRegistrationAfterOneMoreHouseKeepingCycle(): void
    {
        $oProvider = new ProxyProvider($this->oLogger, [182]);
        $oDispatcher = new ServiceDispatcher($this->oLogger, [$oProvider]);
        $oRelayServer = new RelayServer($this->oLogger, 'secret', $oProvider->injectReply(...), $oProvider->claimStreamPort(...));
        $oProvider->setRelayServer($oRelayServer);

        // Claim a stream port over the real wire protocol, with an already-expired timeout, so
        // RelayServer's own registration table (not just ProxyProvider's bookkeeping) is
        // populated exactly as a real remote connection claiming one would leave it.
        $oConn = new ProxyProviderTestConnection();
        $oRelayServer->onOpen($oConn);
        $oRelayServer->onMessage($oConn, Frame::hello('secret')->encode());
        $oRelayServer->onMessage($oConn, Frame::claimStream('req-1', -1)->encode());
        [, $oClaimed] = $oConn->decodedFrames();
        $this->assertSame(Frame::TYPE_STREAM_CLAIMED, $oClaimed->getType());
        $iPort = $oClaimed->getPort();

        // First cycle: ServiceDispatcher frees the port from its own tables, but the sweep -
        // which runs before that happens in the same houseKeeping() call - still sees $this
        // bound to it, so RelayServer's registration survives one cycle longer than the port
        // itself, as documented on sweepExpiredStreamPorts().
        $oDispatcher->houseKeeping();
        $this->assertNull($oDispatcher->getServiceByPort($iPort));
        $bDeliveredBeforeSecondCycle = $oRelayServer->relayInbound(Frame::KIND_UNICAST, self::samplePacket($iPort));
        $this->assertTrue($bDeliveredBeforeSecondCycle, 'the stale registration is still present after only one cycle, as documented');

        // Second cycle: the sweep now notices ServiceDispatcher no longer routes this port to
        // $this, and releases RelayServer's registration for it.
        $oDispatcher->houseKeeping();
        $bDeliveredAfterSecondCycle = $oRelayServer->relayInbound(Frame::KIND_UNICAST, self::samplePacket($iPort));
        $this->assertFalse($bDeliveredAfterSecondCycle);
    }

    private static function samplePacket(int $iPort): \HomeLan\FileStore\Messages\EconetPacket
    {
        $oPacket = new \HomeLan\FileStore\Messages\EconetPacket();
        $oPacket->setSourceNetwork(0);
        $oPacket->setSourceStation(42);
        $oPacket->setDestinationNetwork(0);
        $oPacket->setDestinationStation(254);
        $oPacket->setPort($iPort);
        $oPacket->setData('x');
        return $oPacket;
    }
}
