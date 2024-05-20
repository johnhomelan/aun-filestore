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
class ArpIsAt extends Request {


	private ?string $sSourceIP = NULL;
	private ?string $sRespodingToIP = NULL;

	public function __construct(EconetPacket  $oEconetPacket, \Psr\Log\LoggerInterface $oLogger)
	{
		parent:: __construct($oEconetPacket, $oLogger);
		$this->decode($oEconetPacket->getData());
		$this->iSourceStation = $oEconetPacket->getSourceStation();
		$this->iSourceNetwork = $oEconetPacket->getSourceNetwork();
	}	

	/**
	  * Decodes an arp request
	  *
	*/
	public function decode(string $sBinaryString): void
	{

		switch($this->getFlags()){
			case 0xA2: //Arp reponse type
				//The first 4 bytes is the ipv4 addr the IsAt message is about 
				$this->sSourceIP = inet_ntop($sBinaryString[0].$sBinaryString[1].$sBinaryString[2].$sBinaryString[3]);
				//The second 4 bytes is the ipv4 address the host rquesting the arp response
				$this->sRespodingToIP = inet_ntop($sBinaryString[4].$sBinaryString[5].$sBinaryString[6].$sBinaryString[7]);
				break;
		}
		
	}

	public function getReplyPort():int
	{
		return 0xd2;
	}

	public function getSourceIP():string
	{
		return $this->sSourceIP;
	}

	public function getSourceStation():int
	{
		return $this->iSourceStation;
	}

	public function getSourceNetwork():int
	{
		return $this->iSourceNetwork;
	}

	public function getDestinationIp():string
	{
		return $this->sRespodingToIP;
	}

}
