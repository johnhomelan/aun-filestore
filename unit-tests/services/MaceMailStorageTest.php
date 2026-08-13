<?php

/*
 * @group unit-tests
 *
 * Unit tests for HomeLan\FileStore\Services\Provider\MaceMail\Storage.
 *
 * Storage is the one class in the MaceMail provider that touches a real
 * filesystem, so — unlike MaceMailTest, which mocks Storage entirely —
 * these tests exercise it against a real temporary directory to verify the
 * on-disk layout described in Storage's own docblock.
 */

use PHPUnit\Framework\TestCase;
use HomeLan\FileStore\Services\Provider\MaceMail\Storage;

include_once(__DIR__ . '/../../src/include/system.inc.php');

class MaceMailStorageTest extends TestCase
{
    protected string $sBaseDir;
    protected Storage $oStorage;

    protected function setUp(): void
    {
        $this->sBaseDir = sys_get_temp_dir() . '/macemail_test_' . uniqid() . '/';
        mkdir($this->sBaseDir, 0755, true);
        $this->oStorage = new Storage(rtrim($this->sBaseDir, '/'));
    }

    protected function tearDown(): void
    {
        $this->_deleteDir($this->sBaseDir);
    }

    protected function _deleteDir(string $sDir): void
    {
        if (!is_dir($sDir)) {
            return;
        }
        $oIt = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($sDir, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($oIt as $oFile) {
            $oFile->isDir() ? rmdir($oFile->getRealPath()) : unlink($oFile->getRealPath());
        }
        rmdir($sDir);
    }

    // -------------------------------------------------------------------------
    // Slot registry
    // -------------------------------------------------------------------------

    public function testGetUsernameForSlotReturnsNullWhenUnassigned(): void
    {
        $this->assertNull($this->oStorage->getUsernameForSlot(5));
    }

    public function testAssignSlotThenGetUsernameForSlot(): void
    {
        $this->oStorage->assignSlot(5, 'jsmith');
        $this->assertSame('JSMITH', $this->oStorage->getUsernameForSlot(5));
    }

    public function testAssignSlotPersistsAcrossNewStorageInstance(): void
    {
        $this->oStorage->assignSlot(7, 'awilson');
        $oReloaded = new Storage(rtrim($this->sBaseDir, '/'));
        $this->assertSame('AWILSON', $oReloaded->getUsernameForSlot(7));
    }

    public function testGetSlotForUsernameFindsAssignedSlot(): void
    {
        $this->oStorage->assignSlot(12, 'jsmith');
        $this->assertSame(12, $this->oStorage->getSlotForUsername('JSMITH'));
    }

    public function testGetSlotForUsernameIsCaseInsensitive(): void
    {
        $this->oStorage->assignSlot(12, 'JSMITH');
        $this->assertSame(12, $this->oStorage->getSlotForUsername('jsmith'));
    }

    public function testGetSlotForUsernameReturnsNullWhenNotAssigned(): void
    {
        $this->assertNull($this->oStorage->getSlotForUsername('NOBODY'));
    }

    public function testGetAllSlotsReturnsEmptyArrayInitially(): void
    {
        $this->assertSame([], $this->oStorage->getAllSlots());
    }

    public function testGetAllSlotsReturnsEveryAssignment(): void
    {
        $this->oStorage->assignSlot(1, 'awilson');
        $this->oStorage->assignSlot(2, 'jsmith');
        $this->assertSame([1 => 'AWILSON', 2 => 'JSMITH'], $this->oStorage->getAllSlots());
    }

    public function testReassigningASlotReplacesThePreviousUsername(): void
    {
        $this->oStorage->assignSlot(5, 'awilson');
        $this->oStorage->assignSlot(5, 'jsmith');
        $this->assertSame('JSMITH', $this->oStorage->getUsernameForSlot(5));
    }

    public function testUnassignSlotFreesIt(): void
    {
        $this->oStorage->assignSlot(5, 'jsmith');
        $this->oStorage->unassignSlot(5);
        $this->assertNull($this->oStorage->getUsernameForSlot(5));
    }

    public function testUnassignSlotOnAnUnassignedSlotIsANoOp(): void
    {
        $this->oStorage->unassignSlot(9);
        $this->assertSame([], $this->oStorage->getAllSlots());
    }

    // -------------------------------------------------------------------------
    // Per-user metadata
    // -------------------------------------------------------------------------

    public function testGetUserMetaForNeverSeenUserDefaultsStoreMaskToZero(): void
    {
        $aMeta = $this->oStorage->getUserMeta('JSMITH');
        $this->assertSame(0, $aMeta['store_mask']);
    }

    public function testGetUserMetaForNeverSeenUserDefaultsToTodayForBothDates(): void
    {
        $aMeta = $this->oStorage->getUserMeta('JSMITH');
        $this->assertSame($aMeta['registered'], $aMeta['last_used']);
        $this->assertSame((int) date('j'), $aMeta['registered'][0]);
    }

    public function testTouchLastUsedOnNewUserStampsRegisteredDateToo(): void
    {
        $this->oStorage->touchLastUsed('JSMITH', 15, 6, 26);
        $aMeta = $this->oStorage->getUserMeta('JSMITH');
        $this->assertSame([15, 6, 26], $aMeta['registered']);
        $this->assertSame([15, 6, 26], $aMeta['last_used']);
    }

    public function testTouchLastUsedTwiceKeepsOriginalRegisteredDate(): void
    {
        $this->oStorage->touchLastUsed('JSMITH', 1, 1, 24);
        $this->oStorage->touchLastUsed('JSMITH', 15, 6, 26);
        $aMeta = $this->oStorage->getUserMeta('JSMITH');
        $this->assertSame([1, 1, 24], $aMeta['registered']);
        $this->assertSame([15, 6, 26], $aMeta['last_used']);
    }

    public function testUserMetaIsCaseInsensitiveOnUsername(): void
    {
        $this->oStorage->touchLastUsed('jsmith', 1, 1, 24);
        $aMeta = $this->oStorage->getUserMeta('JSMITH');
        $this->assertSame([1, 1, 24], $aMeta['registered']);
    }

    public function testUserMetaPersistsAcrossNewStorageInstance(): void
    {
        $this->oStorage->touchLastUsed('JSMITH', 1, 1, 24);
        $oReloaded = new Storage(rtrim($this->sBaseDir, '/'));
        $this->assertSame([1, 1, 24], $oReloaded->getUserMeta('JSMITH')['registered']);
    }

    // -------------------------------------------------------------------------
    // Mail counts
    // -------------------------------------------------------------------------

    public function testGetMailCountsForUserWithNoMailAreAllZero(): void
    {
        $aCounts = $this->oStorage->getMailCounts('JSMITH');
        $this->assertSame(
            ['unread_normal' => 0, 'unread_express' => 0, 'read_normal' => 0, 'read_express' => 0],
            $aCounts
        );
    }

    public function testGetMailCountsTalliesIndexEntriesByFlags(): void
    {
        $sDir = rtrim($this->sBaseDir, '/') . '/users/JSMITH/mail';
        mkdir($sDir, 0755, true);
        file_put_contents($sDir . '/index.json', json_encode([
            ['express' => false, 'read' => false],
            ['express' => false, 'read' => false],
            ['express' => false, 'read' => true],
            ['express' => true, 'read' => false],
            ['express' => true, 'read' => true],
            ['express' => true, 'read' => true],
        ]));

        $aCounts = $this->oStorage->getMailCounts('JSMITH');

        $this->assertSame(2, $aCounts['unread_normal']);
        $this->assertSame(1, $aCounts['read_normal']);
        $this->assertSame(1, $aCounts['unread_express']);
        $this->assertSame(2, $aCounts['read_express']);
    }

    // -------------------------------------------------------------------------
    // Mail items
    // -------------------------------------------------------------------------

    public function testAddMailItemStartsIdsAtOne(): void
    {
        $iId = $this->oStorage->addMailItem('JSMITH', ['subject' => 'Hi'], 'Body text');
        $this->assertSame(1, $iId);
    }

    public function testAddMailItemAssignsIncreasingIds(): void
    {
        $iId1 = $this->oStorage->addMailItem('JSMITH', ['subject' => 'One'], 'Body 1');
        $iId2 = $this->oStorage->addMailItem('JSMITH', ['subject' => 'Two'], 'Body 2');
        $this->assertSame(1, $iId1);
        $this->assertSame(2, $iId2);
    }

    public function testAddMailItemMarksItUnreadByDefault(): void
    {
        $this->oStorage->addMailItem('JSMITH', ['subject' => 'Hi'], 'Body');
        $aEntry = $this->oStorage->getMailItem('JSMITH', 1);
        $this->assertFalse($aEntry['read']);
    }

    public function testAddMailItemStoresTheBodySeparately(): void
    {
        $this->oStorage->addMailItem('JSMITH', ['subject' => 'Hi'], 'The message body');
        $this->assertSame('The message body', $this->oStorage->getMailBody('JSMITH', 1));
    }

    public function testGetMailIndexReturnsEmptyArrayForNewUser(): void
    {
        $this->assertSame([], $this->oStorage->getMailIndex('JSMITH'));
    }

    public function testGetMailIndexReturnsAllHeaders(): void
    {
        $this->oStorage->addMailItem('JSMITH', ['subject' => 'One'], 'Body 1');
        $this->oStorage->addMailItem('JSMITH', ['subject' => 'Two'], 'Body 2');
        $aIndex = $this->oStorage->getMailIndex('JSMITH');
        $this->assertCount(2, $aIndex);
        $this->assertSame('One', $aIndex[0]['subject']);
        $this->assertSame('Two', $aIndex[1]['subject']);
    }

    public function testGetMailItemReturnsNullWhenNotFound(): void
    {
        $this->assertNull($this->oStorage->getMailItem('JSMITH', 99));
    }

    public function testGetMailItemFindsTheMatchingEntry(): void
    {
        $this->oStorage->addMailItem('JSMITH', ['subject' => 'One'], 'Body 1');
        $iId2 = $this->oStorage->addMailItem('JSMITH', ['subject' => 'Two'], 'Body 2');
        $aEntry = $this->oStorage->getMailItem('JSMITH', $iId2);
        $this->assertSame('Two', $aEntry['subject']);
    }

    public function testGetMailBodyReturnsEmptyStringWhenMessageDoesNotExist(): void
    {
        $this->assertSame('', $this->oStorage->getMailBody('JSMITH', 99));
    }

    public function testMarkMailReadUpdatesTheEntry(): void
    {
        $iId = $this->oStorage->addMailItem('JSMITH', ['subject' => 'One'], 'Body');
        $this->oStorage->markMailRead('JSMITH', $iId);
        $aEntry = $this->oStorage->getMailItem('JSMITH', $iId);
        $this->assertTrue($aEntry['read']);
    }

    public function testMarkMailReadOnUnknownIdIsANoOp(): void
    {
        $this->oStorage->addMailItem('JSMITH', ['subject' => 'One'], 'Body');
        $this->oStorage->markMailRead('JSMITH', 99);
        $aIndex = $this->oStorage->getMailIndex('JSMITH');
        $this->assertFalse($aIndex[0]['read']);
    }

    public function testDeleteMailItemRemovesItFromTheIndex(): void
    {
        $iId = $this->oStorage->addMailItem('JSMITH', ['subject' => 'One'], 'Body');
        $this->oStorage->deleteMailItem('JSMITH', $iId);
        $this->assertSame([], $this->oStorage->getMailIndex('JSMITH'));
    }

    public function testDeleteMailItemRemovesTheStoredBody(): void
    {
        $iId = $this->oStorage->addMailItem('JSMITH', ['subject' => 'One'], 'Body text');
        $this->oStorage->deleteMailItem('JSMITH', $iId);
        $this->assertSame('', $this->oStorage->getMailBody('JSMITH', $iId));
    }

    public function testDeleteMailItemLeavesOtherMessagesIntact(): void
    {
        $iId1 = $this->oStorage->addMailItem('JSMITH', ['subject' => 'One'], 'Body 1');
        $iId2 = $this->oStorage->addMailItem('JSMITH', ['subject' => 'Two'], 'Body 2');
        $this->oStorage->deleteMailItem('JSMITH', $iId1);
        $this->assertNull($this->oStorage->getMailItem('JSMITH', $iId1));
        $this->assertSame('Two', $this->oStorage->getMailItem('JSMITH', $iId2)['subject']);
    }

    public function testDeleteMailItemOnUnknownIdIsANoOp(): void
    {
        $this->oStorage->addMailItem('JSMITH', ['subject' => 'One'], 'Body');
        $this->oStorage->deleteMailItem('JSMITH', 99);
        $this->assertCount(1, $this->oStorage->getMailIndex('JSMITH'));
    }

    public function testMailItemsPersistAcrossNewStorageInstance(): void
    {
        $this->oStorage->addMailItem('JSMITH', ['subject' => 'One'], 'Body text');
        $oReloaded = new Storage(rtrim($this->sBaseDir, '/'));
        $this->assertSame('Body text', $oReloaded->getMailBody('JSMITH', 1));
    }

    // -------------------------------------------------------------------------
    // Store slots
    // -------------------------------------------------------------------------

    public function testGetStoreSlotReturnsEmptyStringWhenNeverSaved(): void
    {
        $this->assertSame('', $this->oStorage->getStoreSlot('JSMITH', 3));
    }

    public function testSetStoreSlotThenGetStoreSlot(): void
    {
        $this->oStorage->setStoreSlot('JSMITH', 3, 'file contents');
        $this->assertSame('file contents', $this->oStorage->getStoreSlot('JSMITH', 3));
    }

    public function testSetStoreSlotSetsTheBitInTheStoreMask(): void
    {
        $this->oStorage->setStoreSlot('JSMITH', 3, 'data');
        $this->assertSame(1 << 3, $this->oStorage->getUserMeta('JSMITH')['store_mask']);
    }

    public function testSetStoreSlotAccumulatesBitsAcrossSlots(): void
    {
        $this->oStorage->setStoreSlot('JSMITH', 0, 'data');
        $this->oStorage->setStoreSlot('JSMITH', 2, 'data');
        $this->assertSame((1 << 0) | (1 << 2), $this->oStorage->getUserMeta('JSMITH')['store_mask']);
    }

    public function testSetStoreSlotOverwritesPreviousContentsForTheSameSlot(): void
    {
        $this->oStorage->setStoreSlot('JSMITH', 3, 'first');
        $this->oStorage->setStoreSlot('JSMITH', 3, 'second');
        $this->assertSame('second', $this->oStorage->getStoreSlot('JSMITH', 3));
    }

    public function testStoreSlotsAreIndependentPerUser(): void
    {
        $this->oStorage->setStoreSlot('JSMITH', 3, 'jsmith data');
        $this->assertSame('', $this->oStorage->getStoreSlot('AWILSON', 3));
    }

    public function testSetStoreMaskOverwritesTheWholeMask(): void
    {
        $this->oStorage->setStoreSlot('JSMITH', 0, 'data');
        $this->oStorage->setStoreSlot('JSMITH', 1, 'data');
        $this->oStorage->setStoreMask('JSMITH', 0x05);
        $this->assertSame(0x05, $this->oStorage->getUserMeta('JSMITH')['store_mask']);
    }

    public function testSetStoreMaskDoesNotTouchTheUnderlyingSlotData(): void
    {
        $this->oStorage->setStoreSlot('JSMITH', 0, 'still here');
        $this->oStorage->setStoreMask('JSMITH', 0x00);
        $this->assertSame('still here', $this->oStorage->getStoreSlot('JSMITH', 0));
    }

    public function testStoreSlotsPersistAcrossNewStorageInstance(): void
    {
        $this->oStorage->setStoreSlot('JSMITH', 3, 'persisted data');
        $oReloaded = new Storage(rtrim($this->sBaseDir, '/'));
        $this->assertSame('persisted data', $oReloaded->getStoreSlot('JSMITH', 3));
    }
}
