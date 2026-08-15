<?php

/**
 * File containing the HasUsername interface
 *
 * @package coreauth
*/
namespace HomeLan\FileStore\Authentication;

/**
 * Implemented by User and by test doubles that stand in for it wherever
 * only the username is needed (e.g. Printer::isUserAllowed()).
 *
 * @package coreauth
*/
interface HasUsername {

	public function getUsername(): ?string;
}
