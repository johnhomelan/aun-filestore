<?php

/*
 * @group unit-tests
 *
 * Tests for HomeLan\FileStore\Aun\Admin.
 *
 * Uses Aun\Map static state (reset in setUp/tearDown) to feed data to the admin class.
 */

include_once(__DIR__ . '/../../src/include/system.inc.php');

use PHPUnit\Framework\TestCase;
use Monolog\Logger;
use Monolog\Handler\NullHandler;
use HomeLan\FileStore\Aun\Admin as AunAdmin;
use HomeLan\FileStore\Aun\Map;
use HomeLan\FileStore\Aun\HandleInterface;
use HomeLan\FileStore\Encapsulation\EncapsulationAdminInterface;
use HomeLan\FileStore\Services\Provider\AdminEntity;

class AunAdminTest extends TestCase
{
    private AunAdmin $oAdmin;
    private Logger $oLogger;

    protected function setUp(): void
    {
        $this->oLogger = new Logger('test');
        $this->oLogger->pushHandler(new NullHandler());

        // Reset all static state
        Map::$aHostMap       = [];
        Map::$aSubnetMap     = [];
        Map::$aIPLookupCache = [];

        $this->oAdmin = new AunAdmin();
    }

    protected function tearDown(): void
    {
        Map::$aHostMap       = [];
        Map::$aSubnetMap     = [];
        Map::$aIPLookupCache = [];
    }

    // -----------------------------------------------------------------------
    // Interface contract
    // -----------------------------------------------------------------------

    public function testImplementsEncapsulationAdminInterface(): void
    {
        $this->assertInstanceOf(EncapsulationAdminInterface::class, $this->oAdmin);
    }

    // -----------------------------------------------------------------------
    // getId / getName / getDescription
    // -----------------------------------------------------------------------

    public function testGetIdReturnsAun(): void
    {
        $this->assertSame('aun', $this->oAdmin->getId());
    }

    public function testGetNameIsNonEmpty(): void
    {
        $this->assertNotEmpty($this->oAdmin->getName());
    }

    public function testGetDescriptionIsNonEmpty(): void
    {
        $this->assertNotEmpty($this->oAdmin->getDescription());
    }

    // -----------------------------------------------------------------------
    // getStatus
    // -----------------------------------------------------------------------

    public function testGetStatusWithNoMappingsShowsZeroCounts(): void
    {
        $sStatus = $this->oAdmin->getStatus();
        $this->assertStringContainsString('0', $sStatus);
    }

    public function testGetStatusReflectsHostMappingCount(): void
    {
        Map::$aHostMap['1.5'] = '192.168.0.5';
        Map::$aHostMap['1.6'] = '192.168.0.6';
        $sStatus = $this->oAdmin->getStatus();
        $this->assertStringContainsString('2', $sStatus);
        $this->assertStringContainsString('host', $sStatus);
    }

    public function testGetStatusReflectsSubnetMappingCount(): void
    {
        Map::$aSubnetMap[3] = '192.168.3.0/24';
        $sStatus = $this->oAdmin->getStatus();
        $this->assertStringContainsString('1', $sStatus);
        $this->assertStringContainsString('subnet', $sStatus);
    }

    // -----------------------------------------------------------------------
    // getEntityTypes
    // -----------------------------------------------------------------------

    public function testGetEntityTypesHasHostKey(): void
    {
        $this->assertArrayHasKey('host', $this->oAdmin->getEntityTypes());
    }

    public function testGetEntityTypesHasSubnetKey(): void
    {
        $this->assertArrayHasKey('subnet', $this->oAdmin->getEntityTypes());
    }

    // -----------------------------------------------------------------------
    // getEntityFields
    // -----------------------------------------------------------------------

    public function testGetEntityFieldsHostContainsNetworkStationIp(): void
    {
        $aFields = $this->oAdmin->getEntityFields('host');
        $this->assertArrayHasKey('network', $aFields);
        $this->assertArrayHasKey('station', $aFields);
        $this->assertArrayHasKey('ip', $aFields);
    }

    public function testGetEntityFieldsSubnetContainsNetworkAndSubnet(): void
    {
        $aFields = $this->oAdmin->getEntityFields('subnet');
        $this->assertArrayHasKey('network', $aFields);
        $this->assertArrayHasKey('subnet', $aFields);
    }

    public function testGetEntityFieldsUnknownReturnsEmpty(): void
    {
        $this->assertSame([], $this->oAdmin->getEntityFields('unknown'));
    }

    // -----------------------------------------------------------------------
    // getEntities — host
    // -----------------------------------------------------------------------

    public function testGetEntitiesHostReturnsEmptyWithNoMappings(): void
    {
        $this->assertSame([], $this->oAdmin->getEntities('host'));
    }

