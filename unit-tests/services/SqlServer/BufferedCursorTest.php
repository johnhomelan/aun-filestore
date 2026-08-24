<?php

/*
 * @group unit-tests
 *
 * Unit tests for HomeLan\FileStore\Services\Provider\SqlServer\BufferedCursor,
 * against a real temp-file SQLite database (fast, no external service
 * needed - see the sql-server plan).
 */

use PHPUnit\Framework\TestCase;
use HomeLan\FileStore\Services\Provider\SqlServer\BufferedCursor;

include_once(__DIR__ . '/../../../src/include/system.inc.php');

class BufferedCursorTest extends TestCase
{
    protected \PDO $oPdo;

    protected function setUp(): void
    {
        $this->oPdo = new \PDO('sqlite::memory:');
        $this->oPdo->exec('CREATE TABLE t (id INTEGER, name TEXT)');
        $this->oPdo->exec("INSERT INTO t VALUES (1, 'alice'), (2, 'bob'), (3, 'carol')");
    }

    protected function _cursor(string $sSql): BufferedCursor
    {
        $oStatement = $this->oPdo->query($sSql);
        $this->assertNotFalse($oStatement);
        return new BufferedCursor($oStatement);
    }

    public function testColumnNamesAvailableWithNoRowsFetched(): void
    {
        $oCursor = $this->_cursor('SELECT id, name FROM t WHERE 1=0');
        $this->assertSame(['id', 'name'], $oCursor->getColumnNames());
        $this->assertFalse($oCursor->isExhausted());
    }

    public function testFetchNextPagesThroughAllRows(): void
    {
        $oCursor = $this->_cursor('SELECT id, name FROM t ORDER BY id');

        $aPage1 = $oCursor->fetchNext(2);
        $this->assertCount(2, $aPage1);
        $this->assertSame(1, $aPage1[0]['id']);
        $this->assertSame('bob', $aPage1[1]['name']);
        $this->assertFalse($oCursor->isExhausted());

        $aPage2 = $oCursor->fetchNext(2);
        $this->assertCount(1, $aPage2);
        $this->assertSame('carol', $aPage2[0]['name']);
        $this->assertTrue($oCursor->isExhausted());

        $aPage3 = $oCursor->fetchNext(2);
        $this->assertSame([], $aPage3);
    }

    public function testEmptyResultSetIsImmediatelyExhausted(): void
    {
        $oCursor = $this->_cursor('SELECT id, name FROM t WHERE 1=0');
        $this->assertSame([], $oCursor->fetchNext(10));
        $this->assertTrue($oCursor->isExhausted());
    }

    public function testCloseDoesNotThrow(): void
    {
        $oCursor = $this->_cursor('SELECT id FROM t');
        $oCursor->close();
        $this->addToAssertionCount(1);
    }
}
