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

	protected ?int $iSourceNetwork = NULL;

	protected ?int $iSourceStation = NULL;

	protected ?int $iDestinationNetwork = NULL;

	protected ?int $iDestinationStation = NULL;

	protected int $iFlags = 0;

	protected ?string $sData = NULL;

	protected \Psr\Log\LoggerInterface $oLogger;

	public function __construct(EconetPacket $oEconetPacket, \Psr\Log\LoggerInterface $oLogger)
	{
		$this->iSourceNetwork = $oEconetPacket->getSourceNetwork();
		$this->iSourceStation = $oEconetPacket->getSourceStation();
		$this->iFlags = $oEconetPacket->getFlags();
		$this->oLogger = $oLogger;
	}

	public function getSourceStation(): ?int
	{
		return $this->iSourceStation;
	}

	public function getSourceNetwork(): ?int
	{
		return $this->iSourceNetwork;
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

	public function getString(int $iStart): string
	{
		$aBytes = unpack('C*',(string) $this->sData);
		if($aBytes === false){
			return '';
		}
		$sRetstr = "";
		for($i=$iStart;$i<(count($aBytes)+1);$i++){
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

	public function get32bitIntBigEndian(int $iStart): int
	{
		$sStr = substr((string) $this->sData,$iStart-1,4);
		$aInt = unpack('N',$sStr);
		if($aInt === false){
			return 0;
		}
		return $aInt[1];
	}

	public function get24bitIntBigEndian(int $iStart): int
	{
		$aBytes = unpack('C*',(string) $this->sData);
		if($aBytes === false || !array_key_exists($iStart,$aBytes) || !array_key_exists($iStart+1,$aBytes) || !array_key_exists($iStart+2,$aBytes)){
			return 0;
		}
		$iInt= (int) bindec(str_pad(decbin($aBytes[$iStart]),8,"0",STR_PAD_LEFT).str_pad(decbin($aBytes[$iStart+1]),8,"0",STR_PAD_LEFT).str_pad(decbin($aBytes[$iStart+2]),8,"0",STR_PAD_LEFT));
		return $iInt;
	}

	public function get16bitIntBigEndian(int $iStart): int
	{
		$sStr = substr((string) $this->sData,$iStart-1,2);
		$aInt = unpack('n',$sStr);
		if($aInt === false){
			return 0;
		}
		return $aInt[1];
	}


}
