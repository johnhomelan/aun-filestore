<?php

/*
 * @group unit-tests
 *
 * Tests for the IPv4 NAT class:
 *   - NAT table loading and lookup (isNatTarget, getNatEntry)
 *   - Conntrack entry registration (_registerConnection)
 *   - Data forwarding from external socket to Econet (_socketDataIn)
 *   - Socket event handling (_socketEnd, _socketError, _socketClose)
 *   - Housekeeping: timed-out connection cleanup
 *   - processNatPacket: non-SYN for unknown connection ignored
 *   - processNatPacket: data packet with existing conntrack forwarded to socket
 */

include_once(__DIR__.'/../../src/include/system.inc.php');

use PHPUnit\Framework\TestCase;
use Monolog\Logger;
use Monolog\Handler\NullHandler;
use HomeLan\FileStore\Messages\EconetPacket;
use HomeLan\FileStore\Messages\IPv4Request;
use HomeLan\FileStore\Messages\TCPRequest;
use HomeLan\FileStore\Services\Provider\IPv4\NAT;
use HomeLan\FileStore\Services\Provider\IPv4;
use HomeLan\FileStore\Services\Provider\IPv4\Exceptions\NatException;

class natTest extends TestCase {

    protected function setup(): void
    {
        $oProvider = $this->createMock(IPv4::class);
        $sNatFile = "192.168.1.1 192.168.0.1 200 23\n192.168.1.2 192.168.0.2 23 23\n192.168.1.3 192.168.0.3 200 1024";

        $oLogger = new Logger("filestored-unittests");
        $oLogger->pushHandler(new NullHandler());

        $this->oNAT = new NAT($oProvider,$oLogger,$sNatFile);
    }

    // -----------------------------------------------------------------------
    // NAT table loading
    // -----------------------------------------------------------------------

    public function testAllValidRoutesRead()
    {
        $this->assertEquals(3, count($this->oNAT->dumpNatTable()));
    }

    // -----------------------------------------------------------------------
    // isNatTarget
    // -----------------------------------------------------------------------

    public function testIsNAT()
    {
        $this->assertTrue($this->oNAT->isNatTarget('192.168.1.1'));
        $this->assertTrue($this->oNAT->isNatTarget('192.168.1.2'));
        $this->assertTrue($this->oNAT->isNatTarget('192.168.1.3'));
        $this->assertFalse($this->oNAT->isNatTarget('192.168.1.4'));
    }

    // -----------------------------------------------------------------------
    // getNatEntry
    // -----------------------------------------------------------------------

    public function testGetNatEntryReturnsCorrectDestination(): void
    {
        $aEntry = $this->oNAT->getNatEntry('192.168.1.1', 200);
        $this->assertSame('192.168.0.1', $aEntry['ip_to']);
        $this->assertSame(23, $aEntry['port_to']);
    }

    public function testGetNatEntryThrowsForUnknownIp(): void
    {
        $this->expectException(NatException::class);
        $this->oNAT->getNatEntry('10.0.0.99', 23);
    }

    public function testGetNatEntryThrowsForWrongPort(): void
    {
        $this->expectException(NatException::class);
        $this->oNAT->getNatEntry('192.168.1.1', 9999);
    }

    // -----------------------------------------------------------------------
    // Helpers shared by connection-tracking tests
    // -----------------------------------------------------------------------

    /**
     * Returns [$oNat, $oProvider, $oLogger] with a mock provider that
     * has getLogger() wired up. The NAT table has one entry: 192.168.1.1:23.
     */
    private function buildNatWithMockProvider(): array
    {
        $oLogger = new Logger('test');
        $oLogger->pushHandler(new NullHandler());

        $oProvider = $this->createMock(IPv4::class);
        $oProvider->method('getLogger')->willReturn($oLogger);

        $oNat = new NAT($oProvider, $oLogger, "192.168.1.1 192.168.0.1 23 23\n");
        return [$oNat, $oProvider, $oLogger];
    }

    /**
     * Builds a minimal conntrack entry for 192.168.0.5 → 192.168.1.1:23.
     */
    private function makeConntrackEntry(object $oSocket, string $sState = 'connecting', int $iSeq = 1000): array
    {
        return [
            'srcip'         => '192.168.0.5',
            'dstip'         => '192.168.1.1',
            'srcport'       => 12345,
            'dstport'       => 23,
            'pktid'         => 1,
            'window_to'     => 65535,
            'sequence'      => $iSeq,
            'ack'           => 0,
            'state'         => $sState,
            'last_activity' => time(),
            'sequence_sock' => 500,
            'socket'        => $oSocket,
        ];
    }

