<?php
/**
 * This file contains the RemoteBridge Connection class
 *
 * @author John Brown <john@home-lan.co.uk>
 * @package core
*/
namespace HomeLan\FileStore\RemoteBridge;

use React\Socket\ConnectionInterface;
use React\EventLoop\LoopInterface;
use React\EventLoop\TimerInterface;
use HomeLan\FileStore\Messages\EconetPacket;

/**
 * Manages a single remote bridge TCP connection, handling authentication and packet forwarding.
 *
 * Authentication protocol (client initiates):
 *   CLIENT → SERVER: HELLO <unix_timestamp> <ver1>[,<ver2>...]
 *   SERVER → CLIENT: CHALLENGE <32-hex-nonce> <agreed_version>
 *           OR       VERSION_REJECT <server_supported_versions>
 *   CLIENT → SERVER: AUTH <hex-hmac-sha256(secret, nonce:timestamp)>
 *   SERVER → CLIENT: AUTH_OK
 *   BOTH SIDES:      NETWORKS <net1,net2,...>   (announces local networks immediately after auth)
 *
 * The server picks the highest protocol version that both sides support.  If there is no
 * common version it sends VERSION_REJECT and closes the connection.
 *
 * After authentication, both directions accept:
 *   SEND <dst_net> <dst_stn> <src_net> <src_stn> <port> <flags> [<seq>] <base64_data>
 *   ACK <net> <stn> [<seq>]                                  (protocol 1.1+, <seq> in 1.2+)
 *   PING / PONG                                              (protocol 1.1+, see startHeartbeat())
 *
 * @package core
*/
class Connection
{
	/**
	 * Protocol versions this implementation supports, in ascending order.
	 *
	 * 1.1 adds the ACK <net> <stn> message (see sendAck()/handleAuthenticated())
	 * and the PING/PONG heartbeat (see startHeartbeat()). 1.2 adds an optional
	 * trailing <seq> field to both SEND and ACK, carrying the originating
	 * EconetPacket::getSequence() value across the bridge so
	 * ServiceDispatcher::ackEvents() can tell a relayed ack apart from a stray
	 * one for the same station — see BridgePacket's class doc for the wire
	 * format and Map::rememberAckRelay()/relayAckIfKnown() for how the value
	 * is threaded through. See docs/protocols/remote-bridge.md for the full
	 * spec and the conformance requirements it places on third-party bridge
	 * clients. 1.0/1.1 peers are still fully supported; ACK, PING/PONG and the
	 * <seq> field are simply never sent to a peer that didn't negotiate them.
	*/
	public const SUPPORTED_VERSIONS = ['1.0', '1.1', '1.2'];

	/** Seconds between PING sends once authenticated on a 1.1+ connection. */
	private const int PING_INTERVAL_SECONDS = 3;

	/** Seconds of total silence (any line, not just PING/PONG) before a 1.1+ connection is considered dead. */
	private const int IDLE_TIMEOUT_SECONDS = 10;

	private const string STATE_WAITING_HELLO    = 'WAITING_HELLO';
	private const string STATE_CHALLENGING      = 'CHALLENGING';
	private const string STATE_HELLO_SENT       = 'HELLO_SENT';
	private const string STATE_WAITING_AUTH_OK  = 'WAITING_AUTH_OK';
	private const string STATE_AUTHENTICATED    = 'AUTHENTICATED';
	private const string STATE_CLOSED           = 'CLOSED';

	private string $sState;
	private string $sNonce = '';
	private int $iTimestamp = 0;
	private string $sBuffer = '';
	/** @var array<int, int> */
	private array $aPeerNetworks = [];
	private string $sProtocolVersion = '';
	/** @var string[] */
	private array $aSupportedVersions;
	private float $fLastRxTime;
	private ?TimerInterface $oPingTimer = null;
	private ?TimerInterface $oIdleTimer = null;

