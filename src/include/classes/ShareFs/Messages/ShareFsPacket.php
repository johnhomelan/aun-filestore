<?php

namespace HomeLan\FileStore\ShareFs\Messages;

/**
 * The ShareFS file-data RPC protocol (UDP port 49171).
 *
 * Layout matches andrewtimmins/riscos-access-server, src/ops.c. Only the primary 'A'/'F'
 * command framing is implemented; that project also supports an alternate 'B'-command framing
 * some clients use (an immediate full directory catalogue on ROPENDIR, and a single-shot S+B
 * RREAD instead of the D/r streaming ping-pong) - that variant is deliberately not implemented
 * here. All integers are little-endian.
 *
 * Request framing:
 *   'A' rid[3] code(4) <command-specific body>   - main RPC
 *   'F' rid[3] code(4) handle(4)                 - no-path queries (RVERSION, RDEADHANDLES)
 *
 * The body layout after `code` is NOT uniform - most commands carry a handle(4) then either a
 * null-terminated path or binary fields, but RACCESS and RRENAME repurpose that first 4-byte
 * slot for their own fields (attrs / new-name-length) with the path pushed out to offset 16
 * instead of 12. See the per-command decode*() methods below, each of which matches its own
 * command's real layout rather than a generalised one.
 */
class ShareFsPacket
{
    public const int CODE_RFIND       = 0x00;
    public const int CODE_ROPENIN     = 0x01;
    public const int CODE_ROPENUP     = 0x02;
    public const int CODE_ROPENDIR    = 0x03;
    public const int CODE_RCREATE     = 0x04;
    public const int CODE_RCREATEDIR  = 0x05;
    public const int CODE_RDELETE     = 0x06;
    public const int CODE_RACCESS     = 0x07;
    public const int CODE_RFREESPACE  = 0x08;
    public const int CODE_RRENAME     = 0x09;
    public const int CODE_RCLOSE      = 0x0a;
    public const int CODE_RREAD       = 0x0b;
    public const int CODE_RWRITE      = 0x0c;
    public const int CODE_RREADDIR    = 0x0d;
    public const int CODE_RENSURE     = 0x0e;
    public const int CODE_RSETLENGTH  = 0x0f;
    public const int CODE_RSETINFO    = 0x10;
    public const int CODE_RGETSEQPTR  = 0x11;
    public const int CODE_RSETSEQPTR  = 0x12;
    public const int CODE_RDEADHANDLES = 0x13;
    public const int CODE_RZERO       = 0x14;
    public const int CODE_RVERSION    = 0x15;

    public const int TYPE_NOTFOUND = 0;
    public const int TYPE_FILE     = 1;
    public const int TYPE_DIR      = 2;

    /** Default read/write chunk size for the streaming RREAD/RWRITE ping-pong. */
    public const int CHUNK_SIZE = 1024;

    private function __construct(
        private readonly string $sCmdType,
        private readonly string $sRid,
        private readonly int $iCode,
        private readonly string $sBody,
    ) {
    }

    public function getCmdType(): string
    {
        return $this->sCmdType;
    }

    public function getRid(): string
    {
        return $this->sRid;
    }

    public function getCode(): int
    {
        return $this->iCode;
    }

    public function getBody(): string
    {
        return $this->sBody;
    }

    public static function decodeRequest(string $sBinary): self
    {
        if (strlen($sBinary) < 8) {
            throw new \Exception('ShareFs: RPC packet too short');
        }
        $sCmdType = $sBinary[0];
        if ($sCmdType !== 'A' && $sCmdType !== 'F') {
            throw new \Exception("ShareFs: unsupported RPC command type \"{$sCmdType}\"");
        }
        $aFields = unpack('Vcode', substr($sBinary, 4, 4));
        if ($aFields === false) {
            throw new \Exception('ShareFs: failed to unpack RPC code field');
        }

        return new self($sCmdType, substr($sBinary, 1, 3), self::_asInt($aFields['code']), substr($sBinary, 8));
    }

    // -----------------------------------------------------------------------
    // Per-command body decoders. $sBody is everything after the code field
    // (wire offset 8 onward).
    // -----------------------------------------------------------------------

    /** F-cmd queries, and A-cmd RCLOSE/RGETSEQPTR/RVERSION/RFREESPACE: handle(4). */
    public static function decodeHandle(string $sBody): int
    {
        return self::readU32($sBody, 0, 'handle');
    }

