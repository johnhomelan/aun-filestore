<?php

namespace HomeLan\FileStore\RemoteProvider\Messages;

/**
 * A Remote Provider Protocol frame (JSON over WebSocket).
 *
 * The Remote Provider Protocol relays full Econet packets between a ProviderInterface occupying
 * ports on filestored's ServiceDispatcher and the process actually implementing that provider,
 * running elsewhere - see docs/protocols/remote-provider.md. It is the same shape as the Remote
 * Socket Protocol (docs/protocols/remote-socket.md) one layer up the stack: that one relays raw
 * UDP traffic under IPv4, this one relays Econet packets under ServiceDispatcher.
 *
 * Handshake and heartbeat frames (hello/hello_ok/version_reject/auth_fail/ping/pong) are
 * identical in shape and purpose to the Remote Socket Protocol's. `register` declares which
 * Econet port numbers the connecting side wants to provide service for, out of the superset the
 * listening side has statically reserved (see ProxyProvider); `packet` carries one Econet packet
 * in either direction - filestored delivering an inbound unicast/broadcast to the remote
 * provider, or the remote provider sending a reply/unsolicited packet back.
 */
class Frame
{
    public const string TYPE_HELLO               = 'hello';
    public const string TYPE_HELLO_OK            = 'hello_ok';
    public const string TYPE_VERSION_REJECT      = 'version_reject';
    public const string TYPE_AUTH_FAIL           = 'auth_fail';
    public const string TYPE_REGISTER            = 'register';
    public const string TYPE_REGISTER_OK         = 'register_ok';
    public const string TYPE_REGISTER_FAIL       = 'register_fail';
    public const string TYPE_PACKET              = 'packet';
    public const string TYPE_CLAIM_STREAM        = 'claim_stream';
    public const string TYPE_STREAM_CLAIMED      = 'stream_claimed';
    public const string TYPE_STREAM_CLAIM_FAILED = 'stream_claim_failed';
    public const string TYPE_ACK                 = 'ack';
    public const string TYPE_PING                = 'ping';
    public const string TYPE_PONG                = 'pong';

    public const string KIND_UNICAST   = 'unicast';
    public const string KIND_BROADCAST = 'broadcast';

    /**
     * This protocol is unpublished: version 1.0 is edited in place rather than incremented
     * while under development. A new version number is only cut once it has been published
     * somewhere external to this project.
     */
    public const string PROTOCOL_VERSION = '1.0';

    /** @param array<string, mixed> $aFields */
    private function __construct(private readonly string $sType, private readonly array $aFields)
    {
    }

    /** @param list<string> $aVersions */
    public static function hello(string $sSecret, array $aVersions = [self::PROTOCOL_VERSION]): self
    {
        return new self(self::TYPE_HELLO, ['versions' => $aVersions, 'secret' => $sSecret]);
    }

    public static function helloOk(string $sVersion): self
    {
        return new self(self::TYPE_HELLO_OK, ['version' => $sVersion]);
    }

    /** @param list<string> $aSupportedVersions */
    public static function versionReject(array $aSupportedVersions): self
    {
        return new self(self::TYPE_VERSION_REJECT, ['versions' => $aSupportedVersions]);
    }

    public static function authFail(): self
    {
        return new self(self::TYPE_AUTH_FAIL, []);
    }

    /** @param list<int> $aPorts */
    public static function register(array $aPorts): self
    {
        return new self(self::TYPE_REGISTER, ['ports' => $aPorts]);
    }

    public static function registerOk(int $iPort): self
    {
        return new self(self::TYPE_REGISTER_OK, ['port' => $iPort]);
    }

    public static function registerFail(int $iPort, string $sReason): self
    {
        return new self(self::TYPE_REGISTER_FAIL, ['port' => $iPort, 'reason' => $sReason]);
    }

    /**
     * $sKind is "unicast" or "broadcast" - see KIND_* constants. It only drives routing on the
     * filestored->remote-provider leg (which of unicastPacketIn()/broadcastPacketIn() the Host
     * calls); on the reply leg it is carried for symmetry but not otherwise interpreted -
     * ProxyProvider queues whatever EconetPacket the fields describe exactly as any other
     * provider's reply, and AUN/broadcast framing is already implied by a destination station of
     * 255 (see EconetPacket::_getAunRaw()).
     */
    public static function packet(
        string $sKind,
        int $iSrcNet,
        int $iSrcStn,
        int $iDstNet,
        int $iDstStn,
        int $iPort,
        int $iFlags,
        string $sPayload,
    ): self {
        return new self(self::TYPE_PACKET, [
            'kind'    => $sKind,
            'srcNet'  => $iSrcNet,
            'srcStn'  => $iSrcStn,
            'dstNet'  => $iDstNet,
            'dstStn'  => $iDstStn,
            'port'    => $iPort,
            'flags'   => $iFlags,
            'payload' => base64_encode($sPayload),
        ]);
    }

