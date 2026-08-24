<?php

namespace HomeLan\FileStore\Services\Provider\SqlServer;

/**
 * A decoded QUERY request payload - see RequestPayloadParser.
 */
final class QueryPayload
{
	/**
	 * @param array<int, array{value: int|float|string|null, pdoType: int}> $aParameters positional, in `?` order
	 */
	public function __construct(
		public readonly string $sDatabaseName,
		public readonly int $iStreamPort,
		public readonly array $aParameters,
		public readonly string $sSql,
	) {
	}
}
