<?
/**
 * This file contains the fsreply class
 *
 * @author John Brown <john@home-lan.co.uk>
 * @package coreprotocol
*/

/** 
 * This class is used to repressent file server replys
 *
 * @package coreprotocol
*/
class fsreply {

	protected $sPkt = NULL;
	
	protected $oRequest = NULL;

	protected $aTypeMap = array('DONE'=>0,'SAVE'=>1,'LOAD'=>2,'CAT'=>3,'INFO'=>4,'LOGIN'=>5,'SDISC'=>6,'DIR'=>7,'UNREC'=>8,'LIB'=>9,'DISCS'=>10);

	function __construct($oRequest)
	{
		if(is_object($oRequest) AND get_class($oRequest)=='fsrequest'){
			$this->oRequest = $oRequest;
		}else{
			throw new Exception("An fsreply object was created with out suppling an fsrequest object");
		}
	}

	/**
	 * Sets the reply to be an error indicator
	 *
	 * @param int $iCode Error code 0-254
	 * @param string $sMessage The message for the error
	*/
	function setError($iCode,$sMessage)
	{
		if(is_numeric($iCode) AND $iCode>0 AND $iCode<255){
			$this->$sPkt = pack('CCa',$this->aTypeMap['DONE'],$iCode,$sMessage."\r");
		}else{
			throw new Exception("Fsreply: Invaild error code ".$iCode);
		}
	}

	function loginRespone($iUrd,$iCsd,$iLib,$iOpt)
	{
		$this->$sPkt = pack('CCCCCC',$this->aTypeMap['LOGIN'],0,$iUrd,$iCsd,$iLib,$iOpt);
	}

	function DoneOK()
	{
		$this->$sPkt = pack('CC',$this->aTypeMap['DONE'],0);
	}

	function AppendByte($iByte)
	{
		$this->$sPkt = $this->$sPkt.pack('C',$iByte);
	}


}
