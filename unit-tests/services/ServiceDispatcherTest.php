<?php

/*
 * Unit tests for HomeLan\FileStore\Services\ServiceDispatcher.
 *
 * All tests construct ServiceDispatcher directly (not via ::create()) to avoid
 * singleton bleed between tests.
 *
 * Two test doubles are defined at the top of the file:
 *
 *   MockServiceProvider   — implements ProviderInterface; captures every call,
 *                           returns configurable replies from getReplies().
 *
 *   MockEncapsulation     — implements EncapsulationInterface; configurable
 *                           packet type, port, and source address for the
 *                           EconetPacket that buildEconetPacket() produces.
 */

include_once(__DIR__ . '/../../src/include/system.inc.php');

use PHPUnit\Framework\TestCase;
use Monolog\Logger;
use Monolog\Handler\NullHandler;
use HomeLan\FileStore\Services\ServiceDispatcher;
use HomeLan\FileStore\Services\ProviderInterface;
use HomeLan\FileStore\Encapsulation\EncapsulationInterface;
use HomeLan\FileStore\Messages\EconetPacket;

// =============================================================================
// MockServiceProvider
// =============================================================================

class MockServiceProvider implements ProviderInterface
{
    public int $iUnicastCalls    = 0;
    public int $iBroadcastCalls  = 0;
    public int $iRegisterCalls   = 0;
    public ?ServiceDispatcher $oRegisteredWith = null;

    /** @var EconetPacket[] */
    public array $aReplies = [];

    public function __construct(private array $aPorts = [0x99]) {}

    public function getName(): string { return 'MockService'; }
    public function getAdminInterface(): ?\HomeLan\FileStore\Services\Provider\AdminInterface { return null; }
    public function getJobs(): array { return []; }

    public function getServicePorts(): array { return $this->aPorts; }

    public function registerService(ServiceDispatcher $oDispatcher): void
    {
        $this->iRegisterCalls++;
        $this->oRegisteredWith = $oDispatcher;
    }

    public function unicastPacketIn(EconetPacket $oPacket): void
    {
        $this->iUnicastCalls++;
    }

    public function broadcastPacketIn(EconetPacket $oPacket): void
    {
        $this->iBroadcastCalls++;
    }

    public function getReplies(): array
    {
        $aOut = $this->aReplies;
        $this->aReplies = [];
        return $aOut;
    }

    public function queueReply(EconetPacket $oPkt): void
    {
        $this->aReplies[] = $oPkt;
    }
}

// =============================================================================
// MockEncapsulation
// =============================================================================

class MockEncapsulation implements EncapsulationInterface
{
    public function __construct(
        private string $sType   = 'Unicast',
        private int    $iPort   = 0x99,
        private int    $iSrcNet = 1,
        private int    $iSrcStn = 5,
    ) {}

    public function getPort(): int    { return $this->iPort; }
    public function getPacketType(): string { return $this->sType; }
    public function getData(): string { return ''; }
    public function decode(string $s): void {}
    public function toString(): string { return "mock:{$this->sType}:{$this->iPort}"; }

    public function buildEconetPacket(): EconetPacket
    {
        $oPkt = new EconetPacket();
        $oPkt->setSourceNetwork($this->iSrcNet);
        $oPkt->setSourceStation($this->iSrcStn);
        $oPkt->setDestinationNetwork(1);
        $oPkt->setDestinationstation(254);
        $oPkt->setPort($this->iPort);
        $oPkt->setFlags(0);
        $oPkt->setData('');
        return $oPkt;
    }
}

// =============================================================================
// Tests
// =============================================================================

class ServiceDispatcherTest extends TestCase
{
    private Logger $oLogger;

