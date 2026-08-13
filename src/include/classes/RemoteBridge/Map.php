<?php
/**
 * This file contains the RemoteBridge Map class
 *
 * @author John Brown <john@home-lan.co.uk>
 * @package core
*/
namespace HomeLan\FileStore\RemoteBridge;

use config;
use HomeLan\FileStore\Messages\EconetPacket;

/**
 * Tracks which Econet networks are reachable via authenticated remote bridge connections,
 * and parses the remote bridge map file for server and client configuration entries.
 *
 * Map file format:
 *   SERVER <port> <secret> <my_networks>   — listen for incoming bridge connections
 *   CLIENT <host:port> <secret> <my_networks> — connect to a remote bridge server
 *
 * <my_networks> is a comma-separated list of Econet network numbers this side serves locally.
 * After authentication both sides exchange NETWORKS announcements so each knows what the other serves.
 *
 * When a connection drops, networks it served stay "known" for BUFFER_TTL_SECONDS and any
 * outbound packets for them are buffered rather than dropped, so a brief reconnect (e.g. the
 * remote end restarting) delays delivery instead of losing packets. Anything still buffered
 * once that grace period elapses is discarded rather than delivered stale.
 *
 * @package core
*/
class Map
{
	/** How long a network stays "known" and its packets buffered after its connection drops. */
	private const BUFFER_TTL_SECONDS = 5;

	/** Maximum outbound packets buffered per network while its connection is down. */
	private const BUFFER_MAX_PACKETS_PER_NETWORK = 32;

	/** @var \Psr\Log\LoggerInterface */
	static private \Psr\Log\LoggerInterface $oLogger;

	/** @var array<int, array{port:int, secret:string, networks:int[]}> */
	static private array $aServerEntries = [];

	/** @var array<int, array{host:string, port:int, secret:string, networks:int[]}> */
	static private array $aClientEntries = [];

	/** @var array<int, Connection> Network number => authenticated Connection */
	static private array $aNetworkConnections = [];

	/**
	 * @var array<string, Connection> "net.stn" => the connection whose peer most recently sent
	 *      us a SEND destined for that local station. Populated by rememberAckRelay(), consulted
	 *      by relayAckIfKnown() — see both for why this, and not $aNetworkConnections, is what
	 *      answers "should a real local ack for this station be relayed, and to whom".
	*/
	static private array $aPendingAckRelay = [];

	/** @var array<int, int> Network number => unix timestamp its connection was lost */
	static private array $aRecentlyDown = [];

	/** @var array<int, array<int, array{packet: EconetPacket, time: int}>> Network number => buffered outbound packets, oldest first */
	static private array $aOutboundBuffer = [];

	public static function init(\Psr\Log\LoggerInterface $oLogger, ?string $sMapContent = null): void
	{
		self::$oLogger = $oLogger;
		self::$aServerEntries = [];
		self::$aClientEntries = [];
		self::$aNetworkConnections = [];
		self::$aPendingAckRelay = [];
		self::$aRecentlyDown = [];
		self::$aOutboundBuffer = [];

		if ($sMapContent === null) {
			$sFile = (string) config::getValue('remote_bridge_map_file');
			if (!file_exists($sFile)) {
				$oLogger->info("RemoteBridge: map file not found ({$sFile}), no connections configured");
				return;
			}
			$sMapContent = (string) file_get_contents($sFile);
		}

		foreach (explode("\n", $sMapContent) as $sLine) {
			$sLine = trim($sLine);
			if ($sLine === '' || $sLine[0] === '#') {
				continue;
			}

			// SERVER <port> <secret> <networks>
			if (preg_match('/^SERVER\s+(\d+)\s+(\S+)\s+([\d,]+)$/i', $sLine, $aM)) {
				self::$aServerEntries[] = [
					'port'     => (int) $aM[1],
					'secret'   => $aM[2],
					'networks' => array_map('intval', explode(',', $aM[3])),
				];
				$oLogger->debug("RemoteBridge: server entry port={$aM[1]} networks={$aM[3]}");
				continue;
			}

			// CLIENT <host:port> <secret> <networks>
			if (preg_match('/^CLIENT\s+([^\s:]+):(\d+)\s+(\S+)\s+([\d,]+)$/i', $sLine, $aM)) {
				self::$aClientEntries[] = [
					'host'     => $aM[1],
					'port'     => (int) $aM[2],
					'secret'   => $aM[3],
					'networks' => array_map('intval', explode(',', $aM[4])),
				];
				$oLogger->debug("RemoteBridge: client entry host={$aM[1]}:{$aM[2]} networks={$aM[4]}");
				continue;
			}

			$oLogger->warning("RemoteBridge: unrecognised map line: {$sLine}");
		}
	}