    /** RFIND/ROPENIN/ROPENUP/ROPENDIR/RCREATE/RCREATEDIR/RDELETE: handle(4, unused) + path(z). */
    public static function decodePath(string $sBody): string
    {
        return self::readCString($sBody, 4);
    }

    /**
     * RACCESS: attrs(4) + path(z) at body offset 8 (wire offset 16).
     * @return array{attrs:int, path:string}
     */
    public static function decodeAccessRequest(string $sBody): array
    {
        return ['attrs' => self::readU32($sBody, 0, 'attrs'), 'path' => self::readCString($sBody, 8)];
    }

    /**
     * RRENAME arm: newNameLength(4) + oldPath(z) at body offset 8 (wire offset 16).
     * @return array{newNameLength:int, oldPath:string}
     */
    public static function decodeRenameArm(string $sBody): array
    {
        return ['newNameLength' => self::readU32($sBody, 0, 'newNameLength'), 'oldPath' => self::readCString($sBody, 8)];
    }

    /**
     * RREAD/RWRITE/RZERO: handle(4) + offset(4) + amount(4).
     * @return array{handle:int, offset:int, amount:int}
     */
    public static function decodeHandleOffsetAmount(string $sBody): array
    {
        return [
            'handle' => self::readU32($sBody, 0, 'handle'),
            'offset' => self::readU32($sBody, 4, 'offset'),
            'amount' => self::readU32($sBody, 8, 'amount'),
        ];
    }

    /**
     * RSETINFO: handle(4) + load(4) + exec(4).
     * @return array{handle:int, load:int, exec:int}
     */
    public static function decodeSetInfoRequest(string $sBody): array
    {
        return [
            'handle' => self::readU32($sBody, 0, 'handle'),
            'load'   => self::readU32($sBody, 4, 'load'),
            'exec'   => self::readU32($sBody, 8, 'exec'),
        ];
    }

    /**
     * RENSURE/RSETLENGTH/RSETSEQPTR: handle(4) + value(4).
     * @return array{handle:int, value:int}
     */
    public static function decodeHandleAndValue(string $sBody): array
    {
        return ['handle' => self::readU32($sBody, 0, 'handle'), 'value' => self::readU32($sBody, 4, 'value')];
    }

    /**
     * RREADDIR: handle(4) + startEntry(4).
     * @return array{handle:int, startEntry:int}
     */
    public static function decodeReaddirRequest(string $sBody): array
    {
        return ['handle' => self::readU32($sBody, 0, 'handle'), 'startEntry' => self::readU32($sBody, 4, 'startEntry')];
    }

    // -----------------------------------------------------------------------
    // FileDesc (20 bytes): load, exec, length, attrs, type_and_flags - all u32 LE.
    // -----------------------------------------------------------------------

    public static function encodeFileDesc(int $iLoad, int $iExec, int $iLength, int $iAttrs, int $iType): string
    {
        return pack('V', $iLoad) . pack('V', $iExec) . pack('V', $iLength) . pack('V', $iAttrs) . pack('V', $iType | (1 << 8));
    }

    /** @return array{load:int, exec:int, length:int, attrs:int, type:int} */
    public static function decodeFileDesc(string $sBinary): array
    {
        if (strlen($sBinary) < 20) {
            throw new \Exception('ShareFs: FileDesc payload too short');
        }
        $aFields = unpack('Vload/Vexec/Vlength/Vattrs/VtypeAndFlags', substr($sBinary, 0, 20));
        if ($aFields === false) {
            throw new \Exception('ShareFs: failed to unpack FileDesc payload');
        }
        return [
            'load'   => self::_asInt($aFields['load']),
            'exec'   => self::_asInt($aFields['exec']),
            'length' => self::_asInt($aFields['length']),
            'attrs'  => self::_asInt($aFields['attrs']),
            'type'   => self::_asInt($aFields['typeAndFlags']) & 0xFF,
        ];
    }

    // -----------------------------------------------------------------------
    // Replies
    // -----------------------------------------------------------------------

    public static function encodeSuccess(string $sRid, string $sPayload = ''): string
    {
        return 'R' . $sRid . $sPayload;
    }

    public static function encodeError(string $sRid, int $iErrno): string
    {
        return 'E' . $sRid . pack('V', $iErrno);
    }

    // -----------------------------------------------------------------------
    // RREAD streaming: server sends 'D' data chunks, client acks with 'r'.
    // -----------------------------------------------------------------------

