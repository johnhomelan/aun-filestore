<?php
/**
 * This file contains the TcpIpReply class
 *
 * @author John Brown <john@home-lan.co.uk>
 * @package coreprotocol
*/
namespace HomeLan\FileStore\Messages; 

use Exception; 

/** 
 * This class is used to repressent tcp/ip packets created by the NAT service 
 *
 * @package coreprotocol
*/
class TcpIPReply extends Reply {

	protected $sPkt = NULL;
	private int $iSrcNetwork;
	private int $iSrcStation;

	private ?string $sSrcIP = NULL;
	private ?string $sDstIP = NULL;

	private int $iTos = 0x50;
	private int $iPktId;
	private int $iFlagOffset = 16384;  // 2 << 13 (Sets the dont fragment flag)
	private int $iTtl = 64 ;
	private int $iChecksum = 0;
	private int $iVersion = 4;

	private int $iSrcPort;
	private int $iDstPort;
	private int $iSeq;
	private int $iAck;
	private int $iWindow = 65536;
	private int $iUrgent = 0;
	private bool $bSyn = false;
	private bool $bFin = false; 
	private bool $bAck = false; 
	private bool $bNonce = false;
	private bool $bCrw = false;
	private bool $bEcn = false;
	private bool $bUrgent = false;
	private bool $bPush = false;
	private bool $bReset = false;
	private string $sData = "";

	public function __construct()
	{
	}
	
	public function buildEconetpacket(): \HomeLan\FileStore\Messages\EconetPacket
	{		
		//IPv4 Header
		
		//First byte is the version/internet header length (fisrt 4 bits being the version)
		$iVersion = $this->iVersion << 4;
		$this->appendByte($iVersion + $this->getIpHeaderLength());
		//2nd byte is the Type of service
		$this->appendByte($this->iTos);

		//Bytes 3,4 are a 16bit int with the total length of the packet including the header and data 
		$this->append16bitIntBigEndian(40+strlen($this->sData));

		//Bytes 5,6 are a 16bit int is the identification number of the packet, this ids the packet if its broken up into smaller chunks 
		$this->append16bitIntBigEndian($this->iPktId);

		//Bytes 7,8 3 bit IP flags, 13 bit segment offset 
		$this->append16bitIntBigEndian($this->iFlagOffset);

		//Byte 9 TTL
		$this->appendByte($this->iTtl);

		//Byte 10 Protocol e.g. TCP, UDP, ICMP (0x06 is TCP)
		$this->appendByte(0x06);

		//Bytes 11-12 Header checksum (the intial value is 0, the checksum is calculated once the packet is fully created and needs the initial value to be 0)
		$iIPCheckSumPos = strlen($this->sPkt);
		$this->append16bitIntBigEndian($this->iChecksum);

		//Bytes 13,16 Source IP address 
		$this->sPkt = $this->sPkt.inet_pton($this->sSrcIP);
			
		//Bytes 17,20 Dest IP Address 
		$this->sPkt = $this->sPkt.inet_pton($this->sDstIP);


		//TCP Header
		$iTcpPos = strlen($this->sPkt);

		//Bytes 1 - 2 are a 16bit int for the Source port 
		$this->append16bitIntBigEndian($this->iSrcPort);

		//Bytes 3 - 4 are a 16bit int for the Destination port
		$this->append16bitIntBigEndian($this->iDstPort);

		//Bytes 5 - 8 is a 32bit int, used for the sequence number
		$this->append32bitIntBigEndian($this->iSeq);

		//Bytes 9 - 12 is a 32bit int, used for the ack number
		$this->append32bitIntBigEndian($this->iAck);

		//Byte 13 - 14 Are the flags and do number 
		$sDoRsvFlags1 = $this->getTcpHeaderLength()<<4;
		$sDoRsvFlags2 = 0;
		//$sDoRsvFlags1 = $this->bNonce  ? 1 : 0;
		$sDoRsvFlags2 = $this->bCrw    ? $sDoRsvFlags2 + 128 : $sDoRsvFlags2;
		$sDoRsvFlags2 = $this->bEcn    ? $sDoRsvFlags2 + 64  : $sDoRsvFlags2;
		$sDoRsvFlags2 = $this->bUrgent ? $sDoRsvFlags2 + 32  : $sDoRsvFlags2;
		$sDoRsvFlags2 = $this->bAck    ? $sDoRsvFlags2 + 16  : $sDoRsvFlags2;
		$sDoRsvFlags2 = $this->bPush   ? $sDoRsvFlags2 + 8   : $sDoRsvFlags2;
		$sDoRsvFlags2 = $this->bReset  ? $sDoRsvFlags2 + 4   : $sDoRsvFlags2;
		$sDoRsvFlags2 = $this->bSyn    ? $sDoRsvFlags2 + 2   : $sDoRsvFlags2;
		$sDoRsvFlags2 = $this->bFin    ? $sDoRsvFlags2 + 1   : $sDoRsvFlags2;
		$this->appendByte($sDoRsvFlags1);
		$this->appendByte($sDoRsvFlags2);


		//Bytes 15 - 16 16bit int for the Window
		//$this->append16bitIntBigEndian($this->iWindow);
		$this->appendByte(255);
		$this->appendByte(255);
		//Bytes 17 -18 16bit int for the TCP checksum (the intial value is 0, the checksum is calculated once the packet is fully created and needs the initial value to be 0)
		$iTcpCheckSumPos = strlen($this->sPkt);
		$this->append16bitIntBigEndian(0);

		//Bytes 19-20 The urgent number
		$this->append16bitIntBigEndian($this->iUrgent);

		//The data for the TCP stream 	
		$this->appendString($this->sData);

		//Calclate the TCP checksum (its must be offset from where the tcp header begins)
		$sTcpCheckSum = $this->calculateCheckSum($iTcpPos);

		//Update the TCP/Checksum field in the packet 
		$this->sPkt = substr_replace($this->sPkt,$sTcpCheckSum,$iTcpCheckSumPos,strlen($sTcpCheckSum));

		//Update the IP/Checksum field in the packet 
		$sIpCheckSum = $this->calculateCheckSum(0,20);
		$this->sPkt = substr_replace($this->sPkt,$sIpCheckSum,$iIPCheckSumPos,strlen($sIpCheckSum));
	
		//Build the econet packet containing the IP packet 
		$oEconetPacket = new EconetPacket();
		$oEconetPacket->setPort(0xd2);  //The service port for EconetA IPv4
		$oEconetPacket->setFlags(0x1);  //Denotes the packets as a regular IP packet and not arp etc.
		$oEconetPacket->setDestinationStation($this->iSrcStation);
		$oEconetPacket->setDestinationNetwork($this->iSrcNetwork);
		$oEconetPacket->setData($this->sPkt);
		return $oEconetPacket;
	}

