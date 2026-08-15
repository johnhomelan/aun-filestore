<?php

/**
 * This file contains the WebSocketHandler class
 *
 *
 * @author John Brown <john@home-lan.co.uk>
 * @package core
*/
namespace HomeLan\FileStore\Piconet;

use React\Socket\ConnectionInterface;
use React\EventLoop\LoopInterface;

use HomeLan\FileStore\Messages\EconetPacket;
use HomeLan\FileStore\Services\ProviderInterface;
use HomeLan\FileStore\Services\ServiceDispatcher;
use HomeLan\FileStore\Encapsulation\PacketDispatcher;

use HomeLan\FileStore\Piconet\PiconetPacket;
use config;

/**
 * This class deals with taking to the piconet device
 *
 * @package core
 *
 * @phpstan-type QueueEntry array{packet:EconetPacket,retries:int,attempts:int}
 * @phpstan-type AwaitingAckEntry array{dst_station:int,dst_network:int,port:int,flags:int}
*/
class Handler {

	const RECONNECT_DELAY_MIN    = 5;
	const RECONNECT_DELAY_MAX    = 300;
	const RETRY_BACKOFF_SECONDS  = 1;

	// Remote bridge mode relies on the firmware's per-packet source station/network
	// override on the TX command (see _writeOutPkt()), so packets relayed from a remote
	// bridge appear on the physical wire as coming from their true origin rather than
	// from this station. Firmware below this version silently ignores the extra fields,
	// so we refuse to run in remote bridge mode against it.
	const REQUIRED_FIRMWARE_VERSION = '2.1.0';
	const REQUIRED_FIRMWARE_FEATURE = 'overriding the source station/network on a per-packet basis via the TX command';
	const PICONET_FORK_URL          = 'https://github.com/johnhomelan/piconet';
	const PICONET_UPSTREAM_URL      = 'https://github.com/jprayner/piconet';

	private ?ConnectionInterface $oConnection = null;

	private bool $bConnected = false;

	private ?LoopInterface $oLoop = null;

	private ?\Closure $fReconnect = null;

	private int $iReconnectDelay = self::RECONNECT_DELAY_MIN;

	/**
	 * @var array<int, QueueEntry>
	*/
	private array $aQueue = [];

	/**
	 * @var array<int, AwaitingAckEntry>
	*/
	private array $aAwaitingAck = [];

	// Set in onConnect() when remote_bridge_enabled — true until the first STATUS
	// response has been checked against REQUIRED_FIRMWARE_VERSION.
	private bool $bAwaitingFirmwareCheck = false;

	// Set if the board's firmware is too old for remote bridge mode; bringupInterface()
	// is never called and send() drops packets while this is true.
	private bool $bPiconetDisabled = false;

	public function __construct(private readonly \Psr\Log\LoggerInterface $oLogger,  private readonly ServiceDispatcher $oServices, private readonly PacketDispatcher $oPacketDispatcher)
	{
		$this->oLogger->debug("Starting piconet handler");
	}

	public function setLoop(LoopInterface $oLoop): void
	{
		$this->oLoop = $oLoop;
	}

	public function setReconnectCallback(\Closure $fReconnect): void
	{
		$this->fReconnect = $fReconnect;
	}

	public function onOpen(ConnectionInterface $oConnection):void
	{
		$this->oConnection = $oConnection;
		$this->bConnected = true;
		$this->iReconnectDelay = self::RECONNECT_DELAY_MIN;
		$this->bAwaitingFirmwareCheck = false;
		$this->bPiconetDisabled = false;
	}

	public function scheduleReconnect(): void
	{
		if ($this->oLoop === null || $this->fReconnect === null) {
			return;
		}
		$this->oLogger->info("Piconet: will attempt to reconnect in ".$this->iReconnectDelay." seconds");
		$this->oLoop->addTimer($this->iReconnectDelay, $this->fReconnect);
		$this->iReconnectDelay = min($this->iReconnectDelay * 2, self::RECONNECT_DELAY_MAX);
	}

