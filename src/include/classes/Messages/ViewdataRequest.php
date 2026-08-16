<?php
/**
 * This file contains the ViewdataRequest class
 *
 * @author John Brown <john@home-lan.co.uk>
 * @package coreprotocol
*/
namespace HomeLan\FileStore\Messages;

use HomeLan\FileStore\Messages\EconetPacket;

/**
 * This class is used to repressent a message from a Viewdata client
 *
 * @package coreprotocol
*/
class ViewdataRequest extends Request{

	private ?string $sType = null;
	private int $iRxSeq = 0;
	private int $iTxSeq = 0;

	public function __construct(EconetPacket $oEconetPacket, \Psr\Log\LoggerInterface $oLogger)
	{
		parent::__construct($oEconetPacket, $oLogger);
		$this->decode($oEconetPacket->getData() ?? '');
	}

	public function getReplyPort(): int
	{
		return 0xa3;
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
				$this->iRxSeq = $this->getByte(1) ?? 0;
				$this->iTxSeq = $this->getByte(2) ?? 0;
				$this->sData = substr($sBinaryString,2);
				$this->sType = 'DATA' ;
				break;
			case 0x1:
			case 0x81:
				//Login
				$this->sType = 'LOGIN';
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

	public function getType():?string
	{
		return $this->sType;
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
