<?php
/**
 * This file contains the fsrequest class
 *
 * @author John Brown <john@home-lan.co.uk>
 * @package coreprotocol
*/
namespace HomeLan\FileStore\Messages; 

use Exception; 

/** 
 * This class is used to repressent a request
 *
 * @package coreprotocol
*/
class Request {

	protected $iSourceNetwork = NULL;

	protected $iSourceStation = NULL;

	protected $iDestinationNetwork = NULL;

	protected $iDestinationStation = NULL;

	protected $iFlags = NULL;

	protected $sData = NULL;

	protected $oLogger;

	public function __construct($oEconetPacket, \Psr\Log\LoggerInterface $oLogger)
	{
		$this->iSourceNetwork = $oEconetPacket->getSourceNetwork();
		$this->iSourceStation = $oEconetPacket->getSourceStation();
		$this->iFlags = $oEconetPacket->getFlags();
		$this->oLogger = $oLogger;
	}	

	public function getSourceStation()
	{
		return $this->iSourceStation;
	}

	public function getSourceNetwork()
	{
		return $this->iSourceNetwork;
	}

	public function getFlags()
	{
		return $this->iFlags;
	}

	/**
	 * Get the binary data from the fs packet
	 *
	*/
	public function getData()
	{
		return $this->sData;
	}

	public function getByte($iIndex)
	{
		$aBytes = unpack('C*',(string) $this->sData);
		if(array_key_exists($iIndex,$aBytes)){
			return $aBytes[$iIndex];
		}
		return NULL;
	}

	public function getString($iStart): string
	{
		$aBytes = unpack('C*',(string) $this->sData);
		$sRetstr = "";
		for($i=$iStart;$i<(is_countable($aBytes) ? count($aBytes) : 0);$i++){
			if(chr($aBytes[$i])!="\r" AND chr($aBytes[$i])!="\n"){
				$sRetstr = $sRetstr.chr($aBytes[$i]);
			}else{
				break;
			}
		}
		return $sRetstr;
	}

	public function get32bitIntLittleEndian($iStart)
	{
		$sStr = substr((string) $this->sData,$iStart-1,4);
		$aInt = unpack('V',$sStr);
		return $aInt[1];
	}

	public function get24bitIntLittleEndian($iStart): int
	{
		$aBytes = unpack('C*',(string) $this->sData);
		$iInt= bindec(str_pad(decbin($aBytes[$iStart+2]),8,"0",STR_PAD_LEFT).str_pad(decbin($aBytes[$iStart+1]),8,"0",STR_PAD_LEFT).str_pad(decbin($aBytes[$iStart]),8,"0",STR_PAD_LEFT));
		return $iInt;
	}

	public function get16bitIntLittleEndian($iStart): void
	{
		$sStr = substr((string) $this->sData,$iStart-1,2);
		$aInt = unpack('v',$sStr);
	}

}
