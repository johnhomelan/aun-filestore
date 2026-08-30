<?php
/**
 * This file contains the fileserver file handle operations handler
 *
 * @author John Brown <john@home-lan.co.uk>
 * @package core
*/
namespace HomeLan\FileStore\Services\Provider\FileServer;

use HomeLan\FileStore\Encapsulation\EncapsulationInterface;
use HomeLan\FileStore\Services\Provider\FileServer;
use HomeLan\FileStore\Services\ServiceDispatcher;
use HomeLan\FileStore\Services\StreamIn;
use HomeLan\FileStore\Vfs\Exception as VfsException;
use HomeLan\FileStore\Vfs\FileDescriptor;
use HomeLan\FileStore\Messages\EconetPacket;
use HomeLan\FileStore\Messages\FsRequest;

use config;
use Exception;

/**
 * Handles operations against a file handle the client already holds (as
 * opposed to path-based operations): opening/closing handles, reading and
 * writing bytes, checking eof, getting/setting the pointer and extent, and
 * the two handle-addressed function codes for rename and server-side copy.
 * All reachable only via raw FS function codes — none have a "*" CLI
 * equivalent.
 *
 * @package core
*/
class FileHandles {

	public function __construct(private readonly FileServer $oProvider)
	{
	}

	/**
	 * Moves a handle's position to $iExpectedPos only if it isn't already
	 * there.
	 *
	 * Plugins that self-advance position as a side effect of read()
	 * (LocalFile, Mdfs) already report the correct fsFTell() immediately
	 * after a read, so calling setPos() again here isn't just redundant —
	 * for LocalFile it's actively harmful: PHP's feof() only becomes true
	 * once a read attempt has hit the end of the stream, and any further
	 * fseek() (even to the same byte offset) resets that flag, so isEof()
	 * reports false forever and the caller's read loop spins. Only plugins
	 * that don't self-advance (AFS, DfsSsd, AdfsAdl, AdfsHD) actually need
	 * the explicit setPos() this guards.
	 *
	 * @param FileDescriptor $oFsHandle Untyped natively (matching
	 *  vfsGetFsHandle()'s own untyped signature) so test doubles that don't
	 *  extend FileDescriptor keep working; PHPStan is told the real type via
	 *  this docblock.
	*/
	private function syncPos($oFsHandle, int $iExpectedPos): void
	{
		$mPos = $oFsHandle->fsFTell();
		if((is_int($mPos) ? $mPos : 0) !== $iExpectedPos){
			$oFsHandle->setPos($iExpectedPos);
		}
	}

	/**
	 * EC_FS_FUNC_OPEN — opens (or creates) a file/directory and returns a
	 * new handle ID for it.
	*/
	public function openFile(FsRequest $oFsRequest): void
	{
		$iMustExist = $oFsRequest->getByte(1);
		$iReadOnly = $oFsRequest->getByte(2);
		$sPath = $oFsRequest->getString(3);
		$oReply = $oFsRequest->buildReply();
		if($iMustExist===0){
			$bMustExist = FALSE;
		}else{
			$bMustExist = TRUE;
		}
		if($iReadOnly===0){
			$bReadOnly = FALSE;
		}else{
			$bReadOnly = TRUE;
		}
		try {
			$oFsHandle = $this->oProvider->vfsCreateFsHandle($oFsRequest->getSourceNetwork(),$oFsRequest->getSourceStation(),$sPath,$bMustExist,$bReadOnly);
			$oReply->DoneOk();
			$oReply->appendByte($oFsHandle->getID());
		}catch(VfsException $oVfsException){
			if($oVfsException->isLocked()){
				$oReply->setError(0xc3,"Already open");
			}else{
				$oReply->setError(0xff,"No such file");
			}
		}catch(Exception){
			$oReply->setError(0xff,"No such file");
		}
		$this->oProvider->addReplyToBuffer($oReply->buildEconetpacket());
	}

	/**
	 * Closes a file
	 *
	*/
	public function closeFile(FsRequest $oFsRequest): void
	{
		$iHandle = $oFsRequest->getByte(1);
		if($iHandle === 0){
			$this->oProvider->vfsCloseAllFsHandles($oFsRequest->getSourceNetwork(),$oFsRequest->getSourceStation());
		}else{
			$this->oProvider->vfsCloseFsHandle($oFsRequest->getSourceNetwork(),$oFsRequest->getSourceStation(),$iHandle);
		}
		$oReply = $oFsRequest->buildReply();
		$oReply->DoneOk();
		$this->oProvider->addReplyToBuffer($oReply->buildEconetpacket());
	}

