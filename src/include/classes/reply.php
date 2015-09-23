<?php
/**
 * This file contains the fsreply class
 *
 * @author John Brown <john@home-lan.co.uk>
 * @package coreprotocol
*/

/** 
 * This class is used to repressent reply packets
 *
 * @package coreprotocol
*/
class reply {

	protected $sPkt = NULL;
	
	protected $oRequest = NULL;

	protected $iFlags = NULL;

	public function __construct($oRequest)
	{
		if(is_object($oRequest) AND (get_class($oRequest)=='fsrequest' or get_class($oRequest)=='printserverenquiry' OR get_class($oRequest)=='printserverdata')){
			$this->oRequest = $oRequest;
			$this->iFlags = $oRequest->getFlags();
		}else{
			throw new Exception("An fsreply object was created with out suppling an fsrequest object");
		}
	}

	public function appendByte($iByte)
	{
		$this->sPkt = $this->sPkt.pack('C',$iByte);
	}

	public function appendString($sString)
	{
		$aChars = str_split($sString);
		foreach($aChars as $sChar)
		{
			$this->sPkt = $this->sPkt.pack('C',ord($sChar));
		}
	}

	public function append16bitIntLittleEndian($iInt)
	{
		$this->sPkt = $this->sPkt.pack('v',$iInt);
	}

	public function append24bitIntLittleEndian($iInt)
	{
		$this->sPkt = $this->sPkt.pack('v',$iInt).pack('C',0);
	}

	public function append32bitIntLittleEndian($iInt)
	{
		$this->sPkt = $this->sPkt.pack('V',$iInt);
	}

	public function appendRaw($sRawBytes)
	{
		$this->sPkt = $this->sPkt.$sRawBytes;
	}

	public function setFlags($iFlags)
	{
		$this->iFlags = $iFlags;
	}

	public function getFlags()
	{
		return $this->iFlags;
	}

	public function buildEconetpacket()
	{
		$oEconetPacket = new econetpacket();
		$oEconetPacket->setPort($this->oRequest->getReplyPort());
		$oEconetPacket->setFlags($this->iFlags);
		$oEconetPacket->setDestinationStation($this->oRequest->getSourceStation());
		$oEconetPacket->setDestinationNetwork($this->oRequest->getSourceNetwork());
		$oEconetPacket->setData($this->sPkt);
		return $oEconetPacket;
	}
}
