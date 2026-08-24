<?php
/**
 * This file contains the SqlServer service provider
 *
 * @package core
*/
namespace HomeLan\FileStore\Services\Provider;

use HomeLan\FileStore\Services\ProviderInterface;
use HomeLan\FileStore\Services\Provider\AdminInterface;
use HomeLan\FileStore\Services\Provider\SqlServer\DatabaseRegistry;
use HomeLan\FileStore\Services\Provider\SqlServer\DatabaseDefinition;
use HomeLan\FileStore\Services\Provider\SqlServer\ConnectionFactory;
use HomeLan\FileStore\Services\Provider\SqlServer\ValueCodec;
use HomeLan\FileStore\Services\Provider\SqlServer\RequestPayloadParser;
use HomeLan\FileStore\Services\Provider\SqlServer\QueryPayload;
use HomeLan\FileStore\Services\Provider\SqlServer\CursorInterface;
use HomeLan\FileStore\Services\Provider\SqlServer\BufferedCursor;
use HomeLan\FileStore\Services\Provider\SqlServer\PgsqlCursor;
use HomeLan\FileStore\Services\ServiceDispatcher;
use HomeLan\FileStore\Messages\SqlRequest;
use HomeLan\FileStore\Messages\SqlReply;
use HomeLan\FileStore\Messages\EconetPacket;
use HomeLan\FileStore\Authentication\Security;
use config;

/**
 * Implements the SqlServer protocol (see docs/protocols/sql-server.md) so
 * that Econet clients (e.g. a BBC Micro) can authenticate, run a
 * parameterised SQL query against a configured PostgreSQL/MySQL/SQLite
 * database, and stream the (paged) result set back to a port the client
 * itself specifies at query time.
 *
 * Meant to run hosted in its own process (`sql-serverd`) via the Remote
 * Provider Protocol - see docs/protocols/remote-provider.md and
 * Command\SqlServerd - but nothing in this class is aware of that; it is
 * written exactly as it would be if it ran inside filestored directly,
 * the same way EcoSyslog is.
 *
 * @package core
*/
class SqlServer implements ProviderInterface
{
	// Operation codes (control byte of the request payload)
	public const int OP_LOGIN           = 0x01;
	public const int OP_LOGOUT          = 0x02;
	public const int OP_LIST_DATABASES  = 0x03;
	public const int OP_QUERY           = 0x04;
	public const int OP_CANCEL          = 0x05;

	// Error codes (leading status byte of a failed reply)
	public const int ERROR_NOT_AUTHENTICATED   = 1;
	public const int ERROR_BAD_CREDENTIALS     = 2;
	public const int ERROR_UNKNOWN_DATABASE    = 3;
	public const int ERROR_ACCESS_DENIED       = 4;
	public const int ERROR_BUSY                = 5;
	public const int ERROR_QUERY_FAILED        = 6;
	public const int ERROR_UNKNOWN_OP          = 7;
	public const int ERROR_TOO_MANY_CONNECTIONS = 8;
	public const int ERROR_NOTHING_TO_CANCEL   = 9;

	// Engine tags used in the LIST_DATABASES reply
	public const int ENGINE_SQLITE = 0;
	public const int ENGINE_MYSQL  = 1;
	public const int ENGINE_PGSQL  = 2;

	/** Result blocks are packed to this size, matching FileServer's GETBYTES/PUTBYTES. */
	protected const int STREAM_BLOCK_SIZE = 256;

	/** How many rows are pulled from a cursor at a time to top up the encode buffer - an internal tuning knob, not part of the wire protocol. */
	protected const int ROWS_PER_FETCH = 50;

	public const int EOF_COMPLETE = 0x80;
	public const int EOF_ERROR    = 0x01;

	protected ?ServiceDispatcher $oServiceDispatcher = null;

	/** @var array<int, SqlReply|EconetPacket> */
	protected array $aReplyBuffer = [];

	protected readonly DatabaseRegistry $oRegistry;
	protected readonly ConnectionFactory $oConnectionFactory;
	protected readonly ValueCodec $oValueCodec;
	protected readonly RequestPayloadParser $oPayloadParser;

	/**
	 * In-flight query/stream state, keyed by "{network}.{station}" - at
	 * most one per station (see docs/protocols/sql-server.md).
	 *
	 * @var array<string, array{network: int, station: int, databaseName: string, cursor: CursorInterface, buffer: string, headerSent: bool, streamPort: int, rowsSent: int, oRequest: SqlRequest}>
	 */
	protected array $aInFlight = [];