    public function testGetEntitiesHostReturnsOneEntityPerHostMapping(): void
    {
        Map::$aHostMap['1.5']  = '192.168.0.5';
        Map::$aHostMap['1.10'] = '192.168.0.10';
        $aEntities = $this->oAdmin->getEntities('host');
        $this->assertCount(2, $aEntities);
        $this->assertInstanceOf(AdminEntity::class, $aEntities[0]);
    }

    public function testGetEntitiesHostEntityHasCorrectNetworkAndStation(): void
    {
        Map::$aHostMap['2.7'] = '10.0.0.7';
        $oEntity = $this->oAdmin->getEntities('host')[0];
        $this->assertSame(2, $oEntity->getValue('network'));
        $this->assertSame(7, $oEntity->getValue('station'));
    }

    public function testGetEntitiesHostEntityHasCorrectIp(): void
    {
        Map::$aHostMap['1.3'] = '192.168.1.3';
        $oEntity = $this->oAdmin->getEntities('host')[0];
        $this->assertSame('192.168.1.3', $oEntity->getValue('ip'));
    }

    public function testGetEntitiesHostEntityPreservesIpWithPort(): void
    {
        Map::$aHostMap['1.20'] = '192.168.0.20:5000';
        $oEntity = $this->oAdmin->getEntities('host')[0];
        $this->assertSame('192.168.0.20:5000', $oEntity->getValue('ip'));
    }

    public function testGetEntitiesHostUsesIpAsIdField(): void
    {
        Map::$aHostMap['1.5'] = '10.0.0.5';
        $oEntity = $this->oAdmin->getEntities('host')[0];
        $this->assertSame('10.0.0.5', $oEntity->getId());
    }

    public function testGetEntitiesHostEntityTypeIsHost(): void
    {
        Map::$aHostMap['1.1'] = '192.168.0.1';
        $this->assertSame('host', $this->oAdmin->getEntities('host')[0]->getType());
    }

    // -----------------------------------------------------------------------
    // getEntities — subnet
    // -----------------------------------------------------------------------

    public function testGetEntitiesSubnetReturnsEmptyWithNoMappings(): void
    {
        $this->assertSame([], $this->oAdmin->getEntities('subnet'));
    }

    public function testGetEntitiesSubnetReturnsOneEntityPerSubnetMapping(): void
    {
        Map::$aSubnetMap[5] = '10.5.0.0/24';
        Map::$aSubnetMap[6] = '10.6.0.0/24';
        $this->assertCount(2, $this->oAdmin->getEntities('subnet'));
    }

    public function testGetEntitiesSubnetEntityHasCorrectNetworkAndSubnet(): void
    {
        Map::$aSubnetMap[7] = '172.16.7.0/24';
        $oEntity = $this->oAdmin->getEntities('subnet')[0];
        $this->assertSame(7, $oEntity->getValue('network'));
        $this->assertSame('172.16.7.0/24', $oEntity->getValue('subnet'));
    }

    public function testGetEntitiesSubnetUsesNetworkAsIdField(): void
    {
        Map::$aSubnetMap[9] = '192.168.9.0/24';
        $oEntity = $this->oAdmin->getEntities('subnet')[0];
        $this->assertSame(9, $oEntity->getId());
    }

    // -----------------------------------------------------------------------
    // getEntities — unknown type
    // -----------------------------------------------------------------------

    public function testGetEntitiesUnknownTypeReturnsEmpty(): void
    {
        $this->assertSame([], $this->oAdmin->getEntities('unknown'));
    }

    // -----------------------------------------------------------------------
    // Map::getHostMappings / getSubnetMappings directly
    // -----------------------------------------------------------------------

    public function testGetHostMappingsReturnsEmptyArrayWhenMapEmpty(): void
    {
        $this->assertSame([], Map::getHostMappings());
    }

    public function testGetSubnetMappingsReturnsEmptyArrayWhenMapEmpty(): void
    {
        $this->assertSame([], Map::getSubnetMappings());
    }

    public function testGetHostMappingsReturnsCorrectStructure(): void
    {
        Map::$aHostMap['3.15'] = '192.168.3.15';
        $aRows = Map::getHostMappings();
        $this->assertCount(1, $aRows);
        $this->assertSame(3, $aRows[0]['network']);
        $this->assertSame(15, $aRows[0]['station']);
        $this->assertSame('192.168.3.15', $aRows[0]['ip']);
    }

    public function testGetSubnetMappingsReturnsCorrectStructure(): void
    {
        Map::$aSubnetMap[11] = '10.11.0.0/24';
        $aRows = Map::getSubnetMappings();
        $this->assertCount(1, $aRows);
        $this->assertSame(11, $aRows[0]['network']);
        $this->assertSame('10.11.0.0/24', $aRows[0]['subnet']);
    }
}
