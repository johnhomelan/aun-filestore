<?php

namespace HomeLan\FileStore\Services\Provider\SqlServer;

/**
 * Encodes/decodes the type-tagged wire value format used both for a QUERY
 * request's bound parameters and for streamed result cells (see
 * docs/protocols/sql-server.md). Pure logic - no PDO/network access -
 * unit-testable directly.
 *
 * Wire shape of one entry: `[1] type tag` then, depending on the tag:
 *   NULL    - nothing further
 *   INTEGER - `[8]` signed 64-bit little-endian
 *   FLOAT   - `[8]` IEEE754 double, little-endian
 *   TEXT    - `[2] length (LE)` then that many bytes
 *   BLOB    - `[2] length (LE)` then that many bytes
 *
 * Parameters always arrive pre-tagged by the client (it knows what it
 * means to send), so decodeParameter() trusts the wire tag outright.
 * Result cells go the other way: PDO gives back plain PHP null/int/float/
 * string values with no reliable cross-driver "is this text or binary"
 * signal (PDOStatement::getColumnMeta() is documented as
 * driver-inconsistent - see the sql-server plan), so encodeCell() always
 * emits TEXT for a PHP string. BLOB exists on the wire purely so a client
 * can bind a parameter it wants treated as binary (PDO::PARAM_LOB) - it is
 * never produced for a result cell.
 */
class ValueCodec
{
	public const int TAG_NULL    = 0x00;
	public const int TAG_INTEGER = 0x01;
	public const int TAG_FLOAT   = 0x02;
	public const int TAG_TEXT    = 0x03;
	public const int TAG_BLOB    = 0x04;

	/**
	 * Encodes one result cell value, auto-selecting the tag from the PHP
	 * value's own type.
	 */
	public function encodeCell(int|float|string|null $mValue): string
	{
		if ($mValue === null) {
			return chr(self::TAG_NULL);
		}
		if (is_int($mValue)) {
			return chr(self::TAG_INTEGER) . pack('P', $mValue);
		}
		if (is_float($mValue)) {
			return chr(self::TAG_FLOAT) . pack('e', $mValue);
		}
		return chr(self::TAG_TEXT) . $this->_packLength(strlen($mValue)) . $mValue;
	}

	/**
	 * Encodes a value explicitly as the given tag - used when sending a
	 * bound parameter back out is never needed by this server, but is
	 * useful for tests exercising every tag (including BLOB, which
	 * encodeCell() never produces) directly.
	 */
	public function encodeTagged(int $iTag, int|float|string|null $mValue): string
	{
		return match ($iTag) {
			self::TAG_NULL    => chr(self::TAG_NULL),
			self::TAG_INTEGER => chr(self::TAG_INTEGER) . pack('P', (int) $mValue),
			self::TAG_FLOAT   => chr(self::TAG_FLOAT) . pack('e', (float) $mValue),
			self::TAG_TEXT, self::TAG_BLOB => chr($iTag) . $this->_packLength(strlen((string) $mValue)) . (string) $mValue,
			default => throw new \InvalidArgumentException('Unknown value tag ' . $iTag),
		};
	}

