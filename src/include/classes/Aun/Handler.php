<?php
/**
 * This file contains the aun handler class 
 *
 * @author John Brown <john@home-lan.co.uk>
 * @package corenet
*/
namespace HomeLan\FileStore\Aun; 

use HomeLan\FileStore\Messages\EconetPacket; 
use HomeLan\FileStore\Services\ProviderInterface;
use HomeLan\FileStore\Services\ServiceDispatcher;
use HomeLan\FileStore\Encapsulation\PacketDispatcher;
use HomeLan\FileStore\Aun\Map;
use React\Datagram\Socket;

use HomeLan\FileStore\Aun\AunPacket;
use config;


/**
 * This class handles all AUN packets recieved and dispatched by the system
 * @package corenet
*/
class Handler {

	static private ?\HomeLan\FileStore\Aun\Handler $oSingleton = null;
	private array $aQueue = [];

	private array $aAwaitingAck = [];


	/**
	 * Keeping this class as a singleton, this is static method should be used to get references to this object
	 *
	*/
	public static function create(\Psr\Log\LoggerInterface $oLogger = null, ?Socket $oAunServer = null, private readonly ServiceDispatcher $oServices):Handler
	{
		if(!is_object(self::$oSingleton)){
			self::$oSingleton = new Handler($oLogger);
		}
		if(!is_null($oAunServer)){
			self::$oSingleton->setSocket($oAunServer);

		}
		return self::$oSingleton;	
	}

	/**
	 * Constructor registers the Logger
	 *  
	*/
	public function __construct(private readonly \Psr\Log\LoggerInterface $oLogger)
	{		
	}

	public function(Socket $oAunServer)
	{
		$this->oAunServer = $oAunServer;
	}

	public function onClose():void
	{
		$this->oLogger->debug("Aun handler: Closing");
	}

	public function receive(string $sMessage, string $sSrcAddress, string $sDstAddress):void
	{
		$this->oLogger->debug("Aun Handler: Received packet from ".$sSrcAddress);
		$oAunPacket = new AunPacket();
					
		$oAunPacket->setSourceIP($sSrcAddress);
		$oAunPacket->setDestinationIP(config::getValue('local_ip'));
		$oAunPacket->decode($sMessage);
		switch($oAunPacket->getPacketType()){
			case 'Ack':
				//Got an Ack use 
				break;
			default:
				//Send an ack for the AUN packet if needed
				$sAck = $oAunPacket->buildAck();
				if(strlen($sAck)>0){
					$oLogger->debug("filestore: Sending Ack packet");
					$oAunServer->send($sAck,$sSrcAddress);
				}
				//Dispatch packet to all the services so the relivent one can deal with it 
				$oServices->inboundPacket($oAunPacket);
				
				//Send any messages for the services
				$aReplies = $oServices->getReplies();
				foreach($aReplies as $oReply){
					$this->send($oReply);
				}
				break;
		}
	}

	public function send(EconetPacket $oPacket, int $iRetries = 3):void
	{
		$this->oLogger->debug("Aun Handler: Sending packet to queue");
		$this->aQueue[] = ['packet'=>$oPacket,'retries'=>$iRetries,'attempts'=>0];
		if (count($this->aQueue)==1){
			$this->_runQueue();
		}
	}

	private function _runQueue():void
	{	
		$this->oLogger->debug("Aun Handler: Running Queue");
		var_dump($this->aQueue);
		if(count($this->aQueue)>0){
			$aQueueEntry = array_shift($this->aQueue);
			if($aQueueEntry['retries']>0){
				//More re-tires left re-queue
				$aQueueEntry['retries'] = $aQueueEntry['retries']-1;
				$aQueueEntry['attempts'] = $aQueueEntry['attempts']+1;
				array_unshift($this->aQueue,$aQueueEntry);
				$this->oLogger->debug("Aun Handler: ".$aQueueEntry['retries']." retires left, ".$aQueueEntry['attempts']." attempts made.");
			}
			$this->_writeOutPkt($aQueueEntry['packet']);
		}else{
			$this->oLogger->debug("Aun Handler: No packets in Queue");
		}
	}

	private function _unQueue():void
	{
		$this->oLogger->debug("Aun Handler: Dequeuing packet due to scout ack");
		$aQueueEntry = array_shift($this->aQueue);
		if($aQueueEntry['attempts']==0){
			array_unshift($this->aQueue,$aQueueEntry);
		}
		$this->_runQueue();
	}

	private function _writeOutPkt(EconetPacket $oPacket)
	{
		$sIP = AunMap::ecoAddrToIpAddr($oPacket->getDestinationNetwork(),$oPacket->getDestinationStation());
		if(!str_contains($sIP,':')){
			$sHost=$sIP.':'.config::getValue('aun_default_port');
		}else{
			$sHost=$sIP;
		}
		$sAunFrame = $oPacket->getAunFrame();
		if(strlen($sAunFrame)>0){
			$this->oAunServer->send($sAunFrame,$sHost);
		}
	}

}
