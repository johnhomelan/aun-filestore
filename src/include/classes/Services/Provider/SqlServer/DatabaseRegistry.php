<?php

namespace HomeLan\FileStore\Services\Provider\SqlServer;

use config;

/**
 * Reads the sql_databases config key (comma-separated names) plus each
 * name's sql_database_{name}_engine/_dsn/_user/_password/_allowed_users
 * keys into DatabaseDefinition instances - the single source of truth for
 * "what databases exist and who may query them" (see
 * docs/protocols/sql-server.md), the same role NewsFeedDefinitions plays
 * for the news-import feature.
 */
class DatabaseRegistry
{
	/** @var array<int, string> */
	public const array ENGINES = ['pgsql', 'mysql', 'sqlite'];

	/**
	 * @return array<string, DatabaseDefinition>
	 */
	public function all(): array
	{
		$aReturn = [];
		foreach ($this->_names() as $sName) {
			$aReturn[$sName] = $this->_build($sName);
		}
		return $aReturn;
	}

	public function get(string $sName): ?DatabaseDefinition
	{
		if (!in_array($sName, $this->_names(), true)) {
			return null;
		}
		return $this->_build($sName);
	}

	/**
	 * @return array<int, string>
	 */
	protected function _names(): array
	{
		$aNames = array_map('trim', explode(',', config::getValueAsString('sql_databases')));
		return array_values(array_filter($aNames, fn(string $s): bool => $s !== ''));
	}

	protected function _build(string $sName): DatabaseDefinition
	{
		$sEngine = config::getValueAsString('sql_database_' . $sName . '_engine');
		if (!in_array($sEngine, self::ENGINES, true)) {
			throw new \RuntimeException('Database "' . $sName . '": unknown or missing engine "' . $sEngine . '" (must be one of ' . implode(', ', self::ENGINES) . ')');
		}
		$this->_requireExtension($sName, $sEngine);

		$sDsn = config::getValueAsString('sql_database_' . $sName . '_dsn');
		if ($sDsn === '') {
			throw new \RuntimeException('Database "' . $sName . '": no DSN configured (sql_database_' . $sName . '_dsn)');
		}

		$sUser = config::getValueAsString('sql_database_' . $sName . '_user');
		$sPassword = config::getValueAsString('sql_database_' . $sName . '_password');

		$aAllowed = array_map(
			fn(string $s): string => strtoupper(trim($s)),
			explode(',', config::getValueAsString('sql_database_' . $sName . '_allowed_users'))
		);
		$aAllowed = array_values(array_filter($aAllowed, fn(string $s): bool => $s !== ''));

		return new DatabaseDefinition($sName, $sEngine, $sDsn, $sUser, $sPassword, $aAllowed);
	}

	protected function _requireExtension(string $sName, string $sEngine): void
	{
		$sExtension = match ($sEngine) {
			'pgsql'  => 'pdo_pgsql',
			'mysql'  => 'pdo_mysql',
			'sqlite' => 'pdo_sqlite',
			default  => throw new \RuntimeException('Database "' . $sName . '": unknown engine "' . $sEngine . '"'),
		};
		if (!$this->_isExtensionLoaded($sExtension)) {
			throw new \RuntimeException('Database "' . $sName . '" needs the "' . $sExtension . '" PHP extension, which is not loaded');
		}
	}

	/**
	 * Overridden in tests to simulate a missing extension without needing
	 * to actually uninstall one.
	 */
	protected function _isExtensionLoaded(string $sExtension): bool
	{
		return extension_loaded($sExtension);
	}
}
