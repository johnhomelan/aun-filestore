<?php

namespace HomeLan\FileStore\Services\Provider\SqlServer;

use config;

/**
 * Opens and caches PDO connections **one per (network, station, database
 * name)** - every authenticated station gets its own connection to each
 * database it queries, never a connection shared across clients. This
 * isn't just isolation hygiene: PgsqlCursor's explicit `DECLARE ... CURSOR`
 * is scoped to one transaction on one connection, so two stations sharing
 * a connection would corrupt each other's in-flight cursors the moment
 * both had a query open at once (see the sql-server plan).
 *
 * A connection's lifecycle is tied to the owning station's auth session,
 * not to any single query - kept open across multiple queries in the same
 * session, and closed via closeConnection()/closeAllForStation() on
 * LOGOUT or once SqlServer's housekeeping notices Security no longer has
 * a session for that station.
 */
class ConnectionFactory
{
	/** @var array<string, \PDO> keyed by "{network}.{station}.{databaseName}" */
	protected array $aConnections = [];

	/** @var array<string, int> open connection count per database name, across all stations */
	protected array $aConnectionCountsByDatabase = [];

	public function getConnection(int $iNetwork, int $iStation, DatabaseDefinition $oDb): \PDO
	{
		$sKey = $this->_key($iNetwork, $iStation, $oDb->sName);
		if (isset($this->aConnections[$sKey])) {
			return $this->aConnections[$sKey];
		}

		$iMax = config::getValueAsInt('sql_max_connections_per_database');
		if ($iMax > 0 && ($this->aConnectionCountsByDatabase[$oDb->sName] ?? 0) >= $iMax) {
			throw new \RuntimeException('Too many open connections to database "' . $oDb->sName . '" (max ' . $iMax . ')');
		}

		$oPdo = $this->_connect($oDb);
		$this->aConnections[$sKey] = $oPdo;
		$this->aConnectionCountsByDatabase[$oDb->sName] = ($this->aConnectionCountsByDatabase[$oDb->sName] ?? 0) + 1;
		return $oPdo;
	}

	public function closeConnection(int $iNetwork, int $iStation, string $sDatabaseName): void
	{
		$sKey = $this->_key($iNetwork, $iStation, $sDatabaseName);
		if (!isset($this->aConnections[$sKey])) {
			return;
		}
		unset($this->aConnections[$sKey]);
		if (($this->aConnectionCountsByDatabase[$sDatabaseName] ?? 0) > 0) {
			$this->aConnectionCountsByDatabase[$sDatabaseName]--;
		}
	}

	/**
	 * Closes every connection open for a station, across all databases -
	 * used on LOGOUT and by the stale-connection housekeeping sweep.
	 */
	public function closeAllForStation(int $iNetwork, int $iStation): void
	{
		$sPrefix = $iNetwork . '.' . $iStation . '.';
		foreach (array_keys($this->aConnections) as $sKey) {
			if (str_starts_with($sKey, $sPrefix)) {
				$this->closeConnection($iNetwork, $iStation, substr($sKey, strlen($sPrefix)));
			}
		}
	}

	/**
	 * Every (network, station) pair currently holding at least one open
	 * connection - the housekeeping sweep uses this to find stations
	 * Security no longer has a session for.
	 *
	 * @return array<int, array{network: int, station: int}>
	 */
	public function activeStations(): array
	{
		$aSeen = [];
		$aReturn = [];
		foreach (array_keys($this->aConnections) as $sKey) {
			[$sNetwork, $sStation] = explode('.', $sKey, 3);
			$sStationKey = $sNetwork . '.' . $sStation;
			if (isset($aSeen[$sStationKey])) {
				continue;
			}
			$aSeen[$sStationKey] = true;
			$aReturn[] = ['network' => (int) $sNetwork, 'station' => (int) $sStation];
		}
		return $aReturn;
	}

	protected function _key(int $iNetwork, int $iStation, string $sDatabaseName): string
	{
		return $iNetwork . '.' . $iStation . '.' . $sDatabaseName;
	}

	protected function _connect(DatabaseDefinition $oDb): \PDO
	{
		$aOptions = [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION];
		if ($oDb->sEngine === 'mysql') {
			// Stream rows from the server instead of PDO buffering the whole
			// result set in this process's own memory - verified directly
			// against a real MariaDB instance (see the sql-server plan).
			$aOptions[\PDO::MYSQL_ATTR_USE_BUFFERED_QUERY] = false;
		}
		return new \PDO(
			$oDb->sDsn,
			$oDb->sUser !== '' ? $oDb->sUser : null,
			$oDb->sPassword !== '' ? $oDb->sPassword : null,
			$aOptions
		);
	}
}
