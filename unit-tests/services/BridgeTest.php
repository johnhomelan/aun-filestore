<?php

/*
 * @group unit-tests
 */

use PHPUnit\Framework\TestCase;
use Monolog\Logger;
use Monolog\Handler\NullHandler;
use HomeLan\FileStore\Messages\EconetPacket;
use HomeLan\FileStore\Messages\BridgeRequest;
use HomeLan\FileStore\Messages\Reply;
use HomeLan\FileStore\Services\Provider\Bridge;
use HomeLan\FileStore\Aun\Map;
use HomeLan\FileStore\WebSocket\Map as WebSocketMap;
use HomeLan\FileStore\Piconet\Map as PiconetMap;
use HomeLan\FileStore\RemoteBridge\Map as RemoteBridgeMap;

include_once('include/system.inc.php');

// ---------------------------------------------------------------------------
// Testable subclass — exposes the protected $aRemoteNetworks so tests can
// assert what the bridge learned from EC_BR_QUERY messages.
// ---------------------------------------------------------------------------
class BridgeTestable extends Bridge
{
    public function getRemoteNetworks(): array
    {
        return $this->aRemoteNetworks;
    }
}

// ---------------------------------------------------------------------------
// Tests
// ---------------------------------------------------------------------------
class BridgeTest extends TestCase
{
    private BridgeTestable $oBridge;

    protected function setUp(): void
    {
        Map::$aHostMap      = [];
        Map::$aSubnetMap    = [];
        Map::$aIPLookupCache = [];
        WebSocketMap::$aDynamicNetworks = [];
        WebSocketMap::$aSocketList      = [];
        PiconetMap::$aNetworks          = [];
        RemoteBridgeMap::reset();

        config::overrideValue('bridge_local_network_number', 1);

        $oLogger = new Logger('test');
        $oLogger->pushHandler(new NullHandler());
        $this->oBridge = new BridgeTestable($oLogger);
    }

    protected function tearDown(): void
    {
        config::resetValue('bridge_local_network_number');
        Map::$aHostMap      = [];
        Map::$aSubnetMap    = [];
        Map::$aIPLookupCache = [];
        WebSocketMap::$aDynamicNetworks = [];
        WebSocketMap::$aSocketList      = [];
        PiconetMap::$aNetworks          = [];
        RemoteBridgeMap::reset();
    }

    // -----------------------------------------------------------------------
    // Packet builder helpers
    // -----------------------------------------------------------------------

    /**
     * Build a raw EconetPacket carrying a well-formed bridge payload.
     *
     * Format: [fncode:1][Bridge:6][replyPort:1][extra]
     */
    private function makePkt(int $iFn, string $sExtra = '', int $iEcoPort = 0x9D, int $iNet = 1, int $iStn = 3): EconetPacket
    {
        $oPkt = new EconetPacket();
        $oPkt->setPort($iEcoPort);
        $oPkt->setSourceNetwork($iNet);
        $oPkt->setSourceStation($iStn);
        $oPkt->setFlags(0);
        $oPkt->setDestinationNetwork(0);
        $oPkt->setDestinationStation(255);
        $oPkt->setData(pack('C', $iFn) . 'Bridge' . pack('C', 0x9E) . $sExtra);
        return $oPkt;
    }

    private function localnetPkt(int $iNet = 1, int $iStn = 3): EconetPacket
    {
        return $this->makePkt(0x82, '', 0x9D, $iNet, $iStn);
    }

    private function netknownPkt(int $iQueryNet, int $iNet = 1, int $iStn = 3): EconetPacket
    {
        return $this->makePkt(0x83, pack('C', $iQueryNet), 0x9D, $iNet, $iStn);
    }

    private function queryPkt(array $aNetworks = [], int $iNet = 1, int $iStn = 3): EconetPacket
    {
        $sNets = $aNetworks ? pack('C*', ...$aNetworks) : '';
        return $this->makePkt(0x80, $sNets, 0x9C, $iNet, $iStn);
    }

    private function query2Pkt(array $aNetworks = [], int $iNet = 1, int $iStn = 3): EconetPacket
    {
        $sNets = $aNetworks ? pack('C*', ...$aNetworks) : '';
        return $this->makePkt(0x81, $sNets, 0x9C, $iNet, $iStn);
    }

