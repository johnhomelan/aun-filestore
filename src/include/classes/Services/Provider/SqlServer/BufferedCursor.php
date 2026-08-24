<?php

namespace HomeLan\FileStore\Services\Provider\SqlServer;

/**
 * A CursorInterface directly over an already-executed \PDOStatement -
 * "buffered" refers to using PDO's normal per-call fetch() interface
 * (as opposed to PgsqlCursor's explicit SQL-level DECLARE/FETCH cursor),
 * not to buffering the whole result set: SQLite's own C API is already
 * step-based/incremental, and for MySQL the caller is expected to have
 * opened the statement with `PDO::MYSQL_ATTR_USE_BUFFERED_QUERY => false`
 * so the driver streams rows from the server instead of PDO loading the
 * entire result set into this process's memory up front - see
 * ConnectionFactory.
 */
class BufferedCursor implements CursorInterface
{
	protected bool $bExhausted = false;

	public function __construct(protected readonly \PDOStatement $oStatement)
	{
	}

	public function getColumnNames(): array
	{
		return $this->_columnNames($this->oStatement);
	}

	public function fetchNext(int $iMaxRows): array
	{
		if ($this->bExhausted) {
			return [];
		}
		$aRows = [];
		for ($i = 0; $i < $iMaxRows; $i++) {
			$mRow = $this->oStatement->fetch(\PDO::FETCH_ASSOC);
			if ($mRow === false) {
				$this->bExhausted = true;
				break;
			}
			/** @var array<string, int|float|string|null> $mRow */
			$aRows[] = $mRow;
		}
		return $aRows;
	}

	public function isExhausted(): bool
	{
		return $this->bExhausted;
	}

	public function close(): void
	{
		$this->oStatement->closeCursor();
	}

	/**
	 * @return array<int, string>
	 */
	protected function _columnNames(\PDOStatement $oStatement): array
	{
		$aNames = [];
		for ($i = 0, $iCount = $oStatement->columnCount(); $i < $iCount; $i++) {
			$aMeta = $oStatement->getColumnMeta($i);
			$aNames[] = is_array($aMeta) ? $aMeta['name'] : ('col' . $i);
		}
		return $aNames;
	}
}
