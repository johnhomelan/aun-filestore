<?php

/*
 * @group unit-tests
 *
 * Tests for RelayedUdpTransport, the React\Datagram\SocketInterface stand-in that lets a
 * ShareFS handler send/receive over the Remote Socket Protocol exactly as it would a real UDP
 * socket (see docs/protocols/remote-socket.md):
 *   - deliver() emits a 'message' event with the payload and "remoteAddr:remotePort" address
 *   - send() to a peer this transport has never heard from is dropped silently (no interface to
 *     reply from is known yet)
 *   - send() to a known peer reaches Client::sendData() addressed from the interface that
 *     peer's traffic last arrived on
 *   - send() with a malformed address (no port) is dropped silently
 */

include_once(__DIR__.'/../../src/include/system.inc.php');

use PHPUnit\Framework\TestCase;
use Monolog\Logger;
use Monolog\Handler\NullHandler;
use HomeLan\FileStore\RemoteSocket\Client;
use HomeLan\FileStore\RemoteSocket\RelayedUdpTransport;

class RecordingRemoteSocketClient extends Client
{
    /** @var list<array{localAddr:string,localPort:int,remoteAddr:string,remotePort:int,payload:string}> */
    public array $aSentData = [];

    protected function rawSend(string $sEncoded): void
    {
    }

    public function sendData(string $sLocalAddr, int $iLocalPort, string $sRemoteAddr, int $iRemotePort, string $sPayload): void
    {
        $this->aSentData[] = ['localAddr' => $sLocalAddr, 'localPort' => $iLocalPort, 'remoteAddr' => $sRemoteAddr, 'remotePort' => $iRemotePort, 'payload' => $sPayload];
    }
}

class RelayedUdpTransportTest extends TestCase
{
    private Logger $oLogger;

    protected function setUp(): void
    {
        $this->oLogger = new Logger('test');
        $this->oLogger->pushHandler(new NullHandler());
    }

    private function buildClient(): RecordingRemoteSocketClient
    {
        $oLoop = \React\EventLoop\Factory::create();
        return new RecordingRemoteSocketClient($oLoop, $this->oLogger, 'ws://127.0.0.1:8091', 'test-secret');
    }

    public function testDeliverEmitsAMessageEvent(): void
    {
        $oTransport = new RelayedUdpTransport($this->buildClient(), 32770);
        $aReceived = [];
        $oTransport->on('message', function (string $sPayload, string $sSrcAddress) use (&$aReceived): void {
            $aReceived[] = [$sPayload, $sSrcAddress];
        });

        $oTransport->deliver('192.168.0.1', '192.168.0.5', 41230, 'freeway broadcast');

        $this->assertSame([['freeway broadcast', '192.168.0.5:41230']], $aReceived);
    }

    public function testSendToAnUnknownPeerIsDroppedSilently(): void
    {
        $oClient = $this->buildClient();
        $oTransport = new RelayedUdpTransport($oClient, 32770);

        $oTransport->send('reply payload', '192.168.0.5:41230');

        $this->assertEmpty($oClient->aSentData);
    }

    public function testSendToAKnownPeerReachesClientAddressedFromItsInterface(): void
    {
        $oClient = $this->buildClient();
        $oTransport = new RelayedUdpTransport($oClient, 32770);
        $oTransport->deliver('192.168.0.1', '192.168.0.5', 41230, 'request payload');

        $oTransport->send('reply payload', '192.168.0.5:41230');

        $this->assertCount(1, $oClient->aSentData);
        $this->assertSame('192.168.0.1', $oClient->aSentData[0]['localAddr']);
        $this->assertSame(32770, $oClient->aSentData[0]['localPort']);
        $this->assertSame('192.168.0.5', $oClient->aSentData[0]['remoteAddr']);
        $this->assertSame(41230, $oClient->aSentData[0]['remotePort']);
        $this->assertSame('reply payload', $oClient->aSentData[0]['payload']);
    }

    public function testSendWithNoPortInTheAddressIsDroppedSilently(): void
    {
        $oClient = $this->buildClient();
        $oTransport = new RelayedUdpTransport($oClient, 32770);
        $oTransport->deliver('192.168.0.1', '192.168.0.5', 41230, 'request payload');

        $oTransport->send('reply payload', '192.168.0.5');

        $this->assertEmpty($oClient->aSentData);
    }

    public function testSendWithNoAddressIsDroppedSilently(): void
    {
        $oClient = $this->buildClient();
        $oTransport = new RelayedUdpTransport($oClient, 32770);

        $oTransport->send('reply payload');

        $this->assertEmpty($oClient->aSentData);
    }
}
