<?php

namespace HomeLan\FileStore\Messages;

/**
 * Decodes an inbound SqlServer request packet (see docs/protocols/sql-server.md).
 *
 * Like TeletextRequest/MaceMailRequest, there is no embedded command byte
 * distinct from the payload - every operation's request/reply is prefixed
 * with a "control byte" that is simply the first byte of the Econet
 * packet's data payload, so getPort() is the transport-level dispatch key
 * (always sql_server_port - SqlServer registers only one port) and
 * getControlByte() is the protocol-level one.
 *
 * Unlike TeletextRequest, fields after the control byte are not at fixed
 * offsets - LOGIN (username/password) and QUERY (database name/stream
 * port/parameters/SQL text) are all variable-length, so there's no fixed
 * "Data+N" convention that fits every op the way it does for Teletext's
 * mostly fixed-width fields. getPayload() hands back the raw bytes after
 * the control byte; SqlServer parses each op's shape itself.
 */
class SqlRequest extends Request
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

	public function setReplyPort(int $iPort): void
	{
		$this->iReplyPort = $iPort;
	}

	public function getReplyPort(): int
	{
		return $this->iReplyPort;
	}

	/** The first byte of the payload - the operation code for this request. */
	public function getControlByte(): int
	{
		return (int) $this->getByte(1);
	}

	/** Every byte after the control byte - shape depends on the operation, see SqlServer. */
	public function getPayload(): string
	{
		return substr((string) $this->sData, 1);
	}

	public function buildReply(): SqlReply
	{
		return new SqlReply($this);
	}
}