	/**
	 * Decodes one client-supplied parameter entry starting at byte offset
	 * $iOffset within $sBytes.
	 *
	 * @return array{value: int|float|string|null, pdoType: int, length: int}
	 *         `length` is the total number of bytes this entry occupied
	 *         (tag + any length prefix + value), so a caller decoding P
	 *         parameters in sequence knows where the next one starts.
	 */
	public function decodeParameter(string $sBytes, int $iOffset): array
	{
		if ($iOffset >= strlen($sBytes)) {
			throw new \RuntimeException('Truncated parameter: no tag byte at offset ' . $iOffset);
		}
		$iTag = ord($sBytes[$iOffset]);

		return match ($iTag) {
			self::TAG_NULL => ['value' => null, 'pdoType' => \PDO::PARAM_NULL, 'length' => 1],
			self::TAG_INTEGER => [
				'value'   => $this->_unpackInt($this->_require($sBytes, $iOffset + 1, 8, 'INTEGER')),
				'pdoType' => \PDO::PARAM_INT,
				'length'  => 9,
			],
			self::TAG_FLOAT => [
				'value'   => $this->_unpackFloat($this->_require($sBytes, $iOffset + 1, 8, 'FLOAT')),
				// PDO has no PARAM_FLOAT/DOUBLE - bound as a string, every driver parses a
				// well-formed numeric literal correctly in a parameterised query.
				'pdoType' => \PDO::PARAM_STR,
				'length'  => 9,
			],
			self::TAG_TEXT, self::TAG_BLOB => $this->_decodeLengthPrefixed($sBytes, $iOffset, $iTag),
			default => throw new \RuntimeException('Unknown parameter type tag ' . $iTag . ' at offset ' . $iOffset),
		};
	}

	/**
	 * @return array{value: string, pdoType: int, length: int}
	 */
	protected function _decodeLengthPrefixed(string $sBytes, int $iOffset, int $iTag): array
	{
		$sLenBytes = $this->_require($sBytes, $iOffset + 1, 2, 'length prefix');
		$iLength = $this->_unpackUint16($sLenBytes);
		$sValue = $this->_require($sBytes, $iOffset + 3, $iLength, 'value');

		return [
			'value'   => $sValue,
			'pdoType' => $iTag === self::TAG_BLOB ? \PDO::PARAM_LOB : \PDO::PARAM_STR,
			'length'  => 3 + $iLength,
		];
	}

	protected function _require(string $sBytes, int $iOffset, int $iLength, string $sWhat): string
	{
		$sSlice = substr($sBytes, $iOffset, $iLength);
		if (strlen($sSlice) !== $iLength) {
			throw new \RuntimeException('Truncated ' . $sWhat . ' at offset ' . $iOffset);
		}
		return $sSlice;
	}

	protected function _packLength(int $iLength): string
	{
		if ($iLength > 0xFFFF) {
			throw new \InvalidArgumentException('Value too long to encode (' . $iLength . ' bytes, max 65535)');
		}
		return pack('v', $iLength);
	}

	/**
	 * Relies on PHP's native int being a 64-bit two's-complement value
	 * internally, so packing/unpacking with the same (nominally unsigned,
	 * but bit-exact) 'P' format round-trips negative numbers correctly -
	 * verified directly for this platform rather than assumed.
	 */
	protected function _unpackInt(string $sBytes): int
	{
		$aUnpacked = unpack('P', $sBytes);
		if ($aUnpacked === false) {
			throw new \RuntimeException('unpack(P) failed');
		}
		return self::_asInt($aUnpacked[1]);
	}

	protected function _unpackFloat(string $sBytes): float
	{
		$aUnpacked = unpack('e', $sBytes);
		if ($aUnpacked === false) {
			throw new \RuntimeException('unpack(e) failed');
		}
		return self::_asFloat($aUnpacked[1]);
	}

	/**
	 * unpack() is typed to return false on a malformed format string - never
	 * actually possible here since the format is always our own literal
	 * 'v', but PHPStan can't know that, so this is where false maps to a
	 * real exception instead.
	 */
	protected function _unpackUint16(string $sBytes): int
	{
		$aUnpacked = unpack('v', $sBytes);
		if ($aUnpacked === false) {
			throw new \RuntimeException('unpack(v) failed');
		}
		return self::_asInt($aUnpacked[1]);
	}

	/**
	 * unpack()'s array is typed with mixed values regardless of format -
	 * narrows it back to what the format code actually guarantees, the same
	 * pattern Messages\Request::_asInt() already uses for the same reason.
	 */
	protected static function _asInt(mixed $mValue): int
	{
		return is_int($mValue) ? $mValue : 0;
	}

	protected static function _asFloat(mixed $mValue): float
	{
		return is_float($mValue) ? $mValue : 0.0;
	}
}
