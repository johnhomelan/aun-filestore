<?php

/*
 * @group unit-tests
 *
 * Tests for HomeLan\FileStore\Piconet\Admin.
 *
 * Uses Piconet\Map static state (reset between tests) to feed data.
 */

include_once(__DIR__ . '/../../src/include/system.inc.php');

use PHPUnit\Framework\TestCase;
use HomeLan\FileStore\Piconet\Admin as PiconetAdmin;
use HomeLan\FileStore\Piconet\Map;
use HomeLan\FileStore\Encapsulation\EncapsulationAdminInterface;
use HomeLan\FileStore\Services\Provider\AdminEntity;

class PiconetAdminTest extends TestCase
{
    private PiconetAdmin $oAdmin;

    protected function setUp(): void
    {
        $rp = new \ReflectionProperty(Map::class, 'aNetworks');
        $rp->setAccessible(true);
        $rp->setValue(null, []);

        $this->oAdmin = new PiconetAdmin();
    }

    protected function tearDown(): void
    {
        $rp = new \ReflectionProperty(Map::class, 'aNetworks');
        $rp->setAccessible(true);
        $rp->setValue(null, []);
    }

    // -----------------------------------------------------------------------
    // Interface
    // -----------------------------------------------------------------------

    public function testImplementsEncapsulationAdminInterface(): void
    {
        $this->assertInstanceOf(EncapsulationAdminInterface::class, $this->oAdmin);
    }

    // -----------------------------------------------------------------------
    // getId / getName / getDescription
    // -----------------------------------------------------------------------

    public function testGetIdReturnsPiconet(): void
    {
        $this->assertSame('piconet', $this->oAdmin->getId());
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

    public function testGetStatusWithNoNetworksShowsZero(): void
    {
        $sStatus = $this->oAdmin->getStatus();
        $this->assertStringContainsString('0', $sStatus);
        $this->assertStringContainsString('network', $sStatus);
    }

    public function testGetStatusReflectsRegisteredNetworkCount(): void
    {
        Map::addNetwork(5);
        Map::addNetwork(6);
        $sStatus = $this->oAdmin->getStatus();
        $this->assertStringContainsString('2', $sStatus);
    }

    // -----------------------------------------------------------------------
    // getEntityTypes
    // -----------------------------------------------------------------------

    public function testGetEntityTypesHasNetworkKey(): void
    {
        $this->assertArrayHasKey('network', $this->oAdmin->getEntityTypes());
    }

    // -----------------------------------------------------------------------
    // getEntityFields
    // -----------------------------------------------------------------------

    public function testGetEntityFieldsNetworkHasNetworkKey(): void
    {
        $aFields = $this->oAdmin->getEntityFields('network');
        $this->assertArrayHasKey('network', $aFields);
    }

    public function testGetEntityFieldsUnknownReturnsEmpty(): void
    {
        $this->assertSame([], $this->oAdmin->getEntityFields('unknown'));
    }

    // -----------------------------------------------------------------------
    // getEntities — network
    // -----------------------------------------------------------------------

    public function testGetEntitiesNetworkReturnsEmptyWhenNoNetworksRegistered(): void
    {
        $this->assertSame([], $this->oAdmin->getEntities('network'));
    }

    public function testGetEntitiesNetworkReturnsOneEntityPerNetwork(): void
    {
        Map::addNetwork(3);
        Map::addNetwork(7);
        $aEntities = $this->oAdmin->getEntities('network');
        $this->assertCount(2, $aEntities);
        $this->assertInstanceOf(AdminEntity::class, $aEntities[0]);
    }

    public function testGetEntitiesNetworkEntityHasCorrectNetworkValue(): void
    {
        Map::addNetwork(9);
        $oEntity = $this->oAdmin->getEntities('network')[0];
        $this->assertSame(9, $oEntity->getValue('network'));
    }

    public function testGetEntitiesNetworkUsesNetworkAsIdField(): void
    {
        Map::addNetwork(4);
        $oEntity = $this->oAdmin->getEntities('network')[0];
        $this->assertSame(4, $oEntity->getId());
    }

    public function testGetEntitiesNetworkEntityTypeIsNetwork(): void
    {
        Map::addNetwork(1);
        $this->assertSame('network', $this->oAdmin->getEntities('network')[0]->getType());
    }

    public function testGetEntitiesNetworkDeduplicatesNetworks(): void
    {
        Map::addNetwork(5);
        Map::addNetwork(5); // duplicate — Map::addNetwork guards against this
        $this->assertCount(1, $this->oAdmin->getEntities('network'));
    }

    // -----------------------------------------------------------------------
    // getEntities — unknown type
    // -----------------------------------------------------------------------

    public function testGetEntitiesUnknownTypeReturnsEmpty(): void
    {
        $this->assertSame([], $this->oAdmin->getEntities('unknown'));
    }

    // -----------------------------------------------------------------------
    // Map::getNetworks directly
    // -----------------------------------------------------------------------

    public function testGetNetworksReturnsEmptyArrayByDefault(): void
    {
        $this->assertSame([], Map::getNetworks());
    }

    public function testGetNetworksReturnsRowsWithNetworkKey(): void
    {
        Map::addNetwork(10);
        $aRows = Map::getNetworks();
        $this->assertCount(1, $aRows);
        $this->assertSame(10, $aRows[0]['network']);
    }

    public function testGetNetworksReturnsAllRegisteredNetworks(): void
    {
        Map::addNetwork(1);
        Map::addNetwork(2);
        Map::addNetwork(3);
        $this->assertCount(3, Map::getNetworks());
    }
}