	/**
	 * @param string    $sRole              'server' or 'client'
	 * @param string    $sSecret            Shared HMAC secret
	 * @param int[]     $aLocalNetworks     Networks this side serves locally (announced after auth)
	 * @param \Closure  $fOnPacket          Called with BridgePacket when an authenticated SEND arrives
	 * @param \Closure  $fOnAck             Called with a BridgePacket built via makeAck() when an
	 *                                      authenticated ACK <net> <stn> arrives (protocol 1.1+) —
	 *                                      always local dispatch, never forwarded (see
	 *                                      handleAuthenticated()'s ACK case for why)
	 * @param string[]|null $aSupportedVersions Protocol versions to advertise (null = SUPPORTED_VERSIONS)
	 * @param LoopInterface|null $oLoop         Event loop used to schedule the protocol 1.1+ PING
	 *                                          heartbeat and idle-timeout check (see startHeartbeat()).
	 *                                          Null disables the heartbeat entirely — used by unit
	 *                                          tests that drive the connection without a real loop.
	*/
	public function __construct(
		private readonly \Psr\Log\LoggerInterface $oLogger,
		private readonly ConnectionInterface $oTcpConn,
		private readonly string $sRole,
		private readonly string $sSecret,
		private readonly array $aLocalNetworks,
		private readonly \Closure $fOnPacket,
		private readonly \Closure $fOnAck,
		?array $aSupportedVersions = null,
		private readonly ?LoopInterface $oLoop = null,
	) {
		$this->aSupportedVersions = $aSupportedVersions ?? self::SUPPORTED_VERSIONS;
		$this->fLastRxTime = microtime(true);

		if ($this->sRole === 'client') {
			$this->sState = self::STATE_HELLO_SENT;
			$iTs = time();
			$this->iTimestamp = $iTs;
			$sVersions = implode(',', $this->aSupportedVersions);
			$this->oTcpConn->write("HELLO {$iTs} {$sVersions}\n");
		} else {
			$this->sState = self::STATE_WAITING_HELLO;
		}
	}

	public function onData(string $sData): void
	{
		$this->sBuffer .= $sData;
		while (($iPos = strpos($this->sBuffer, "\n")) !== false) {
			$sLine = trim(substr($this->sBuffer, 0, $iPos));
			$this->sBuffer = substr($this->sBuffer, $iPos + 1);
			if ($sLine !== '') {
				$this->handleLine($sLine);
			}
		}
	}

	private function handleLine(string $sLine): void
	{
		$this->fLastRxTime = microtime(true);
		$this->oLogger->debug("RemoteBridge[{$this->sRole}] rx: {$sLine}");
		$iSpace = strpos($sLine, ' ');
		$sCmd = strtoupper($iSpace !== false ? substr($sLine, 0, $iSpace) : $sLine);
		$sArgs = $iSpace !== false ? substr($sLine, $iSpace + 1) : '';

		if ($this->sState === self::STATE_AUTHENTICATED) {
			$this->handleAuthenticated($sCmd, $sArgs, $sLine);
			return;
		}

		switch ($this->sState) {
			case self::STATE_WAITING_HELLO:
				$this->handleHello($sCmd, $sArgs);
				break;
			case self::STATE_CHALLENGING:
				$this->handleAuth($sCmd, $sArgs);
				break;
			case self::STATE_HELLO_SENT:
				$this->handleChallenge($sCmd, $sArgs);
				break;
			case self::STATE_WAITING_AUTH_OK:
				$this->handleAuthOk($sCmd, $sArgs);
				break;
		}
	}

