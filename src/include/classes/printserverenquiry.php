<?php
/**
 * This file contains the printserverenquiry class
 *
 * @author John Brown <john@home-lan.co.uk>
 * @package coreprotocol
*/

/** 
 * This class is used to repressent a print server enquiry
 *
 * @package coreprotocol
*/
class printserverenquiry extends request{


	protected $sData = NULL;

	public function __construct($oEconetPacket)
	{
		parent::__construct($oEconetPacket);
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
		return new reply($this);
	}

}
