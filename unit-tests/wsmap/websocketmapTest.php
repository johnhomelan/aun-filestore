<?php

/*
 * @group unit-tests
*/

include_once(__DIR__.'/../../src/include/system.inc.php');

use PHPUnit\Framework\TestCase;
use Monolog\Logger;
use HomeLan\FileStore\WebSocket\Map as WebSocketMap;
use HomeLan\FileStore\Piconet\Handler;
use Ratchet\ConnectionInterface;
class websocketmapTest extends TestCase {

	protected function setup(): void
	{
		// Reset statics first so tests are fully isolated
		foreach (['aDynamicNetworks', 'aSocketList'] as $sProp) {
			$rp = new \ReflectionProperty(WebSocketMap::class, $sProp);
			$rp->setAccessible(true);
			$rp->setValue(null, []);
		}
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

	public function testallocateAddress()
	{
		$oConnection  = $this->getMockBuilder(ConnectionInterface::class)->getMock();
		$sEconetAddr = WebSocketMap::allocateAddress($oConnection);
		$this->assertIsString($sEconetAddr);
	}

	// =========================================================================
	// webSocketToEconetAddress()
	// =========================================================================

	public function testWebSocketToEconetAddressReturnsNullForUnregisteredSocket(): void
	{
		$oConnection = $this->getMockBuilder(ConnectionInterface::class)->getMock();
		$this->assertNull(WebSocketMap::webSocketToEconetAddress($oConnection));
	}

	public function testWebSocketToEconetAddressReturnsAddressAfterAllocation(): void
	{
		$oConnection = $this->getMockBuilder(ConnectionInterface::class)->getMock();
		$sExpected = WebSocketMap::allocateAddress($oConnection);

		$this->assertSame($sExpected, WebSocketMap::webSocketToEconetAddress($oConnection));
	}

	public function testWebSocketToEconetAddressFormatIsNetworkDotStation(): void
	{
		$oConnection = $this->getMockBuilder(ConnectionInterface::class)->getMock();
		WebSocketMap::allocateAddress($oConnection);
		$sAddr = WebSocketMap::webSocketToEconetAddress($oConnection);

		$this->assertMatchesRegularExpression('/^\d+\.\d+$/', $sAddr);
	}

	public function testWebSocketToEconetAddressReturnsNullAfterFree(): void
	{
		$oConnection = $this->getMockBuilder(ConnectionInterface::class)->getMock();
		WebSocketMap::allocateAddress($oConnection);
		WebSocketMap::freeAddress($oConnection);

		$this->assertNull(WebSocketMap::webSocketToEconetAddress($oConnection));
	}

	public function testDifferentSocketsGetDifferentAddresses(): void
	{
		$oConn1 = $this->getMockBuilder(ConnectionInterface::class)->getMock();
		$oConn2 = $this->getMockBuilder(ConnectionInterface::class)->getMock();

		$sAddr1 = WebSocketMap::allocateAddress($oConn1);
		$sAddr2 = WebSocketMap::allocateAddress($oConn2);

		$this->assertNotSame($sAddr1, $sAddr2);
		$this->assertSame($sAddr1, WebSocketMap::webSocketToEconetAddress($oConn1));
		$this->assertSame($sAddr2, WebSocketMap::webSocketToEconetAddress($oConn2));
	}

	// =========================================================================
	// ecoAddrToSocket()
	// =========================================================================

	public function testEcoAddrToSocketReturnsNullForUnallocatedAddress(): void
	{
		$this->assertNull(WebSocketMap::ecoAddrToSocket(1, 99));
	}

	public function testEcoAddrToSocketReturnsNullForUnknownNetwork(): void
	{
		$this->assertNull(WebSocketMap::ecoAddrToSocket(200, 1));
	}

	public function testEcoAddrToSocketReturnsSocketAfterAllocation(): void
	{
		$oConnection = $this->getMockBuilder(ConnectionInterface::class)->getMock();
		$sAddr = WebSocketMap::allocateAddress($oConnection);

		[$iNet, $iStn] = array_map('intval', explode('.', $sAddr));

		$this->assertSame($oConnection, WebSocketMap::ecoAddrToSocket($iNet, $iStn));
	}

	public function testEcoAddrToSocketReturnsNullAfterFree(): void
	{
		$oConnection = $this->getMockBuilder(ConnectionInterface::class)->getMock();
		$sAddr = WebSocketMap::allocateAddress($oConnection);
		WebSocketMap::freeAddress($oConnection);

		[$iNet, $iStn] = array_map('intval', explode('.', $sAddr));

		$this->assertNull(WebSocketMap::ecoAddrToSocket($iNet, $iStn));
	}

	public function testEcoAddrToSocketRoundTripsWithWebSocketToEconetAddress(): void
	{
		$oConnection = $this->getMockBuilder(ConnectionInterface::class)->getMock();
		$sAddr = WebSocketMap::allocateAddress($oConnection);

		[$iNet, $iStn] = array_map('intval', explode('.', $sAddr));
		$oRetrieved = WebSocketMap::ecoAddrToSocket($iNet, $iStn);

		// Confirm both lookup directions are consistent
		$this->assertSame($sAddr, WebSocketMap::webSocketToEconetAddress($oRetrieved));
	}
}
