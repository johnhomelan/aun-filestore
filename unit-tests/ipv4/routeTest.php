<?php

/*
 * @group unit-tests
*/

include_once(__DIR__.'/../../src/include/system.inc.php');

use PHPUnit\Framework\TestCase;
use Monolog\Logger;
use Monolog\Handler\NullHandler;
use HomeLan\FileStore\Services\Provider\IPv4\Routes;
use HomeLan\FileStore\Services\Provider\IPv4;

class routeTest extends TestCase {

	private Routes $oRoutes;

	protected function setup(): void
	{
		$oProvider = $this->createMock(IPv4::class);
		$sRoutesFile = "192.168.4.0/255.255.255.0 192.168.0.1 30\n192.168.4.0/255.255.255.0 192.168.0.10 20\n0.0.0.0/0.0.0.0 192.168.0.2\n#0.0.0.0/0.0.0.0 192.168.0.1";

		$oLogger = new Logger("filestored-unittests");
		$oLogger->pushHandler(new NullHandler());
		
		$this->oRoutes = new Routes($oProvider,$oLogger,$sRoutesFile);
	}

	public function testAllValidRoutesRead()
	{
		$this->assertEquals(3,count($this->oRoutes->dumpRoutingTable()));
	}

	public function testRouteSelection()
	{
		//Test metric
		$this->assertEquals('192.168.0.10',$this->oRoutes->getRoute('192.168.4.2')['via']);

		//Test subnet matching
		$this->assertEquals('192.168.0.2',$this->oRoutes->getRoute('192.168.5.2')['via']);

	}

	public function testGetRouteReturnsNullWhenNoRouteExists(): void
	{
		$oProvider = $this->createMock(IPv4::class);
		$oLogger = new Logger("filestored-unittests");
		$oLogger->pushHandler(new NullHandler());
		$oEmptyRoutes = new Routes($oProvider, $oLogger, '');
		$this->assertNull($oEmptyRoutes->getRoute('10.0.0.1'));
	}

	public function testCommentedRouteIsNotLoaded(): void
	{
		// Config has 3 valid lines + 1 commented; all valid + default = 3 entries
		$this->assertSame(3, count($this->oRoutes->dumpRoutingTable()));
	}

	public function testDumpRoutingTableHasExpectedKeys(): void
	{
		$aTable = $this->oRoutes->dumpRoutingTable();
		$this->assertNotEmpty($aTable);
		$aEntry = $aTable[0];
		$this->assertArrayHasKey('network', $aEntry);
		$this->assertArrayHasKey('subnet', $aEntry);
		$this->assertArrayHasKey('gw', $aEntry);
		$this->assertArrayHasKey('metric', $aEntry);
	}

	public function testDefaultRouteMatchesAnyAddress(): void
	{
		// 0.0.0.0/0.0.0.0 default route present; should match arbitrary address
		$aRoute = $this->oRoutes->getRoute('1.2.3.4');
		$this->assertNotNull($aRoute);
		$this->assertSame('192.168.0.2', $aRoute['via']);
	}
}
