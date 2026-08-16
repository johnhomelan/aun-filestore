<?php
/**
 * This file contains the RemoteBridge ClientHandler class
 *
 * @author John Brown <john@home-lan.co.uk>
 * @package core
*/
namespace HomeLan\FileStore\RemoteBridge;

use React\Socket\ConnectionInterface;
use React\EventLoop\LoopInterface;
use HomeLan\FileStore\Services\ServiceDispatcher;
use HomeLan\FileStore\Encapsulation\PacketDispatcher;
use config;

/**
 * Initiates outgoing remote bridge TCP connections for all CLIENT entries in the map file.
 *
 * Implements exponential-backoff reconnection (5 s → 10 s → 20 s … capped at 300 s)
 * so a temporary server outage does not flood the logs.
 *
 * @package core
*/
class ClientHandler
{
	private const int RECONNECT_DELAY_MIN = 5;
	private const int RECONNECT_DELAY_MAX = 300;

	/** @var array<string, array{entry: array{host: string, port: int, secret: string, networks: int[]}, delay: int}> keyed by 'host:port' */
	private array $aEntryState = [];

	public function __construct(
		private readonly \Psr\Log\LoggerInterface $oLogger,
		private readonly ServiceDispatcher $oServices,
		private readonly PacketDispatcher $oPacketDispatcher,
		private readonly LoopInterface $oLoop,
	) {}

	public function start(): void
	{
		foreach (Map::getClientEntries() as $aEntry) {
			$sKey = $aEntry['host'] . ':' . $aEntry['port'];
			$this->aEntryState[$sKey] = ['entry' => $aEntry, 'delay' => self::RECONNECT_DELAY_MIN];
			$this->connect($sKey);
		}
	}

	private function connect(string $sKey): void
	{
		$aState = &$this->aEntryState[$sKey];
		$aEntry = $aState['entry'];

		$oConnector = new \React\Socket\Connector($this->oLoop);
		$this->oLogger->info("RemoteBridge: connecting to {$sKey}");

		$oConnector->connect("tcp://{$aEntry['host']}:{$aEntry['port']}")->then(
			function (ConnectionInterface $oTcpConn) use ($sKey, &$aState, $aEntry) {
				$aState['delay'] = self::RECONNECT_DELAY_MIN;
				$this->oLogger->info("RemoteBridge: connected to {$sKey}");

				$oServices = $this->oServices;
				$oPacketDispatcher = $this->oPacketDispatcher;

				$oConn = new Connection(
					$this->oLogger,
					$oTcpConn,
					'client',
					$aEntry['secret'],
					$aEntry['networks'],
					function (BridgePacket $oPkt) use ($oServices, $oPacketDispatcher) {
						// Only forward to the local interface if not addressed to our own virtual
						// service station — packets for that station are handled exclusively by
						// ServiceDispatcher below (no physical Piconet device to deliver to).
						$iLocalNet = config::getValueAsInt('piconet_local_network');
						$iOurStn   = config::getValueAsInt('piconet_station');
						$bForLocalService = $iOurStn > 0
							&& $oPkt->getDstStation() === $iOurStn
							&& ($oPkt->getDstNetwork() === $iLocalNet || $oPkt->getDstNetwork() === 0);

						if (!$bForLocalService) {
							$oPacketDispatcher->sendPacket($oPkt->buildEconetPacket());
						}

						// Offer to service providers so local services (FS, etc.) can respond.
						$oServices->inboundPacket($oPkt);
						$aReplies = $oServices->getReplies();
						foreach ($aReplies as $oReply) {
							$oPacketDispatcher->sendPacket($oReply);
						}
					},
					function (BridgePacket $oAckPkt) use ($oServices, $oPacketDispatcher) {
						// An ACK <net> <stn> line (protocol 1.1+) is always for our own
						// ServiceDispatcher — never forwarded, never gated by
						// aLocalNetworks, unlike a SEND. See Connection::handleAuthenticated().
						$oServices->inboundPacket($oAckPkt);
						$aReplies = $oServices->getReplies();
						foreach ($aReplies as $oReply) {
							$oPacketDispatcher->sendPacket($oReply);
						}
					},
					null,
					$this->oLoop,
				);

				$oTcpConn->on('data', $oConn->onData(...));
				$oTcpConn->on('close', function () use ($oConn, $sKey) {
					$oConn->onClose();
					$this->scheduleReconnect($sKey);
				});
				$oTcpConn->on('error', function (\Exception $oEx) use ($sKey) {
					$this->oLogger->error("RemoteBridge: connection error to {$sKey}: " . $oEx->getMessage());
				});
			},
			function (\Throwable $oEx) use ($sKey) {
				$this->oLogger->error("RemoteBridge: failed to connect to {$sKey}: " . $oEx->getMessage());
				$this->scheduleReconnect($sKey);
			}
		);
	}

	private function scheduleReconnect(string $sKey): void
	{
		$aState = &$this->aEntryState[$sKey];
		$iDelay = $aState['delay'];
		$this->oLogger->info("RemoteBridge: will retry {$sKey} in {$iDelay} seconds");
		$this->oLoop->addTimer($iDelay, function () use ($sKey) {
			$this->connect($sKey);
		});
		$aState['delay'] = min($iDelay * 2, self::RECONNECT_DELAY_MAX);
	}
}