	/**
	 * EC_FS_FUNC_GETBYTES — streams up to $iBytes bytes from an open handle
	 * to the client over the econet data port, in 256-byte blocks, each
	 * gated on the previous block's ack.
	*/
	public function getBytes(FsRequest $oFsRequest): void
	{
		//The urd becomes the port to send the data to
		//The urd handle in the request is not the urd when load is called but denotes the port to stream the data to
		$iDataPort = $oFsRequest->getUrd();
		if($iDataPort === null){
			$oReply = $oFsRequest->buildReply();
			$oReply->setError(0xff,"Syntax ?");
			$this->oProvider->addReplyToBuffer($oReply->buildEconetpacket());
			return;
		}

		//File handle
		$iHandle = $oFsRequest->getByte(1);
		//Use pointer
		$iUserPtr = $oFsRequest->getByte(2);
		//Number of bytes to get
		$iBytes = $oFsRequest->get24bitIntLittleEndian(3);
		//Offset (only use if $iUserPtr!=0)
		$iOffset = $oFsRequest->get24bitIntLittleEndian(6);

		$this->oProvider->getLogger()->debug("Getbytes handle ".$iHandle." size ".$iBytes." prt ".$iUserPtr." offset ".$iOffset.".");

		$oFsHandle = $this->oProvider->vfsGetFsHandle($oFsRequest->getSourceNetwork(),$oFsRequest->getSourceStation(),$iHandle);

		//Send reply directly
		$oReply = $oFsRequest->buildReply();
		$oReply->DoneOk();
		$oReplyEconetPacket = $oReply->buildEconetpacket();
		$this->oProvider->addReplyToBuffer($oReplyEconetPacket);

		$_this = $this->oProvider;
		$oServiceDispatcher = $this->oProvider->getServiceDispatcher();

		if($oServiceDispatcher === null){
			$this->oProvider->getLogger()->error("FileServer: no ServiceDispatcher registered — cannot stream file data");
			return;
		}

		$oServiceDispatcher->addAckEvent($oFsRequest->getSourceNetwork(),$oFsRequest->getSourceStation(),$oReplyEconetPacket->getSequence(),function() use ($_this, $oFsHandle, $oFsRequest, $iBytes, $iOffset, $iUserPtr, $iDataPort, $oServiceDispatcher){
			if($iUserPtr != 0){
				$oFsHandle->setPos($iOffset);
			}
			//Not every VFS plugin's read() advances the handle's own position as a
			//side effect (see syncPos()) — capture the absolute start position here
			//and sync it explicitly around every block read below, rather than
			//relying on read() to have moved it — otherwise those plugins re-read
			//the same block forever and isEof() never trips.
			$mStartPos = $oFsHandle->fsFTell();
			$iStartPos = is_int($mStartPos) ? $mStartPos : 0;
			$iBytesToRead = $iBytes;
			if($iBytesToRead>256){
				$mBlock = $oFsHandle->read(256);
				$sBlock = is_string($mBlock) ? $mBlock : '';
				$iBytesToRead=$iBytesToRead-256;
			}else{
				$mBlock = $oFsHandle->read($iBytesToRead);
				$sBlock = is_string($mBlock) ? $mBlock : '';
				$iBytesToRead = $iBytesToRead-strlen($sBlock);
			}
			//Persist how far this read actually got straight away — the client may
			//send GETBYTES as a series of separate FS requests (one per 256-byte
			//block, each with $iUserPtr=0 meaning "continue from the current
			//position") rather than one request the ack-loop below chunks
			//internally. Without this, a request that's satisfied by this single
			//read (the common case) never advances the handle's position at all,
			//so the next GETBYTES request re-reads the same bytes forever.
			$this->syncPos($oFsHandle, $iStartPos + ($iBytes - $iBytesToRead));
			if(strlen($sBlock)>0){

				$oEconetPacket = new EconetPacket();
				$oEconetPacket->setDestinationNetwork($oFsRequest->getSourceNetwork());
				$oEconetPacket->setDestinationStation($oFsRequest->getSourceStation());
				$oEconetPacket->setFlags(0);
				$oEconetPacket->setPort($iDataPort);
				$oEconetPacket->setData($sBlock);

				$_this->addReplyToBuffer($oEconetPacket);
				$iSentSeq = $oEconetPacket->getSequence();
				$oServiceDispatcher->sendPackets($_this);
			}else{
				//No data at all was available on this call's first read (offset was already at or
				//past EOF). The client opened its receive block expecting data on $iDataPort the
				//moment it sent this request — nothing has landed there yet for this call, unlike
				//the block-then-done case above where a real block already satisfied it — so a
				//"done" reply straight on the request's own reply port arrives on a port the client
				//isn't listening on yet and is silently discarded. Send a marker on the data port
				//first, exactly as the shortfall-at-EOF path in the ack handler below does.
				//
				//That marker must be padded to the full $iBytes requested, not sent empty: the ROM's
				//GETBYTES client (ANFS's OSBGET read-ahead refill, send_txcb_swap_addrs) precomputes
				//the buffer end-address it expects from the byte count it originally asked for, and
				//silently re-arms the same one-shot RXCB and waits again — without ever resending
				//anything — if the reply it gets back doesn't advance the buffer pointer by exactly
				//that much. An empty reply always fails that check and hangs forever; a full-size
				//zero-padded block satisfies it exactly like the real short-block-plus-padding case
				//below does.
				//
				//Critically, the "done" summary can't just be queued alongside it in the same
				//sendPackets() call either: the client's one-shot RXCB has to be closed and reopened
				//for the *second* port transition (data port back to the request's own reply port)
				//just as much as for the first, and sending both together races that reopening the
				//same way the original single-reply bug did. Gate it on an ack of the marker instead,
				//so the reply port isn't touched until the client has actually finished with the
				//data port and had a chance to reopen for what comes next.
				$oEconetPacket = new EconetPacket();
				$oEconetPacket->setDestinationNetwork($oFsRequest->getSourceNetwork());
				$oEconetPacket->setDestinationStation($oFsRequest->getSourceStation());
				$oEconetPacket->setFlags(0);
				$oEconetPacket->setPort($iDataPort);
				$oEconetPacket->setData(str_pad("",$iBytesToRead,"\x00"));
				$_this->addReplyToBuffer($oEconetPacket);
				$iMarkerSeq = $oEconetPacket->getSequence();
				$oServiceDispatcher->sendPackets($_this);

				$oServiceDispatcher->addAckEvent($oFsRequest->getSourceNetwork(),$oFsRequest->getSourceStation(),$iMarkerSeq,function() use ($_this, $oFsRequest, $oServiceDispatcher){
					$oReply2 = $oFsRequest->buildReply();
					$oReply2->DoneOk();
					$oReply2->appendByte(0x80);
					$oReply2->setFlags(0);
					//Number of bytes sent
					$oReply2->append24bitIntLittleEndian(0);
					$_this->addReplyToBuffer($oReply2->buildEconetpacket());
					$oServiceDispatcher->sendPackets($_this);
				});
				return;
			}

			$cAckHandler = function(EncapsulationInterface $oAckPacket, FileServer $_this, FsRequest $oFsRequest, ServiceDispatcher $oServiceDispatcher, int $iBytes, int $iBytesToRead, FileDescriptor $oFsHandle, int $iDataPort, int $iStartPos, \Closure $cAckHandler): void {
				if($iBytesToRead==0 OR $oFsHandle->isEof()){
					//Builds and sends the "done" summary alone: only safe to batch straight in
					//with whatever triggered this (an ack of the last real data block) when
					//there's no new port transition involved — i.e. no padding packet is also
					//about to be sent on $iDataPort in this same round (see below).
					$fSendDoneReply = function() use ($_this, $oFsRequest, $oServiceDispatcher, $iBytes, $iBytesToRead, $oFsHandle){
						$oReply2 = $oFsRequest->buildReply();
						$oReply2->DoneOk();
						if($oFsHandle->isEof()){
							$oReply2->appendByte(0x80);
							$oReply2->setFlags(0);
						}else{
							$oReply2->appendByte(0);
						}
						//Number of bytes sent
						$oReply2->append24bitIntLittleEndian($iBytes-$iBytesToRead);
						$_this->addReplyToBuffer($oReply2->buildEconetpacket());
						$oServiceDispatcher->sendPackets($_this);
					};
					//As we have hit EOF the number of bytes sent has fallen short of the ammount
					//requested; send the remaining bytes. Only when there actually is a shortfall
					//to pad: a read that exactly satisfied $iBytes (iBytesToRead==0) that also
					//happens to land on EOF has nothing left to pad, and a zero-length data packet
					//on a data port whose one-shot RXCB the client already closed after the last
					//real block is a frame the client was never expecting and silently discards,
					//so the done reply below would never be requested and the transfer would hang.
					if($oFsHandle->isEof() AND $iBytesToRead>0){
						//The done summary goes on the request's own reply port, a different port
						//to the padding packet below (the data port) — sending both in the same
						//batch races the client's one-shot RXCB just as the single-reply case does
						//(see the no-data branch above), so gate it on an ack of the padding packet
						//instead of queuing it alongside.
						$oEconetPacket = new EconetPacket();
						$oEconetPacket->setDestinationNetwork($oFsRequest->getSourceNetwork());
						$oEconetPacket->setDestinationStation($oFsRequest->getSourceStation());
						$oEconetPacket->setPort($iDataPort);
						$oEconetPacket->setFlags(0);
						$oEconetPacket->setData(str_pad("",$iBytesToRead,"\x00"));
						$_this->addReplyToBuffer($oEconetPacket);
						$iPaddingSeq = $oEconetPacket->getSequence();
						$oServiceDispatcher->sendPackets($_this);

						$oServiceDispatcher->addAckEvent($oFsRequest->getSourceNetwork(),$oFsRequest->getSourceStation(),$iPaddingSeq,$fSendDoneReply);
					}else{
						($fSendDoneReply)();
					}

				}else{
					//See the comment in the outer closure — explicitly position the
					//handle at the next block before reading it.
					$this->syncPos($oFsHandle, $iStartPos + ($iBytes - $iBytesToRead));
					if($iBytesToRead>256){
						$mBlock = $oFsHandle->read(256);
					}else{
						$mBlock = $oFsHandle->read($iBytesToRead);
					}
					$sBlock = is_string($mBlock) ? $mBlock : '';
					$iBytesToRead = $iBytesToRead-strlen($sBlock);
					//See the comment in the outer closure — persist how far this read
					//actually got immediately, not just before the next one.
					$this->syncPos($oFsHandle, $iStartPos + ($iBytes - $iBytesToRead));

					$oEconetPacket = new EconetPacket();
					$oEconetPacket->setDestinationNetwork($oFsRequest->getSourceNetwork());
					$oEconetPacket->setDestinationStation($oFsRequest->getSourceStation());
					$oEconetPacket->setFlags(0);
					$oEconetPacket->setPort($iDataPort);
					$oEconetPacket->setData($sBlock);

					$_this->addReplyToBuffer($oEconetPacket);
					$oServiceDispatcher->addAckEvent($oFsRequest->getSourceNetwork(),$oFsRequest->getSourceStation(),$oEconetPacket->getSequence(),function(EncapsulationInterface $oAckPacket) use ($_this, $oFsRequest, $oServiceDispatcher, $iBytes, $iBytesToRead, $oFsHandle, $iDataPort, $iStartPos, $cAckHandler){
						($cAckHandler)($oAckPacket,$_this, $oFsRequest, $oServiceDispatcher, $iBytes, $iBytesToRead, $oFsHandle, $iDataPort, $iStartPos, $cAckHandler);
					});
					$oServiceDispatcher->sendPackets($_this);
				}

			};

			$oServiceDispatcher->addAckEvent($oFsRequest->getSourceNetwork(),$oFsRequest->getSourceStation(),$iSentSeq,function(EncapsulationInterface $oAckPacket) use ($cAckHandler, $_this, $oFsRequest, $oServiceDispatcher, $iBytes, $iBytesToRead, $oFsHandle, $iDataPort, $iStartPos) {
				($cAckHandler)($oAckPacket, $_this, $oFsRequest, $oServiceDispatcher, $iBytes, $iBytesToRead, $oFsHandle, $iDataPort, $iStartPos, $cAckHandler) ;
			});
		});

		$oServiceDispatcher->sendPackets($_this);

	}

