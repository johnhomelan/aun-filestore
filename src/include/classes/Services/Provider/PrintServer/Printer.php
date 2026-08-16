<?php
/**
 * This file contains the Printer value object
 *
 * @author John Brown <john@home-lan.co.uk>
 * @package core
*/
namespace HomeLan\FileStore\Services\Provider\PrintServer;

use HomeLan\FileStore\Authentication\HasUsername;

/**
 * Represents one virtual printer configured in printers.cfg
 *
 * @package core
*/
class Printer
{
	/** @param array<int,string> $aAllowedUsers */
	public function __construct(
		private readonly string $sName,
		private readonly string $sDescription,
		private readonly bool   $bEnabled,
		private readonly string $sBehavior,
		private readonly string $sScript,
		private readonly array  $aAllowedUsers
	) {}

	public function getName(): string        { return $this->sName; }
	public function getDescription(): string { return $this->sDescription; }
	public function isEnabled(): bool        { return $this->bEnabled; }
	public function getBehavior(): string    { return $this->sBehavior; }
	public function getScript(): string      { return $this->sScript; }

	/**
	 * Returns true if the given user is permitted to use this printer.
	 * An empty allowed_users list means all users are permitted.
	 * A null user (unauthenticated station) is only permitted when the list is empty.
	 */
	/** Returns the allowed-users list as a display string, or 'All' when unrestricted. */
	public function getAllowedUsersDisplay(): string
	{
		return empty($this->aAllowedUsers) ? 'All' : implode(', ', $this->aAllowedUsers);
	}

	public function isUserAllowed(?HasUsername $oUser): bool
	{
		if (empty($this->aAllowedUsers)) {
			return true;
		}
		if ($oUser === null) {
			return false;
		}
		return in_array(
			strtoupper($oUser->getUsername() ?? ''),
			array_map(strtoupper(...), $this->aAllowedUsers),
			true
		);
	}
}
