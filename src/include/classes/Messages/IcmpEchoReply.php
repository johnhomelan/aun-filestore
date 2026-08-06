<?php
/**
 * This file contains the IcmpEchoReply class
 *
 * @author John Brown <john@home-lan.co.uk>
 * @package coreprotocol
*/
namespace HomeLan\FileStore\Messages;

/**
 * Builds an ICMP echo reply (type 0) wrapped in an IPv4 EconetPacket.
 *
 * Usage:
 *   $oReply = new IcmpEchoReply();
 *   $oReply->setSrcIP($sInterfaceIP);
 *   $oReply->setDstIP($oIcmp->getSrcIP());
 *   $oReply->setSrcStation($aIface['station']);
 *   $oReply->setSrcNetwork($aIface['network']);
 *   $oReply->setDstStation($oPacket->getSourceStation());
 *   $oReply->setDstNetwork($oPacket->getSourceNetwork());
 *   $oReply->setId($oIcmp->getId());
 *   $oReply->setSequence($oIcmp->getSequence());
 *   $oReply->setData($oIcmp->getEchoData());
 *   $oEconetPkt = $oReply->buildEconetpacket();
 *
 * @package coreprotocol
*/
class IcmpEchoReply extends Reply
{
    private string $sSrcIP     = '';
    private string $sDstIP     = '';
    private int    $iSrcStation = 0;
    private int    $iSrcNetwork = 0;
    private int    $iDstStation = 0;
    private int    $iDstNetwork = 0;
    private int    $iId         = 0;
    private int    $iSeq        = 0;
    private string $sEchoData   = '';
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
    public function setId(int $i): void               { $this->iId         = $i; }
    public function setSequence(int $i): void         { $this->iSeq        = $i; }
    public function setData(string $s): void          { $this->sEchoData   = $s; }
    public function setPktId(int $i): void            { $this->iPktId      = $i; }

    public function buildEconetpacket(): \HomeLan\FileStore\Messages\EconetPacket
    {
        // Build ICMP echo reply payload
        $sIcmp  = chr(0);                              // type: echo reply
        $sIcmp .= chr(0);                              // code: 0
        $sIcmp .= "\x00\x00";                          // checksum placeholder
        $sIcmp .= pack('n', $this->iId);               // identifier
        $sIcmp .= pack('n', $this->iSeq);              // sequence number
        $sIcmp .= $this->sEchoData;                    // data copied from request

        // Fill in ICMP checksum (covers the entire ICMP segment)
        $sIcmp = substr_replace($sIcmp, $this->icmpChecksum($sIcmp), 2, 2);

        $iTotalLen = 20 + strlen($sIcmp);

        // IPv4 header
        $sIP  = chr(0x45);                             // version=4, IHL=5
        $sIP .= chr(0x00);                             // TOS
        $sIP .= pack('n', $iTotalLen);                 // total length
        $sIP .= pack('n', $this->iPktId);              // packet ID
        $sIP .= pack('n', 0x0000);                     // flags + fragment offset
        $sIP .= chr(64);                               // TTL
        $sIP .= chr(0x01);                             // protocol: ICMP
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
        $oEconetPacket->setData($sIP . $sIcmp);
        return $oEconetPacket;
    }

    private function icmpChecksum(string $sData): string
    {
        return $this->onesComplementChecksum($sData);
    }

    private function onesComplementChecksum(string $sData): string
    {
        if (strlen($sData) % 2 !== 0) {
            $sData .= "\x00";
        }
        $aPairs = unpack('n*', $sData);
        $iSum   = array_sum($aPairs);
        while ($iSum >> 16) {
            $iSum = ($iSum >> 16) + ($iSum & 0xffff);
        }
        return pack('n', ~$iSum & 0xffff);
    }
}
