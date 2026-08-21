<?php

/*
 * @group unit-tests
 *
 * Tests for ShareFs ShareList - share list file parsing.
 */

include_once(__DIR__ . '/../../src/include/system.inc.php');

use PHPUnit\Framework\TestCase;
use Monolog\Logger;
use Monolog\Handler\NullHandler;
use HomeLan\FileStore\ShareFs\ShareList;

class ShareListTest extends TestCase
{
    private Logger $oLogger;

    protected function setUp(): void
    {
        $this->oLogger = new Logger('test');
        $this->oLogger->pushHandler(new NullHandler());
        ShareList::reset();
    }

    public function testParsesOpenShare(): void
    {
        ShareList::init($this->oLogger, "SHARE DISC0 \$.DISC0\n");
        $oShare = ShareList::getShare('DISC0');
        $this->assertNotNull($oShare);
        $this->assertSame('$.DISC0', $oShare->getVfsPath());
        $this->assertFalse($oShare->isProtected());
    }

    public function testParsesProtectedShareWithPassword(): void
    {
        ShareList::init($this->oLogger, "SHARE PRIVATE \$.PRIVATE protected secretpw\n");
        $oShare = ShareList::getShare('PRIVATE');
        $this->assertNotNull($oShare);
        $this->assertTrue($oShare->isProtected());
        $this->assertSame('secretpw', $oShare->getPassword());
    }

    public function testParsesMultipleAttributes(): void
    {
        ShareList::init($this->oLogger, "SHARE BACKUP \$.BACKUP protected,readonly backuppw\n");
        $oShare = ShareList::getShare('BACKUP');
        $this->assertTrue($oShare->isProtected());
        $this->assertTrue($oShare->isReadOnly());
        $this->assertSame('backuppw', $oShare->getPassword());
    }

    public function testParsesHiddenShare(): void
    {
        ShareList::init($this->oLogger, "SHARE SPARE \$.SPARE hidden\n");
        $oShare = ShareList::getShare('SPARE');
        $this->assertTrue($oShare->isHidden());
        $this->assertFalse($oShare->isProtected());
    }

    public function testProtectedShareWithoutPasswordIsRejected(): void
    {
        ShareList::init($this->oLogger, "SHARE PRIVATE \$.PRIVATE protected\n");
        $this->assertNull(ShareList::getShare('PRIVATE'));
    }

    public function testShareLookupIsCaseInsensitive(): void
    {
        ShareList::init($this->oLogger, "SHARE disc0 \$.DISC0\n");
        $this->assertNotNull(ShareList::getShare('DISC0'));
        $this->assertNotNull(ShareList::getShare('disc0'));
    }

    public function testIgnoresCommentsAndBlankLines(): void
    {
        ShareList::init($this->oLogger, "# comment\n\nSHARE DISC0 \$.DISC0\n\n# trailing\n");
        $this->assertCount(1, ShareList::getShares());
    }

    public function testSkipsUnrecognisedLines(): void
    {
        ShareList::init($this->oLogger, "NOT A SHARE LINE\nSHARE DISC0 \$.DISC0\n");
        $this->assertCount(1, ShareList::getShares());
    }

    public function testMissingListFileLeavesNoShares(): void
    {
        ShareList::init($this->oLogger, null);
        $this->assertSame([], ShareList::getShares());
    }

    public function testGetAdvertisedSharesExcludesHiddenAndProtected(): void
    {
        $sList = "SHARE DISC0 \$.DISC0\n"
               . "SHARE SPARE \$.SPARE hidden\n"
               . "SHARE PRIVATE \$.PRIVATE protected pw\n";
        ShareList::init($this->oLogger, $sList);
        $aAdvertised = ShareList::getAdvertisedShares();
        $this->assertCount(1, $aAdvertised);
        $this->assertSame('DISC0', $aAdvertised[0]->getName());
    }

    public function testGetProtectedSharesReturnsOnlyProtected(): void
    {
        $sList = "SHARE DISC0 \$.DISC0\n"
               . "SHARE PRIVATE \$.PRIVATE protected pw1\n"
               . "SHARE SECRET \$.SECRET protected pw2\n";
        ShareList::init($this->oLogger, $sList);
        $aProtected = ShareList::getProtectedShares();
        $this->assertCount(2, $aProtected);
    }
}
