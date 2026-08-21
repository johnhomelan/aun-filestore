<?php
/**
 * This file contains the UdpEconetReply class
 *
 * @author John Brown <john@home-lan.co.uk>
 * @package coreprotocol
*/
namespace HomeLan\FileStore\Messages;

/**
 * Builds a UDP datagram wrapped in an IPv4 EconetPacket, addressed as coming from one of our
 * own EconetA interfaces - used to relay a reply received over the Remote Socket Protocol (see
 * docs/protocols/remote-socket.md) back out onto Econet.
 *
 * Usage:
 *   $oReply = new UdpEconetReply();
 *   $oReply->setSrcIP($sInterfaceIP);
 *   $oReply->setDstIP($sClientIP);
 *   $oReply->setSrcPort($iInterfacePort);
 *   $oReply->setDstPort($sClientPort);
 *   $oReply->setSrcStation($aIface['station']);
 *   $oReply->setSrcNetwork($aIface['network']);
 *   $oReply->setDstStation($oPacket->getSourceStation());
 *   $oReply->setDstNetwork($oPacket->getSourceNetwork());
 *   $oReply->setData($sPayload);
 *   $oEconetPkt = $oReply->buildEconetpacket();
 *
 * @package coreprotocol
*/
class UdpEconetReply extends Reply
{
    private string $sSrcIP      = '';
    private string $sDstIP      = '';
    private int    $iSrcStation = 0;
    private int    $iSrcNetwork = 0;
    private int    $iDstStation = 0;
    private int    $iDstNetwork = 0;
    private int    $iSrcPort    = 0;
    private int    $iDstPort    = 0;
    private string $sPayload    = '';
    private int    $iPktId      = 1;

    public function __construct()
    {
    }

    public function setSrcIP(string $sIP): void       { $this->sSrcIP      = $sIP; }
    public function setDstIP(string $sIP): void       { $this->sDstIP      = $sIP; }
    public function setSrcStation(int $i): void       { $this->iSrcStation = $i; }
    public function setSrcNetwork(int $i): void       { $this->iSrcNetwork = $i; }
    public function setDstStation(int $i): void       { $this->iDstStation = $i; }
    public function setDstNetwork(int $i): void       { $this->iDstNetwork = $i; }
    public function setSrcPort(int $i): void          { $this->iSrcPort    = $i; }
    public function setDstPort(int $i): void          { $this->iDstPort    = $i; }
    public function setData(string $s): void          { $this->sPayload    = $s; }
    public function setPktId(int $i): void            { $this->iPktId      = $i; }

    public function buildEconetpacket(): \HomeLan\FileStore\Messages\EconetPacket
    {
        // UDP header: src port, dst port, length, checksum. Checksum 0 is valid for IPv4 UDP
        // (means "not computed") and is what every other reply-building class in this codebase
        // that touches IP-in-Econet already relies on for the IP header itself.
        $iUdpLen = 8 + strlen($this->sPayload);
        $sUdp  = pack('n', $this->iSrcPort);
        $sUdp .= pack('n', $this->iDstPort);
        $sUdp .= pack('n', $iUdpLen);
        $sUdp .= "\x00\x00";
        $sUdp .= $this->sPayload;

        $iTotalLen = 20 + strlen($sUdp);

        // IPv4 header
        $sIP  = chr(0x45);                             // version=4, IHL=5
        $sIP .= chr(0x00);                             // TOS
        $sIP .= pack('n', $iTotalLen);                 // total length
        $sIP .= pack('n', $this->iPktId);               // packet ID
        $sIP .= pack('n', 0x0000);                     // flags + fragment offset
        $sIP .= chr(64);                               // TTL
        $sIP .= chr(0x11);                              // protocol: UDP
        $sIP .= "\x00\x00";                            // checksum placeholder
        $sIP .= inet_pton($this->sSrcIP);              // source IP
        $sIP .= inet_pton($this->sDstIP);              // destination IP

        // Fill in IP header checksum (first 20 bytes)
        $sIP = substr_replace($sIP, $this->onesComplementChecksum($sIP), 10, 2);

        $oEconetPacket = new EconetPacket();
        $oEconetPacket->setPort(0xD2);
        $oEconetPacket->setFlags(0x01);
        $oEconetPacket->setSourceStation($this->iSrcStation);
        $oEconetPacket->setSourceNetwork($this->iSrcNetwork);
        $oEconetPacket->setDestinationStation($this->iDstStation);
        $oEconetPacket->setDestinationNetwork($this->iDstNetwork);
        $oEconetPacket->setData($sIP . $sUdp);
        return $oEconetPacket;
    }

    private function onesComplementChecksum(string $sData): string
    {
        if (strlen($sData) % 2 !== 0) {
            $sData .= "\x00";
        }
        $aPairs = unpack('n*', $sData);
        if ($aPairs === false) {
            $aPairs = [];
        }
        $iSum = array_sum($aPairs);
        while ($iSum >> 16) {
            $iSum = ($iSum >> 16) + ($iSum & 0xffff);
        }
        return pack('n', ~$iSum & 0xffff);
    }
}
