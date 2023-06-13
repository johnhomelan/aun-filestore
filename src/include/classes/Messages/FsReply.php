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
 * This class is used to repressent file server replys
 *
 * @package coreprotocol
*/
class FsReply extends Reply {

	protected $sPkt = NULL;
	
	protected $oRequest = NULL;

	protected $iFlags = NULL;


	protected $aTypeMap = ['DONE'=>0, 'SAVE'=>1, 'LOAD'=>2, 'CAT'=>3, 'INFO'=>4, 'LOGIN'=>5, 'SDISC'=>6, 'DIR'=>7, 'UNREC'=>8, 'LIB'=>9, 'DISCS'=>10];

	/**
	 * Sets the reply to be an error indicator
	 *
	 * @param int $iCode Error code 0-254
	 * @param string $sMessage The message for the error
	*/
	public function setError(int $iCode,string $sMessage): void
	{
		if(is_numeric($iCode) AND $iCode>0 AND $iCode<256){
			$this->sPkt = pack('CC',$this->aTypeMap['DONE'],$iCode);
			$sMessage = $sMessage."\r";
			$aMessage = str_split($sMessage);
			foreach($aMessage as $sChar){
				$this->sPkt = $this->sPkt.pack('C',ord($sChar));
			}
		}else{
			throw new Exception("Fsreply: Invaild error code ".$iCode);
		}
	}

	public function loginRespone($iUrd,$iCsd,$iLib,$iOpt): void
	{
		$this->sPkt = pack('CCCCCC',$this->aTypeMap['LOGIN'],0,$iUrd,$iCsd,$iLib,$iOpt);
	}

	public function Done(): void
	{
		$this->sPkt = pack('C',$this->aTypeMap['DONE']);
	}

	public function DoneOK(): void
	{
		$this->sPkt = pack('CC',$this->aTypeMap['DONE'],0);
	}

	public function DirOK(): void
	{
		$this->sPkt = pack('CC',$this->aTypeMap['DIR'],0);
	}

	public function LibOK(): void
	{
		$this->sPkt = pack('CC',$this->aTypeMap['LIB'],0);
	}

	public function UnrecognisedOk(): void
	{
		$this->sPkt = pack('CC',$this->aTypeMap['UNREC'],0);
	}

	public function DiscsOk(): void
	{
		$this->sPkt = pack('CC',$this->aTypeMap['DISCS'],0);
	}
	
	public function DoneNoton(): void
	{
		$this->sPkt = pack('CC',$this->aTypeMap['DONE'],0xAE);
	}

	public function InfoOk(): void
	{
		$this->sPkt = pack('CC',$this->aTypeMap['INFO'],0);
	}

}
