<?php

/*
 * @group unit-tests
 *
 * End-to-end test proving a real AUN ack, arriving via Aun\Handler::receive(),
 * reaches ServiceDispatcher::ackEvents() and drives a FileServer-style
 * block-by-block ack chain (via the shared AckChainMockProvider) all the way
 * to completion — not just that a single ack fires a single callback.
 *
 * The initial "request" that kicks off the chain is delivered directly via
 * ServiceDispatcher::inboundPacket() — AUN's own inbound-Unicast wire
 * handling is already covered by AunHandlerTest. What's under test here is
 * specifically the ack path: Handler::receive() → ackEvents() → provider
 * callback → next block → re-registered addAckEvent, repeated to completion.
 */

include_once(__DIR__ . '/../../src/include/system.inc.php');
include_once(__DIR__ . '/../support/AckChainMockProvider.php');
include_once(__DIR__ . '/../support/AckChainKickoffPacket.php');

use PHPUnit\Framework\TestCase;
use Monolog\Logger;
use Monolog\Handler\NullHandler;
use HomeLan\FileStore\Aun\Handler;
use HomeLan\FileStore\Aun\Map as AunMap;
use HomeLan\FileStore\Services\ServiceDispatcher;
use HomeLan\FileStore\Encapsulation\PacketDispatcher;
use HomeLan\FileStore\Messages\EconetPacket;
use React\Datagram\Socket as DatagramSocket;

class AunAckChainIntegrationTest extends TestCase
{
    private const MAP = "192.168.0.0/24 127\n192.168.0.40 127.254\n";
    private const CLIENT_NET  = 127;
    private const CLIENT_STN  = 1;
    private const CLIENT_IP   = '192.168.0.1';
    private const CLIENT_HOST = '192.168.0.1:32768';

    private Logger $oLogger;

    protected function setUp(): void
    {
        $this->oLogger = new Logger('aun-ackchain-test');
        $this->oLogger->pushHandler(new NullHandler());

        $this->resetAunMapState();
        $oMapHandler = $this->createStub(Handler::class);
        AunMap::init($this->oLogger, $oMapHandler, self::MAP);
        AunMap::setAunCounter(self::CLIENT_IP, 0);

        config::overrideValue('local_ip', '127.0.0.1');
        config::overrideValue('aun_default_port', '32768');
    }

    protected function tearDown(): void
    {
        config::resetValue('local_ip');
        config::resetValue('aun_default_port');
        $this->resetAunMapState();
    }

    private function resetAunMapState(): void
    {
        foreach (['aHostMap', 'aSubnetMap', 'aIPLookupCache', 'aIpCounter'] as $sProp) {
            $rp = new \ReflectionProperty(AunMap::class, $sProp);
            $rp->setAccessible(true);
            $rp->setValue(null, []);
        }
    }

    private function makeAunWire(int $iType, int $iPort, int $iCb, int $iSeq, string $sData): string
    {
        return pack('CCCCV', $iType, $iPort, $iCb, 0, $iSeq) . $sData;
    }

    private function makeEconetPacket(int $iDstNet, int $iDstStn, int $iPort, string $sData = 'block'): EconetPacket
    {
        $oPacket = new EconetPacket();
        $oPacket->setDestinationNetwork($iDstNet);
        $oPacket->setDestinationStation($iDstStn);
        $oPacket->setPort($iPort);
        $oPacket->setFlags(0);
        $oPacket->setData($sData);
        return $oPacket;
    }

    public function testRealAckChainOverAunDrivesProviderToCompletion(): void
    {
        $oProvider = new AckChainMockProvider(0x99, 3);
        $oServices = new ServiceDispatcher($this->oLogger, [$oProvider]);

        $oPacketDispatcher = $this->createMock(PacketDispatcher::class);
        $oHandler = new Handler($this->oLogger, $oServices, $oPacketDispatcher);
        $oSocket = $this->createMock(DatagramSocket::class);
        $oSocket->method('send');
        $oHandler->setSocket($oSocket);

        // Kick off the chain: block 1 is sent and an addAckEvent registered
        // for the client.
        $oServices->inboundPacket(new AckChainKickoffPacket(0x99, self::CLIENT_NET, self::CLIENT_STN));
        $this->assertSame(1, $oProvider->getBlocksSent());
        $this->assertFalse($oProvider->isComplete());
        $oProvider->getReplies(); // drain block 1 — not under test here

        // Real ack #1, over the wire, matching an outbound packet Handler has in flight.
        $oOutbound = $this->makeEconetPacket(self::CLIENT_NET, self::CLIENT_STN, 0x99);
        $oHandler->send($oOutbound);
        $oHandler->timer();
        $iSeq = $oOutbound->getSequence();
        $oHandler->receive($this->makeAunWire(3, 0, 0, $iSeq, ''), self::CLIENT_HOST, '127.0.0.1:32768');

        $this->assertSame(2, $oProvider->getBlocksSent(), 'first real ack must have driven block 2');
        $this->assertFalse($oProvider->isComplete());

        // Real ack #2 — completes the chain.
        $oOutbound2 = $this->makeEconetPacket(self::CLIENT_NET, self::CLIENT_STN, 0x99);
        $oHandler->send($oOutbound2);
        $oHandler->timer();
        $iSeq2 = $oOutbound2->getSequence();
        $oHandler->receive($this->makeAunWire(3, 0, 0, $iSeq2, ''), self::CLIENT_HOST, '127.0.0.1:32768');

        $this->assertSame(3, $oProvider->getBlocksSent());
        $this->assertTrue($oProvider->isComplete(), 'chain must be complete after the final real ack');
    }
}