	public function __construct(
		protected readonly \Psr\Log\LoggerInterface $oLogger,
		?DatabaseRegistry $oRegistry = null,
		?ConnectionFactory $oConnectionFactory = null
	) {
		$this->oRegistry = $oRegistry ?? new DatabaseRegistry();
		$this->oConnectionFactory = $oConnectionFactory ?? new ConnectionFactory();
		$this->oValueCodec = new ValueCodec();
		$this->oPayloadParser = new RequestPayloadParser($this->oValueCodec);
	}

	public function getName(): string
	{
		return 'SqlServer';
	}

	public function getAdminInterface(): ?AdminInterface
	{
		// Hosted out-of-process (see docs/protocols/remote-provider.md § Scope and
		// Limitations) - no admin UI in this first version; would be served from
		// sql-serverd's own admin port, the same pattern sharefsd already uses,
		// if ever added.
		return null;
	}

	/**
	 * @return array<int,int>
	*/
	public function getServicePorts(): array
	{
		return [config::getValueAsInt('sql_server_port')];
	}

	public function registerService(ServiceDispatcher $oServiceDispatcher): void
	{
		$this->oServiceDispatcher = $oServiceDispatcher;
		$_this = $this;
		$oServiceDispatcher->addHousingKeepingTask(function () use ($_this) {
			$_this->sweepStaleConnections();
		});
	}

	public function broadcastPacketIn(EconetPacket $oPacket): void
	{
		// This protocol has no broadcast discovery - a client is expected to
		// know the server's station directly (matches FileServer).
	}

	public function unicastPacketIn(EconetPacket $oPacket): void
	{
		$this->handleClientRequest(new SqlRequest($oPacket, $this->oLogger));
	}

	/**
	 * @return array<int,EconetPacket>
	*/
	public function getReplies(): array
	{
		$aReturn = [];
		foreach ($this->aReplyBuffer as $oReply) {
			$aReturn[] = ($oReply instanceof EconetPacket) ? $oReply : $oReply->buildEconetpacket();
		}
		$this->aReplyBuffer = [];
		return $aReturn;
	}

	/**
	 * @return array<int,array<string,mixed>>
	*/
	public function getJobs(): array
	{
		return [];
	}

	protected function addReplyToBuffer(SqlReply|EconetPacket $oReply): void
	{
		$this->aReplyBuffer[] = $oReply;
	}

	// -------------------------------------------------------------------------
	// Operation dispatch
	// -------------------------------------------------------------------------

	protected function handleClientRequest(SqlRequest $oRequest): void
	{
		$this->oLogger->debug('SqlServer: op=' . sprintf('0x%02X', $oRequest->getControlByte())
			. ' from ' . $oRequest->getSourceNetwork() . '.' . $oRequest->getSourceStation());

		switch ($oRequest->getControlByte()) {
			case self::OP_LOGIN:
				$this->handleLogin($oRequest);
				break;
			case self::OP_LOGOUT:
				$this->handleLogout($oRequest);
				break;
			case self::OP_LIST_DATABASES:
				$this->handleListDatabases($oRequest);
				break;
			case self::OP_QUERY:
				$this->handleQuery($oRequest);
				break;
			case self::OP_CANCEL:
				$this->handleCancel($oRequest);
				break;
			default:
				$this->sendError($oRequest, self::ERROR_UNKNOWN_OP, 'Unknown operation');
				break;
		}
	}

	protected function handleLogin(SqlRequest $oRequest): void
	{
		try {
			$aLogin = $this->oPayloadParser->parseLogin($oRequest->getPayload());
		} catch (\Throwable $oException) {
			$this->sendError($oRequest, self::ERROR_BAD_CREDENTIALS, 'Malformed login request');
			return;
		}

		$bOk = Security::login($oRequest->getSourceNetwork() ?? 0, $oRequest->getSourceStation() ?? 0, $aLogin['username'], $aLogin['password']);

		$oReply = $oRequest->buildReply();
		if (!$bOk) {
			$oReply->appendStatus(self::ERROR_BAD_CREDENTIALS);
			$oReply->appendCrString('Login failed');
		} else {
			$oReply->appendStatus(0);
		}
		$this->addReplyToBuffer($oReply->buildEconetpacket());
	}

