<?php

/*
 * @group unit-tests
 *
 * Tests for RemoteBridge BridgePacket encode/decode.
 */

include_once(__DIR__ . '/../../src/include/system.inc.php');

use PHPUnit\Framework\TestCase;
use HomeLan\FileStore\RemoteBridge\BridgePacket;
use HomeLan\FileStore\Messages\EconetPacket;

class RemoteBridgePacketTest extends TestCase
{
    private function makePkt(int $dstNet, int $dstStn, int $srcNet, int $srcStn, int $port, int $flags, string $data): EconetPacket
    {
        $o = new EconetPacket();
        $o->setDestinationNetwork($dstNet);
        $o->setDestinationstation($dstStn);
        $o->setSourceNetwork($srcNet);
        $o->setSourceStation($srcStn);
        $o->setPort($port);
        $o->setFlags($flags);
        $o->setData($data);
        return $o;
    }

    public function testEncodeProducesSendLine(): void
    {
        $oPkt = $this->makePkt(2, 5, 1, 254, 0x97, 0, 'Hello');
        $sLine = BridgePacket::encode($oPkt);
        $this->assertStringStartsWith('SEND ', $sLine);
        $this->assertStringEndsWith("\n", $sLine);
    }

    public function testEncodeContainsAllFields(): void
    {
        $oPkt = $this->makePkt(2, 5, 1, 254, 0x97, 3, 'Test');
        $sLine = trim(BridgePacket::encode($oPkt));
        $aParts = explode(' ', $sLine);
        $this->assertSame('SEND',  $aParts[0]);
        $this->assertSame('2',     $aParts[1]);  // dst_net
        $this->assertSame('5',     $aParts[2]);  // dst_stn
        $this->assertSame('1',     $aParts[3]);  // src_net
        $this->assertSame('254',   $aParts[4]);  // src_stn
        $this->assertSame('151',   $aParts[5]);  // port 0x97 = 151
        $this->assertSame('3',     $aParts[6]);  // flags
        $this->assertSame(base64_encode('Test'), $aParts[7]);
    }

    public function testFromLineDecodesCorrectly(): void
    {
        $oPkt = $this->makePkt(2, 5, 1, 254, 0x97, 0, 'Hello');
        $sLine = BridgePacket::encode($oPkt);
        $oDecoded = BridgePacket::fromLine($sLine);
        $this->assertNotNull($oDecoded);
        $this->assertSame(2,   $oDecoded->getDstNetwork());
        $this->assertSame(5,   $oDecoded->getDstStation());
        $this->assertSame(1,   $oDecoded->getSrcNetwork());
        $this->assertSame(254, $oDecoded->getSrcStation());
        $this->assertSame(0x97, $oDecoded->getPort());
        $this->assertSame(0,   $oDecoded->getFlags());
        $this->assertSame('Hello', $oDecoded->getData());
    }

    public function testFromLineRoundTrip(): void
    {
        $oPkt = $this->makePkt(3, 100, 1, 200, 0xD1, 2, "\x00\x01\x02\xff");
        $oDecoded = BridgePacket::fromLine(BridgePacket::encode($oPkt));
        $this->assertNotNull($oDecoded);
        $this->assertSame("\x00\x01\x02\xff", $oDecoded->getData());
    }

    public function testFromLineRejectsWrongCommand(): void
    {
        $this->assertNull(BridgePacket::fromLine("RECV 2 5 1 254 151 0 SGVsbG8="));
    }

    public function testFromLineRejectsMissingFields(): void
    {
        // Only 5 numeric fields (missing flags) — must be rejected
        $this->assertNull(BridgePacket::fromLine("SEND 2 5 1 254 151"));
    }

    public function testFromLineAcceptsEmptyPayload(): void
    {
        // 7 words (command + 6 numeric fields, no base64 part) is a valid empty-data packet
        $oPkt = BridgePacket::fromLine("SEND 2 5 1 254 151 0");
        $this->assertNotNull($oPkt);
        $this->assertSame('', $oPkt->getData());
    }

    public function testFromLineRejectsEmptyString(): void
    {
        $this->assertNull(BridgePacket::fromLine(''));
    }

    public function testBuildEconetPacketSetsAllFields(): void
    {
        $oPkt = $this->makePkt(2, 5, 1, 254, 0x97, 0, 'Data');
        $oDecoded = BridgePacket::fromLine(BridgePacket::encode($oPkt));
        $this->assertNotNull($oDecoded);
        $oEco = $oDecoded->buildEconetPacket();
        $this->assertSame(2,   $oEco->getDestinationNetwork());
        $this->assertSame(5,   $oEco->getDestinationStation());
        $this->assertSame(1,   $oEco->getSourceNetwork());
        $this->assertSame(254, $oEco->getSourceStation());
        $this->assertSame(0x97, $oEco->getPort());
        $this->assertSame(0,   $oEco->getFlags());
        $this->assertSame('Data', $oEco->getData());
    }

