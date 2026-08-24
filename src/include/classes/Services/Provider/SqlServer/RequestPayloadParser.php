<?php

namespace HomeLan\FileStore\Services\Provider\SqlServer;

/**
 * Parses the variable-length payload shapes SqlRequest::getPayload() hands
 * back for LOGIN and QUERY (see docs/protocols/sql-server.md) - pure logic,
 * no network/DB access, unit-testable directly against literal byte
 * strings.
 *
 * Wire shapes (control byte already stripped by SqlRequest::getPayload()):
 *
 *   LOGIN:  [1] username length (U) [U] username [1] password length (P) [P] password
 *   QUERY:  [1] database name length (N) [N] database name
 *           [2] stream port (LE)
 *           [1] parameter count (C)
 *           C x { see ValueCodec::decodeParameter() }
 *           remaining bytes: SQL text
 */
class RequestPayloadParser
{
	public function __construct(protected readonly ValueCodec $oValueCodec)
	{
	}

	/**
	 * @return array{username: string, password: string}
	 */
	public function parseLogin(string $sPayload): array
	{
		$iOffset = 0;
		$sUsername = $this->_readLengthPrefixed($sPayload, $iOffset);
		$sPassword = $this->_readLengthPrefixed($sPayload, $iOffset);
		return ['username' => $sUsername, 'password' => $sPassword];
	}

	public function parseQuery(string $sPayload): QueryPayload
	{
		$iOffset = 0;
		$sDatabaseName = $this->_readLengthPrefixed($sPayload, $iOffset);
		$iStreamPort = $this->_readUint16($sPayload, $iOffset);
		$iParamCount = $this->_readByte($sPayload, $iOffset);

		$aParameters = [];
		for ($i = 0; $i < $iParamCount; $i++) {
			$aDecoded = $this->oValueCodec->decodeParameter($sPayload, $iOffset);
			$aParameters[] = ['value' => $aDecoded['value'], 'pdoType' => $aDecoded['pdoType']];
			$iOffset += $aDecoded['length'];
		}

		$sSql = substr($sPayload, $iOffset);

		return new QueryPayload($sDatabaseName, $iStreamPort, $aParameters, $sSql);
	}

	protected function _readByte(string $sBytes, int &$iOffset): int
	{
		if ($iOffset >= strlen($sBytes)) {
			throw new \RuntimeException('Truncated payload: expected a byte at offset ' . $iOffset);
		}
		return ord($sBytes[$iOffset++]);
	}

	protected function _readLengthPrefixed(string $sBytes, int &$iOffset): string
	{
		$iLength = $this->_readByte($sBytes, $iOffset);
		if ($iOffset + $iLength > strlen($sBytes)) {
			throw new \RuntimeException('Truncated payload: expected ' . $iLength . ' bytes at offset ' . $iOffset);
		}
		$sValue = substr($sBytes, $iOffset, $iLength);
		$iOffset += $iLength;
		return $sValue;
	}

	protected function _readUint16(string $sBytes, int &$iOffset): int
	{
		if ($iOffset + 2 > strlen($sBytes)) {
			throw new \RuntimeException('Truncated payload: expected a 16-bit value at offset ' . $iOffset);
		}
		$aUnpacked = unpack('v', substr($sBytes, $iOffset, 2));
		if ($aUnpacked === false) {
			throw new \RuntimeException('unpack(v) failed at offset ' . $iOffset);
		}
		$iOffset += 2;
		return $this->_asInt($aUnpacked[1]);
	}

	/**
	 * unpack()'s array is typed with mixed values regardless of format -
	 * narrows it back to what the 'v' format code actually guarantees, the
	 * same pattern Messages\Request::_asInt() already uses for the same
	 * reason.
	 */
	protected function _asInt(mixed $mValue): int
	{
		return is_int($mValue) ? $mValue : 0;
	}
}
