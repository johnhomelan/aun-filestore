<?php

/*
 * @group unit-tests
 *
 * Tests for ShareFs\Share.
 */

include_once(__DIR__ . '/../../src/include/system.inc.php');

use PHPUnit\Framework\TestCase;
use HomeLan\FileStore\ShareFs\Share;

class ShareTest extends TestCase
{
    public function testOpenShareIsAdvertised(): void
    {
        $oShare = new Share('DISC0', '$.DISC0');
        $this->assertTrue($oShare->isAdvertised());
        $this->assertFalse($oShare->isProtected());
        $this->assertFalse($oShare->isReadOnly());
        $this->assertFalse($oShare->isHidden());
    }

    public function testHiddenShareIsNotAdvertised(): void
    {
        $oShare = new Share('SPARE', '$.SPARE', bHidden: true);
        $this->assertFalse($oShare->isAdvertised());
    }

    public function testProtectedShareIsNotAdvertised(): void
    {
        $oShare = new Share('PRIVATE', '$.PRIVATE', bProtected: true, sPassword: 'secret');
        $this->assertFalse($oShare->isAdvertised());
        $this->assertSame('secret', $oShare->getPassword());
    }

    public function testReadOnlyDoesNotAffectAdvertising(): void
    {
        $oShare = new Share('ARCHIVE', '$.ARCHIVE', bReadOnly: true);
        $this->assertTrue($oShare->isAdvertised());
        $this->assertTrue($oShare->isReadOnly());
    }
}
