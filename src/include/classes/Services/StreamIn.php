<?php

namespace HomeLan\FileStore\Services;

use HomeLan\FileStore\Messages\EconetPacket; 
/**
 * Some services make use of another port for streaming data from the client.  Each client stream will get its own unique port when its in use, the port is then cleared down once the 
 * data has been streamed.
 *
 * For example the fileserver uses it to stream binary data from the client to write into a file handle
 *
 * This class deals with streaming in data from a port, that any service can make use of.
*/
class StreamIn {

	private $fRecivedPacket;
	private $fSucessCallback;
	private $fFailCallback;
	private int $iTimeout;
	private readonly int $iNoPktTimeout;
	private readonly string $sPath;
	private readonly string $sUser;
	private ?string $sData = null;

	public function __construct(private readonly int $iBytes,callable $fRecivedPacket, callable $fSucessCallback, callable $fFailCallback,int $iTimeout, string $sPath, string $sUser)
	{
		$this->fRecivedPacket = $fRecivedPacket;
		$this->fSucessCallback = $fSucessCallback;
		$this->fFailCallback = $fFailCallback;
		$this->iTimeout = time() + $iTimeout;
		$this->iNoPktTimeout = $iTimeout;
		$this->sPath = $sPath;
		$this->sUser = $sUser;
	}

	/** 
	 * Processes an inbound packet 
	 *
	 * If there is still outstanding data this packets data will just get appended to the buffer, and the fRecivedPacket callback is called
	 * If this packet completes the data stream the fSucessCallback callback is called 
	 * In the event of a timeout the fFailCallback callback is called
	*/
	public function inboundPacket(EconetPacket $oPacket): void
	{
		$this->sData=$this->sData.$oPacket->getData();
		if(strlen($this->sData)<$this->iBytes){
			if(time()>$this->iTimeout){
				//Timed out 
				($this->fFailCallback)("timeout");
			}else{
				//Pkt recevied reset the timeout
				$this->iTimeout = time() + $this->iNoPktTimeout;
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
	public function checkTimeout(): void
	{
		if(time()>$this->iTimeout){
			//Timed out 
			($this->fFailCallback)("timeout");
		}
	}

	public function getPath():string
	{
		return $this->sPath;
	}
	
	public function getUser():string
	{
		return $this->sUser;
	}
}
