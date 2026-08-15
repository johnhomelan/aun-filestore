<?php
/**
 * This file contains the IcmpRequest class
 *
 * @author John Brown <john@home-lan.co.uk>
 * @package coreprotocol
*/
namespace HomeLan\FileStore\Messages;

use HomeLan\FileStore\Messages\IPv4Request;
use HomeLan\FileStore\Messages\EconetPacket;

/**
 * Parses an incoming ICMP message carried inside an IPv4 EconetPacket.
 *
 * @package coreprotocol
*/
class IcmpRequest extends Request
{
    const TYPE_ECHO_REPLY   = 0;
    const TYPE_ECHO_REQUEST = 8;
    const TYPE_UNREACHABLE  = 3;

    private int    $iType     = 0;
    private int    $iCode     = 0;
    private int    $iChecksum = 0;
    private int    $iId       = 0;
    private int    $iSeq      = 0;
    private string $sEchoData = '';
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
        if ($oIPv4->getProtocol() !== 'ICMP') {
            return;
        }
        $this->sSrcIP  = $oIPv4->getSrcIP();
        $this->sDstIP  = $oIPv4->getDstIP();
        $this->sData   = $oIPv4->getData(); // full ICMP segment

        $this->iType     = $this->getByte(1) ?? 0;
        $this->iCode     = $this->getByte(2) ?? 0;
        $this->iChecksum = $this->get16bitIntBigEndian(3);

        if ($this->iType === self::TYPE_ECHO_REQUEST || $this->iType === self::TYPE_ECHO_REPLY) {
            $this->iId       = $this->get16bitIntBigEndian(5);
            $this->iSeq      = $this->get16bitIntBigEndian(7);
            $this->sEchoData = (string) substr((string) $this->sData, 8);
        }

        $this->bValid = true;
    }

    public function isValid(): bool
    {
        return $this->bValid;
    }

    public function isEchoRequest(): bool
    {
        return $this->bValid && $this->iType === self::TYPE_ECHO_REQUEST && $this->iCode === 0;
    }

    public function getType(): int
    {
        return $this->iType;
    }

    public function getCode(): int
    {
        return $this->iCode;
    }

    public function getChecksum(): int
    {
        return $this->iChecksum;
    }

    public function getId(): int
    {
        return $this->iId;
    }

    public function getSequence(): int
    {
        return $this->iSeq;
    }

    public function getEchoData(): string
    {
        return $this->sEchoData;
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
