<?php

/*
 * @group unit-tests
 *
 * Tests for all ARP message classes:
 *   ArpRequest    – DCI-2 who-has decode and reply construction
 *   ArpReply      – DCI-2 is-at packet build
 *   Dci4ArpRequest – DCI-4 who-has decode and reply construction
 *   Dci4ArpReply  – DCI-4 is-at packet build
 *   ArpIsAt       – is-at decode for both DCI-2 (0x22) and DCI-4 (0xA2)
 *   ArpWhoHas     – outbound who-has broadcast build
 */

include_once(__DIR__.'/../../src/include/system.inc.php');

use PHPUnit\Framework\TestCase;
use Monolog\Logger;
use Monolog\Handler\NullHandler;
use HomeLan\FileStore\Messages\EconetPacket;
use HomeLan\FileStore\Messages\ArpRequest;
use HomeLan\FileStore\Messages\ArpReply;
use HomeLan\FileStore\Messages\ArpIsAt;
use HomeLan\FileStore\Messages\ArpWhoHas;
use HomeLan\FileStore\Messages\Dci4ArpRequest;
use HomeLan\FileStore\Messages\Dci4ArpReply;

class arpMessagesTest extends TestCase
{
    private Logger $oLogger;

    protected function setUp(): void
    {
        $this->oLogger = new Logger('test');
        $this->oLogger->pushHandler(new NullHandler());
    }

    // -----------------------------------------------------------------------
    // Packet builders
    // -----------------------------------------------------------------------

    /** Build an EconetPacket carrying an ARP who-has payload (sender IP + target IP). */
    private function whoHasPkt(
        string $sSenderIP,
        string $sTargetIP,
        int    $iFlags,
        int    $iNet = 1,
        int    $iStn = 5
    ): EconetPacket {
        $oPkt = new EconetPacket();
        $oPkt->setFlags($iFlags);
        $oPkt->setSourceNetwork($iNet);
        $oPkt->setSourceStation($iStn);
        $oPkt->setDestinationNetwork(0);
        $oPkt->setDestinationStation(255);
        $oPkt->setPort(0xD2);
        $oPkt->setData(inet_pton($sSenderIP) . inet_pton($sTargetIP));
        return $oPkt;
    }

    /** Build an EconetPacket carrying an ARP is-at payload (responder IP + requester IP). */
    private function isAtPkt(
        string $sResponderIP,
        string $sRequesterIP,
        int    $iFlags,
        int    $iNet = 1,
        int    $iStn = 24
    ): EconetPacket {
        $oPkt = new EconetPacket();
        $oPkt->setFlags($iFlags);
        $oPkt->setSourceNetwork($iNet);
        $oPkt->setSourceStation($iStn);
        $oPkt->setDestinationNetwork(0);
        $oPkt->setDestinationStation(0);
        $oPkt->setPort(0xD2);
        $oPkt->setData(inet_pton($sResponderIP) . inet_pton($sRequesterIP));
        return $oPkt;
    }

    // -----------------------------------------------------------------------
    // ArpRequest – DCI-2 who-has (flags = 0x21)
    // -----------------------------------------------------------------------

    public function testDci2RequestDecodesSourceIp(): void
    {
        $oReq = new ArpRequest($this->whoHasPkt('192.168.0.5', '192.168.0.1', 0x21), $this->oLogger);
        $this->assertSame('192.168.0.5', $oReq->getSourceIP());
    }

    public function testDci2RequestDecodesTargetIp(): void
    {
        $oReq = new ArpRequest($this->whoHasPkt('192.168.0.5', '192.168.0.1', 0x21), $this->oLogger);
        $this->assertSame('192.168.0.1', $oReq->getRequestedIP());
    }

    public function testDci2RequestDecodesSourceStation(): void
    {
        $oReq = new ArpRequest($this->whoHasPkt('192.168.0.5', '192.168.0.1', 0x21, 2, 7), $this->oLogger);
        $this->assertSame(7, $oReq->getSourceStation());
    }

    public function testDci2RequestDecodesSourceNetwork(): void
    {
        $oReq = new ArpRequest($this->whoHasPkt('192.168.0.5', '192.168.0.1', 0x21, 2, 7), $this->oLogger);
        $this->assertSame(2, $oReq->getSourceNetwork());
    }

    public function testDci2RequestBuildReplyReturnsArpReplyInstance(): void
    {
        $oReq = new ArpRequest($this->whoHasPkt('192.168.0.5', '192.168.0.1', 0x21), $this->oLogger);
        $this->assertInstanceOf(ArpReply::class, $oReq->buildReply());
    }

    public function testDci2RequestBuildReplyDoesNotReturnDci4Reply(): void
    {
        $oReq = new ArpRequest($this->whoHasPkt('192.168.0.5', '192.168.0.1', 0x21), $this->oLogger);
        $this->assertNotInstanceOf(Dci4ArpReply::class, $oReq->buildReply());
    }

