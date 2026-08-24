<?php

namespace HomeLan\FileStore\Services\Provider\SqlServer;

/**
 * One configured database (see DatabaseRegistry) - name, PDO connection
 * details, and which Econet usernames may query it.
 */
final class DatabaseDefinition
{
	/**
	 * @param array<int, string> $aAllowedUsers Upper-cased usernames; empty means any authenticated user.
	 */
	public function __construct(
		public readonly string $sName,
		public readonly string $sEngine,
		public readonly string $sDsn,
		public readonly string $sUser,
		public readonly string $sPassword,
		public readonly array $aAllowedUsers,
	) {
	}

	public function isUserAllowed(string $sUsername): bool
	{
		if ($this->aAllowedUsers === []) {
			return true;
		}
		return in_array(strtoupper($sUsername), $this->aAllowedUsers, true);
	}
}
