<?php

/*
 * @group unit-tests
 *
 * Tests for Client, the connecting side of the Remote Socket Protocol (see
 * docs/protocols/remote-socket.md), used by sharefsd to receive UDP traffic relayed from an
 * EconetA interface owned by filestored:
 *   - registrations made via getTransport() before hello_ok are sent once hello_ok arrives
 *   - a registration made after hello_ok is sent straight away
 *   - every previously-requested port is re-registered on a second hello_ok (i.e. after a
 *     reconnect)
 *   - a `data` frame is dispatched to the transport for its local port
 *   - a `data` frame for a port nothing has registered is ignored, not an error
 *   - sendData() sends a `data` frame
 *   - a malformed frame is discarded without throwing
 *   - an auth_fail frame makes handleFrame() throw AuthenticationFailedException
 *
 * The real network connection (Ratchet\Client\Connector) is not exercised here; connect() itself
 * is left untested (it is a thin wrapper around that library), and TestableRemoteSocketClient
 * overrides rawSend() so tests can inspect what would have gone out over the socket without one
 * actually existing.
 */

include_once(__DIR__.'/../../src/include/system.inc.php');

use PHPUnit\Framework\TestCase;
use Monolog\Logger;
use Monolog\Handler\NullHandler;
use HomeLan\FileStore\RemoteSocket\Client;
use HomeLan\FileStore\RemoteSocket\Messages\Frame;
use HomeLan\FileStore\RemoteSocket\Exceptions\AuthenticationFailedException;

class TestableRemoteSocketClient extends Client
{
    /** @var list<string> */
    public array $aSent = [];

    protected function rawSend(string $sEncoded): void
    {
        $this->aSent[] = $sEncoded;
    }

    /** @return list<Frame> */
    public function decodedSentFrames(): array
    {
        return array_map(static fn (string $sJson): Frame => Frame::decode($sJson), $this->aSent);
    }
}

class ClientTest extends TestCase
{
    private Logger $oLogger;

    protected function setUp(): void
    {
        $this->oLogger = new Logger('test');
        $this->oLogger->pushHandler(new NullHandler());
    }

    private function buildClient(): TestableRemoteSocketClient
    {
        $oLoop = \React\EventLoop\Factory::create();
        return new TestableRemoteSocketClient($oLoop, $this->oLogger, 'ws://127.0.0.1:8091', 'test-secret');
    }

    public function testRegistrationBeforeHelloOkIsNotSentImmediately(): void
    {
        $oClient = $this->buildClient();
        $oClient->getTransport(32770);
        $this->assertEmpty($oClient->aSent);
    }

    public function testRegistrationIsFlushedOnHelloOk(): void
    {
        $oClient = $this->buildClient();
        $oClient->getTransport(32770);
        $oClient->handleFrame(Frame::helloOk(Frame::PROTOCOL_VERSION)->encode());

        $aFrames = $oClient->decodedSentFrames();
        $oRegister = $aFrames[count($aFrames) - 1];
        $this->assertSame(Frame::TYPE_REGISTER, $oRegister->getType());
        $this->assertSame([['protocol' => 'UDP', 'port' => 32770]], $oRegister->getServices());
    }

    public function testRegistrationAfterHelloOkIsSentImmediately(): void
    {
        $oClient = $this->buildClient();
        $oClient->handleFrame(Frame::helloOk(Frame::PROTOCOL_VERSION)->encode());
        $oClient->getTransport(32771);

        $aFrames = $oClient->decodedSentFrames();
        $oRegister = $aFrames[count($aFrames) - 1];
        $this->assertSame(Frame::TYPE_REGISTER, $oRegister->getType());
        $this->assertSame([['protocol' => 'UDP', 'port' => 32771]], $oRegister->getServices());
    }

    public function testSameLocalPortReturnsTheSameTransportAndOnlyRegistersOnce(): void
    {
        $oClient = $this->buildClient();
        $oTransportA = $oClient->getTransport(32770);
        $oTransportB = $oClient->getTransport(32770);
        $oClient->handleFrame(Frame::helloOk(Frame::PROTOCOL_VERSION)->encode());

        $this->assertSame($oTransportA, $oTransportB);
        $aRegisterFrames = array_filter($oClient->decodedSentFrames(), static fn (Frame $oFrame): bool => $oFrame->getType() === Frame::TYPE_REGISTER);
        $this->assertCount(1, $aRegisterFrames);
    }