	protected function handleLogout(SqlRequest $oRequest): void
	{
		$iNetwork = $oRequest->getSourceNetwork() ?? 0;
		$iStation = $oRequest->getSourceStation() ?? 0;

		$this->cancelInFlight($iNetwork, $iStation);
		$this->oConnectionFactory->closeAllForStation($iNetwork, $iStation);
		Security::logout($iNetwork, $iStation);

		$oReply = $oRequest->buildReply();
		$oReply->appendStatus(0);
		$this->addReplyToBuffer($oReply->buildEconetpacket());
	}

	protected function handleListDatabases(SqlRequest $oRequest): void
	{
		$iNetwork = $oRequest->getSourceNetwork() ?? 0;
		$iStation = $oRequest->getSourceStation() ?? 0;

		if (!Security::isLoggedIn($iNetwork, $iStation)) {
			$this->sendError($oRequest, self::ERROR_NOT_AUTHENTICATED, 'Not logged in');
			return;
		}

		$sUsername = Security::getUser($iNetwork, $iStation)?->getUsername() ?? '';
		$aVisible = array_values(array_filter(
			$this->oRegistry->all(),
			fn(DatabaseDefinition $oDb): bool => $oDb->isUserAllowed($sUsername)
		));

		$oReply = $oRequest->buildReply();
		$oReply->appendStatus(0);
		$oReply->appendByte(count($aVisible));
		foreach ($aVisible as $oDb) {
			$oReply->appendLengthPrefixed($oDb->sName);
			$oReply->appendByte($this->engineTag($oDb->sEngine));
		}
		$this->addReplyToBuffer($oReply->buildEconetpacket());
	}

	protected function handleCancel(SqlRequest $oRequest): void
	{
		$iNetwork = $oRequest->getSourceNetwork() ?? 0;
		$iStation = $oRequest->getSourceStation() ?? 0;
		$sKey = $this->stationKey($iNetwork, $iStation);

		if (!isset($this->aInFlight[$sKey])) {
			$this->sendError($oRequest, self::ERROR_NOTHING_TO_CANCEL, 'No query in progress');
			return;
		}

		$this->cancelInFlight($iNetwork, $iStation);

		$oReply = $oRequest->buildReply();
		$oReply->appendStatus(0);
		$this->addReplyToBuffer($oReply->buildEconetpacket());
	}

	protected function handleQuery(SqlRequest $oRequest): void
	{
		$iNetwork = $oRequest->getSourceNetwork() ?? 0;
		$iStation = $oRequest->getSourceStation() ?? 0;

		if (!Security::isLoggedIn($iNetwork, $iStation)) {
			$this->sendError($oRequest, self::ERROR_NOT_AUTHENTICATED, 'Not logged in');
			return;
		}

		$sKey = $this->stationKey($iNetwork, $iStation);
		if (isset($this->aInFlight[$sKey])) {
			$this->sendError($oRequest, self::ERROR_BUSY, 'A query is already in progress for this station');
			return;
		}

		try {
			$oQuery = $this->oPayloadParser->parseQuery($oRequest->getPayload());
		} catch (\Throwable $oException) {
			$this->sendError($oRequest, self::ERROR_QUERY_FAILED, 'Malformed query request: ' . $oException->getMessage());
			return;
		}

		$oDb = $this->oRegistry->get($oQuery->sDatabaseName);
		if ($oDb === null) {
			$this->sendError($oRequest, self::ERROR_UNKNOWN_DATABASE, 'Unknown database "' . $oQuery->sDatabaseName . '"');
			return;
		}

		$sUsername = Security::getUser($iNetwork, $iStation)?->getUsername() ?? '';
		if (!$oDb->isUserAllowed($sUsername)) {
			$this->sendError($oRequest, self::ERROR_ACCESS_DENIED, 'Not permitted to query "' . $oQuery->sDatabaseName . '"');
			return;
		}

		try {
			$oPdo = $this->oConnectionFactory->getConnection($iNetwork, $iStation, $oDb);
		} catch (\Throwable $oException) {
			$this->sendError($oRequest, self::ERROR_TOO_MANY_CONNECTIONS, $oException->getMessage());
			return;
		}

		try {
			[$bHasResultSet, $mResult] = $this->execute($oPdo, $oDb, $oQuery);
		} catch (\Throwable $oException) {
			$this->sendError($oRequest, self::ERROR_QUERY_FAILED, $oException->getMessage());
			return;
		}

		$oReply = $oRequest->buildReply();
		$oReply->appendStatus(0);

		if (!$bHasResultSet) {
			/** @var int $mResult */
			$oReply->appendByte(0);
			$oReply->append32bitIntLittleEndian($mResult);
			$this->addReplyToBuffer($oReply->buildEconetpacket());
			return;
		}

		/** @var CursorInterface $mResult */
		$oReply->appendByte(1);
		$this->addReplyToBuffer($oReply->buildEconetpacket());

		$this->aInFlight[$sKey] = [
			'network'      => $iNetwork,
			'station'      => $iStation,
			'databaseName' => $oDb->sName,
			'cursor'       => $mResult,
			'buffer'       => '',
			'headerSent'   => false,
			'streamPort'   => $oQuery->iStreamPort,
			'rowsSent'     => 0,
			'oRequest'     => $oRequest,
		];

		$this->pumpStream($sKey);
	}

