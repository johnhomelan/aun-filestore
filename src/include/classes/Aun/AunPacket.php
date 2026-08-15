<?php
/**
 * This file contains the aunpacket class
 *
 * @author John Brown <john@home-lan.co.uk>
 * @package corenet
*/
namespace HomeLan\FileStore\Aun; 

use HomeLan\FileStore\Messages\EconetPacket; 
use HomeLan\FileStore\Encapsulation\EncapsulationInterface;
use Exception; 
use config;

/** 
 * This class is used to repressent and process aun network packets
 *
 * @package corenet
*/

class AunPacket implements EncapsulationInterface {

	//Single byte (unsigned int) Aun Packet Type 1=>BroadCast =
	protected ?int $iPktType = NULL;

	//Single byte (unsigned int) Control/flag
	protected ?int $iCb = NULL;

	//Single byte (unsigned int) Padding
	protected ?int $iPadding = NULL;

	//Single byte (unsigned int) Port number
	protected int $iPort = 0;

	//32 bit int unsigned  little-endian
	protected int $iSeq = 0;

	//Binary Data String
	protected string $sData = '';

	protected ?string $sSoruceIP = NULL;

	protected ?string $sSourceUdpPort = NULL;

	protected ?string $sDestinationIP = NULL;

	/**
 	 * @var array<int, string>
 	 */	
	protected array $aTypeMap = [1=>'Broadcast', 2=>'Unicast', 3=>'Ack', 4=>'Reject', 5=>'Immediate', 6=>'ImmediateReply'];

	/**
	 * Get the econet port number the aun packet is for
	 *
	 * @return int
	*/
	public function getPort(): int
	{
		return $this->iPort;
	}

	/**
	 * Get the type of aun packet
	 *
	 * e.g. Broadcast,Unicast,Ack etc
	 * @return string
	*/ 
	public function getPacketType(): string
	{
		if(array_key_exists($this->iPktType,$this->aTypeMap)){
			return $this->aTypeMap[$this->iPktType];
		}
		return 'Unknown';
	}

	/**
	 * Get the binary data from the aun packet
	 *
	 * @return string
	*/
	public function getData(): string
	{
		return $this->sData;
	}

	/**
	 * Decodes an AUN packet 
	 *
	 * @param string $sBinaryString
	*/
	public function decode(string $sBinaryString): void
	{
		if(strlen($sBinaryString) < 8){
			throw new Exception("AUN packet too short (".strlen($sBinaryString)." bytes); minimum is 8");
		}

		//Read the header

		//Read the aun packet type 1 byte unsigned int
		$aHeader=unpack('C',$sBinaryString);
		if($aHeader === false){
			throw new Exception("Failed to unpack AUN packet type byte");
		}
		$this->iPktType = $aHeader[1];
		$sBinaryString = substr($sBinaryString,1);

		//Read the dst port 1 byte unsigned int
		$aHeader=unpack('C',$sBinaryString);
		if($aHeader === false){
			throw new Exception("Failed to unpack AUN packet port byte");
		}
		$this->iPort = $aHeader[1];
		$sBinaryString = substr($sBinaryString,1);

		//Read the flag 1 byte unsigned int
		$aHeader=unpack('C',$sBinaryString);
		if($aHeader === false){
			throw new Exception("Failed to unpack AUN packet flag byte");
		}
		$this->iCb = $aHeader[1];
		$sBinaryString = substr($sBinaryString,1);

		//Retrans 1 byte unsigned int
		$aHeader=unpack('C',$sBinaryString);
		if($aHeader === false){
			throw new Exception("Failed to unpack AUN packet retrans byte");
		}
		$this->iPadding = $aHeader[1];
		$sBinaryString = substr($sBinaryString,1);

		//Sequence 4 bytes little-endian
		$aHeader=unpack('V',$sBinaryString);
		if($aHeader === false){
			throw new Exception("Failed to unpack AUN packet sequence number");
		}
		$this->iSeq = $aHeader[1];
		$sBinaryString = substr($sBinaryString,4);

		//The reset is data
		$this->sData = $sBinaryString;

		//Set the aun counter (only if setSourceIP has already been called)
		if(!is_null($this->sSoruceIP)){
			Map::setAunCounter($this->sSoruceIP,$this->iSeq);
		}
	}

