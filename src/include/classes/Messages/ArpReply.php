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

	protected $sPkt = NULL;
	
	protected $oRequest = NULL;

	protected $iFlags = NULL;

	protected ?int $iArpResponseNetwork = NULL;

	protected ?int $iArpResponseStation = NULL;

	protected ?string iArpResponseIPv4 = NULL;

	public function	setHwAddr(int $iNetwork, int $iStation)
	{
		$this->iArpResponseNetwork = $iNetwork;
		$this->iArpResponseStation = $iStation;
	}

	public function setIPv4Addr(string $sIP)
	{
		$this->iArpResponseIPv4 = $sIP;
	}


	public function buildEconetpacket(): \HomeLan\FileStore\Messages\EconetPacket
	{
		
		return parent::buildEconetpacket();
	}
}