	public function onConnect(): void
	{
		$this->oLogger->debug("Piconet handler: Connected");
		//stream_set_blocking($this->oConnection->stream,true);
		stream_set_write_buffer($this->oConnection->stream, 0); //Turn off the write buffer  @phpstan-ignore-line

		fwrite($this->oConnection->stream,"STATUS\r\r"); //@phpstan-ignore-line
		fflush($this->oConnection->stream); //@phpstan-ignore-line

		if (config::getValueAsBool('remote_bridge_enabled')) {
			// Defer bringing up the interface until the STATUS reply has been checked
			// against REQUIRED_FIRMWARE_VERSION — see decodeMessage()'s STATUS case.
			$this->oLogger->debug("Piconet handler: remote bridge enabled, awaiting STATUS to check firmware version before bringing up interface");
			$this->bAwaitingFirmwareCheck = true;
		} else {
			$this->bringupInterface();
		}
	}

	public function bringupInterface(): void
	{
		$this->oLogger->debug("Piconet handler: Bringing up the interface");
		$this->oLogger->debug("Piconet handler: Set station to ".config::getValueAsString('piconet_station'));
		fwrite($this->oConnection->stream,"SET_STATION ".config::getValueAsString('piconet_station')."\r\r"); //@phpstan-ignore-line
		fflush($this->oConnection->stream); //@phpstan-ignore-line

		if (config::getValueAsBool('remote_bridge_enabled')) {
			$this->oLogger->debug("Piconet handler: Set to monitor mode (remote bridge enabled)");
			fwrite($this->oConnection->stream,"SET_MODE MONITOR\r\r"); //@phpstan-ignore-line
		} else {
			$this->oLogger->debug("Piconet handler: Set to listen mode");
			fwrite($this->oConnection->stream,"SET_MODE LISTEN\r\r"); //@phpstan-ignore-line
		}
		fflush($this->oConnection->stream); //@phpstan-ignore-line

		$this->oLogger->debug("Piconet handler: Interface setup and ready");
	}

	/**
	 * Checks a STATUS response's reported firmware version against
	 * self::REQUIRED_FIRMWARE_VERSION before allowing remote bridge mode to bring up
	 * the interface. If the firmware is missing, too old, or unparseable, the Piconet
	 * interface is disabled (see bPiconetDisabled) rather than run with source
	 * addressing that would silently be wrong.
	 */
	private function _checkFirmwareVersionAndBringUp(string $sMessage): void
	{
		$aParts = preg_split('/\s+/', trim($sMessage));
		$sVersion = $aParts[1] ?? '';

		if ($sVersion === '' || !preg_match('/^\d+\.\d+\.\d+$/', $sVersion)) {
			$this->oLogger->error("Piconet Handler: could not determine firmware version from STATUS response (\"".$sMessage."\"); remote bridge mode requires firmware ".self::REQUIRED_FIRMWARE_VERSION." or greater. Disabling Piconet interface.");
			$this->bPiconetDisabled = true;
			return;
		}

		if (version_compare($sVersion, self::REQUIRED_FIRMWARE_VERSION, '<')) {
			$this->oLogger->error(
				"Piconet Handler: firmware version ".$sVersion." is too old for remote bridge mode. ".
				"Requires version ".self::REQUIRED_FIRMWARE_VERSION." or greater for ".self::REQUIRED_FIRMWARE_FEATURE.". ".
				"Update the firmware from ".self::PICONET_FORK_URL." (not yet merged upstream — check ".self::PICONET_UPSTREAM_URL." too). ".
				"Disabling Piconet interface."
			);
			$this->bPiconetDisabled = true;
			return;
		}

		$this->oLogger->debug("Piconet Handler: firmware version ".$sVersion." meets minimum required version ".self::REQUIRED_FIRMWARE_VERSION." for remote bridge mode");
		$this->bringupInterface();
	}


