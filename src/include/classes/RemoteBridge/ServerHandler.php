<?php
/**
 * This file contains the RemoteBridge ServerHandler class
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
 * Listens for incoming remote bridge TCP connections and manages their authentication lifecycle.
 *
 * One ServerHandler instance handles all SERVER entries from the map file, starting a separate
 * TCP listener for each configured port.
 *
 * @package core
*/
class ServerHandler
{
	public function __construct(
		private readonly \Psr\Log\LoggerInterface $oLogger,
		private readonly ServiceDispatcher $oServices,
		private readonly PacketDispatcher $oPacketDispatcher,
	) {}

	/**
	 * Starts a TCP listener for one SERVER map entry.
	 *
	 * @param array{port:int, secret:string, networks:int[]} $aEntry
	*/
	public function start(array $aEntry, LoopInterface $oLoop): void
	{
		$sListenAddress = config::getValueAsString('remote_bridge_server_address');
		$sBindAddress = "{$sListenAddress}:{$aEntry['port']}";

		try {
			$oSocket = new \React\Socket\SocketServer($sBindAddress, [], $oLoop);
		} catch (\Exception $oEx) {
			$this->oLogger->error("RemoteBridge: failed to bind server on {$sBindAddress}: " . $oEx->getMessage());
			return;
		}

		$oSocket->on('connection', function (ConnectionInterface $oTcpConn) use ($aEntry, $oLoop) {
			$sPeer = $oTcpConn->getRemoteAddress() ?? 'unknown';
			$this->oLogger->info("RemoteBridge: incoming connection from {$sPeer}");

			$oServices = $this->oServices;
			$oPacketDispatcher = $this->oPacketDispatcher;

			$oConn = new Connection(
				$this->oLogger,
				$oTcpConn,
				'server',
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
				$oLoop,
			);

			$oTcpConn->on('data', $oConn->onData(...));
			$oTcpConn->on('close', $oConn->onClose(...));
			$oTcpConn->on('error', function (\Exception $oEx) use ($sPeer) {
				$this->oLogger->error("RemoteBridge: connection error from {$sPeer}: " . $oEx->getMessage());
			});
		});

		$oSocket->on('error', function (\Exception $oEx) {
			$this->oLogger->error("RemoteBridge server error: " . $oEx->getMessage());
		});

		$this->oLogger->info("RemoteBridge: server listening on {$sBindAddress} for networks " . implode(',', $aEntry['networks']));
	}
}
