<?php

/*
 * @group unit-tests
 *
 * Tests for RelayServer, the listening side of the Remote Socket Protocol
 * (see docs/protocols/remote-socket.md):
 *   - hello handshake: version accept/reject, secret accept/reject
 *   - register: accept, and reject on a (protocol, port) already claimed by another connection
 *   - relayInbound(): dispatches a `data` frame to the registered connection, or reports
 *     nothing was registered
 *   - a `data` frame received from a client invokes the injected reply callback
 *   - ping is answered with pong
 *   - onClose() releases that connection's registrations
 */

include_once(__DIR__.'/../../src/include/system.inc.php');

use PHPUnit\Framework\TestCase;
use Monolog\Logger;
use Monolog\Handler\NullHandler;
use Ratchet\ConnectionInterface;
use HomeLan\FileStore\RemoteSocket\RelayServer;
use HomeLan\FileStore\RemoteSocket\Messages\Frame;

#[\AllowDynamicProperties]
class FakeRelayConnection implements ConnectionInterface
{
    /** @var list<string> */
    public array $aSent = [];
    public bool $bClosed = false;

    public function send($data)
    {
        $this->aSent[] = (string) $data;
        return $this;
    }

    public function close(): void
    {
        $this->bClosed = true;
    }

    /** @return list<Frame> */
    public function decodedFrames(): array
    {
        return array_map(static fn (string $sJson): Frame => Frame::decode($sJson), $this->aSent);
    }
}

class RelayServerTest extends TestCase
{
    private const SECRET = 'correct-horse-battery-staple';

    private Logger $oLogger;

    protected function setUp(): void
    {
        $this->oLogger = new Logger('test');
        $this->oLogger->pushHandler(new NullHandler());
    }

    /** @var list<array{addr:string,port:int,remoteAddr:string,remotePort:int,payload:string}> */
    private array $aInjectedReplyCalls = [];

    private function buildServer(): RelayServer
    {
        $this->aInjectedReplyCalls = [];
        return new RelayServer($this->oLogger, self::SECRET, function (string $sLocalAddr, int $iLocalPort, string $sRemoteAddr, int $iRemotePort, string $sPayload): void {
            $this->aInjectedReplyCalls[] = ['addr' => $sLocalAddr, 'port' => $iLocalPort, 'remoteAddr' => $sRemoteAddr, 'remotePort' => $iRemotePort, 'payload' => $sPayload];
        });
    }

    private function authedConnection(RelayServer $oServer): FakeRelayConnection
    {
        $oConn = new FakeRelayConnection();
        $oServer->onOpen($oConn);
        $oServer->onMessage($oConn, Frame::hello(self::SECRET)->encode());
        return $oConn;
    }

    // -----------------------------------------------------------------------
    // Handshake
    // -----------------------------------------------------------------------

    public function testHelloWithCorrectSecretAndVersionGetsHelloOk(): void
    {
        $oServer = $this->buildServer();
        $oConn = new FakeRelayConnection();
        $oServer->onOpen($oConn);
        $oServer->onMessage($oConn, Frame::hello(self::SECRET)->encode());

        [$oReply] = $oConn->decodedFrames();
        $this->assertSame(Frame::TYPE_HELLO_OK, $oReply->getType());
        $this->assertFalse($oConn->bClosed);
    }

    public function testHelloWithWrongSecretGetsAuthFailAndCloses(): void
    {
        $oServer = $this->buildServer();
        $oConn = new FakeRelayConnection();
        $oServer->onOpen($oConn);
        $oServer->onMessage($oConn, Frame::hello('wrong-secret')->encode());

        [$oReply] = $oConn->decodedFrames();
        $this->assertSame(Frame::TYPE_AUTH_FAIL, $oReply->getType());
        $this->assertTrue($oConn->bClosed);
    }

    public function testHelloWithUnsupportedVersionGetsVersionRejectAndCloses(): void
    {
        $oServer = $this->buildServer();
        $oConn = new FakeRelayConnection();
        $oServer->onOpen($oConn);
        $oServer->onMessage($oConn, Frame::hello(self::SECRET, ['9.9'])->encode());

        [$oReply] = $oConn->decodedFrames();
        $this->assertSame(Frame::TYPE_VERSION_REJECT, $oReply->getType());
        $this->assertTrue($oConn->bClosed);
    }

    public function testRegisterBeforeHelloIsIgnored(): void
    {
        $oServer = $this->buildServer();
        $oConn = new FakeRelayConnection();
        $oServer->onOpen($oConn);
        $oServer->onMessage($oConn, Frame::register([['protocol' => 'UDP', 'port' => 32770]])->encode());

        $this->assertEmpty($oConn->decodedFrames());
    }

    // -----------------------------------------------------------------------
    // Registration
    // -----------------------------------------------------------------------

