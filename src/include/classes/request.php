<?
/**
 * This file contains the fsrequest class
 *
 * @author John Brown <john@home-lan.co.uk>
 * @package coreprotocol
*/

/** 
 * This class is used to repressent a request
 *
 * @package coreprotocol
*/
class request {

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
		$aBytes = unpack('C*',$this->sData);
		if(array_key_exists($iIndex,$aBytes)){
			return $aBytes[$iIndex];
		}
		return NULL;
	}

	public function getString($iStart)
	{
		$aBytes = unpack('C*',$this->sData);
		$sRetstr = "";
		for($i=$iStart;$i<count($aBytes);$i++){
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
		$sStr = substr($this->sData,$iStart-1,4);
		$aInt = unpack('V',$sStr);
		return $aInt[1];
	}

	public function get24bitIntLittleEndian($iStart)
	{
		$aBytes = unpack('C*',$this->sData);
		$iInt= bindec(str_pad(decbin($aBytes[$iStart+2]),8,"0",STR_PAD_LEFT).str_pad(decbin($aBytes[$iStart+1]),8,"0",STR_PAD_LEFT).str_pad(decbin($aBytes[$iStart]),8,"0",STR_PAD_LEFT));
		return $iInt;
	}

	public function get16bitIntLittleEndian($iStart)
	{
		$sStr = substr($this->sData,$iStart-1,2);
		$aInt = unpack('v',$sStr);
	}

}
