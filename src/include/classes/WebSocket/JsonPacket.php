<?php
/**
 * This file contains the JsonPacket class
 *
 * @author John Brown <john@home-lan.co.uk>
 * @package corenet
*/
namespace HomeLan\FileStore\WebSocket; 

use HomeLan\FileStore\Messages\EconetPacket; 
use Exception; 

/** 
 * This class is used to repressent and process aun network packet over a websocket
 *
 * @package corenet
*/

class JsonPacket {

	//Single byte (unsigned int) Aun Packet Type 1=>BroadCast =
	protected $iPktType = NULL;
	
	//Single byte (unsigned int) Control/flag 
	protected $iCb = NULL;

	//Single byte (unsigned int) Padding
	protected $iPadding = NULL;

	//Single byte (unsigned int) Port number
	protected $iPort = NULL;

	//32 bit int unsigned  little-endian 
	protected $iSeq = 0;

	//Binary Data String
	protected $sData = NULL;

	protected $iNetworkNumber = NULL;

	protected $iStationNumber = NULL;

	protected $aTypeMap = array(1=>'Broadcast',2=>'Unicast',3=>'Ack',4=>'Reject',5=>'Immediate',6=>'ImmediateReply');

	/**
	 * Get the econet port number the aun packet is for
	 *
	 * @return int
	*/
	public function getPort()
	{
		return $this->iPort;
	}

	/**
	 * Get the type of aun packet
	 *
	 * e.g. Broadcast,Unicast,Ack etc
	 * @return string
	*/ 
	public function getPacketType()
	{
		return $this->aTypeMap[$this->iPktType];
	}

	/**
	 * Get the binary data from the aun packet
	 *
	 * @return string
	*/
	public function getData()
	{
		return $this->sData;
	}

	/**
	 * Decodes an AUN packet 
	 *
	 * @param string $sBinaryString
	*/
	public function decode($sJsonString)
	{
		$aPacket = json_decode($sJsonString,true);
		if(is_null($aPacket)){
			throw new Exception("Invalid json encoded econet packet");
		}

		$this->iStationNumber = $aPacket['station'];
		$this->iNetworkNumber = $aPacket['network'];

		//Read the header

		//Read the aun packet type 1 byte unsigned int
		$aHeader=unpack('C',$aPacket['payload']);
		$this->iPktType = $aHeader[1];
		$sBinaryString = substr($sBinaryString,1);
		
		//Read the dst port 1 byte unsigned int
		$aHeader=unpack('C',$sBinaryString);
		$this->iPort = $aHeader[1];
		$sBinaryString = substr($sBinaryString,1);
		
		//Read the flag 1 byte unsigned int
		$aHeader=unpack('C',$sBinaryString);
		$this->iCb = $aHeader[1];
		$sBinaryString = substr($sBinaryString,1);
		
		//Retrans 1 byte unsigned int
		$aHeader=unpack('C',$sBinaryString);
		$this->iPadding = $aHeader[1];
		$sBinaryString = substr($sBinaryString,1);
		
		//Sequence 4 bytes little-endian
		$aHeader=unpack('V',$sBinaryString);
		$this->iSeq = $aHeader[1];
		$sBinaryString = substr($sBinaryString,4);

		//The reset is data
		$this->sData = $sBinaryString;

		//Set the aun counter 		
		Map::setAunCounter($this->sSoruceIP,$this->iSeq);
	}

	public function buildAck(): ?string
	{
		//No decoded packet to to Ack
		if(!is_numeric($this->iPktType)){
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
			//Sequence
			$sPtk = $sPtk.pack('V',0);
			//Peek Lo
			$sPtk = $sPtk.pack('C',0x40);
			//Peek Hi
			$sPtk = $sPtk.pack('C',0x66);
			$sPtk = $sPtk.pack('C',config::getValue('version_minor'));
			$sPtk = $sPtk.pack('C',config::getValue('version_majour'));
		}
		return json_encode(
			[
				'to'=>[
					'station'=>$this->iStationNumber,
					'network'=>$this->iNetworkNumber
				],
				'payload'=>$sPtk
			]);

	}

	/**
	 * Builds an econet packet object from this aun packet
	 *
	 * All the sub applications FileServer, PrintServer uses the econetpacket object so
	 * that we can support more than 1 type of econet emulation/encapsulation
	 * @return object econetpacket
	*/
	public function buildEconetPacket()
	{
		$oEconetPacket = new EconetPacket();
		$oEconetPacket->setPort($this->iPort);
		$oEconetPacket->setFlags($this->iCb);
		$oEconetPacket->setSourceNetwork($this->iNetworkNumber);
		$oEconetPacket->setSourceStation($this->iStationNumber);
		$oEconetPacket->setData($this->sData);
		return $oEconetPacket;
	}

	/**
	 * Produces a nice string representation of the packet for debugging
	 *
	 * @return string
	*/
	public function toString()
	{
		$aPkt = unpack('C*',$this->getData());
		$sReturn = "Header | Type : ".$this->getPacketType()." Port : ".$this->getPort()." Control : ".$this->iCb." Pad : ".$this->iPadding." Seq : ".$this->iSeq." | Body |".implode(":",$aPkt)." |";
		return $sReturn;	
	}
}
