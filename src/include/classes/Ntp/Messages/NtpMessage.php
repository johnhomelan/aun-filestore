<?php

namespace HomeLan\FileStore\Ntp\Messages;

/**
 * An NTP packet (RFC 5905 §7.3): decodes the 48-byte base header common to every NTP/SNTP
 * client request and encodes the matching server response. No extension fields, MAC, or
 * NTPv4-specific optional fields are read or written - see docs/protocols/ntp.md for exactly
 * what this covers.
 */
class NtpMessage
{
    public const MODE_CLIENT = 3;
    public const MODE_SERVER = 4;

    /** Seconds between the NTP epoch (1900-01-01) and the Unix epoch (1970-01-01). */
    private const NTP_EPOCH_OFFSET = 2208988800;

    private function __construct(
        private readonly int $iVersion,
        private readonly int $iMode,
        private readonly int $iPoll,
        private readonly string $sTransmitTimestamp,
    ) {
    }

    public static function decodeRequest(string $sPacket): self
    {
        if (strlen($sPacket) < 48) {
            throw new \Exception('Ntp: packet shorter than the minimum NTP header');
        }

        $iFlags = ord($sPacket[0]);

        return new self(
            ($iFlags >> 3) & 0x7,
            $iFlags & 0x7,
            self::decodeSignedByte(ord($sPacket[2])),
            substr($sPacket, 40, 8),
        );
    }

    public function getVersion(): int
    {
        return $this->iVersion;
    }

    public function getMode(): int
    {
        return $this->iMode;
    }

    public function getPoll(): int
    {
        return $this->iPoll;
    }

    /**
     * Builds the server (mode 4) reply to this request. `$fReceiveTime`/`$fTransmitTime` are
     * Unix timestamps (as from `microtime(true)`) - the moment the request was received and the
     * moment the reply is being sent, respectively; the Reference Timestamp is set to
     * `$fReceiveTime` too, since a self-referencing clock (see docs/protocols/ntp.md) is always
     * considered "just synchronized" to itself.
     */
    public function encodeResponse(int $iStratum, string $sReferenceId, int $iPrecision, float $fReceiveTime, float $fTransmitTime): string
    {
        $iFlags = ($this->iVersion & 0x7) << 3 | self::MODE_SERVER;

        return chr($iFlags)
             . chr($iStratum & 0xFF)
             . self::encodeSignedByte($this->iPoll)
             . self::encodeSignedByte($iPrecision)
             . pack('N', 0) // root delay
             . pack('N', 0) // root dispersion
             . self::encodeReferenceId($sReferenceId)
             . self::encodeTimestamp($fReceiveTime) // reference timestamp
             . $this->sTransmitTimestamp // origin timestamp: the client's own transmit timestamp, echoed back verbatim
             . self::encodeTimestamp($fReceiveTime) // receive timestamp
             . self::encodeTimestamp($fTransmitTime); // transmit timestamp
    }

    private static function encodeReferenceId(string $sId): string
    {
        return str_pad(substr($sId, 0, 4), 4, "\x00");
    }

    private static function encodeTimestamp(float $fUnixTime): string
    {
        $fSeconds = floor($fUnixTime);
        $iSeconds = (int) $fSeconds + self::NTP_EPOCH_OFFSET;
        $iFraction = (int) round(($fUnixTime - $fSeconds) * 4294967296.0);
        if ($iFraction >= 4294967296) {
            $iFraction = 0;
            $iSeconds++;
        }
        return pack('NN', $iSeconds, $iFraction);
    }

    private static function decodeSignedByte(int $iByte): int
    {
        return $iByte >= 128 ? $iByte - 256 : $iByte;
    }

    private static function encodeSignedByte(int $iValue): string
    {
        return chr($iValue & 0xFF);
    }
}
