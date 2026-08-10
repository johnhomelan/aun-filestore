<?php
/**
 * This file contains the RemoteBridge Connection class
 *
 * @author John Brown <john@home-lan.co.uk>
 * @package core
*/
namespace HomeLan\FileStore\RemoteBridge;

use React\Socket\ConnectionInterface;
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
 *   SEND <dst_net> <dst_stn> <src_net> <src_stn> <port> <flags> <base64_data>
 *
 * @package core
*/
class Connection
{
	/** Protocol versions this implementation supports, in ascending order. */
	public const SUPPORTED_VERSIONS = ['1.0'];

	private const STATE_WAITING_HELLO    = 'WAITING_HELLO';
	private const STATE_CHALLENGING      = 'CHALLENGING';
	private const STATE_HELLO_SENT       = 'HELLO_SENT';
	private const STATE_WAITING_AUTH_OK  = 'WAITING_AUTH_OK';
	private const STATE_AUTHENTICATED    = 'AUTHENTICATED';
	private const STATE_CLOSED           = 'CLOSED';

	private string $sState;
	private string $sNonce = '';
	private int $iTimestamp = 0;
	private string $sBuffer = '';
	private array $aPeerNetworks = [];
	private string $sProtocolVersion = '';
	private array $aSupportedVersions;

	/**
	 * @param string    $sRole              'server' or 'client'
	 * @param string    $sSecret            Shared HMAC secret
	 * @param int[]     $aLocalNetworks     Networks this side serves locally (announced after auth)
	 * @param \Closure  $fOnPacket          Called with BridgePacket when an authenticated SEND arrives
	 * @param string[]|null $aSupportedVersions Protocol versions to advertise (null = SUPPORTED_VERSIONS)
	*/
	public function __construct(
		private readonly \Psr\Log\LoggerInterface $oLogger,
		private readonly ConnectionInterface $oTcpConn,
		private readonly string $sRole,
		private readonly string $sSecret,
		private readonly array $aLocalNetworks,
		private readonly \Closure $fOnPacket,
		?array $aSupportedVersions = null,
	) {
		$this->aSupportedVersions = $aSupportedVersions ?? self::SUPPORTED_VERSIONS;

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
				array_map('intval', explode(',', trim($sArgs))),
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
			$oPkt = BridgePacket::fromLine($sLine);
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
			($this->fOnPacket)($oPkt);
		}
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
		$aClientVersions = isset($aParts[1]) ? array_map('trim', explode(',', $aParts[1])) : ['1.0'];

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
		$this->oLogger->info("RemoteBridge: authenticated with server");
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
		$this->oTcpConn->write(BridgePacket::encode($oPacket));
	}

	public function onClose(): void
	{
		if ($this->sState === self::STATE_CLOSED) {
			return;
		}
		$this->sState = self::STATE_CLOSED;
		Map::unregisterConnection($this);
		$this->oLogger->info("RemoteBridge[{$this->sRole}]: connection closed");
	}

	public function isAuthenticated(): bool
	{
		return $this->sState === self::STATE_AUTHENTICATED;
	}

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
			array_map('trim', $aClientVersions),
			array_map('trim', $aServerVersions)
		);
		if (empty($aCommon)) {
			return null;
		}
		usort($aCommon, 'version_compare');
		return end($aCommon);
	}
}
