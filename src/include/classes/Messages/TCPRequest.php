<?php
/**
 * This file contains the fsrequest class
 *
 * @author John Brown <john@home-lan.co.uk>
 * @package coreprotocol
*/
namespace HomeLan\FileStore\Messages; 

use HomeLan\FileStore\Messages\IPv4Request;
use HomeLan\FileStore\Messages\EconetPacket;
use Exception; 

/** 
 * This class is used to repressent a general IPv4 packet 
 *
 * @package coreprotocol
*/
class TCPRequest extends Request {


	private int $iSrcPort;
	private int $iDstPort;
	private int $iSeq;
	private int $iAck;
	private int $iWindow;
	private bool $bSyn;
	private bool $bFin;
	private bool $bAck;
	private string $sData
	private EconetPacket $oEconetPacket;
	private int $iChecksum;

	public function __construct(EconetPacket $oEconetPacket, \Psr\Log\LoggerInterface $oLogger)
	{
		parent:: __construct($oEconetPacket, $oLogger);
		$this->oEconetPacket = $oEconetPacket;
		$this->decode($oEconetPacket);
	}

	/**
	  * Decodes the IPv4 packet 
	  *
	*/
	public function decode(EconetPacket $oEconetPacket): void
	{
		
		$oIPv4 = new IPv4Request($oEconetPacket,$this->oLogger);
		if($oIPv4->getProtocol()!='TCP'){
			return;
		}
		$this->sData = $oIPv4->getData();

		//Copy the full packet so we can retransmitt a copy of this packet if its not mean modified, rather than rebuilding it from the parsed structure and appending the data.
		$this->sFullPacket = $this->sData;

		$this->iSrcPort = $this->get16bitIntLittleEndian(0);
		$this->iDstPort = $this->get16bitIntLittleEndian(0);
		$this->iSeq = $this->get32bitIntLittleEndian(0);
		$this->iAck = $this->get32bitIntLittleEndian(0);
		$this->sDoRsvFlags = $this->get16bitIntLittleEndian(0);
		$this->iWindow =  $this->get16bitIntLittleEndian(0);
		$this->iChecksum = $this->get16bitIntLittleEndian(0);
		$this->iUrgent = $this->get16bitIntLittleEndian(0);

		//@TODO deal with options
		
	}

	public function getCheckSum():int
	{
		return $this->iChecksum;
	}

	public function getSorucePort():int
	{
		return $this->iSrcPort;
	}

	public function getDestinationPort():int
	{
		return $this->iDstPort;
	}
}