	/**
	 * EC_FS_FUNC_PUTBYTES — tells the client which port to stream data to,
	 * then registers an inbound stream that writes the received bytes to
	 * the open handle once the client has sent them all.
	*/
	public function putBytes(FsRequest $oFsRequest): void
	{
		//File handle
		$iHandle = $oFsRequest->getByte(1);
		//Use pointer
		$iUserPtr = $oFsRequest->getByte(2);
		//Number of bytes to get
		$iBytes = $oFsRequest->get24bitIntLittleEndian(3);
		//Offset (only use if $iUserPtr!=0)
		$iOffset = $oFsRequest->get24bitIntLittleEndian(6);
		$this->oProvider->getLogger()->debug("Putbytes handle ".$iHandle." size ".$iBytes." prt ".$iUserPtr." offset ".$iOffset.".");

		$oFsHandle = $this->oProvider->vfsGetFsHandle($oFsRequest->getSourceNetwork(),$oFsRequest->getSourceStation(),$iHandle);

		$oUser = $this->oProvider->secGetUser($oFsRequest->getSourceNetwork(),$oFsRequest->getSourceStation());
		if($oUser === null){
			$oReply = $oFsRequest->buildReply();
			$oReply->setError(0xbf,"Who are you?");
			$this->oProvider->addReplyToBuffer($oReply->buildEconetpacket());
			return;
		}

		if($iUserPtr!=0){
			$this->oProvider->getLogger()->debug("Moving point ".$iOffset." bytes along the file ");
			//Move the file pointer to offset
			$oFsHandle->setPos($iOffset);
		}

		$oReply = $oFsRequest->buildReply();
		$oReply->DoneOk();
		$oReply->appendByte(config::getValueAsInt('econet_data_stream_port'));
		//Add max block size
		$oReply->append16bitIntLittleEndian(256);

		//Send reply directly
		$this->oProvider->addReplyToBuffer($oReply->buildEconetpacket());

		$_this = $this->oProvider;

		$this->oProvider->addStream($oFsRequest->getSourceNetwork(),$oFsRequest->getSourceStation(),
			new StreamIn(
				$iBytes,
				function(StreamIn $oStream, EconetPacket $oPacket) use ($oFsRequest, $_this) {
					$oAck = $oFsRequest->buildReply();
					$oAck->DoneOk();
					$oAckPackage = $oAck->buildEconetpacket();
					$_this->addReplyToBuffer($oAckPackage);
				},
				function(StreamIn $oStream, string $sData) use ($oFsRequest, $oFsHandle, $_this){
					$oFsHandle->write($sData);
					usleep(config::getValueAsInt('bbc_default_pkg_sleep'));
					$oReply2 = $oFsRequest->buildReply();
			                $oReply2->DoneOk();
			                $oReply2->appendByte(0);
					$oReply2->append24bitIntLittleEndian(strlen($sData));
					$_this->addReplyToBuffer($oReply2->buildEconetpacket());
					$_this->freeStream($oFsRequest->getSourceNetwork(),$oFsRequest->getSourceStation());
				},
				function(string $sError) use($oFsRequest, $_this) {
					$_this->getLogger()->debug("Putbytes waiting for data (".$sError.")");
					$_this->freeStream($oFsRequest->getSourceNetwork(),$oFsRequest->getSourceStation());
					$oFailReply=$oFsRequest->buildReply();
					$oFailReply->setError(0xff,"Timeout");
					$_this->addReplyToBuffer($oFailReply->buildEconetpacket());
				},
				60,
				$oFsHandle->getEconetPath(),
				$oUser->getUsername() ?? ''

			)
		);

	}

