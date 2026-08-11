<?php
/**
 * This file contains the JsonPacket class
 *
 * @author John Brown <john@home-lan.co.uk>
 * @package corenet
*/
namespace HomeLan\FileStore\WebSocket; 

use HomeLan\FileStore\Messages\EconetPacket; 
use HomeLan\FileStore\WebSocket\Map; 
use HomeLan\FileStore\Aun\Map as AunMap; 
use HomeLan\FileStore\Encapsulation\EncapsulationInterface;

use Ratchet\ConnectionInterface;
use config;
use Exception; 

/** 
 * This class is used to repressent and process aun network packet over a websocket
 *
 * @package corenet
*/

class JsonPacket implements EncapsulationInterface {

	protected ConnectionInterface $oSocket;

	//Single byte (unsigned int) Aun Packet Type 1=>BroadCast =
	protected ?int $iAunPktType = NULL;
	
	//Single byte (unsigned int) Control/flag
	protected int $iCb = 0;

	//Single byte (unsigned int) Padding
	protected int $iPadding = 0;

	//Single byte (unsigned int) Port number
	protected int $iPort = 0;

	//32 bit int unsigned  little-endian 
	protected int $iSeq = 0;

	protected string $sJsonMsgType = '';

	protected string $sCtrlRequest = '';

	/**
 	 *  @var array<mixed[]>
 	*/		
	protected array $aCtrlRequestArgs = [];

	//Binary Data String
	protected string $sData = '';

	protected ?int $iNetworkNumber = NULL;

	protected ?int $iStationNumber = NULL;

	protected ?int $iDstNetworkNumber = NULL;

	protected ?int $iDstStationNumber = NULL;

	/**
	 * @var array<int, string>
	*/ 
	protected array $aAunTypeMap = [1=>'Broadcast', 2=>'Unicast', 3=>'Ack', 4=>'Reject', 5=>'Immediate', 6=>'ImmediateReply'];

	protected ?string $sSoruceIP = NULL;


	public function __construct(ConnectionInterface $oSocket)
	{
		$this->oSocket = $oSocket;
	}
	/**
	 * Get the econet port number the aun packet is for
	 *
	 * @return int
	*/
	public function getPort(): int
	{
		return $this->iPort;
	}

	public function getType():string
	{
		return $this->sJsonMsgType;
	}
	/**
	 * Get the type of aun packet
	 *
	 * e.g. Broadcast,Unicast,Ack etc
	 * @return string
	*/ 
	public function getPacketType(): string
	{
		return $this->aAunTypeMap[$this->iAunPktType] ?? 'Unknown';
	}

	public function getSequence(): int
	{
		return $this->iSeq;
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
	 * Decodes an AUN packet 
	 *
	 * @param string $sJsonString
	*/
	public function decode($sJsonString): void
	{
		$aPacket = json_decode((string) $sJsonString,true, 255, JSON_THROW_ON_ERROR);
		if(is_null($aPacket)){
			throw new Exception("Invalid json encoded econet packet");
		}
		$this->sJsonMsgType = $aPacket['type'];
		switch($this->sJsonMsgType){
			case 'pkt':
				if (!isset($aPacket['src'], $aPacket['dst'], $aPacket['payload'])) {
					throw new Exception("pkt message missing required src, dst, or payload fields");
				}
				if (!isset($aPacket['src']['station'], $aPacket['src']['network'])) {
					throw new Exception("pkt message src missing station or network");
				}
				if (!isset($aPacket['dst']['station'], $aPacket['dst']['network'])) {
					throw new Exception("pkt message dst missing station or network");
				}
				$sPayload = (string) $aPacket['payload'];
				if (strlen($sPayload) < 8) {
					throw new Exception("pkt payload too short (" . strlen($sPayload) . " bytes); minimum is 8");
				}

				$this->iStationNumber = $aPacket['src']['station'];
				$this->iNetworkNumber = $aPacket['src']['network'];
				$this->iDstStationNumber = $aPacket['dst']['station'];
				$this->iDstNetworkNumber = $aPacket['dst']['network'];

				//Read the aun packet type 1 byte unsigned int
				$aHeader=unpack('C',$sPayload);
				$this->iAunPktType = $aHeader[1];
				$sBinaryString = substr($sPayload,1);

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

				//The rest is data
				$this->sData = $sBinaryString;
				AunMap::setAunCounter('ws_'.$this->iNetworkNumber.'_'.$this->iStationNumber,$this->iSeq);

				break;
			case 'ctrl':
				if (!isset($aPacket['request'], $aPacket['args'])) {
					throw new Exception("ctrl message missing required request or args fields");
				}
				$this->sCtrlRequest = $aPacket['request'];
				$this->aCtrlRequestArgs = $aPacket['args'];
				break;
			default:
				throw new Exception("Invalid type supplied for json encoded econet packet");
				
		}
	}

	public function buildAck(): ?string
	{
		
		switch($this->sJsonMsgType){
			case 'pkt':
				//No decoded packet to to Ack
				if(!is_numeric($this->iAunPktType)){
					return null;
				}
				$sPtk = NULL;
				if($this->aAunTypeMap[$this->iAunPktType]=='Unicast'){
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
				if($this->aAunTypeMap[$this->iAunPktType]=='Immediate' AND $this->iCb==0){
					// Machine type query — identify as Acorn FS01 FileStore
					$sPtk = pack('C',6);
					$sPtk .= pack('C',0);
					$sPtk .= pack('C',0);
					$sPtk .= pack('C',0);
					$sPtk .= pack('V',0);
					$sPtk .= pack('C',0x40); // FS01 FileStore machine type
					$sPtk .= pack('C',0x00);
					$sPtk .= pack('C',0x00);
					$sPtk .= pack('C',0x00);
				}
				if($this->aAunTypeMap[$this->iAunPktType]=='Immediate' AND $this->iCb==1){
					// OS version query
					$sPtk = pack('C',6);
					$sPtk .= pack('C',0);
					$sPtk .= pack('C',0);
					$sPtk .= pack('C',0);
					$sPtk .= pack('V',0);
					$sPtk .= pack('C',config::getValue('version_major'));
					$sPtk .= pack('C',config::getValue('version_minor'));
					$sPtk .= pack('C',0x00);
					$sPtk .= pack('C',0x00);
				}
				if($this->aAunTypeMap[$this->iAunPktType]=='Immediate' AND $this->iCb==8){
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
					$sPtk = $sPtk.pack('C',config::getValue('version_major'));
				}
				return json_encode(
					[
						'type'=>'pkt',
						'dst'=>[
							'station'=>$this->iStationNumber,
							'network'=>$this->iNetworkNumber
						],
						'src'=>[
							'station'=>config::getValue('websocket_station_address'),
							'network'=>config::getValue('websocket_network_address')
						],
						'payload'=>$sPtk
					], JSON_THROW_ON_ERROR);
			case 'ctrl':
				switch($this->sCtrlRequest){
					case 'dynamic_alloction_request':
						$sAllocation = Map::allocateAddress($this->oSocket);
						return json_encode(
							[
								'type'=>'ctrl',
								'response'=>$sAllocation
							]);
				}
		}
		return null;
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
		$oEconetPacket->setDestinationStation($this->iDstStationNumber);
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
		$sReturn = "Header | Type : ".$this->getPacketType()." Port : ".$this->getPort()." Control : ".$this->iCb." Pad : ".$this->iPadding." Seq : ".$this->iSeq." | Body |".implode(":",$aPkt)." |";
		return $sReturn;	
	}

}
