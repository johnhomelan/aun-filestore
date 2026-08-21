<?php

namespace HomeLan\FileStore\Dns\Messages;

/**
 * A DNS message (RFC 1035 §4): decodes a single-question query and encodes the matching
 * response. Only what a basic hosts-file server needs is implemented - see
 * docs/protocols/dns.md for exactly what that covers.
 *
 * dnsd is IPv4-only throughout (EconetA, its only client, has no IPv6 support at all - see
 * docs/protocols/dns.md): TYPE_AAAA exists here only so an AAAA query can be recognised and
 * refused, and so stripAaaaRecords() can recognise and drop AAAA records from a forwarded
 * upstream response before it ever reaches an Econet client.
 */
class DnsMessage
{
    public const TYPE_A = 1;
    public const TYPE_NS = 2;
    public const TYPE_CNAME = 5;
    public const TYPE_SOA = 6;
    public const TYPE_PTR = 12;
    public const TYPE_MX = 15;
    public const TYPE_AAAA = 28;
    public const TYPE_ANY = 255;

    public const CLASS_IN = 1;

    public const RCODE_NOERROR = 0;
    public const RCODE_FORMERR = 1;
    public const RCODE_SERVFAIL = 2;
    public const RCODE_NXDOMAIN = 3;
    public const RCODE_NOTIMP = 4;
    public const RCODE_REFUSED = 5;

    private function __construct(
        private readonly int $iId,
        private readonly int $iOpcode,
        private readonly bool $bRecursionDesired,
        private readonly string $sName,
        private readonly int $iType,
        private readonly int $iClass,
    ) {
    }

    public static function decodeQuery(string $sPacket): self
    {
        if (strlen($sPacket) < 12) {
            throw new \Exception('Dns: packet shorter than a DNS header');
        }
        $aHeader = unpack('nid/nflags/nqdcount/nancount/nnscount/narcount', $sPacket);
        if ($aHeader === false) {
            throw new \Exception('Dns: unable to unpack header');
        }

        $iFlags = self::_asInt($aHeader['flags']);
        if ((($iFlags >> 15) & 0x1) === 1) {
            throw new \Exception('Dns: not a query (QR bit set)');
        }
        $iQdCount = self::_asInt($aHeader['qdcount']);
        if ($iQdCount !== 1) {
            throw new \Exception("Dns: unsupported question count ({$iQdCount})");
        }

        $iOffset = 12;
        $sName = self::decodeName($sPacket, $iOffset);
        if (strlen($sPacket) < $iOffset + 4) {
            throw new \Exception('Dns: truncated question section');
        }
        $aQuestion = unpack('ntype/nclass', substr($sPacket, $iOffset, 4));
        if ($aQuestion === false) {
            throw new \Exception('Dns: unable to unpack question type/class');
        }

        return new self(
            self::_asInt($aHeader['id']),
            ($iFlags >> 11) & 0xF,
            (($iFlags >> 8) & 0x1) === 1,
            $sName,
            self::_asInt($aQuestion['type']),
            self::_asInt($aQuestion['class']),
        );
    }

    private static function _asInt(mixed $mValue): int
    {
        return is_int($mValue) ? $mValue : 0;
    }

    private static function decodeName(string $sPacket, int &$iOffset): string
    {
        $aLabels = [];
        while (true) {
            if ($iOffset >= strlen($sPacket)) {
                throw new \Exception('Dns: name runs past end of packet');
            }
            $iLen = ord($sPacket[$iOffset]);
            if ($iLen === 0) {
                $iOffset++;
                break;
            }
            if (($iLen & 0xC0) === 0xC0) {
                throw new \Exception('Dns: unexpected compression pointer in question name');
            }
            $iOffset++;
            if ($iOffset + $iLen > strlen($sPacket)) {
                throw new \Exception('Dns: label runs past end of packet');
            }
            $aLabels[] = substr($sPacket, $iOffset, $iLen);
            $iOffset += $iLen;
        }
        return implode('.', $aLabels);
    }

    public function getId(): int
    {
        return $this->iId;
    }

    public function getOpcode(): int
    {
        return $this->iOpcode;
    }

    public function isRecursionDesired(): bool
    {
        return $this->bRecursionDesired;
    }

    public function getName(): string
    {
        return $this->sName;
    }

    public function getType(): int
    {
        return $this->iType;
    }

    public function getClass(): int
    {
        return $this->iClass;
    }

    /**
     * Builds the reply to this query. `$aAnswers` entries are pre-encoded RDATA (e.g.
     * inet_pton() output for an A/AAAA record) - this class has no opinion on record content.
     *
     * @param list<array{type:int,ttl:int,rdata:string}> $aAnswers
     */
    public function encodeResponse(int $iRcode, array $aAnswers): string
    {
        $iFlags = (1 << 15)
                | (($this->iOpcode & 0xF) << 11)
                | (($this->bRecursionDesired ? 1 : 0) << 8)
                | ($iRcode & 0xF);

        $sHeader = pack('n6', $this->iId, $iFlags, 1, count($aAnswers), 0, 0);
        $sQuestion = self::encodeDomainName($this->sName) . pack('n2', $this->iType, $this->iClass);

        $sAnswers = '';
        foreach ($aAnswers as $aAnswer) {
            $sAnswers .= "\xC0\x0C" // pointer to the question name at offset 12
                       . pack('n2Nn', $aAnswer['type'], self::CLASS_IN, $aAnswer['ttl'], strlen($aAnswer['rdata']))
                       . $aAnswer['rdata'];
        }

        return $sHeader . $sQuestion . $sAnswers;
    }