	/**
	 * EC_FS_FUNC_GETBYTE — reads and replies with a single byte from an open
	 * handle, advancing its position.
	*/
	public function getByte(FsRequest $oFsRequest): void
	{
		$iHandle = $oFsRequest->getByte(1);
		$oReply = $oFsRequest->buildReply();
		$oReply->DoneOk();

		$this->oProvider->getLogger()->debug("Getbyte handle ".$iHandle." ");
		//Reads a byte from the file handle
		$oFsHandle = $this->oProvider->vfsGetFsHandle($oFsRequest->getSourceNetwork(),$oFsRequest->getSourceStation(),$iHandle);
		if($oFsHandle->isEof()){
			$oReply->appendByte(0);
			$oReply->appendByte(0x80);
		}else{
			//Not every VFS plugin's read() advances the handle's own position as a
			//side effect (see syncPos()) — track and set it explicitly rather than
			//relying on that.
			$mPos = $oFsHandle->fsFTell();
			$iPos = is_int($mPos) ? $mPos : 0;
			$mByte = $oFsHandle->read(1);
			$this->syncPos($oFsHandle, $iPos + 1);
			$oReply->appendByte(ord(is_string($mByte) ? $mByte : ''));
			$oReply->appendByte(0);
		}

		$this->oProvider->addReplyToBuffer($oReply->buildEconetpacket());

	}

