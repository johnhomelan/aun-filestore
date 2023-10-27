<?php

/*
 * @group unit-tests
*/

include_once(__DIR__.'/../../src/include/system.inc.php');

use PHPUnit\Framework\TestCase;
use Monolog\Logger;
use Monolog\Handler\NullHandler;
use HomeLan\FileStore\Services\Provider\IPv4\NAT;
use HomeLan\FileStore\Services\Provider\IPv4;

class natTest extends TestCase {

	private Routes $oRoutes;

	protected function setup(): void
	{
		$oProvider = $this->createMock(IPv4::class);
		$sNatFile = "192.168.1.1 192.168.0.1 200 23\n192.168.1.2 192.168.0.2 23 23\n192.168.1.3 192.168.0.3 200 1024";

		$oLogger = new Logger("filestored-unittests");
		$oLogger->pushHandler(new NullHandler());
		
		$this->oNAT = new NAT($oProvider,$oLogger,$sNatFile);
	}

	public function testAllValidRoutesRead()
	{
		$this->assertEquals(3,count($this->oNAT->dumpNatTable()));
	}

	public function testIsNAT()
	{
		$this->assertTrue($this->oNAT->isNatTarget('192.168.1.1'));
		$this->assertTrue($this->oNAT->isNatTarget('192.168.1.2'));
		$this->assertTrue($this->oNAT->isNatTarget('192.168.1.3'));

		$this->assertFalse($this->oNAT->isNatTarget('192.168.1.4'));


	}
}