    /**
     * Wire-encodes a plain domain name (length-prefixed labels, zero-terminated) - used both
     * for the question section above and for a PTR record's RDATA, which is itself just a
     * domain name.
     */
    public static function encodeDomainName(string $sName): string
    {
        $sEncoded = '';
        foreach (explode('.', $sName) as $sLabel) {
            if ($sLabel === '') {
                continue;
            }
            $sEncoded .= chr(strlen($sLabel)) . $sLabel;
        }
        return $sEncoded . "\x00";
    }

    /**
     * Parses an IPv4 reverse-lookup query name (`<reversed-octets>.in-addr.arpa`) into the IP
     * address it represents, or null if `$sName` isn't a well-formed reverse name. `ip6.arpa`
     * names are deliberately not recognised at all - dnsd is IPv4-only (see class docblock) -
     * so one falls through to the same null/NXDOMAIN treatment as any other unparseable name.
     */
    public static function ipFromPtrName(string $sName): ?string
    {
        $sName = strtolower(rtrim($sName, '.'));

        if (!str_ends_with($sName, '.in-addr.arpa')) {
            return null;
        }

        $aOctets = explode('.', substr($sName, 0, -strlen('.in-addr.arpa')));
        if (count($aOctets) !== 4) {
            return null;
        }
        foreach ($aOctets as $sOctet) {
            if (!ctype_digit($sOctet) || (int) $sOctet > 255) {
                return null;
            }
        }
        return implode('.', array_reverse($aOctets));
    }

    /**
     * Strips every AAAA (IPv6) resource record from a raw DNS response - answer, authority, and
     * additional sections alike - before it's relayed on to an Econet client (see
     * docs/protocols/dns.md → "Forwarding to an external server"). Every retained record's NAME
     * and any domain name embedded in its RDATA (CNAME/NS/PTR/SOA/MX - the RFC 1035 types that
     * carry one) is re-encoded fully expanded rather than reusing the original packet's
     * compression, since compression pointers are offsets into a packet whose layout this
     * necessarily changes; every other record type's RDATA is opaque bytes, copied verbatim.
     *
     * Throws on anything that doesn't parse as a well-formed response - callers should treat
     * that as a forwarding failure, not attempt to relay a response this couldn't confidently
     * filter.
     */
    public static function stripAaaaRecords(string $sPacket): string
    {
        if (strlen($sPacket) < 12) {
            throw new \Exception('Dns: response shorter than a DNS header');
        }
        $aHeader = unpack('nid/nflags/nqdcount/nancount/nnscount/narcount', $sPacket);
        if ($aHeader === false) {
            throw new \Exception('Dns: unable to unpack response header');
        }

        $iOffset = 12;
        $sQuestionSection = '';
        for ($i = 0; $i < self::_asInt($aHeader['qdcount']); $i++) {
            [$sName, $iAfterName] = self::decodeCompressedName($sPacket, $iOffset);
            if (strlen($sPacket) < $iAfterName + 4) {
                throw new \Exception('Dns: truncated question section in response');
            }
            $sQuestionSection .= self::encodeDomainName($sName) . substr($sPacket, $iAfterName, 4);
            $iOffset = $iAfterName + 4;
        }

        [$sAnswers, $iAnCount, $iOffset] = self::filterResourceRecords($sPacket, $iOffset, self::_asInt($aHeader['ancount']));
        [$sAuthority, $iNsCount, $iOffset] = self::filterResourceRecords($sPacket, $iOffset, self::_asInt($aHeader['nscount']));
        [$sAdditional, $iArCount, $iOffset] = self::filterResourceRecords($sPacket, $iOffset, self::_asInt($aHeader['arcount']));

        $sNewHeader = pack('n6', self::_asInt($aHeader['id']), self::_asInt($aHeader['flags']), self::_asInt($aHeader['qdcount']), $iAnCount, $iNsCount, $iArCount);
        return $sNewHeader . $sQuestionSection . $sAnswers . $sAuthority . $sAdditional;
    }

    /** @return array{0:string,1:int,2:int} [encoded records with AAAA dropped, count kept, offset after this section in the original packet] */
    private static function filterResourceRecords(string $sPacket, int $iOffset, int $iCount): array
    {
        $sEncoded = '';
        $iKept = 0;
        for ($i = 0; $i < $iCount; $i++) {
            $aRecord = self::decodeResourceRecord($sPacket, $iOffset);
            $iOffset = $aRecord['nextOffset'];
            if ($aRecord['type'] === self::TYPE_AAAA) {
                continue;
            }
            $sEncoded .= self::encodeDomainName($aRecord['name'])
                       . pack('n2N', $aRecord['type'], $aRecord['class'], $aRecord['ttl'])
                       . pack('n', strlen($aRecord['rdata']))
                       . $aRecord['rdata'];
            $iKept++;
        }
        return [$sEncoded, $iKept, $iOffset];
    }