    /**
     * Build a raw EconetPacket carrying a genuine Acorn ANFS ROM bridge broadcast.
     *
     * Real ROM broadcasts carry the function code in the Econet control byte
     * (not as a leading data byte) and spell the magic string upper-case.
     *
     * Format: flags=fncode, data=[BRIDGE:6][replyPort:1][extra]
     */
    private function makeRomPkt(int $iFn, string $sExtra = '', int $iEcoPort = 0x9D, int $iNet = 1, int $iStn = 3): EconetPacket
    {
        $oPkt = new EconetPacket();
        $oPkt->setPort($iEcoPort);
        $oPkt->setSourceNetwork($iNet);
        $oPkt->setSourceStation($iStn);
        $oPkt->setFlags($iFn);
        $oPkt->setDestinationNetwork(0);
        $oPkt->setDestinationStation(255);
        $oPkt->setData('BRIDGE' . pack('C', 0x9C) . $sExtra);
        return $oPkt;
    }

    private function dispatch(EconetPacket $oPkt): array
    {
        $this->oBridge->broadcastPacketIn($oPkt);
        return $this->oBridge->getReplies();
    }

    // -----------------------------------------------------------------------
    // BridgeRequest — decode and accessors
    // -----------------------------------------------------------------------

    public function testDecodeLocalnetFunction(): void
    {
        $oLogger = new Logger('test');
        $oLogger->pushHandler(new NullHandler());
        $oReq = new BridgeRequest($this->localnetPkt(), $oLogger);
        $this->assertSame('EC_BR_LOCALNET', $oReq->getFunction());
    }

    public function testDecodeNetknownFunction(): void
    {
        $oLogger = new Logger('test');
        $oLogger->pushHandler(new NullHandler());
        $oReq = new BridgeRequest($this->netknownPkt(5), $oLogger);
        $this->assertSame('EC_BR_NETKNOWN', $oReq->getFunction());
    }

    public function testDecodeQueryFunction(): void
    {
        $oLogger = new Logger('test');
        $oLogger->pushHandler(new NullHandler());
        $oReq = new BridgeRequest($this->queryPkt(), $oLogger);
        $this->assertSame('EC_BR_QUERY', $oReq->getFunction());
    }

    public function testDecodeQuery2Function(): void
    {
        $oLogger = new Logger('test');
        $oLogger->pushHandler(new NullHandler());
        $oReq = new BridgeRequest($this->query2Pkt(), $oLogger);
        $this->assertSame('EC_BR_QUERY2', $oReq->getFunction());
    }

    // -----------------------------------------------------------------------
    // BridgeRequest — genuine Acorn ANFS ROM wire layout (function code in
    // the control byte, upper-case "BRIDGE" magic, no leading data byte)
    // -----------------------------------------------------------------------

    public function testDecodeRomLocalnetFunction(): void
    {
        $oLogger = new Logger('test');
        $oLogger->pushHandler(new NullHandler());
        $oReq = new BridgeRequest($this->makeRomPkt(0x82), $oLogger);
        $this->assertSame('EC_BR_LOCALNET', $oReq->getFunction());
    }

    public function testDecodeRomQueryFunction(): void
    {
        $oLogger = new Logger('test');
        $oLogger->pushHandler(new NullHandler());
        $oReq = new BridgeRequest($this->makeRomPkt(0x80, '', 0x9C), $oLogger);
        $this->assertSame('EC_BR_QUERY', $oReq->getFunction());
    }

    public function testDecodeRomReplyPortReadCorrectly(): void
    {
        $oLogger = new Logger('test');
        $oLogger->pushHandler(new NullHandler());
        $oReq = new BridgeRequest($this->makeRomPkt(0x82), $oLogger);
        $this->assertSame(0x9C, $oReq->getReplyPort());
    }

    public function testRomLocalnetPacketProducesReply(): void
    {
        $aReplies = $this->dispatch($this->makeRomPkt(0x82));
        $this->assertCount(1, $aReplies);
    }

