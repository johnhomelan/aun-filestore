<?php

namespace HomeLan\FileStore\Services;

use HomeLan\FileStore\Messages\EconetPacket; 

class StreamIn {

	private $iPort;
	private $iBytes;
	private $fRecivedPacket;
	private $fSucessCallback;
	private $fFailCallback;
	private $iTimeout;
	private $iNoPktTimeout;
	private $sData;

	public function __construct(int $iPort, int $iBytes,callable $fRecivedPacket, callable $fSucessCallback, callable $fFailCallback,int $iTimeout=60)
	{
		$this->iPort = $iPort;
		$this->iBytes = $iBytes;
		$this->fRecivedPacket = $fRecivedPacket;
		$this->fSucessCallback = $fSucessCallback;
		$this->fFailCallback = $fFailCallback;
		$this->iTimeout = time() + $iTimeout;
		$this->iNoPktTimeout = $iTimeout;
	}

	/** 
	 * Processes an inbound packet 
	 *
	 * If there is still outstanding data this packets data will just get appended to the buffer, and the fRecivedPacket callback is called
	 * If this packet completes the data stream the fSucessCallback callback is called 
	 * In the event of a timeout the fFailCallback callback is called
	*/
	public function inboundPacket(EconetPacket $oPacket)
	{
		$this->sData=$this->sData.$oPacket->getData();
		if(strlen($this->sData)<$this->iBytes){
			if(time()>$this->iTimeout){
				//Timed out 
				($this->fFailCallback)("timeout");
			}else{
				//Pkt recevied reset the timeout
				$$this->iTimeout = time() + $this->iNoPktTimeout;
				($this->fRecivedPacket)($this,$oPacket);
			}
		}else{
			($this->fSucessCallback)($this,$this->sData);
		}
	}

	/**
	 * Checks if the stream has timed out
	 *
	 * If the stream has timed out the fFailCallback callback is called
	 * If it has not timeout no action is taken
	*/
	public function checkTimeout()
	{
		if(time()>$this->iTimeout){
			//Timed out 
			($this->fFailCallback)("timeout");
		}
	}
}
