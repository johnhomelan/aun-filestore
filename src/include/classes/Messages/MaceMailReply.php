<?php

namespace HomeLan\FileStore\Messages;

/**
 * Builds an outbound MaceMail reply packet, addressed back to the request's
 * source station on the port set via MaceMailRequest::setReplyPort()
 * (defaults to the request's own port).
 */
class MaceMailReply extends Reply
{
	/**
	 * Appends a fixed-length, space-padded string.
	 */
	public function appendFixedString(string $sString, int $iLength): void
	{
		$this->appendString(str_pad(substr($sString, 0, $iLength), $iLength, ' '));
	}
}
