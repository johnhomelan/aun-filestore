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
	private int $iUrgent;
	private int $iHeaderLen;
	private bool $bSyn;
	private bool $bFin;
	private bool $bAck;
	private bool $bNonce;
	private bool $bCrw;
	private bool $bEcn;
	private bool $bUrgent;
	private bool $bPush;
	private bool $bReset;
	private array $aOptions  = [];
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

		$this->iSrcPort = $this->get16bitIntBigEndian(0);
		$this->iDstPort = $this->get16bitIntBigEndian(0);
		$this->iSeq = $this->get32bitIntBigEndian(0);
		$this->iAck = $this->get32bitIntBigEndian(0);
		$sDoRsvFlags1 = $this->getByte(0);
		$sDoRsvFlags2 = $this->getByte(0);
		$this->iWindow =  $this->get16bitIntBigEndian(0);
		$this->iChecksum = $this->get16bitIntBigEndian(0);
		$this->iUrgent = $this->get16bitIntBigEndian(0);

		$this->iHeaderLen = $sDoRsvFlags1 >> 4;
	
		$this->bNonce  = (bool) $sDoRsvFlags1 & 1;
		$this->bCrw    = (bool) $sDoRsvFlags2 & 128;
		$this->bEcn    = (bool) $sDoRsvFlags2 & 64;
		$this->bUrgent = (bool) $sDoRsvFlags2 & 32;
		$this->bAck    = (bool) $sDoRsvFlags2 & 16;
		$this->bPush   = (bool) $sDoRsvFlags2 & 8;
		$this->bReset  = (bool) $sDoRsvFlags2 & 4;
		$this->bSyn    = (bool) $sDoRsvFlags2 & 2;
		$this->bFin    = (bool) $sDoRsvFlags2 & 1;

		$iOptionsLen = $this->iHeaderLen - 20;
		if($iOptionsLen>0){
			//We have options read them
			while($iOptionsLen>0){
				$iOption = $this->getByte(0);
				$iOptLen = $this->getByte(0);
				switch($iOptLen){
					case 1:
						$iOptValue = $this->getByte(0);
						break;
					case 2:
						$iOptValue = $this->get16bitIntBigEndian(0);
						break;
					case 3: 
						$iOptValue = $this->get24bitIntBigEndian(0);
						break;
					case 4:
						$iOptValue = $this->get32bitIntBigEndian(0);
						break;
				}
				$iOptionsLen = $iOptionsLen - (2 + $iOptLen);
				$this->aOptions[] = ['option'=>$iOption, 'length'=>$iOptLen, 'value'=>$iOptValue];
			}
		}
		//@TODO deal with options
		
	}

	public function getCheckSum():int
	{
		return $this->iChecksum;
	}

	public function getSrcPort():int
	{
		return $this->iSrcPort;
	}

	public function getDstPort():int
	{
		return $this->iDstPort;
	}

	public function getSynFlag():bool
	{
		return $this->bSyn;
	}

	public function getFinFlag():bool
	{
		return $this->bFin;
	}

	public function getAckFlag():bool
	{
		return $this->bAck;
	}

	public function getResetFlag():bool
	{
		return $this->bReset;
	}

	public function getAck():int
	{
		return $this->iAck;
	}
	
	public function getWindow():int
	{
		return $this->iWindow;
	}
		
	public function getSequence():int
	{
		return $this->iSeq;
	}

}
