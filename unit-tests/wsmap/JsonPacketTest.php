<?php

/*
 * @group unit-tests
 *
 * Unit tests for HomeLan\FileStore\WebSocket\JsonPacket covering all
 * public methods not exercised indirectly by WebSocketHandlerTest.
 *
 * JsonPacket is constructed with a Ratchet\ConnectionInterface (used only
 * when buildAck() allocates a dynamic address for ctrl messages).
 * All other paths receive a simple stub that is never inspected.
 */

include_once(__DIR__ . '/../../src/include/system.inc.php');

use PHPUnit\Framework\TestCase;
use Monolog\Logger;
use Monolog\Handler\NullHandler;
use HomeLan\FileStore\WebSocket\JsonPacket;
use HomeLan\FileStore\WebSocket\Map as WebSocketMap;
use HomeLan\FileStore\Aun\Map as AunMap;
use HomeLan\FileStore\Messages\EconetPacket;
use Ratchet\ConnectionInterface;

class JsonPacketTest extends TestCase
{
    private ConnectionInterface $oConn;

    protected function setUp(): void
    {
        // Reset WebSocket\Map and Aun\Map static state
        foreach (['aDynamicNetworks', 'aSocketList'] as $sProp) {
            $rp = new \ReflectionProperty(WebSocketMap::class, $sProp);
            $rp->setAccessible(true);
            $rp->setValue(null, []);
        }
        foreach (['aHostMap', 'aSubnetMap', 'aIPLookupCache', 'aIpCounter'] as $sProp) {
            $rp = new \ReflectionProperty(AunMap::class, $sProp);
            $rp->setAccessible(true);
            $rp->setValue(null, []);
        }

        WebSocketMap::addDynamicRangeNetwork(128);

        $this->oConn = $this->createStub(ConnectionInterface::class);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * Build a JSON-encoded 'pkt' message.
     *
     * All payload bytes must be ≤ 0x7F for valid UTF-8 in JSON.
     */
    private function makePktJson(
        int    $iDstNet  = 128,
        int    $iDstStn  = 254,
        int    $iSrcNet  = 1,
        int    $iSrcStn  = 1,
        int    $iAunType = 2,      // 2=Unicast
        int    $iPort    = 0x01,   // must be ≤ 0x7F
        int    $iCb      = 0,
        int    $iSeq     = 42,
        string $sData    = 'hello'
    ): string {
        $sPayload = pack('CCCCV', $iAunType, $iPort, $iCb, 0, $iSeq) . $sData;
        return json_encode([
            'type'    => 'pkt',
            'src'     => ['station' => $iSrcStn, 'network' => $iSrcNet],
            'dst'     => ['station' => $iDstStn, 'network' => $iDstNet],
            'payload' => $sPayload,
        ], JSON_THROW_ON_ERROR);
    }

    private function makeCtrlJson(string $sRequest = 'dynamic_alloction_request', array $aArgs = []): string
    {
        return json_encode([
            'type'    => 'ctrl',
            'request' => $sRequest,
            'args'    => $aArgs,
        ], JSON_THROW_ON_ERROR);
    }

    private function decodePacket(string $sJson): JsonPacket
    {
        $oPkt = new JsonPacket($this->oConn);
        $oPkt->decode($sJson);
        return $oPkt;
    }

    // =========================================================================
    // decode() — 'pkt' type
    // =========================================================================

    public function testDecodeUnicastSetsTypeToPkt(): void
    {
        $oPkt = $this->decodePacket($this->makePktJson(iAunType: 2));
        $this->assertSame('pkt', $oPkt->getType());
    }

    public function testDecodeUnicastSetsAunPacketTypeToUnicast(): void
    {
        $oPkt = $this->decodePacket($this->makePktJson(iAunType: 2));
        $this->assertSame('Unicast', $oPkt->getPacketType());
    }

    public function testDecodeBroadcastSetsAunPacketTypeToBroadcast(): void
    {
        $oPkt = $this->decodePacket($this->makePktJson(iAunType: 1));
        $this->assertSame('Broadcast', $oPkt->getPacketType());
    }

    public function testDecodeImmediateSetsAunPacketTypeToImmediate(): void
    {
        $oPkt = $this->decodePacket($this->makePktJson(iAunType: 5, iCb: 0));
        $this->assertSame('Immediate', $oPkt->getPacketType());
    }

    public function testDecodeAckSetsAunPacketTypeToAck(): void
    {
        $oPkt = $this->decodePacket($this->makePktJson(iAunType: 3));
        $this->assertSame('Ack', $oPkt->getPacketType());
    }

    public function testDecodeSetsDstStation(): void
    {
        $oPkt = $this->decodePacket($this->makePktJson(iDstStn: 42));
        $this->assertSame(42, $oPkt->getDstStation());
    }

    public function testDecodeSetsDstNetwork(): void
    {
        $oPkt = $this->decodePacket($this->makePktJson(iDstNet: 5));
        $this->assertSame(5, $oPkt->getDstNetwork());
    }

    public function testDecodeSetsPort(): void
    {
        $oPkt = $this->decodePacket($this->makePktJson(iPort: 0x7F));
        $this->assertSame(0x7F, $oPkt->getPort());
    }

    public function testDecodeSetsData(): void
    {
        $oPkt = $this->decodePacket($this->makePktJson(sData: 'world'));
        $this->assertSame('world', $oPkt->getData());
    }

    public function testDecodeStoresEmptyDataWhenPayloadHasNoBody(): void
    {
        $oPkt = $this->decodePacket($this->makePktJson(sData: ''));
        $this->assertSame('', $oPkt->getData());
    }

    // =========================================================================
    // decode() — 'ctrl' type
    // =========================================================================

    public function testDecodeCtrlSetsTypeToCtrl(): void
    {
        $oPkt = $this->decodePacket($this->makeCtrlJson());
        $this->assertSame('ctrl', $oPkt->getType());
    }

    public function testDecodeInvalidJsonThrows(): void
    {
        $this->expectException(\JsonException::class);
        $oPkt = new JsonPacket($this->oConn);
        $oPkt->decode('not-valid-json');
    }

    public function testDecodeInvalidTypeThrows(): void
    {
        $this->expectException(\Exception::class);
        $oPkt = new JsonPacket($this->oConn);
        $oPkt->decode(json_encode(['type' => 'unknown']));
    }

    // =========================================================================
    // buildAck() — Unicast pkt
    // =========================================================================

    public function testBuildAckUnicastReturnsJson(): void
    {
        $oPkt = $this->decodePacket($this->makePktJson(iAunType: 2, iSeq: 55));
        $sAck = $oPkt->buildAck();
        $this->assertNotNull($sAck);
        $aDecoded = json_decode($sAck, true);
        $this->assertIsArray($aDecoded);
    }

    public function testBuildAckUnicastHasPktTypeInJson(): void
    {
        $oPkt = $this->decodePacket($this->makePktJson(iAunType: 2));
        $aDecoded = json_decode($oPkt->buildAck(), true);
        $this->assertSame('pkt', $aDecoded['type']);
    }

    public function testBuildAckUnicastPayloadStartsWithAckTypeByte(): void
    {
        $oPkt = $this->decodePacket($this->makePktJson(iAunType: 2, iSeq: 33));
        $aDecoded = json_decode($oPkt->buildAck(), true);
        $aType = unpack('C', $aDecoded['payload']);
        $this->assertSame(3, $aType[1]);
    }

    public function testBuildAckUnicastEchoesSequenceNumber(): void
    {
        $oPkt = $this->decodePacket($this->makePktJson(iAunType: 2, iSeq: 99));
        $aDecoded = json_decode($oPkt->buildAck(), true);
        $aSeq = unpack('V', substr($aDecoded['payload'], 4, 4));
        $this->assertSame(99, (int) $aSeq[1]);
    }

    public function testBuildAckUnicastAddressesReplyToOriginalSource(): void
    {
        $oPkt = $this->decodePacket($this->makePktJson(iSrcNet: 3, iSrcStn: 7));
        $aDecoded = json_decode($oPkt->buildAck(), true);
        $this->assertSame(3, $aDecoded['dst']['network']);
        $this->assertSame(7, $aDecoded['dst']['station']);
    }

    // =========================================================================
    // buildAck() — Broadcast pkt (no ack payload expected)
    // =========================================================================

    public function testBuildAckBroadcastReturnsJsonWithNullPayload(): void
    {
        $oPkt = $this->decodePacket($this->makePktJson(iAunType: 1));
        $aDecoded = json_decode($oPkt->buildAck(), true);
        $this->assertNull($aDecoded['payload']);
    }

    // =========================================================================
    // buildAck() — Immediate pkt responses
    // =========================================================================

    public function testBuildAckImmediateMachineTypeReturnsImmediateReplyType(): void
    {
        $oPkt = $this->decodePacket($this->makePktJson(iAunType: 5, iCb: 0));
        $aDecoded = json_decode($oPkt->buildAck(), true);
        $aType = unpack('C', $aDecoded['payload']);
        $this->assertSame(6, $aType[1]);
    }

    public function testBuildAckImmediateMachineTypeByteIdentifiesAsFileStore(): void
    {
        $oPkt = $this->decodePacket($this->makePktJson(iAunType: 5, iCb: 0));
        $aDecoded = json_decode($oPkt->buildAck(), true);
        $iMachineType = unpack('C', substr($aDecoded['payload'], 8, 1))[1];
        $this->assertSame(0x40, $iMachineType);
    }

    public function testBuildAckImmediateOsVersionReturnsImmediateReply(): void
    {
        $oPkt = $this->decodePacket($this->makePktJson(iAunType: 5, iCb: 1));
        $aDecoded = json_decode($oPkt->buildAck(), true);
        $aType = unpack('C', $aDecoded['payload']);
        $this->assertSame(6, $aType[1]);
    }

    public function testBuildAckImmediateEchoReturnsImmediateReply(): void
    {
        $oPkt = $this->decodePacket($this->makePktJson(iAunType: 5, iCb: 8));
        $aDecoded = json_decode($oPkt->buildAck(), true);
        $aType = unpack('C', $aDecoded['payload']);
        $this->assertSame(6, $aType[1]);
    }

    // =========================================================================
    // buildAck() — ctrl
    // =========================================================================

    public function testBuildAckCtrlDynamicAllocRequestReturnsCtrlType(): void
    {
        $oPkt = $this->decodePacket($this->makeCtrlJson('dynamic_alloction_request'));
        $aDecoded = json_decode($oPkt->buildAck(), true);
        $this->assertSame('ctrl', $aDecoded['type']);
    }

    public function testBuildAckCtrlDynamicAllocRequestAllocatesAnAddress(): void
    {
        $oPkt = $this->decodePacket($this->makeCtrlJson('dynamic_alloction_request'));
        $aDecoded = json_decode($oPkt->buildAck(), true);
        $this->assertNotEmpty($aDecoded['response']);
    }

    public function testBuildAckCtrlUnknownRequestReturnsNull(): void
    {
        $oPkt = $this->decodePacket($this->makeCtrlJson('unknown_request'));
        $this->assertNull($oPkt->buildAck());
    }

    // =========================================================================
    // buildEconetPacket()
    // =========================================================================

    public function testBuildEconetPacketReturnEconetPacketInstance(): void
    {
        $oPkt = $this->decodePacket($this->makePktJson(iPort: 0x20, iCb: 0, sData: 'abc'));
        $this->assertInstanceOf(EconetPacket::class, $oPkt->buildEconetPacket());
    }

    public function testBuildEconetPacketSetsPort(): void
    {
        $oPkt = $this->decodePacket($this->makePktJson(iPort: 0x20));
        $this->assertSame(0x20, $oPkt->buildEconetPacket()->getPort());
    }

    public function testBuildEconetPacketSetsFlags(): void
    {
        $oPkt = $this->decodePacket($this->makePktJson(iCb: 5));
        $this->assertSame(5, $oPkt->buildEconetPacket()->getFlags());
    }

    public function testBuildEconetPacketSetsSourceNetwork(): void
    {
        $oPkt = $this->decodePacket($this->makePktJson(iSrcNet: 3));
        $this->assertSame(3, $oPkt->buildEconetPacket()->getSourceNetwork());
    }

    public function testBuildEconetPacketSetsSourceStation(): void
    {
        $oPkt = $this->decodePacket($this->makePktJson(iSrcStn: 7));
        $this->assertSame(7, $oPkt->buildEconetPacket()->getSourceStation());
    }

    public function testBuildEconetPacketSetsData(): void
    {
        $oPkt = $this->decodePacket($this->makePktJson(sData: 'payload'));
        $this->assertSame('payload', $oPkt->buildEconetPacket()->getData());
    }

    // =========================================================================
    // toString()
    // =========================================================================

    public function testToStringReturnsAString(): void
    {
        $oPkt = $this->decodePacket($this->makePktJson(iAunType: 2, iPort: 0x01));
        $this->assertIsString($oPkt->toString());
    }

    public function testToStringContainsPacketTypeName(): void
    {
        $oPkt = $this->decodePacket($this->makePktJson(iAunType: 2));
        $this->assertStringContainsString('Unicast', $oPkt->toString());
    }

    public function testToStringContainsPortNumber(): void
    {
        $oPkt = $this->decodePacket($this->makePktJson(iPort: 0x10));
        $this->assertStringContainsString('16', $oPkt->toString());
    }

    public function testToStringContainsHeaderLabel(): void
    {
        $oPkt = $this->decodePacket($this->makePktJson());
        $this->assertStringContainsString('Header', $oPkt->toString());
    }
}