	public function onClose():void
	{
		$this->oLogger->error("Piconet: device disconnected");
		$this->bConnected = false;

		// Tell the device to stop accepting Econet frames before we lose the connection.
		// write() is a no-op on an already-closed connection so this is safe in both
		// the normal-shutdown and unexpected-disconnect cases.
		if ($this->oConnection !== null) {
			$this->oConnection->write("SET_MODE STOP\r\r");
		}
		$this->oConnection = null;

		// Unblock services waiting on acks. Track which (network, station) pairs have
		// already been cleared so the in-flight packet — which appears in both
		// aAwaitingAck and at the front of aQueue — is only cleared once.
		$aClearedKeys = [];

		foreach ($this->aAwaitingAck as $aAck) {
			$sKey = $aAck['dst_network'] . '.' . $aAck['dst_station'];
			$aClearedKeys[$sKey] = true;
			$this->oServices->clearAckEvent($aAck['dst_network'], $aAck['dst_station']);
		}
		foreach ($this->aQueue as $aEntry) {
			$oPacket = $aEntry['packet'];
			$sKey = $oPacket->getDestinationNetwork() . '.' . $oPacket->getDestinationStation();
			if (!isset($aClearedKeys[$sKey])) {
				$aClearedKeys[$sKey] = true;
				$this->oServices->clearAckEvent($oPacket->getDestinationNetwork(), $oPacket->getDestinationStation());
			}
		}

		$this->aAwaitingAck = [];
		$this->aQueue = [];

		$this->scheduleReconnect();
	}

	public function onMessage(string $sMessage):void
	{
		$aLines = explode("\n", $sMessage);
		foreach ($aLines as $sLine) {
			try {
				$this->decodeMessage($sLine);
			} catch (\Exception $oEx) {
				$this->oLogger->warning("Piconet Handler: Error processing message line: " . $oEx->getMessage());
			}
		}
	}


