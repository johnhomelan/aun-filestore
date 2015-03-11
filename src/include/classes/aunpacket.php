<?
/**
 * This file contains the aunpacket class
 *
 * @author John Brown <john@home-lan.co.uk>
 * @package corenet
*/

/** 
 * This class is used to repressent and process aun network packets
 *
 * @package corenet
*/

class aunpacket {

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

	protected $sSoruceIP = NULL;

	protected $sSourceUdpPort = NULL;

	protected $sDestinationIP = NULL;


	protected $aPortMap = array(0x00=>'Immediate Operation',0x4D=>'MUGINS',0x54=>'DigitalServicesTapeStore',0x90=>'FileServerReply',0x91=> 'FileServerData', 0x93=>'Remote',0x99=>'FileServerCommand',0x9C=> 'Bridge', 0x9D=> 'ResourceLocator', 0x9E=> 'PrinterServerEnquiryReply', 0x9F=>	'PrinterServerEnquiry',0xA0=>'SJ Research *FAST protocol',0xAF=>'SJ Research Nexus net find reply port - SJVirtualEconet', 0xB0=>'FindServer', 0xB1=> 'FindServerReply', 0xB2=> 'TeletextServerCommand', 0xB3=>'TeletextServerPage',0xB4=>'Teletext',0xB5=>'Teletext',0xD0=>'PrinterServerReply',0xD1=> 'PrinterServerData',0xD2=> 'TCPIPProtocolSuite - IP over Econet',0xD3=> 'SIDFrameSlave, FastFS_Control',0xD4=> 'Scrollarama',0xD5=> 'Phone',0xD6=> 'BroadcastControl',0xD7=> 'BroadcastData',0xD8=> 'ImpressionLicenceChecker',0xD9=> 'DigitalServicesSquirrel',0xDA=> 'SIDSecondary, FastFS_Data',0xDB=> 'DigitalServicesSquirrel2',0xDC=> 'DataDistributionControl, Cambridge Systems Design',0xDD=> 'DataDistributionData, Cambridge Systems Design',0xDE=> 'ClassROM, Oak Solutions',0xDF=> 'PrinterSpoolerCommand, Oak Solutions',0xE0=> 'DigitalServicesNetGain1, David Faulkner, Digital Services',0xE1=> 'DigitalServicesNetGain2, David Faulkner, Digital Services',0xE2=> 'AppFS1, Les Want, AppFS',0xE3=> 'AppFS2, Les Want, AppFS',0xE4=> 'AtomWideFaxNet, Martin Coulson / Chris Ross',0xE5=> 'AtomWidePrintNet, Martin Coulson / Chris Ross',0xE6=> 'IotaDataPower, Neil Raine, Iota',0xE7=> 'CDNetServerBroadcast, Ellis Hall, PEP Associates',0xE8=> 'CDNetServerReplies, Ellis Hall, PEP Associates',0xE9=> 'ClassFS_Server, Oak Solutions',0xEA=> 'DigitalServicesTapeStore2',0xEB=> 'DeveloperSupport, Mark/Jon communication port',0xEC=> 'LLS_Net, Longman Logotron S-Net server');

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
	 * Gets the name of the econet port the aun packet is for
	 *
	 * @return string
	*/
	public function getPortName()
	{
		if(array_key_exists($this->iPort,$this->aPortMap)){
			return $this->aPortMap[$this->iPort];
		}
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
	public function decode($sBinaryString)
	{
		//Read the header

		//Read the aun packet type 1 byte unsigned int
		$aHeader=unpack('C',$sBinaryString);
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
		aunmap::setAunCounter($this->sSoruceIP,$this->iSeq);
	}

	public function buildAck()
	{
		//No decoded packet to to Ack
		if(!is_numeric($this->iPktType)){
			return;
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
			$sPtk = pack('C',6)
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
		return $sPtk;

	}

	public function setSourceIP($sHost)
	{
		if(strpos($sHost,':')!==FALSE){
			$aIPParts = explode(':',$sHost);
			$this->sSoruceIP=$aIPParts[0];
			$this->sSourceUdpPort=$aIPParts[1];
		}else{
			$this->sSoruceIP=$sHost;
		}
	}

	public function getSourceIP()
	{
		return $this->sSoruceIP;
	}

	public function setSourceUdpPort($iPort)
	{
		$this->sSourceUdpPort = $iPort;
	}

	public function getSourceUdpPort()
	{
		return $this->sSourceUdpPort;
	}

	public function setDestinationIP($sHost)
	{
		if(strpos($sHost,':')!==FALSE){
			$aIPParts = explode(':',$sHost);
			$this->sDestinationIP = $aIPParts[0];
		}else{
			$this->sDestinationIP=$sHost;
		}
	}

	public function getDestinationIP()
	{
		return $this->sDestinationIP;
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
		$sNetworkStation = aunmap::ipAddrToEcoAddr($this->sSoruceIP,$this->sSourceUdpPort);
		$aEcoAddr = explode('.',$sNetworkStation);
		if(array_key_exists(0,$aEcoAddr) AND array_key_exists(1,$aEcoAddr)){
			$oEconetPacket->setSourceNetwork($aEcoAddr[0]);
			$oEconetPacket->setSourceStation($aEcoAddr[1]);
		}
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