    /**
     * Injects a conntrack entry directly into NAT's private $aConnTrack,
     * bypassing _registerConnection (which would send a SYN-ACK).
     */
    private function injectConntrack(NAT $oNat, string $sKey, array $aEntry): void
    {
        $oRef  = new \ReflectionClass($oNat);
        $oProp = $oRef->getProperty('aConnTrack');
        $oProp->setAccessible(true);
        $oProp->setValue($oNat, [$sKey => $aEntry]);
    }

    /**
     * Builds an EconetPacket containing a valid IPv4+TCP frame.
     */
    private function buildTcpEconetPacket(
        string $sSrcIP    = '192.168.0.5',
        string $sDstIP    = '192.168.1.1',
        int    $iSrcPort  = 12345,
        int    $iDstPort  = 23,
        int    $iSeq      = 1000,
        int    $iAck      = 0,
        int    $iTcpFlags = 0x02,   // SYN
        int    $iWindow   = 65535,
        string $sPayload  = '',
        int    $iPktId    = 42
    ): EconetPacket {
        $iTotalLen = 40 + strlen($sPayload);

        $sIPHdr  = chr(0x45);
        $sIPHdr .= chr(0x00);
        $sIPHdr .= pack('n', $iTotalLen);
        $sIPHdr .= pack('n', $iPktId);
        $sIPHdr .= pack('n', 0x4000);
        $sIPHdr .= chr(64);
        $sIPHdr .= chr(0x06);
        $sIPHdr .= pack('n', 0);
        $sIPHdr .= inet_pton($sSrcIP);
        $sIPHdr .= inet_pton($sDstIP);

        $sTCPHdr  = pack('n', $iSrcPort);
        $sTCPHdr .= pack('n', $iDstPort);
        $sTCPHdr .= pack('N', $iSeq);
        $sTCPHdr .= pack('N', $iAck);
        $sTCPHdr .= chr(0x50);
        $sTCPHdr .= chr($iTcpFlags);
        $sTCPHdr .= pack('n', $iWindow);
        $sTCPHdr .= pack('n', 0);
        $sTCPHdr .= pack('n', 0);

        $oPkt = new EconetPacket();
        $oPkt->setFlags(0x01);
        $oPkt->setSourceNetwork(1);
        $oPkt->setSourceStation(5);
        $oPkt->setDestinationNetwork(1);
        $oPkt->setDestinationStation(24);
        $oPkt->setPort(0xD2);
        $oPkt->setData($sIPHdr . $sTCPHdr . $sPayload);
        return $oPkt;
    }

    // -----------------------------------------------------------------------
    // _registerConnection
    // -----------------------------------------------------------------------

    public function testRegisterConnectionSendsSynAckToProvider(): void
    {
        [$oNat, $oProvider] = $this->buildNatWithMockProvider();
        $oMockSocket = $this->createMock(\React\Socket\ConnectionInterface::class);

        $oProvider->expects($this->once())->method('processUnicastIPv4Pkt');

        $sKey = '192.168.0.5_192.168.1.1_12345_23';
        $oNat->_registerConnection($sKey, $this->makeConntrackEntry($oMockSocket));
    }

    public function testRegisterConnectionSetsStateToConnected(): void
    {
        [$oNat, $oProvider] = $this->buildNatWithMockProvider();
        $oMockSocket = $this->createMock(\React\Socket\ConnectionInterface::class);
        $oProvider->method('processUnicastIPv4Pkt');

        $sKey = '192.168.0.5_192.168.1.1_12345_23';
        $oNat->_registerConnection($sKey, $this->makeConntrackEntry($oMockSocket));

        $aConnTrack = $oNat->dumpConnTrack();
        $this->assertSame('connected', $aConnTrack[$sKey]['state']);
    }

    // -----------------------------------------------------------------------
    // _socketDataIn
    // -----------------------------------------------------------------------

    public function testSocketDataInForwardsDataToProvider(): void
    {
        [$oNat, $oProvider] = $this->buildNatWithMockProvider();
        $oMockSocket = $this->createMock(\React\Socket\ConnectionInterface::class);
        $oProvider->expects($this->once())->method('processUnicastIPv4Pkt');

        $sKey = '192.168.0.5_192.168.1.1_12345_23';
        $this->injectConntrack($oNat, $sKey, $this->makeConntrackEntry($oMockSocket, 'connected'));

        $oNat->_socketDataIn($sKey, 'hello from server');
    }

    public function testSocketDataInUpdatesSequenceSock(): void
    {
        [$oNat, $oProvider] = $this->buildNatWithMockProvider();
        $oMockSocket = $this->createMock(\React\Socket\ConnectionInterface::class);
        $oProvider->method('processUnicastIPv4Pkt');

        $sKey = '192.168.0.5_192.168.1.1_12345_23';
        $this->injectConntrack($oNat, $sKey, $this->makeConntrackEntry($oMockSocket, 'connected'));

        $oNat->_socketDataIn($sKey, 'hello');
        $aConnTrack = $oNat->dumpConnTrack();
        // sequence_sock was 500; 5 bytes received → should now be 505
        $this->assertSame(505, $aConnTrack[$sKey]['sequence_sock']);
    }