	// -------------------------------------------------------------------------
	// Query execution
	// -------------------------------------------------------------------------

	/**
	 * Runs one QUERY payload's statement, returning either a CursorInterface
	 * to stream (a result-set-producing statement) or a plain rows-affected
	 * count (everything else).
	 *
	 * @return array{0: bool, 1: int|CursorInterface}
	 */
	protected function execute(\PDO $oPdo, DatabaseDefinition $oDb, QueryPayload $oQuery): array
	{
		// Postgres has no PDO equivalent of MySQL's "unbuffered query" flag -
		// true server-side paging needs an explicit SQL cursor instead (see
		// PgsqlCursor), which only works for a result-set-producing
		// statement. A cheap keyword sniff decides which path to take
		// without executing the statement twice; a statement this misses
		// (e.g. `INSERT ... RETURNING`) falls back to the plain path below
		// and its returned rows are not streamed - a known, documented
		// limitation (see docs/protocols/sql-server.md).
		if ($oDb->sEngine === 'pgsql' && $this->looksLikeSelect($oQuery->sSql)) {
			return [true, new PgsqlCursor($oPdo, $oQuery->sSql, $oQuery->aParameters)];
		}

		$oStatement = $oPdo->prepare($oQuery->sSql);
		foreach ($oQuery->aParameters as $i => $aParam) {
			$oStatement->bindValue($i + 1, $aParam['value'], $aParam['pdoType']);
		}
		$oStatement->execute();

		if ($oStatement->columnCount() > 0) {
			return [true, new BufferedCursor($oStatement)];
		}
		return [false, $oStatement->rowCount()];
	}

	protected function looksLikeSelect(string $sSql): bool
	{
		return (bool) preg_match('/^(SELECT|WITH|SHOW|EXPLAIN|TABLE)\b/i', ltrim($sSql));
	}

	// -------------------------------------------------------------------------
	// Result streaming - ack-per-block, exactly mirroring FileServer's
	// GETBYTES loop (see FileServer::loadFile()); this is the paging
	// mechanism the client's own acks pace.
	// -------------------------------------------------------------------------

	protected function pumpStream(string $sKey): void
	{
		if (!isset($this->aInFlight[$sKey])) {
			return;
		}

		$aState = $this->aInFlight[$sKey];

		if (!$aState['headerSent']) {
			$aState['buffer'] .= $this->encodeHeader($aState['cursor']->getColumnNames());
			$aState['headerSent'] = true;
		}

		while (strlen($aState['buffer']) < self::STREAM_BLOCK_SIZE && !$aState['cursor']->isExhausted()) {
			$aRows = $aState['cursor']->fetchNext(self::ROWS_PER_FETCH);
			if ($aRows === []) {
				break;
			}
			foreach ($aRows as $aRow) {
				$aState['buffer'] .= $this->encodeRow($aRow);
				$aState['rowsSent']++;
			}
		}

		if ($aState['buffer'] === '') {
			$this->aInFlight[$sKey] = $aState;
			$this->finishStream($sKey, self::EOF_COMPLETE);
			return;
		}

		$sBlock = substr($aState['buffer'], 0, self::STREAM_BLOCK_SIZE);
		$aState['buffer'] = substr($aState['buffer'], self::STREAM_BLOCK_SIZE);
		$this->aInFlight[$sKey] = $aState;

		$oServiceDispatcher = $this->oServiceDispatcher;
		if ($oServiceDispatcher === null) {
			$this->oLogger->error('SqlServer: no ServiceDispatcher registered - cannot stream query results');
			$this->cancelInFlight($aState['network'], $aState['station']);
			return;
		}

		$oPacket = new EconetPacket();
		$oPacket->setDestinationNetwork($aState['network']);
		$oPacket->setDestinationStation($aState['station']);
		$oPacket->setPort($aState['streamPort']);
		$oPacket->setFlags(0);
		$oPacket->setData($sBlock);
		$this->addReplyToBuffer($oPacket);

		$this->flushReplies();

		$_this = $this;
		$oServiceDispatcher->addAckEvent($aState['network'], $aState['station'], $oPacket->getSequence(), function () use ($_this, $sKey) {
			$_this->pumpStream($sKey);
		});
	}