    /** @return array{name:string,type:int,class:int,ttl:int,rdata:string,nextOffset:int} */
    private static function decodeResourceRecord(string $sPacket, int $iOffset): array
    {
        [$sName, $iCursor] = self::decodeCompressedName($sPacket, $iOffset);
        if (strlen($sPacket) < $iCursor + 10) {
            throw new \Exception('Dns: truncated resource record');
        }
        $aFixed = unpack('ntype/nclass/Nttl/nrdlength', substr($sPacket, $iCursor, 10));
        if ($aFixed === false) {
            throw new \Exception('Dns: unable to unpack resource record fields');
        }
        $iType = self::_asInt($aFixed['type']);
        $iRdLength = self::_asInt($aFixed['rdlength']);
        $iRdataStart = $iCursor + 10;
        if (strlen($sPacket) < $iRdataStart + $iRdLength) {
            throw new \Exception('Dns: truncated resource record rdata');
        }

        return [
            'name' => $sName,
            'type' => $iType,
            'class' => self::_asInt($aFixed['class']),
            'ttl' => self::_asInt($aFixed['ttl']),
            'rdata' => self::decodeRdata($sPacket, $iType, $iRdataStart, $iRdLength),
            'nextOffset' => $iRdataStart + $iRdLength,
        ];
    }

    /** Re-encodes RDATA for the RFC 1035 types that embed a (possibly compressed) domain name; everything else is opaque, copied verbatim. */
    private static function decodeRdata(string $sPacket, int $iType, int $iRdataStart, int $iRdLength): string
    {
        switch ($iType) {
            case self::TYPE_CNAME:
            case self::TYPE_NS:
            case self::TYPE_PTR:
                [$sName] = self::decodeCompressedName($sPacket, $iRdataStart);
                return self::encodeDomainName($sName);

            case self::TYPE_MX:
                if ($iRdLength < 2) {
                    throw new \Exception('Dns: truncated MX rdata');
                }
                $sPreference = substr($sPacket, $iRdataStart, 2);
                [$sName] = self::decodeCompressedName($sPacket, $iRdataStart + 2);
                return $sPreference . self::encodeDomainName($sName);

            case self::TYPE_SOA:
                [$sMname, $iAfterMname] = self::decodeCompressedName($sPacket, $iRdataStart);
                [$sRname, $iAfterRname] = self::decodeCompressedName($sPacket, $iAfterMname);
                if (strlen($sPacket) < $iAfterRname + 20) {
                    throw new \Exception('Dns: truncated SOA rdata');
                }
                return self::encodeDomainName($sMname) . self::encodeDomainName($sRname) . substr($sPacket, $iAfterRname, 20);

            default:
                return substr($sPacket, $iRdataStart, $iRdLength);
        }
    }

    /**
     * Decodes a (possibly compressed) domain name starting at $iOffset, following pointers per
     * RFC 1035 §4.1.4. Unlike decodeName(), used for a query's own question name, a pointer is
     * expected here - a real-world response uses them constantly. A pointer must point strictly
     * backward, and a hard cap on the number of jumps followed, together rule out both the
     * malformed self-referencing case and any longer pointer cycle.
     *
     * @return array{0:string,1:int} [dotted name, offset immediately after this field at the
     *         *starting* position - i.e. +2 if it started with a pointer, regardless of how far
     *         that pointer's target then continues]
     */
    private static function decodeCompressedName(string $sPacket, int $iOffset): array
    {
        $aLabels = [];
        $iCursor = $iOffset;
        $iNextOffset = null;
        $iJumps = 0;

        while (true) {
            if ($iCursor >= strlen($sPacket)) {
                throw new \Exception('Dns: name runs past end of packet');
            }
            $iLen = ord($sPacket[$iCursor]);

            if (($iLen & 0xC0) === 0xC0) {
                if ($iCursor + 1 >= strlen($sPacket)) {
                    throw new \Exception('Dns: truncated compression pointer');
                }
                $iTarget = (($iLen & 0x3F) << 8) | ord($sPacket[$iCursor + 1]);
                $iNextOffset ??= $iCursor + 2;
                $iJumps++;
                if ($iJumps > 32 || $iTarget >= $iCursor) {
                    throw new \Exception('Dns: invalid or looping compression pointer');
                }
                $iCursor = $iTarget;
                continue;
            }

            if ($iLen === 0) {
                $iNextOffset ??= $iCursor + 1;
                break;
            }

            $iCursor++;
            if ($iCursor + $iLen > strlen($sPacket)) {
                throw new \Exception('Dns: label runs past end of packet');
            }
            $aLabels[] = substr($sPacket, $iCursor, $iLen);
            $iCursor += $iLen;
        }

        return [implode('.', $aLabels), $iNextOffset];
    }
}