    /**
     * The exact base64 payload reported from a real Acorn ANFS ROM client:
     * AZyCAAAAAABCUklER0WcAA== decodes to AUN type=1 (Broadcast), port=0x9C,
     * control=0x82 (EC_BR_LOCALNET), data="BRIDGE"+0x9C+0x00 — no leading
     * function byte, upper-case magic. Must decode without throwing and
     * produce exactly one EC_BR_LOCALNET reply.
     */
    public function testRealAnfsRomLocalnetBroadcastDecodesCorrectly(): void
    {
        $sPayload = base64_decode('AZyCAAAAAABCUklER0WcAA==', true);
        $this->assertNotFalse($sPayload);

        $aHeader = unpack('Ctype/Cport/Ccb/Cpad/Vseq', $sPayload);
        $this->assertNotFalse($aHeader);

        $oPkt = new EconetPacket();
        $oPkt->setPort($aHeader['port']);
        $oPkt->setFlags($aHeader['cb']);
        $oPkt->setSourceNetwork(1);
        $oPkt->setSourceStation(3);
        $oPkt->setDestinationNetwork(0);
        $oPkt->setDestinationStation(255);
        $oPkt->setData(substr($sPayload, 8));

        $aReplies = $this->dispatch($oPkt);
        $this->assertCount(1, $aReplies);
    }

    public function testDecodeInvalidMagicThrows(): void
    {
        $this->expectException(Exception::class);
        $oLogger = new Logger('test');
        $oLogger->pushHandler(new NullHandler());
        $oPkt = new EconetPacket();
        $oPkt->setPort(0x9D);
        $oPkt->setSourceNetwork(1);
        $oPkt->setSourceStation(3);
        $oPkt->setFlags(0);
        $oPkt->setDestinationNetwork(0);
        $oPkt->setDestinationStation(255);
        $oPkt->setData(pack('C', 0x82) . 'BADSTR' . pack('C', 0x9E));
        new BridgeRequest($oPkt, $oLogger);
    }

    public function testGetReplyPortFromPacket(): void
    {
        $oLogger = new Logger('test');
        $oLogger->pushHandler(new NullHandler());
        $oReq = new BridgeRequest($this->localnetPkt(), $oLogger);
        $this->assertSame(0x9E, $oReq->getReplyPort());
    }

    public function testGetNetworkReturnsCorrectValue(): void
    {
        $oLogger = new Logger('test');
        $oLogger->pushHandler(new NullHandler());
        $oReq = new BridgeRequest($this->netknownPkt(42), $oLogger);
        $this->assertSame(42, $oReq->getNetwork());
    }

    public function testGetNetworkListEmptyWhenNoData(): void
    {
        $oLogger = new Logger('test');
        $oLogger->pushHandler(new NullHandler());
        $oReq = new BridgeRequest($this->queryPkt([]), $oLogger);
        $this->assertSame([], $oReq->getNetworkList());
    }

    public function testGetNetworkListReturnsAllNetworks(): void
    {
        $oLogger = new Logger('test');
        $oLogger->pushHandler(new NullHandler());
        $oReq = new BridgeRequest($this->queryPkt([5, 17, 128]), $oLogger);
        $this->assertSame([5, 17, 128], $oReq->getNetworkList());
    }

    public function testBuildReplyReturnsReplyInstance(): void
    {
        $oLogger = new Logger('test');
        $oLogger->pushHandler(new NullHandler());
        $oReq = new BridgeRequest($this->localnetPkt(), $oLogger);
        $this->assertInstanceOf(Reply::class, $oReq->buildReply());
    }

    // -----------------------------------------------------------------------
    // Bridge service — metadata
    // -----------------------------------------------------------------------

    public function testGetNameReturnsBridge(): void
    {
        $this->assertSame('Bridge', $this->oBridge->getName());
    }

    public function testGetServicePortsReturnsBothPorts(): void
    {
        $this->assertSame([0x9C, 0x9D], $this->oBridge->getServicePorts());
    }

    public function testGetAdminInterfaceReturnsBridgeAdminInstance(): void
    {
        $this->assertInstanceOf(\HomeLan\FileStore\Services\Provider\Bridge\Admin::class, $this->oBridge->getAdminInterface());
    }

    // -----------------------------------------------------------------------
    // Bridge service — unicast ignored
    // -----------------------------------------------------------------------

    public function testUnicastPacketInGeneratesNoReply(): void
    {
        $this->oBridge->unicastPacketIn($this->localnetPkt());
        $this->assertEmpty($this->oBridge->getReplies());
    }

