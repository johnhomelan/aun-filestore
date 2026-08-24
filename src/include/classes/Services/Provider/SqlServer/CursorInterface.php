<?php

namespace HomeLan\FileStore\Services\Provider\SqlServer;

/**
 * A paged view over one executed SELECT's result set, hiding the
 * per-engine mechanics needed to fetch it incrementally rather than
 * buffering the whole thing in this process's own memory (see
 * docs/protocols/sql-server.md and the sql-server plan):
 * BufferedCursor for SQLite (already incremental) and MySQL (with
 * PDO::MYSQL_ATTR_USE_BUFFERED_QUERY disabled), PgsqlCursor for
 * PostgreSQL (which needs an explicit SQL cursor for true server-side
 * paging).
 */
interface CursorInterface
{
	/**
	 * Column names, in select-list order. Available immediately after the
	 * statement executes - verified directly against SQLite, MySQL, and
	 * PostgreSQL that `PDOStatement::getColumnMeta()['name']` is reliable
	 * even for a zero-row result, unlike column *type* metadata (see
	 * ValueCodec's docblock for why types are never relied on).
	 *
	 * @return array<int, string>
	 */
	public function getColumnNames(): array;

	/**
	 * Fetches up to $iMaxRows more rows. Returns fewer than $iMaxRows (down
	 * to an empty array) once the result set is exhausted.
	 *
	 * @return array<int, array<string, int|float|string|null>>
	 */
	public function fetchNext(int $iMaxRows): array;

	/** True once a fetchNext() call has returned fewer rows than it asked for. */
	public function isExhausted(): bool;

	/** Releases whatever server-side resource (cursor, statement) this holds. */
	public function close(): void;
}
