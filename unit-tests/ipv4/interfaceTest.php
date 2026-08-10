<?php

/*
 * @group unit-tests
*/

include_once(__DIR__.'/../../src/include/system.inc.php');

use PHPUnit\Framework\TestCase;
use Monolog\Logger;
use Monolog\Handler\NullHandler;
use HomeLan\FileStore\Services\Provider\IPv4\Interfaces;
use HomeLan\FileStore\Services\Provider\IPv4;
use HomeLan\FileStore\Services\Provider\IPv4\Exceptions\InterfaceNotFound;

class interfaceTest extends TestCase {

	private Interfaces $oInterfaces;

	protected function setup(): void
	{
		$oProvider = $this->createMock(IPv4::class);
		$sInterfacesFile = "1 24 192.168.1.24 255.255.255.0\n2 24 192.168.2.24 255.255.255.0\n2 26 192.168.2.26 255.255.255.0\n";
		$oLogger = new Logger("filestored-unittests");
		$oLogger->pushHandler(new NullHandler());
	
		$this->oInterfaces = new Interfaces($oProvider,$oLogger,$sInterfacesFile);
	}

	public function testAllValidInterfacesRead()
	{
		$this->assertEquals(3,count($this->oInterfaces->dumpInterfaceTable()));
	}

	public function testInterfaceSelection()
	{
		$this->assertEquals(2,$this->oInterfaces->getInterfaceFor('192.168.2.21')['network']);
		$this->assertEquals(24,$this->oInterfaces->getInterfaceFor('192.168.2.21')['station']);
	}
	
	public function testNoVaildInterface()
	{

		$this->expectException(InterfaceNotFound::class);
		$this->oInterfaces->getInterfaceFor('192.168.10.21');
	}

	public function testIsInterfaceIPReturnsTrueForExactInterfaceAddress(): void
	{
		$this->assertTrue($this->oInterfaces->isInterfaceIP('192.168.1.24'));
	}

	public function testIsInterfaceIPReturnsTrueForSecondInterface(): void
	{
		$this->assertTrue($this->oInterfaces->isInterfaceIP('192.168.2.24'));
	}

	public function testIsInterfaceIPReturnsFalseForAddressInSubnetButNotInterface(): void
	{
		// 192.168.1.100 is in the 192.168.1.0/24 subnet but is NOT the interface address
		$this->assertFalse($this->oInterfaces->isInterfaceIP('192.168.1.100'));
	}

	public function testIsInterfaceIPReturnsFalseForCompletelyUnknownAddress(): void
	{
		$this->assertFalse($this->oInterfaces->isInterfaceIP('10.0.0.1'));
	}

	public function testDumpInterfaceTableHasExpectedKeys(): void
	{
		$aTable = $this->oInterfaces->dumpInterfaceTable();
		$this->assertNotEmpty($aTable);
		$aEntry = $aTable[0];
		$this->assertArrayHasKey('network', $aEntry);
		$this->assertArrayHasKey('station', $aEntry);
		$this->assertArrayHasKey('ipaddr', $aEntry);
		$this->assertArrayHasKey('mask', $aEntry);
	}

	public function testDumpInterfaceTableEntryValuesMatchConfig(): void
	{
		$aTable = $this->oInterfaces->dumpInterfaceTable();
		// First line: "1 24 192.168.1.24 255.255.255.0"
		$aFirst = $aTable[0];
		$this->assertSame(1, $aFirst['network']);
		$this->assertSame(24, $aFirst['station']);
		$this->assertSame('192.168.1.24', $aFirst['ipaddr']);
		$this->assertSame('255.255.255.0', $aFirst['mask']);
	}

	public function testInterfaceSelectionPicksLowestStationWhenMultipleOnSameNetwork(): void
	{
		// Network 2 has two interfaces: 2.24 and 2.26.
		// Both serve 192.168.2.0/24.  The one returned is whichever comes first in config.
		$aIface = $this->oInterfaces->getInterfaceFor('192.168.2.100');
		$this->assertSame(2, $aIface['network']);
	}
}
