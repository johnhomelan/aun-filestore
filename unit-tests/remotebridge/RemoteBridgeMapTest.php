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
use HomeLan\FileStore\Messages\EconetPacket;

class RemoteBridgeMapTest extends TestCase
{
    private Logger $oLogger;
    private int $iBufferTtl;
    private int $iBufferMaxPackets;

    protected function setUp(): void
    {
        $this->oLogger = new Logger('test');
        $this->oLogger->pushHandler(new NullHandler());
        Map::reset();

        $this->iBufferTtl = (new \ReflectionClassConstant(Map::class, 'BUFFER_TTL_SECONDS'))->getValue();
        $this->iBufferMaxPackets = (new \ReflectionClassConstant(Map::class, 'BUFFER_MAX_PACKETS_PER_NETWORK'))->getValue();
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

    public function testUnregisterConnectionKeepsNetworksKnownDuringGracePeriod(): void
    {
        Map::init($this->oLogger, '');
        $oConn = $this->makeFakeConnection();
        Map::registerPeerNetworks($oConn, [1, 2]);
        Map::unregisterConnection($oConn);

        // Still "known" immediately after disconnect — this is what lets outbound packets
        // keep being buffered instead of being mis-routed elsewhere.
        $this->assertTrue(Map::networkKnown(1));
        $this->assertTrue(Map::networkKnown(2));

        // But no longer routable — networkToConnection() must return null while down.
        $this->assertNull(Map::networkToConnection(1));
        $this->assertNull(Map::networkToConnection(2));
    }

    public function testNetworkBecomesUnknownAfterGracePeriodExpires(): void
    {
        Map::init($this->oLogger, '');
        $oConn = $this->makeFakeConnection();
        Map::registerPeerNetworks($oConn, [1]);
        Map::unregisterConnection($oConn);

        $this->backdateRecentlyDown(1, $this->iBufferTtl + 1);

        $this->assertFalse(Map::networkKnown(1));
    }

    public function testUnregisterDoesNotAffectOtherConnections(): void
    {
        Map::init($this->oLogger, '');
        $oConn1 = $this->makeFakeConnection();
        $oConn2 = $this->makeFakeConnection();
        Map::registerPeerNetworks($oConn1, [1]);
        Map::registerPeerNetworks($oConn2, [2]);
        Map::unregisterConnection($oConn1);
        $this->assertTrue(Map::networkKnown(1));
        $this->assertTrue(Map::networkKnown(2));
        $this->assertNull(Map::networkToConnection(1));
        $this->assertSame($oConn2, Map::networkToConnection(2));
    }

    // -----------------------------------------------------------------------
    // Outbound packet buffering across a brief reconnect
    // -----------------------------------------------------------------------

    public function testBufferedPacketIsDeliveredWhenNetworkComesBack(): void
    {
        Map::init($this->oLogger, '');
        $oOldConn = $this->makeFakeConnection();
        Map::registerPeerNetworks($oOldConn, [7]);
        Map::unregisterConnection($oOldConn);

        Map::bufferPacket(7, $this->makePacket());

        $oNewConn = $this->createMock(Connection::class);
        $oNewConn->expects($this->once())->method('send');
        Map::registerPeerNetworks($oNewConn, [7]);
    }

    public function testExpiredBufferedPacketIsDroppedNotSent(): void
    {
        Map::init($this->oLogger, '');
        $oOldConn = $this->makeFakeConnection();
        Map::registerPeerNetworks($oOldConn, [7]);
        Map::unregisterConnection($oOldConn);

        Map::bufferPacket(7, $this->makePacket());
        $this->backdateBufferedPacket(7, 0, $this->iBufferTtl + 1);

        // Reconnect happens before the network's own "known" grace period lapses,
        // but the buffered packet itself is already stale and must not be sent.
        $oNewConn = $this->createMock(Connection::class);
        $oNewConn->expects($this->never())->method('send');
        Map::registerPeerNetworks($oNewConn, [7]);
    }

    public function testBufferIsCappedAndDropsOldestFirst(): void
    {
        Map::init($this->oLogger, '');
        $oOldConn = $this->makeFakeConnection();
        Map::registerPeerNetworks($oOldConn, [7]);
        Map::unregisterConnection($oOldConn);

        $iCap = $this->iBufferMaxPackets;
        for ($i = 0; $i < $iCap + 5; $i++) {
            Map::bufferPacket(7, $this->makePacket());
        }

        $oNewConn = $this->createMock(Connection::class);
        $oNewConn->expects($this->exactly($iCap))->method('send');
        Map::registerPeerNetworks($oNewConn, [7]);
    }

    public function testBufferForOneNetworkUnaffectedByAnother(): void
    {
        Map::init($this->oLogger, '');
        $oConnA = $this->makeFakeConnection();
        $oConnB = $this->makeFakeConnection();
        Map::registerPeerNetworks($oConnA, [7]);
        Map::registerPeerNetworks($oConnB, [8]);
        Map::unregisterConnection($oConnA);
        Map::unregisterConnection($oConnB);

        Map::bufferPacket(7, $this->makePacket());
        Map::bufferPacket(8, $this->makePacket());

        $oNewConnA = $this->createMock(Connection::class);
        $oNewConnA->expects($this->once())->method('send');
        Map::registerPeerNetworks($oNewConnA, [7]);

        // Network 8's buffer must still be intact — flushing 7 must not touch it.
        $oNewConnB = $this->createMock(Connection::class);
        $oNewConnB->expects($this->once())->method('send');
        Map::registerPeerNetworks($oNewConnB, [8]);
    }

    public function testResetClearsBufferedPacketsAndRecentlyDownState(): void
    {
        Map::init($this->oLogger, '');
        $oConn = $this->makeFakeConnection();
        Map::registerPeerNetworks($oConn, [7]);
        Map::unregisterConnection($oConn);
        Map::bufferPacket(7, $this->makePacket());

        Map::reset();

        $this->assertFalse(Map::networkKnown(7));

        // Buffer must have been cleared too — nothing should be sent even if 7 comes back.
        $oNewConn = $this->createMock(Connection::class);
        $oNewConn->expects($this->never())->method('send');
        Map::registerPeerNetworks($oNewConn, [7]);
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
        Map::rememberAckRelay(7, 254, $oConn);

        Map::reset();

        $this->assertEmpty(Map::getServerEntries());
        $this->assertEmpty(Map::getClientEntries());
        $this->assertFalse(Map::networkKnown(3));
        $this->assertFalse(Map::relayAckIfKnown(7, 254));
    }

    // -----------------------------------------------------------------------
    // rememberAckRelay() / relayAckIfKnown() — see docs/protocols/remote-bridge.md
    //
    // Deliberately keyed by (network, station) via rememberAckRelay(), not by
    // registerPeerNetworks()/networkToConnection() — a station this instance
    // relays a SEND to lives on a network *this* instance itself serves, so
    // the peer-announced-networks table (used for outbound SEND routing) can
    // never contain it. See Map::rememberAckRelay()'s docblock and
    // docs/protocols/remote-bridge.md's Conformance Requirement #2.
    // -----------------------------------------------------------------------

    public function testRelayAckIfKnownReturnsFalseForUnknownStation(): void
    {
        Map::init($this->oLogger, '');
        $this->assertFalse(Map::relayAckIfKnown(99, 254));
    }

    public function testRegisteringOnlyThePeerNetworkIsNotEnoughToRelay(): void
    {
        // Reproduces the original bug directly: announcing a network via
        // NETWORKS (i.e. registerPeerNetworks(), the table outbound SEND
        // routing uses) must NOT by itself make relayAckIfKnown() succeed —
        // only an actual rememberAckRelay() entry may.
        Map::init($this->oLogger, '');
        $oConn = $this->createMock(Connection::class);
        $oConn->expects($this->never())->method('sendAck');
        Map::registerPeerNetworks($oConn, [7]);

        $this->assertFalse(Map::relayAckIfKnown(7, 254));
    }

    public function testRelayAckIfKnownCallsSendAckOnConnectionForRememberedStation(): void
    {
        Map::init($this->oLogger, '');
        $oConn = $this->createMock(Connection::class);
        $oConn->expects($this->once())->method('sendAck')->with(7, 254);
        Map::rememberAckRelay(7, 254, $oConn);

        $this->assertTrue(Map::relayAckIfKnown(7, 254));
    }

    public function testRelayAckIfKnownDoesNotMatchADifferentStationOnTheSameNetwork(): void
    {
        Map::init($this->oLogger, '');
        $oConn = $this->createMock(Connection::class);
        $oConn->expects($this->never())->method('sendAck');
        Map::rememberAckRelay(7, 254, $oConn);

        $this->assertFalse(Map::relayAckIfKnown(7, 1));
    }

    public function testRelayAckIfKnownReturnsFalseAfterConnectionUnregistered(): void
    {
        Map::init($this->oLogger, '');
        $oConn = $this->makeFakeConnection();
        Map::rememberAckRelay(7, 254, $oConn);
        Map::unregisterConnection($oConn);

        // The pending-relay entry is purged along with the connection — no connection to relay via.
        $this->assertFalse(Map::relayAckIfKnown(7, 254));
    }

    public function testLaterSendForSameStationOverwritesEarlierPendingRelay(): void
    {
        Map::init($this->oLogger, '');
        $oConnOld = $this->createMock(Connection::class);
        $oConnOld->expects($this->never())->method('sendAck');
        $oConnNew = $this->createMock(Connection::class);
        $oConnNew->expects($this->once())->method('sendAck')->with(7, 254);

        Map::rememberAckRelay(7, 254, $oConnOld);
        Map::rememberAckRelay(7, 254, $oConnNew);

        $this->assertTrue(Map::relayAckIfKnown(7, 254));
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
            static function (BridgePacket $p) {},
        );
    }

