<?php
/**
 * This file contains the RequestInterface interface
 *
 * @author John Brown <john@home-lan.co.uk>
 * @package coreprotocol
*/
namespace HomeLan\FileStore\Messages;

/**
 * Implemented by every request-ish class Reply::__construct() accepts
 * (the Request hierarchy, plus PrintServerData which sits outside it).
 *
 * @package coreprotocol
*/
interface RequestInterface {

	public function getFlags(): int;

	public function getReplyPort(): ?int;

	public function getSourceStation(): ?int;

	public function getSourceNetwork(): ?int;
}
