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
 * whichever side originated the packet being acked.
 *
 * As of protocol version 1.2, both messages optionally carry the
 * EconetPacket::getSequence() value of the packet in question — SEND gains a
 * trailing <seq> field ahead of the payload, ACK gains a trailing <seq>
 * field of its own — so ServiceDispatcher::ackEvents() on the *originating*
 * side of a bridge hop can tell a relayed ack apart from a stray one for the
 * same station, exactly as it already can for purely local AUN/WebSocket
 * traffic. Only sent/expected once both sides have negotiated 1.2 — see
 * Connection::sendAck()/send() and the $bHasSeq parameter on fromLine().
 * See docs/protocols/remote-bridge.md for the full wire format and the
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
	private ?int $iSeq = null;

	public function getPort(): int { return $this->iPort; }
	public function getData(): string { return $this->sData; }
	public function getDstNetwork(): int { return $this->iDstNetwork; }
	public function getDstStation(): int { return $this->iDstStation; }
	public function getSrcNetwork(): int { return $this->iSrcNetwork; }
	public function getSrcStation(): int { return $this->iSrcStation; }
	public function getFlags(): int { return $this->iFlags; }

	/** Null for a 1.0/1.1 peer, or a 1.2+ SEND/ACK line that simply omitted it. */
	public function getSequence(): ?int { return $this->iSeq; }

	public function getPacketType(): string
	{
		if ($this->bIsAck) {
			return 'Ack';
		}
		return ($this->iDstStation === 255) ? 'Broadcast' : 'Unicast';
	}

	/**
	 * Turns this instance into a representation of an incoming ACK <net>
	 * <stn> [<seq>] line — a real Econet-level ack relayed back across the
	 * bridge for a station whose ack-worthy packet originated from the other
	 * side. $iNet/$iStn go into the *source* fields, matching how
	 * ServiceDispatcher::ackEvents() reads a real ack (by the acking
	 * station's own network/station, via getSourceNetwork()/
	 * getSourceStation() on the built EconetPacket) — mirrors
	 * PiconetPacket::makeAck()'s equivalent field mapping. $iSeq is the
	 * sequence number of the packet being acked (protocol 1.2+ only, see
	 * Map::rememberAckRelay()); null on a 1.1 connection or if it was never
	 * captured on the way in.
	*/
	public function makeAck(int $iNet, int $iStn, ?int $iSeq = null): void
	{
		$this->bIsAck      = true;
		$this->iSrcNetwork = $iNet;
		$this->iSrcStation = $iStn;
		$this->iDstNetwork = 0;
		$this->iDstStation = 0;
		$this->iPort       = 0;
		$this->iFlags      = 0;
		$this->sData       = '';
		$this->iSeq        = $iSeq;
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
	 *
	 * $bHasSeq must reflect the negotiated protocol version of the connection this line
	 * arrived on (true for 1.2+) — it is not otherwise inferable from the line itself, since
	 * a bare trailing field is ambiguous between "seq, no payload" and "payload, no seq".
	 *
	 * Returns null if the line is malformed.
	*/
	public static function fromLine(string $sLine, bool $bHasSeq = false): ?self
	{
		// Use a limit of 9 so that any spaces inside base64 (impossible, but defensive) stay in the payload field.
		$aParts = explode(' ', trim($sLine), 9);
		$iCount = count($aParts);
		if ($aParts[0] !== 'SEND') {
			return null;
		}
		$oPkt = new self();
		if ($bHasSeq) {
			// 8 fields = empty payload, 9 fields = non-empty payload.
			if ($iCount !== 8 && $iCount !== 9) {
				return null;
			}
			$oPkt->iSeq = (int) $aParts[7];
			$sPayload = $aParts[8] ?? '';
		} else {
			// 7 fields = empty payload, 8 fields = non-empty payload.
			if ($iCount !== 7 && $iCount !== 8) {
				return null;
			}
			$sPayload = $aParts[7] ?? '';
		}
		$oPkt->iDstNetwork = (int) $aParts[1];
		$oPkt->iDstStation = (int) $aParts[2];
		$oPkt->iSrcNetwork = (int) $aParts[3];
		$oPkt->iSrcStation = (int) $aParts[4];
		$oPkt->iPort       = (int) $aParts[5];
		$oPkt->iFlags      = (int) $aParts[6];
		if (trim($sPayload) !== '') {
			$sDecoded = base64_decode(trim($sPayload), true);
			$oPkt->sData = ($sDecoded !== false) ? $sDecoded : '';
		} else {
			$oPkt->sData = '';
		}
		return $oPkt;
	}

	/**
	 * Encodes an EconetPacket as a SEND line ready for writing to the TCP stream.
	 *
	 * $bIncludeSeq must reflect the negotiated protocol version of the destination connection
	 * (true for 1.2+) — sending the extra field to a 1.1 peer would make the line unparsable.
	*/
	public static function encode(EconetPacket $oPacket, bool $bIncludeSeq = false): string
	{
		if ($bIncludeSeq) {
			return sprintf(
				"SEND %d %d %d %d %d %d %d %s\n",
				$oPacket->getDestinationNetwork(),
				$oPacket->getDestinationStation(),
				$oPacket->getSourceNetwork(),
				$oPacket->getSourceStation(),
				$oPacket->getPort(),
				$oPacket->getFlags(),
				$oPacket->getSequence(),
				base64_encode((string) $oPacket->getData())
			);
		}
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
	 * Parses an ACK <net> <stn> [<seq>] line (protocol version 1.1+, <seq> added in 1.2) into
	 * a BridgePacket. Returns null if the line is malformed.
	*/
	public static function fromAckLine(string $sLine): ?self
	{
		$aParts = explode(' ', trim($sLine));
		$iCount = count($aParts);
		if (($iCount !== 3 && $iCount !== 4) || $aParts[0] !== 'ACK') {
			return null;
		}
		$oPkt = new self();
		$oPkt->makeAck((int) $aParts[1], (int) $aParts[2], $iCount === 4 ? (int) $aParts[3] : null);
		return $oPkt;
	}

	/**
	 * Encodes an ack for (net, stn) as an ACK line ready for writing to the
	 * TCP stream. Only meaningful to send once both sides have negotiated
	 * protocol version 1.1 or later — see Connection::sendAck(). $iSeq is
	 * only included (protocol 1.2+) when the caller passes one; omit it for
	 * a 1.1 peer, which would otherwise reject the line as malformed.
	*/
	public static function encodeAck(int $iNet, int $iStn, ?int $iSeq = null): string
	{
		if ($iSeq !== null) {
			return sprintf("ACK %d %d %d\n", $iNet, $iStn, $iSeq);
		}
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

	public function asString(): string
	{
		return sprintf(
			'RemoteBridge dst:%d.%d src:%d.%d port:%d flags:%d',
			$this->iDstNetwork, $this->iDstStation,
			$this->iSrcNetwork, $this->iSrcStation,
			$this->iPort, $this->iFlags
		);
	}
}