    // -----------------------------------------------------------------------
    // ArpReply – DCI-2 is-at (flags = 0x22)
    // -----------------------------------------------------------------------

    public function testDci2ReplyFlagsIs0x22(): void
    {
        $oReq   = new ArpRequest($this->whoHasPkt('192.168.0.5', '192.168.0.1', 0x21), $this->oLogger);
        $oEpkt  = $oReq->buildReply()->buildEconetpacket();
        $this->assertSame(0x22, $oEpkt->getFlags());
    }

    public function testDci2ReplyPayloadIsTargetIpThenSenderIp(): void
    {
        $oReq  = new ArpRequest($this->whoHasPkt('192.168.0.5', '192.168.0.1', 0x21), $this->oLogger);
        $oEpkt = $oReq->buildReply()->buildEconetpacket();
        // payload = requested IP (target) followed by source IP (sender)
        $this->assertSame(inet_pton('192.168.0.1') . inet_pton('192.168.0.5'), $oEpkt->getData());
    }

    public function testDci2ReplyAddressedToRequester(): void
    {
        $oReq  = new ArpRequest($this->whoHasPkt('192.168.0.5', '192.168.0.1', 0x21, 2, 7), $this->oLogger);
        $oEpkt = $oReq->buildReply()->buildEconetpacket();
        $this->assertSame(7, $oEpkt->getDestinationStation());
        $this->assertSame(2, $oEpkt->getDestinationNetwork());
    }

    public function testDci2ReplyPortIs0xD2(): void
    {
        $oReq  = new ArpRequest($this->whoHasPkt('192.168.0.5', '192.168.0.1', 0x21), $this->oLogger);
        $oEpkt = $oReq->buildReply()->buildEconetpacket();
        $this->assertSame(0xD2, $oEpkt->getPort());
    }

    // -----------------------------------------------------------------------
    // Dci4ArpRequest – DCI-4 who-has (flags = 0xA1)
    // -----------------------------------------------------------------------

    public function testDci4RequestDecodesSourceIp(): void
    {
        $oReq = new Dci4ArpRequest($this->whoHasPkt('192.168.0.5', '192.168.0.1', 0xA1), $this->oLogger);
        $this->assertSame('192.168.0.5', $oReq->getSourceIP());
    }

    public function testDci4RequestDecodesTargetIp(): void
    {
        $oReq = new Dci4ArpRequest($this->whoHasPkt('192.168.0.5', '192.168.0.1', 0xA1), $this->oLogger);
        $this->assertSame('192.168.0.1', $oReq->getRequestedIP());
    }

    public function testDci4RequestDecodesSourceStation(): void
    {
        $oReq = new Dci4ArpRequest($this->whoHasPkt('192.168.0.5', '192.168.0.1', 0xA1, 3, 9), $this->oLogger);
        $this->assertSame(9, $oReq->getSourceStation());
    }

    public function testDci4RequestDecodesSourceNetwork(): void
    {
        $oReq = new Dci4ArpRequest($this->whoHasPkt('192.168.0.5', '192.168.0.1', 0xA1, 3, 9), $this->oLogger);
        $this->assertSame(3, $oReq->getSourceNetwork());
    }

    public function testDci4RequestBuildReplyReturnsDci4ArpReply(): void
    {
        $oReq = new Dci4ArpRequest($this->whoHasPkt('192.168.0.5', '192.168.0.1', 0xA1), $this->oLogger);
        $this->assertInstanceOf(Dci4ArpReply::class, $oReq->buildReply());
    }

    // -----------------------------------------------------------------------
    // Dci4ArpReply – DCI-4 is-at (flags = 0xA2)
    // -----------------------------------------------------------------------

    public function testDci4ReplyFlagsIs0xA2(): void
    {
        $oReq  = new Dci4ArpRequest($this->whoHasPkt('192.168.0.5', '192.168.0.1', 0xA1), $this->oLogger);
        $oEpkt = $oReq->buildReply()->buildEconetpacket();
        $this->assertSame(0xA2, $oEpkt->getFlags());
    }

    public function testDci4ReplyPayloadMatchesDci2Format(): void
    {
        $oReq  = new Dci4ArpRequest($this->whoHasPkt('192.168.0.5', '192.168.0.1', 0xA1), $this->oLogger);
        $oEpkt = $oReq->buildReply()->buildEconetpacket();
        $this->assertSame(inet_pton('192.168.0.1') . inet_pton('192.168.0.5'), $oEpkt->getData());
    }

    public function testDci4ReplyAddressedToRequester(): void
    {
        $oReq  = new Dci4ArpRequest($this->whoHasPkt('192.168.0.5', '192.168.0.1', 0xA1, 3, 9), $this->oLogger);
        $oEpkt = $oReq->buildReply()->buildEconetpacket();
        $this->assertSame(9, $oEpkt->getDestinationStation());
        $this->assertSame(3, $oEpkt->getDestinationNetwork());
    }

