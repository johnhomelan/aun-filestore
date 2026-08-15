<?php
/**
 * This file contains the IcmpUnreachable class
 *
 * @author John Brown <john@home-lan.co.uk>
 * @package coreprotocol
*/
namespace HomeLan\FileStore\Messages;

/**
 * Builds an ICMP Destination Unreachable message (type 3) inside an IPv4 EconetPacket.
 *
 * RFC 792: the ICMP payload includes the original IPv4 header plus the first
 * 64 bits (8 bytes) of the original datagram that triggered the error.
 *
 * @package coreprotocol
*/
class IcmpUnreachable extends Reply
{
    const NET_UNREACHABLE  = 0;
    const HOST_UNREACHABLE = 1;
    const PORT_UNREACHABLE = 3;

    private string $sSrcIP       = '';
    private string $sDstIP       = '';
    private int    $iSrcStation  = 0;
    private int    $iSrcNetwork  = 0;
    private int    $iDstStation  = 0;
    private int    $iDstNetwork  = 0;
    private string $sOriginalPkt = '';
    private int    $iPktId       = 1;

    public function __construct(private int $iCode = self::NET_UNREACHABLE)
    {
    }

    public function setSrcIP(string $sIP): void         { $this->sSrcIP      = $sIP; }
    public function setDstIP(string $sIP): void         { $this->sDstIP      = $sIP; }
    public function setSrcStation(int $i): void         { $this->iSrcStation = $i; }
    public function setSrcNetwork(int $i): void         { $this->iSrcNetwork = $i; }
    public function setDstStation(int $i): void         { $this->iDstStation = $i; }
    public function setDstNetwork(int $i): void         { $this->iDstNetwork = $i; }
    public function setPktId(int $i): void              { $this->iPktId      = $i; }

    /**
     * Pass the raw bytes of the original IPv4 packet that triggered the error.
     * EconetPacket::getData() gives this directly for regular IP packets.
     */
    public function setOriginalPacket(string $sRaw): void
    {
        $this->sOriginalPkt = $sRaw;
    }

    public function buildEconetpacket(): \HomeLan\FileStore\Messages\EconetPacket
    {
        // ICMP payload per RFC 792: 4 unused bytes + original IP header (20) + first 8 bytes of original data
        $sOrigHdr  = substr($this->sOriginalPkt, 0, 20);
        $sOrigData = substr($this->sOriginalPkt, 20, 8);

        $sIcmp  = chr(3);                              // type: destination unreachable
        $sIcmp .= chr($this->iCode);                   // code
        $sIcmp .= "\x00\x00";                          // checksum placeholder
        $sIcmp .= "\x00\x00\x00\x00";                 // unused
        $sIcmp .= $sOrigHdr;                           // original IP header (20 bytes)
        $sIcmp .= str_pad($sOrigData, 8, "\x00");      // first 8 bytes of original data

        // ICMP checksum over the whole ICMP segment
        $sIcmp = substr_replace($sIcmp, $this->onesComplementChecksum($sIcmp), 2, 2);

        $iTotalLen = 20 + strlen($sIcmp);

        $sIP  = chr(0x45);
        $sIP .= chr(0x00);
        $sIP .= pack('n', $iTotalLen);
        $sIP .= pack('n', $this->iPktId);
        $sIP .= pack('n', 0x0000);
        $sIP .= chr(64);
        $sIP .= chr(0x01);                             // ICMP
        $sIP .= "\x00\x00";
        $sIP .= inet_pton($this->sSrcIP);
        $sIP .= inet_pton($this->sDstIP);

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

    private function onesComplementChecksum(string $sData): string
    {
        if (strlen($sData) % 2 !== 0) {
            $sData .= "\x00";
        }
        $aPairs = unpack('n*', $sData);
        if($aPairs === false){
            $aPairs = [];
        }
        $iSum   = array_sum($aPairs);
        while ($iSum >> 16) {
            $iSum = ($iSum >> 16) + ($iSum & 0xffff);
        }
        return pack('n', ~$iSum & 0xffff);
    }
}
