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

	protected ?int $iSourceNetwork = NULL;

	protected ?int $iSourceStation = NULL;

	protected ?int $iDestinationNetwork = NULL;

	protected ?int $iDestinationStation = NULL;

	protected int $iFlags = 0;

	protected ?string $sData = NULL;

	public function __construct(EconetPacket $oEconetPacket)
	{
		$this->iSourceNetwork = $oEconetPacket->getSourceNetwork();
		$this->iSourceStation = $oEconetPacket->getSourceStation();
		$this->iFlags = $oEconetPacket->getFlags();
		$this->decode((string) $oEconetPacket->getData());
	}

	public function getSourceStation(): ?int
	{
		return $this->iSourceStation;
	}

	public function getSourceNetwork(): ?int
	{
		return $this->iSourceNetwork;
	}

	public function getReplyPort(): int
	{
		return 0xD1;
	}

	public function getFlags(): int
	{
		return $this->iFlags;
	}

	/**
	 * Get the binary data from the fs packet
	 *
	*/
	public function getData(): ?string
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

	public function getByte(int $iIndex): ?int
	{
		$aBytes = unpack('C*',(string) $this->sData);
		if($aBytes === false){
			return NULL;
		}
		if(array_key_exists($iIndex,$aBytes)){
			return $aBytes[$iIndex];
		}
		return NULL;
	}

	public function getString(int $iStart,?int $iLen=NULL): string
	{
		$aBytes = unpack('C*',(string) $this->sData);
		if($aBytes === false){
			return '';
		}
		if(is_null($iLen)){
			$iLen = count($aBytes);
		}
		$sRetstr = "";
		for($i=$iStart;$i<$iLen;$i++){
			if(!array_key_exists($i,$aBytes)){
				break;
			}
			if(chr($aBytes[$i])!="\r" AND chr($aBytes[$i])!="\n"){
				$sRetstr = $sRetstr.chr($aBytes[$i]);
			}else{
				break;
			}
		}
		return $sRetstr;
	}

	public function get32bitIntLittleEndian(int $iStart): int
	{
		$sStr = substr((string) $this->sData,$iStart-1,4);
		$aInt = unpack('V',$sStr);
		if($aInt === false){
			return 0;
		}
		return $aInt[1];
	}

	public function get24bitIntLittleEndian(int $iStart): int
	{
		$aBytes = unpack('C*',(string) $this->sData);
		if($aBytes === false || !array_key_exists($iStart,$aBytes) || !array_key_exists($iStart+1,$aBytes) || !array_key_exists($iStart+2,$aBytes)){
			return 0;
		}
		$iInt= (int) bindec(str_pad(decbin($aBytes[$iStart+2]),8,"0",STR_PAD_LEFT).str_pad(decbin($aBytes[$iStart+1]),8,"0",STR_PAD_LEFT).str_pad(decbin($aBytes[$iStart]),8,"0",STR_PAD_LEFT));
		return $iInt;
	}

	public function get16bitIntLittleEndian(int $iStart): int
	{
		$sStr = substr((string) $this->sData,$iStart-1,2);
		$aInt = unpack('v',$sStr);
		if($aInt === false){
			return 0;
		}
		return $aInt[1];
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
