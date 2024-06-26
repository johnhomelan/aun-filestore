<?php

/*
 * @group unit-tests
*/

include_once(__DIR__.'/../../src/include/system.inc.php');

use PHPUnit\Framework\TestCase;
use Monolog\Logger;
use HomeLan\FileStore\Aun\Map as aunmap;
use HomeLan\FileStore\Aun\HandleInterface;

class aunmapTest extends TestCase {

	protected function setup(): void
	{
		$oLogger = new Logger("filestored-unittests");
		$sMapFile = "192.168.0.0/24 127\n192.168.0.40 127.254\n192.168.2.20 129.29\n192.168.1.0/24 128\n192.168.0.41\n192.168.2.0/24\n192.168.0.40:1000 127.200\n";
		$oFakeHandler =  Mockery::mock(Handle::class, 'HomeLan\FileStore\Aun\HandleInterface');
		aunmap::init($oLogger,$oFakeHandler,$sMapFile);
	}

	public function testLookUpByIp()
	{
		//Test subnet map
		$this->assertEquals(aunmap::ipAddrToEcoAddr('192.168.0.1'),'127.1');
		$this->assertEquals(aunmap::ipAddrToEcoAddr('192.168.0.2'),'127.2');
		$this->assertEquals(aunmap::ipAddrToEcoAddr('192.168.1.5'),'128.5');
		$this->assertEquals(aunmap::ipAddrToEcoAddr('192.168.1.55'),'128.55');

		//Test host map
		$this->assertEquals(aunmap::ipAddrToEcoAddr('192.168.2.20'),'129.29');
	
		//Test host map overides subnet map
		$this->assertEquals(aunmap::ipAddrToEcoAddr('192.168.0.40'),'127.254');

		//Test host port map overides subnet map and host
		$this->assertEquals(aunmap::ipAddrToEcoAddr('192.168.0.40:1000'),'127.200');
		
	}

	public function testEcoAddrToIpAddr()
	{
		//Test subnet map
		$this->assertEquals(aunmap::ecoAddrToIpAddr('127','1'),'192.168.0.1');
		$this->assertEquals(aunmap::ecoAddrToIpAddr('127','2'),'192.168.0.2');
		$this->assertEquals(aunmap::ecoAddrToIpAddr('128','5'),'192.168.1.5');
		
		//Test host map
		$this->assertEquals(aunmap::ecoAddrToIpAddr('129','29'),'192.168.2.20');
		
		//Test host map overides subnet map
		$this->assertEquals(aunmap::ecoAddrToIpAddr('127','254'),'192.168.0.40');

		//Test host port map overides subnet map and host
		$this->assertEquals(aunmap::ecoAddrToIpAddr('127','200'),'192.168.0.40:1000');
	}

	public function testCounter()
	{
		aunmap::setAunCounter('192.168.0.3',0);
		$this->assertEquals(aunmap::incAunCounter('192.168.0.3'),4);
		$this->assertEquals(aunmap::incAunCounter('192.168.0.3'),8);

		$this->assertEquals(aunmap::incAunCounter('192.168.0.1'),4);

		aunmap::setAunCounter('192.168.0.3',20);
		$this->assertEquals(aunmap::incAunCounter('192.168.0.3'),24);
	}
}