	private function handleAuthenticated(string $sCmd, string $sArgs, string $sLine): void
	{
		if ($sCmd === 'NETWORKS') {
			$aPeerNets = array_filter(
				array_map(intval(...), explode(',', trim($sArgs))),
				fn(int $n) => $n > 0
			);
			$this->aPeerNetworks = array_values($aPeerNets);
			Map::registerPeerNetworks($this, $this->aPeerNetworks);
			$this->oLogger->info(
				"RemoteBridge[{$this->sRole}]: peer serves networks " . implode(',', $this->aPeerNetworks)
			);
			return;
		}

		if ($sCmd === 'SEND') {
			$oPkt = BridgePacket::fromLine($sLine, $this->hasSeq());
			if ($oPkt === null) {
				$this->oLogger->warning("RemoteBridge: malformed SEND line");
				return;
			}
			// Validate the destination is one of our local networks (defence against misbehaving peers)
			if (!empty($this->aLocalNetworks) && !in_array($oPkt->getDstNetwork(), $this->aLocalNetworks, true)) {
				$this->oLogger->warning(
					"RemoteBridge: dropping SEND for network {$oPkt->getDstNetwork()} — not our network"
				);
				return;
			}
			// Remember that this connection asked for delivery to this station, so that
			// when our own local encapsulation observes the real hardware ack it provokes,
			// relayAckIfKnown() knows to relay it back across this same connection, echoing
			// $oPkt->getSequence() (protocol 1.2+, else null) — see
			// RemoteBridge\Map::rememberAckRelay().
			Map::rememberAckRelay($oPkt->getDstNetwork(), $oPkt->getDstStation(), $this, $oPkt->getSequence());
			($this->fOnPacket)($oPkt);
			return;
		}

		if ($sCmd === 'ACK') {
			// ACK <net> <stn> [<seq>] — a real Econet-level ack for a station, relayed
			// back across the bridge (protocol 1.1+, <seq> added in 1.2). Unlike SEND, this is
			// never forwarded on or gated by aLocalNetworks: it always means
			// "dispatch this to my own ServiceDispatcher", since the whole
			// point is to reach whichever local addAckEvent() registration
			// on *this* instance is waiting for it — see
			// docs/protocols/remote-bridge.md.
			$oAck = BridgePacket::fromAckLine($sLine);
			if ($oAck === null) {
				$this->oLogger->warning("RemoteBridge: malformed ACK line");
				return;
			}
			($this->fOnAck)($oAck);
			return;
		}

		if ($sCmd === 'PING') {
			// No-payload liveness probe (protocol 1.1+) — reply in kind. $sArgs is
			// ignored rather than validated: an unexpected trailing field on PING
			// is tolerated like any other malformed 1.1 line, never a reason to
			// drop the connection. See docs/protocols/remote-bridge.md#heartbeat-protocol-11.
			$this->oTcpConn->write("PONG\n");
			return;
		}

		if ($sCmd === 'PONG') {
			// Nothing to do beyond the rx-watermark bump already applied above —
			// PONG exists purely as proof of liveness.
			return;
		}
	}

	/**
	 * Relays a real Econet-level ack for (net, stn) back across the bridge —
	 * called by RemoteBridgeMap::relayAckIfKnown() when a locally-received
	 * ack turns out to be for a station whose network is only reachable via
	 * this connection. A no-op pre-1.1 peer, silently: sending ACK to a peer
	 * that never advertised 1.1 support would just be ignored by a
	 * conformant implementation, but not sending it at all avoids relying on
	 * that. $iSeq (the sequence Map::rememberAckRelay() captured off the
	 * original SEND, if any) is only written to the wire once this
	 * connection has negotiated 1.2 — see BridgePacket::encodeAck().
	*/
	public function sendAck(int $iNet, int $iStn, ?int $iSeq = null): void
	{
		if ($this->sState !== self::STATE_AUTHENTICATED) {
			return;
		}
		if (version_compare($this->sProtocolVersion, '1.1', '<')) {
			return;
		}
		$this->oTcpConn->write(BridgePacket::encodeAck($iNet, $iStn, $this->hasSeq() ? $iSeq : null));
	}

	/** True once this connection has negotiated protocol 1.2 or later (the SEND/ACK <seq> field). */
	private function hasSeq(): bool
	{
		return version_compare($this->sProtocolVersion, '1.2', '>=');
	}