    // -----------------------------------------------------------------------
    // _socketEnd
    // -----------------------------------------------------------------------

    public function testSocketEndSendsFinalPacketToProvider(): void
    {
        [$oNat, $oProvider] = $this->buildNatWithMockProvider();
        $oMockSocket = $this->createMock(\React\Socket\ConnectionInterface::class);
        $oProvider->expects($this->once())->method('processUnicastIPv4Pkt');

        $sKey = '192.168.0.5_192.168.1.1_12345_23';
        $this->injectConntrack($oNat, $sKey, $this->makeConntrackEntry($oMockSocket, 'connected'));

        $oNat->_socketEnd($sKey);
    }

    public function testSocketEndRemovesConntrackEntry(): void
    {
        [$oNat, $oProvider] = $this->buildNatWithMockProvider();
        $oMockSocket = $this->createMock(\React\Socket\ConnectionInterface::class);
        $oProvider->method('processUnicastIPv4Pkt');

        $sKey = '192.168.0.5_192.168.1.1_12345_23';
        $this->injectConntrack($oNat, $sKey, $this->makeConntrackEntry($oMockSocket, 'connected'));

        $oNat->_socketEnd($sKey);
        $this->assertArrayNotHasKey($sKey, $oNat->dumpConnTrack());
    }

    // -----------------------------------------------------------------------
    // _socketError
    // -----------------------------------------------------------------------

    public function testSocketErrorSendsResetToProvider(): void
    {
        [$oNat, $oProvider] = $this->buildNatWithMockProvider();
        $oMockSocket = $this->createMock(\React\Socket\ConnectionInterface::class);
        $oProvider->expects($this->once())->method('processUnicastIPv4Pkt');

        $sKey = '192.168.0.5_192.168.1.1_12345_23';
        $this->injectConntrack($oNat, $sKey, $this->makeConntrackEntry($oMockSocket, 'connected'));

        $oNat->_socketError($sKey, new \Exception('connection reset by peer'));
    }

    public function testSocketErrorRemovesConntrackEntry(): void
    {
        [$oNat, $oProvider] = $this->buildNatWithMockProvider();
        $oMockSocket = $this->createMock(\React\Socket\ConnectionInterface::class);
        $oProvider->method('processUnicastIPv4Pkt');

        $sKey = '192.168.0.5_192.168.1.1_12345_23';
        $this->injectConntrack($oNat, $sKey, $this->makeConntrackEntry($oMockSocket, 'connected'));

        $oNat->_socketError($sKey, new \Exception('error'));
        $this->assertArrayNotHasKey($sKey, $oNat->dumpConnTrack());
    }

    // -----------------------------------------------------------------------
    // _socketClose
    // -----------------------------------------------------------------------

    public function testSocketCloseRemovesConntrackEntry(): void
    {
        [$oNat, $oProvider] = $this->buildNatWithMockProvider();
        $oMockSocket = $this->createMock(\React\Socket\ConnectionInterface::class);
        $oProvider->method('processUnicastIPv4Pkt');

        $sKey = '192.168.0.5_192.168.1.1_12345_23';
        $this->injectConntrack($oNat, $sKey, $this->makeConntrackEntry($oMockSocket, 'connected'));

        $oNat->_socketClose($sKey);
        $this->assertArrayNotHasKey($sKey, $oNat->dumpConnTrack());
    }

    public function testSocketCloseForUnknownKeyIsHarmless(): void
    {
        [$oNat] = $this->buildNatWithMockProvider();
        // Should not throw
        $oNat->_socketClose('nonexistent_key');
        $this->assertEmpty($oNat->dumpConnTrack());
    }

    // -----------------------------------------------------------------------
    // houseKeeping
    // -----------------------------------------------------------------------

    public function testHouseKeepingClosesTimedOutConnection(): void
    {
        [$oNat, $oProvider] = $this->buildNatWithMockProvider();
        $oProvider->method('processUnicastIPv4Pkt');

        $oMockSocket = $this->createMock(\React\Socket\ConnectionInterface::class);
        $oMockSocket->expects($this->once())->method('close');

        $sKey   = '192.168.0.5_192.168.1.1_12345_23';
        $aEntry = $this->makeConntrackEntry($oMockSocket, 'connected');
        $aEntry['last_activity'] = time() - 200; // 200 s ago, exceeds DEFAULT_TIMEOUT(120)
        $this->injectConntrack($oNat, $sKey, $aEntry);

        $oNat->houseKeeping();
    }