	public static function networkKnown(int $iNetwork): bool
	{
		if (array_key_exists($iNetwork, self::$aNetworkConnections)) {
			return true;
		}
		return isset(self::$aRecentlyDown[$iNetwork])
			&& (time() - self::$aRecentlyDown[$iNetwork]) <= self::BUFFER_TTL_SECONDS;
	}

	public static function networkToConnection(int $iNetwork): ?Connection
	{
		return self::$aNetworkConnections[$iNetwork] ?? null;
	}

	/**
	 * Records that a SEND for (net, stn) — one of this instance's own local
	 * networks, i.e. a station we can physically deliver to — arrived over
	 * $oConn. Called by Connection::handleAuthenticated()'s SEND case for
	 * every accepted SEND, so that when this instance's own local
	 * encapsulation (AUN/Piconet/WebSocket) later observes the genuine
	 * hardware-level ack that delivery provokes, relayAckIfKnown() knows
	 * which connection originated the request and so which peer's pending
	 * addAckEvent() is actually waiting for it.
	 *
	 * Deliberately keyed by station, not by network: unlike outbound SEND
	 * routing (networkToConnection(), keyed by network, since an entire
	 * foreign network is reachable via exactly one bridge connection), a
	 * network this instance itself serves is never "reachable via a bridge
	 * connection" in that sense — unbounded local stations on it can each be
	 * the target of a SEND relayed in from a bridge peer, and each such
	 * station's eventual ack must be relayed back to whichever peer actually
	 * asked for it.
	*/
	public static function rememberAckRelay(int $iNetwork, int $iStation, Connection $oConn): void
	{
		self::$aPendingAckRelay["{$iNetwork}.{$iStation}"] = $oConn;
	}

	/**
	 * Relays a real Econet-level ack for (net, stn) back across whichever
	 * bridge connection most recently sent us a SEND destined for that
	 * station, if any — called from ServiceDispatcher::ackEvents() so every
	 * encapsulation's already-correct local ack handling (AUN, Piconet,
	 * WebSocket) gains bridge relay for free, with no changes needed in
	 * those handlers. A no-op, returning false, if this station never had a
	 * SEND relayed to it (the overwhelmingly common case — most acks are for
	 * genuinely, purely local traffic) or if the connection hasn't
	 * negotiated protocol 1.1+ (see Connection::sendAck()).
	 *
	 * Single-hop only: if the peer this relays to is itself bridging the
	 * network onward to a third instance, this does not re-relay further.
	 *
	 * @return bool True if a bridge connection for this station was found
	 *              (an ACK line was sent, or attempted).
	*/
	public static function relayAckIfKnown(int $iNetwork, int $iStation): bool
	{
		$oConn = self::$aPendingAckRelay["{$iNetwork}.{$iStation}"] ?? null;
		if ($oConn === null) {
			return false;
		}
		$oConn->sendAck($iNetwork, $iStation);
		return true;
	}

	/**
	 * Registers the networks that a peer has announced as reachable via this connection.
	 * Called when a NETWORKS message is received from the other side of an authenticated connection.
	*/
	public static function registerPeerNetworks(Connection $oConn, array $aNetworks): void
	{
		foreach ($aNetworks as $iNetwork) {
			self::$aNetworkConnections[$iNetwork] = $oConn;
			unset(self::$aRecentlyDown[$iNetwork]);
			if (isset(self::$oLogger)) {
				self::$oLogger->info("RemoteBridge: network {$iNetwork} now reachable via remote bridge");
			}
			self::flushBuffer($iNetwork, $oConn);
		}
	}