    // -----------------------------------------------------------------------
    // Bridge service — malformed broadcast is silently discarded, not thrown
    //
    // docs/protocols/bridge.md: "If the magic string does not match, the
    // packet is silently discarded." BridgeRequest::decode() throws on a bad
    // magic string (see testDecodeInvalidMagicThrows above); broadcastPacketIn()
    // must catch that so one malformed packet on the shared 0x9C/0x9D port
    // doesn't propagate an exception up to the encapsulation handler and tear
    // down the sending connection.
    // -----------------------------------------------------------------------

    public function testBroadcastPacketInDoesNotThrowOnBadMagicString(): void
    {
        $oPkt = new EconetPacket();
        $oPkt->setPort(0x9D);
        $oPkt->setSourceNetwork(1);
        $oPkt->setSourceStation(3);
        $oPkt->setFlags(0);
        $oPkt->setDestinationNetwork(0);
        $oPkt->setDestinationStation(255);
        $oPkt->setData(pack('C', 0x82) . 'BADSTR' . pack('C', 0x9E));

        $this->oBridge->broadcastPacketIn($oPkt);
        $this->assertTrue(true, 'broadcastPacketIn() must not throw for a malformed bridge payload');
    }

    public function testBroadcastPacketInGeneratesNoReplyOnBadMagicString(): void
    {
        $oPkt = new EconetPacket();
        $oPkt->setPort(0x9D);
        $oPkt->setSourceNetwork(1);
        $oPkt->setSourceStation(3);
        $oPkt->setFlags(0);
        $oPkt->setDestinationNetwork(0);
        $oPkt->setDestinationStation(255);
        $oPkt->setData(pack('C', 0x82) . 'BADSTR' . pack('C', 0x9E));

        $this->oBridge->broadcastPacketIn($oPkt);
        $this->assertEmpty($this->oBridge->getReplies());
    }

    // -----------------------------------------------------------------------
    // Bridge service — getReplies drains buffer
    // -----------------------------------------------------------------------

    public function testGetRepliesDrainsBuffer(): void
    {
        $this->dispatch($this->localnetPkt());
        $this->assertEmpty($this->oBridge->getReplies());
    }

    // -----------------------------------------------------------------------
    // EC_BR_LOCALNET
    // -----------------------------------------------------------------------

    public function testLocalnetReplyContainsClientNetworkNumber(): void
    {
        [$oReply] = $this->dispatch($this->localnetPkt(iNet: 42, iStn: 3));
        $aBytes = unpack('C*', $oReply->getData());
        $this->assertSame(42, $aBytes[1]);
    }

    public function testLocalnetReplyVariesBySourceNetwork(): void
    {
        [$oReplyA] = $this->dispatch($this->localnetPkt(iNet: 5,   iStn: 1));
        [$oReplyB] = $this->dispatch($this->localnetPkt(iNet: 129, iStn: 1));
        $aBytesA = unpack('C*', $oReplyA->getData());
        $aBytesB = unpack('C*', $oReplyB->getData());
        $this->assertSame(5,   $aBytesA[1]);
        $this->assertSame(129, $aBytesB[1]);
    }

    public function testLocalnetReplySecondByteIsFirmwareVersion(): void
    {
        [$oReply] = $this->dispatch($this->localnetPkt());
        $aBytes = unpack('C*', $oReply->getData());
        $this->assertSame(128, $aBytes[2]);
    }

    public function testLocalnetReplyDataIsExactlyTwoBytes(): void
    {
        [$oReply] = $this->dispatch($this->localnetPkt());
        $this->assertSame(2, strlen($oReply->getData()));
    }

    public function testLocalnetReplyUsesReplyPortFromPacket(): void
    {
        [$oReply] = $this->dispatch($this->localnetPkt());
        $this->assertSame(0x9E, $oReply->getPort());
    }

    public function testLocalnetReplyRoutedToSourceStation(): void
    {
        [$oReply] = $this->dispatch($this->localnetPkt(iNet: 2, iStn: 15));
        $this->assertSame(15, $oReply->getDestinationStation());
        $this->assertSame(2,  $oReply->getDestinationNetwork());
    }

    public function testLocalnetProducesExactlyOneReply(): void
    {
        $aReplies = $this->dispatch($this->localnetPkt());
        $this->assertCount(1, $aReplies);
    }

    // -----------------------------------------------------------------------
    // EC_BR_NETKNOWN
    // -----------------------------------------------------------------------

    public function testNetknownRepliesWhenNetworkInAunMap(): void
    {
        Map::$aHostMap['5.10'] = '192.168.5.10';
        $aReplies = $this->dispatch($this->netknownPkt(5));
        $this->assertCount(1, $aReplies);
    }