	private function handleHello(string $sCmd, string $sArgs): void
	{
		if ($sCmd !== 'HELLO') {
			$this->oLogger->warning("RemoteBridge: expected HELLO, got {$sCmd}");
			$this->oTcpConn->close();
			return;
		}
		// HELLO <timestamp> <versions_csv>
		$aParts = explode(' ', trim($sArgs), 2);
		$iTs = (int) $aParts[0];
		$aClientVersions = isset($aParts[1]) ? array_map(trim(...), explode(',', $aParts[1])) : ['1.0'];

		if (abs(time() - $iTs) > 60) {
			$this->oLogger->warning("RemoteBridge: HELLO timestamp out of range ({$iTs}), rejecting");
			$this->oTcpConn->write("AUTH_FAIL timestamp_drift\n");
			$this->oTcpConn->close();
			return;
		}

		$sAgreed = self::negotiateVersion($aClientVersions, $this->aSupportedVersions);
		if ($sAgreed === null) {
			$sMyVersions = implode(',', $this->aSupportedVersions);
			$this->oLogger->warning(
				"RemoteBridge: no common protocol version (client wants " . implode(',', $aClientVersions) . ", we support {$sMyVersions})"
			);
			$this->oTcpConn->write("VERSION_REJECT {$sMyVersions}\n");
			$this->oTcpConn->close();
			return;
		}

		$this->sProtocolVersion = $sAgreed;
		$this->iTimestamp = $iTs;
		$this->sNonce = bin2hex(random_bytes(16));
		$this->sState = self::STATE_CHALLENGING;
		$this->oLogger->debug("RemoteBridge: negotiated protocol version {$sAgreed}");
		$this->oTcpConn->write("CHALLENGE {$this->sNonce} {$sAgreed}\n");
	}

	private function handleAuth(string $sCmd, string $sArgs): void
	{
		if ($sCmd !== 'AUTH') {
			$this->oLogger->warning("RemoteBridge: expected AUTH, got {$sCmd}");
			$this->oTcpConn->close();
			return;
		}
		$sExpected = hash_hmac('sha256', $this->sNonce . ':' . $this->iTimestamp, $this->sSecret);
		if (!hash_equals($sExpected, trim($sArgs))) {
			$this->oLogger->warning("RemoteBridge: AUTH failed — HMAC mismatch");
			$this->oTcpConn->write("AUTH_FAIL bad_hmac\n");
			$this->oTcpConn->close();
			return;
		}
		$this->sState = self::STATE_AUTHENTICATED;
		$this->oTcpConn->write("AUTH_OK\n");
		$this->announceNetworks();
		$this->startHeartbeat();
		$this->oLogger->info("RemoteBridge: client authenticated successfully");
	}

	private function handleChallenge(string $sCmd, string $sArgs): void
	{
		if ($sCmd === 'VERSION_REJECT') {
			$this->oLogger->error(
				"RemoteBridge: server has no common protocol version; server supports: {$sArgs}"
			);
			$this->oTcpConn->close();
			return;
		}
		if ($sCmd !== 'CHALLENGE') {
			$this->oLogger->warning("RemoteBridge: expected CHALLENGE, got {$sCmd}");
			$this->oTcpConn->close();
			return;
		}
		// CHALLENGE <nonce> <agreed_version>
		$aParts = explode(' ', trim($sArgs), 2);
		$this->sNonce = $aParts[0];
		$this->sProtocolVersion = $aParts[1] ?? '1.0';
		$this->oLogger->debug("RemoteBridge: negotiated protocol version {$this->sProtocolVersion}");
		$sHmac = hash_hmac('sha256', $this->sNonce . ':' . $this->iTimestamp, $this->sSecret);
		$this->sState = self::STATE_WAITING_AUTH_OK;
		$this->oTcpConn->write("AUTH {$sHmac}\n");
	}

	private function handleAuthOk(string $sCmd, string $sArgs): void
	{
		if ($sCmd === 'AUTH_FAIL') {
			$this->oLogger->error("RemoteBridge: server rejected authentication: {$sArgs}");
			$this->oTcpConn->close();
			return;
		}
		if ($sCmd !== 'AUTH_OK') {
			$this->oLogger->warning("RemoteBridge: expected AUTH_OK, got {$sCmd}");
			$this->oTcpConn->close();
			return;
		}
		$this->sState = self::STATE_AUTHENTICATED;
		$this->announceNetworks();
		$this->startHeartbeat();
		$this->oLogger->info("RemoteBridge: authenticated with server");
	}