	/**
	 * EC_FS_FUNC_PUTBYTE — writes a single byte to an open handle at its
	 * current position.
	*/
	public function putByte(FsRequest $oFsRequest): void
	{
		$iHandle = $oFsRequest->getByte(1);
		$iByte = $oFsRequest->getByte(2);
		$oReply = $oFsRequest->buildReply();

		$this->oProvider->getLogger()->debug("Putbyte handle ".$iHandle." ");
		//Writes a byte to the file handle
		$oFsHandle = $this->oProvider->vfsGetFsHandle($oFsRequest->getSourceNetwork(),$oFsRequest->getSourceStation(),$iHandle);
		$oFsHandle->write(chr($iByte ?? 0));

		$oReply->DoneOk();
		$this->oProvider->addReplyToBuffer($oReply->buildEconetpacket());
	}

	/**
	 * EC_FS_FUNC_GET_EOF — replies whether an open handle is at end-of-file.
	*/
	public function eof(FsRequest $oFsRequest): void
	{
		$this->oProvider->getLogger()->debug("Eof Called by ".$oFsRequest->getSourceNetwork().".".$oFsRequest->getSourceStation());
		//Get the file handle id
		$iHandle = $oFsRequest->getByte(1);
		$oReply = $oFsRequest->buildReply();
		$oReply->DoneOk();

		//Get the file handle
		$oFsHandle = $this->oProvider->vfsGetFsHandle($oFsRequest->getSourceNetwork(),$oFsRequest->getSourceStation(),$iHandle);
		if($oFsHandle->isEof()){
			$oReply->appendByte(0xFF);
		}else{
			$oReply->appendByte(0);
		}
		$this->oProvider->addReplyToBuffer($oReply->buildEconetpacket());
	}

