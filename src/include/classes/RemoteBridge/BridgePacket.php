<?php
/**
 * This file contains the BridgePacket class
 *
 * @author John Brown <john@home-lan.co.uk>
 * @package core
*/
namespace HomeLan\FileStore\RemoteBridge;

use HomeLan\FileStore\Messages\EconetPacket;
use HomeLan\FileStore\Encapsulation\EncapsulationInterface;

/**
 * Encapsulates an Econet packet for transmission over a remote bridge TCP connection.
 *
 * Wire format: SEND <dst_net> <dst_stn> <src_net> <src_stn> <port> <flags> <base64_data>\n
 *
 * As of protocol version 1.1, a second, unrelated wire message is also
 * represented by this class — ACK <net> <stn>\n (see makeAck()) — used to
 * relay a real Econet-level ack for a station back across the bridge to
 * whichever side originated the packet being acked. See
 * docs/protocols/remote-bridge.md for the full wire format and the
 * conformance requirements this places on third-party bridge clients.
 *
 * @package core
*/
class BridgePacket implements EncapsulationInterface
{
	private int $iDstNetwork = 0;
	private int $iDstStation = 0;
	private int $iSrcNetwork = 0;
	private int $iSrcStation = 0;
	private int $iPort = 0;
	private int $iFlags = 0;
	private string $sData = '';
	private bool $bIsAck = false;

	public function getPort(): int { return $this->iPort; }
	public function getData(): string { return $this->sData; }
	public function getDstNetwork(): int { return $this->iDstNetwork; }
	public function getDstStation(): int { return $this->iDstStation; }
	public function getSrcNetwork(): int { return $this->iSrcNetwork; }
	public function getSrcStation(): int { return $this->iSrcStation; }
	public function getFlags(): int { return $this->iFlags; }

	public function getPacketType(): string
	{
		if ($this->bIsAck) {
			return 'Ack';
		}
		return ($this->iDstStation === 255) ? 'Broadcast' : 'Unicast';
	}

	/**
	 * Turns this instance into a representation of an incoming ACK <net>
	 * <stn> line — a real Econet-level ack relayed back across the bridge
	 * for a station whose ack-worthy packet originated from the other side.
	 * $iNet/$iStn go into the *source* fields, matching how
	 * ServiceDispatcher::ackEvents() reads a real ack (by the acking
	 * station's own network/station, via getSourceNetwork()/
	 * getSourceStation() on the built EconetPacket) — mirrors
	 * PiconetPacket::makeAck()'s equivalent field mapping.
	*/
	public function makeAck(int $iNet, int $iStn): void
	{
		$this->bIsAck      = true;
		$this->iSrcNetwork = $iNet;
		$this->iSrcStation = $iStn;
		$this->iDstNetwork = 0;
		$this->iDstStation = 0;
		$this->iPort       = 0;
		$this->iFlags      = 0;
		$this->sData       = '';
	}

	public function decode(string $sBinaryString): void
	{
		$oParsed = self::fromLine($sBinaryString);
		if ($oParsed === null) {
			throw new \RuntimeException('Invalid remote bridge SEND line: ' . $sBinaryString);
		}
		$this->iDstNetwork = $oParsed->iDstNetwork;
		$this->iDstStation = $oParsed->iDstStation;
		$this->iSrcNetwork = $oParsed->iSrcNetwork;
		$this->iSrcStation = $oParsed->iSrcStation;
		$this->iPort       = $oParsed->iPort;
		$this->iFlags      = $oParsed->iFlags;
		$this->sData       = $oParsed->sData;
	}

	/**
	 * Parses a SEND line from the wire into a BridgePacket.
	 * Returns null if the line is malformed.
	*/
	public static function fromLine(string $sLine): ?self
	{
		// Use a limit of 8 so that any spaces inside base64 (impossible, but defensive) stay in field 7.
		$aParts = explode(' ', trim($sLine), 8);
		$iCount = count($aParts);
		// 7 fields = empty payload (base64_encode('') produces ''), 8 fields = non-empty payload.
		if (($iCount !== 7 && $iCount !== 8) || $aParts[0] !== 'SEND') {
			return null;
		}
		$oPkt = new self();
		$oPkt->iDstNetwork = (int) $aParts[1];
		$oPkt->iDstStation = (int) $aParts[2];
		$oPkt->iSrcNetwork = (int) $aParts[3];
		$oPkt->iSrcStation = (int) $aParts[4];
		$oPkt->iPort       = (int) $aParts[5];
		$oPkt->iFlags      = (int) $aParts[6];
		if ($iCount === 8 && trim($aParts[7]) !== '') {
			$sDecoded = base64_decode(trim($aParts[7]), true);
			$oPkt->sData = ($sDecoded !== false) ? $sDecoded : '';
		} else {
			$oPkt->sData = '';
		}
		return $oPkt;
	}

	/**
	 * Encodes an EconetPacket as a SEND line ready for writing to the TCP stream.
	*/
	public static function encode(EconetPacket $oPacket): string
	{
		return sprintf(
			"SEND %d %d %d %d %d %d %s\n",
			$oPacket->getDestinationNetwork(),
			$oPacket->getDestinationStation(),
			$oPacket->getSourceNetwork(),
			$oPacket->getSourceStation(),
			$oPacket->getPort(),
			$oPacket->getFlags(),
			base64_encode((string) $oPacket->getData())
		);
	}

	/**
	 * Parses an ACK <net> <stn> line (protocol version 1.1+) into a
	 * BridgePacket. Returns null if the line is malformed.
	*/
	public static function fromAckLine(string $sLine): ?self
	{
		$aParts = explode(' ', trim($sLine));
		if (count($aParts) !== 3 || $aParts[0] !== 'ACK') {
			return null;
		}
		$oPkt = new self();
		$oPkt->makeAck((int) $aParts[1], (int) $aParts[2]);
		return $oPkt;
	}

	/**
	 * Encodes an ack for (net, stn) as an ACK line ready for writing to the
	 * TCP stream. Only meaningful to send once both sides have negotiated
	 * protocol version 1.1 or later — see Connection::sendAck().
	*/
	public static function encodeAck(int $iNet, int $iStn): string
	{
		return sprintf("ACK %d %d\n", $iNet, $iStn);
	}

	public function buildEconetPacket(): EconetPacket
	{
		$oEconetPacket = new EconetPacket();
		$oEconetPacket->setDestinationNetwork($this->iDstNetwork);
		$oEconetPacket->setDestinationstation($this->iDstStation);
		$oEconetPacket->setSourceNetwork($this->iSrcNetwork);
		$oEconetPacket->setSourceStation($this->iSrcStation);
		$oEconetPacket->setPort($this->iPort);
		$oEconetPacket->setFlags($this->iFlags);
		$oEconetPacket->setData($this->sData);
		return $oEconetPacket;
	}

	public function toString(): string
	{
		return sprintf(
			'RemoteBridge dst:%d.%d src:%d.%d port:%d flags:%d',
			$this->iDstNetwork, $this->iDstStation,
			$this->iSrcNetwork, $this->iSrcStation,
			$this->iPort, $this->iFlags
		);
	}
}
