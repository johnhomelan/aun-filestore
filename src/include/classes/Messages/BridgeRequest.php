<?php
/**
 * This file contains the bridgerequest class
 *
 * @author John Brown <john@home-lan.co.uk>
 * @package coreprotocol
*/
namespace HomeLan\FileStore\Messages; 

use Exception; 

/** 
 * This class is used to repressent a file server request
 *
 * @package coreprotocol
*/
class BridgeRequest extends Request {


	protected $iReplyPort = NULL;
	
	protected $iFunction = NULL;

	protected $iUrd = NULL;

	protected $iCsd = NULL;

	protected $iLib = NULL;

	protected $sData = NULL;

	protected $aFunctionMap = array(0x80=>'EC_BR_QUERY',0x81=>'EC_BR_QUERY2',0x82=>'EC_BR_LOCALNET',0x83=>'EC_BR_NETKNOWN');


	public function __construct($oEconetPacket, \Psr\Log\LoggerInterface $oLogger)
	{
		parent:: __construct($oEconetPacket,$oLogger);
		$this->decode($oEconetPacket->getData());
	}	

	public function getReplyPort()
	{
		return $this->iReplyPort;
	}


	public function getFunction()
	{
		if(is_numeric($this->iFunction)){
			if(isset($this->aFunctionMap[$this->iFunction])){
				return $this->aFunctionMap[$this->iFunction];
			}
			$this->oLogger->debug("No function to map on to ".$this->iFunction);
		}
		throw new Exception("No packet was decoded unable to getFunction");
	}

	/**
	 * Decodes an AUN packet 
	 *
	 * @param string $sBinaryString
	*/
	public function decode(string $sBinaryString): void
	{
		//Read the function code 1 byte unsigned int
		$aHeader=unpack('C',$sBinaryString);
		$this->iFunction = $aHeader[1];
		$sBinaryString = substr($sBinaryString,1);

		//All bridge requests contain the string "Bridge"
		$aHeader=unpack('CCCCCC',$sBinaryString);
		$sBinaryString = substr($sBinaryString,5);
		if(implode('',$aHeader)!=='Bridge'){
			$this->oLogger->debug("An invalid bridge request was received (it did not begin with the string Bridge)");
			throw new Exception("Invalid bridge request");
		}

		//Read the reply port type 1 byte unsigned int
		$aHeader=unpack('C',$sBinaryString);
		$this->iReplyPort = $aHeader[1];
		$sBinaryString = substr($sBinaryString,1);
	
		//The reset is data
		$this->sData = $sBinaryString;
		
	}

	public function buildReply(): \HomeLan\FileStore\Messages\FsReply
	{
		return new FsReply($this);
	}
}