    public function testAllPortsAreReRegisteredOnASecondHelloOk(): void
    {
        // Simulates a reconnect: connect, get a port registered, then a second hello_ok arrives
        // (as it would after Client reconnects and re-authenticates) without any new
        // getTransport() call in between.
        $oClient = $this->buildClient();
        $oClient->getTransport(32770);
        $oClient->handleFrame(Frame::helloOk(Frame::PROTOCOL_VERSION)->encode());
        $oClient->aSent = []; // discard the first registration, only interested in the reconnect

        $oClient->handleFrame(Frame::helloOk(Frame::PROTOCOL_VERSION)->encode());

        $aRegisterFrames = array_values(array_filter(
            $oClient->decodedSentFrames(),
            static fn (Frame $oFrame): bool => $oFrame->getType() === Frame::TYPE_REGISTER,
        ));
        $this->assertCount(1, $aRegisterFrames);
        $this->assertSame([['protocol' => 'UDP', 'port' => 32770]], $aRegisterFrames[0]->getServices());
    }

    public function testEveryPortIsReRegisteredOnReconnectNotJustTheLastOne(): void
    {
        $oClient = $this->buildClient();
        $oClient->getTransport(32770);
        $oClient->handleFrame(Frame::helloOk(Frame::PROTOCOL_VERSION)->encode());
        $oClient->getTransport(32771); // registered immediately, already authenticated
        $oClient->aSent = [];

        $oClient->handleFrame(Frame::helloOk(Frame::PROTOCOL_VERSION)->encode());

        $aPorts = array_map(
            static fn (Frame $oFrame): int => $oFrame->getServices()[0]['port'],
            array_values(array_filter(
                $oClient->decodedSentFrames(),
                static fn (Frame $oFrame): bool => $oFrame->getType() === Frame::TYPE_REGISTER,
            )),
        );
        sort($aPorts);
        $this->assertSame([32770, 32771], $aPorts);
    }

    public function testDataFrameIsDeliveredToTheMatchingTransport(): void
    {
        $oClient = $this->buildClient();
        $oTransport = $oClient->getTransport(32770);
        $oClient->handleFrame(Frame::helloOk(Frame::PROTOCOL_VERSION)->encode());

        $aReceived = [];
        $oTransport->on('message', function (string $sPayload, string $sSrcAddress) use (&$aReceived): void {
            $aReceived[] = [$sPayload, $sSrcAddress];
        });

        $oClient->handleFrame(Frame::data('UDP', '192.168.0.1', 32770, '192.168.0.5', 41230, 'freeway broadcast')->encode());

        $this->assertCount(1, $aReceived);
        $this->assertSame('freeway broadcast', $aReceived[0][0]);
        $this->assertSame('192.168.0.5:41230', $aReceived[0][1]);
    }

    public function testDataFrameForAnUnregisteredPortIsIgnored(): void
    {
        $oClient = $this->buildClient();
        $oClient->handleFrame(Frame::helloOk(Frame::PROTOCOL_VERSION)->encode());
        $oClient->handleFrame(Frame::data('UDP', '192.168.0.1', 32770, '192.168.0.5', 41230, 'ignored')->encode());
        $this->addToAssertionCount(1); // reaching here without an exception is the assertion
    }

    public function testSendDataSendsADataFrame(): void
    {
        $oClient = $this->buildClient();
        $oClient->sendData('192.168.0.1', 32770, '192.168.0.5', 41230, 'ShareFS reply bytes');

        [$oFrame] = $oClient->decodedSentFrames();
        $this->assertSame(Frame::TYPE_DATA, $oFrame->getType());
        $this->assertSame('192.168.0.1', $oFrame->getLocalAddr());
        $this->assertSame(32770, $oFrame->getLocalPort());
        $this->assertSame('192.168.0.5', $oFrame->getRemoteAddr());
        $this->assertSame(41230, $oFrame->getRemotePort());
        $this->assertSame('ShareFS reply bytes', $oFrame->getPayload());
    }

    public function testMalformedFrameIsDiscardedWithoutThrowing(): void
    {
        $oClient = $this->buildClient();
        $oClient->handleFrame('not json at all');
        $this->addToAssertionCount(1);
    }

    public function testAuthFailThrowsAuthenticationFailedException(): void
    {
        $oClient = $this->buildClient();
        $this->expectException(AuthenticationFailedException::class);
        $oClient->handleFrame(Frame::authFail()->encode());
    }
}
