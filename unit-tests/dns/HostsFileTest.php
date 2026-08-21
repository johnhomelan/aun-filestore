<?php

/*
 * @group unit-tests
 *
 * Tests for Dns\HostsFile - parses a Unix-style hosts file and answers lookups for it.
 * IPv4 only - dnsd is IPv4-only throughout (see docs/protocols/dns.md), so an IPv6 line in the
 * hosts file is rejected the same way any other malformed line is.
 */

include_once(__DIR__ . '/../../src/include/system.inc.php');

use PHPUnit\Framework\TestCase;
use Monolog\Logger;
use Monolog\Handler\NullHandler;
use HomeLan\FileStore\Dns\HostsFile;

class HostsFileTest extends TestCase
{
    private Logger $oLogger;

    protected function setUp(): void
    {
        $this->oLogger = new Logger('test');
        $this->oLogger->pushHandler(new NullHandler());
    }

    protected function tearDown(): void
    {
        HostsFile::reset();
    }

    public function testLooksUpAnIpv4Address(): void
    {
        HostsFile::init($this->oLogger, "192.168.0.5 fileserver\n");
        $this->assertSame(['192.168.0.5'], HostsFile::lookup('fileserver'));
    }

    public function testIpv6HostsFileLineIsIgnored(): void
    {
        HostsFile::init($this->oLogger, "fe80::1 fileserver\n");
        $this->assertFalse(HostsFile::isKnownName('fileserver'));
        $this->assertSame([], HostsFile::lookup('fileserver'));
    }

    public function testIpv6LineDoesNotPreventALaterIpv4LineForTheSameName(): void
    {
        HostsFile::init($this->oLogger, "fe80::1 fileserver\n192.168.0.5 fileserver\n");
        $this->assertSame(['192.168.0.5'], HostsFile::lookup('fileserver'));
    }

    public function testLookupIsCaseInsensitive(): void
    {
        HostsFile::init($this->oLogger, "192.168.0.5 FileServer\n");
        $this->assertSame(['192.168.0.5'], HostsFile::lookup('fileserver'));
        $this->assertSame(['192.168.0.5'], HostsFile::lookup('FILESERVER'));
    }

    public function testLookupIgnoresTrailingDot(): void
    {
        HostsFile::init($this->oLogger, "192.168.0.5 fileserver\n");
        $this->assertSame(['192.168.0.5'], HostsFile::lookup('fileserver.'));
    }

    public function testAliasesShareTheSameAddress(): void
    {
        HostsFile::init($this->oLogger, "192.168.0.5 fileserver fs fs.local\n");
        $this->assertSame(['192.168.0.5'], HostsFile::lookup('fs'));
        $this->assertSame(['192.168.0.5'], HostsFile::lookup('fs.local'));
    }

    public function testMultipleLinesForTheSameNameAreAllReturned(): void
    {
        HostsFile::init($this->oLogger, "192.168.0.5 fileserver\n192.168.0.6 fileserver\n");
        $this->assertSame(['192.168.0.5', '192.168.0.6'], HostsFile::lookup('fileserver'));
    }

    public function testCommentsAreIgnored(): void
    {
        HostsFile::init($this->oLogger, "# this is a comment\n192.168.0.5 fileserver # trailing comment\n");
        $this->assertSame(['192.168.0.5'], HostsFile::lookup('fileserver'));
    }

    public function testBlankLinesAreIgnored(): void
    {
        HostsFile::init($this->oLogger, "\n\n192.168.0.5 fileserver\n\n");
        $this->assertSame(['192.168.0.5'], HostsFile::lookup('fileserver'));
    }

    public function testUnknownNameReturnsEmptyList(): void
    {
        HostsFile::init($this->oLogger, "192.168.0.5 fileserver\n");
        $this->assertSame([], HostsFile::lookup('nosuchhost'));
    }

    public function testInvalidIpLineIsIgnored(): void
    {
        HostsFile::init($this->oLogger, "not-an-ip fileserver\n");
        $this->assertSame([], HostsFile::lookup('fileserver'));
    }

    public function testLineWithOnlyAnIpIsIgnored(): void
    {
        HostsFile::init($this->oLogger, "192.168.0.5\n");
        $this->assertFalse(HostsFile::isKnownName('192.168.0.5'));
    }

    public function testIsKnownNameTrueForAConfiguredName(): void
    {
        HostsFile::init($this->oLogger, "192.168.0.5 fileserver\n");
        $this->assertTrue(HostsFile::isKnownName('fileserver'));
    }

    public function testIsKnownNameFalseForAnUnconfiguredName(): void
    {
        HostsFile::init($this->oLogger, "192.168.0.5 fileserver\n");
        $this->assertFalse(HostsFile::isKnownName('nosuchhost'));
    }

    public function testInitFromAMissingFileLeavesNoHostsConfigured(): void
    {
        HostsFile::init($this->oLogger, null);
        $this->assertFalse(HostsFile::isKnownName('fileserver'));
    }

    public function testInitResetsPreviousState(): void
    {
        HostsFile::init($this->oLogger, "192.168.0.5 fileserver\n");
        $this->assertTrue(HostsFile::isKnownName('fileserver'));
        HostsFile::init($this->oLogger, "192.168.0.6 otherhost\n");
        $this->assertFalse(HostsFile::isKnownName('fileserver'));
        $this->assertTrue(HostsFile::isKnownName('otherhost'));
    }

    // -----------------------------------------------------------------------
    // reverseLookup
    // -----------------------------------------------------------------------

    public function testReverseLookupFindsThePrimaryName(): void
    {
        HostsFile::init($this->oLogger, "192.168.0.5 fileserver\n");
        $this->assertSame(['fileserver'], HostsFile::reverseLookup('192.168.0.5'));
    }

    public function testReverseLookupUsesTheFirstNameOnlyNotAliases(): void
    {
        HostsFile::init($this->oLogger, "192.168.0.5 fileserver fs fs.local\n");
        $this->assertSame(['fileserver'], HostsFile::reverseLookup('192.168.0.5'));
    }

    public function testReverseLookupReturnsEveryPrimaryNameForAnIpAppearingOnMultipleLines(): void
    {
        HostsFile::init($this->oLogger, "192.168.0.5 fileserver\n192.168.0.5 backup\n");
        $this->assertSame(['fileserver', 'backup'], HostsFile::reverseLookup('192.168.0.5'));
    }

    public function testReverseLookupForAnUnknownIpReturnsEmptyList(): void
    {
        HostsFile::init($this->oLogger, "192.168.0.5 fileserver\n");
        $this->assertSame([], HostsFile::reverseLookup('192.168.0.99'));
    }

    public function testReverseLookupWithAMalformedIpReturnsEmptyList(): void
    {
        HostsFile::init($this->oLogger, "192.168.0.5 fileserver\n");
        $this->assertSame([], HostsFile::reverseLookup('not-an-ip'));
    }

    public function testReverseLookupForAnIpv6AddressReturnsEmptyList(): void
    {
        // The hosts file line was ignored entirely (IPv4-only), so there's nothing to find.
        HostsFile::init($this->oLogger, "fe80::1 fileserver\n");
        $this->assertSame([], HostsFile::reverseLookup('fe80::1'));
    }
}