    public function testDci4ReplyPortIs0xD2(): void
    {
        $oReq  = new Dci4ArpRequest($this->whoHasPkt('192.168.0.5', '192.168.0.1', 0xA1), $this->oLogger);
        $oEpkt = $oReq->buildReply()->buildEconetpacket();
        $this->assertSame(0xD2, $oEpkt->getPort());
    }

    // Flags are different between DCI-2 and DCI-4 replies but payload is identical
    public function testDci2AndDci4ReplyPayloadsAreIdentical(): void
    {
        $oReq2 = new ArpRequest($this->whoHasPkt('192.168.0.5', '192.168.0.1', 0x21), $this->oLogger);
        $oReq4 = new Dci4ArpRequest($this->whoHasPkt('192.168.0.5', '192.168.0.1', 0xA1), $this->oLogger);
        $this->assertSame(
            $oReq2->buildReply()->buildEconetpacket()->getData(),
            $oReq4->buildReply()->buildEconetpacket()->getData()
        );
    }

    // -----------------------------------------------------------------------
    // ArpIsAt – is-at decode for DCI-2 (0x22) and DCI-4 (0xA2)
    // -----------------------------------------------------------------------

    public function testDci2IsAtDecodesResponderIp(): void
    {
        $oIsAt = new ArpIsAt($this->isAtPkt('192.168.0.1', '192.168.0.5', 0x22), $this->oLogger);
        $this->assertSame('192.168.0.1', $oIsAt->getSourceIP());
    }

    public function testDci2IsAtDecodesRequesterIp(): void
    {
        $oIsAt = new ArpIsAt($this->isAtPkt('192.168.0.1', '192.168.0.5', 0x22), $this->oLogger);
        $this->assertSame('192.168.0.5', $oIsAt->getDestinationIp());
    }

    public function testDci4IsAtDecodesResponderIp(): void
    {
        $oIsAt = new ArpIsAt($this->isAtPkt('192.168.0.1', '192.168.0.5', 0xA2), $this->oLogger);
        $this->assertSame('192.168.0.1', $oIsAt->getSourceIP());
    }

    public function testDci4IsAtDecodesRequesterIp(): void
    {
        $oIsAt = new ArpIsAt($this->isAtPkt('192.168.0.1', '192.168.0.5', 0xA2), $this->oLogger);
        $this->assertSame('192.168.0.5', $oIsAt->getDestinationIp());
    }

    public function testIsAtDecodesSourceStationAndNetwork(): void
    {
        $oIsAt = new ArpIsAt($this->isAtPkt('192.168.0.1', '192.168.0.5', 0x22, 2, 8), $this->oLogger);
        $this->assertSame(8, $oIsAt->getSourceStation());
        $this->assertSame(2, $oIsAt->getSourceNetwork());
    }

    public function testDci2AndDci4IsAtDecodeIdentically(): void
    {
        $oIsAt2 = new ArpIsAt($this->isAtPkt('192.168.0.1', '192.168.0.5', 0x22), $this->oLogger);
        $oIsAt4 = new ArpIsAt($this->isAtPkt('192.168.0.1', '192.168.0.5', 0xA2), $this->oLogger);
        $this->assertSame($oIsAt2->getSourceIP(),     $oIsAt4->getSourceIP());
        $this->assertSame($oIsAt2->getDestinationIp(), $oIsAt4->getDestinationIp());
    }

    // -----------------------------------------------------------------------
    // ArpWhoHas – outbound who-has broadcast
    // -----------------------------------------------------------------------

    public function testWhoHasFlagsIs0x21(): void
    {
        $oEpkt = (new ArpWhoHas('192.168.0.1', '192.168.0.10', 1, 24))->buildEconetpacket();
        $this->assertSame(0x21, $oEpkt->getFlags());
    }

    public function testWhoHasIsSentAsBroadcast(): void
    {
        $oEpkt = (new ArpWhoHas('192.168.0.1', '192.168.0.10', 1, 24))->buildEconetpacket();
        $this->assertSame(255, $oEpkt->getDestinationStation());
    }

    public function testWhoHasSentToConfiguredNetwork(): void
    {
        $oEpkt = (new ArpWhoHas('192.168.0.1', '192.168.0.10', 2, 24))->buildEconetpacket();
        $this->assertSame(2, $oEpkt->getDestinationNetwork());
    }

    public function testWhoHasPayloadIsSenderIpThenTargetIp(): void
    {
        $oEpkt = (new ArpWhoHas('192.168.0.1', '192.168.0.10', 1, 24))->buildEconetpacket();
        $this->assertSame(inet_pton('192.168.0.1') . inet_pton('192.168.0.10'), $oEpkt->getData());
    }

    public function testWhoHasPortIs0xD2(): void
    {
        $oEpkt = (new ArpWhoHas('192.168.0.1', '192.168.0.10', 1, 24))->buildEconetpacket();
        $this->assertSame(0xD2, $oEpkt->getPort());
    }
}
