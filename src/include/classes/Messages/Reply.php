<?php
/**
 * This file contains the fsreply class
 *
 * @author John Brown <john@home-lan.co.uk>
 * @package coreprotocol
*/
namespace HomeLan\FileStore\Messages; 

use Exception;

/** 
 * This class is used to repressent reply packets
 *
 * @package coreprotocol
*/
class Reply {

	protected $sPkt = NULL;
	
	protected $oRequest = NULL;

	protected $iFlags = NULL;

	public function __construct($oRequest)
	{
		//Main Loop @phpstan-ignore-next-line
		if(is_object($oRequest) AND ($oRequest::class=='fsrequest' or $oRequest::class=='printserverenquiry' OR $oRequest::class=='printserverdata' OR $oRequest::class=='arprequest')){
			$this->oRequest = $oRequest;
			$this->iFlags = $oRequest->getFlags();
		}else{
			throw new Exception("An fsreply object was created with out suppling an fsrequest object");
		}
	}

	public function appendByte($iByte): void
	{
		$this->sPkt = $this->sPkt.pack('C',$iByte);
	}

	public function appendString($sString): void
	{
		$aChars = str_split((string) $sString);
		foreach($aChars as $sChar)
		{
			$this->sPkt = $this->sPkt.pack('C',ord($sChar));
		}
	}

	public function append16bitIntLittleEndian($iInt): void
	{
		$this->sPkt = $this->sPkt.pack('v',$iInt);
	}

	public function append24bitIntLittleEndian($iInt): void
	{
		$this->sPkt = $this->sPkt.pack('v',$iInt).pack('C',0);
	}

	public function append32bitIntLittleEndian($iInt): void
	{
		$this->sPkt = $this->sPkt.pack('V',$iInt);
	}

	public function appendRaw($sRawBytes): void
	{
		$this->sPkt = $this->sPkt.$sRawBytes;
	}

	public function setFlags($iFlags): void
	{
		$this->iFlags = $iFlags;
	}

	public function getFlags()
	{
		return $this->iFlags;
	}

	public function buildEconetpacket(): \HomeLan\FileStore\Messages\EconetPacket
	{
		$oEconetPacket = new EconetPacket();
		$oEconetPacket->setPort($this->oRequest->getReplyPort());
		$oEconetPacket->setFlags($this->iFlags);
		$oEconetPacket->setDestinationStation($this->oRequest->getSourceStation());
		$oEconetPacket->setDestinationNetwork($this->oRequest->getSourceNetwork());
		$oEconetPacket->setData($this->sPkt);
		return $oEconetPacket;
	}
}