    private function makePacket(): EconetPacket
    {
        $oPkt = new EconetPacket();
        $oPkt->setFlags(0x02);
        $oPkt->setPort(0x01);
        $oPkt->setSourceNetwork(1);
        $oPkt->setSourceStation(1);
        $oPkt->setDestinationNetwork(7);
        $oPkt->setDestinationStation(10);
        $oPkt->setData('hello');
        return $oPkt;
    }

    /** Backdates Map::$aRecentlyDown[$iNetwork] by $iSecondsAgo, to simulate grace-period expiry without sleeping. */
    private function backdateRecentlyDown(int $iNetwork, int $iSecondsAgo): void
    {
        $rProp = new \ReflectionProperty(Map::class, 'aRecentlyDown');
        $rProp->setAccessible(true);
        $aRecentlyDown = $rProp->getValue();
        $aRecentlyDown[$iNetwork] = time() - $iSecondsAgo;
        $rProp->setValue(null, $aRecentlyDown);
    }

    /** Backdates the buffered packet at $iIndex for $iNetwork by $iSecondsAgo, to simulate it going stale. */
    private function backdateBufferedPacket(int $iNetwork, int $iIndex, int $iSecondsAgo): void
    {
        $rProp = new \ReflectionProperty(Map::class, 'aOutboundBuffer');
        $rProp->setAccessible(true);
        $aBuffer = $rProp->getValue();
        $aBuffer[$iNetwork][$iIndex]['time'] = time() - $iSecondsAgo;
        $rProp->setValue(null, $aBuffer);
    }
}
