<?php

/*
 * Unit tests for RemoteBridge ClientHandler reconnect-backoff logic.
 *
 * ClientHandler.connect() opens a live TCP socket so the reconnect scheduling
 * is tested by calling the private scheduleReconnect() via ReflectionMethod
 * with a mock LoopInterface; no real connection is made.
 */

include_once(__DIR__ . '/../../src/include/system.inc.php');

use PHPUnit\Framework\TestCase;
use Monolog\Logger;
use Monolog\Handler\NullHandler;
use HomeLan\FileStore\RemoteBridge\ClientHandler;
use HomeLan\FileStore\RemoteBridge\Map;
use HomeLan\FileStore\Services\ServiceDispatcher;
use HomeLan\FileStore\Encapsulation\PacketDispatcher;
use React\EventLoop\LoopInterface;

class RemoteBridgeClientHandlerTest extends TestCase
{
    private Logger $oLogger;

    protected function setUp(): void
    {
        $this->oLogger = new Logger('test');
        $this->oLogger->pushHandler(new NullHandler());
        Map::reset();
    }

    // -----------------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------------

    private function makeHandler(LoopInterface $oLoop): ClientHandler
    {
        $oServices         = $this->createMock(ServiceDispatcher::class);
        $oPacketDispatcher = $this->createMock(PacketDispatcher::class);
        return new ClientHandler($this->oLogger, $oServices, $oPacketDispatcher, $oLoop);
    }

    /**
     * Seeds aEntryState for $sKey with the given initial delay and returns
     * the accessor for scheduleReconnect().
     */
    private function prepareReconnect(
        ClientHandler $oHandler,
        string $sKey,
        int $iDelay
    ): \ReflectionMethod {
        $rProp = new \ReflectionProperty(ClientHandler::class, 'aEntryState');
        $rProp->setAccessible(true);
        $rProp->setValue($oHandler, [
            $sKey => [
                'entry' => ['host' => '10.0.0.1', 'port' => 9000, 'secret' => 's', 'networks' => [1]],
                'delay' => $iDelay,
            ],
        ]);

        $rMethod = new \ReflectionMethod(ClientHandler::class, 'scheduleReconnect');
        $rMethod->setAccessible(true);
        return $rMethod;
    }

    private function getStoredDelay(ClientHandler $oHandler, string $sKey): int
    {
        $rProp = new \ReflectionProperty(ClientHandler::class, 'aEntryState');
        $rProp->setAccessible(true);
        return $rProp->getValue($oHandler)[$sKey]['delay'];
    }

    // -----------------------------------------------------------------------
    // Constants
    // -----------------------------------------------------------------------

    public function testReconnectDelayMinIs5(): void
    {
        $rConst = new \ReflectionClassConstant(ClientHandler::class, 'RECONNECT_DELAY_MIN');
        $this->assertSame(5, $rConst->getValue());
    }

    public function testReconnectDelayMaxIs300(): void
    {
        $rConst = new \ReflectionClassConstant(ClientHandler::class, 'RECONNECT_DELAY_MAX');
        $this->assertSame(300, $rConst->getValue());
    }

    // -----------------------------------------------------------------------
    // start() with empty Map
    // -----------------------------------------------------------------------

    public function testStartWithEmptyMapLeavesEntryStateEmpty(): void
    {
        $oLoop    = $this->createMock(LoopInterface::class);
        $oHandler = $this->makeHandler($oLoop);

        $oHandler->start();

        $rProp = new \ReflectionProperty(ClientHandler::class, 'aEntryState');
        $rProp->setAccessible(true);
        $this->assertEmpty($rProp->getValue($oHandler));
    }

    // -----------------------------------------------------------------------
    // scheduleReconnect() — timer fires with the current delay
    // -----------------------------------------------------------------------

    public function testScheduleReconnectCallsAddTimerWithCurrentDelay(): void
    {
        $sKey   = '10.0.0.1:9000';
        $iDelay = 5;

        $oLoop = $this->createMock(LoopInterface::class);
        $oLoop->expects($this->once())
            ->method('addTimer')
            ->with($iDelay, $this->isType('callable'));

        $oHandler = $this->makeHandler($oLoop);
        $rMethod  = $this->prepareReconnect($oHandler, $sKey, $iDelay);
        $rMethod->invoke($oHandler, $sKey);
    }

    public function testTimerDelayUsesPreDoubleValue(): void
    {
        // The timer must be scheduled with the CURRENT delay, not the already-doubled one.
        $sKey   = '10.0.0.1:9000';
        $iDelay = 20;

        $iCapturedDelay = null;
        $oLoop = $this->createMock(LoopInterface::class);
        $oLoop->method('addTimer')
            ->willReturnCallback(function ($iD) use (&$iCapturedDelay) {
                $iCapturedDelay = $iD;
            });

        $oHandler = $this->makeHandler($oLoop);
        $rMethod  = $this->prepareReconnect($oHandler, $sKey, $iDelay);
        $rMethod->invoke($oHandler, $sKey);

        $this->assertSame(20, $iCapturedDelay);
    }

    // -----------------------------------------------------------------------
    // Delay doubling
    // -----------------------------------------------------------------------

    public function testDelayDoublesAfterFirstReconnect(): void
    {
        $sKey = '10.0.0.1:9000';

        $oLoop    = $this->createMock(LoopInterface::class);
        $oHandler = $this->makeHandler($oLoop);
        $rMethod  = $this->prepareReconnect($oHandler, $sKey, 5);

        $rMethod->invoke($oHandler, $sKey);

        $this->assertSame(10, $this->getStoredDelay($oHandler, $sKey));
    }

    public function testDelayDoublesRepeatedly(): void
    {
        $sKey = '10.0.0.1:9000';

        $oLoop    = $this->createMock(LoopInterface::class);
        $oHandler = $this->makeHandler($oLoop);

        foreach ([5, 10, 20, 40, 80] as $iExpected) {
            $rMethod = $this->prepareReconnect($oHandler, $sKey, $iExpected);
            $rMethod->invoke($oHandler, $sKey);
            $this->assertSame($iExpected * 2, $this->getStoredDelay($oHandler, $sKey));
        }
    }

    public function testDelayIsCappedAt300(): void
    {
        $sKey = '10.0.0.1:9000';

        $oLoop    = $this->createMock(LoopInterface::class);
        $oHandler = $this->makeHandler($oLoop);

        // 160 * 2 = 320, must be capped to 300
        $rMethod = $this->prepareReconnect($oHandler, $sKey, 160);
        $rMethod->invoke($oHandler, $sKey);

        $this->assertSame(300, $this->getStoredDelay($oHandler, $sKey));
    }

    public function testDelayAtMaxStaysAt300(): void
    {
        $sKey = '10.0.0.1:9000';

        $oLoop    = $this->createMock(LoopInterface::class);
        $oHandler = $this->makeHandler($oLoop);

        $rMethod = $this->prepareReconnect($oHandler, $sKey, 300);
        $rMethod->invoke($oHandler, $sKey);

        $this->assertSame(300, $this->getStoredDelay($oHandler, $sKey));
    }
}
