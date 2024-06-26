<?php
/**
 * This file contains the fsreply class
 *
 * @author John Brown <john@home-lan.co.uk>
 * @package coreprotocol
*/
namespace HomeLan\FileStore\Messages; 

use Exception; 

/** 
 * This class is used to repressent file server replys
 *
 * @package coreprotocol
*/
class ArpReply extends Reply {


	protected ?int $iArpResponseNetwork = NULL;

	protected ?int $iArpResponseStation = NULL;

	protected ?string $iArpResponseIPv4 = NULL;

	public function	setHwAddr(int $iNetwork, int $iStation):void
	{
		$this->iArpResponseNetwork = $iNetwork;
		$this->iArpResponseStation = $iStation;
	}

	public function setIPv4Addr(string $sIP):void
	{
		$this->iArpResponseIPv4 = $sIP;
	}



	public function buildEconetpacket(): \HomeLan\FileStore\Messages\EconetPacket
	{
		$this->iFlags = 0xa2; //Arp reply type
		$this->sPkt .= inet_pton($this->oRequest->getRequestedIP());
		$this->sPkt .= inet_pton($this->oRequest->getSourceIP());
	
		return parent::buildEconetpacket();
	}
}
