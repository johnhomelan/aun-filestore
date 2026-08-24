<?php

/*
 * @group unit-tests
 *
 * Unit tests for HomeLan\FileStore\Services\Provider\SqlServer\PgsqlCursor,
 * against a real PostgreSQL server - the explicit DECLARE/FETCH cursor
 * this class generates was verified directly against a real instance while
 * building it (see the sql-server plan), and this test reproduces that
 * same check as a repeatable, real integration test.
 *
 * No PostgreSQL server is assumed to be available in every environment
 * this suite runs in (this project's own dev sandbox has none by
 * default) - the connection DSN is configurable via the
 * SQLSERVER_TEST_PGSQL_DSN/_USER/_PASSWORD env vars, and every test skips
 * cleanly if connecting fails rather than failing the whole suite.
 */

use PHPUnit\Framework\TestCase;
use HomeLan\FileStore\Services\Provider\SqlServer\PgsqlCursor;

include_once(__DIR__ . '/../../../src/include/system.inc.php');

class PgsqlCursorTest extends TestCase
{
    protected ?\PDO $oPdo = null;

    protected function setUp(): void
    {
        $sDsn = getenv('SQLSERVER_TEST_PGSQL_DSN') ?: 'pgsql:host=127.0.0.1;port=5432;dbname=postgres';
        $sUser = getenv('SQLSERVER_TEST_PGSQL_USER') ?: 'postgres';
        $sPassword = getenv('SQLSERVER_TEST_PGSQL_PASSWORD') ?: '';

        try {
            $this->oPdo = new \PDO($sDsn, $sUser, $sPassword);
        } catch (\PDOException $oException) {
            $this->markTestSkipped('No reachable PostgreSQL server (' . $sDsn . '): ' . $oException->getMessage());
        }

        $this->oPdo->exec('DROP TABLE IF EXISTS sqlservertest_t');
        $this->oPdo->exec('CREATE TABLE sqlservertest_t (id INTEGER, name TEXT)');
        $this->oPdo->exec("INSERT INTO sqlservertest_t VALUES (1, 'alice'), (2, 'bob'), (3, 'carol')");
    }

    protected function tearDown(): void
    {
        $this->oPdo?->exec('DROP TABLE IF EXISTS sqlservertest_t');
    }

    public function testColumnNamesAvailableWithNoRowsFetched(): void
    {
        $oCursor = new PgsqlCursor($this->oPdo, 'SELECT id, name FROM sqlservertest_t WHERE 1=0', []);
        $this->assertSame(['id', 'name'], $oCursor->getColumnNames());
        $oCursor->close();
    }

    public function testFetchNextPagesThroughAllRows(): void
    {
        $oCursor = new PgsqlCursor($this->oPdo, 'SELECT id, name FROM sqlservertest_t ORDER BY id', []);

        $aPage1 = $oCursor->fetchNext(2);
        $this->assertCount(2, $aPage1);
        $this->assertSame(1, $aPage1[0]['id']);
        $this->assertSame('bob', $aPage1[1]['name']);
        $this->assertFalse($oCursor->isExhausted());

        $aPage2 = $oCursor->fetchNext(2);
        $this->assertCount(1, $aPage2);
        $this->assertSame('carol', $aPage2[0]['name']);
        $this->assertTrue($oCursor->isExhausted());

        $oCursor->close();
    }

    public function testBoundParametersAreApplied(): void
    {
        $oCursor = new PgsqlCursor(
            $this->oPdo,
            'SELECT id, name FROM sqlservertest_t WHERE id > ? ORDER BY id',
            [['value' => 1, 'pdoType' => \PDO::PARAM_INT]]
        );

        $aRows = $oCursor->fetchNext(10);
        $this->assertCount(2, $aRows);
        $this->assertSame('bob', $aRows[0]['name']);
        $this->assertSame('carol', $aRows[1]['name']);

        $oCursor->close();
    }

    public function testCloseCommitsAndReleasesTheCursor(): void
    {
        $oCursor = new PgsqlCursor($this->oPdo, 'SELECT id FROM sqlservertest_t', []);
        $oCursor->fetchNext(1);
        $oCursor->close();

        $this->assertFalse($this->oPdo->inTransaction());
    }
}
