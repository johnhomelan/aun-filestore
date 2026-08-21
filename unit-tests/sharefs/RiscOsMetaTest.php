<?php

/*
 * @group unit-tests
 *
 * Tests for ShareFs\RiscOsMeta - RISC OS timestamp/filetype/attribute conversions.
 */

include_once(__DIR__ . '/../../src/include/system.inc.php');

use PHPUnit\Framework\TestCase;
use HomeLan\FileStore\ShareFs\RiscOsMeta;

class RiscOsMetaTest extends TestCase
{
    public function testUnixTimeRoundTripsThroughCentiseconds(): void
    {
        $iUnixTime = 1700000000;
        $iCentiseconds = RiscOsMeta::unixTimeToCentiseconds($iUnixTime);
        $this->assertSame($iUnixTime, RiscOsMeta::centisecondsToUnixTime($iCentiseconds));
    }

    public function testEpochDifferenceMatchesKnownConstant(): void
    {
        $this->assertSame(2208988800, RiscOsMeta::EPOCH_DIFF_SECONDS);
        // 1970-01-01 00:00:00 unix -> centiseconds since 1900-01-01
        $this->assertSame(2208988800 * 100, RiscOsMeta::unixTimeToCentiseconds(0));
    }

    public function testMakeLoadAddrEncodesFiletypeInHighNibbleWindow(): void
    {
        $iLoad = RiscOsMeta::makeLoadAddr(0xFFF, 0);
        $this->assertSame(0xFFF00000 | (0xFFF << 8), $iLoad);
        $this->assertSame(0xFFF, RiscOsMeta::filetypeFromLoadAddr($iLoad));
    }

    public function testUntypedLoadAddrReturnsDefaultDataFiletype(): void
    {
        $this->assertSame(RiscOsMeta::FILETYPE_DATA, RiscOsMeta::filetypeFromLoadAddr(0x12345678));
    }

    public function testMakeExecAddrIsLow32BitsOfCentiseconds(): void
    {
        $iCentiseconds = 0x1_2345_6789;
        $this->assertSame(0x23456789, RiscOsMeta::makeExecAddr($iCentiseconds));
    }

    public function testCentisecondsFromLoadExecRoundTrips(): void
    {
        $iCentiseconds = RiscOsMeta::unixTimeToCentiseconds(1700000000);
        $iLoad = RiscOsMeta::makeLoadAddr(0xFFD, $iCentiseconds);
        $iExec = RiscOsMeta::makeExecAddr($iCentiseconds);
        $this->assertSame($iCentiseconds, RiscOsMeta::centisecondsFromLoadExec($iLoad, $iExec));
    }

    public function testCentisecondsFromLoadExecReturnsNullForUntypedLoad(): void
    {
        $this->assertNull(RiscOsMeta::centisecondsFromLoadExec(0x12345678, 0));
    }

    public function testFiletypeFromSuffixParsesHex(): void
    {
        $this->assertSame(0xFFF, RiscOsMeta::filetypeFromSuffix('BugReport,fff'));
        $this->assertSame(0xC85, RiscOsMeta::filetypeFromSuffix('Photo,c85'));
    }

    public function testFiletypeFromSuffixReturnsNullWhenAbsent(): void
    {
        $this->assertNull(RiscOsMeta::filetypeFromSuffix('PlainName'));
    }

    public function testStripTypeSuffixRemovesIt(): void
    {
        $this->assertSame('BugReport', RiscOsMeta::stripTypeSuffix('BugReport,fff'));
        $this->assertSame('PlainName', RiscOsMeta::stripTypeSuffix('PlainName'));
    }

    public function testAppendTypeSuffixAddsIt(): void
    {
        $this->assertSame('BugReport,fff', RiscOsMeta::appendTypeSuffix('BugReport', 0xFFF));
        $this->assertSame('BugReport,fff', RiscOsMeta::appendTypeSuffix('BugReport,aaa', 0xFFF));
    }

    public function testEconetAccessToShareFsAttrsForOpenReadWriteFile(): void
    {
        // Econet: read pub(1)+write pub(2)+read owner(4)+write owner(8) = 15, not locked
        $iAttrs = RiscOsMeta::econetAccessToShareFsAttrs(15);
        $this->assertSame(0x01 | 0x02 | 0x10 | 0x20, $iAttrs);
    }

    public function testEconetAccessToShareFsAttrsForLockedFile(): void
    {
        // Econet: locked(16) only
        $iAttrs = RiscOsMeta::econetAccessToShareFsAttrs(16);
        $this->assertSame(0x01 | 0x08, $iAttrs);
    }
}
