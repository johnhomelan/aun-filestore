<?php
/**
 * This file contains the printserverdata class
 *
 * @author John Brown <john@home-lan.co.uk>
 * @package coreprotocol
*/
namespace HomeLan\FileStore\Messages; 

use Exception; 

/** 
 * This class is used to repressent a print server data
 *
 * @package coreprotocol
*/
class PrintServerData {

	protected $iSourceNetwork = NULL;

	protected $iSourceStation = NULL;

	protected $iDestinationNetwork = NULL;

	protected $iDestinationStation = NULL;

	protected $iFlags = NULL;

	protected $sData = NULL;

	public function __construct($oEconetPacket)
	{
		$this->iSourceNetwork = $oEconetPacket->getSourceNetwork();
		$this->iSourceStation = $oEconetPacket->getSourceStation();
		$this->iFlags = $oEconetPacket->getFlags();
		$this->decode($oEconetPacket->getData());
	}	

	public function getSourceStation()
	{
		return $this->iSourceStation;
	}

	public function getSourceNetwork()
	{
		return $this->iSourceNetwork;
	}

	public function getReplyPort(): int
	{
		return 0xD1;
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

	/**
  * Decodes an AUN packet 
  *
  */
 public function decode(string $sBinaryString): void
	{
	
		//The reset is data
		$this->sData = $sBinaryString;
		
	}

	public function getByte($iIndex)
	{
		$aBytes = unpack('C*',(string) $this->sData);
		if(array_key_exists($iIndex,$aBytes)){
			return $aBytes[$iIndex];
		}
		return NULL;
	}

	public function getString($iStart,$iLen=NULL): string
	{
		$aBytes = unpack('C*',(string) $this->sData);
		if(is_null($iLen)){
			$iLen = is_countable($aBytes) ? count($aBytes) : 0;
		}
		$sRetstr = "";
		for($i=$iStart;$i<$iLen;$i++){
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

	public function buildReply(): \HomeLan\FileStore\Messages\Reply
	{
		return new Reply($this);
	}

	public function getLen(): int
	{
		return strlen((string) $this->sData);
	}
}
