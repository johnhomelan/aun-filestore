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
 * This class is used to repressent a file server request
 *
 * @package coreprotocol
*/
class ArpRequest extends Request {


	protected $sIPv4Addr = NULL;

	//The types use by econet to presesent the std arp operations
	private  array $aArpTypes = [0x0a=>'ECOTYPE_ARP',0x09=>'ECOTYPE_REVARP',0x20=>'ECOTYPE_ARP_REPLY',0x21=>'ECOTYPE_ARP_REQUEST',0x22=>'ECOTYPE_ARP_REPLY',0x23=>'ECOTYPE_REVARP_REQUEST',0x24=>'ECOTYPE_REVARP_REPLY'];

	private array $aArpOps = [1=>'ARPOP_REQUEST',2=>'ARPOP_REPLY',3=>'ARPOP_RREQUEST',4=>'ARPOP_RREPLY',8=>'ARPOP_InREQUEST',9=>'ARPOP_InREPLY',10=>'ARPOP_NAK'];
	
	public function __construct($oEconetPacket, \Psr\Log\LoggerInterface $oLogger)
	{
		parent:: __construct($oEconetPacket, $oLogger);
		$this->decode($oEconetPacket->getData());
	}	

	/**
	  * Decodes an AUN packet 
	  *
	*/
	public function decode(string $sBinaryString): void
	{

		switch($this->aArpTypes[$this->getFlags()]){
			case 'ECOTYPE_ARP':
				break;
			case 'ECOTYPE_REVARP':
				break;
		}
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

	public function buildReply(): \HomeLan\FileStore\Messages\ArpReply
	{
		return new ArpReply($this);
	}
}