	/**
	 * Starts the protocol 1.1+ PING heartbeat and idle-timeout check, once authenticated.
	 * A no-op on a 1.0 connection (no loop reference is needed there — a 1.0 peer has no
	 * obligation to generate periodic traffic, so silence is not itself a fault) and a
	 * no-op when constructed without a loop (unit tests exercising the protocol directly).
	*/
	private function startHeartbeat(): void
	{
		if ($this->oLoop === null || version_compare($this->sProtocolVersion, '1.1', '<')) {
			return;
		}

		// tpc: StreamSelectLoop calls periodic-timer callbacks with a
		// TimerInterface; declare the (ignored) param for strict arg-count.
		$this->oPingTimer = $this->oLoop->addPeriodicTimer(self::PING_INTERVAL_SECONDS, function (?TimerInterface $oTimer = null): void {
			if ($this->sState !== self::STATE_AUTHENTICATED) {
				return;
			}
			$this->oTcpConn->write("PING\n");
		});

		$this->oIdleTimer = $this->oLoop->addPeriodicTimer(1, function (?TimerInterface $oTimer = null): void {
			if ($this->sState !== self::STATE_AUTHENTICATED) {
				return;
			}
			if ((microtime(true) - $this->fLastRxTime) > self::IDLE_TIMEOUT_SECONDS) {
				$this->oLogger->warning(
					"RemoteBridge[{$this->sRole}]: no traffic for over " . self::IDLE_TIMEOUT_SECONDS . "s, closing connection as dead"
				);
				$this->oTcpConn->close();
			}
		});
	}

	private function stopHeartbeat(): void
	{
		if ($this->oLoop === null) {
			return;
		}
		if ($this->oPingTimer !== null) {
			$this->oLoop->cancelTimer($this->oPingTimer);
			$this->oPingTimer = null;
		}
		if ($this->oIdleTimer !== null) {
			$this->oLoop->cancelTimer($this->oIdleTimer);
			$this->oIdleTimer = null;
		}
	}

	private function announceNetworks(): void
	{
		if (!empty($this->aLocalNetworks)) {
			$this->oTcpConn->write("NETWORKS " . implode(',', $this->aLocalNetworks) . "\n");
		}
	}

	public function send(EconetPacket $oPacket): void
	{
		if ($this->sState !== self::STATE_AUTHENTICATED) {
			return;
		}
		$this->oTcpConn->write(BridgePacket::encode($oPacket, $this->hasSeq()));
	}

	public function onClose(): void
	{
		if ($this->sState === self::STATE_CLOSED) {
			return;
		}
		$this->sState = self::STATE_CLOSED;
		$this->stopHeartbeat();
		Map::unregisterConnection($this);
		$this->oLogger->info("RemoteBridge[{$this->sRole}]: connection closed");
	}

	public function isAuthenticated(): bool
	{
		return $this->sState === self::STATE_AUTHENTICATED;
	}

	/** @return array<int, int> */
	public function getPeerNetworks(): array
	{
		return $this->aPeerNetworks;
	}

	/** Returns the protocol version agreed during the handshake, or '' if not yet negotiated. */
	public function getProtocolVersion(): string
	{
		return $this->sProtocolVersion;
	}

	/** Returns the current state name — used by unit tests. */
	public function getState(): string
	{
		return $this->sState;
	}

	/**
	 * Picks the highest version that appears in both lists.
	 * Returns null if the intersection is empty.
	 *
	 * @param string[] $aClientVersions
	 * @param string[] $aServerVersions
	*/
	public static function negotiateVersion(array $aClientVersions, array $aServerVersions): ?string
	{
		$aCommon = array_intersect(
			array_map(trim(...), $aClientVersions),
			array_map(trim(...), $aServerVersions)
		);
		if (empty($aCommon)) {
			return null;
		}
		usort($aCommon, fn(string $sA, string $sB): int => version_compare($sA, $sB));
		return end($aCommon);
	}
}
