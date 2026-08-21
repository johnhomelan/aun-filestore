<?php

/*
 * @group unit-tests
 *
 * Tests for Ntp\Handler - answers a decoded NtpMessage request using the host system clock and
 * sends the response back on whichever socket it was given (a real UDP socket or, in ntpd's
 * case, a RemoteSocket\RelayedUdpTransport - see docs/NTPD.md).
 */

include_once(__DIR__ . '/../../src/include/system.inc.php');

use PHPUnit\Framework\TestCase;
use Monolog\Logger;
use Monolog\Handler\NullHandler;
use HomeLan\FileStore\Ntp\Handler;
use HomeLan\FileStore\Ntp\Messages\NtpMessage;
use React\Datagram\SocketInterface;

class NtpHandlerTest extends TestCase
{
    private Logger $oLogger;
    private SocketInterface $oSocket;
    private Handler $oHandler;

    protected function setUp(): void
    {
        $this->oLogger = new Logger('test');
        $this->oLogger->pushHandler(new NullHandler());

        $this->oSocket = $this->createMock(SocketInterface::class);
        $this->oHandler = new Handler($this->oLogger, 1, 'LOCL');
        $this->oHandler->setSocket($this->oSocket);
    }

    private function buildRequestBytes(int $iVersion = 3, int $iMode = NtpMessage::MODE_CLIENT, int $iPoll = 4, string $sTransmitTimestamp = "\x00\x00\x00\x00\x00\x00\x00\x00"): string
    {
        $iFlags = (($iVersion & 0x7) << 3) | ($iMode & 0x7);
        $sHeader = chr($iFlags) . chr(0) . chr($iPoll & 0xFF) . chr(0);
        $sHeader .= str_repeat("\x00", 4);
        $sHeader .= str_repeat("\x00", 4);
        $sHeader .= str_repeat("\x00", 4);
        $sHeader .= str_repeat("\x00", 8);
        $sHeader .= str_repeat("\x00", 8);
        $sHeader .= str_repeat("\x00", 8);
        $sHeader .= $sTransmitTimestamp;
        return $sHeader;
    }

    public function testClientRequestGetsAReply(): void
    {
        $this->oSocket->expects($this->once())->method('send');
        $this->oHandler->receive($this->buildRequestBytes(), '10.0.0.5:5000');
    }

    public function testReplyIsSentBackToTheSender(): void
    {
        $this->oSocket->expects($this->once())
            ->method('send')
            ->with($this->anything(), '10.0.0.5:5000');

        $this->oHandler->receive($this->buildRequestBytes(), '10.0.0.5:5000');
    }

    public function testReplyUsesTheConfiguredStratum(): void
    {
        $oHandler = new Handler($this->oLogger, 3, 'LOCL');
        $oHandler->setSocket($this->oSocket);

        $sSent = null;
        $this->oSocket->method('send')->willReturnCallback(function (string $sData) use (&$sSent) {
            $sSent = $sData;
        });

        $oHandler->receive($this->buildRequestBytes(), '10.0.0.5:5000');

        $this->assertSame(3, ord((string) $sSent[1]));
    }

    public function testReplyUsesTheConfiguredReferenceId(): void
    {
        $oHandler = new Handler($this->oLogger, 1, 'GPS');
        $oHandler->setSocket($this->oSocket);

        $sSent = null;
        $this->oSocket->method('send')->willReturnCallback(function (string $sData) use (&$sSent) {
            $sSent = $sData;
        });

        $oHandler->receive($this->buildRequestBytes(), '10.0.0.5:5000');

        $this->assertSame("GPS\x00", substr((string) $sSent, 12, 4));
    }

    public function testReplyEchoesTheClientsTransmitTimestampAsTheOriginTimestamp(): void
    {
        $sClientTransmit = "\x01\x02\x03\x04\x05\x06\x07\x08";
        $sSent = null;
        $this->oSocket->method('send')->willReturnCallback(function (string $sData) use (&$sSent) {
            $sSent = $sData;
        });

        $this->oHandler->receive($this->buildRequestBytes(sTransmitTimestamp: $sClientTransmit), '10.0.0.5:5000');

        $this->assertSame($sClientTransmit, substr((string) $sSent, 24, 8));
    }

    public function testReplyIsInServerMode(): void
    {
        $sSent = null;
        $this->oSocket->method('send')->willReturnCallback(function (string $sData) use (&$sSent) {
            $sSent = $sData;
        });

        $this->oHandler->receive($this->buildRequestBytes(), '10.0.0.5:5000');

        $this->assertSame(NtpMessage::MODE_SERVER, ord((string) $sSent[0]) & 0x7);
    }

    public function testSymmetricPassiveRequestIsIgnored(): void
    {
        $this->oSocket->expects($this->never())->method('send');
        $this->oHandler->receive($this->buildRequestBytes(iMode: 2), '10.0.0.5:5000');
    }

    public function testServerModeRequestIsIgnored(): void
    {
        // A mode 4 packet arriving as a "request" (e.g. a misbehaving client, or a reply
        // reflected back) is not something this server should ever answer.
        $this->oSocket->expects($this->never())->method('send');
        $this->oHandler->receive($this->buildRequestBytes(iMode: NtpMessage::MODE_SERVER), '10.0.0.5:5000');
    }

    public function testMalformedRequestIsDroppedWithoutReply(): void
    {
        $this->oSocket->expects($this->never())->method('send');
        $this->oHandler->receive('too short', '10.0.0.5:5000');
    }
}
