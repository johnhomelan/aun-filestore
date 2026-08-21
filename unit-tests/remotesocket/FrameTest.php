<?php

/*
 * @group unit-tests
 *
 * Tests for RemoteSocket\Messages\Frame - Remote Socket Protocol wire framing.
 */

include_once(__DIR__ . '/../../src/include/system.inc.php');

use PHPUnit\Framework\TestCase;
use HomeLan\FileStore\RemoteSocket\Messages\Frame;

class FrameTest extends TestCase
{
    public function testHelloRoundTrips(): void
    {
        $oFrame = Frame::hello('supersecret', ['1.0']);
        $oDecoded = Frame::decode($oFrame->encode());

        $this->assertSame(Frame::TYPE_HELLO, $oDecoded->getType());
        $this->assertSame(['1.0'], $oDecoded->getVersions());
        $this->assertSame('supersecret', $oDecoded->getSecret());
    }

    public function testHelloDefaultsToCurrentProtocolVersion(): void
    {
        $oFrame = Frame::hello('secret');
        $oDecoded = Frame::decode($oFrame->encode());
        $this->assertSame([Frame::PROTOCOL_VERSION], $oDecoded->getVersions());
    }

    public function testHelloOkRoundTrips(): void
    {
        $oDecoded = Frame::decode(Frame::helloOk('1.0')->encode());
        $this->assertSame(Frame::TYPE_HELLO_OK, $oDecoded->getType());
        $this->assertSame('1.0', $oDecoded->getVersion());
    }

    public function testVersionRejectRoundTrips(): void
    {
        $oDecoded = Frame::decode(Frame::versionReject(['1.0', '2.0'])->encode());
        $this->assertSame(Frame::TYPE_VERSION_REJECT, $oDecoded->getType());
        $this->assertSame(['1.0', '2.0'], $oDecoded->getVersions());
    }

    public function testAuthFailRoundTrips(): void
    {
        $this->assertSame(Frame::TYPE_AUTH_FAIL, Frame::decode(Frame::authFail()->encode())->getType());
    }

    public function testRegisterRoundTrips(): void
    {
        $aServices = [['protocol' => 'udp', 'port' => 32770], ['protocol' => 'udp', 'port' => 32771]];
        $oDecoded = Frame::decode(Frame::register($aServices)->encode());

        $this->assertSame(Frame::TYPE_REGISTER, $oDecoded->getType());
        $this->assertSame($aServices, $oDecoded->getServices());
    }

    public function testRegisterOkRoundTrips(): void
    {
        $oDecoded = Frame::decode(Frame::registerOk('udp', 32770)->encode());
        $this->assertSame('udp', $oDecoded->getProtocol());
        $this->assertSame(32770, $oDecoded->getPort());
    }

    public function testRegisterFailRoundTrips(): void
    {
        $oDecoded = Frame::decode(Frame::registerFail('udp', 32770, 'port already in use')->encode());
        $this->assertSame(Frame::TYPE_REGISTER_FAIL, $oDecoded->getType());
        $this->assertSame('udp', $oDecoded->getProtocol());
        $this->assertSame(32770, $oDecoded->getPort());
        $this->assertSame('port already in use', $oDecoded->getReason());
    }

    public function testDataRoundTrips(): void
    {
        $oFrame = Frame::data('udp', '192.168.1.240', 32770, '192.168.1.50', 41230, "\x00\x01binary\xff");
        $oDecoded = Frame::decode($oFrame->encode());

        $this->assertSame(Frame::TYPE_DATA, $oDecoded->getType());
        $this->assertSame('udp', $oDecoded->getProtocol());
        $this->assertSame('192.168.1.240', $oDecoded->getLocalAddr());
        $this->assertSame(32770, $oDecoded->getLocalPort());
        $this->assertSame('192.168.1.50', $oDecoded->getRemoteAddr());
        $this->assertSame(41230, $oDecoded->getRemotePort());
        $this->assertSame("\x00\x01binary\xff", $oDecoded->getPayload());
        $this->assertNull($oDecoded->getStreamId());
    }

    public function testDataWithStreamIdRoundTrips(): void
    {
        $oFrame = Frame::data('tcp', '192.168.1.240', 80, '192.168.1.50', 41230, 'hello', 'stream-1');
        $oDecoded = Frame::decode($oFrame->encode());
        $this->assertSame('stream-1', $oDecoded->getStreamId());
    }

    public function testPingPongRoundTrip(): void
    {
        $this->assertSame(Frame::TYPE_PING, Frame::decode(Frame::ping()->encode())->getType());
        $this->assertSame(Frame::TYPE_PONG, Frame::decode(Frame::pong()->encode())->getType());
    }

    public function testDecodeRejectsNonJson(): void
    {
        $this->expectException(\Exception::class);
        Frame::decode('not json');
    }

    public function testDecodeRejectsMissingType(): void
    {
        $this->expectException(\Exception::class);
        Frame::decode('{"versions":["1.0"]}');
    }

    public function testGetServicesIgnoresMalformedEntries(): void
    {
        $oDecoded = Frame::decode('{"type":"register","services":[{"protocol":"udp","port":1},{"nope":true},"garbage"]}');
        $this->assertSame([['protocol' => 'udp', 'port' => 1]], $oDecoded->getServices());
    }

    public function testEncodeProducesExpectedTypeField(): void
    {
        $aDecoded = json_decode(Frame::ping()->encode(), true);
        $this->assertSame('ping', $aDecoded['type']);
    }
}
