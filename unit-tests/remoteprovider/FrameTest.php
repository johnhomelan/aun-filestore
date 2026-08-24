<?php

/*
 * @group unit-tests
 *
 * Tests for RemoteProvider\Messages\Frame - Remote Provider Protocol wire framing.
 */

include_once(__DIR__ . '/../../src/include/system.inc.php');

use PHPUnit\Framework\TestCase;
use HomeLan\FileStore\RemoteProvider\Messages\Frame;

class RemoteProviderFrameTest extends TestCase
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
        $oDecoded = Frame::decode(Frame::register([182, 183])->encode());
        $this->assertSame(Frame::TYPE_REGISTER, $oDecoded->getType());
        $this->assertSame([182, 183], $oDecoded->getPorts());
    }

    public function testRegisterOkRoundTrips(): void
    {
        $oDecoded = Frame::decode(Frame::registerOk(182)->encode());
        $this->assertSame(Frame::TYPE_REGISTER_OK, $oDecoded->getType());
        $this->assertSame(182, $oDecoded->getPort());
    }

    public function testRegisterFailRoundTrips(): void
    {
        $oDecoded = Frame::decode(Frame::registerFail(182, 'port already in use')->encode());
        $this->assertSame(Frame::TYPE_REGISTER_FAIL, $oDecoded->getType());
        $this->assertSame(182, $oDecoded->getPort());
        $this->assertSame('port already in use', $oDecoded->getReason());
    }

    public function testPacketRoundTrips(): void
    {
        $oFrame = Frame::packet(Frame::KIND_UNICAST, 0, 42, 0, 254, 182, 0x80, "\x06hello");
        $oDecoded = Frame::decode($oFrame->encode());

        $this->assertSame(Frame::TYPE_PACKET, $oDecoded->getType());
        $this->assertSame(Frame::KIND_UNICAST, $oDecoded->getKind());
        $this->assertSame(0, $oDecoded->getSrcNet());
        $this->assertSame(42, $oDecoded->getSrcStn());
        $this->assertSame(0, $oDecoded->getDstNet());
        $this->assertSame(254, $oDecoded->getDstStn());
        $this->assertSame(182, $oDecoded->getPort());
        $this->assertSame(0x80, $oDecoded->getFlags());
        $this->assertSame("\x06hello", $oDecoded->getPayload());
    }

    public function testBroadcastPacketRoundTrips(): void
    {
        $oDecoded = Frame::decode(Frame::packet(Frame::KIND_BROADCAST, 0, 42, 0, 255, 182, 0, 'hi')->encode());
        $this->assertSame(Frame::KIND_BROADCAST, $oDecoded->getKind());
        $this->assertSame(255, $oDecoded->getDstStn());
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

    public function testGetPortsIgnoresNonScalarEntries(): void
    {
        $oDecoded = Frame::decode('{"type":"register","ports":[182,[1,2],"183"]}');
        $this->assertSame([182, 0, 183], $oDecoded->getPorts());
    }

    public function testEncodeProducesExpectedTypeField(): void
    {
        $aDecoded = json_decode(Frame::ping()->encode(), true);
        $this->assertSame('ping', $aDecoded['type']);
    }
}
