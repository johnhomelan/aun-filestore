<?php

/*
 * @group unit-tests
 *
 * Tests for ShareFs\ShareAuthTable - per (client IP, share) Access+ authentication cache.
 */

include_once(__DIR__ . '/../../src/include/system.inc.php');

use PHPUnit\Framework\TestCase;
use HomeLan\FileStore\ShareFs\ShareAuthTable;

class ShareAuthTableTest extends TestCase
{
    protected function setUp(): void
    {
        ShareAuthTable::reset();
    }

    public function testUnknownPairIsNotAuthenticated(): void
    {
        $this->assertFalse(ShareAuthTable::check('10.0.0.1', 'PRIVATE'));
    }

    public function testAddedPairIsAuthenticated(): void
    {
        ShareAuthTable::add('10.0.0.1', 'PRIVATE');
        $this->assertTrue(ShareAuthTable::check('10.0.0.1', 'PRIVATE'));
    }

    public function testKeyIsCaseInsensitiveOnShareName(): void
    {
        ShareAuthTable::add('10.0.0.1', 'private');
        $this->assertTrue(ShareAuthTable::check('10.0.0.1', 'PRIVATE'));
    }

    public function testDifferentIpIsNotAuthenticated(): void
    {
        ShareAuthTable::add('10.0.0.1', 'PRIVATE');
        $this->assertFalse(ShareAuthTable::check('10.0.0.2', 'PRIVATE'));
    }

    public function testDifferentShareIsNotAuthenticated(): void
    {
        ShareAuthTable::add('10.0.0.1', 'PRIVATE');
        $this->assertFalse(ShareAuthTable::check('10.0.0.1', 'OTHER'));
    }

    public function testExpiredEntryIsNotAuthenticated(): void
    {
        $rp = new \ReflectionProperty(ShareAuthTable::class, 'aEntries');
        $rp->setAccessible(true);
        $rp->setValue(null, ['10.0.0.1|PRIVATE' => time() - 1]);

        $this->assertFalse(ShareAuthTable::check('10.0.0.1', 'PRIVATE'));
    }

    public function testHouseKeepingRemovesExpiredEntries(): void
    {
        $rp = new \ReflectionProperty(ShareAuthTable::class, 'aEntries');
        $rp->setAccessible(true);
        $rp->setValue(null, ['10.0.0.1|PRIVATE' => time() - 1, '10.0.0.2|PRIVATE' => time() + 600]);

        ShareAuthTable::houseKeeping();

        $aEntries = ShareAuthTable::getEntries();
        $this->assertCount(1, $aEntries);
        $this->assertSame('10.0.0.2', $aEntries[0]['ip']);
    }

    public function testGetEntriesReturnsAddedPairs(): void
    {
        ShareAuthTable::add('10.0.0.1', 'PRIVATE');
        $aEntries = ShareAuthTable::getEntries();
        $this->assertCount(1, $aEntries);
        $this->assertSame('10.0.0.1', $aEntries[0]['ip']);
        $this->assertSame('PRIVATE', $aEntries[0]['share']);
    }
}
