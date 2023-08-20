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
 * This class is used to repressent a general IPv4 packet 
 *
 * @package coreprotocol
*/
class IPv4Request extends Request {


	private ?string $sSrcIP = NULL;
	private ?string $sDstIP = NULL;

	private string $sFullPacket;
	private int $iVerLength;
	private int $iTos;
	private int $iLength;
	private int $iPtkId;
	private int $iFlagOffset;
	private int $iTtl;
	private int $iProtocol;
	private int $iChecksum;
	private int $iIpHeaderLength;
	private int $iVersion;

	private array $aProtocols = [0x00=>'HOPOPT',0x01=>'ICMP',0x02=>'IGMP',0x03=>'GGP',0x04=>'IP-in-IP',0x05=>'ST',0x06=>'TCP',0x07=>'CBT',0x08=>'EGP',0x09=>'IGP',0x0A=>'BBN-RCC-MON',0x0B=>'NVP-II',0x0C=>'PUP',0x0D=>'ARGUS',0x0E=>'EMCON',0x0F=>'XNET',0x10=>'CHAOS',0x11=>'UDP',0x12=>'MUX',0x13=>'DCN-MEAS',0x14=>'HMP',0x15=>'PRM',0x16=>'XNS-IDP',0x17=>'TRUNK-1',0x18=>'TRUNK-2',0x19=>'LEAF-1',0x1A=>'LEAF-2',0x1B=>'RDP',0x1C=>'IRTP',0x1D=>'ISO-TP4',0x1E=>'NETBLT',0x1F=>'MFE-NSP',0x20=>'MERIT-INP',0x21=>'DCCP',0x22=>'3PC',0x23=>'IDPR',0x24=>'3XTP',0x25=>'DDP',0x26=>'IDPR-CMTP',0x27=>'TP++',0x28=>'IL',0x29=>'IPv6',0x2A=>'SDRP',0x2B=>'IPv6-Route',0x2C=>'IPv6-Frag',0x2D=>'IDRP',0x2E=>'RSVP',0x2F=>'GRE',0x30=>'DSR',0x31=>'BNA',0x32=>'ESP',0x33=>'AH',0x34=>'I-NLSP',0x35=>'SwIPe',0x36=>'NARP',0x37=>'MOBILE',0x38=>'TLSP',0x39=>'SKIP',0x3A=>'IPv6-ICMP',0x3B=>'IPv6-NoNxt',0x3C=>'IPv6-Opts',0x3D=>'Any-Host',0x3E=>'CFTP',0x3F=>'Any-Net',0x40=>'SAT-EXPAK',0x41=>'KRYPTOLAN',0x42=>'RVD',0x43=>'IPPC',0x44=>'Any-distributed-file-system',0x45=>'AT-MON',0x46=>'VISA',0x47=>'IPCU',0x48=>'CPNX',0x49=>'CPHB',0x4A=>'WSN',0x4B=>'PVP',0x4C=>'BR-SAT-MON',0x4D=>'SUN-ND',0x4E=>'WB-MON',0x4F=>'WB-EXPAK',0x50=>'ISO-IP',0x51=>'VMTP',0x52=>'SECURE-VMTP',0x53=>'VINES',0x54=>'IPTM',0x55=>'NSFNET-IGP',0x56=>'DGP',0x57=>'TCF',0x58=>'EIGRP',0x59=>'OSPF',0x5A=>'Sprite-RPC',0x5B=>'LARP',0x5C=>'MTP',0x5D=>'AX.25',0x5E=>'OS',0x5F=>'MICP',0x60=>'SCC-SP',0x61=>'ETHERIP',0x62=>'ENCAP',0x63=>'Any-private-encryption-scheme',0x64=>'GMTP',0x65=>'IFMP',0x66=>'PNNI',0x67=>'PIM',0x68=>'ARIS',0x69=>'SCPS',0x6A=>'QNX',0x6B=>'A/N',0x6C=>'IPComp',0x6D=>'SNP',0x6E=>'Compaq-Peer',0x6F=>'IPX-in-IP',0x70=>'VRRP',0x71=>'PGM',0x72=>'Any-0-hop-protocol',0x73=>'L2TP',0x74=>'DDX',0x75=>'IATP',0x76=>'STP',0x77=>'SRP',0x78=>'UTI',0x79=>'SMP',0x7A=>'SM',0x7B=>'PTP',0x7C=>'IS-IS',0x7D=>'FIRE',0x7E=>'CRTP',0x7F=>'CRUDP',0x80=>'SSCOPMCE',0x81=>'IPLT',0x82=>'SPS',0x83=>'PIPE',0x84=>'SCTP',0x85=>'FC',0x86=>'RSVP-E2E-IGNORE',0x87=>'Mobility-Header-Mobility-Extension-Header-for-IPv6',0x88=>'UDPLite',0x89=>'MPLS-in-IP',0x8A=>'MANET',0x8B=>'HIP',0x8C=>'Shim6',0x8D=>'WESP',0x8E=>'ROHC',0x8F=>'Ethernet-IPv6',0x90=>'AGGFRAG',0x91=>'NSH'];

