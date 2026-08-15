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
		$this->decode($oEconetPacket->getData() ?? '');
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
			case 0x22: //ARP Is-At: DCI-2/AUN wire value
			case 0xA2: //ARP Is-At: DCI-4 native Econet wire value
				//The first 4 bytes is the ipv4 addr the IsAt message is about
				$sSourceIP = inet_ntop($sBinaryString[0].$sBinaryString[1].$sBinaryString[2].$sBinaryString[3]);
				$this->sSourceIP = ($sSourceIP === false) ? null : $sSourceIP;
				//The second 4 bytes is the ipv4 address the host rquesting the arp response
				$sRespodingToIP = inet_ntop($sBinaryString[4].$sBinaryString[5].$sBinaryString[6].$sBinaryString[7]);
				$this->sRespodingToIP = ($sRespodingToIP === false) ? null : $sRespodingToIP;
				break;
		}
		
	}

	public function getReplyPort():int
	{
		return 0xd2;
	}

	public function getSourceIP():string
	{
		return $this->sSourceIP ?? '';
	}

	public function getSourceStation():int
	{
		if($this->iSourceStation === null){
			throw new Exception("ArpIsAt has no source station");
		}
		return $this->iSourceStation;
	}

	public function getSourceNetwork():int
	{
		if($this->iSourceNetwork === null){
			throw new Exception("ArpIsAt has no source network");
		}
		return $this->iSourceNetwork;
	}

	public function getDestinationIp():string
	{
		return $this->sRespodingToIP ?? '';
	}

}