	public function decodeMessage(string $sMessage):void
	{
		$aMessageParts = explode(" ",$sMessage);
		switch(trim($aMessageParts[0])){
			case 'STATUS':
				$this->oLogger->debug("Piconet Handler: Status is ".$sMessage);
				if ($this->bAwaitingFirmwareCheck) {
					$this->bAwaitingFirmwareCheck = false;
					$this->_checkFirmwareVersionAndBringUp($sMessage);
				}
				break;
			case 'ERROR':
				$this->oLogger->error("Piconet Handler: An error occured (".$sMessage.")");
				break;
			case 'MONITOR':
				break;
			case 'RX_BROADCAST':
			case 'RX_IMMEDIATE':
			case 'RX_TRANSMIT':
				$oPacket = new PiconetPacket();
				try {
					$oPacket->decode($sMessage);
				} catch (\Exception $oEx) {
					$this->oLogger->warning("Piconet Handler: Discarding malformed RX packet: " . $oEx->getMessage());
					break;
				}
				$this->oLogger->debug("Piconet Handler: RX Packet ".$oPacket->toString());

				// In monitor mode (when remote bridge is enabled) filter local-network unicast
				// packets not addressed to our station, and forward inter-network packets via bridge.
				if (config::getValueAsBool('remote_bridge_enabled') && trim($aMessageParts[0]) === 'RX_TRANSMIT') {
					$iDstNet = (int) $oPacket->getDstNetwork();
					$iDstStn = (int) $oPacket->getDstStation();
					$iOurStn = config::getValueAsInt('piconet_station');

					if ($iDstNet === 0) {
						// Local network — ignore unicasts not addressed to our station
						if ($iDstStn !== $iOurStn && $iDstStn !== 255) {
							$this->oLogger->debug("Piconet: monitor — ignoring local unicast for station {$iDstStn}");
							break;
						}
					} else {
						// Non-local network — forward via PacketDispatcher (WebSocket, RemoteBridge, or AUN)
						$this->oLogger->debug("Piconet: monitor — forwarding network {$iDstNet} via PacketDispatcher");
						$this->oPacketDispatcher->sendPacket($oPacket->buildEconetPacket());
						break;
					}
				}

				//Dispatch packet to all the services so the relevant one can deal with it
				$this->oServices->inboundPacket($oPacket);

				//Send any messages for the services
				$aReplies = $this->oServices->getReplies();
				foreach($aReplies as $oReply){
					$this->oPacketDispatcher->sendPacket($oReply);
				}
				break;
			case 'TX_RESULT':
				if (!isset($aMessageParts[1])) {
					$this->oLogger->warning("Piconet Handler: TX_RESULT received with no status code");
					break;
				}
				switch(trim($aMessageParts[1])){
					case 'OK':
						$this->oLogger->debug("Piconet Handler: TX OK");
						$aAck = array_shift($this->aAwaitingAck);
						if (!is_array($aAck)) {
							$this->oLogger->warning("Piconet Handler: TX_RESULT OK with no awaiting ack entry");
							break;
						}
						$this->_unQueue();
						$oPacket = new PiconetPacket();
						$oPacket->makeAck($aAck['dst_network'],$aAck['dst_station'],$aAck['port'],$aAck['flags']);
						$this->oServices->inboundPacket($oPacket);
						$aReplies = $this->oServices->getReplies();
						foreach($aReplies as $oReply){
							$this->oPacketDispatcher->sendPacket($oReply);
						}
						break;
					case 'UNINITIALISED':
					case 'OVERFLOW':
					case 'UNDERRUN':
					case 'LINE_JAMMED':
					case 'NO_SCOUT_ACK':
					case 'NO_DATA_ACK':
					case 'TIMEOUT':
					case 'MISC':
						$aAck = array_shift($this->aAwaitingAck);
						if (!is_array($aAck)) {
							$this->oLogger->warning("Piconet Handler: TX_RESULT " . trim($aMessageParts[1]) . " with no awaiting ack entry");
							break;
						}
						$this->oLogger->info("Piconet Handler: TX failed with error ".trim($aMessageParts[1]));
						// If the queue still holds a retry entry (retries > 0 when it was
						// last run), schedule the retry with a short backoff. Otherwise
						// retries are exhausted and we clear the service ack event.
						$bHasRetry = count($this->aQueue) > 0;
						if ($bHasRetry) {
							if ($this->oLoop !== null) {
								$this->oLoop->addTimer(self::RETRY_BACKOFF_SECONDS, function () {
									$this->_runQueue();
								});
							} else {
								$this->_runQueue();
							}
						} else {
							$this->oServices->clearAckEvent($aAck['dst_network'],$aAck['dst_station']);
						}
						break;
					case 'UNEXPECTED':
						$aAck = array_shift($this->aAwaitingAck);
						if (!is_array($aAck)) {
							$this->oLogger->warning("Piconet Handler: TX_RESULT UNEXPECTED with no awaiting ack entry");
							break;
						}
						$this->oLogger->error("Piconet Handler: Internal interface error while transmitting (UNEXPECTED), message: ".trim($aMessageParts[1]));
						// No retry on internal errors — clear ack event immediately.
						$this->oServices->clearAckEvent($aAck['dst_network'],$aAck['dst_station']);
						break;
					default:
						$this->oLogger->warning("Piconet Handler: Ignoring unknown TX_RESULT code: " . trim($aMessageParts[1]));
						break;
				}
				break;
			default:
				$this->oLogger->debug("Piconet Handler: Ignoring unknown message type: " . trim($aMessageParts[0]));
				break;
		}
	}

	public function onError(\Exception $oError):void
	{
		$this->oLogger->error("Piconet: device error - ".$oError->getMessage());
	}

