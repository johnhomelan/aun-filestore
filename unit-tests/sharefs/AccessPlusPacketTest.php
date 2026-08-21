<?php

/*
 * @group unit-tests
 *
 * Tests for ShareFs\Messages\AccessPlusPacket - PIN folding and packet encode/decode.
 */

include_once(__DIR__ . '/../../src/include/system.inc.php');

use PHPUnit\Framework\TestCase;
use HomeLan\FileStore\ShareFs\Messages\AccessPlusPacket;

class AccessPlusPacketTest extends TestCase
{
    // -----------------------------------------------------------------------
    // Password -> PIN folding: pin = pin*37 + val, val = digit:1..10, letter:11..36
    // -----------------------------------------------------------------------

    public function testFoldsSingleLetter(): void
    {
        // 'A' -> 11
        $this->assertSame(11, AccessPlusPacket::foldPassword('A'));
    }

    public function testFoldsMultipleLetters(): void
    {
        // 'A'=11, then 'B'=12: 11*37+12 = 419
        $this->assertSame(419, AccessPlusPacket::foldPassword('AB'));
        // then 'C'=13: 419*37+13 = 15516
        $this->assertSame(15516, AccessPlusPacket::foldPassword('ABC'));
    }

    public function testFoldingIsCaseInsensitive(): void
    {
        $this->assertSame(AccessPlusPacket::foldPassword('ABC'), AccessPlusPacket::foldPassword('abc'));
    }

    public function testFoldsDigitsOneToTen(): void
    {
        // '0' -> 1, '1' -> 2
        $this->assertSame(1, AccessPlusPacket::foldPassword('0'));
        $this->assertSame(2, AccessPlusPacket::foldPassword('1'));
    }

    public function testUnrecognisedCharactersFoldAsZero(): void
    {
        // 'A'=11, then '!' contributes 0: 11*37+0 = 407
        $this->assertSame(407, AccessPlusPacket::foldPassword('A!'));
    }

    public function testEmptyPasswordFoldsToZero(): void
    {
        $this->assertSame(0, AccessPlusPacket::foldPassword(''));
    }

    public function testOnlyFirstSixCharactersAreFolded(): void
    {
        $this->assertSame(AccessPlusPacket::foldPassword('ABCDEF'), AccessPlusPacket::foldPassword('ABCDEFGHIJ'));
    }

    // -----------------------------------------------------------------------
    // Packet encode/decode
    // -----------------------------------------------------------------------

    public function testShareKeyRequestRoundTrips(): void
    {
        $iKey = AccessPlusPacket::foldPassword('secret');
        $sEncoded = AccessPlusPacket::encodeShareKeyRequest($iKey);
        $oDecoded = AccessPlusPacket::decode($sEncoded);

        $this->assertTrue($oDecoded->isShareKeyRequest());
        $this->assertSame($iKey, $oDecoded->getClientKey());
    }

    public function testDecodeRejectsTooShortPacket(): void
    {
        $this->expectException(\Exception::class);
        AccessPlusPacket::decode('short');
    }

    public function testUnrecognisedMessageIsNotAShareKeyRequest(): void
    {
        $sEncoded = pack('V', 0x00010002) . pack('V', 0x00010001) . pack('V', 1234);
        $oDecoded = AccessPlusPacket::decode($sEncoded);
        $this->assertFalse($oDecoded->isShareKeyRequest());
    }

    public function testProtectedShareReplyContainsNameAndAttributes(): void
    {
        $sEncoded = AccessPlusPacket::encodeProtectedShareReply(419, 'Documents', 0x01);
        // header(12) + key(4) + name(9) + attr(1) + null(1) = 27 bytes
        $this->assertSame(27, strlen($sEncoded));
        $this->assertStringContainsString('Documents', $sEncoded);
        $this->assertSame("\x00", substr($sEncoded, -1));
    }
}
