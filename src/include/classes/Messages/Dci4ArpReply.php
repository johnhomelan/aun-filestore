<?php
/**
 * This file contains the Dci4ArpReply class
 *
 * @author John Brown <john@home-lan.co.uk>
 * @package coreprotocol
*/
namespace HomeLan\FileStore\Messages;

/**
 * Represents a DCI-4 ARP is-at reply (flags=0xA2)
 *
 * Used to respond to DCI-4 ARP who-has requests from later RiscOS machines.
 * The wire payload is identical to the DCI-2 reply (requested IP + source IP,
 * 8 bytes) but the Econet flags byte is 0xA2 instead of 0x22.
 *
 * @package coreprotocol
*/
class Dci4ArpReply extends ArpReply {

	protected function getArpReplyFlags(): int
	{
		return 0xA2; // DCI-4 native Econet is-at
	}
}
