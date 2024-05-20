<?php
/**
 * This file contains the BeebTermRequest class
 *
 * @author John Brown <john@home-lan.co.uk>
 * @package coreprotocol
*/
namespace HomeLan\FileStore\Messages; 

use HomeLan\FileStore\Messages\EconetPacket; 
use Exception; 

/** 
 * This class is used to repressent a message from a Beeb Term  Client
 *
 * @package coreprotocol
*/
class BeebTermRequest extends Request{


	private ?string $sType = null;
	private ?string $sService = null;
	private int $iRxSeq = 0;
	private int $iTxSeq = 0;

	public function __construct(EconetPacket $oEconetPacket, \Psr\Log\LoggerInterface $oLogger)
	{
		parent::__construct($oEconetPacket, $oLogger);
		$this->decode($oEconetPacket->getData());
	}	

	public function getReplyPort(): int
	{
		return 0xa2;
	}

	/**
	  * Decodes an AUN packet 
	  *
	*/
	public function decode(string $sBinaryString): void
	{
		$this->sData = $sBinaryString;
		switch($this->getFlags()){
			case 0x0:
			case 0x80:
				//Data
				$this->sData = $sBinaryString;
				$this->iRxSeq = $this->getByte(1);
				$this->iTxSeq = $this->getByte(2);
				$this->sData = substr($sBinaryString,2);
				$this->sType = 'DATA' ;
				break;
			case 0x1:
			case 0x81:
				//Login
				$this->sType = 'LOGIN';
				$this->sService = $this->getString(9);
				break;
			case 0x2:
			case 0x82:
				//Login ack
				$this->sType = 'LOGIN_OK';
				break;
			case 0x3:
			case 0x83:
				//Login Reject
				$this->sType = 'LOGIN_REJECT';
				break;
			case 0x4:
			case 0x84:
				//Terminate
				$this->sType = 'TERMINATE';
				break;
		}
		
	}

	public function getType():string
	{
		return $this->sType;
	}

	public function getService():string 
	{
		return $this->sService;
	}

	public function getRxSeq():int
	{
		return $this->iRxSeq;
	}

	public function getTxSeq():int
	{
		return $this->iTxSeq;
	}

	public function buildReply(): \HomeLan\FileStore\Messages\Reply
	{
		return new Reply($this);
	}

}
