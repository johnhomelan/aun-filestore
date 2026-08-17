<?php
/**
 * This file contains the bridgerequest class
 *
 * @author John Brown <john@home-lan.co.uk>
 * @package coreprotocol
*/
namespace HomeLan\FileStore\Messages; 

use HomeLan\FileStore\Messages\EconetPacket; 
use Exception; 

/** 
 * This class is used to repressent a file server request
 *
 * @package coreprotocol
*/
class BridgeRequest extends Request {


	protected ?int $iReplyPort = NULL;
	
	protected ?int $iFunction = NULL;

	protected ?int $iUrd = NULL;

	protected ?int $iCsd = NULL;

	protected ?int $iLib = NULL;

	/**
	  * @var array<int, string>
	*/  
	protected array $aFunctionMap = [0x80=>'EC_BR_QUERY', 0x81=>'EC_BR_QUERY2', 0x82=>'EC_BR_LOCALNET', 0x83=>'EC_BR_NETKNOWN'];

	protected ?EconetPacket $oEconetPacket = NULL;


	public function __construct(EconetPacket $oEconetPacket, \Psr\Log\LoggerInterface $oLogger)
	{
		parent:: __construct($oEconetPacket,$oLogger);
		$this->oEconetPacket = $oEconetPacket;
		$this->decode($oEconetPacket->getData() ?? '');
	}

	public function getReplyPort():?int
	{
		return $this->iReplyPort;
	}

	/**
	 * Bridge rejects any packet without a resolvable source network/station
	 * before ever constructing a BridgeRequest (see Bridge::broadcastPacketIn()),
	 * so both are guaranteed present here.
	 */
	public function getSourceNetwork(): int
	{
		if ($this->iSourceNetwork === null) {
			throw new Exception("BridgeRequest has no source network");
		}
		return $this->iSourceNetwork;
	}

	public function getSourceStation(): int
	{
		if ($this->iSourceStation === null) {
			throw new Exception("BridgeRequest has no source station");
		}
		return $this->iSourceStation;
	}


	public function getFunction():string
	{
		if(is_numeric($this->iFunction)){
			if(isset($this->aFunctionMap[$this->iFunction])){
				return $this->aFunctionMap[$this->iFunction];
			}
			$this->oLogger->debug("No function to map on to ".$this->iFunction);
		}
		throw new Exception("No packet was decoded unable to getFunction");
	}

	/**
	  * Decodes an AUN packet
	  *
	  * Two wire layouts are seen in the wild, so both are tried:
	  *
	  *   A) [function:1]["Bridge":6][replyPort:1][data]  — this project's own bridge
	  *      replies, and some software bridges, put the function code as a leading
	  *      data byte ahead of the mixed-case magic string.
	  *   B) [magic:6][replyPort:1][data], function code carried in the Econet
	  *      control byte — genuine Acorn ANFS ROM broadcasts. The ROM's magic
	  *      string is commonly upper-cased ("BRIDGE"), so the match is
	  *      case-insensitive.
 	  *
 	*/
	public function decode(string $sBinaryString): void
	{
		if(strcasecmp(substr($sBinaryString, 1, 6), 'Bridge') === 0){
			//Layout A — function code is the leading data byte
			$aHeader=unpack('C',$sBinaryString);
			$this->iFunction = ($aHeader !== false) ? self::_asInt($aHeader[1]) : 0;
			$sBinaryString = substr($sBinaryString, 7);
		}elseif(strcasecmp(substr($sBinaryString, 0, 6), 'Bridge') === 0){
			//Layout B — no leading function byte; the control byte carries it instead
			$this->iFunction = self::_asInt($this->oEconetPacket?->getFlags());
			$sBinaryString = substr($sBinaryString, 6);
		}else{
			$this->oLogger->debug("An invalid bridge request was received (it did not begin with the string Bridge)");
			throw new Exception("Invalid bridge request");
		}

		//Read the reply port type 1 byte unsigned int
		$aHeader=unpack('C',$sBinaryString);
		if($aHeader === false){
			return;
		}
		$this->iReplyPort = self::_asInt($aHeader[1]);
		$sBinaryString = substr($sBinaryString,1);

		//The reset is data
		$this->sData = $sBinaryString;

	}

	public function getNetwork():int
	{
		//This first byte after the reply port is the network number the bridge is being queried about
		$aData = unpack('C',(string) $this->sData);
		if($aData === false){
			return 0;
		}
		return self::_asInt($aData[1]);
	}

	/** @return array<int,int> */
	public function getNetworkList(): array
	{
		if(empty($this->sData)){
			return [];
		}
		$aBytes = unpack('C*', (string) $this->sData);
		if($aBytes === false){
			return [];
		}
		return array_values(array_map(self::_asInt(...), $aBytes));
	}

	public function buildReply(): \HomeLan\FileStore\Messages\Reply
	{
		return new Reply($this);
	}
}
