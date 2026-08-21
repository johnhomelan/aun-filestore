<?php

/*
 * @group unit-tests
 *
 * Tests for Dns\DomainFilter - restricts which query names Forwarder is allowed to send
 * upstream, from a single comma-separated list mixing forward and reverse domains.
 */

include_once(__DIR__ . '/../../src/include/system.inc.php');

use PHPUnit\Framework\TestCase;
use HomeLan\FileStore\Dns\DomainFilter;

class DomainFilterTest extends TestCase
{
    public function testEmptyListAllowsAnything(): void
    {
        $oFilter = new DomainFilter('');
        $this->assertTrue($oFilter->isAllowed('anything.example.org'));
        $this->assertTrue($oFilter->isAllowed('5.0.168.192.in-addr.arpa'));
    }

    public function testExactMatchIsAllowed(): void
    {
        $oFilter = new DomainFilter('example.com');
        $this->assertTrue($oFilter->isAllowed('example.com'));
    }

    public function testSubdomainOfAnAllowedDomainIsAllowed(): void
    {
        $oFilter = new DomainFilter('example.com');
        $this->assertTrue($oFilter->isAllowed('fileserver.example.com'));
    }

    public function testUnrelatedDomainIsNotAllowed(): void
    {
        $oFilter = new DomainFilter('example.com');
        $this->assertFalse($oFilter->isAllowed('example.org'));
    }

    public function testDomainThatMerelySharesASuffixIsNotAllowed(): void
    {
        // "notexample.com" must not match the allowed domain "example.com".
        $oFilter = new DomainFilter('example.com');
        $this->assertFalse($oFilter->isAllowed('notexample.com'));
    }

    public function testReverseDomainInTheListIsAllowed(): void
    {
        $oFilter = new DomainFilter('168.192.in-addr.arpa');
        $this->assertTrue($oFilter->isAllowed('5.0.168.192.in-addr.arpa'));
    }

    public function testMultipleDomainsAreAllComparedAgainst(): void
    {
        $oFilter = new DomainFilter('example.com,168.192.in-addr.arpa');
        $this->assertTrue($oFilter->isAllowed('fileserver.example.com'));
        $this->assertTrue($oFilter->isAllowed('5.0.168.192.in-addr.arpa'));
        $this->assertFalse($oFilter->isAllowed('example.org'));
    }

    public function testMatchingIsCaseInsensitive(): void
    {
        $oFilter = new DomainFilter('Example.COM');
        $this->assertTrue($oFilter->isAllowed('FileServer.example.com'));
    }

    public function testTrailingDotsAreIgnoredOnBothSides(): void
    {
        $oFilter = new DomainFilter('example.com.');
        $this->assertTrue($oFilter->isAllowed('fileserver.example.com.'));
    }

    public function testWhitespaceAroundEntriesIsTrimmed(): void
    {
        $oFilter = new DomainFilter(' example.com , 168.192.in-addr.arpa ');
        $this->assertTrue($oFilter->isAllowed('example.com'));
        $this->assertTrue($oFilter->isAllowed('5.0.168.192.in-addr.arpa'));
    }

    public function testEmptyEntriesInTheListAreIgnored(): void
    {
        $oFilter = new DomainFilter('example.com,,other.net');
        $this->assertTrue($oFilter->isAllowed('example.com'));
        $this->assertTrue($oFilter->isAllowed('other.net'));
    }
}