    public function testNetknownNoReplyWhenNetworkUnknown(): void
    {
        $aReplies = $this->dispatch($this->netknownPkt(99));
        $this->assertEmpty($aReplies);
    }

    public function testNetknownRepliesWhenNetworkLearnedFromPeer(): void
    {
        // Teach the bridge about network 7 via a peer query first.
        $this->dispatch($this->queryPkt([7]));
        $this->oBridge->getReplies();   // drain query reply

        $aReplies = $this->dispatch($this->netknownPkt(7));
        $this->assertCount(1, $aReplies);
    }

    public function testNetknownSendsOnlyOneReplyWhenNetworkInBothSources(): void
    {
        // Network 5 is both in the AUN map and in remote networks.
        Map::$aHostMap['5.10'] = '192.168.5.10';
        $this->dispatch($this->queryPkt([5]));
        $this->oBridge->getReplies();

        $aReplies = $this->dispatch($this->netknownPkt(5));
        $this->assertCount(1, $aReplies);
    }

    public function testNetknownReplyRoutedToSourceStation(): void
    {
        Map::$aSubnetMap[3] = '192.168.3.0/24';
        [$oReply] = $this->dispatch($this->netknownPkt(3, iNet: 2, iStn: 8));
        $this->assertSame(8, $oReply->getDestinationStation());
        $this->assertSame(2, $oReply->getDestinationNetwork());
    }

    public function testNetknownMatchesViaSubnetMap(): void
    {
        Map::$aSubnetMap[10] = '10.0.0.0/24';
        $aReplies = $this->dispatch($this->netknownPkt(10));
        $this->assertCount(1, $aReplies);
    }

    public function testNetknownRepliesWhenNetworkInWebSocketMap(): void
    {
        WebSocketMap::$aDynamicNetworks[128] = [];
        $aReplies = $this->dispatch($this->netknownPkt(128));
        $this->assertCount(1, $aReplies);
    }

    public function testNetknownRepliesWhenNetworkInPiconetMap(): void
    {
        PiconetMap::$aNetworks = [3];
        $aReplies = $this->dispatch($this->netknownPkt(3));
        $this->assertCount(1, $aReplies);
    }

    // -----------------------------------------------------------------------
    // EC_BR_QUERY
    // -----------------------------------------------------------------------

    public function testQueryBridgeRecordsAdvertisedNetworks(): void
    {
        $this->dispatch($this->queryPkt([5, 17, 128]));
        $aRemote = $this->oBridge->getRemoteNetworks();
        $this->assertArrayHasKey(5,   $aRemote);
        $this->assertArrayHasKey(17,  $aRemote);
        $this->assertArrayHasKey(128, $aRemote);
    }

    public function testQueryBridgeRemoteNetworkKeyedByPeerAddress(): void
    {
        $this->dispatch($this->queryPkt([99], iNet: 2, iStn: 7));
        $this->assertSame('2.7', $this->oBridge->getRemoteNetworks()[99]);
    }

    public function testQueryBridgeReplyContainsLocalNetworkNumber(): void
    {
        config::overrideValue('bridge_local_network_number', 4);
        [$oReply] = $this->dispatch($this->queryPkt([5]));
        $aBytes = array_values(unpack('C*', $oReply->getData()));
        $this->assertContains(4, $aBytes);
    }

    public function testQueryBridgeReplyExcludesRequesterNetwork(): void
    {
        config::overrideValue('bridge_local_network_number', 4);
        // Peer is on network 1; reply must not include network 1.
        [$oReply] = $this->dispatch($this->queryPkt([5], iNet: 1, iStn: 3));
        $aBytes = array_values(unpack('C*', $oReply->getData()));
        $this->assertNotContains(1, $aBytes);
    }

    public function testQueryBridgeReplyIncludesLearnedNetworks(): void
    {
        config::overrideValue('bridge_local_network_number', 9);
        [$oReply] = $this->dispatch($this->queryPkt([5], iNet: 2, iStn: 3));
        $aBytes = array_values(unpack('C*', $oReply->getData()));
        $this->assertContains(5, $aBytes);
    }

    public function testQueryBridgeAlwaysRepliesEvenWithNoNetworks(): void
    {
        $aReplies = $this->dispatch($this->queryPkt([]));
        $this->assertCount(1, $aReplies);
    }

