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
class ArpRequest extends Request {


	private ?string $sSourceIP = NULL;
	private ?string $sIPv4Addr = NULL;

	//The types use by econet to presesent the std arp operations
	//private  array $aArpTypes = [0x0a=>'ECOTYPE_ARP',0x09=>'ECOTYPE_REVARP',0x20=>'ECOTYPE_ARP_REPLY',0x21=>'ECOTYPE_ARP_REQUEST',0x22=>'ECOTYPE_ARP_REPLY',0x23=>'ECOTYPE_REVARP_REQUEST',0x24=>'ECOTYPE_REVARP_REPLY'];

	//private array $aArpOps = [1=>'ARPOP_REQUEST',2=>'ARPOP_REPLY',3=>'ARPOP_RREQUEST',4=>'ARPOP_RREPLY',8=>'ARPOP_InREQUEST',9=>'ARPOP_InREPLY',10=>'ARPOP_NAK'];
	
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
			case 33: //Arp request type
				//The first 4 bytes is the ipv4 addr of the requesting host
				$this->sSourceIP = inet_ntop($sBinaryString[0].$sBinaryString[1].$sBinaryString[2].$sBinaryString[3]);
				//The second 4 bytes is the ipv4 address the remote host is requesting the layer address for 
				$this->sIPv4Addr = inet_ntop($sBinaryString[4].$sBinaryString[5].$sBinaryString[6].$sBinaryString[7]);
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

	public function getRequestedIP():string
	{
		return $this->sIPv4Addr;
	}

	public function getSourceStation():int
	{
		return $this->iSourceStation;
	}

	public function getSourceNetwork():int
	{
		return $this->iSourceNetwork;
	}

	
	public function buildReply(): \HomeLan\FileStore\Messages\ArpReply
	{
		return new ArpReply($this);
	}
}
