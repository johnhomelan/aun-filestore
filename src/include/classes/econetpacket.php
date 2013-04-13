<?
/**
 * This file contains the econetpacket class
 *
 * @author John Brown <john@home-lan.co.uk>
 * @package coreprotocol
*/

/** 
 * This class is used to represcent an econet packet
 *
 * @package coreprotocol
*/
class econetpacket {

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

	protected $aPortMap = array(0x00=>'Immediate Operation',0x4D=>'MUGINS',0x54=>'DigitalServicesTapeStore',0x90=>'FileServerReply', 0x91=> 'FileServerData', 0x93=>'Remote',0x99=>'FileServerCommand',0x9C=> 'Bridge', 0x9D=> 'ResourceLocator', 0x9E=> 'PrinterServerEnquiryReply', 0x9F=>	'PrinterServerEnquiry',0xA0=>'SJ Research *FAST protocol',0xAF=>'SJ Research Nexus net find reply port - SJVirtualEconet', 0xB0=>'FindServer', 0xB1=> 'FindServerReply', 0xB2=> 'TeletextServerCommand', 0xB3=>'TeletextServerPage',0xB4=>'Teletext',0xB5=>'Teletext',0xD0=>'PrinterServerReply',0xD1=> 'PrinterServerData',0xD2=> 'TCPIPProtocolSuite - IP over Econet',0xD3=> 'SIDFrameSlave, FastFS_Control',0xD4=> 'Scrollarama',0xD5=> 'Phone',0xD6=> 'BroadcastControl',0xD7=> 'BroadcastData',0xD8=> 'ImpressionLicenceChecker',0xD9=> 'DigitalServicesSquirrel',0xDA=> 'SIDSecondary, FastFS_Data',0xDB=> 'DigitalServicesSquirrel2',0xDC=> 'DataDistributionControl, Cambridge Systems Design',0xDD=> 'DataDistributionData, Cambridge Systems Design',0xDE=> 'ClassROM, Oak Solutions',0xDF=> 'PrinterSpoolerCommand, Oak Solutions',0xE0=> 'DigitalServicesNetGain1, David Faulkner, Digital Services',0xE1=> 'DigitalServicesNetGain2, David Faulkner, Digital Services',0xE2=> 'AppFS1, Les Want, AppFS',0xE3=> 'AppFS2, Les Want, AppFS',0xE4=> 'AtomWideFaxNet, Martin Coulson / Chris Ross',0xE5=> 'AtomWidePrintNet, Martin Coulson / Chris Ross',0xE6=> 'IotaDataPower, Neil Raine, Iota',0xE7=> 'CDNetServerBroadcast, Ellis Hall, PEP Associates',0xE8=> 'CDNetServerReplies, Ellis Hall, PEP Associates',0xE9=> 'ClassFS_Server, Oak Solutions',0xEA=> 'DigitalServicesTapeStore2, New allocation to replace 0x54',0xEB=> 'DeveloperSupport, Mark/Jon communication port',0xEC=> 'LLS_Net, Longman Logotron S-Net server');

	public function getPort()
	{
		return $this->iPort;
	}

	public function getPortName()
	{
		if(array_key_exists(dechex($this->iPort),$this->aPortMap)){
			return $this->aPortMap[dechex($this->iPort)];
		}
	}

	public function getPortByName($sName)
	{
		if(in_array($sName,$this->aPortMap)){
			$this->iPort = array_search($sName,$this->aPortMap);
		}else{
			throw new Exception($sName." is not a vailid econet service name.");
		}
	}

	public function setData($sData)
	{
		$this->sData=$sData;
	}

	public function getData()
	{
		return $this->sData;
	}
	
	public function setPort($iPort)
	{
		$this->iPort = $iPort;
	}

	public function setFlags($iCb)
	{
		$this->iCb = $iCb;
	}

	public function setSourceStation($sStation)
	{
		if(strpos($sStation,'.')!==FALSE){
			$aStnParts = explode('.',$sStation);
			$this->iSrcNet=$aStnParts[0];
			$this->iSrcStn=$aStnParts[1];
		}else{
			$this->iSrcStn=$sStation;
			$this->iSrcNet=config::getValue('default_econet_network');
		}
	}
	
	public function getSourceNetwork()
	{
		return $this->iSrcNet;
	}

	public function getSourceStation()
	{
		return $this->iSrcStn;
	}

	public function setDestinationStation($sStation)
	{
		if(strpos($sStation,'.')!==FALSE){
			$aStnParts = explode('.',$sStation);
			$this->iDstNet=$aStnParts[0];
			$this->iDstStn=$aStnParts[1];
		}else{
			$this->iDstStn=$sStation;
			$this->iDstNet=config::getValue('default_econet_network');
		}

	}
}