    public static function encodeReadData(string $sRid, int $iOffset, string $sData): string
    {
        return 'D' . $sRid . pack('V', $iOffset) . $sData;
    }

    public static function decodeReadAck(string $sBinary): string
    {
        if (strlen($sBinary) < 4 || $sBinary[0] !== 'r') {
            throw new \Exception('ShareFs: not a read-ack (r) packet');
        }
        return substr($sBinary, 1, 3);
    }

    // -----------------------------------------------------------------------
    // RWRITE (and RRENAME's new-name delivery, which reuses this same exchange):
    // server requests a window with 'w', client delivers it with 'd'.
    // -----------------------------------------------------------------------

    public static function encodeWriteRequest(string $sRid, int $iRelPos, int $iRelEnd): string
    {
        return 'w' . $sRid . pack('V', $iRelPos) . pack('V', 0) . pack('V', $iRelEnd);
    }

    /** @return array{rid:string, relPos:int, data:string} */
    public static function decodeWriteData(string $sBinary): array
    {
        if (strlen($sBinary) < 8 || $sBinary[0] !== 'd') {
            throw new \Exception('ShareFs: not a write-data (d) packet');
        }
        return [
            'rid'    => substr($sBinary, 1, 3),
            'relPos' => self::readU32($sBinary, 4, 'relPos'),
            'data'   => substr($sBinary, 8),
        ];
    }

    // -----------------------------------------------------------------------
    // RREADDIR reply: S (entries) + B (trailer) combined packet.
    // -----------------------------------------------------------------------

    /** @param list<array{name:string, load:int, exec:int, length:int, attrs:int, type:int}> $aEntries */
    public static function encodeDirEntries(array $aEntries): string
    {
        $sOut = '';
        foreach ($aEntries as $aEntry) {
            $sName = $aEntry['name'] . "\x00";
            $sRaw = self::encodeFileDesc($aEntry['load'], $aEntry['exec'], $aEntry['length'], $aEntry['attrs'], $aEntry['type']) . $sName;
            $iPadded = (strlen($sRaw) + 3) & ~3;
            $sOut .= str_pad($sRaw, $iPadded, "\x00");
        }
        return $sOut;
    }

    /** @return list<array{name:string, load:int, exec:int, length:int, attrs:int, type:int}> */
    public static function decodeDirEntries(string $sBinary): array
    {
        $aEntries = [];
        $iOffset = 0;
        $iLength = strlen($sBinary);
        while ($iOffset + 20 <= $iLength) {
            $aDesc = self::decodeFileDesc(substr($sBinary, $iOffset, 20));
            $iNameStart = $iOffset + 20;
            $iNul = strpos($sBinary, "\x00", $iNameStart);
            if ($iNul === false) {
                throw new \Exception('ShareFs: unterminated directory entry name');
            }
            $sName = substr($sBinary, $iNameStart, $iNul - $iNameStart);
            $iEntrySize = (20 + ($iNul - $iNameStart) + 1 + 3) & ~3;
            $aEntries[] = ['name' => $sName, ...$aDesc];
            $iOffset += $iEntrySize;
        }
        return $aEntries;
    }

    public static function encodeReaddirPage(string $sRid, string $sEntriesBlob): string
    {
        $sHead = 'S' . $sRid . pack('V', strlen($sEntriesBlob)) . pack('V', 0x0c);
        $sTrailer = 'B' . $sRid . pack('V', strlen($sEntriesBlob)) . pack('V', 0xFFFFFFFF);
        return $sHead . $sEntriesBlob . $sTrailer;
    }

    // -----------------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------------

    private static function readU32(string $sBinary, int $iOffset, string $sField): int
    {
        if (strlen($sBinary) < $iOffset + 4) {
            throw new \Exception("ShareFs: packet too short to read {$sField}");
        }
        $aFields = unpack('Vvalue', substr($sBinary, $iOffset, 4));
        if ($aFields === false) {
            throw new \Exception("ShareFs: failed to unpack {$sField}");
        }
        return self::_asInt($aFields['value']);
    }

    private static function readCString(string $sBinary, int $iOffset): string
    {
        if (strlen($sBinary) < $iOffset) {
            return '';
        }
        $sTail = substr($sBinary, $iOffset);
        $iNul = strpos($sTail, "\x00");
        return $iNul === false ? $sTail : substr($sTail, 0, $iNul);
    }

    private static function _asInt(mixed $mValue): int
    {
        return is_int($mValue) ? $mValue : 0;
    }
}
