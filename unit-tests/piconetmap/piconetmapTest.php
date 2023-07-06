<?php

/*
 * @group unit-tests
*/

include_once(__DIR__.'/../../src/include/system.inc.php');

use PHPUnit\Framework\TestCase;
use Monolog\Logger;
use HomeLan\FileStore\Piconet\Map as PiconetMap;
use HomeLan\FileStore\Piconet\Handler;

class piconetmapTest extends TestCase {

	protected function setup(): void
	{
		$oLogger = new Logger("filestored-unittests");
		$oHandler = $this->createMock(Handler::class);
		$sMapFile = "1\n2\n3\n27\n129";
		PiconetMap::init($oLogger,$oHandler,$sMapFile);
	}

	public function testnetworkKnown()
	{
		$this->assertTrue(PiconetMap::networkKnown(1));
		$this->assertTrue(PiconetMap::networkKnown(2));
		$this->assertTrue(PiconetMap::networkKnown(3));
		$this->assertTrue(PiconetMap::networkKnown(27));
		$this->assertTrue(PiconetMap::networkKnown(129));
		$this->assertNotTrue(PiconetMap::networkKnown(4));
		$this->assertNotTrue(PiconetMap::networkKnown(100));
		$this->assertNotTrue(PiconetMap::networkKnown(20));
		
	}

	public function testecoAddrToHandler()
	{
		$this->assertNull(PiconetMap::ecoAddrToHandler(4,1));
		$this->assertNull(PiconetMap::ecoAddrToHandler(4,2));
		$this->assertNull(PiconetMap::ecoAddrToHandler(5,1));

		$this->assertIsObject(PiconetMap::ecoAddrToHandler(3,1));
		$this->assertIsObject(PiconetMap::ecoAddrToHandler(3,2));
		$this->assertIsObject(PiconetMap::ecoAddrToHandler(1,1));
		$this->assertIsObject(PiconetMap::ecoAddrToHandler(1,2));
		$this->assertIsObject(PiconetMap::ecoAddrToHandler(2,1));
		$this->assertIsObject(PiconetMap::ecoAddrToHandler(129,1));
		$this->assertIsObject(PiconetMap::ecoAddrToHandler(27,1));

	}

}