	/**
	 * EC_FS_FUNC_GET_ARGS — replies with a property of an open handle:
	 * current file pointer (arg 0) or file size (args 1/2).
	*/
	public function getArgs(FsRequest $oFsRequest): void
	{
		$iHandle = $oFsRequest->getByte(1);
		$iArg = $oFsRequest->getByte(2);

		switch($iArg){
			case 0:
				//EC_FS_ARG_PTR
				$oReply = $oFsRequest->buildReply();
				$oFd = $this->oProvider->vfsGetFsHandle($oFsRequest->getSourceNetwork(),$oFsRequest->getSourceStation(),$iHandle);
				$mPos = $oFd->fsFTell();
				$oReply->DoneOk();
				$oReply->append24bitIntLittleEndian(is_int($mPos) ? $mPos : 0);
				break;
			case 1:
				//EC_FS_ARG_EXT
				$oReply = $oFsRequest->buildReply();
				$oFd = $this->oProvider->vfsGetFsHandle($oFsRequest->getSourceNetwork(),$oFsRequest->getSourceStation(),$iHandle);
				$aStat = $oFd->fsFStat();
				if(is_array($aStat) AND array_key_exists('size',$aStat) AND is_int($aStat['size'])){
					$iSize = $aStat['size'];
				}else{
					$iSize = 0;
				}
				$oReply->DoneOk();
				$oReply->append24bitIntLittleEndian($iSize);
				break;
			case 2:
				//EC_FS_ARG_SIZE
				$oReply = $oFsRequest->buildReply();
				$oFd = $this->oProvider->vfsGetFsHandle($oFsRequest->getSourceNetwork(),$oFsRequest->getSourceStation(),$iHandle);
				$aStat = $oFd->fsFStat();
				if(is_array($aStat) AND array_key_exists('size',$aStat) AND is_int($aStat['size'])){
					$iSize = $aStat['size'];
				}else{
					$iSize = 0;
				}
				$oReply->DoneOk();
				$oReply->append24bitIntLittleEndian($iSize);
				break;
			default:
				$oReply = $oFsRequest->buildReply();
				$oReply->setError(0x8f,"Bad RDARGS argument");
				break;
		}
		$this->oProvider->addReplyToBuffer($oReply->buildEconetpacket());
	}

