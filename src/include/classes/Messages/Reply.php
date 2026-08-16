<?php
/**
 * This file contains the fsreply class
 *
 * @author John Brown <john@home-lan.co.uk>
 * @package coreprotocol
*/
namespace HomeLan\FileStore\Messages; 

use Exception;

/** 
 * This class is used to repressent reply packets
 *
 * @package coreprotocol
*/
class Reply {

	protected string $sPkt = '';

	protected RequestInterface $oRequest;

	protected int $iFlags = 0;

	protected ?int $iReplyPort = NULL;

	/** @param RequestInterface $oRequest One of the concrete Request subclasses (or PrintServerData) accepted below. */
	public function __construct(RequestInterface $oRequest)
	{
		if($oRequest::class==\HomeLan\FileStore\Messages\FsRequest::class or $oRequest::class==\HomeLan\FileStore\Messages\PrintServerEnquiry::class OR $oRequest::class==\HomeLan\FileStore\Messages\PrintServerData::class OR $oRequest::class==\HomeLan\FileStore\Messages\ArpRequest::class OR $oRequest::class==\HomeLan\FileStore\Messages\Dci4ArpRequest::class OR $oRequest::class==\HomeLan\FileStore\Messages\BeebTermRequest::class OR $oRequest::class==\HomeLan\FileStore\Messages\TorchnetRequest::class OR $oRequest::class==\HomeLan\FileStore\Messages\BridgeRequest::class OR $oRequest::class==\HomeLan\FileStore\Messages\MaceMailRequest::class OR $oRequest::class==\HomeLan\FileStore\Messages\TeletextRequest::class OR $oRequest::class==\HomeLan\FileStore\Messages\ViewdataRequest::class){
			$this->oRequest = $oRequest;
			$this->iFlags = $oRequest->getFlags();
			//Snapshotted now rather than re-read from $oRequest in
			//buildEconetpacket(): some request classes (e.g. MaceMailRequest,
			//TeletextRequest) support a mutable reply port via setReplyPort(),
			//used to build several differently-addressed replies off one
			//shared request object in turn. Reading it lazily meant every
			//already-built Reply would silently pick up whatever the port
			//was set to *last*, rather than what it was when that Reply was
			//actually built.
			$this->iReplyPort = $oRequest->getReplyPort();
		}else{
			throw new Exception("An fsreply object was created with out suppling an fsrequest object");
		}
	}

	public function appendByte(int $iByte): void
	{
		$this->sPkt = $this->sPkt.pack('C',$iByte);
	}

	public function appendString(string $sString): void
	{
		$aChars = str_split($sString);
		foreach($aChars as $sChar)
		{
			$this->sPkt = $this->sPkt.pack('C',ord($sChar));
		}
	}

	public function append16bitIntLittleEndian(int $iInt): void
	{
		$this->sPkt = $this->sPkt.pack('v',$iInt);
	}

	public function append24bitIntLittleEndian(int $iInt): void
	{
		$this->sPkt = $this->sPkt.pack('v',$iInt).pack('C',0);
	}

	public function append32bitIntLittleEndian(int $iInt): void
	{
		$this->sPkt = $this->sPkt.pack('V',$iInt);
	}

	public function append16bitIntBigEndian(int $iInt): void
	{
		$this->sPkt = $this->sPkt.pack('n',$iInt);
	}

	public function append24bitIntBigEndian(int $iInt): void
	{
		$this->sPkt = $this->sPkt.pack('C',0).pack('n',$iInt);
	}

	public function append32bitIntBigEndian(int $iInt): void
	{
		$this->sPkt = $this->sPkt.pack('N',$iInt);
	}


	public function appendRaw(string $sRawBytes): void
	{
		$this->sPkt = $this->sPkt.$sRawBytes;
	}

	public function setFlags(int $iFlags): void
	{
		$this->iFlags = $iFlags;
	}

	public function getFlags(): int
	{
		return $this->iFlags;
	}

	public function buildEconetpacket(): \HomeLan\FileStore\Messages\EconetPacket
	{
		$iDestinationStation = $this->oRequest->getSourceStation();
		$iDestinationNetwork = $this->oRequest->getSourceNetwork();
		if($iDestinationStation === null OR $iDestinationNetwork === null OR $this->iReplyPort === null){
			throw new Exception("Reply cannot be addressed: the request it was built from has no source network/station/reply port");
		}

		$oEconetPacket = new EconetPacket();
		$oEconetPacket->setPort($this->iReplyPort);
		$oEconetPacket->setFlags($this->iFlags);
		$oEconetPacket->setDestinationStation($iDestinationStation);
		$oEconetPacket->setDestinationNetwork($iDestinationNetwork);
		$oEconetPacket->setData($this->sPkt);
		return $oEconetPacket;
	}
}
