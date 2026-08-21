<?php

namespace HomeLan\FileStore\ShareFs\Messages;

/**
 * Access+ per-share password authentication (UDP port 32771).
 *
 * Layout and the PIN-folding algorithm match andrewtimmins/riscos-access-server, src/accessplus.c.
 *
 * Real Access+ has no concept of a user account: a share flagged "protected" carries its own
 * password (max 6 characters), and a client wanting it folds that password into a PIN and
 * sends it as a "share key". If the folded PIN the client sent matches the server's own fold
 * of the share's configured password, the server treats that (client IP, share name) pair as
 * authenticated for a sliding 10-minute window - see ShareAuthTable.
 *
 * All integers are little-endian.
 */
class AccessPlusPacket
{
    /** Client -> server: "what protected shares do you have?" (unused standalone by this reference server) */
    public const int MSG_DISCS_STARTUP = 0x00010001;
    /** Server -> client (general broadcast, not used on this port): share available */
    public const int MSG_DISCS_AVAILABLE = 0x00010002;
    /** Server -> client (general broadcast, not used on this port): share removed */
    public const int MSG_DISCS_REMOVED = 0x00010003;
    /** Server -> client: reply to a successful share-key match, carrying the protected share's info */
    public const int MSG_DISCS_PERIODIC = 0x00010004;

    /** The fixed "share_type" value a client sends alongside MSG_DISCS_STARTUP when requesting a protected share by key. */
    public const int SHARE_TYPE_DISC = 0x00010001;

    private function __construct(
        private readonly int $iMessageType,
        private readonly int $iShareType,
        private readonly ?int $iClientKey,
    ) {
    }

    public function getMessageType(): int
    {
        return $this->iMessageType;
    }

    public function getShareType(): int
    {
        return $this->iShareType;
    }

    /** The folded-password PIN the client is asserting for a share, if this is a share-key request. */
    public function getClientKey(): ?int
    {
        return $this->iClientKey;
    }

    public function isShareKeyRequest(): bool
    {
        return $this->iMessageType === self::MSG_DISCS_STARTUP
            && $this->iShareType === self::SHARE_TYPE_DISC
            && $this->iClientKey !== null;
    }

    public static function decode(string $sBinary): self
    {
        if (strlen($sBinary) < 8) {
            throw new \Exception('ShareFs: Access+ packet too short');
        }
        $aHeader = unpack('VmessageType/VshareType', substr($sBinary, 0, 8));
        if ($aHeader === false) {
            throw new \Exception('ShareFs: failed to unpack Access+ packet header');
        }

        $iClientKey = null;
        if (strlen($sBinary) >= 12) {
            $aKey = unpack('VclientKey', substr($sBinary, 8, 4));
            if ($aKey !== false) {
                $iClientKey = self::_asInt($aKey['clientKey']);
            }
        }

        return new self(self::_asInt($aHeader['messageType']), self::_asInt($aHeader['shareType']), $iClientKey);
    }

    public static function encodeShareKeyRequest(int $iClientKey): string
    {
        return pack('V', self::MSG_DISCS_STARTUP) . pack('V', self::SHARE_TYPE_DISC) . pack('V', $iClientKey);
    }

    /**
     * The reply sent when a client's share key matches: header layout deliberately differs
     * from FreewayPacket's general broadcast (the length word here packs a fixed 0x0001 in
     * its upper 16 bits rather than a real description length - there is no description field
     * at all, just a trailing attribute byte and a null terminator).
     */
    public static function encodeProtectedShareReply(int $iShareKey, string $sName, int $iAttributes): string
    {
        $iLengths = (0x0001 << 16) | strlen($sName);

        return pack('V', self::MSG_DISCS_PERIODIC)
             . pack('V', self::SHARE_TYPE_DISC)
             . pack('V', $iLengths)
             . pack('V', $iShareKey)
             . $sName
             . pack('C', $iAttributes)
             . "\x00";
    }

    /**
     * Folds a share password into the PIN a real Access+ client would send: uppercase each
     * character, map digit -> 1..10 / letter -> 11..36 (0 for anything else), fold with
     * pin = pin*37 + value, first 6 characters only.
     */
    public static function foldPassword(string $sPassword): int
    {
        $iPin = 0;
        $sPassword = strtoupper($sPassword);
        for ($i = 0; $i < min(strlen($sPassword), 6); $i++) {
            $iPin = ($iPin * 37 + self::encodeChar($sPassword[$i])) & 0xFFFFFFFF;
        }
        return $iPin;
    }

    private static function encodeChar(string $cChar): int
    {
        if ($cChar >= '0' && $cChar <= '9') {
            return (ord($cChar) - ord('0')) + 1;
        }
        if ($cChar >= 'A' && $cChar <= 'Z') {
            return (ord($cChar) - ord('A')) + 11;
        }
        return 0;
    }

    private static function _asInt(mixed $mValue): int
    {
        return is_int($mValue) ? $mValue : 0;
    }
}