	/**
	 * EC_FS_FUNC_SET_ARGS — sets a property of an open handle: the file
	 * pointer (arg 0) or the file extent, truncating/extending it (arg 1).
	*/
	public function setArgs(FsRequest $oFsRequest): void
	{
		$iHandle = $oFsRequest->getByte(1);
		$iArg    = $oFsRequest->getByte(2);
		$oReply  = $oFsRequest->buildReply();

		switch($iArg){
			case 0:
				//EC_FS_ARG_PTR — set file pointer
				$oFd = $this->oProvider->vfsGetFsHandle($oFsRequest->getSourceNetwork(),$oFsRequest->getSourceStation(),$iHandle);
				$iPos = $oFsRequest->get24bitIntLittleEndian(3);
				$oFd->setPos($iPos);
				$oReply->DoneOk();
				break;
			case 1:
				//EC_FS_ARG_EXT — set file extent (truncate/extend)
				$oFd = $this->oProvider->vfsGetFsHandle($oFsRequest->getSourceNetwork(),$oFsRequest->getSourceStation(),$iHandle);
				$iExt = $oFsRequest->get24bitIntLittleEndian(3);
				$oFd->setExt($iExt);
				$oReply->DoneOk();
				break;
			default:
				$oReply->setError(0x8f,"Bad SETARGS argument");
				break;
		}
		$this->oProvider->addReplyToBuffer($oReply->buildEconetpacket());
	}

	/**
	 * EC_FS_FUNC_RENAME — resolves the source/destination directory handles
	 * (falling back to the caller's CSD when a handle of 0 is given) and
	 * renames/moves the file by the resulting paths.
	*/
	public function renameFileByHandle(FsRequest $oFsRequest): void
	{
		$oReply = $oFsRequest->buildReply();
		try {
			$iSrcHandle = $oFsRequest->getByte(1);
			[$sSrcName, $iNextPos] = $oFsRequest->getStringEndPos(2);

			$iDstHandle = $oFsRequest->getByte($iNextPos);
			[$sDstName]  = $oFsRequest->getStringEndPos($iNextPos + 1);

			$iResolvedSrc = ($iSrcHandle === 0) ? $oFsRequest->getCsd() : $iSrcHandle;
			$iResolvedDst = ($iDstHandle === 0) ? $oFsRequest->getCsd() : $iDstHandle;

			$oSrcDir  = $this->oProvider->vfsGetFsHandle($oFsRequest->getSourceNetwork(),$oFsRequest->getSourceStation(),$iResolvedSrc);
			$sSrcPath = $oSrcDir->getEconetPath().'.'.$sSrcName;

			$oDstDir  = $this->oProvider->vfsGetFsHandle($oFsRequest->getSourceNetwork(),$oFsRequest->getSourceStation(),$iResolvedDst);
			$sDstPath = $oDstDir->getEconetPath().'.'.$sDstName;

			$this->oProvider->vfsMoveFile($oFsRequest->getSourceNetwork(),$oFsRequest->getSourceStation(),$sSrcPath,$sDstPath);
			$oReply->DoneOk();
		}catch(Exception){
			$oReply->setError(0xff,"No such file");
		}
		$this->oProvider->addReplyToBuffer($oReply->buildEconetpacket());
	}

	/**
	 * Handles function-code COPY_DATA (code 35) — server-side block copy between open handles.
	 * Packet data: [src_handle][src_offset 3LE][dst_handle][dst_offset 3LE][length 3LE]
	*/
	public function copyData(FsRequest $oFsRequest): void
	{
		$iSrcHandle = $oFsRequest->getByte(1);
		$iSrcOffset = $oFsRequest->get24bitIntLittleEndian(2);
		$iDstHandle = $oFsRequest->getByte(5);
		$iDstOffset = $oFsRequest->get24bitIntLittleEndian(6);
		$iLength    = $oFsRequest->get24bitIntLittleEndian(9);

		$oReply = $oFsRequest->buildReply();
		try {
			$oSrc = $this->oProvider->vfsGetFsHandle($oFsRequest->getSourceNetwork(),$oFsRequest->getSourceStation(),$iSrcHandle);
			$oDst = $this->oProvider->vfsGetFsHandle($oFsRequest->getSourceNetwork(),$oFsRequest->getSourceStation(),$iDstHandle);

			$oSrc->setPos($iSrcOffset);
			$mData = $oSrc->read($iLength);
			$oDst->setPos($iDstOffset);
			$oDst->write(is_string($mData) ? $mData : '');

			$oReply->DoneOk();
		}catch(Exception){
			$oReply->setError(0xff,"Copy failed");
		}
		$this->oProvider->addReplyToBuffer($oReply->buildEconetpacket());
	}

}
