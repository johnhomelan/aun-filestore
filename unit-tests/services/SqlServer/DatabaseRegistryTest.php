<?php

/*
 * @group unit-tests
 *
 * Unit tests for HomeLan\FileStore\Services\Provider\SqlServer\DatabaseRegistry.
 */

use PHPUnit\Framework\TestCase;
use HomeLan\FileStore\Services\Provider\SqlServer\DatabaseRegistry;

include_once(__DIR__ . '/../../../src/include/system.inc.php');

class TestableDatabaseRegistry extends DatabaseRegistry
{
    /** @var array<int, string> extensions to report as NOT loaded */
    public array $aMissingExtensions = [];

    protected function _isExtensionLoaded(string $sExtension): bool
    {
        return !in_array($sExtension, $this->aMissingExtensions, true);
    }
}

class DatabaseRegistryTest extends TestCase
{
    protected function tearDown(): void
    {
        $aKeys = [
            'sql_databases',
            'sql_database_accounts_engine', 'sql_database_accounts_dsn',
            'sql_database_accounts_user', 'sql_database_accounts_password',
            'sql_database_accounts_allowed_users',
            'sql_database_inventory_engine', 'sql_database_inventory_dsn',
        ];
        foreach ($aKeys as $sKey) {
            config::resetValue($sKey);
        }
    }

    public function testEmptyConfigReturnsNoDatabases(): void
    {
        config::overrideValue('sql_databases', '');
        $oRegistry = new DatabaseRegistry();
        $this->assertSame([], $oRegistry->all());
        $this->assertNull($oRegistry->get('accounts'));
    }

    public function testParsesASingleDatabase(): void
    {
        config::overrideValue('sql_databases', 'accounts');
        config::overrideValue('sql_database_accounts_engine', 'sqlite');
        config::overrideValue('sql_database_accounts_dsn', 'sqlite:/tmp/accounts.db');
        config::overrideValue('sql_database_accounts_user', '');
        config::overrideValue('sql_database_accounts_password', '');
        config::overrideValue('sql_database_accounts_allowed_users', '');

        $oDb = (new DatabaseRegistry())->get('accounts');

        $this->assertNotNull($oDb);
        $this->assertSame('accounts', $oDb->sName);
        $this->assertSame('sqlite', $oDb->sEngine);
        $this->assertSame('sqlite:/tmp/accounts.db', $oDb->sDsn);
        $this->assertTrue($oDb->isUserAllowed('ANYONE'));
    }

    public function testParsesMultipleDatabaseNames(): void
    {
        config::overrideValue('sql_databases', 'accounts, inventory');
        config::overrideValue('sql_database_accounts_engine', 'sqlite');
        config::overrideValue('sql_database_accounts_dsn', 'sqlite:/tmp/a.db');
        config::overrideValue('sql_database_inventory_engine', 'sqlite');
        config::overrideValue('sql_database_inventory_dsn', 'sqlite:/tmp/b.db');

        $aAll = (new DatabaseRegistry())->all();

        $this->assertCount(2, $aAll);
        $this->assertArrayHasKey('accounts', $aAll);
        $this->assertArrayHasKey('inventory', $aAll);
    }

    public function testUnknownEngineThrows(): void
    {
        config::overrideValue('sql_databases', 'accounts');
        config::overrideValue('sql_database_accounts_engine', 'oracle');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/unknown or missing engine/');
        (new DatabaseRegistry())->get('accounts');
    }

    public function testMissingEngineThrows(): void
    {
        config::overrideValue('sql_databases', 'accounts');
        config::overrideValue('sql_database_accounts_engine', '');

        $this->expectException(\RuntimeException::class);
        (new DatabaseRegistry())->get('accounts');
    }

    public function testMissingDsnThrows(): void
    {
        config::overrideValue('sql_databases', 'accounts');
        config::overrideValue('sql_database_accounts_engine', 'sqlite');
        config::overrideValue('sql_database_accounts_dsn', '');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/no DSN configured/');
        (new DatabaseRegistry())->get('accounts');
    }

    public function testMissingExtensionThrowsWithClearMessage(): void
    {
        config::overrideValue('sql_databases', 'accounts');
        config::overrideValue('sql_database_accounts_engine', 'pgsql');
        config::overrideValue('sql_database_accounts_dsn', 'pgsql:host=localhost;dbname=accounts');

        $oRegistry = new TestableDatabaseRegistry();
        $oRegistry->aMissingExtensions = ['pdo_pgsql'];

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/pdo_pgsql/');
        $oRegistry->get('accounts');
    }

    public function testAllowedUsersGating(): void
    {
        config::overrideValue('sql_databases', 'accounts');
        config::overrideValue('sql_database_accounts_engine', 'sqlite');
        config::overrideValue('sql_database_accounts_dsn', 'sqlite:/tmp/a.db');
        config::overrideValue('sql_database_accounts_allowed_users', 'john, JANE');

        $oDb = (new DatabaseRegistry())->get('accounts');

        $this->assertTrue($oDb->isUserAllowed('JOHN'));
        $this->assertTrue($oDb->isUserAllowed('jane'));
        $this->assertFalse($oDb->isUserAllowed('EVE'));
    }
}
