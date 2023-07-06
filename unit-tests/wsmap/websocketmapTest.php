<?php

/*
 * @group unit-tests
*/

include_once(__DIR__.'/../../src/include/system.inc.php');

use PHPUnit\Framework\TestCase;
use Monolog\Logger;
use HomeLan\FileStore\WebSocket\Map as WebSocketMap;
use HomeLan\FileStore\Piconet\Handler;

class websocketmapTest extends TestCase {

	protected function setup(): void
	{
		$oLogger = new Logger("filestored-unittests");
		$sMapFile = "1\n2\n3\n24\n124\n";
		WebSocketMap::init($oLogger,$sMapFile);
	}

	public function testnetworkKnown()
	{
		$this->assertTrue(WebSocketMap::networkKnown(1));
		$this->assertTrue(WebSocketMap::networkKnown(2));
		$this->assertTrue(WebSocketMap::networkKnown(3));
		$this->assertTrue(WebSocketMap::networkKnown(24));
		$this->assertTrue(WebSocketMap::networkKnown(124));
		$this->assertNotTrue(WebSocketMap::networkKnown(4));
		$this->assertNotTrue(WebSocketMap::networkKnown(100));
		
	}

}