	/**
	 * Sends whatever this provider has queued right now, rather than
	 * waiting for the normal request/reply flow to harvest getReplies() -
	 * needed here because an ack-driven block send happens asynchronously,
	 * well after the QUERY request that started the stream already
	 * returned (exactly the same reasoning as FileServer's GETBYTES loop,
	 * which calls ServiceDispatcher::sendPackets() itself for the same
	 * reason). Overridden to a no-op in tests, which read getReplies()
	 * directly instead - real transmission needs infrastructure
	 * (WebSocket\Map et al.) a unit test has no reason to stand up.
	 */
	protected function flushReplies(): void
	{
		$this->oServiceDispatcher?->sendPackets($this);
	}

	protected function finishStream(string $sKey, int $iEofFlag): void
	{
		if (!isset($this->aInFlight[$sKey])) {
			return;
		}
		$aState = $this->aInFlight[$sKey];
		$aState['cursor']->close();
		unset($this->aInFlight[$sKey]);

		$oReply = $aState['oRequest']->buildReply();
		$oReply->appendStatus(0);
		$oReply->appendByte($iEofFlag);
		$oReply->append32bitIntLittleEndian($aState['rowsSent']);
		$this->addReplyToBuffer($oReply->buildEconetpacket());
	}

	protected function cancelInFlight(int $iNetwork, int $iStation): void
	{
		$sKey = $this->stationKey($iNetwork, $iStation);
		if (!isset($this->aInFlight[$sKey])) {
			return;
		}
		$this->aInFlight[$sKey]['cursor']->close();
		unset($this->aInFlight[$sKey]);
		$this->oServiceDispatcher?->clearAckEvent($iNetwork, $iStation);
	}

	// -------------------------------------------------------------------------
	// Wire encoding helpers
	// -------------------------------------------------------------------------

	/**
	 * @param array<int, string> $aColumnNames
	 */
	protected function encodeHeader(array $aColumnNames): string
	{
		$sOut = pack('v', count($aColumnNames));
		foreach ($aColumnNames as $sName) {
			$sName = substr($sName, 0, 255);
			$sOut .= chr(strlen($sName)) . $sName;
		}
		return $sOut;
	}

	/**
	 * @param array<string, int|float|string|null> $aRow
	 */
	protected function encodeRow(array $aRow): string
	{
		$sOut = '';
		foreach ($aRow as $mValue) {
			$sOut .= $this->oValueCodec->encodeCell($mValue);
		}
		return $sOut;
	}

	protected function engineTag(string $sEngine): int
	{
		return match ($sEngine) {
			'sqlite' => self::ENGINE_SQLITE,
			'mysql'  => self::ENGINE_MYSQL,
			'pgsql'  => self::ENGINE_PGSQL,
			default  => self::ENGINE_SQLITE,
		};
	}

	protected function stationKey(int $iNetwork, int $iStation): string
	{
		return $iNetwork . '.' . $iStation;
	}

	protected function sendError(SqlRequest $oRequest, int $iError, string $sMessage): void
	{
		$oReply = $oRequest->buildReply();
		$oReply->appendStatus($iError);
		$oReply->appendCrString($sMessage);
		$this->addReplyToBuffer($oReply->buildEconetpacket());
	}

	// -------------------------------------------------------------------------
	// Housekeeping
	// -------------------------------------------------------------------------

	/**
	 * Runs on every housekeeping tick: closes any connection
	 * ConnectionFactory is holding for a station Security no longer has a
	 * session for (a client that vanished without LOGOUT, or whose session
	 * idled out - see docs/authentication.md's session reaper).
	 */
	public function sweepStaleConnections(): void
	{
		foreach ($this->oConnectionFactory->activeStations() as $aStation) {
			if (!Security::isLoggedIn($aStation['network'], $aStation['station'])) {
				$this->cancelInFlight($aStation['network'], $aStation['station']);
				$this->oConnectionFactory->closeAllForStation($aStation['network'], $aStation['station']);
			}
		}
	}
}
