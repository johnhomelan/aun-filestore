<?php

/*
 * @group unit-tests
 *
 * Tests for ShareFs\FreewayHandler - periodic broadcast of advertised shares.
 */

include_once(__DIR__ . '/../../src/include/system.inc.php');

use PHPUnit\Framework\TestCase;
use Monolog\Logger;
use Monolog\Handler\NullHandler;
use HomeLan\FileStore\ShareFs\FreewayHandler;
use HomeLan\FileStore\ShareFs\ShareList;
use HomeLan\FileStore\ShareFs\Messages\FreewayPacket;
use React\Datagram\Socket as DatagramSocket;

class FreewayHandlerTest extends TestCase
{
    private Logger $oLogger;
    private DatagramSocket $oSocket;
    private FreewayHandler $oHandler;

    protected function setUp(): void
    {
        $this->oLogger = new Logger('test');
        $this->oLogger->pushHandler(new NullHandler());

        ShareList::reset();
        ShareList::init($this->oLogger, "SHARE DISC0 \$.DISC0\nSHARE SPARE \$.SPARE hidden\nSHARE PRIVATE \$.PRIVATE protected pw\n");

        $this->oSocket = $this->createMock(DatagramSocket::class);
        $this->oHandler = new FreewayHandler($this->oLogger);
        $this->oHandler->setSocket($this->oSocket);

        config::overrideValue('sharefs_freeway_broadcast_address', '255.255.255.255');
        config::overrideValue('sharefs_freeway_port', 32770);
    }

    protected function tearDown(): void
    {
        config::resetValue('sharefs_freeway_broadcast_address');
        config::resetValue('sharefs_freeway_port');
    }

    public function testBroadcastsOnlyAdvertisedShares(): void
    {
        $aSent = [];
        $this->oSocket->method('send')->willReturnCallback(function (string $sData) use (&$aSent) {
            $aSent[] = FreewayPacket::decode($sData)->getName();
        });

        $this->oHandler->broadcast();

        $this->assertSame(['DISC0'], $aSent);
    }

    public function testBroadcastUsesAvailableMinorAndDiscType(): void
    {
        $this->oSocket->expects($this->once())
            ->method('send')
            ->with(
                $this->callback(function (string $sData): bool {
                    $oPacket = FreewayPacket::decode($sData);
                    return $oPacket->getType() === FreewayPacket::TYPE_DISC
                        && $oPacket->getMinor() === FreewayPacket::MINOR_AVAILABLE;
                }),
                '255.255.255.255:32770'
            );

        $this->oHandler->broadcast();
    }

    public function testBroadcastWithNoAdvertisableSharesSendsNothing(): void
    {
        ShareList::reset();
        ShareList::init($this->oLogger, "SHARE SPARE \$.SPARE hidden\n");

        $this->oSocket->expects($this->never())->method('send');
        $this->oHandler->broadcast();
    }

    public function testReceiveDoesNotThrow(): void
    {
        $this->oSocket->expects($this->never())->method('send');
        $this->oHandler->receive('anything', '10.0.0.5:32770');
        $this->addToAssertionCount(1);
    }
}