    public function testQueryBridgeLearnedNetworkUsableByNetknown(): void
    {
        $this->dispatch($this->queryPkt([42]));
        $this->oBridge->getReplies();

        $aReplies = $this->dispatch($this->netknownPkt(42));
        $this->assertCount(1, $aReplies);
    }

    public function testQueryBridgeOverwritesPeerForSameNetwork(): void
    {
        $this->dispatch($this->queryPkt([9], iNet: 1, iStn: 3));
        $this->dispatch($this->queryPkt([9], iNet: 2, iStn: 5));
        $this->oBridge->getReplies();

        $this->assertSame('2.5', $this->oBridge->getRemoteNetworks()[9]);
    }

    // -----------------------------------------------------------------------
    // EC_BR_QUERY2
    // -----------------------------------------------------------------------

    public function testQuery2BridgeRecordsAdvertisedNetworks(): void
    {
        $this->dispatch($this->query2Pkt([3, 9]));
        $aRemote = $this->oBridge->getRemoteNetworks();
        $this->assertArrayHasKey(3, $aRemote);
        $this->assertArrayHasKey(9, $aRemote);
    }

    public function testQuery2BridgeReplies(): void
    {
        $aReplies = $this->dispatch($this->query2Pkt([3]));
        $this->assertCount(1, $aReplies);
    }

    // -----------------------------------------------------------------------
    // getAllKnownNetworkNumbers
    // -----------------------------------------------------------------------

    public function testGetAllKnownNetworkNumbersIncludesLocalNetwork(): void
    {
        config::overrideValue('bridge_local_network_number', 7);
        $this->assertContains(7, $this->oBridge->getAllKnownNetworkNumbers(0));
    }

    public function testGetAllKnownNetworkNumbersExcludesGivenNetwork(): void
    {
        config::overrideValue('bridge_local_network_number', 5);
        $this->assertNotContains(5, $this->oBridge->getAllKnownNetworkNumbers(5));
    }

    public function testGetAllKnownNetworkNumbersIncludesAunSubnetNetworks(): void
    {
        Map::$aSubnetMap[10] = '10.0.10.0/24';
        Map::$aSubnetMap[11] = '10.0.11.0/24';
        $aNets = $this->oBridge->getAllKnownNetworkNumbers(0);
        $this->assertContains(10, $aNets);
        $this->assertContains(11, $aNets);
    }

    public function testGetAllKnownNetworkNumbersIncludesAunHostNetworks(): void
    {
        Map::$aHostMap['20.5'] = '192.168.20.5';
        $this->assertContains(20, $this->oBridge->getAllKnownNetworkNumbers(0));
    }

    public function testGetAllKnownNetworkNumbersIncludesWebSocketNetworks(): void
    {
        WebSocketMap::$aDynamicNetworks[128] = [];
        $this->assertContains(128, $this->oBridge->getAllKnownNetworkNumbers(0));
    }

    public function testGetAllKnownNetworkNumbersIncludesPiconetNetworks(): void
    {
        PiconetMap::$aNetworks = [3, 4];
        $this->assertContains(3, $this->oBridge->getAllKnownNetworkNumbers(0));
        $this->assertContains(4, $this->oBridge->getAllKnownNetworkNumbers(0));
    }

    public function testGetAllKnownNetworkNumbersIncludesRemoteBridgeNetworks(): void
    {
        $oConn = $this->createStub(\HomeLan\FileStore\RemoteBridge\Connection::class);
        RemoteBridgeMap::registerPeerNetworks($oConn, [50, 51]);
        $aNets = $this->oBridge->getAllKnownNetworkNumbers(0);
        $this->assertContains(50, $aNets);
        $this->assertContains(51, $aNets);
    }

    public function testGetAllKnownNetworkNumbersIncludesLearnedRemoteNetworks(): void
    {
        $this->dispatch($this->queryPkt([30], iNet: 2, iStn: 1));
        $this->oBridge->getReplies();
        $this->assertContains(30, $this->oBridge->getAllKnownNetworkNumbers(0));
    }

    public function testGetAllKnownNetworkNumbersDeduplicates(): void
    {
        // Same network visible in both AUN map and piconet — should appear once.
        Map::$aSubnetMap[9] = '192.168.9.0/24';
        PiconetMap::$aNetworks = [9];
        $aNets = $this->oBridge->getAllKnownNetworkNumbers(0);
        $this->assertSame(array_unique($aNets), $aNets);
        $this->assertCount(1, array_filter($aNets, fn($n) => $n === 9));
    }

