<?php
/**
 * This file contains the UdpRequest class
 *
 * @author John Brown <john@home-lan.co.uk>
 * @package coreprotocol
*/
namespace HomeLan\FileStore\Messages;

use HomeLan\FileStore\Messages\IPv4Request;
use HomeLan\FileStore\Messages\EconetPacket;

/**
 * Parses an incoming UDP datagram carried inside an IPv4 EconetPacket.
 *
 * @package coreprotocol
*/
class UdpRequest extends Request
{
    private int    $iSrcPort  = 0;
    private int    $iDstPort  = 0;
    private int    $iLength   = 0;
    private int    $iChecksum = 0;
    private string $sPayload  = '';
    private string $sSrcIP    = '';
    private string $sDstIP    = '';
    private bool   $bValid    = false;

    public function __construct(EconetPacket $oEconetPacket, \Psr\Log\LoggerInterface $oLogger)
    {
        parent::__construct($oEconetPacket, $oLogger);
        $this->decode($oEconetPacket);
    }

    private function decode(EconetPacket $oEconetPacket): void
    {
        $oIPv4 = new IPv4Request($oEconetPacket, $this->oLogger);
        if ($oIPv4->getProtocol() !== 'UDP') {
            return;
        }
        $this->sSrcIP = $oIPv4->getSrcIP();
        $this->sDstIP = $oIPv4->getDstIP();
        $this->sData  = $oIPv4->getData(); // full UDP datagram: 8-byte header + payload

        $this->iSrcPort  = $this->get16bitIntBigEndian(1);
        $this->iDstPort  = $this->get16bitIntBigEndian(3);
        $this->iLength   = $this->get16bitIntBigEndian(5);
        $this->iChecksum = $this->get16bitIntBigEndian(7);
        $this->sPayload  = (string) substr((string) $this->sData, 8);

        $this->bValid = true;
    }

    public function isValid(): bool
    {
        return $this->bValid;
    }

    public function getSrcPort(): int
    {
        return $this->iSrcPort;
    }

    public function getDstPort(): int
    {
        return $this->iDstPort;
    }

    public function getLength(): int
    {
        return $this->iLength;
    }

    public function getChecksum(): int
    {
        return $this->iChecksum;
    }

    public function getPayload(): string
    {
        return $this->sPayload;
    }

    public function getSrcIP(): string
    {
        return $this->sSrcIP;
    }

    public function getDstIP(): string
    {
        return $this->sDstIP;
    }
}
