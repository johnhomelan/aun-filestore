<?php

/*
 * @group unit-tests
 *
 * Tests for HomeLan\FileStore\Services\Provider\AdminEntity.
 */

include_once(__DIR__ . '/../../src/include/system.inc.php');

use PHPUnit\Framework\TestCase;
use HomeLan\FileStore\Services\Provider\AdminEntity;

class AdminEntityTest extends TestCase
{
    private array $aFields = ['network' => 'int', 'station' => 'int', 'name' => 'string'];

    // -----------------------------------------------------------------------
    // getType()
    // -----------------------------------------------------------------------

    public function testGetTypeReturnsTypePassedToConstructor(): void
    {
        $oEntity = new AdminEntity('session', $this->aFields, ['network' => 1, 'station' => 2, 'name' => 'user']);
        $this->assertSame('session', $oEntity->getType());
    }

    // -----------------------------------------------------------------------
    // getFields()
    // -----------------------------------------------------------------------

    public function testGetFieldsReturnsFieldsArray(): void
    {
        $oEntity = new AdminEntity('session', $this->aFields, []);
        $this->assertSame($this->aFields, $oEntity->getFields());
    }

    // -----------------------------------------------------------------------
    // getValue()
    // -----------------------------------------------------------------------

    public function testGetValueReturnsCorrectFieldValue(): void
    {
        $oEntity = new AdminEntity('session', $this->aFields, ['network' => 3, 'station' => 7, 'name' => 'test']);
        $this->assertSame(3, $oEntity->getValue('network'));
        $this->assertSame(7, $oEntity->getValue('station'));
        $this->assertSame('test', $oEntity->getValue('name'));
    }

    public function testGetValueReturnsNullForMissingField(): void
    {
        $oEntity = new AdminEntity('session', $this->aFields, ['network' => 1]);
        $this->assertNull($oEntity->getValue('nonexistent'));
    }

    // -----------------------------------------------------------------------
    // getId() — string field key
    // -----------------------------------------------------------------------

    public function testGetIdWithIdFieldReturnsFieldValue(): void
    {
        $oEntity = new AdminEntity('session', $this->aFields, ['network' => 5, 'station' => 10, 'name' => 'foo'], null, 'station');
        $this->assertSame(10, $oEntity->getId());
    }

    public function testGetIdWithIdFieldReturnNullWhenFieldMissing(): void
    {
        $oEntity = new AdminEntity('session', $this->aFields, ['network' => 5], null, 'station');
        $this->assertNull($oEntity->getId());
    }

    // -----------------------------------------------------------------------
    // getId() — callable
    // -----------------------------------------------------------------------

    public function testGetIdWithCallableInvokesCallable(): void
    {
        $fId = fn($aRow) => $aRow['network'] . '_' . $aRow['station'];
        $oEntity = new AdminEntity('session', $this->aFields, ['network' => 2, 'station' => 15, 'name' => 'x'], $fId);
        $this->assertSame('2_15', $oEntity->getId());
    }

    public function testGetIdWithCallableTakesPriorityOverIdField(): void
    {
        $fId = fn($aRow) => 'computed';
        $oEntity = new AdminEntity('session', $this->aFields, ['network' => 1, 'station' => 2, 'name' => 'y'], $fId, 'station');
        $this->assertSame('computed', $oEntity->getId());
    }

    // -----------------------------------------------------------------------
    // createCollection()
    // -----------------------------------------------------------------------

    public function testCreateCollectionReturnsCorrectCount(): void
    {
        $aRows = [
            ['network' => 1, 'station' => 1, 'name' => 'a'],
            ['network' => 1, 'station' => 2, 'name' => 'b'],
            ['network' => 2, 'station' => 1, 'name' => 'c'],
        ];
        $aEntities = AdminEntity::createCollection('session', $this->aFields, $aRows, null, 'station');
        $this->assertCount(3, $aEntities);
    }

    public function testCreateCollectionReturnsAdminEntityInstances(): void
    {
        $aRows = [['network' => 1, 'station' => 5, 'name' => 'test']];
        $aEntities = AdminEntity::createCollection('session', $this->aFields, $aRows, null, 'station');
        $this->assertInstanceOf(AdminEntity::class, $aEntities[0]);
    }

    public function testCreateCollectionEntitiesHaveCorrectValues(): void
    {
        $aRows = [
            ['network' => 3, 'station' => 9, 'name' => 'hello'],
        ];
        $aEntities = AdminEntity::createCollection('session', $this->aFields, $aRows, null, 'network');
        $this->assertSame('session', $aEntities[0]->getType());
        $this->assertSame(3, $aEntities[0]->getValue('network'));
        $this->assertSame(9, $aEntities[0]->getValue('station'));
        $this->assertSame(3, $aEntities[0]->getId());
    }

    public function testCreateCollectionWithCallableId(): void
    {
        $fId = fn($aRow) => $aRow['network'] . '.' . $aRow['station'];
        $aRows = [
            ['network' => 4, 'station' => 12, 'name' => 'z'],
        ];
        $aEntities = AdminEntity::createCollection('session', $this->aFields, $aRows, $fId);
        $this->assertSame('4.12', $aEntities[0]->getId());
    }

    public function testCreateCollectionFromEmptyRowsReturnsEmptyArray(): void
    {
        $aEntities = AdminEntity::createCollection('session', $this->aFields, []);
        $this->assertSame([], $aEntities);
    }
}