	public function send(EconetPacket $oPacket, int $iRetries = 3):void
	{
		if ($this->bPiconetDisabled) {
			$this->oLogger->warning("Piconet: dropping packet to ".$oPacket->getDestinationNetwork().".".$oPacket->getDestinationStation()." — Piconet interface disabled (firmware too old for remote bridge mode)");
			return;
		}
		if (!$this->bConnected) {
			$this->oLogger->warning("Piconet: dropping packet to ".$oPacket->getDestinationNetwork().".".$oPacket->getDestinationStation()." — device not connected");
			return;
		}
		$this->oLogger->debug("Piconet Handler: Sending packet to queue");
		$this->aQueue[] = ['packet'=>$oPacket,'retries'=>$iRetries,'attempts'=>0];
		if (count($this->aQueue)==1){
			$this->_runQueue();
		}
	}
	private function _runQueue():void
	{
		$this->oLogger->debug("Piconet Handler: Running Queue");
		if(count($this->aQueue)>0){
			$aQueueEntry = array_shift($this->aQueue);
			if($aQueueEntry['retries']>0){
				//More re-tires left re-queue
				$aQueueEntry['retries'] = $aQueueEntry['retries']-1;
				$aQueueEntry['attempts'] = $aQueueEntry['attempts']+1;
				array_unshift($this->aQueue,$aQueueEntry);
				$this->oLogger->debug("Piconet Handler: ".$aQueueEntry['retries']." retires left, ".$aQueueEntry['attempts']." attempts made.");
			}
			$this->_writeOutPkt($aQueueEntry['packet']);
		}else{
			$this->oLogger->debug("Piconet Handler: No packets in Queue");
		}
	}

	private function _unQueue():void
	{
		$this->oLogger->debug("Piconet Handler: Dequeuing packet due to scout ack");
		array_shift($this->aQueue);
		$this->_runQueue();
	}
	private function _writeOutPkt(EconetPacket $oPacket): void
	{
		if ($this->oConnection === null) {
			$this->oLogger->warning("Piconet Handler: Cannot transmit — no active connection");
			return;
		}
		$iDstNetwork = $oPacket->getDestinationNetwork();
		//The local network is
		if($iDstNetwork == config::getValueAsInt('piconet_local_network')){
			$iDstNetwork = 0;
		}
		$sData = $oPacket->getData() ?? '';
		switch($oPacket->getDestinationStation()){
			case 255:
				$this->oLogger->debug("Piconet Handler: Sending broadcast packet (".base64_encode($sData).")");
				fwrite($this->oConnection->stream,"BCAST ".base64_encode($sData)."\r\r"); //@phpstan-ignore-line
				fflush($this->oConnection->stream); //@phpstan-ignore-line
				break;
			default:
				$this->oLogger->debug("Piconet Handler: Sending unicast packet to station ".$oPacket->getDestinationStation()." network ".$iDstNetwork." port ".$oPacket->getPort()." packet ".base64_encode($sData));
				$this->aAwaitingAck[] = ['dst_station'=>$oPacket->getDestinationStation(),'dst_network'=>$oPacket->getDestinationNetwork(),'port'=>$oPacket->getPort(),'flags'=>$oPacket->getFlags()];

				$sTxCommand = "TX ".$oPacket->getDestinationStation()." ".$iDstNetwork." ".$oPacket->getFlags()." ".$oPacket->getPort()." ".base64_encode($sData);

				// When remote bridge mode is enabled, use the updated TX form and override
				// the source station/network on this transmission so a packet relayed from
				// a remote bridge appears on the physical wire as coming from its true
				// origin, not from this station (requires firmware
				// self::REQUIRED_FIRMWARE_VERSION or greater — see onConnect()/
				// decodeMessage()). Packets with no explicit source — e.g. replies from the
				// local FileServer — fall back to the original TX form so the board uses
				// its own configured station, exactly as before this feature existed.
				$iSrcStation = $oPacket->getSourceStation();
				$iSrcNetwork = $oPacket->getSourceNetwork();
				if (config::getValueAsBool('remote_bridge_enabled') && $iSrcStation !== null && $iSrcNetwork !== null) {
					$sTxCommand .= " ".$iSrcStation." ".$iSrcNetwork;
				}

				fwrite($this->oConnection->stream, $sTxCommand."\r\r"); //@phpstan-ignore-line
				fflush($this->oConnection->stream); //@phpstan-ignore-line

				break;
		}
	}

}
