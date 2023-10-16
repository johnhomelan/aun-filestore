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
class TcpIpReply extends Reply {

	protected $sPkt = NULL;
	private ?string $sSrcIP = NULL;
	private ?string $sDstIP = NULL;

	private string $sFullPacket;
	private int $iVerLength;
	private int $iTos;
	private int $iLength;
	private int $iPtkId;
	private int $iFlagOffset;
	private int $iTtl;
	private int $iChecksum;
	private int $iIpHeaderLength;
	private int $iVersion;

	private int $iSrcPort;
	private int $iDstPort;
	private int $iSeq;
	private int $iAck;
	private int $iWindow;
	private bool $bSyn;
	private bool $bFin;
	private bool $bAck;
	private bool $bNonce;
	private bool $bCrw;
	private bool $bEcn;
	private bool $bUrgent;
	private bool $bPush;
	private bool $bReset;
	private string $sData;

	public function __construct()
	{
	}
	
	public function buildEconetpacket(): \HomeLan\FileStore\Messages\EconetPacket
	{		
		//IPv4 Header
		
		//First byte is the version/internet header length (fisrt 4 bits being the version)
		$iVerson = 4 << 4;
		$this->appendByte($iVersion & $this->getIpHeaderLength());

		//2nd byte is the Type of service
		$this->appendByte($iTos);

		//Bytes 3,4 are a 16bit int with the total length of the packet including the header and data 
		$this->iLength = $this->append16bitIntLittleEndian($this->getIpHeaderLength()+$this->getTcpHeaderLength()+strlen($this->sData));

		//Bytes 5,6 are a 16bit int is the identification number of the packet, this ids the packet if its broken up into smaller chunks 
		$this->append16bitIntLittleEndian($this->iPktId);

		//Bytes 7,8 3 bit IP flags, 13 bit segment offset 
		$this->append16bitIntLittleEndian($this->iFlagOffset);

		//Byte 9 TTL
		$this->appendByte($this->iTtl);

		//Byte 10 Protocol e.g. TCP, UDP, ICMP (0x06 is TCP)
		$this->appendByte(0x06);

		//Bytes 11-12 Header checksum
		$this->append16bitIntLittleEndian($this->calculateIPCheckSum());

		//Bytes 13,16 Source IP address 
		$this->append32bitIntLittleEndian(inet_pton($this->sSrcIP));
			
		//Bytes 17,20 Dest IP Address 
		$this->append32bitIntLittleEndian(inet_pton($this->sDstIP));


		//TCP Header

		//Bytes 1 - 2 are a 16bit int for the Source port 
		$this->append16bitIntLittleEndian($this->iSrcPort);

		//Bytes 3 - 4 are a 16bit int for the Destination port
		$this->append16bitIntLittleEndian($this->iDstPort);

		//Bytes 5 - 8 is a 32bit int, used for the sequence number
		$this->iSeq = $this->get32bitIntLittleEndian(0);

		//Bytes 9 - 12 is a 32bit int, used for the ack number
		$this->iAck = $this->get32bitIntLittleEndian(0);

		//Byte 13 - 14 Are the flags and do number 
		$sDoRsvFlags1 = $this->bNonce  ? 1 : 0;
		$sDoRsvFlags2 = $this->bCrw    ? $sDoRsvFlags2 + 128 : $sDoRsvFlags2;
		$sDoRsvFlags2 = $this->bEcn    ? $sDoRsvFlags2 + 64  : $sDoRsvFlags2;
		$sDoRsvFlags2 = $this->bUrgent ? $sDoRsvFlags2 + 32  : $sDoRsvFlags2;
		$sDoRsvFlags2 = $this->bAck    ? $sDoRsvFlags2 + 16  : $sDoRsvFlags2;
		$sDoRsvFlags2 = $this->bPush   ? $sDoRsvFlags2 + 8   : $sDoRsvFlags2;
		$sDoRsvFlags2 = $this->bReset  ? $sDoRsvFlags2 + 4   : $sDoRsvFlags2;
		$sDoRsvFlags2 = $this->bSyn    ? $sDoRsvFlags2 + 2   : $sDoRsvFlags2;
		$sDoRsvFlags2 = $this->bFin    ? $sDoRsvFlags2 + 1   : $sDoRsvFlags2;
		$this->appendByte($sDoRsvFlags1);
		$this->appnedByte($sDoRsvFlags2);


		//Bytes 15 - 16 16bit int for the Window
		$this->iWindow =  $this->append16bitIntLittleEndian($this->iWindow;
		
		//Bytes 17 -18 16bit int for the TCP checksum
		$this->append16bitIntLittleEndian($this->getTcpChecksum());

		//Bytes 19-20 The urgent number
		$this->append16bitIntLittleEndian($this->iUrgent);

		//The data for the TCP stream 	
		$this->appendString($this->sData);
	
		$oEconetPacket = new EconetPacket();
		$oEconetPacket->setPort(0xd2);  //The service port for EconetA IPv4
		$oEconetPacket->setFlags(0x1);  //Denotes the packets as a regular IP packet and not arp etc.
		$oEconetPacket->setDestinationStation($this->getSourceStation());
		$oEconetPacket->setDestinationNetwork($this->getSourceNetwork());
		$oEconetPacket->setData($this->sPkt);
		return $oEconetPacket;
	}

}
