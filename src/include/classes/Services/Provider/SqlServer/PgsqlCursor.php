<?php

namespace HomeLan\FileStore\Services\Provider\SqlServer;

/**
 * A CursorInterface for PostgreSQL, using an explicit SQL-level cursor for
 * true server-side paging - PDO's pgsql driver has no equivalent of
 * MySQL's `PDO::MYSQL_ATTR_USE_BUFFERED_QUERY` flag (see
 * ConnectionFactory/BufferedCursor), so a plain unbuffered fetch() loop
 * would still have libpq materialise the whole result set. `DECLARE ...
 * CURSOR` + `FETCH n` is the standard way to page a Postgres result set
 * without that - verified directly against a real Postgres instance while
 * building this (see the sql-server plan).
 *
 * The cursor is scoped to one transaction, opened lazily on first use and
 * committed (closing the cursor) when close() is called.
 */
class PgsqlCursor implements CursorInterface
{
	protected bool $bStarted = false;
	protected bool $bExhausted = false;
	protected readonly string $sCursorName;

	/**
	 * @param array<int, array{value: int|float|string|null, pdoType: int}> $aBoundParams positional, in `?` order
	 */
	public function __construct(
		protected readonly \PDO $oConnection,
		protected readonly string $sSql,
		protected readonly array $aBoundParams
	) {
		$this->sCursorName = 'sqlserver_cursor_' . bin2hex(random_bytes(8));
	}

	public function getColumnNames(): array
	{
		$this->_ensureStarted();
		// FETCH 0 returns no rows but the statement still carries real
		// column metadata for them - verified directly against Postgres.
		$oStatement = $this->_query('FETCH 0 FROM ' . $this->sCursorName);
		$aNames = [];
		for ($i = 0, $iCount = $oStatement->columnCount(); $i < $iCount; $i++) {
			$aMeta = $oStatement->getColumnMeta($i);
			$aNames[] = is_array($aMeta) ? $aMeta['name'] : ('col' . $i);
		}
		return $aNames;
	}

	public function fetchNext(int $iMaxRows): array
	{
		$this->_ensureStarted();
		if ($this->bExhausted) {
			return [];
		}
		$aRows = $this->_query('FETCH ' . $iMaxRows . ' FROM ' . $this->sCursorName)->fetchAll(\PDO::FETCH_ASSOC);
		if (count($aRows) < $iMaxRows) {
			$this->bExhausted = true;
		}
		/** @var array<int, array<string, int|float|string|null>> $aRows */
		return $aRows;
	}

	public function isExhausted(): bool
	{
		return $this->bExhausted;
	}

	public function close(): void
	{
		if (!$this->bStarted) {
			return;
		}
		try {
			$this->oConnection->exec('CLOSE ' . $this->sCursorName);
		} catch (\Throwable) {
			// Best-effort - the transaction commit/rollback below is what actually matters.
		}
		if ($this->oConnection->inTransaction()) {
			$this->oConnection->commit();
		}
	}

	protected function _ensureStarted(): void
	{
		if ($this->bStarted) {
			return;
		}
		$this->oConnection->beginTransaction();
		$oDeclare = $this->oConnection->prepare('DECLARE ' . $this->sCursorName . ' CURSOR FOR ' . $this->sSql);
		foreach ($this->aBoundParams as $i => $aParam) {
			$oDeclare->bindValue($i + 1, $aParam['value'], $aParam['pdoType']);
		}
		$oDeclare->execute();
		$this->bStarted = true;
	}

	protected function _query(string $sSql): \PDOStatement
	{
		$oStatement = $this->oConnection->query($sSql);
		if ($oStatement === false) {
			throw new \RuntimeException('PgsqlCursor: query failed: ' . $sSql);
		}
		return $oStatement;
	}
}
