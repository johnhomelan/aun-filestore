<?php

namespace HomeLan\FileStore\ShareFs\Messages;

/**
 * A Freeway share-availability broadcast (UDP port 32770).
 *
 * Layout matches andrewtimmins/riscos-access-server, src/broadcast.c:send_broadcast().
 *
 * All integers are little-endian.
 *
 * Layout:
 *   word0(4)   = type<<16 | minor
 *   version(4) = 0x00010000, fixed
 *   lengths(4) = (descLength<<16) | nameLength  (each length includes its trailing null)
 *   name(nameLength)   null-terminated
 *   desc(descLength)   null-terminated
 */
class FreewayPacket
{
    public const int TYPE_DISC    = 1;
    public const int TYPE_PRINTER = 2;

    public const int MINOR_STARTUP   = 1; // client -> server: "what do you have?" (unused by this reference server as a reply trigger)
    public const int MINOR_AVAILABLE = 2; // server -> client: share is available (sent repeatedly on the broadcast timer, not just once)
    public const int MINOR_REMOVED   = 3; // server -> client: share withdrawn
    public const int MINOR_PERIODIC  = 4; // server -> client: only observed for a protected share's Access+ reply, not a general re-broadcast

    /** Seconds between broadcast_shares() calls in the reference server's sample config. Not fixed by the wire protocol - purely an operational default. */
    public const int DEFAULT_BROADCAST_INTERVAL = 3;

    public function __construct(
        private readonly int $iType,
        private readonly int $iMinor,
        private readonly string $sName,
        private readonly string $sDescription = '',
    ) {
    }

    public function getType(): int
    {
        return $this->iType;
    }

    public function getMinor(): int
    {
        return $this->iMinor;
    }

    public function getName(): string
    {
        return $this->sName;
    }

    public function getDescription(): string
    {
        return $this->sDescription;
    }

    public function encode(): string
    {
        $sName = $this->sName . "\x00";
        $sDesc = $this->sDescription . "\x00";
        $iWord0 = ($this->iType << 16) | $this->iMinor;
        $iLengths = (strlen($sDesc) << 16) | strlen($sName);

        return pack('V', $iWord0) . pack('V', 0x00010000) . pack('V', $iLengths) . $sName . $sDesc;
    }

    public static function decode(string $sBinary): self
    {
        if (strlen($sBinary) < 12) {
            throw new \Exception('ShareFs: Freeway packet too short');
        }
        $aFields = unpack('Vword0/Vversion/Vlengths', substr($sBinary, 0, 12));
        if ($aFields === false) {
            throw new \Exception('ShareFs: failed to unpack Freeway packet header');
        }
        $iWord0 = self::_asInt($aFields['word0']);
        $iLengths = self::_asInt($aFields['lengths']);

        $iType = ($iWord0 >> 16) & 0xFFFF;
        $iMinor = $iWord0 & 0xFFFF;
        $iNameLength = $iLengths & 0xFFFF;
        $iDescLength = ($iLengths >> 16) & 0xFFFF;

        if (strlen($sBinary) < 12 + $iNameLength + $iDescLength) {
            throw new \Exception('ShareFs: Freeway packet truncated before name/description');
        }
        $sName = rtrim(substr($sBinary, 12, $iNameLength), "\x00");
        $sDescription = rtrim(substr($sBinary, 12 + $iNameLength, $iDescLength), "\x00");

        return new self($iType, $iMinor, $sName, $sDescription);
    }

    private static function _asInt(mixed $mValue): int
    {
        return is_int($mValue) ? $mValue : 0;
    }
}
