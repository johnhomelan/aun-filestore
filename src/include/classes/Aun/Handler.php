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
	 * Constructor registers the Logger
	 *  
	*/
	public function __construct(private readonly \Psr\Log\LoggerInterface $oLogger,private readonly ServiceDispatcher $oServices,private readonly PacketDispatcher $oPacketDispatcher)
	{		
	}

	public function setSocket(Socket $oAunServer)
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
				$this->oLogger->debug("Aun Handler: Ack");
				$this->_unQueue($oAunPacket);
				break;
			default:
				//Send an ack for the AUN packet if needed
				$sAck = $oAunPacket->buildAck();
				if(strlen($sAck)>0){
					$this->oLogger->debug("Aun Handler: ".$oAunPacket->getPacketType()." Sending Ack packet");
					$this->oAunServer->send($sAck,$sSrcAddress);
				}
				break;
		}
		//Dispatch packet to all the services so the relivent one can deal with it 
		$this->oServices->inboundPacket($oAunPacket);
		
		//Send any messages for the services
		$aReplies = $this->oServices->getReplies();
		foreach($aReplies as $oReply){
			//In theroy there can be packets queued for other abstractions (e.g. created via a timer that triggered) 
			$this->oPacketDispatcher->sendPacket($oReply);
		}
	}

	public function timer():void
	{
		$this->_runQueue();
	}

	public function send(EconetPacket $oPacket, int $iRetries = 3):void
	{
		$this->oLogger->debug("Aun Handler: Sending packet to queue");
	
		$this->aQueue[] = ['packet'=>$oPacket,'retries'=>$iRetries,'attempts'=>0,'backoff'=>0];
/*		if (count($this->aQueue)==1){
			$this->_runQueue();
		}*/
	}

	private function _runQueue():void
	{	
		if(count($this->aQueue)>0){
			var_dump($this->aQueue);
			$aQueueEntry = array_shift($this->aQueue);
			if($aQueueEntry['backoff']>1){
				//Each attempt increase the time between attempts
				$aQueueEntry['backoff']=$aQueueEntry['backoff']-400;
				array_unshift($this->aQueue,$aQueueEntry);
				return;
			}
			if($aQueueEntry['retries']>0){
				//More re-tires left re-queue
				$aQueueEntry['retries'] = $aQueueEntry['retries']-1;
				$aQueueEntry['attempts'] = $aQueueEntry['attempts']+1;
				$aQueueEntry['backoff']=$aQueueEntry['attempts']*400;
				array_unshift($this->aQueue,$aQueueEntry);
				$this->oLogger->debug("Aun Handler: ".$aQueueEntry['retries']." retires left, ".$aQueueEntry['attempts']." attempts made.");
			}
			$this->_writeOutPkt($aQueueEntry['packet']);
		}else{
			//$this->oLogger->debug("Aun Handler: No packets in Queue");
		}
	}

	private function _unQueue(AunPacket $oAck):void
	{
		$this->oLogger->debug("Aun Handler: Dequeuing packet due to scout ack");
		if(count($this->aQueue)>0){
			$aQueueEntry = array_shift($this->aQueue);
			if($oAck->getSequence() == $aQueueEntry['packet']->getSequence()){
				if($aQueueEntry['attempts']==0){
					array_unshift($this->aQueue,$aQueueEntry);
				}
				$this->_runQueue();
			}else{
				array_unshift($this->aQueue,$aQueueEntry);
			}
		}
	}

	private function _writeOutPkt(EconetPacket $oPacket)
	{
		$this->oLogger->debug("Aun Handler: Transmitting packet");
		$sHost = $this->_getIpPortFromNetworkStation($oPacket->getDestinationNetwork(),$oPacket->getDestinationStation());
		$sAunFrame = $oPacket->getAunFrame();
		if(strlen($sAunFrame)>0){
			$this->oAunServer->send($sAunFrame,$sHost);
		}
	}

	/**
	 * Get the ip:port combination for a given network and station 
	*/ 	
	private function _getIpPortFromNetworkStation(int $iNetwork, int $iStation):string
	{
		$sIP = Map::ecoAddrToIpAddr($iNetwork,$iStation);
		if(!str_contains($sIP,':')){
			$sHost=$sIP.':'.config::getValue('aun_default_port');
		}else{
			$sHost=$sIP;
		}
		return $sHost;
	}

}
