<?php
/**
 * This file contains the RemoteBridge Map class
 *
 * @author John Brown <john@home-lan.co.uk>
 * @package core
*/
namespace HomeLan\FileStore\RemoteBridge;

use config;

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
 * @package core
*/
class Map
{
	/** @var \Psr\Log\LoggerInterface */
	static private \Psr\Log\LoggerInterface $oLogger;

	/** @var array<int, array{port:int, secret:string, networks:int[]}> */
	static private array $aServerEntries = [];

	/** @var array<int, array{host:string, port:int, secret:string, networks:int[]}> */
	static private array $aClientEntries = [];

	/** @var array<int, Connection> Network number => authenticated Connection */
	static private array $aNetworkConnections = [];

	public static function init(\Psr\Log\LoggerInterface $oLogger, ?string $sMapContent = null): void
	{
		self::$oLogger = $oLogger;
		self::$aServerEntries = [];
		self::$aClientEntries = [];
		self::$aNetworkConnections = [];

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
		return array_key_exists($iNetwork, self::$aNetworkConnections);
	}

	public static function networkToConnection(int $iNetwork): ?Connection
	{
		return self::$aNetworkConnections[$iNetwork] ?? null;
	}

	/**
	 * Registers the networks that a peer has announced as reachable via this connection.
	 * Called when a NETWORKS message is received from the other side of an authenticated connection.
	*/
	public static function registerPeerNetworks(Connection $oConn, array $aNetworks): void
	{
		foreach ($aNetworks as $iNetwork) {
			self::$aNetworkConnections[$iNetwork] = $oConn;
			if (isset(self::$oLogger)) {
				self::$oLogger->info("RemoteBridge: network {$iNetwork} now reachable via remote bridge");
			}
		}
	}

	/**
	 * Removes all routing entries for a connection (called on close/disconnect).
	*/
	public static function unregisterConnection(Connection $oConn): void
	{
		foreach (self::$aNetworkConnections as $iNetwork => $oRegistered) {
			if ($oRegistered === $oConn) {
				unset(self::$aNetworkConnections[$iNetwork]);
				if (isset(self::$oLogger)) {
					self::$oLogger->info("RemoteBridge: network {$iNetwork} no longer reachable (connection closed)");
				}
			}
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
	}
}
