<?php

namespace HomeLan\FileStore\Messages;

class TeletextReply extends Reply
{
	/**
	 * Appends the leading status/error byte every reply starts with (0 for
	 * success).
	 */
	public function appendStatus(int $iStatus): void
	{
		$this->appendByte($iStatus);
	}

	/**
	 * Appends a CR-terminated string, as used for the version string and
	 * error messages.
	 */
	public function appendCrString(string $sString): void
	{
		$this->appendString($sString);
		$this->appendByte(0x0D);
	}

	/**
	 * Appends a fixed-length, space-padded (or custom-padded) string.
	 */
	public function appendFixedString(string $sString, int $iLength, string $sPad = ' '): void
	{
		$this->appendString(str_pad(substr($sString, 0, $iLength), $iLength, $sPad));
	}
}
