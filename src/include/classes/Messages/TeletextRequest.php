<?php

namespace HomeLan\FileStore\Messages;

/**
 * Decodes an inbound Teletext server packet (see docs/protocols/teletext.md).
 *
 * Like MaceMailRequest, there is no embedded command byte distinct from the
 * payload — every operation's request/reply is prefixed with a "control
 * byte" that is simply the first byte of the Econet packet's data payload
 * (not the underlying Econet frame's own control-byte field), so getPort()
 * is the transport-level dispatch key and getControlByte() is the
 * protocol-level one.
 *
 * Fields after the control byte are addressed relative to it (the source
 * document's own "Data+0", "Data+1" notation) via getDataByte()/
 * getDataString()/getRawDataBytes() rather than the base class's 1-indexed
 * getByte(), to avoid every call site having to remember the +1 offset.
 */
class TeletextRequest extends Request
{
	private int $iPort;

	private int $iReplyPort;

	public function __construct($oEconetPacket, \Psr\Log\LoggerInterface $oLogger)
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

	public function setReplyPort(int $iPort): void
	{
		$this->iReplyPort = $iPort;
	}

	public function getReplyPort(): int
	{
		return $this->iReplyPort;
	}

	/**
	 * The first byte of the payload — an operation code for a client
	 * request, or (on some servers) an echo of the request type; this
	 * server never needs to read it back off its own replies.
	 */
	public function getControlByte(): int
	{
		return (int) $this->getByte(1);
	}

	/**
	 * Reads a single byte at the given "Data+N" offset (0-based, relative
	 * to the byte immediately after the control byte).
	 */
	public function getDataByte(int $iOffset): int
	{
		return (int) $this->getByte($iOffset + 2);
	}

	/**
	 * Reads a fixed-length, space-trimmed string at the given "Data+N"
	 * offset.
	 */
	public function getDataString(int $iOffset, int $iLength): string
	{
		return rtrim(substr((string) $this->sData, $iOffset + 1, $iLength));
	}

	/**
	 * Reads a fixed-length byte range verbatim, with no trimming — for
	 * payload regions that are opaque binary data rather than text.
	 */
	public function getRawDataBytes(int $iOffset, int $iLength): string
	{
		return str_pad(substr((string) $this->sData, $iOffset + 1, $iLength), $iLength, "\0");
	}

	/**
	 * The length of the payload after the control byte — used to tell
	 * whether an optional field (e.g. the page request's subpage) was
	 * actually sent.
	 */
	public function getDataLength(): int
	{
		return max(0, strlen((string) $this->sData) - 1);
	}

	public function buildReply(): TeletextReply
	{
		return new TeletextReply($this);
	}
}
