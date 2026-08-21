<?php

/*
 * @group unit-tests
 *
 * Tests for ShareFs\AccessPlusHandler - per-share password authentication.
 */

include_once(__DIR__ . '/../../src/include/system.inc.php');

use PHPUnit\Framework\TestCase;
use Monolog\Logger;
use Monolog\Handler\NullHandler;
use HomeLan\FileStore\ShareFs\AccessPlusHandler;
use HomeLan\FileStore\ShareFs\ShareAuthTable;
use HomeLan\FileStore\ShareFs\ShareList;
use HomeLan\FileStore\ShareFs\Messages\AccessPlusPacket;
use React\Datagram\Socket as DatagramSocket;

class AccessPlusHandlerTest extends TestCase
{
    private Logger $oLogger;
    private DatagramSocket $oSocket;
    private AccessPlusHandler $oHandler;

    protected function setUp(): void
    {
        $this->oLogger = new Logger('test');
        $this->oLogger->pushHandler(new NullHandler());

        ShareAuthTable::reset();
        ShareList::reset();
        ShareList::init($this->oLogger, "SHARE PRIVATE \$.PRIVATE protected secretpw\nSHARE DISC0 \$.DISC0\n");

        $this->oSocket = $this->createMock(DatagramSocket::class);
        $this->oHandler = new AccessPlusHandler($this->oLogger);
        $this->oHandler->setSocket($this->oSocket);
    }

    public function testCorrectShareKeyAuthenticatesClientAndReplies(): void
    {
        $iKey = AccessPlusPacket::foldPassword('secretpw');
        $this->oSocket->expects($this->once())
            ->method('send')
            ->with($this->anything(), '10.0.0.1:32771');

        $this->oHandler->receive(AccessPlusPacket::encodeShareKeyRequest($iKey), '10.0.0.1:32771');

        $this->assertTrue(ShareAuthTable::check('10.0.0.1', 'PRIVATE'));
    }

    public function testWrongShareKeyGetsSilenceAndNoAuthentication(): void
    {
        $this->oSocket->expects($this->never())->method('send');

        $this->oHandler->receive(AccessPlusPacket::encodeShareKeyRequest(999999), '10.0.0.1:32771');

        $this->assertFalse(ShareAuthTable::check('10.0.0.1', 'PRIVATE'));
    }

    public function testReplyContainsShareNameAndProtectedAttribute(): void
    {
        $iKey = AccessPlusPacket::foldPassword('secretpw');
        $sSent = null;
        $this->oSocket->method('send')->willReturnCallback(function (string $sData) use (&$sSent) {
            $sSent = $sData;
        });

        $this->oHandler->receive(AccessPlusPacket::encodeShareKeyRequest($iKey), '10.0.0.1:32771');

        $this->assertNotNull($sSent);
        $this->assertStringContainsString('PRIVATE', $sSent);
    }

    public function testMalformedPacketIsDiscardedWithoutThrowing(): void
    {
        $this->oSocket->expects($this->never())->method('send');
        $this->oHandler->receive('', '10.0.0.1:32771');
        $this->addToAssertionCount(1);
    }

    public function testNonShareKeyMessageIsIgnored(): void
    {
        $this->oSocket->expects($this->never())->method('send');
        $sPacket = pack('V', 0x00010002) . pack('V', 0x00010001) . pack('V', 0);
        $this->oHandler->receive($sPacket, '10.0.0.1:32771');
    }

    public function testAddressToIpStripsPort(): void
    {
        $this->assertSame('10.0.0.1', AccessPlusHandler::addressToIp('10.0.0.1:32771'));
    }
}
