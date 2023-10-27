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
}