	public function __construct(EconetPacket $oEconetPacket, \Psr\Log\LoggerInterface $oLogger)
	{
		parent:: __construct($oEconetPacket, $oLogger);
		$this->decode($oEconetPacket->getData());
		$this->iSourceStation = $oEconetPacket->getSourceStation();
		$this->iSourceNetwork = $oEconetPacket->getSourceNetwork();
	}	

	/**
	  * Decodes the IPv4 packet 
	  *
	*/
	public function decode(string $sBinaryString): void
	{
		//Copy the full packet so we can retransmitt a copy of this packet if its not mean modified, rather than rebuilding it from the parsed structure and appending the data.
		$this->sFullPacket = $this->sData;

		switch($this->getFlags()){
			case 0x1: //Regular IP packet
				//First byte is the version/internet header length (fisrt 4 bits being the version)
				//2nd byte is the Type of service
				//Bytes 3,4 are a 16bit int with the total length of the packet including the header and data 
				//Bytes 5,6 are a 16bit int is the identification number of the packet, this ids the packet if its broken up into smaller chunks 
				//Bytes 7,8 3 bit IP flags, 13 bit segment offset 
				//Byte 9 TTL
				//Byte 10 Protocol e.g. TCP, UDP, ICMP
				//Bytes 11-12 Header checksum
				//Bytes 13,16 Source IP address 
				//Bytes 17,20 Dest IP Address 
				//Bytes 21.....  The data 
				
				//@TODO parse out the ip flags, and offset properly so we can deal with fragmenation properly at some point.				
				
				$this->iVerLength = $this->getByte(0);

				$this->iIpHeaderLength = ($this->iVerLength&15)*32;  //Mask out the highest order bits by doing a bitwise and with 00001111
				if($this->iIpHeaderLength<20){
					//The header is to small (or corupt) to be a vaild IPv4 header
					throw new Exception("Invalid IPv4 packet");
				}
				$this->iVersion = $this->iVerLength >>4; //Bit shift down the top bit by 4 leaves us with only the top 4 bits
				if($this->iVersion!=4){
					//We are not an IPv4 packet 
					throw new Exception("Invalid IPv4 packet");
				}

				//So far the header is vaild read out the other fields
				$this->iTos = $this->getByte(0);
				$this->iLength = $this->get16bitIntLittleEndian(0);
				$this->iPtkId = $this->get16bitIntLittleEndian(0);
				$this->iFlagOffset = $this->get16bitIntLittleEndian(0);
				$this->iTtl = $this->getByte(0);
				$this->iProtocol = $this->getByte(0);
				$this->iChecksum = $this->get16bitIntLittleEndian(0);

				
				//The first remaining 4  bytes is the soruce ip address
				$this->sSrcIP = inet_ntop($this->sData[0].$this->sData[1].$this->sData[2].$this->sData[3]);
				//The second remianing 4 bytes is the dest ip address
				$this->sDstIP = inet_ntop($this->sData[4].$this->sData[5].$this->sData[6].$this->sData[7]);

				//Trim off the remaining IP header (we will not read any options if present and just skip over them)  
				$this->sData = substr($this->sData,8+($this->iIpHeaderLength-40));  //8 is for the 2 ip addresses, the header_length - 40 gives us the length of the options.
				break;
		}
		
	}

	public function getReplyPort():int
	{
		return 0xd2;
	}

	public function getSrcIP():string
	{
		return $this->sSrcIP;
	}

	public function getDstIP():string
	{
		return $this->sDstIP;
	}


	public function getTos():int
	{
		return $this->iTos;
	}

	public function getIpVersion():int
	{
		return $this->iVersion;
	}

	public function getTtl():int
	{
		return $this->iTtl;
	}

	public function getId():int
	{
		return $this->iPtkId;
	}

	public function getCheckSum():int
	{
		return $this->iChecksum;
	}
	
	public function getFlagsOffset():int
	{
		return $this->iFlagOffset;
	}

	public function getIpHeaderLength():int
	{
		return $this->iIpHeaderLength;
	}

	public function getPacketLength():int
	{
		return $this->iLength;
	}

	public function getSourceStation():int
	{
		return $this->iSourceStation;
	}

	public function getSourceNetwork():int
	{
		return $this->iSourceNetwork;
	}

	public function getProtocol():?string
	{
		return $this->aProtocols[$this->iProtocol];
	}

	
	public function forward(int $iDstNetwork, int $iDstStation, int $iSrcNetwork, int $iSrcStation):EconetPacket
	{
		$oEconetPacket = new EconetPacket();
		$oEconetPacket->setPort($this->getReplyPort());
		$oEconetPacket->setFlags(0x01); //Regular IP packet
		$oEconetPacket->setDestinationStation($iDstStation);
		$oEconetPacket->setDestinationNetwork($iDstNetwork);
		$oEconetPacket->setSourceStation($iSrcStation);
		$oEconetPacket->setSourceNetwork($iSrcNetwork);
		$oEconetPacket->setData($this->sFullPacket);
		return $oEconetPacket;

	}
}
