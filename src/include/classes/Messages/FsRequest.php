<?php
/**
 * This file contains the fsrequest class
 *
 * @author John Brown <john@home-lan.co.uk>
 * @package coreprotocol
*/
namespace HomeLan\FileStore\Messages; 

use HomeLan\FileStore\Messages\EconetPacket; 
use Exception; 

/** 
 * This class is used to repressent a file server request
 *
 * @package coreprotocol
*/
class FsRequest extends Request {


	protected ?int $iReplyPort = NULL;
	
	protected ?int $iFunction = NULL;

	protected ?int $iUrd = NULL;

	protected ?int $iCsd = NULL;

	protected ?int $iLib = NULL;

	/**
	 * @var  array<int, string>
	*/  
	protected array $aFunctionMap = [0=>'EC_FS_FUNC_CLI', 1=>'EC_FS_FUNC_SAVE', 2=>'EC_FS_FUNC_LOAD', 3=>'EC_FS_FUNC_EXAMINE', 4=>'EC_FS_FUNC_CAT_HEADER', 5=>'EC_FS_FUNC_LOAD_COMMAND', 6=>'EC_FS_FUNC_OPEN', 7=>'EC_FS_FUNC_CLOSE', 8=>'EC_FS_FUNC_GETBYTE', 9=>'EC_FS_FUNC_PUTBYTE', 10=>'EC_FS_FUNC_GETBYTES', 11=>'EC_FS_FUNC_PUTBYTES', 12=>'EC_FS_FUNC_GET_ARGS', 13=>'EC_FS_FUNC_SET_ARGS', 17=>'EC_FS_FUNC_GET_EOF', 14=>'EC_FS_FUNC_GET_DISCS', 18=>'EC_FS_FUNC_GET_INFO', 19=>'EC_FS_FUNC_SET_INFO', 21=>'EC_FS_FUNC_GET_UENV', 23=>'EC_FS_FUNC_LOGOFF', 15=>'EC_FS_FUNC_GET_USERS_ON', 24=>'EC_FS_FUNC_GET_USER', 16=>'EC_FS_FUNC_GET_TIME', 22=>'EC_FS_FUNC_SET_OPT4', 20=>'EC_FS_FUNC_DELETE', 25=>'EC_FS_FUNC_GET_VERSION', 26=>'EC_FS_FUNC_GET_DISC_FREE', 27=>'EC_FS_FUNC_CDIRN', 29=>'EC_FS_FUNC_CREATE', 30=>'EC_FS_FUNC_GET_USER_FREE',31=>'EC_FS_FUNC_SET_USER_FREE',32=>'EC_FS_FUNC_WHO_AM_I',33=>'EC_FS_FUNC_USERS_EXT',34=>'EC_FS_FUNC_USER_INFO_EXT',35=>'EC_FS_FUNC_COPY_DATA'];

	public function __construct(EconetPacket $oEconetPacket, \Psr\Log\LoggerInterface $oLogger)
	{
		parent:: __construct($oEconetPacket, $oLogger);
		$this->decode($oEconetPacket->getData());
	}	

	public function getReplyPort():int
	{
		return $this->iReplyPort;
	}

	public function getUrd():?int
	{
		return $this->iUrd;
	}

	public function getCsd():?int
	{
		return $this->iCsd;
	}

	public function getLib():?int
	{
		return $this->iLib;
	}

	public function getFunction():string
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
	  */
	public function decode(string $sBinaryString): void
	{
		//Read the header

		//Read the reply port type 1 byte unsigned int
		$aHeader=unpack('C',$sBinaryString);
		$this->iReplyPort = $aHeader[1];
		$sBinaryString = substr($sBinaryString,1);
		
		//Read the function code 1 byte unsigned int
		$aHeader=unpack('C',$sBinaryString);
		$this->iFunction = $aHeader[1];
		$sBinaryString = substr($sBinaryString,1);
	
		//Read the urd code 1 byte unsigned int
		$aHeader=unpack('C',$sBinaryString);
		$this->iUrd = $aHeader[1];
		$sBinaryString = substr($sBinaryString,1);

		//Read the csd code 1 byte unsigned int
		$aHeader=unpack('C',$sBinaryString);
		$this->iCsd = $aHeader[1];
		$sBinaryString = substr($sBinaryString,1);

		//Read the lib code 1 byte unsigned int
		if(strlen($sBinaryString)>0){
			$aHeader=unpack('C',$sBinaryString);
			$this->iLib = $aHeader[1];
			$sBinaryString = substr($sBinaryString,1);
		}
	
		//The reset is data
		$this->sData = $sBinaryString;
		
	}

	public function buildReply(): \HomeLan\FileStore\Messages\FsReply
	{
		return new FsReply($this);
	}
}