	private function getIpHeaderLength():int
	{
		//Number of 32bit blocks 
		return (20*8)/32;
	}

	private function getTcpHeaderLength():int
	{
		return (20*8)/32;
	}

	/**
 	 * Calculates out the checksum for the data stored in $this->sPkt
 	 *
 	 * It can calculate the checksum from a sub part of the packet 
 	 *
 	 *  It is computed as the 16 bit one's complement of the one's Complement sum of all 16 bit words 
 	 *  in part of the packet thats been selected.
 	*/    	
	private function calculateCheckSum(int $iStart, ?int $iLength = null):string
	{

		$sBuffer = substr($this->sPkt,$iStart,$iLength)."\x0";
		$aPairs = unpack('n*', $sBuffer);
		
		$iSum = array_sum($aPairs);
		while ($iSum >> 16){
			$iSum = ($iSum >> 16) + ($iSum & 0xffff);
		}
		return pack('n', ~$iSum);
	}

	public function setSrcNetwork(int $iNetwork):void
	{
		$this->iSrcNetwork = $iNetwork;
	}

	public function setSrcStation(int $iStation):void
	{
		$this->iSrcStation = $iStation;
	}

	public function setDstIP(string $sIP):void
	{
		$this->sDstIP = $sIP;
	}

	public function setSrcIP(string $sIP):void
	{
		$this->sSrcIP = $sIP;
	}

	public function setDstPort(int $iPort):void
	{
		$this->iDstPort = $iPort;
	}

	public function setSrcPort(int $iPort):void
	{
		$this->iSrcPort = $iPort;
	}

	public function setFlagNonce(bool $bValue):void
	{
		$this->bNonce = $bValue;
	}

	public function setFlagCrw(bool $bValue):void
	{
		$this->bCrw = $bValue;
	}

	public function setFlagEcn(bool $bValue):void
	{
		$this->bEcn = $bValue;
	}

	public function setFlagUrgent(bool $bValue):void
	{
		$this->bUrgent = $bValue;
	}

	public function setFlagPush(bool $bValue):void
	{
		$this->bPush = $bValue;
	}

	public function setFlagReset(bool $bValue):void
	{
		$this->bReset = $bValue;
	}

	public function setFlagSyn(bool $bValue):void
	{
		$this->bSyn = $bValue;
	}

	public function setFlagAck(bool $bValue):void
	{
		$this->bAck = $bValue;
	}

	public function setFlagFin(bool $bValue):void
	{
		$this->bFin = $bValue;
	}

	public function setData(string $sData):void
	{
		$this->sData = $sData;
	}

	public function setSeqNumber(int $iNumber):void
	{
		$this->iSeq = $iNumber;
	}

	public function setAckNumber(int $iNumber):void
	{
		$this->iAck = $iNumber;
	}

	public function setId(int $iNumber):void
	{
		$this->iPktId = $iNumber;
	}

	public function setWindow(int $iNumber):void
	{
		$this->iWindow = $iNumber;
	}

	public function toString():string
	{
		return "TCP:  Src| ".$this->sSrcIP.":".$this->iSrcPort." Dst| ".$this->sDstIP.":".$this->iDstPort."  Seq| ".$this->iSeq." Ack| ".$this->iAck." Syn| ".$this->bSyn." Ack| ".$this->bAck." Fin| ".$this->bFin." Reset|".$this->bReset." Data Len|".strlen($this->sData)." Data|".$this->sData;
	}
 
}