	public function buildAck(): ?string
	{
		//No decoded packet to to Ack
		if(!is_numeric($this->iPktType) OR !array_key_exists($this->iPktType,$this->aTypeMap)){
			return null;
		}
		$sPtk = NULL;
		if($this->aTypeMap[$this->iPktType]=='Unicast'){
			//Set the type as Ack
			$sPtk = pack('C',3);
			//Port 0
			$sPtk = $sPtk.pack('C',0);
			//Flag 0
			$sPtk = $sPtk.pack('C',0);
			//Retrans 0
			$sPtk = $sPtk.pack('C',0);
			//Sequence
			$sPtk = $sPtk.pack('V',$this->iSeq);
		}
		if($this->aTypeMap[$this->iPktType]=='Immediate' AND $this->iCb==0){
			// Machine type query — identify as Acorn FS01 FileStore
			$sPtk = pack('C',6);
			$sPtk .= pack('C',0);
			$sPtk .= pack('C',0);
			$sPtk .= pack('C',0);
			$sPtk .= pack('V',$this->iSeq);
			$sPtk .= pack('C',0x40); // FS01 FileStore machine type (Acorn Econet machine type table)
			$sPtk .= pack('C',0x00);
			$sPtk .= pack('C',0x00);
			$sPtk .= pack('C',0x00);
		}
		if($this->aTypeMap[$this->iPktType]=='Immediate' AND $this->iCb==1){
			// OS version query
			$sPtk = pack('C',6);
			$sPtk .= pack('C',0);
			$sPtk .= pack('C',0);
			$sPtk .= pack('C',0);
			$sPtk .= pack('V',$this->iSeq);
			$sPtk .= pack('C',config::getValue('version_major'));
			$sPtk .= pack('C',config::getValue('version_minor'));
			$sPtk .= pack('C',0x00);
			$sPtk .= pack('C',0x00);
		}
		if($this->aTypeMap[$this->iPktType]=='Immediate' AND $this->iCb==8){
			//Echo request equiv

			//Set the type as Immediate reply
			$sPtk = pack('C',6);
			//Port 0
			$sPtk = $sPtk.pack('C',0);
			//Flag 0
			$sPtk = $sPtk.pack('C',0);
			//Retrans 0
			$sPtk = $sPtk.pack('C',0);
			//Sequence — echo back the received sequence number
			$sPtk = $sPtk.pack('V',$this->iSeq);
			//Peek Lo
			$sPtk = $sPtk.pack('C',0x40);
			//Peek Hi
			$sPtk = $sPtk.pack('C',0x66);
			$sPtk = $sPtk.pack('C',config::getValue('version_minor'));
			$sPtk = $sPtk.pack('C',config::getValue('version_major'));
		}
		return $sPtk;

	}

	public function getSequence():int
	{
		return $this->iSeq;
	}

	public function getCb(): ?int
	{
		return $this->iCb;
	}

	public function setSourceIP(string $sHost): void
	{
		if(str_contains((string) $sHost,':')){
			$aIPParts = explode(':',(string) $sHost);
			$this->sSoruceIP=$aIPParts[0];
			$this->sSourceUdpPort=$aIPParts[1];
		}else{
			$this->sSoruceIP=$sHost;
		}
	}

	public function getSourceIP():string
	{
		return $this->sSoruceIP;
	}

	public function setSourceUdpPort(int $iPort): void
	{
		$this->sSourceUdpPort = (string) $iPort;
	}

	public function getSourceUdpPort():string
	{
		return $this->sSourceUdpPort;
	}

	public function setDestinationIP(string $sHost): void
	{
		if(str_contains((string) $sHost,':')){
			$aIPParts = explode(':',(string) $sHost);
			$this->sDestinationIP = $aIPParts[0];
		}else{
			$this->sDestinationIP=$sHost;
		}
	}

	public function getDestinationIP():string
	{
		return $this->sDestinationIP;
	}

	/**
	 * Builds an econet packet object from this aun packet
	 *
	 * All the sub applications FileServer, PrintServer uses the econetpacket object so
	 * that we can support more than 1 type of econet emulation/encapsulation
	*/
	public function buildEconetPacket(): \HomeLan\FileStore\Messages\EconetPacket
	{
		$oEconetPacket = new EconetPacket();
		$oEconetPacket->setPort($this->iPort);
		$oEconetPacket->setFlags($this->iCb);

		if (is_null($this->sSoruceIP)) {
			return $oEconetPacket;
		}

		$sNetworkStation = Map::ipAddrToEcoAddr($this->sSoruceIP,$this->sSourceUdpPort);
		$aEcoAddr = explode('.',$sNetworkStation);
		if(array_key_exists(1,$aEcoAddr)){
			$oEconetPacket->setSourceNetwork((int) $aEcoAddr[0]);
			$oEconetPacket->setSourceStation((int) $aEcoAddr[1]);
		}
		$oEconetPacket->setData($this->sData);
		return $oEconetPacket;
	}

	/**
	 * Produces a nice string representation of the packet for debugging
	 *
	 * @return string
	*/
	public function toString(): string
	{
		$aPkt = unpack('C*',$this->getData());
		if($aPkt === false){
			$aPkt = [];
		}
		$sReturn = "Header | Type : ".$this->getPacketType()." Port : ".$this->getPort()." Control : ".$this->iCb." Pad : ".$this->iPadding." Seq : ".$this->iSeq." | Body |".implode(":",$aPkt)." |";
		return $sReturn;
	}

}