    public function testHouseKeepingDoesNotCloseActiveConnection(): void
    {
        [$oNat] = $this->buildNatWithMockProvider();

        $oMockSocket = $this->createMock(\React\Socket\ConnectionInterface::class);
        $oMockSocket->expects($this->never())->method('close');

        $sKey   = '192.168.0.5_192.168.1.1_12345_23';
        $aEntry = $this->makeConntrackEntry($oMockSocket, 'connected');
        $aEntry['last_activity'] = time(); // fresh
        $this->injectConntrack($oNat, $sKey, $aEntry);

        $oNat->houseKeeping();
    }

    // -----------------------------------------------------------------------
    // processNatPacket
    // -----------------------------------------------------------------------

    public function testProcessNatPacketIgnoresNonSynForUnknownConnection(): void
    {
        [$oNat, $oProvider, $oLogger] = $this->buildNatWithMockProvider();
        $oProvider->expects($this->never())->method('processUnicastIPv4Pkt');

        // ACK packet (not SYN) for a connection not yet in conntrack
        $oEconetPkt = $this->buildTcpEconetPacket(
            sDstIP: '192.168.1.1',
            iDstPort: 23,
            iTcpFlags: 0x10  // ACK only
        );
        $oIPv4 = new IPv4Request($oEconetPkt, $oLogger);
        $oTcp  = new TCPRequest($oEconetPkt, $oLogger);

        $oNat->processNatPacket($oIPv4, $oTcp);
    }

    public function testProcessNatPacketIgnoresPacketForNonNatTarget(): void
    {
        [$oNat, $oProvider, $oLogger] = $this->buildNatWithMockProvider();
        $oProvider->expects($this->never())->method('processUnicastIPv4Pkt');

        // SYN to an IP that is NOT in the NAT table
        $oEconetPkt = $this->buildTcpEconetPacket(
            sDstIP: '10.0.0.99',
            iDstPort: 23,
            iTcpFlags: 0x02  // SYN
        );
        $oIPv4 = new IPv4Request($oEconetPkt, $oLogger);
        $oTcp  = new TCPRequest($oEconetPkt, $oLogger);

        $oNat->processNatPacket($oIPv4, $oTcp);
    }

    public function testProcessNatPacketForwardsDataPacketViaSocket(): void
    {
        [$oNat, $oProvider, $oLogger] = $this->buildNatWithMockProvider();
        $oProvider->method('processUnicastIPv4Pkt');

        $oMockSocket = $this->createMock(\React\Socket\ConnectionInterface::class);
        $oMockSocket->expects($this->once())->method('write')->with('payload');

        // Pre-inject a conntrack entry so no socket connection is needed
        $sKey   = '192.168.0.5_192.168.1.1_12345_23';
        $aEntry = $this->makeConntrackEntry($oMockSocket, 'connected', iSeq: 1000);
        $this->injectConntrack($oNat, $sKey, $aEntry);

        // Data packet: seq=1500 (not 1000 or 1001, so it passes the duplicate/ack-only checks)
        $oEconetPkt = $this->buildTcpEconetPacket(
            sDstIP: '192.168.1.1',
            iDstPort: 23,
            iSeq: 1500,
            iTcpFlags: 0x18, // ACK + PSH
            sPayload: 'payload'
        );
        $oIPv4 = new IPv4Request($oEconetPkt, $oLogger);
        $oTcp  = new TCPRequest($oEconetPkt, $oLogger);

        $oNat->processNatPacket($oIPv4, $oTcp);
    }

    public function testProcessNatPacketDropsDuplicateSequence(): void
    {
        [$oNat, $oProvider, $oLogger] = $this->buildNatWithMockProvider();
        $oProvider->expects($this->never())->method('processUnicastIPv4Pkt');

        $oMockSocket = $this->createMock(\React\Socket\ConnectionInterface::class);
        $oMockSocket->expects($this->never())->method('write');

        // Inject entry with sequence=1000
        $sKey   = '192.168.0.5_192.168.1.1_12345_23';
        $aEntry = $this->makeConntrackEntry($oMockSocket, 'connected', iSeq: 1000);
        $this->injectConntrack($oNat, $sKey, $aEntry);

        // Duplicate: seq == stored sequence
        $oEconetPkt = $this->buildTcpEconetPacket(
            sDstIP: '192.168.1.1',
            iDstPort: 23,
            iSeq: 1000, // duplicate
            iTcpFlags: 0x10
        );
        $oIPv4 = new IPv4Request($oEconetPkt, $oLogger);
        $oTcp  = new TCPRequest($oEconetPkt, $oLogger);

        $oNat->processNatPacket($oIPv4, $oTcp);
    }
}
