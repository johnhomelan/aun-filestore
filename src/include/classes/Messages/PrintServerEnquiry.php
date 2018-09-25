<?php
/**
 * This file contains the printserverenquiry class
 *
 * @author John Brown <john@home-lan.co.uk>
 * @package coreprotocol
*/
namespace HomeLan\FileStore\Messages; 

use Exception; 

/** 
 * This class is used to repressent a print server enquiry
 *
 * @package coreprotocol
*/
class PrintServerEnquiry extends Request{


	protected $sData = NULL;

	public function __construct($oEconetPacket, \Psr\Log\LoggerInterface $oLogger)
	{
		parent::__construct($oEconetPacket, $oLogger);
		$this->decode($oEconetPacket->getData());
	}	

	public function getReplyPort()
	{
		return 0x9E;
	}

	/**
	 * Decodes an AUN packet 
	 *
	 * @param string $sBinaryString
	*/
	public function decode($sBinaryString)
	{
	
		//The reset is data
		$this->sData = $sBinaryString;
		
	}

	public function buildReply()
	{
		return new Reply($this);
	}

}
