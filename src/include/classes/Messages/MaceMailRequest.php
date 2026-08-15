<?php

namespace HomeLan\FileStore\Messages;

/**
 * Decodes an inbound MaceMail packet (see docs/protocols/macemail.md).
 *
 * Unlike FsRequest/TorchnetRequest, MaceMail has no embedded command byte —
 * the Econet port number the packet arrived on fully identifies the
 * operation, so getPort() is the dispatch key and $this->sData is the whole
 * payload with no header to skip.
 *
 * The reply port defaults to the request's own port but many MaceMail
 * exchanges reply on a different port to the one the request arrived on
 * (e.g. a quick command on 0x19 is acknowledged on 0x1A) — call
 * setReplyPort() before buildReply() when that's the case.
 */
class MaceMailRequest extends Request
{
	private int $iPort;

	private int $iReplyPort;

	public function __construct(EconetPacket $oEconetPacket, \Psr\Log\LoggerInterface $oLogger)
	{
		parent::__construct($oEconetPacket, $oLogger);
		$this->iPort = $oEconetPacket->getPort();
		$this->iReplyPort = $this->iPort;
		$this->sData = (string) $oEconetPacket->getData();
	}

	public function getPort(): int
	{
		return $this->iPort;
	}

	/**
	 * MaceMail rejects any packet without a resolvable source network/station
	 * before ever constructing a MaceMailRequest (see MaceMail::unicastPacketIn()/
	 * broadcastPacketIn()), so both are guaranteed present here.
	 */
	public function getSourceNetwork(): int
	{
		if ($this->iSourceNetwork === null) {
			throw new \Exception("MaceMailRequest has no source network");
		}
		return $this->iSourceNetwork;
	}

	public function getSourceStation(): int
	{
		if ($this->iSourceStation === null) {
			throw new \Exception("MaceMailRequest has no source station");
		}
		return $this->iSourceStation;
	}

	public function setReplyPort(int $iPort): void
	{
		$this->iReplyPort = $iPort;
	}

	public function getReplyPort(): int
	{
		return $this->iReplyPort;
	}

	/**
	 * Reads a fixed-length, space-padded string from the payload.
	 *
	 * @param int $iStart 1-based byte offset
	 */
	public function getFixedString(int $iStart, int $iLength): string
	{
		return rtrim(substr((string) $this->sData, $iStart - 1, $iLength));
	}

	/**
	 * Reads a fixed-length byte range verbatim, with no trimming — for
	 * payload regions that are opaque binary data rather than text (e.g. a
	 * store-slot's contents), where stripping trailing whitespace/NUL bytes
	 * would corrupt the data.
	 *
	 * @param int $iStart 1-based byte offset
	 */
	public function getRawBytes(int $iStart, int $iLength): string
	{
		return str_pad(substr((string) $this->sData, $iStart - 1, $iLength), $iLength, "\0");
	}

	public function buildReply(): MaceMailReply
	{
		return new MaceMailReply($this);
	}
}
