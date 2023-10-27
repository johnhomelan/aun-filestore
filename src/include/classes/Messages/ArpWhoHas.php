<?php
/**
 * This file contains the arpwhohas request class
 *
 * @author John Brown <john@home-lan.co.uk>
 * @package coreprotocol
*/
namespace HomeLan\FileStore\Messages; 

use Exception; 

/** 
 * This class is used to repressent a aprwhohas request in the EconetA scheme
 *
 * Its use by the system to get an econet network/staion address for a give ip addr
 * @package coreprotocol
*/
class ArpWhoHas extends Reply {


	private ?string $sIPv4Addr = NULL;
	private ?string $sSourceIP = NULL;
	private int $iSourceStation = 0;
	private ?int $iNetwork;

	//The types use by econet to presesent the std arp operations
	//private  array $aArpTypes = [0x0a=>'ECOTYPE_ARP',0x09=>'ECOTYPE_REVARP',0x20=>'ECOTYPE_ARP_REPLY',0x21=>'ECOTYPE_ARP_REQUEST',0x22=>'ECOTYPE_ARP_REPLY',0x23=>'ECOTYPE_REVARP_REQUEST',0x24=>'ECOTYPE_REVARP_REPLY'];

	//private array $aArpOps = [1=>'ARPOP_REQUEST',2=>'ARPOP_REPLY',3=>'ARPOP_RREQUEST',4=>'ARPOP_RREPLY',8=>'ARPOP_InREQUEST',9=>'ARPOP_InREPLY',10=>'ARPOP_NAK'];
	
	public function __construct(string $sSourceIP, string $sIPv4Addr,int $iNetwork,int $iSourceStation)
	{
		$this->sIPv4Addr = $sIPv4Addr;
		$this->sSourceIP = $sSourceIP;
		$this->iNetwork = $iNetwork;
		$this->iSourceStation = $iSourceStation;
		
	}	


	public function getRequestedIP():string
	{
		return $this->sIPv4Addr;
	}

	public function getSourceStation():int
	{
		return $this->iSourceStation;
	}


	public function buildEconetpacket(): \HomeLan\FileStore\Messages\EconetPacket
	{
		//Arp how as request on EconetA 
		$this->sPkt=inet_pton($this->sSourceIP).inet_pton($this->sIPv4Addr);
		$this->appendByte($this->iNetwork);
		$this->appendByte($this->iSourceStation);
		$oEconetPacket = new EconetPacket();
		$oEconetPacket->setPort(0xd2);
		$oEconetPacket->setFlags(33);
		$oEconetPacket->setDestinationStation(255);
		$oEconetPacket->setDestinationNetwork($this->iNetwork);
		$oEconetPacket->setData($this->sPkt);
		return $oEconetPacket;
	}

}