    protected function setUp(): void
    {
        $this->oLogger = new Logger('test');
        $this->oLogger->pushHandler(new NullHandler());
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function make(array $aProviders = []): ServiceDispatcher
    {
        return new ServiceDispatcher($this->oLogger, $aProviders);
    }

    private function packet(string $sType = 'Unicast', int $iPort = 0x99, int $iSrcNet = 1, int $iSrcStn = 5): MockEncapsulation
    {
        return new MockEncapsulation($sType, $iPort, $iSrcNet, $iSrcStn);
    }

    private function makeReply(int $iDstNet = 1, int $iDstStn = 5): EconetPacket
    {
        $oPkt = new EconetPacket();
        $oPkt->setDestinationNetwork($iDstNet);
        $oPkt->setDestinationstation($iDstStn);
        $oPkt->setSourceNetwork(1);
        $oPkt->setSourceStation(254);
        $oPkt->setPort(0x99);
        $oPkt->setFlags(0);
        $oPkt->setData('reply');
        return $oPkt;
    }

    // =========================================================================
    // Construction / addService()
    // =========================================================================

    public function testConstructorRegistersAllProviders(): void
    {
        $oA = new MockServiceProvider([0x99]);
        $oB = new MockServiceProvider([0x9D]);
        $this->make([$oA, $oB]);
        $this->assertSame(1, $oA->iRegisterCalls);
        $this->assertSame(1, $oB->iRegisterCalls);
    }

    public function testConstructorPassesSelfToRegisterService(): void
    {
        $oA = new MockServiceProvider([0x99]);
        $oDispatcher = $this->make([$oA]);
        $this->assertSame($oDispatcher, $oA->oRegisteredWith);
    }

    public function testGetServicesReturnsAllRegisteredProviders(): void
    {
        $oA = new MockServiceProvider([0x99]);
        $oB = new MockServiceProvider([0x9D]);
        $oDispatcher = $this->make([$oA, $oB]);
        $this->assertContains($oA, $oDispatcher->getServices());
        $this->assertContains($oB, $oDispatcher->getServices());
    }

    public function testAddServiceThrowsWhenServiceAlreadyAdded(): void
    {
        $this->expectException(\Exception::class);
        $oA = new MockServiceProvider([0x99]);
        $oDispatcher = $this->make([$oA]);
        $oDispatcher->addService($oA);
    }

    public function testAddServiceThrowsOnPortConflict(): void
    {
        $this->expectException(\Exception::class);
        $oA = new MockServiceProvider([0x99]);
        $oB = new MockServiceProvider([0x99]);   // same port
        $this->make([$oA, $oB]);
    }

    // =========================================================================
    // getServiceByPort()
    // =========================================================================

    public function testGetServiceByPortReturnsProviderForKnownPort(): void
    {
        $oA = new MockServiceProvider([0x99]);
        $oDispatcher = $this->make([$oA]);
        $this->assertSame($oA, $oDispatcher->getServiceByPort(0x99));
    }

    public function testGetServiceByPortReturnsNullForUnknownPort(): void
    {
        $oDispatcher = $this->make([]);
        $this->assertNull($oDispatcher->getServiceByPort(0x99));
    }

    // =========================================================================
    // inboundPacket() — Unicast
    // =========================================================================

    public function testUnicastPacketCallsUnicastPacketIn(): void
    {
        $oA = new MockServiceProvider([0x99]);
        $oDispatcher = $this->make([$oA]);
        $oDispatcher->inboundPacket($this->packet('Unicast', 0x99));
        $this->assertSame(1, $oA->iUnicastCalls);
    }

    public function testUnicastPacketDoesNotCallBroadcastPacketIn(): void
    {
        $oA = new MockServiceProvider([0x99]);
        $oDispatcher = $this->make([$oA]);
        $oDispatcher->inboundPacket($this->packet('Unicast', 0x99));
        $this->assertSame(0, $oA->iBroadcastCalls);
    }

    public function testUnicastPacketToUnregisteredPortNotDispatched(): void
    {
        $oA = new MockServiceProvider([0x99]);
        $oDispatcher = $this->make([$oA]);
        $oDispatcher->inboundPacket($this->packet('Unicast', 0x01));
        $this->assertSame(0, $oA->iUnicastCalls);
    }

    public function testUnicastRepliesQueuedForGetReplies(): void
    {
        $oA = new MockServiceProvider([0x99]);
        $oReply = $this->makeReply();
        $oA->queueReply($oReply);

        $oDispatcher = $this->make([$oA]);
        $oDispatcher->inboundPacket($this->packet('Unicast', 0x99));

        $aReplies = $oDispatcher->getReplies();
        $this->assertCount(1, $aReplies);
        $this->assertSame($oReply, $aReplies[0]);
    }

    public function testMultipleUnicastRepliesAllQueued(): void
    {
        $oA = new MockServiceProvider([0x99]);
        $oA->queueReply($this->makeReply());
        $oA->queueReply($this->makeReply());
        $oA->queueReply($this->makeReply());

        $oDispatcher = $this->make([$oA]);
        $oDispatcher->inboundPacket($this->packet('Unicast', 0x99));

        $this->assertCount(3, $oDispatcher->getReplies());
    }

    // =========================================================================
    // inboundPacket() — Immediate (shares Unicast path)
    // =========================================================================

    public function testImmediatePacketCallsUnicastPacketIn(): void
    {
        $oA = new MockServiceProvider([0x99]);
        $oDispatcher = $this->make([$oA]);
        $oDispatcher->inboundPacket($this->packet('Immediate', 0x99));
        $this->assertSame(1, $oA->iUnicastCalls);
    }

    public function testImmediatePacketDoesNotCallBroadcastPacketIn(): void
    {
        $oA = new MockServiceProvider([0x99]);
        $oDispatcher = $this->make([$oA]);
        $oDispatcher->inboundPacket($this->packet('Immediate', 0x99));
        $this->assertSame(0, $oA->iBroadcastCalls);
    }

    // =========================================================================
    // inboundPacket() — Broadcast
    // =========================================================================

    public function testBroadcastPacketCallsBroadcastPacketIn(): void
    {
        $oA = new MockServiceProvider([0x9C]);
        $oDispatcher = $this->make([$oA]);
        $oDispatcher->inboundPacket($this->packet('Broadcast', 0x9C));
        $this->assertSame(1, $oA->iBroadcastCalls);
    }

    public function testBroadcastPacketDoesNotCallUnicastPacketIn(): void
    {
        $oA = new MockServiceProvider([0x9C]);
        $oDispatcher = $this->make([$oA]);
        $oDispatcher->inboundPacket($this->packet('Broadcast', 0x9C));
        $this->assertSame(0, $oA->iUnicastCalls);
    }

    public function testBroadcastToUnregisteredPortNotDispatched(): void
    {
        $oA = new MockServiceProvider([0x9C]);
        $oDispatcher = $this->make([$oA]);
        $oDispatcher->inboundPacket($this->packet('Broadcast', 0x01));
        $this->assertSame(0, $oA->iBroadcastCalls);
    }

    public function testBroadcastRepliesQueued(): void
    {
        $oA = new MockServiceProvider([0x9C]);
        $oReply = $this->makeReply();
        $oA->queueReply($oReply);

        $oDispatcher = $this->make([$oA]);
        $oDispatcher->inboundPacket($this->packet('Broadcast', 0x9C));

        $this->assertSame([$oReply], $oDispatcher->getReplies());
    }

    // =========================================================================
    // inboundPacket() — Ack
    // =========================================================================

    public function testAckPacketDoesNotCallUnicastPacketIn(): void
    {
        $oA = new MockServiceProvider([0x99]);
        $oDispatcher = $this->make([$oA]);
        $oDispatcher->inboundPacket($this->packet('Ack', 0x99));
        $this->assertSame(0, $oA->iUnicastCalls);
    }

    public function testAckPacketDoesNotCallBroadcastPacketIn(): void
    {
        $oA = new MockServiceProvider([0x99]);
        $oDispatcher = $this->make([$oA]);
        $oDispatcher->inboundPacket($this->packet('Ack', 0x99));
        $this->assertSame(0, $oA->iBroadcastCalls);
    }

    public function testAckPacketFiresRegisteredAckEvent(): void
    {
        $oDispatcher = $this->make([]);
        $bFired = false;
        $oDispatcher->addAckEvent(1, 5, function() use (&$bFired) { $bFired = true; });
        $oDispatcher->inboundPacket($this->packet('Ack', 0x99, iSrcNet: 1, iSrcStn: 5));
        $this->assertTrue($bFired);
    }

    // =========================================================================
    // inboundPacket() — unknown type
    // =========================================================================

    public function testUnknownPacketTypeDoesNotCallUnicastOrBroadcast(): void
    {
        $oA = new MockServiceProvider([0x99]);
        $oDispatcher = $this->make([$oA]);
        $oDispatcher->inboundPacket($this->packet('Reject', 0x99));
        $this->assertSame(0, $oA->iUnicastCalls);
        $this->assertSame(0, $oA->iBroadcastCalls);
    }

    public function testUnknownPacketTypeDoesNotThrow(): void
    {
        $oDispatcher = $this->make([]);
        $oDispatcher->inboundPacket($this->packet('Reject', 0x99));
        $this->assertTrue(true);   // reached without exception
    }

    // =========================================================================
    // getReplies()
    // =========================================================================

    public function testGetRepliesReturnsEmptyArrayWhenNothingQueued(): void
    {
        $oDispatcher = $this->make([]);
        $this->assertSame([], $oDispatcher->getReplies());
    }

    public function testGetRepliesClearsQueueOnEachCall(): void
    {
        $oA = new MockServiceProvider([0x99]);
        $oA->queueReply($this->makeReply());

        $oDispatcher = $this->make([$oA]);
        $oDispatcher->inboundPacket($this->packet('Unicast', 0x99));

        $oDispatcher->getReplies();                        // first drain
        $this->assertSame([], $oDispatcher->getReplies()); // second must be empty
    }

    public function testRepliesFromTwoServicesOnDifferentPortsBothQueued(): void
    {
        $oA = new MockServiceProvider([0x99]);
        $oB = new MockServiceProvider([0x9D]);
        $oA->queueReply($this->makeReply());
        $oB->queueReply($this->makeReply());

        $oDispatcher = $this->make([$oA, $oB]);
        $oDispatcher->inboundPacket($this->packet('Unicast', 0x99));
        $oDispatcher->inboundPacket($this->packet('Unicast', 0x9D));

        $this->assertCount(2, $oDispatcher->getReplies());
    }

    // =========================================================================
    // addAckEvent() / ackEvents() / clearAckEvent()
    // =========================================================================

    public function testAckEventFiredForMatchingNetworkAndStation(): void
    {
        $oDispatcher = $this->make([]);
        $bFired = false;
        $oDispatcher->addAckEvent(2, 10, function() use (&$bFired) { $bFired = true; });
        $oDispatcher->ackEvents($this->packet('Ack', 0x99, iSrcNet: 2, iSrcStn: 10));
        $this->assertTrue($bFired);
    }

    public function testAckEventNotFiredForWrongNetwork(): void
    {
        $oDispatcher = $this->make([]);
        $bFired = false;
        $oDispatcher->addAckEvent(2, 10, function() use (&$bFired) { $bFired = true; });
        $oDispatcher->ackEvents($this->packet('Ack', 0x99, iSrcNet: 3, iSrcStn: 10));
        $this->assertFalse($bFired);
    }

    public function testAckEventNotFiredForWrongStation(): void
    {
        $oDispatcher = $this->make([]);
        $bFired = false;
        $oDispatcher->addAckEvent(2, 10, function() use (&$bFired) { $bFired = true; });
        $oDispatcher->ackEvents($this->packet('Ack', 0x99, iSrcNet: 2, iSrcStn: 11));
        $this->assertFalse($bFired);
    }

    public function testAckEventFiredOnlyOnce(): void
    {
        $oDispatcher = $this->make([]);
        $iCount = 0;
        $oDispatcher->addAckEvent(1, 5, function() use (&$iCount) { $iCount++; });
        $oPkt = $this->packet('Ack', 0x99, iSrcNet: 1, iSrcStn: 5);
        $oDispatcher->ackEvents($oPkt);
        $oDispatcher->ackEvents($oPkt);   // second — event already consumed
        $this->assertSame(1, $iCount);
    }

    public function testAckEventReceivesPacketAsArgument(): void
    {
        $oDispatcher = $this->make([]);
        $oReceived   = null;
        $oPkt = $this->packet('Ack', 0x99, iSrcNet: 1, iSrcStn: 5);
        $oDispatcher->addAckEvent(1, 5, function($p) use (&$oReceived) { $oReceived = $p; });
        $oDispatcher->ackEvents($oPkt);
        $this->assertSame($oPkt, $oReceived);
    }

    public function testClearAckEventPreventsEventFromFiring(): void
    {
        $oDispatcher = $this->make([]);
        $bFired = false;
        $oDispatcher->addAckEvent(1, 5, function() use (&$bFired) { $bFired = true; });
        $oDispatcher->clearAckEvent(1, 5);
        $oDispatcher->ackEvents($this->packet('Ack', 0x99, iSrcNet: 1, iSrcStn: 5));
        $this->assertFalse($bFired);
    }

    public function testClearAckEventIsNoOpWhenEventNotRegistered(): void
    {
        $oDispatcher = $this->make([]);
        $oDispatcher->clearAckEvent(9, 9);   // must not throw
        $this->assertTrue(true);
    }

    // =========================================================================
    // disableService() / enableService()
    // =========================================================================

    public function testDisabledServiceDoesNotReceiveUnicast(): void
    {
        $oA = new MockServiceProvider([0x99]);
        $oDispatcher = $this->make([$oA]);
        $oDispatcher->disableService($oA);
        $oDispatcher->inboundPacket($this->packet('Unicast', 0x99));
        $this->assertSame(0, $oA->iUnicastCalls);
    }

    public function testDisabledServiceDoesNotReceiveBroadcast(): void
    {
        $oA = new MockServiceProvider([0x99]);
        $oDispatcher = $this->make([$oA]);
        $oDispatcher->disableService($oA);
        $oDispatcher->inboundPacket($this->packet('Broadcast', 0x99));
        $this->assertSame(0, $oA->iBroadcastCalls);
    }

    public function testReenabledServiceReceivesUnicast(): void
    {
        $oA = new MockServiceProvider([0x99]);
        $oDispatcher = $this->make([$oA]);
        $oDispatcher->disableService($oA);
        $oDispatcher->enableService($oA);
        $oDispatcher->inboundPacket($this->packet('Unicast', 0x99));
        $this->assertSame(1, $oA->iUnicastCalls);
    }

    public function testEnableServiceIsNoOpForUnregisteredService(): void
    {
        $oA = new MockServiceProvider([0x99]);
        $oDispatcher = $this->make([]);       // oA not added
        $oDispatcher->enableService($oA);     // must not throw or register
        $this->assertNull($oDispatcher->getServiceByPort(0x99));
    }

    public function testGetServiceByPortReturnsNullAfterDisable(): void
    {
        $oA = new MockServiceProvider([0x99]);
        $oDispatcher = $this->make([$oA]);
        $oDispatcher->disableService($oA);
        $this->assertNull($oDispatcher->getServiceByPort(0x99));
    }

    // =========================================================================
    // claimStreamPort()
    // =========================================================================

    public function testClaimStreamPortReturnsPortInStreamRange(): void
    {
        $oA = new MockServiceProvider([0x99]);
        $oDispatcher = $this->make([$oA]);
        $iPort = $oDispatcher->claimStreamPort($oA);
        $this->assertGreaterThanOrEqual(20, $iPort);
        $this->assertLessThan(40, $iPort);
    }

    public function testClaimStreamPortReturnsDifferentPortOnSecondClaim(): void
    {
        $oA = new MockServiceProvider([0x99]);
        $oDispatcher = $this->make([$oA]);
        $iPort1 = $oDispatcher->claimStreamPort($oA);
        $iPort2 = $oDispatcher->claimStreamPort($oA);
        $this->assertNotSame($iPort1, $iPort2);
    }

    public function testClaimedStreamPortRoutesUnicastToService(): void
    {
        $oA = new MockServiceProvider([0x99]);
        $oDispatcher = $this->make([$oA]);
        $iPort = $oDispatcher->claimStreamPort($oA);
        $oDispatcher->inboundPacket($this->packet('Unicast', $iPort));
        $this->assertSame(1, $oA->iUnicastCalls);
    }

    public function testClaimStreamPortThrowsWhenAllPortsExhausted(): void
    {
        $this->expectException(\Exception::class);
        $oA = new MockServiceProvider([0x99]);
        $oDispatcher = $this->make([$oA]);
        // Claim all 20 stream ports (range 20–39)
        for ($i = 0; $i < ServiceDispatcher::MAX_STREAMS; $i++) {
            $oDispatcher->claimStreamPort($oA);
        }
        // One more must throw
        $oDispatcher->claimStreamPort($oA);
    }

    // =========================================================================
    // houseKeeping()
    // =========================================================================

    public function testHouseKeepingCallsRegisteredTasks(): void
    {
        $oDispatcher = $this->make([]);
        $iCalled = 0;
        $oDispatcher->addHousingKeepingTask(function() use (&$iCalled) { $iCalled++; });
        $oDispatcher->houseKeeping();
        $this->assertSame(1, $iCalled);
    }

    public function testHouseKeepingCallsMultipleRegisteredTasks(): void
    {
        $oDispatcher = $this->make([]);
        $iA = 0; $iB = 0;
        $oDispatcher->addHousingKeepingTask(function() use (&$iA) { $iA++; });
        $oDispatcher->addHousingKeepingTask(function() use (&$iB) { $iB++; });
        $oDispatcher->houseKeeping();
        $this->assertSame(1, $iA);
        $this->assertSame(1, $iB);
    }

    public function testHouseKeepingFreesExpiredStreamPort(): void
    {
        $oA = new MockServiceProvider([0x99]);
        $oDispatcher = $this->make([$oA]);

        // Claim with -1 timeout so the port is immediately expired
        $iPort = $oDispatcher->claimStreamPort($oA, -1);
        $oDispatcher->houseKeeping();

        // Port must now be gone — a unicast to it must not reach the service
        $oDispatcher->inboundPacket($this->packet('Unicast', $iPort));
        $this->assertSame(0, $oA->iUnicastCalls);
    }

    public function testHouseKeepingKeepsNonExpiredStreamPort(): void
    {
        $oA = new MockServiceProvider([0x99]);
        $oDispatcher = $this->make([$oA]);

        $iPort = $oDispatcher->claimStreamPort($oA, 300);
        $oDispatcher->houseKeeping();

        // Port must still be active — unicast reaches the service
        $oDispatcher->inboundPacket($this->packet('Unicast', $iPort));
        $this->assertSame(1, $oA->iUnicastCalls);
    }

    public function testHouseKeepingExpiredPortCanBeReclaimedAfterwards(): void
    {
        $oA = new MockServiceProvider([0x99]);
        $oDispatcher = $this->make([$oA]);

        // Fill all 20 stream ports with immediately-expired claims
        for ($i = 0; $i < ServiceDispatcher::MAX_STREAMS; $i++) {
            $oDispatcher->claimStreamPort($oA, -1);
        }

        $oDispatcher->houseKeeping();  // frees all expired ports

        // Now a fresh claim must succeed
        $iPort = $oDispatcher->claimStreamPort($oA, 60);
        $this->assertGreaterThanOrEqual(20, $iPort);
    }
}
