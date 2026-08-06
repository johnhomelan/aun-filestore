<?php
/**
 * This file contains the Dci4ArpRequest class
 *
 * @author John Brown <john@home-lan.co.uk>
 * @package coreprotocol
*/
namespace HomeLan\FileStore\Messages;

/**
 * Represents a DCI-4 ARP who-has request (flags=0xA1)
 *
 * DCI-4 is used by later versions of RiscOS. The wire payload is identical
 * to DCI-2 (sender IP + target IP, 8 bytes) but the Econet flags byte is
 * 0xA1 for the request and 0xA2 for the reply, rather than 0x21/0x22.
 *
 * @package coreprotocol
*/
class Dci4ArpRequest extends ArpRequest {

	/**
	 * Decodes a DCI-4 ARP who-has request (flags=0xA1)
	 *
	 * Payload layout matches DCI-2: sender IP (4 bytes) + target IP (4 bytes).
	*/
	public function decode(string $sBinaryString): void
	{
		if ($this->getFlags() !== 0xA1) {
			return;
		}
		$this->sSourceIP = inet_ntop($sBinaryString[0].$sBinaryString[1].$sBinaryString[2].$sBinaryString[3]);
		$this->sIPv4Addr = inet_ntop($sBinaryString[4].$sBinaryString[5].$sBinaryString[6].$sBinaryString[7]);
	}

	/**
	 * Builds a DCI-4 ARP reply (is-at) in response to this request
	*/
	public function buildReply(): \HomeLan\FileStore\Messages\ArpReply
	{
		return new Dci4ArpReply($this);
	}
}
