<?php

/**
 * This file contains the WebSocketHandler class
 *
 *
 * @author John Brown <john@home-lan.co.uk>
 * @package core
*/
namespace HomeLan\FileStore\WebSocket;

use Ratchet\MessageComponentInterface;
use Ratchet\ConnectionInterface;

use HomeLan\FileStore\Messages\EconetPacket; 
use HomeLan\FileStore\Aun\AunPacket; 
use HomeLan\FileStore\Aun\Map as AunMap; 
use HomeLan\FileStore\Services\ProviderInterface;
use HomeLan\FileStore\Services\ServiceDispatcher;
use HomeLan\FileStore\Encapsulation\PacketDispatcher;

use HomeLan\FileStore\WebSocket\Map as WebSocketMap;
use HomeLan\FileStore\WebSocket\JsonPacket;

use config;

/**
 * This class deals with taking data submitted via websocket and passing it to the services
 *
 * @package core
*/
class Handler implements MessageComponentInterface {

	private int $iConnectionSequence = 0;

	/** @var \SplObjectStorage<ConnectionInterface, int> */
	private readonly \SplObjectStorage $oConnections;

	/**
	 * Per-connection sliding dedup window: last SEQ_WINDOW_SIZE sequence numbers per connection.
	 * Keyed by spl_object_id of the ConnectionInterface.
	 * @var array<int, int[]>
	 */
	private array $aSeqWindows = [];

	private const SEQ_WINDOW_SIZE = 8;


	public function __construct(private readonly \Psr\Log\LoggerInterface $oLogger,  private readonly ServiceDispatcher $oServices, private readonly PacketDispatcher $oPacketDispatcher) 
	{
		$this->oLogger->debug("Starting websocket handler");
		$this->oConnections = new \SplObjectStorage;
	}

	public function onOpen(ConnectionInterface $oConnection):void
	{
		$this->iConnectionSequence++;
		$this->oConnections->attach($oConnection,$this->iConnectionSequence);
	}

	public function onClose(ConnectionInterface $oConnection):void
	{
		//Logout of the filestore
		//@TODO The logout needs implmenting, or security contexts will leak which is bad TM

		//Free the connection in the map
		WebSocketMap::freeAddress($oConnection);

		//Remove the connection from connections object store
		$this->oConnections->detach($oConnection);

		//Free the dedup window for this connection
		unset($this->aSeqWindows[spl_object_id($oConnection)]);
	}

	public function onMessage(ConnectionInterface $oConnection, $sMessage):void
	{
		try {
			$oJsonMessage = new JsonPacket($oConnection);
			$oJsonMessage->decode($sMessage);
		} catch (\Exception $oEx) {
			$this->oLogger->warning("websocket: Discarding malformed message: " . $oEx->getMessage());
			return;
		}

		switch($oJsonMessage->getType()){
			case 'pkt':
				$iSeq    = $oJsonMessage->getSequence();
				$iConnId = spl_object_id($oConnection);

				//ACK the sender immediately regardless of destination (store-and-forward semantics)
				$sAck = $oJsonMessage->buildAck();
				$oConnection->send($sAck);

				//Duplicate retransmission — re-ack was sufficient, discard
				if ($this->isDuplicate($iConnId, $iSeq)) {
					$this->oLogger->debug("websocket: Duplicate packet seq {$iSeq}, dropping after re-ack");
					return;
				}
				$this->recordSeq($iConnId, $iSeq);

				if (
					$oJsonMessage->getDstNetwork()==config::getValue('websocket_network_address') AND
					$oJsonMessage->getDstStation()==config::getValue('websocket_station_address')
				){
					//We are the target — dispatch to local services
					$this->oLogger->debug("websocket: Sending Ack packet for pkt message");
					$this->oServices->inboundPacket($oJsonMessage);

					//Send any messages from the services
					$aReplies = $this->oServices->getReplies();
					foreach($aReplies as $oReply){
						$this->oPacketDispatcher->sendPacket($oReply);
					}
				} else {
					//Transit packet — forward to the appropriate encapsulation
					$this->oLogger->debug("websocket: Forwarding transit pkt to {$oJsonMessage->getDstNetwork()}.{$oJsonMessage->getDstStation()}");
					$this->oPacketDispatcher->sendPacket($oJsonMessage->buildEconetPacket());
				}
				break;
			case 'ctrl':
				//Build the response to the control message
				$sAck = $oJsonMessage->buildAck();
				$this->oLogger->debug("websocket: Sending Ack packet for ctrl message");
				$oConnection->send($sAck);
				break;
			default:
				$this->oLogger->warning("websocket: Ignoring unknown message type: " . $oJsonMessage->getType());
				return;
		}
	}

	public function onError(ConnectionInterface $oConnection, \Exception $oError):void
	{
		//Remove the connection from connections object store
		$this->oConnections->detach($oConnection);

		//Need to logout of the filestore
		//@TODO The logout needs implmenting, or security contexts will leak which is bad TM

		//Free the connection in the map
		WebSocketMap::freeAddress($oConnection);

		//Free the dedup window for this connection
		unset($this->aSeqWindows[spl_object_id($oConnection)]);

		//Close the connection
		$oConnection->close();
	}

	private function isDuplicate(int $iConnId, int $iSeq): bool
	{
		if (!isset($this->aSeqWindows[$iConnId])) {
			return false;
		}
		return in_array($iSeq, $this->aSeqWindows[$iConnId], true);
	}

	private function recordSeq(int $iConnId, int $iSeq): void
	{
		if (!isset($this->aSeqWindows[$iConnId])) {
			$this->aSeqWindows[$iConnId] = [];
		}
		$this->aSeqWindows[$iConnId][] = $iSeq;
		if (count($this->aSeqWindows[$iConnId]) > self::SEQ_WINDOW_SIZE) {
			array_shift($this->aSeqWindows[$iConnId]);
		}
	}
}	
