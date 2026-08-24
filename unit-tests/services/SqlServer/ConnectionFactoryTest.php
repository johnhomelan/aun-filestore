<?php

/*
 * @group unit-tests
 *
 * Unit tests for HomeLan\FileStore\Services\Provider\SqlServer\ConnectionFactory,
 * against real (in-memory/temp-file) SQLite connections - cheap enough to
 * open several for real rather than mocking PDO.
 */

use PHPUnit\Framework\TestCase;
use HomeLan\FileStore\Services\Provider\SqlServer\ConnectionFactory;
use HomeLan\FileStore\Services\Provider\SqlServer\DatabaseDefinition;

include_once(__DIR__ . '/../../../src/include/system.inc.php');

class ConnectionFactoryTest extends TestCase
{
    protected function tearDown(): void
    {
        config::resetValue('sql_max_connections_per_database');
    }

    protected function _db(string $sName = 'accounts'): DatabaseDefinition
    {
        return new DatabaseDefinition($sName, 'sqlite', 'sqlite::memory:', '', '', []);
    }

    public function testReturnsAPdoConnection(): void
    {
        $oFactory = new ConnectionFactory();
        $oPdo = $oFactory->getConnection(0, 1, $this->_db());
        $this->assertInstanceOf(PDO::class, $oPdo);
    }

    public function testSameStationAndDatabaseReusesTheSameConnection(): void
    {
        $oFactory = new ConnectionFactory();
        $oDb = $this->_db();
        $oFirst = $oFactory->getConnection(0, 1, $oDb);
        $oSecond = $oFactory->getConnection(0, 1, $oDb);
        $this->assertSame($oFirst, $oSecond);
    }

    public function testDifferentStationsGetDifferentConnections(): void
    {
        $oFactory = new ConnectionFactory();
        $oDb = $this->_db();
        $oStation1 = $oFactory->getConnection(0, 1, $oDb);
        $oStation2 = $oFactory->getConnection(0, 2, $oDb);
        $this->assertNotSame($oStation1, $oStation2);
    }

    public function testDifferentDatabasesForTheSameStationGetDifferentConnections(): void
    {
        $oFactory = new ConnectionFactory();
        $oAccounts = $oFactory->getConnection(0, 1, $this->_db('accounts'));
        $oInventory = $oFactory->getConnection(0, 1, $this->_db('inventory'));
        $this->assertNotSame($oAccounts, $oInventory);
    }

    public function testCloseConnectionAllowsReopening(): void
    {
        $oFactory = new ConnectionFactory();
        $oDb = $this->_db();
        $oFirst = $oFactory->getConnection(0, 1, $oDb);
        $oFactory->closeConnection(0, 1, 'accounts');
        $oSecond = $oFactory->getConnection(0, 1, $oDb);
        $this->assertNotSame($oFirst, $oSecond);
    }

    public function testCloseAllForStationClosesEveryDatabaseForThatStationOnly(): void
    {
        $oFactory = new ConnectionFactory();
        $oFactory->getConnection(0, 1, $this->_db('accounts'));
        $oFactory->getConnection(0, 1, $this->_db('inventory'));
        $oFactory->getConnection(0, 2, $this->_db('accounts'));

        $oFactory->closeAllForStation(0, 1);

        $this->assertSame([['network' => 0, 'station' => 2]], $oFactory->activeStations());
    }

    public function testActiveStationsListsEachStationOnceAcrossMultipleDatabases(): void
    {
        $oFactory = new ConnectionFactory();
        $oFactory->getConnection(0, 1, $this->_db('accounts'));
        $oFactory->getConnection(0, 1, $this->_db('inventory'));

        $this->assertSame([['network' => 0, 'station' => 1]], $oFactory->activeStations());
    }

    public function testMaxConnectionsPerDatabaseCapIsEnforced(): void
    {
        config::overrideValue('sql_max_connections_per_database', 2);
        $oFactory = new ConnectionFactory();
        $oDb = $this->_db();

        $oFactory->getConnection(0, 1, $oDb);
        $oFactory->getConnection(0, 2, $oDb);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/Too many open connections/');
        $oFactory->getConnection(0, 3, $oDb);
    }

    public function testClosingAConnectionFreesUpTheCap(): void
    {
        config::overrideValue('sql_max_connections_per_database', 1);
        $oFactory = new ConnectionFactory();
        $oDb = $this->_db();

        $oFactory->getConnection(0, 1, $oDb);
        $oFactory->closeConnection(0, 1, 'accounts');

        // Would throw if the cap wasn't correctly decremented.
        $oFactory->getConnection(0, 2, $oDb);
        $this->addToAssertionCount(1);
    }

    public function testCapIsPerDatabaseNotGlobal(): void
    {
        config::overrideValue('sql_max_connections_per_database', 1);
        $oFactory = new ConnectionFactory();

        $oFactory->getConnection(0, 1, $this->_db('accounts'));
        // A different database name has its own budget.
        $oFactory->getConnection(0, 1, $this->_db('inventory'));
        $this->addToAssertionCount(1);
    }
}