    /**
     * Asks the listening side to claim a stream port on the requester's behalf (see
     * ServiceDispatcher::claimStreamPort() and docs/protocols/remote-provider.md § Stream
     * Claims). $sRequestId correlates the eventual stream_claimed/stream_claim_failed reply,
     * since more than one claim can be in flight on a connection at once.
     */
    public static function claimStream(string $sRequestId, int $iTimeout): self
    {
        return new self(self::TYPE_CLAIM_STREAM, ['requestId' => $sRequestId, 'timeout' => $iTimeout]);
    }

    public static function streamClaimed(string $sRequestId, int $iPort): self
    {
        return new self(self::TYPE_STREAM_CLAIMED, ['requestId' => $sRequestId, 'port' => $iPort]);
    }

    public static function streamClaimFailed(string $sRequestId, string $sReason): self
    {
        return new self(self::TYPE_STREAM_CLAIM_FAILED, ['requestId' => $sRequestId, 'reason' => $sReason]);
    }

    /**
     * A real Econet-level ack for (net, stn), relayed from filestored to whichever remote
     * provider host most recently sent a reply there - see AckRelayMap and
     * docs/protocols/remote-provider.md § Ack Relay. $iSeq is null when the encapsulation that
     * observed the real ack had no sequence concept of its own (raw hardware Econet) - see
     * EncapsulationInterface::getSequence().
     */
    public static function ack(int $iNet, int $iStn, ?int $iSeq): self
    {
        return new self(self::TYPE_ACK, ['net' => $iNet, 'stn' => $iStn, 'seq' => $iSeq]);
    }

    public static function ping(): self
    {
        return new self(self::TYPE_PING, []);
    }

    public static function pong(): self
    {
        return new self(self::TYPE_PONG, []);
    }

    public function getType(): string
    {
        return $this->sType;
    }

    /** @return list<string> */
    public function getVersions(): array
    {
        return self::asStringList($this->aFields['versions'] ?? []);
    }

    public function getVersion(): string
    {
        return self::asString($this->aFields['version'] ?? '');
    }

    public function getSecret(): string
    {
        return self::asString($this->aFields['secret'] ?? '');
    }

    /** @return list<int> */
    public function getPorts(): array
    {
        $aPorts = $this->aFields['ports'] ?? [];
        if (!is_array($aPorts)) {
            return [];
        }
        return array_values(array_map(self::asInt(...), $aPorts));
    }

    public function getPort(): int
    {
        return self::asInt($this->aFields['port'] ?? 0);
    }

    public function getReason(): string
    {
        return self::asString($this->aFields['reason'] ?? '');
    }

    public function getKind(): string
    {
        return self::asString($this->aFields['kind'] ?? self::KIND_UNICAST);
    }

    public function getSrcNet(): int
    {
        return self::asInt($this->aFields['srcNet'] ?? 0);
    }

    public function getSrcStn(): int
    {
        return self::asInt($this->aFields['srcStn'] ?? 0);
    }

    public function getDstNet(): int
    {
        return self::asInt($this->aFields['dstNet'] ?? 0);
    }

    public function getDstStn(): int
    {
        return self::asInt($this->aFields['dstStn'] ?? 0);
    }

    public function getFlags(): int
    {
        return self::asInt($this->aFields['flags'] ?? 0);
    }

    public function getPayload(): string
    {
        $sDecoded = base64_decode(self::asString($this->aFields['payload'] ?? ''), true);
        return $sDecoded === false ? '' : $sDecoded;
    }

    public function getRequestId(): string
    {
        return self::asString($this->aFields['requestId'] ?? '');
    }

    public function getTimeout(): int
    {
        return self::asInt($this->aFields['timeout'] ?? 0);
    }

    public function getNet(): int
    {
        return self::asInt($this->aFields['net'] ?? 0);
    }

    public function getStn(): int
    {
        return self::asInt($this->aFields['stn'] ?? 0);
    }

    public function getSeq(): ?int
    {
        $mValue = $this->aFields['seq'] ?? null;
        return $mValue === null ? null : self::asInt($mValue);
    }

    public function encode(): string
    {
        $sJson = json_encode(['type' => $this->sType, ...$this->aFields]);
        return $sJson === false ? '{}' : $sJson;
    }

    public static function decode(string $sJson): self
    {
        $mDecoded = json_decode($sJson, true);
        if (!is_array($mDecoded) || !isset($mDecoded['type']) || !is_string($mDecoded['type'])) {
            throw new \Exception('RemoteProvider: malformed frame');
        }
        $sType = $mDecoded['type'];

        $aFields = [];
        foreach ($mDecoded as $sKey => $mValue) {
            if (is_string($sKey) && $sKey !== 'type') {
                $aFields[$sKey] = $mValue;
            }
        }

        return new self($sType, $aFields);
    }

    private static function asString(mixed $mValue): string
    {
        return is_scalar($mValue) ? (string) $mValue : '';
    }

    private static function asInt(mixed $mValue): int
    {
        return is_scalar($mValue) ? (int) $mValue : 0;
    }

    /** @return list<string> */
    private static function asStringList(mixed $mValue): array
    {
        if (!is_array($mValue)) {
            return [];
        }
        return array_values(array_map(self::asString(...), $mValue));
    }
}
