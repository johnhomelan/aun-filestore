<?php
/**
 * This file contains the econetpacket class
 *
 * @author John Brown <john@home-lan.co.uk>
 * @package corenet
*/
namespace HomeLan\FileStore\Messages; 

use HomeLan\FileStore\Aun\Map; 
use Exception;

/** 
 * This class is used to represcent an econet packet
 *
 * @package corenet
*/
class EconetPacket {

	//Single byte (unsigned char)
	protected $iDstStn = NULL;

	//Single byte (unsigned char)
	protected $iDstNet = NULL;

	//Single byte (unsigned char)
	protected $iSrcStn = NULL;

	//Single byte (unsigned char)
	protected $iSrcNet = NULL;

	//Single byte (unsigned char) Control/flag 
	protected $iCb = NULL;

	//Single byte (unsigned char) Port number
	protected $iPort = NULL;

	//Binary Data String
	protected $sData = NULL;

	protected $aPortMap = [0x00=>'Immediate Operation', 0x4D=>'MUGINS', 0x54=>'DigitalServicesTapeStore', 0x90=>'FileServerReply', 0x91=> 'FileServerData', 0x93=>'Remote', 0x99=>'FileServerCommand', 0x9C=> 'Bridge', 0x9D=> 'ResourceLocator', 0x9E=> 'PrinterServerEnquiryReply', 0x9F=>	'PrinterServerEnquiry', 0xA0=>'SJ Research *FAST protocol', 0xAF=>'SJ Research Nexus net find reply port - SJVirtualEconet', 0xB0=>'FindServer', 0xB1=> 'FindServerReply', 0xB2=> 'TeletextServerCommand', 0xB3=>'TeletextServerPage', 0xB4=>'Teletext', 0xB5=>'Teletext', 0xD0=>'PrinterServerReply', 0xD1=> 'PrinterServerData', 0xD2=> 'TCPIPProtocolSuite - IP over Econet', 0xD3=> 'SIDFrameSlave, FastFS_Control', 0xD4=> 'Scrollarama', 0xD5=> 'Phone', 0xD6=> 'BroadcastControl', 0xD7=> 'BroadcastData', 0xD8=> 'ImpressionLicenceChecker', 0xD9=> 'DigitalServicesSquirrel', 0xDA=> 'SIDSecondary, FastFS_Data', 0xDB=> 'DigitalServicesSquirrel2', 0xDC=> 'DataDistributionControl, Cambridge Systems Design', 0xDD=> 'DataDistributionData, Cambridge Systems Design', 0xDE=> 'ClassROM, Oak Solutions', 0xDF=> 'PrinterSpoolerCommand, Oak Solutions', 0xE0=> 'DigitalServicesNetGain1, David Faulkner, Digital Services', 0xE1=> 'DigitalServicesNetGain2, David Faulkner, Digital Services', 0xE2=> 'AppFS1, Les Want, AppFS', 0xE3=> 'AppFS2, Les Want, AppFS', 0xE4=> 'AtomWideFaxNet, Martin Coulson / Chris Ross', 0xE5=> 'AtomWidePrintNet, Martin Coulson / Chris Ross', 0xE6=> 'IotaDataPower, Neil Raine, Iota', 0xE7=> 'CDNetServerBroadcast, Ellis Hall, PEP Associates', 0xE8=> 'CDNetServerReplies, Ellis Hall, PEP Associates', 0xE9=> 'ClassFS_Server, Oak Solutions', 0xEA=> 'DigitalServicesTapeStore2, New allocation to replace 0x54', 0xEB=> 'DeveloperSupport, Mark/Jon communication port', 0xEC=> 'LLS_Net, Longman Logotron S-Net server'];

	public function getPort():int
	{
		return $this->iPort;
	}

	public function getPortName():string 
	{
		if(array_key_exists($this->iPort,$this->aPortMap)){
			return $this->aPortMap[$this->iPort];
		}
		return '';
	}

	public function getPortByName(string $sName): void
	{
		if(in_array($sName,$this->aPortMap)){
			$this->iPort = array_search($sName,$this->aPortMap);
		}else{
			throw new Exception($sName." is not a vailid econet service name.");
		}
	}

	public function setData(string $sData): void
	{
		$this->sData=$sData;
	}

	public function getData():string 
	{
		return $this->sData;
	}
	
	public function setPort(int $iPort): void
	{
		$this->iPort = $iPort;
	}

	public function setFlags(int $iCb): void
	{
		$this->iCb = $iCb;
	}

	public function getFlags():int
	{
		return $this->iCb;
	}

	public function setSourceStation($sStation): void
	{
		$this->iSrcStn=$sStation;
	}

	public function setSourceNetwork($iNetwork): void
	{
		$this->iSrcNet=$iNetwork;
	}
	
	public function getSourceNetwork()
	{
		return $this->iSrcNet;
	}

	public function getSourceStation()
	{
		return $this->iSrcStn;
	}



	public function setDestinationNetwork($iNetwork): void
	{
		$this->iDstNet=$iNetwork;

	}

	public function setDestinationStation($sStation): void
	{
		if(str_contains((string) $sStation,'.')){
			$aStnParts = explode('.',(string) $sStation);
			$this->iDstNet=$aStnParts[0];
			$this->iDstStn=$aStnParts[1];
		}else{
			$this->iDstStn=$sStation;
		}

	}

	public function getDestinationStation()
	{
		return $this->iDstStn;
	}

	public function getDestinationNetwork()
	{
		return $this->iDstNet;
	}

	public function getAunFrame():string
	{
		$sIP = Map::ecoAddrToIpAddr($this->getDestinationNetwork(),$this->getDestinationStation());
		if(strlen($sIP)>0){
			//Set the packet type to unicast
			$sPacket = pack('C',2);
		
			//Set the port
			$sPacket=$sPacket.pack('C',$this->iPort);

		
			//Set the flags
			$sPacket=$sPacket.pack('C',$this->iCb);
			
			//Add the pad
			$sPacket=$sPacket.pack('C',0);
		
			//Sequence 4 bytes little-endian
			$sPacket=$sPacket.pack('V',Map::incAunCounter($sIP));

			//Add the data
			$sPacket=$sPacket.$this->sData;
			
			return $sPacket;	
		}
		return '';
	}

	/**
	 * Produces a nice string representation of the packet for debugging
	 *
	 * @return string
	*/
	public function toString():string
	{
		$aPkt = unpack('C*',$this->getData());
		$sReturn = "Header |  Port : ".$this->getPort()." Control : ".$this->iCb." | Body |".implode(":",$aPkt)." |";
		return $sReturn;	
	}

}