    public function testGetAllKnownNetworkNumbersReturnsEmptyWhenNothingConfigured(): void
    {
        // setUp sets local network to 1; exclude it → nothing left.
        config::overrideValue('bridge_local_network_number', 0);
        $this->assertSame([], $this->oBridge->getAllKnownNetworkNumbers(0));
    }

    // -----------------------------------------------------------------------
    // getLocalKnownNetworks
    // -----------------------------------------------------------------------

    public function testGetLocalKnownNetworksReturnsEmptyWhenNoMapsPopulated(): void
    {
        config::overrideValue('bridge_local_network_number', 0);
        $this->assertSame([], $this->oBridge->getLocalKnownNetworks());
    }

    public function testGetLocalKnownNetworksIncludesLocalNetworkWithLocalLabel(): void
    {
        config::overrideValue('bridge_local_network_number', 5);
        $aResult = $this->oBridge->getLocalKnownNetworks();
        $this->assertCount(1, $aResult);
        $this->assertSame(5,       $aResult[0]['network']);
        $this->assertSame('local', $aResult[0]['via']);
    }

    public function testGetLocalKnownNetworksIncludesAunNetworkWithAunLabel(): void
    {
        config::overrideValue('bridge_local_network_number', 0);
        Map::$aSubnetMap[10] = '10.0.10.0/24';
        $aResult = $this->oBridge->getLocalKnownNetworks();
        $this->assertCount(1, $aResult);
        $this->assertSame(10,    $aResult[0]['network']);
        $this->assertSame('AUN', $aResult[0]['via']);
    }

    public function testGetLocalKnownNetworksIncludesWebSocketNetworkWithWebSocketLabel(): void
    {
        config::overrideValue('bridge_local_network_number', 0);
        WebSocketMap::$aDynamicNetworks[128] = [];
        $aResult = $this->oBridge->getLocalKnownNetworks();
        $this->assertCount(1, $aResult);
        $this->assertSame(128,         $aResult[0]['network']);
        $this->assertSame('WebSocket', $aResult[0]['via']);
    }

    public function testGetLocalKnownNetworksIncludesPiconetNetworkWithPiconetLabel(): void
    {
        config::overrideValue('bridge_local_network_number', 0);
        PiconetMap::$aNetworks = [3];
        $aResult = $this->oBridge->getLocalKnownNetworks();
        $this->assertCount(1, $aResult);
        $this->assertSame(3,         $aResult[0]['network']);
        $this->assertSame('Piconet', $aResult[0]['via']);
    }

    public function testGetLocalKnownNetworksIncludesRemoteBridgeNetworkWithRemoteBridgeLabel(): void
    {
        config::overrideValue('bridge_local_network_number', 0);
        $oConn = $this->createStub(\HomeLan\FileStore\RemoteBridge\Connection::class);
        RemoteBridgeMap::registerPeerNetworks($oConn, [50]);
        $aResult = $this->oBridge->getLocalKnownNetworks();
        $this->assertCount(1, $aResult);
        $this->assertSame(50,              $aResult[0]['network']);
        $this->assertSame('RemoteBridge',  $aResult[0]['via']);
    }

    public function testGetLocalKnownNetworksDoesNotIncludePeerLearnedNetworks(): void
    {
        config::overrideValue('bridge_local_network_number', 0);
        // Teach the bridge about network 42 via a peer query.
        $this->dispatch($this->queryPkt([42], iNet: 2, iStn: 1));
        $this->oBridge->getReplies();

        $aNetworks = array_column($this->oBridge->getLocalKnownNetworks(), 'network');
        $this->assertNotContains(42, $aNetworks);
    }

    public function testGetLocalKnownNetworksDeduplicatesByNetworkNumber(): void
    {
        config::overrideValue('bridge_local_network_number', 0);
        // Network 9 in both AUN and Piconet — should appear once.
        Map::$aSubnetMap[9] = '192.168.9.0/24';
        PiconetMap::$aNetworks = [9];
        $aResult = $this->oBridge->getLocalKnownNetworks();
        $this->assertCount(1, array_filter($aResult, fn($r) => $r['network'] === 9));
    }
}
