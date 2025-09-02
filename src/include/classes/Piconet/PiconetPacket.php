<?php
/**
 * This file contains the JsonPacket class
 *
 * @author John Brown <john@home-lan.co.uk>
 * @package corenet
*/
namespace HomeLan\FileStore\Piconet; 

use HomeLan\FileStore\Messages\EconetPacket; 
use HomeLan\FileStore\Piconet\Map; 
use HomeLan\FileStore\Encapsulation\EncapsulationInterface;

use config;
use Exception; 

/** 
 * This class is used to repressent and process aun network packet over a websocket
 *
 * @package corenet
*/

class PiconetPacket implements EncapsulationInterface {

	//Single byte (unsigned int) Aun Packet Type 1=>BroadCast =
	protected ?string $sMessageType = NULL;
	
	//Single byte (unsigned int) Control/flag 
	protected ?int $iCb = NULL;

	//Single byte (unsigned int) Port number
	protected ?int $iPort = NULL;

	//Binary Data String
	protected ?string $sData = NULL;

	protected ?int $iNetworkNumber = NULL;

	protected ?int $iStationNumber = NULL;

	protected ?int $iDstNetworkNumber = NULL;

	protected ?int $iDstStationNumber = NULL;

	/**
	 * @var array<string, string>
	*/ 
	protected array $aTypeMap = ['RX_BROADCAST'=>'Broadcast', 'RX_TRANSMIT'=>'Unicast', 'RX_IMMEDIATE'=>'Immediate', 'Ack'=>'Ack'];


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
		return $this->aTypeMap[$this->sMessageType];
	}

	public function getDstStation(): ?int
	{
		return $this->iDstStationNumber;
	}

	public function getDstNetwork(): ?int
	{
		return $this->iDstNetworkNumber;
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
	 * Decodes a packet from the piconet interface
	 *
	 * @param string $sPacket
	*/
	public function decode($sPacket): void
	{
		$aPacket = explode(" ",$sPacket);
		$this->sMessageType = (string) $aPacket[0];
		switch($this->sMessageType){
			case 'RX_BROADCAST':
				$sScout = $aPacket[1];
				$sData = "";
				break;
			case 'RX_IMMEDIATE':
				$sScout = $aPacket[1];
				$sData = $aPacket[2];
				break;
			case 'RX_TRANSMIT':
				$sScout = $aPacket[1];
				$sData = $aPacket[2];
				break;
			default:
				$sData="";
				$sScout="";
				break;
		}
		$sRawScout = (string) base64_decode($sScout);


		//Read the dst/src contolbyte port each is 1 byte unsigned int |DstStn|DstNet|SrcStn|SrcNet|Cb|Port
		$aScout=unpack('C',(string) $sRawScout);
		$sRawScout = substr($sRawScout,1);
		$this->iDstStationNumber = (int) $aScout[1];

		$aScout=unpack('C',(string) $sRawScout);
		$sRawScout = substr($sRawScout,1);
		$this->iDstNetworkNumber = (int) $aScout[1];

		$aScout=unpack('C',(string) $sRawScout);
		$sRawScout = substr($sRawScout,1);
		$this->iStationNumber = (int) $aScout[1];

		$aScout=unpack('C',(string) $sRawScout);
		$sRawScout = substr($sRawScout,1);
		$this->iNetworkNumber = (int) $aScout[1];

		$aScout=unpack('C',(string) $sRawScout);
		$sRawScout = substr($sRawScout,1);
		$this->iCb = (int) $aScout[1];

		$aScout=unpack('C',(string) $sRawScout);
		$sRawScout = substr($sRawScout,1);
		$this->iPort = (int) $aScout[1];

		//The packets on the local network always have a network number of 0, so update the network number to the correct global number
		if($this->iNetworkNumber==0){
			$this->iNetworkNumber = config::getValue('piconet_local_network');
		}

		switch($aPacket[0]){
			case 'RX_BROADCAST':
				$this->sData = substr($sRawScout,0,8);
				break;
			case 'RX_IMMEDIATE':
				$this->sData = substr($sRawScout,0,4);	
				break;
			case 'RX_TRANSMIT':
				$sRawData = "".(string) base64_decode($sData);
				$this->sData = substr($sRawData,4,strlen($sRawData)-4);
				break;
		}
	}

	public function makeAck(int $iNetwork, int $iStation, int $iPort, int $iCb):void
	{
		$this->iNetworkNumber = $iNetwork;
		$this->iStationNumber = $iStation;
		$this->iPort = $iPort;
		$this->iCb = $iCb;
		$this->sMessageType = 'Ack';
	}

	public function buildAck(): ?string
	{
		//The piconet interface does the acks in hardware, thus our code does not needed to.
		return "";
	}

	/**
	 * Builds an econet packet object from this aun packet
	 *
	 * All the sub applications FileServer, PrintServer uses the econetpacket object so
	 * that we can support more than 1 type of econet emulation/encapsulation
	 * @return econetpacket
	*/
	public function buildEconetPacket(): \HomeLan\FileStore\Messages\EconetPacket
	{
		$oEconetPacket = new EconetPacket();
		$oEconetPacket->setPort($this->iPort);
		$oEconetPacket->setFlags($this->iCb);
		$oEconetPacket->setSourceNetwork($this->iNetworkNumber);
		$oEconetPacket->setSourceStation($this->iStationNumber);
		$oEconetPacket->setDestinationNetwork($this->iDstNetworkNumber);
		$oEconetPacket->setDestinationstation($this->iDstStationNumber);
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
		$sReturn = "Header | Type : ".$this->getPacketType()." Port : ".$this->getPort()." Control : ".$this->iCb." | Body |".implode(":",$aPkt)." |";
		return $sReturn;	
	}

}