    public function testGetPacketTypeUnicastForNonBroadcast(): void
    {
        $oPkt = $this->makePkt(2, 5, 1, 254, 0x97, 0, '');
        $oDecoded = BridgePacket::fromLine(BridgePacket::encode($oPkt));
        $this->assertSame('Unicast', $oDecoded->getPacketType());
    }

    public function testGetPacketTypeBroadcastForStation255(): void
    {
        $oPkt = $this->makePkt(2, 255, 1, 254, 0x9F, 0, '');
        $oDecoded = BridgePacket::fromLine(BridgePacket::encode($oPkt));
        $this->assertSame('Broadcast', $oDecoded->getPacketType());
    }

    public function testDecodeInstanceMethodMatchesFactory(): void
    {
        $oPkt = $this->makePkt(2, 5, 1, 254, 0x97, 0, 'Test');
        $sLine = BridgePacket::encode($oPkt);
        $oViaFactory = BridgePacket::fromLine($sLine);
        $oViaDecode = new BridgePacket();
        $oViaDecode->decode($sLine);
        $this->assertSame($oViaFactory->getDstNetwork(), $oViaDecode->getDstNetwork());
        $this->assertSame($oViaFactory->getData(), $oViaDecode->getData());
    }

    public function testToStringContainsDstAndSrc(): void
    {
        $oPkt = $this->makePkt(2, 5, 1, 254, 0x97, 0, '');
        $oDecoded = BridgePacket::fromLine(BridgePacket::encode($oPkt));
        $s = $oDecoded->toString();
        $this->assertStringContainsString('2.5', $s);
        $this->assertStringContainsString('1.254', $s);
    }

    public function testEncodedDataIsValidBase64(): void
    {
        $oPkt = $this->makePkt(1, 1, 1, 1, 1, 0, "\x00\xff\xfe\x80");
        $sLine = trim(BridgePacket::encode($oPkt));
        $aParts = explode(' ', $sLine);
        $sDecoded = base64_decode($aParts[7], true);
        $this->assertNotFalse($sDecoded);
        $this->assertSame("\x00\xff\xfe\x80", $sDecoded);
    }

    // -------------------------------------------------------------------------
    // ACK (protocol 1.1+) — see docs/protocols/remote-bridge.md
    // -------------------------------------------------------------------------

    public function testEncodeAckProducesAckLine(): void
    {
        $sLine = BridgePacket::encodeAck(2, 254);
        $this->assertSame("ACK 2 254\n", $sLine);
    }

    public function testMakeAckSetsPacketTypeToAck(): void
    {
        $oPkt = new BridgePacket();
        $oPkt->makeAck(2, 254);
        $this->assertSame('Ack', $oPkt->getPacketType());
    }

    public function testMakeAckPlacesNetworkAndStationInEconetSourceFields(): void
    {
        // ServiceDispatcher::ackEvents() reads getSourceNetwork()/getSourceStation()
        // to find the matching addAckEvent() registration — an ack-flavoured
        // BridgePacket must map (net, stn) there, not into the destination fields.
        $oPkt = new BridgePacket();
        $oPkt->makeAck(2, 254);
        $oEco = $oPkt->buildEconetPacket();
        $this->assertSame(2, $oEco->getSourceNetwork());
        $this->assertSame(254, $oEco->getSourceStation());
    }

    public function testFromAckLineParsesCorrectly(): void
    {
        $oPkt = BridgePacket::fromAckLine("ACK 2 254\n");
        $this->assertNotNull($oPkt);
        $this->assertSame('Ack', $oPkt->getPacketType());
        $this->assertSame(2, $oPkt->getSrcNetwork());
        $this->assertSame(254, $oPkt->getSrcStation());
    }

    public function testFromAckLineRoundTripsWithEncodeAck(): void
    {
        $oPkt = BridgePacket::fromAckLine(BridgePacket::encodeAck(3, 100));
        $this->assertNotNull($oPkt);
        $this->assertSame(3, $oPkt->getSrcNetwork());
        $this->assertSame(100, $oPkt->getSrcStation());
    }

    public function testFromAckLineRejectsWrongCommand(): void
    {
        $this->assertNull(BridgePacket::fromAckLine("SEND 2 5 1 254 151 0"));
    }

    public function testFromAckLineRejectsMissingFields(): void
    {
        $this->assertNull(BridgePacket::fromAckLine("ACK 2"));
    }

    public function testFromAckLineRejectsExtraFields(): void
    {
        $this->assertNull(BridgePacket::fromAckLine("ACK 2 254 999"));
    }

    public function testFromAckLineRejectsEmptyString(): void
    {
        $this->assertNull(BridgePacket::fromAckLine(''));
    }
}
