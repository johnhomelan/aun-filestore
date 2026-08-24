<?php

/*
 * @group unit-tests
 *
 * Unit tests for HomeLan\FileStore\Services\Provider\SqlServer\RequestPayloadParser.
 *
 * Pure logic, no network/DB access - builds literal payload byte strings
 * and checks the parsed result.
 */

use PHPUnit\Framework\TestCase;
use HomeLan\FileStore\Services\Provider\SqlServer\RequestPayloadParser;
use HomeLan\FileStore\Services\Provider\SqlServer\ValueCodec;

include_once(__DIR__ . '/../../../src/include/system.inc.php');

class RequestPayloadParserTest extends TestCase
{
    protected RequestPayloadParser $oParser;
    protected ValueCodec $oCodec;

    protected function setUp(): void
    {
        $this->oCodec = new ValueCodec();
        $this->oParser = new RequestPayloadParser($this->oCodec);
    }

    protected function _lengthPrefixed(string $s): string
    {
        return chr(strlen($s)) . $s;
    }

    // -------------------------------------------------------------------------
    // LOGIN
    // -------------------------------------------------------------------------

    public function testParsesLogin(): void
    {
        $sPayload = $this->_lengthPrefixed('JOHN') . $this->_lengthPrefixed('secret123');
        $aResult = $this->oParser->parseLogin($sPayload);

        $this->assertSame('JOHN', $aResult['username']);
        $this->assertSame('secret123', $aResult['password']);
    }

    public function testParsesLoginWithEmptyPassword(): void
    {
        $sPayload = $this->_lengthPrefixed('JOHN') . $this->_lengthPrefixed('');
        $aResult = $this->oParser->parseLogin($sPayload);

        $this->assertSame('JOHN', $aResult['username']);
        $this->assertSame('', $aResult['password']);
    }

    public function testTruncatedLoginThrows(): void
    {
        $sPayload = chr(10) . 'short'; // declares 10 bytes of username, only 5 given
        $this->expectException(\RuntimeException::class);
        $this->oParser->parseLogin($sPayload);
    }

    // -------------------------------------------------------------------------
    // QUERY
    // -------------------------------------------------------------------------

    public function testParsesQueryWithNoParameters(): void
    {
        $sPayload = $this->_lengthPrefixed('accounts') . pack('v', 25000) . chr(0) . 'SELECT 1';
        $oResult = $this->oParser->parseQuery($sPayload);

        $this->assertSame('accounts', $oResult->sDatabaseName);
        $this->assertSame(25000, $oResult->iStreamPort);
        $this->assertSame([], $oResult->aParameters);
        $this->assertSame('SELECT 1', $oResult->sSql);
    }

    public function testParsesQueryWithParameters(): void
    {
        $sPayload = $this->_lengthPrefixed('accounts')
            . pack('v', 30)
            . chr(2)
            . $this->oCodec->encodeCell(42)
            . $this->oCodec->encodeCell('bob')
            . 'SELECT * FROM t WHERE id = ? AND name = ?';

        $oResult = $this->oParser->parseQuery($sPayload);

        $this->assertCount(2, $oResult->aParameters);
        $this->assertSame(42, $oResult->aParameters[0]['value']);
        $this->assertSame(\PDO::PARAM_INT, $oResult->aParameters[0]['pdoType']);
        $this->assertSame('bob', $oResult->aParameters[1]['value']);
        $this->assertSame('SELECT * FROM t WHERE id = ? AND name = ?', $oResult->sSql);
    }

    public function testSqlTextCanContainNewlinesAndArbitraryBytes(): void
    {
        $sSql = "SELECT *\nFROM t\nWHERE id = ?;\x00trailing";
        $sPayload = $this->_lengthPrefixed('db') . pack('v', 1) . chr(0) . $sSql;

        $oResult = $this->oParser->parseQuery($sPayload);

        $this->assertSame($sSql, $oResult->sSql);
    }

    public function testEmptyDatabaseNameAndNoSqlText(): void
    {
        $sPayload = chr(0) . pack('v', 1) . chr(0);
        $oResult = $this->oParser->parseQuery($sPayload);

        $this->assertSame('', $oResult->sDatabaseName);
        $this->assertSame('', $oResult->sSql);
    }

    public function testTruncatedDatabaseNameThrows(): void
    {
        $sPayload = chr(20) . 'short';
        $this->expectException(\RuntimeException::class);
        $this->oParser->parseQuery($sPayload);
    }

    public function testTruncatedStreamPortThrows(): void
    {
        $sPayload = $this->_lengthPrefixed('db') . "\x01"; // only 1 byte of the 2-byte port
        $this->expectException(\RuntimeException::class);
        $this->oParser->parseQuery($sPayload);
    }

    public function testTruncatedParameterCountThrows(): void
    {
        $sPayload = $this->_lengthPrefixed('db') . pack('v', 1); // no parameter-count byte at all
        $this->expectException(\RuntimeException::class);
        $this->oParser->parseQuery($sPayload);
    }

    public function testTruncatedParameterThrows(): void
    {
        $sPayload = $this->_lengthPrefixed('db') . pack('v', 1) . chr(1) . chr(ValueCodec::TAG_INTEGER) . "\x01"; // integer needs 8 bytes, only 1 given
        $this->expectException(\RuntimeException::class);
        $this->oParser->parseQuery($sPayload);
    }
}
