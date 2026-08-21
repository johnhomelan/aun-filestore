<?php

/*
 * @group unit-tests
 *
 * Tests for Dns\Forwarder - forwards a query to an external DNS server asynchronously over the
 * ReactPHP event loop, and demultiplexes replies on a shared upstream connection by its own
 * internally-assigned transaction id, independent of the original client's id (see
 * docs/protocols/dns.md).
 *
 * The real upstream connection (React\Datagram\Factory::createClient()) is not exercised here:
 * TestableForwarder overrides createUpstreamConnectionPromise() to hand back an already
 * "connected" fake socket synchronously, so tests can inspect what would have gone out and
 * simulate replies without any real network I/O.
 */

include_once(__DIR__ . '/../../src/include/system.inc.php');

use PHPUnit\Framework\TestCase;
use Monolog\Logger;
use Monolog\Handler\NullHandler;
use HomeLan\FileStore\Dns\Forwarder;
use React\Datagram\SocketInterface;
use React\EventLoop\LoopInterface;
use React\EventLoop\Factory as LoopFactory;

use function React\Promise\resolve;
use function React\Promise\reject;

#[\AllowDynamicProperties]
class FakeUpstreamSocket extends \Evenement\EventEmitter implements SocketInterface
{
    /** @var list<string> */
    public array $aSent = [];

    public function send($data, $remoteAddress = null)
    {
        $this->aSent[] = (string) $data;
        return $this;
    }

    public function close()
    {
    }

    public function end()
    {
    }

    public function resume()
    {
    }

    public function pause()
    {
    }

    public function getLocalAddress()
    {
        return null;
    }

    public function getRemoteAddress()
    {
        return null;
    }
}

class TestableForwarder extends Forwarder
{
    public function __construct(
        LoopInterface $oLoop,
        \Psr\Log\LoggerInterface $oLogger,
        public readonly FakeUpstreamSocket $oFakeSocket,
        float $fTimeout = 5.0,
    ) {
        parent::__construct($oLoop, $oLogger, '0.0.0.0:0', $fTimeout);
    }

    protected function createUpstreamConnectionPromise(string $sUpstreamAddress): mixed
    {
        return resolve($this->oFakeSocket);
    }
}

class UnconnectedForwarder extends Forwarder
{
    protected function createUpstreamConnectionPromise(string $sUpstreamAddress): mixed
    {
        return reject(new \Exception('no connection for this test'));
    }
}

class ForwarderTest extends TestCase
{
    private Logger $oLogger;

    protected function setUp(): void
    {
        $this->oLogger = new Logger('test');
        $this->oLogger->pushHandler(new NullHandler());
    }

    /** @return array{0: LoopInterface, 1: TestableForwarder, 2: FakeUpstreamSocket} */
    private function buildForwarder(float $fTimeout = 5.0): array
    {
        $oLoop = LoopFactory::create();
        $oFakeSocket = new FakeUpstreamSocket();
        $oForwarder = new TestableForwarder($oLoop, $this->oLogger, $oFakeSocket, $fTimeout);
        return [$oLoop, $oForwarder, $oFakeSocket];
    }

    public function testForwardSendsAPacketUpstream(): void
    {
        [, $oForwarder, $oFakeSocket] = $this->buildForwarder();
        $oForwarder->forward(pack('n', 0x1234) . 'rest of the query');
        $this->assertCount(1, $oFakeSocket->aSent);
    }

    public function testForwardRewritesTheIdOnTheWayOut(): void
    {
        [, $oForwarder, $oFakeSocket] = $this->buildForwarder();
        $oForwarder->forward(pack('n', 0xBEEF) . 'rest of the query');

        $aId = unpack('nid', substr($oFakeSocket->aSent[0], 0, 2));
        $this->assertSame(0, $aId['id']);
    }

    public function testForwardPreservesEverythingAfterTheId(): void
    {
        [, $oForwarder, $oFakeSocket] = $this->buildForwarder();
        $oForwarder->forward(pack('n', 0xBEEF) . 'rest of the query');
        $this->assertSame('rest of the query', substr($oFakeSocket->aSent[0], 2));
    }

    public function testReplyResolvesTheForwardPromise(): void
    {
        [, $oForwarder, $oFakeSocket] = $this->buildForwarder();
        $oPromise = $oForwarder->forward(pack('n', 0xBEEF) . 'query');

        $mResult = null;
        $oPromise->then(function ($mValue) use (&$mResult) {
            $mResult = $mValue;
        });

        $oFakeSocket->emit('message', [pack('n', 0) . 'reply payload']);

        $this->assertSame(pack('n', 0) . 'reply payload', $mResult);
    }

    public function testConcurrentForwardsGetDifferentIds(): void
    {
        [, $oForwarder, $oFakeSocket] = $this->buildForwarder();
        $oForwarder->forward(pack('n', 1) . 'a');
        $oForwarder->forward(pack('n', 1) . 'b');

        $aId0 = unpack('nid', substr($oFakeSocket->aSent[0], 0, 2));
        $aId1 = unpack('nid', substr($oFakeSocket->aSent[1], 0, 2));
        $this->assertNotSame($aId0['id'], $aId1['id']);
    }

    public function testReplyOnlyResolvesTheMatchingPendingForward(): void
    {
        [, $oForwarder, $oFakeSocket] = $this->buildForwarder();
        $oPromiseA = $oForwarder->forward(pack('n', 1) . 'a');
        $oPromiseB = $oForwarder->forward(pack('n', 1) . 'b');

        $mResultA = 'unset';
        $mResultB = 'unset';
        $oPromiseA->then(function ($v) use (&$mResultA) {
            $mResultA = $v;
        });
        $oPromiseB->then(function ($v) use (&$mResultB) {
            $mResultB = $v;
        });

        $oFakeSocket->emit('message', [pack('n', 0) . 'reply for a']);

        $this->assertSame(pack('n', 0) . 'reply for a', $mResultA);
        $this->assertSame('unset', $mResultB);
    }

    public function testUnrecognisedReplyIdIsIgnored(): void
    {
        [, $oForwarder, $oFakeSocket] = $this->buildForwarder();
        $oForwarder->forward(pack('n', 1) . 'a');

        $oFakeSocket->emit('message', [pack('n', 999) . 'stray reply']);
        $this->addToAssertionCount(1); // reaching here without error is the assertion
    }

    public function testForwardWithNoUpstreamConnectionRejectsImmediately(): void
    {
        $oLoop = LoopFactory::create();
        $oForwarder = new UnconnectedForwarder($oLoop, $this->oLogger, '0.0.0.0:0', 5.0);

        $bRejected = false;
        $oForwarder->forward('anything')->then(null, function () use (&$bRejected) {
            $bRejected = true;
        });

        $this->assertTrue($bRejected);
    }

    public function testTimeoutRejectsThePromise(): void
    {
        [$oLoop, $oForwarder] = $this->buildForwarder(0.05);

        $bRejected = false;
        $oForwarder->forward(pack('n', 1) . 'a')->then(null, function () use (&$bRejected) {
            $bRejected = true;
        });

        $oLoop->run();

        $this->assertTrue($bRejected);
    }

    public function testReplyAfterTimeoutIsIgnored(): void
    {
        [$oLoop, $oForwarder, $oFakeSocket] = $this->buildForwarder(0.05);

        $iCalls = 0;
        $oForwarder->forward(pack('n', 1) . 'a')->then(null, function () use (&$iCalls) {
            $iCalls++;
        });

        $oLoop->run();
        $oFakeSocket->emit('message', [pack('n', 0) . 'too late']);

        $this->assertSame(1, $iCalls);
    }
}
