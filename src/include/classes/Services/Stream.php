<?php

namespace HomeLan\FileStore\Services;

use HomeLan\FileStore\Messages\EconetPacket; 

class Stream {

	private $iPort;
	private $iBytes;
	private $fRecivedPacket;
	private $fSucessCallback;
	private $fFailCallback;
	private $iTimeout;
	private $sData;

	public function __construct(int $iPort, int $iBytes,callable $fRecivedPacket, callable $fSucessCallback, callable $fFailCallback,int $iTimeout=60)
	{
		$this->iPort = $iPort;
		$this->iBytes = $iBytes;
		$this->fRecivedPacket = $fRecivedPacket;
		$this->fSucessCallback = $fSucessCallback;
		$this->fFailCallback = $fFailCallback;
		$this->iTimeout = time() + $iTimeout;
	}

	public function inboundPacket(EconetPacket $oPacket)
	{
		$this->sData=$this->sData.$oPacket->getData();
		if(strlen($this->sData)<$this->iBytes){
			if(time()>$this->iTimeout){
				//Timed out 
				$this->fFailCallback("timeout");
			}else{
				$this->fRecivedPacket($this,$oPacket);
			}
		}else{
			$this->fSucessCallback($this,$this->sData);
		}
	}
}
