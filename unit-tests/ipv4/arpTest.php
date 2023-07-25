<?php

/*
 * @group unit-tests
*/

include_once(__DIR__.'/../../src/include/system.inc.php');

use PHPUnit\Framework\TestCase;
use Monolog\Logger;
use HomeLan\FileStore\Services\Provider\IPv4\Exceptions\ArpEntryNotFound;
use HomeLan\FileStore\Services\Provider\IPv4\Arpcache;
use HomeLan\FileStore\Services\Provider\IPv4;

class arpTest extends TestCase {

	private Arpcache $oArp;

	protected function setup(): void
	{
		$oProvider = $this->createMock(IPv4::class);
		
		$this->oArp = new Arpcache($oProvider);
	}

	public function testAdd()
	{
		$this->oArp->addEntry(1, 1, '192.168.0.1');
		$this->oArp->addEntry(1, 2, '192.168.0.2');
		$this->oArp->addEntry(1, 3, '192.168.0.3');
		$this->oArp->addEntry(2, 1, '192.168.1.1');
		$this->oArp->addEntry(2, 2, '192.168.1.2');
		$this->oArp->addEntry(2, 3, '192.168.1.3');

		$this->assertEquals(6,count($this->oArp->dumpArpCache()));
	}

	public function testTTL()
	{
		//Setup entries with both positve and negative timeouts
		$this->oArp->addEntry(1, 1, '192.168.0.1',3600);
		$this->oArp->addEntry(1, 2, '192.168.0.2',3600);
		$this->oArp->addEntry(1, 3, '192.168.0.3',-1);
		$this->oArp->addEntry(2, 1, '192.168.1.1',-1);
		$this->oArp->addEntry(2, 2, '192.168.1.2',-1);
		$this->oArp->addEntry(2, 3, '192.168.1.3',3600);

		//The housekeeping method should remove all the timed out entries
		$this->oArp->houseKeeping();
		$this->assertEquals(3,count($this->oArp->dumpArpCache()));
	

	}

	public function testGetPhyStn()
	{
		$this->oArp->addEntry(1, 1, '192.168.0.1');
		$this->oArp->addEntry(1, 2, '192.168.0.2');
		$this->oArp->addEntry(1, 3, '192.168.0.3');
		$this->oArp->addEntry(2, 1, '192.168.1.1');
		$this->oArp->addEntry(2, 2, '192.168.1.2');
		$this->oArp->addEntry(2, 3, '192.168.1.3');

		$this->assertEquals(1,$this->oArp->getStation('192.168.0.1'));
		$this->assertEquals(2,$this->oArp->getStation('192.168.0.2'));
		$this->assertEquals(3,$this->oArp->getStation('192.168.0.3'));
		$this->assertEquals(1,$this->oArp->getStation('192.168.1.1'));
		$this->assertEquals(2,$this->oArp->getStation('192.168.1.2'));
		$this->assertEquals(3,$this->oArp->getStation('192.168.1.3'));

	}

	public function testGetPhyNet()
	{
		$this->oArp->addEntry(1, 1, '192.168.0.1');
		$this->oArp->addEntry(1, 2, '192.168.0.2');
		$this->oArp->addEntry(1, 3, '192.168.0.3');
		$this->oArp->addEntry(2, 1, '192.168.1.1');
		$this->oArp->addEntry(2, 2, '192.168.1.2');
		$this->oArp->addEntry(2, 3, '192.168.1.3');

		$this->assertEquals(1,$this->oArp->getNetwork('192.168.0.1'));
		$this->assertEquals(1,$this->oArp->getNetwork('192.168.0.2'));
		$this->assertEquals(1,$this->oArp->getNetwork('192.168.0.3'));
		$this->assertEquals(2,$this->oArp->getNetwork('192.168.1.1'));
		$this->assertEquals(2,$this->oArp->getNetwork('192.168.1.2'));
		$this->assertEquals(2,$this->oArp->getNetwork('192.168.1.3'));

	}

	public function testUnknownIP()
	{
		$this->oArp->addEntry(1, 1, '192.168.0.1');
		$this->oArp->addEntry(1, 2, '192.168.0.2');
		$this->oArp->addEntry(1, 3, '192.168.0.3');
		$this->oArp->addEntry(2, 1, '192.168.1.1');
		$this->oArp->addEntry(2, 2, '192.168.1.2');
		$this->oArp->addEntry(2, 3, '192.168.1.3');

		$this->assertEquals(1,$this->oArp->getNetwork('192.168.0.1'));

		$this->expectException(ArpEntryNotFound::class);
		$this->assertEquals(null,$this->oArp->getStation('192.168.4.1'));
	}

	public function testGetPhy()
	{
		$this->oArp->addEntry(1, 1, '192.168.0.1');
		$this->oArp->addEntry(1, 2, '192.168.0.2');
		$this->oArp->addEntry(1, 3, '192.168.0.3');
		$this->oArp->addEntry(2, 1, '192.168.1.1');
		$this->oArp->addEntry(2, 2, '192.168.1.2');
		$this->oArp->addEntry(2, 3, '192.168.1.3');

		$this->assertEquals(['network'=>1,'station'=>1],$this->oArp->getNetworkAndStation('192.168.0.1'));
		$this->assertEquals(['network'=>1,'station'=>2],$this->oArp->getNetworkAndStation('192.168.0.2'));
		$this->assertEquals(['network'=>1,'station'=>3],$this->oArp->getNetworkAndStation('192.168.0.3'));
		$this->assertEquals(['network'=>2,'station'=>1],$this->oArp->getNetworkAndStation('192.168.1.1'));
		$this->assertEquals(['network'=>2,'station'=>2],$this->oArp->getNetworkAndStation('192.168.1.2'));
		$this->assertEquals(['network'=>2,'station'=>3],$this->oArp->getNetworkAndStation('192.168.1.3'));


	}
}
