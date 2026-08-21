<?php

/*
 * @group unit-tests
 *
 * Tests for ShareFs\Messages\FreewayPacket encode/decode round-tripping.
 */

include_once(__DIR__ . '/../../src/include/system.inc.php');

use PHPUnit\Framework\TestCase;
use HomeLan\FileStore\ShareFs\Messages\FreewayPacket;

class FreewayPacketTest extends TestCase
{
    public function testRoundTripsAvailableBroadcast(): void
    {
        $oPacket = new FreewayPacket(FreewayPacket::TYPE_DISC, FreewayPacket::MINOR_AVAILABLE, 'DISC0');
        $oDecoded = FreewayPacket::decode($oPacket->encode());

        $this->assertSame(FreewayPacket::TYPE_DISC, $oDecoded->getType());
        $this->assertSame(FreewayPacket::MINOR_AVAILABLE, $oDecoded->getMinor());
        $this->assertSame('DISC0', $oDecoded->getName());
        $this->assertSame('', $oDecoded->getDescription());
    }

    public function testRoundTripsWithDescription(): void
    {
        $oPacket = new FreewayPacket(FreewayPacket::TYPE_PRINTER, FreewayPacket::MINOR_AVAILABLE, 'LaserJet', 'PostScript Printer');
        $oDecoded = FreewayPacket::decode($oPacket->encode());

        $this->assertSame('LaserJet', $oDecoded->getName());
        $this->assertSame('PostScript Printer', $oDecoded->getDescription());
    }

    public function testEncodedWord0PacksTypeAndMinor(): void
    {
        $oPacket = new FreewayPacket(1, 2, '');
        // word0 = type<<16 | minor = 0x00010002, little-endian on the wire
        $this->assertSame("\x02\x00\x01\x00", substr($oPacket->encode(), 0, 4));
    }

    public function testEncodedVersionWordIsFixed(): void
    {
        $oPacket = new FreewayPacket(1, 2, '');
        $this->assertSame("\x00\x00\x01\x00", substr($oPacket->encode(), 4, 4));
    }

    public function testDecodeRejectsTooShortPacket(): void
    {
        $this->expectException(\Exception::class);
        FreewayPacket::decode('short');
    }

    public function testDecodeRejectsTruncatedName(): void
    {
        // Header claims a 10-byte name but none follows.
        $sHeader = pack('V', (1 << 16) | 2) . pack('V', 0x00010000) . pack('V', 10);
        $this->expectException(\Exception::class);
        FreewayPacket::decode($sHeader);
    }
}
