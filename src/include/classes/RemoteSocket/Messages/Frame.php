<?php

namespace HomeLan\FileStore\RemoteSocket\Messages;

/**
 * A Remote Socket Protocol frame (JSON over WebSocket).
 *
 * The Remote Socket Protocol relays arbitrary UDP (and, in future, TCP) traffic arriving at
 * one process's network interface to another process over a WebSocket connection, and its
 * replies back again - ShareFS/Freeway/Access+ over EconetA is its first use, not its purpose.
 * See docs/protocols/remote-socket.md.
 *
 * Every frame is a JSON object with a "type" field. Handshake: client sends `hello` (offered
 * versions + shared secret), server replies `hello_ok` (version_reject on no common version,
 * auth_fail on a bad secret, either way closing the connection). Then the client `register`s
 * the (protocol, port) pairs it wants relayed traffic for; the server replies `register_ok`/
 * `register_fail` per entry. `data` frames carry packets in either direction once registered;
 * `ping`/`pong` are a heartbeat.
 */
class Frame
{
    public const string TYPE_HELLO           = 'hello';
    public const string TYPE_HELLO_OK        = 'hello_ok';
    public const string TYPE_VERSION_REJECT  = 'version_reject';
    public const string TYPE_AUTH_FAIL       = 'auth_fail';
    public const string TYPE_REGISTER        = 'register';
    public const string TYPE_REGISTER_OK     = 'register_ok';
    public const string TYPE_REGISTER_FAIL   = 'register_fail';
    public const string TYPE_DATA            = 'data';
    public const string TYPE_PING            = 'ping';
    public const string TYPE_PONG            = 'pong';

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

    /** @param list<array{protocol:string, port:int}> $aServices */
    public static function register(array $aServices): self
    {
        return new self(self::TYPE_REGISTER, ['services' => $aServices]);
    }

    public static function registerOk(string $sProtocol, int $iPort): self
    {
        return new self(self::TYPE_REGISTER_OK, ['protocol' => $sProtocol, 'port' => $iPort]);
    }

    public static function registerFail(string $sProtocol, int $iPort, string $sReason): self
    {
        return new self(self::TYPE_REGISTER_FAIL, ['protocol' => $sProtocol, 'port' => $iPort, 'reason' => $sReason]);
    }

    /**
     * `localAddr`/`localPort` is always the interface-facing side (whichever process owns the
     * network interface the packet arrived at); `remoteAddr`/`remotePort` is always the far
     * side. A reply going the other way echoes the same four fields, exactly as a real bound
     * UDP socket's own sendto()/recvfrom() pair already would.
     *
     * `streamId` is unused (null) for UDP - reserved so a future TCP mode, which needs to tell
     * multiple concurrent connections between the same address pair apart, doesn't need a
     * breaking wire change to add it.
     */
    public static function data(
        string $sProtocol,
        string $sLocalAddr,
        int $iLocalPort,
        string $sRemoteAddr,
        int $iRemotePort,
        string $sPayload,
        ?string $sStreamId = null,
    ): self {
        return new self(self::TYPE_DATA, [
            'protocol'   => $sProtocol,
            'localAddr'  => $sLocalAddr,
            'localPort'  => $iLocalPort,
            'remoteAddr' => $sRemoteAddr,
            'remotePort' => $iRemotePort,
            'streamId'   => $sStreamId,
            'payload'    => base64_encode($sPayload),
        ]);
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

    /** @return list<array{protocol:string, port:int}> */
    public function getServices(): array
    {
        $aReturn = [];
        $aServices = $this->aFields['services'] ?? [];
        if (!is_array($aServices)) {
            return [];
        }
        foreach ($aServices as $aService) {
            if (!is_array($aService) || !isset($aService['protocol'], $aService['port'])) {
                continue;
            }
            $aReturn[] = ['protocol' => self::asString($aService['protocol']), 'port' => self::asInt($aService['port'])];
        }
        return $aReturn;
    }

    public function getProtocol(): string
    {
        return self::asString($this->aFields['protocol'] ?? '');
    }

    public function getPort(): int
    {
        return self::asInt($this->aFields['port'] ?? 0);
    }

    public function getReason(): string
    {
        return self::asString($this->aFields['reason'] ?? '');
    }

    public function getLocalAddr(): string
    {
        return self::asString($this->aFields['localAddr'] ?? '');
    }

    public function getLocalPort(): int
    {
        return self::asInt($this->aFields['localPort'] ?? 0);
    }

    public function getRemoteAddr(): string
    {
        return self::asString($this->aFields['remoteAddr'] ?? '');
    }

    public function getRemotePort(): int
    {
        return self::asInt($this->aFields['remotePort'] ?? 0);
    }

    public function getStreamId(): ?string
    {
        $mValue = $this->aFields['streamId'] ?? null;
        return $mValue === null ? null : self::asString($mValue);
    }

    public function getPayload(): string
    {
        $sDecoded = base64_decode(self::asString($this->aFields['payload'] ?? ''), true);
        return $sDecoded === false ? '' : $sDecoded;
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
            throw new \Exception('RemoteSocket: malformed frame');
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
