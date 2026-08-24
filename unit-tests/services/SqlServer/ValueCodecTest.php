<?php

/*
 * @group unit-tests
 *
 * Unit tests for HomeLan\FileStore\Services\Provider\SqlServer\ValueCodec.
 *
 * Pure logic, no DB/network access - exhaustive round-trip coverage of the
 * wire value format used for both bound parameters and result cells (see
 * docs/protocols/sql-server.md).
 */

use PHPUnit\Framework\TestCase;
use HomeLan\FileStore\Services\Provider\SqlServer\ValueCodec;

include_once(__DIR__ . '/../../../src/include/system.inc.php');

class ValueCodecTest extends TestCase
{
    protected ValueCodec $oCodec;

    protected function setUp(): void
    {
        $this->oCodec = new ValueCodec();
    }

    // -------------------------------------------------------------------------
    // encodeCell() - auto-tagging from PHP type
    // -------------------------------------------------------------------------

    public function testEncodesNull(): void
    {
        $this->assertSame(chr(ValueCodec::TAG_NULL), $this->oCodec->encodeCell(null));
    }

    public function testEncodesPositiveInteger(): void
    {
        $sWire = $this->oCodec->encodeCell(42);
        $this->assertSame(ValueCodec::TAG_INTEGER, ord($sWire[0]));
        $aDecoded = $this->oCodec->decodeParameter($sWire, 0);
        $this->assertSame(42, $aDecoded['value']);
        $this->assertSame(9, $aDecoded['length']);
    }

    public function testEncodesNegativeInteger(): void
    {
        $sWire = $this->oCodec->encodeCell(-12345);
        $aDecoded = $this->oCodec->decodeParameter($sWire, 0);
        $this->assertSame(-12345, $aDecoded['value']);
    }

    public function testEncodesLargeInteger(): void
    {
        $iValue = 9223372036854775800; // near PHP_INT_MAX
        $sWire = $this->oCodec->encodeCell($iValue);
        $aDecoded = $this->oCodec->decodeParameter($sWire, 0);
        $this->assertSame($iValue, $aDecoded['value']);
    }

    public function testEncodesFloat(): void
    {
        $sWire = $this->oCodec->encodeCell(3.14159);
        $this->assertSame(ValueCodec::TAG_FLOAT, ord($sWire[0]));
        $aDecoded = $this->oCodec->decodeParameter($sWire, 0);
        $this->assertEqualsWithDelta(3.14159, $aDecoded['value'], 0.0000001);
    }

    public function testEncodesNegativeFloat(): void
    {
        $sWire = $this->oCodec->encodeCell(-273.15);
        $aDecoded = $this->oCodec->decodeParameter($sWire, 0);
        $this->assertEqualsWithDelta(-273.15, $aDecoded['value'], 0.0000001);
    }

    public function testEncodesTextString(): void
    {
        $sWire = $this->oCodec->encodeCell('hello world');
        $this->assertSame(ValueCodec::TAG_TEXT, ord($sWire[0]));
        $aDecoded = $this->oCodec->decodeParameter($sWire, 0);
        $this->assertSame('hello world', $aDecoded['value']);
        $this->assertSame(\PDO::PARAM_STR, $aDecoded['pdoType']);
    }

    public function testEncodesEmptyString(): void
    {
        $sWire = $this->oCodec->encodeCell('');
        $aDecoded = $this->oCodec->decodeParameter($sWire, 0);
        $this->assertSame('', $aDecoded['value']);
        $this->assertSame(3, $aDecoded['length']);
    }

    public function testEncodesBinarySafeString(): void
    {
        $sBinary = "\x00\x01\xFF\xFE binary \x00 data";
        $sWire = $this->oCodec->encodeCell($sBinary);
        $aDecoded = $this->oCodec->decodeParameter($sWire, 0);
        $this->assertSame($sBinary, $aDecoded['value']);
    }

    // -------------------------------------------------------------------------
    // encodeTagged() - explicit tag (covers BLOB, which encodeCell() never produces)
    // -------------------------------------------------------------------------

    public function testEncodeTaggedBlob(): void
    {
        $sWire = $this->oCodec->encodeTagged(ValueCodec::TAG_BLOB, "\x00binary\xFF");
        $aDecoded = $this->oCodec->decodeParameter($sWire, 0);
        $this->assertSame("\x00binary\xFF", $aDecoded['value']);
        $this->assertSame(\PDO::PARAM_LOB, $aDecoded['pdoType']);
    }

    public function testEncodeTaggedNullIgnoresValue(): void
    {
        $sWire = $this->oCodec->encodeTagged(ValueCodec::TAG_NULL, 'ignored');
        $this->assertSame(chr(ValueCodec::TAG_NULL), $sWire);
    }

    public function testEncodeTaggedUnknownTagThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->oCodec->encodeTagged(0x99, 'x');
    }

    // -------------------------------------------------------------------------
    // decodeParameter() - PDO type mapping, offsets, sequential parsing, errors
    // -------------------------------------------------------------------------

    public function testNullPdoType(): void
    {
        $aDecoded = $this->oCodec->decodeParameter(chr(ValueCodec::TAG_NULL), 0);
        $this->assertNull($aDecoded['value']);
        $this->assertSame(\PDO::PARAM_NULL, $aDecoded['pdoType']);
        $this->assertSame(1, $aDecoded['length']);
    }

    public function testIntegerPdoType(): void
    {
        $aDecoded = $this->oCodec->decodeParameter($this->oCodec->encodeCell(7), 0);
        $this->assertSame(\PDO::PARAM_INT, $aDecoded['pdoType']);
    }

    public function testDecodesMultipleParametersSequentiallyByOffset(): void
    {
        $sWire = $this->oCodec->encodeCell(1) . $this->oCodec->encodeCell('two') . $this->oCodec->encodeCell(null);

        $iOffset = 0;
        $aFirst = $this->oCodec->decodeParameter($sWire, $iOffset);
        $this->assertSame(1, $aFirst['value']);
        $iOffset += $aFirst['length'];

        $aSecond = $this->oCodec->decodeParameter($sWire, $iOffset);
        $this->assertSame('two', $aSecond['value']);
        $iOffset += $aSecond['length'];

        $aThird = $this->oCodec->decodeParameter($sWire, $iOffset);
        $this->assertNull($aThird['value']);
        $iOffset += $aThird['length'];

        $this->assertSame(strlen($sWire), $iOffset);
    }

    public function testDecodeAtNonZeroOffsetIgnoresPrecedingBytes(): void
    {
        $sWire = "\xFF\xFF" . $this->oCodec->encodeCell(99);
        $aDecoded = $this->oCodec->decodeParameter($sWire, 2);
        $this->assertSame(99, $aDecoded['value']);
    }

    public function testTruncatedTagByteThrows(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->oCodec->decodeParameter('', 0);
    }

    public function testTruncatedIntegerValueThrows(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->oCodec->decodeParameter(chr(ValueCodec::TAG_INTEGER) . "\x01\x02", 0);
    }

    public function testTruncatedTextValueThrows(): void
    {
        // Declares a length of 10 but supplies only 2 bytes of value.
        $sWire = chr(ValueCodec::TAG_TEXT) . pack('v', 10) . 'ab';
        $this->expectException(\RuntimeException::class);
        $this->oCodec->decodeParameter($sWire, 0);
    }

    public function testUnknownTagThrows(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->oCodec->decodeParameter("\x99", 0);
    }
}