    public function testRegisterGetsRegisterOk(): void
    {
        $oServer = $this->buildServer();
        $oConn = $this->authedConnection($oServer);
        $oServer->onMessage($oConn, Frame::register([['protocol' => 'UDP', 'port' => 32770]])->encode());

        $aFrames = $oConn->decodedFrames();
        $oReply = $aFrames[count($aFrames) - 1];
        $this->assertSame(Frame::TYPE_REGISTER_OK, $oReply->getType());
        $this->assertSame('UDP', $oReply->getProtocol());
        $this->assertSame(32770, $oReply->getPort());
    }

    public function testSecondRegistrationForSamePortFails(): void
    {
        $oServer = $this->buildServer();
        $oConnA = $this->authedConnection($oServer);
        $oServer->onMessage($oConnA, Frame::register([['protocol' => 'UDP', 'port' => 32770]])->encode());

        $oConnB = $this->authedConnection($oServer);
        $oServer->onMessage($oConnB, Frame::register([['protocol' => 'UDP', 'port' => 32770]])->encode());

        $aFrames = $oConnB->decodedFrames();
        $oReply = $aFrames[count($aFrames) - 1];
        $this->assertSame(Frame::TYPE_REGISTER_FAIL, $oReply->getType());
    }

    public function testRegistrationIsFreedOnClose(): void
    {
        $oServer = $this->buildServer();
        $oConnA = $this->authedConnection($oServer);
        $oServer->onMessage($oConnA, Frame::register([['protocol' => 'UDP', 'port' => 32770]])->encode());
        $oServer->onClose($oConnA);

        $oConnB = $this->authedConnection($oServer);
        $oServer->onMessage($oConnB, Frame::register([['protocol' => 'UDP', 'port' => 32770]])->encode());

        $aFrames = $oConnB->decodedFrames();
        $oReply = $aFrames[count($aFrames) - 1];
        $this->assertSame(Frame::TYPE_REGISTER_OK, $oReply->getType());
    }

    // -----------------------------------------------------------------------
    // Relay dispatch
    // -----------------------------------------------------------------------

    public function testRelayInboundReturnsFalseWhenNothingRegistered(): void
    {
        $oServer = $this->buildServer();
        $bDelivered = $oServer->relayInbound('UDP', 32770, '192.168.0.1', '192.168.0.5', 41230, 'hello');
        $this->assertFalse($bDelivered);
    }

    public function testRelayInboundDeliversDataFrameToRegisteredConnection(): void
    {
        $oServer = $this->buildServer();
        $oConn = $this->authedConnection($oServer);
        $oServer->onMessage($oConn, Frame::register([['protocol' => 'UDP', 'port' => 32770]])->encode());

        $bDelivered = $oServer->relayInbound('UDP', 32770, '192.168.0.1', '192.168.0.5', 41230, 'freeway broadcast');
        $this->assertTrue($bDelivered);

        $aFrames = $oConn->decodedFrames();
        $oData = $aFrames[count($aFrames) - 1];
        $this->assertSame(Frame::TYPE_DATA, $oData->getType());
        $this->assertSame('UDP', $oData->getProtocol());
        $this->assertSame('192.168.0.1', $oData->getLocalAddr());
        $this->assertSame(32770, $oData->getLocalPort());
        $this->assertSame('192.168.0.5', $oData->getRemoteAddr());
        $this->assertSame(41230, $oData->getRemotePort());
        $this->assertSame('freeway broadcast', $oData->getPayload());
    }

    public function testDataFrameFromClientInvokesInjectedReplyCallback(): void
    {
        $oServer = $this->buildServer();
        $oConn = $this->authedConnection($oServer);

        $oServer->onMessage($oConn, Frame::data('UDP', '192.168.0.1', 32770, '192.168.0.5', 41230, 'reply payload')->encode());

        $this->assertCount(1, $this->aInjectedReplyCalls);
        $this->assertSame('192.168.0.1', $this->aInjectedReplyCalls[0]['addr']);
        $this->assertSame(32770, $this->aInjectedReplyCalls[0]['port']);
        $this->assertSame('192.168.0.5', $this->aInjectedReplyCalls[0]['remoteAddr']);
        $this->assertSame(41230, $this->aInjectedReplyCalls[0]['remotePort']);
        $this->assertSame('reply payload', $this->aInjectedReplyCalls[0]['payload']);
    }

    // -----------------------------------------------------------------------
    // Heartbeat
    // -----------------------------------------------------------------------

    public function testPingGetsPong(): void
    {
        $oServer = $this->buildServer();
        $oConn = $this->authedConnection($oServer);
        $oServer->onMessage($oConn, Frame::ping()->encode());

        $aFrames = $oConn->decodedFrames();
        $oReply = $aFrames[count($aFrames) - 1];
        $this->assertSame(Frame::TYPE_PONG, $oReply->getType());
    }

    // -----------------------------------------------------------------------
    // Malformed input
    // -----------------------------------------------------------------------

    public function testMalformedFrameIsDiscardedWithoutError(): void
    {
        $oServer = $this->buildServer();
        $oConn = new FakeRelayConnection();
        $oServer->onOpen($oConn);
        $oServer->onMessage($oConn, 'not json at all');

        $this->assertEmpty($oConn->decodedFrames());
        $this->assertFalse($oConn->bClosed);
    }
}
