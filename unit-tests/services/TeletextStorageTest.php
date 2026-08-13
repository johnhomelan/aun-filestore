<?php

/*
 * @group unit-tests
 *
 * Unit tests for HomeLan\FileStore\Services\Provider\Teletext\Storage.
 *
 * Storage is the one class in the Teletext provider that touches a real
 * filesystem, so — unlike TeletextTest, which mocks Storage entirely —
 * these tests exercise it against a real temporary directory to verify the
 * on-disk layout described in Storage's own docblock.
 */

use PHPUnit\Framework\TestCase;
use HomeLan\FileStore\Services\Provider\Teletext\Storage;

include_once(__DIR__ . '/../../src/include/system.inc.php');

class TeletextStorageTest extends TestCase
{
    protected string $sBaseDir;
    protected Storage $oStorage;

    protected function setUp(): void
    {
        $this->sBaseDir = sys_get_temp_dir() . '/teletext_test_' . uniqid() . '/';
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

    protected function _writePage(string $sChannel, string $sPage, string $sData): void
    {
        $sDir = rtrim($this->sBaseDir, '/') . '/' . $sChannel;
        if (!is_dir($sDir)) {
            mkdir($sDir, 0755, true);
        }
        file_put_contents($sDir . '/' . $sPage . '.dat', $sData);
    }

    // -------------------------------------------------------------------------
    // getPage / pageExists
    // -------------------------------------------------------------------------

    public function testGetPageReturnsNullWhenChannelDoesNotExist(): void
    {
        $this->assertNull($this->oStorage->getPage('1', '100'));
    }

    public function testGetPageReturnsNullWhenPageDoesNotExist(): void
    {
        mkdir(rtrim($this->sBaseDir, '/') . '/1', 0755, true);
        $this->assertNull($this->oStorage->getPage('1', '100'));
    }

    public function testGetPageReturnsStoredContent(): void
    {
        $this->_writePage('1', '100', str_repeat('X', 1024));
        $this->assertSame(str_repeat('X', 1024), $this->oStorage->getPage('1', '100'));
    }

    public function testPageExistsFalseWhenMissing(): void
    {
        $this->assertFalse($this->oStorage->pageExists('1', '100'));
    }

    public function testPageExistsTrueWhenPresent(): void
    {
        $this->_writePage('1', '100', str_repeat('X', 1024));
        $this->assertTrue($this->oStorage->pageExists('1', '100'));
    }

    public function testDifferentChannelsAreIndependent(): void
    {
        $this->_writePage('1', '100', 'channel one');
        $this->assertNull($this->oStorage->getPage('2', '100'));
    }

    // -------------------------------------------------------------------------
    // getChannels
    // -------------------------------------------------------------------------

    public function testGetChannelsReturnsEmptyArrayWhenStoreDoesNotExist(): void
    {
        $this->_deleteDir($this->sBaseDir);
        $this->assertSame([], $this->oStorage->getChannels());
    }

    public function testGetChannelsReturnsEmptyArrayWithNoChannelDirectories(): void
    {
        $this->assertSame([], $this->oStorage->getChannels());
    }

    public function testGetChannelsListsEveryChannelDirectorySorted(): void
    {
        $this->_writePage('3', '100', 'x');
        $this->_writePage('1', '100', 'x');
        $this->_writePage('2', '100', 'x');

        $this->assertSame(['1', '2', '3'], $this->oStorage->getChannels());
    }

    public function testGetChannelsIgnoresPlainFilesInTheBaseDirectory(): void
    {
        file_put_contents(rtrim($this->sBaseDir, '/') . '/README.txt', 'not a channel');
        $this->_writePage('1', '100', 'x');

        $this->assertSame(['1'], $this->oStorage->getChannels());
    }

    // -------------------------------------------------------------------------
    // getPages
    // -------------------------------------------------------------------------

    public function testGetPagesReturnsEmptyArrayWhenChannelDoesNotExist(): void
    {
        $this->assertSame([], $this->oStorage->getPages('1'));
    }

    public function testGetPagesListsEveryPageSorted(): void
    {
        $this->_writePage('1', '300', 'x');
        $this->_writePage('1', '100', 'x');
        $this->_writePage('1', '200', 'x');

        $this->assertSame(['100', '200', '300'], $this->oStorage->getPages('1'));
    }

    public function testGetPagesOnlyCountsDatFiles(): void
    {
        $this->_writePage('1', '100', 'x');
        file_put_contents(rtrim($this->sBaseDir, '/') . '/1/notes.txt', 'not a page');

        $this->assertSame(['100'], $this->oStorage->getPages('1'));
    }

    public function testGetPagesIsIndependentPerChannel(): void
    {
        $this->_writePage('1', '100', 'x');
        $this->_writePage('2', '200', 'x');

        $this->assertSame(['100'], $this->oStorage->getPages('1'));
        $this->assertSame(['200'], $this->oStorage->getPages('2'));
    }

    // -------------------------------------------------------------------------
    // Subpages
    // -------------------------------------------------------------------------

    protected function _writeSubpage(string $sChannel, string $sPage, int $iSubpage, string $sData): void
    {
        $sDir = rtrim($this->sBaseDir, '/') . '/' . $sChannel;
        if (!is_dir($sDir)) {
            mkdir($sDir, 0755, true);
        }
        file_put_contents($sDir . '/' . $sPage . '_' . $iSubpage . '.dat', $sData);
    }

    public function testGetPageWithNoSubpageArgumentDefaultsToSubpage1(): void
    {
        $this->_writePage('1', '100', 'subpage one');
        $this->assertSame('subpage one', $this->oStorage->getPage('1', '100'));
    }

    public function testGetPageWithExplicitSubpage1UsesThePlainFile(): void
    {
        $this->_writePage('1', '100', 'subpage one');
        $this->assertSame('subpage one', $this->oStorage->getPage('1', '100', 1));
    }

    public function testGetPageWithSubpage2UsesTheSuffixedFile(): void
    {
        $this->_writePage('1', '100', 'subpage one');
        $this->_writeSubpage('1', '100', 2, 'subpage two');

        $this->assertSame('subpage two', $this->oStorage->getPage('1', '100', 2));
    }

    public function testGetPageReturnsNullForAMissingSubpage(): void
    {
        $this->_writePage('1', '100', 'subpage one');
        $this->assertNull($this->oStorage->getPage('1', '100', 2));
    }

    public function testPageExistsForASpecificSubpage(): void
    {
        $this->_writeSubpage('1', '100', 3, 'x');
        $this->assertTrue($this->oStorage->pageExists('1', '100', 3));
        $this->assertFalse($this->oStorage->pageExists('1', '100', 2));
    }

    public function testGetPagesTreatsAllSubpagesOfAPageAsOnePage(): void
    {
        $this->_writePage('1', '100', 'x');
        $this->_writeSubpage('1', '100', 2, 'x');
        $this->_writeSubpage('1', '100', 3, 'x');

        $this->assertSame(['100'], $this->oStorage->getPages('1'));
    }

    public function testGetPagesIncludesAPageThatOnlyHasSubpagesAboveOne(): void
    {
        $this->_writeSubpage('1', '100', 2, 'x');
        $this->assertSame(['100'], $this->oStorage->getPages('1'));
    }

    public function testGetSubpagesReturnsEmptyArrayWhenPageDoesNotExist(): void
    {
        $this->assertSame([], $this->oStorage->getSubpages('1', '100'));
    }

    public function testGetSubpagesReturnsJustOneForASingleSubpagePage(): void
    {
        $this->_writePage('1', '100', 'x');
        $this->assertSame([1], $this->oStorage->getSubpages('1', '100'));
    }

    public function testGetSubpagesListsEverySubpageSortedNumerically(): void
    {
        $this->_writePage('1', '100', 'x');
        $this->_writeSubpage('1', '100', 3, 'x');
        $this->_writeSubpage('1', '100', 2, 'x');
        $this->_writeSubpage('1', '100', 10, 'x');

        $this->assertSame([1, 2, 3, 10], $this->oStorage->getSubpages('1', '100'));
    }

    public function testGetSubpagesIsIndependentPerPage(): void
    {
        $this->_writePage('1', '100', 'x');
        $this->_writeSubpage('1', '100', 2, 'x');
        $this->_writePage('1', '101', 'x');

        $this->assertSame([1, 2], $this->oStorage->getSubpages('1', '100'));
        $this->assertSame([1], $this->oStorage->getSubpages('1', '101'));
    }
}
