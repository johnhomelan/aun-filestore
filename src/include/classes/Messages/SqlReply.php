<?php

namespace HomeLan\FileStore\Messages;

class SqlReply extends Reply
{
	/**
	 * Appends the leading status byte every reply starts with (0 for
	 * success, one of SqlServer::ERROR_* otherwise).
	 */
	public function appendStatus(int $iStatus): void
	{
		$this->appendByte($iStatus);
	}

	/**
	 * Appends a CR-terminated string, as used for error messages.
	 */
	public function appendCrString(string $sString): void
	{
		$this->appendString($sString);
		$this->appendByte(0x0D);
	}

	/**
	 * Appends a single length-prefixed byte string (a byte count 0-255,
	 * then that many raw bytes) - used for database/column names.
	 */
	public function appendLengthPrefixed(string $sBytes): void
	{
		$sBytes = substr($sBytes, 0, 255);
		$this->appendByte(strlen($sBytes));
		$this->appendRaw($sBytes);
	}
}
