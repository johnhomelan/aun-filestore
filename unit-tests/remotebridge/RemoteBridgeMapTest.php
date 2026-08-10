<?php

/*
 * @group unit-tests
 *
 * Tests for RemoteBridge Map — map file parsing and network routing registry.
 */

include_once(__DIR__ . '/../../src/include/system.inc.php');
include_once(__DIR__ . '/MockTcpConnection.php');

use PHPUnit\Framework\TestCase;
use Monolog\Logger;
use Monolog\Handler\NullHandler;
use HomeLan\FileStore\RemoteBridge\Map;
use HomeLan\FileStore\RemoteBridge\Connection;
use HomeLan\FileStore\RemoteBridge\BridgePacket;

class RemoteBridgeMapTest extends TestCase
{
    private Logger $oLogger;

    protected function setUp(): void
    {
        $this->oLogger = new Logger('test');
        $this->oLogger->pushHandler(new NullHandler());
        Map::reset();
    }

    // -----------------------------------------------------------------------
    // Map file parsing — SERVER entries
    // -----------------------------------------------------------------------

    public function testParsesSingleServerEntry(): void
    {
        Map::init($this->oLogger, "SERVER 8765 mysecret 1,2,3\n");
        $aEntries = Map::getServerEntries();
        $this->assertCount(1, $aEntries);
        $this->assertSame(8765, $aEntries[0]['port']);
        $this->assertSame('mysecret', $aEntries[0]['secret']);
        $this->assertSame([1, 2, 3], $aEntries[0]['networks']);
    }

    public function testParsesMultipleServerEntries(): void
    {
        $sMap = "SERVER 8765 secretA 1\nSERVER 9000 secretB 2,3\n";
        Map::init($this->oLogger, $sMap);
        $this->assertCount(2, Map::getServerEntries());
    }

    // -----------------------------------------------------------------------
    // Map file parsing — CLIENT entries
    // -----------------------------------------------------------------------

    public function testParsesSingleClientEntry(): void
    {
        Map::init($this->oLogger, "CLIENT 192.168.1.10:8765 theirsecret 4,5\n");
        $aEntries = Map::getClientEntries();
        $this->assertCount(1, $aEntries);
        $this->assertSame('192.168.1.10', $aEntries[0]['host']);
        $this->assertSame(8765, $aEntries[0]['port']);
        $this->assertSame('theirsecret', $aEntries[0]['secret']);
        $this->assertSame([4, 5], $aEntries[0]['networks']);
    }

    public function testParsesMultipleClientEntries(): void
    {
        $sMap = "CLIENT 10.0.0.1:8765 s1 6\nCLIENT 10.0.0.2:8765 s2 7,8\n";
        Map::init($this->oLogger, $sMap);
        $this->assertCount(2, Map::getClientEntries());
    }

    // -----------------------------------------------------------------------
    // Map file parsing — edge cases
    // -----------------------------------------------------------------------

    public function testIgnoresCommentLines(): void
    {
        $sMap = "# This is a comment\nSERVER 8765 secret 1\n# Another comment\n";
        Map::init($this->oLogger, $sMap);
        $this->assertCount(1, Map::getServerEntries());
    }

    public function testIgnoresBlankLines(): void
    {
        $sMap = "\nSERVER 8765 secret 1\n\n";
        Map::init($this->oLogger, $sMap);
        $this->assertCount(1, Map::getServerEntries());
    }

    public function testIgnoresUnknownKeywords(): void
    {
        $sMap = "UNKNOWN 8765 secret 1\nSERVER 9000 s 2\n";
        Map::init($this->oLogger, $sMap);
        $this->assertCount(1, Map::getServerEntries());
    }

    public function testEmptyMapYieldsNoEntries(): void
    {
        Map::init($this->oLogger, '');
        $this->assertEmpty(Map::getServerEntries());
        $this->assertEmpty(Map::getClientEntries());
    }

    // -----------------------------------------------------------------------
    // Network routing registry
    // -----------------------------------------------------------------------

    public function testNetworkUnknownInitially(): void
    {
        Map::init($this->oLogger, '');
        $this->assertFalse(Map::networkKnown(1));
    }

    public function testRegisterPeerNetworksMakesNetworkKnown(): void
    {
        Map::init($this->oLogger, '');
        $oConn = $this->makeFakeConnection();
        Map::registerPeerNetworks($oConn, [2, 3]);
        $this->assertTrue(Map::networkKnown(2));
        $this->assertTrue(Map::networkKnown(3));
        $this->assertFalse(Map::networkKnown(4));
    }

    public function testNetworkToConnectionReturnsRegisteredConnection(): void
    {
        Map::init($this->oLogger, '');
        $oConn = $this->makeFakeConnection();
        Map::registerPeerNetworks($oConn, [5]);
        $this->assertSame($oConn, Map::networkToConnection(5));
    }

    public function testNetworkToConnectionReturnsNullForUnknown(): void
    {
        Map::init($this->oLogger, '');
        $this->assertNull(Map::networkToConnection(99));
    }

    public function testUnregisterConnectionRemovesNetworks(): void
    {
        Map::init($this->oLogger, '');
        $oConn = $this->makeFakeConnection();
        Map::registerPeerNetworks($oConn, [1, 2]);
        Map::unregisterConnection($oConn);
        $this->assertFalse(Map::networkKnown(1));
        $this->assertFalse(Map::networkKnown(2));
    }

    public function testUnregisterDoesNotAffectOtherConnections(): void
    {
        Map::init($this->oLogger, '');
        $oConn1 = $this->makeFakeConnection();
        $oConn2 = $this->makeFakeConnection();
        Map::registerPeerNetworks($oConn1, [1]);
        Map::registerPeerNetworks($oConn2, [2]);
        Map::unregisterConnection($oConn1);
        $this->assertFalse(Map::networkKnown(1));
        $this->assertTrue(Map::networkKnown(2));
    }

    public function testGetKnownNetworksReturnsAllRegistered(): void
    {
        Map::init($this->oLogger, '');
        $oConn = $this->makeFakeConnection();
        Map::registerPeerNetworks($oConn, [3, 4, 5]);
        $aKnown = Map::getKnownNetworks();
        sort($aKnown);
        $this->assertSame([3, 4, 5], $aKnown);
    }

    public function testResetClearsAllState(): void
    {
        Map::init($this->oLogger, "SERVER 8765 s 1\nCLIENT 10.0.0.1:8765 s 2\n");
        $oConn = $this->makeFakeConnection();
        Map::registerPeerNetworks($oConn, [3]);

        Map::reset();

        $this->assertEmpty(Map::getServerEntries());
        $this->assertEmpty(Map::getClientEntries());
        $this->assertFalse(Map::networkKnown(3));
    }

    // -----------------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------------

    private function makeFakeConnection(): Connection
    {
        $oTcp = new MockTcpConnection();
        return new Connection(
            $this->oLogger,
            $oTcp,
            'server',
            'secret',
            [1],
            static function (BridgePacket $p) {},
        );
    }
}
