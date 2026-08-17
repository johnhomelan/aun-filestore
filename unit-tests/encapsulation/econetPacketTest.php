<?php

/*
 * @group unit-tests
 *
 * Tests for HomeLan\FileStore\Messages\EconetPacket wire framing.
 *
 * getAunFrame() and getWebSocketFrame() both build their raw AUN header via
 * the private _getAunRaw() helper, which must mark the packet as AUN type 1
 * (Broadcast) when the destination station is 255, and type 2 (Unicast)
 * otherwise.
 */

include_once(__DIR__ . '/../../src/include/system.inc.php');

use PHPUnit\Framework\TestCase;
use HomeLan\FileStore\Messages\EconetPacket;
use HomeLan\FileStore\Aun\Map as AunMap;
use HomeLan\FileStore\Aun\HandleInterface;
use Monolog\Logger;
use Monolog\Handler\NullHandler;

class EconetPacketTest extends TestCase
{
    protected function setUp(): void
    {
        $oLogger = new Logger('test');
        $oLogger->pushHandler(new NullHandler());

        // Map network 1 to a /24 so ecoAddrToIpAddr() resolves for any station,
        // including 255 (the broadcast address of that subnet).
        AunMap::init($oLogger, $this->createMock(HandleInterface::class), "192.168.0.0/24 1\n");
    }

    protected function tearDown(): void
    {
        foreach (['aHostMap', 'aSubnetMap', 'aIPLookupCache', 'aIpCounter'] as $sProp) {
            $rp = new \ReflectionProperty(AunMap::class, $sProp);
            $rp->setAccessible(true);
            $rp->setValue(null, []);
        }
    }

    private function makePacket(int $iDstStn): EconetPacket
    {
        $oPkt = new EconetPacket();
        $oPkt->setFlags(0x00);
        $oPkt->setPort(0x01);
        $oPkt->setSourceNetwork(1);
        $oPkt->setSourceStation(1);
        $oPkt->setDestinationNetwork(1);
        $oPkt->setDestinationStation($iDstStn);
        $oPkt->setData('hello');
        return $oPkt;
    }

    public function testAunFrameMarksBroadcastDestinationAsBroadcastType(): void
    {
        $sFrame = $this->makePacket(255)->getAunFrame();
        $aType = unpack('C', $sFrame);
        $this->assertSame(1, $aType[1], 'AUN frame for destination station 255 must be type 1 (Broadcast)');
    }

    public function testAunFrameMarksUnicastDestinationAsUnicastType(): void
    {
        $sFrame = $this->makePacket(100)->getAunFrame();
        $aType = unpack('C', $sFrame);
        $this->assertSame(2, $aType[1], 'AUN frame for a regular station must be type 2 (Unicast)');
    }

    public function testWebSocketFrameMarksBroadcastDestinationAsBroadcastType(): void
    {
        $sJson = $this->makePacket(255)->getWebSocketFrame();
        $aDecoded = json_decode($sJson, true);
        $aType = unpack('C', base64_decode($aDecoded['payload']));
        $this->assertSame(1, $aType[1], 'WebSocket frame for destination station 255 must be type 1 (Broadcast)');
    }

    public function testWebSocketFrameMarksUnicastDestinationAsUnicastType(): void
    {
        $sJson = $this->makePacket(100)->getWebSocketFrame();
        $aDecoded = json_decode($sJson, true);
        $aType = unpack('C', base64_decode($aDecoded['payload']));
        $this->assertSame(2, $aType[1], 'WebSocket frame for a regular station must be type 2 (Unicast)');
    }
}