	/**
	 * Removes all routing entries for a connection (called on close/disconnect).
	 *
	 * The network stays "known" for BUFFER_TTL_SECONDS so outbound packets for it are
	 * buffered rather than mis-routed elsewhere, in case the connection comes back.
	*/
	public static function unregisterConnection(Connection $oConn): void
	{
		foreach (self::$aNetworkConnections as $iNetwork => $oRegistered) {
			if ($oRegistered === $oConn) {
				unset(self::$aNetworkConnections[$iNetwork]);
				self::$aRecentlyDown[$iNetwork] = time();
				if (isset(self::$oLogger)) {
					self::$oLogger->info("RemoteBridge: network {$iNetwork} no longer reachable (connection closed)");
				}
			}
		}

		foreach (self::$aPendingAckRelay as $sKey => $oRegistered) {
			if ($oRegistered === $oConn) {
				unset(self::$aPendingAckRelay[$sKey]);
			}
		}
	}

	/**
	 * Buffers an outbound packet for a network whose connection is currently down, so it can
	 * be delivered if the connection comes back within BUFFER_TTL_SECONDS. Oldest packets are
	 * dropped first, both on expiry and once BUFFER_MAX_PACKETS_PER_NETWORK is exceeded.
	*/
	public static function bufferPacket(int $iNetwork, EconetPacket $oPacket): void
	{
		$iNow = time();
		$aQueue = self::$aOutboundBuffer[$iNetwork] ?? [];
		$aQueue = array_values(array_filter(
			$aQueue,
			fn(array $aEntry) => ($iNow - $aEntry['time']) <= self::BUFFER_TTL_SECONDS
		));

		$aQueue[] = ['packet' => $oPacket, 'time' => $iNow];

		if (count($aQueue) > self::BUFFER_MAX_PACKETS_PER_NETWORK) {
			array_shift($aQueue);
			if (isset(self::$oLogger)) {
				self::$oLogger->warning(
					"RemoteBridge: outbound buffer for network {$iNetwork} full, dropping oldest packet"
				);
			}
		}

		self::$aOutboundBuffer[$iNetwork] = $aQueue;
	}

	/**
	 * Sends any buffered, still-fresh packets for a network through its newly (re-)authenticated
	 * connection, and discards whatever has gone stale. Called whenever a connection announces
	 * (or re-announces) the networks it serves.
	*/
	private static function flushBuffer(int $iNetwork, Connection $oConn): void
	{
		if (empty(self::$aOutboundBuffer[$iNetwork])) {
			return;
		}

		$iNow = time();
		$iSent = 0;
		$iExpired = 0;
		foreach (self::$aOutboundBuffer[$iNetwork] as $aEntry) {
			if (($iNow - $aEntry['time']) <= self::BUFFER_TTL_SECONDS) {
				$oConn->send($aEntry['packet']);
				$iSent++;
			} else {
				$iExpired++;
			}
		}
		unset(self::$aOutboundBuffer[$iNetwork]);

		if (isset(self::$oLogger) && ($iSent > 0 || $iExpired > 0)) {
			self::$oLogger->info(
				"RemoteBridge: flushed {$iSent} buffered packet(s) for network {$iNetwork} ({$iExpired} expired and dropped)"
			);
		}
	}

	/** @return array<int, array{port:int, secret:string, networks:int[]}> */
	public static function getServerEntries(): array { return self::$aServerEntries; }

	/** @return array<int, array{host:string, port:int, secret:string, networks:int[]}> */
	public static function getClientEntries(): array { return self::$aClientEntries; }

	/** @return int[] */
	public static function getKnownNetworks(): array { return array_keys(self::$aNetworkConnections); }

	/** Reset all state — used only by unit tests. */
	public static function reset(): void
	{
		self::$aServerEntries = [];
		self::$aClientEntries = [];
		self::$aNetworkConnections = [];
		self::$aPendingAckRelay = [];
		self::$aRecentlyDown = [];
		self::$aOutboundBuffer = [];
	}
}
