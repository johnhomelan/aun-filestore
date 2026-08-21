<?php

namespace HomeLan\FileStore\Dns;

use HomeLan\FileStore\Dns\Messages\DnsMessage;
use React\Datagram\SocketInterface;

/**
 * Answers DNS queries from HostsFile, optionally forwarding whatever it can't answer to an
 * external server via Forwarder - see docs/protocols/dns.md. Given its traffic source through
 * setSocket(), exactly like ShareFs's protocol handlers - see docs/DNSD.md for why dnsd always
 * receives its traffic over a Remote Socket Protocol relay rather than a socket bound to a
 * real UDP port.
 */
class Handler
{
    private const DEFAULT_TTL = 300;

    private SocketInterface $oSocket;

    public function __construct(
        private readonly \Psr\Log\LoggerInterface $oLogger,
        private readonly ?Forwarder $oForwarder = null,
        private readonly ?DomainFilter $oDomainFilter = null,
    ) {
    }

    public function setSocket(SocketInterface $oSocket): void
    {
        $this->oSocket = $oSocket;
    }

    public function receive(string $sMessage, string $sSrcAddress): void
    {
        try {
            $oQuery = DnsMessage::decodeQuery($sMessage);
        } catch (\Exception $oException) {
            $this->oLogger->debug("Dns: dropping malformed query from {$sSrcAddress}: " . $oException->getMessage());
            return;
        }

        $this->oLogger->debug("Dns: query from {$sSrcAddress} for \"{$oQuery->getName()}\" type {$oQuery->getType()}");

        if ($oQuery->getOpcode() !== 0 || $oQuery->getClass() !== DnsMessage::CLASS_IN) {
            $this->send($oQuery->encodeResponse(DnsMessage::RCODE_NOTIMP, []), $sSrcAddress);
            return;
        }

        if ($oQuery->getType() === DnsMessage::TYPE_PTR) {
            $this->handlePtrQuery($oQuery, $sMessage, $sSrcAddress);
            return;
        }

        // IPv4 only throughout - EconetA, dnsd's only client, has no IPv6 support at all (see
        // docs/protocols/dns.md). AAAA falls through to NOTIMP exactly like any other type this
        // server doesn't serve.
        if ($oQuery->getType() !== DnsMessage::TYPE_A) {
            $this->send($oQuery->encodeResponse(DnsMessage::RCODE_NOTIMP, []), $sSrcAddress);
            return;
        }

        $aAddresses = HostsFile::lookup($oQuery->getName());
        if ($aAddresses !== []) {
            $this->send($oQuery->encodeResponse(DnsMessage::RCODE_NOERROR, $this->buildAddressAnswers($aAddresses)), $sSrcAddress);
            return;
        }

        // HostsFile indexes only one record type (A) per name now, so an empty lookup always
        // means the name itself is unknown - there's no separate "known name, wrong type" case
        // left to distinguish (that only existed back when AAAA shared the same index).
        $this->tryForward($oQuery, $sMessage, $sSrcAddress, function () use ($oQuery, $sSrcAddress): void {
            $this->send($oQuery->encodeResponse(DnsMessage::RCODE_NXDOMAIN, []), $sSrcAddress);
        });
    }

    private function handlePtrQuery(DnsMessage $oQuery, string $sRawMessage, string $sSrcAddress): void
    {
        $sIp = DnsMessage::ipFromPtrName($oQuery->getName());
        if ($sIp === null) {
            // Not a well-formed in-addr.arpa name (or an ip6.arpa one - not recognised at all,
            // dnsd is IPv4-only) - nothing sensible to forward either.
            $this->send($oQuery->encodeResponse(DnsMessage::RCODE_NXDOMAIN, []), $sSrcAddress);
            return;
        }

        $aNames = HostsFile::reverseLookup($sIp);
        if ($aNames !== []) {
            $aAnswers = array_map(
                static fn (string $sName): array => [
                    'type' => DnsMessage::TYPE_PTR,
                    'ttl' => self::DEFAULT_TTL,
                    'rdata' => DnsMessage::encodeDomainName($sName),
                ],
                $aNames,
            );
            $this->send($oQuery->encodeResponse(DnsMessage::RCODE_NOERROR, $aAnswers), $sSrcAddress);
            return;
        }

        $this->tryForward($oQuery, $sRawMessage, $sSrcAddress, function () use ($oQuery, $sSrcAddress): void {
            $this->send($oQuery->encodeResponse(DnsMessage::RCODE_NXDOMAIN, []), $sSrcAddress);
        });
    }

    /**
     * @param list<string> $aAddresses
     * @return list<array{type:int,ttl:int,rdata:string}>
     */
    private function buildAddressAnswers(array $aAddresses): array
    {
        return array_map(
            static fn (string $sIp): array => [
                'type' => DnsMessage::TYPE_A,
                'ttl' => self::DEFAULT_TTL,
                'rdata' => (string) inet_pton($sIp),
            ],
            $aAddresses,
        );
    }

    /**
     * If forwarding is configured and this name passes the domain filter, forwards the query
     * upstream (asynchronously - this method returns immediately either way) and relays its
     * response back once it arrives. Otherwise, or if the upstream doesn't reply in time, calls
     * $fLocalFallback - a NXDOMAIN built purely from the hosts file, exactly what would have
     * been sent had forwarding not been configured at all.
     */
    private function tryForward(DnsMessage $oQuery, string $sRawMessage, string $sSrcAddress, callable $fLocalFallback): void
    {
        if ($this->oForwarder === null || ($this->oDomainFilter !== null && !$this->oDomainFilter->isAllowed($oQuery->getName()))) {
            $fLocalFallback();
            return;
        }

        $this->oForwarder->forward($sRawMessage)->then(
            function (mixed $mUpstreamResponse) use ($oQuery, $sSrcAddress, $fLocalFallback): void {
                if (!is_string($mUpstreamResponse) || strlen($mUpstreamResponse) < 2) {
                    return;
                }

                // dnsd is IPv4-only (see docs/protocols/dns.md): strip any AAAA record before
                // this ever reaches an Econet client, which has no IPv6 support to receive it
                // safely. If the response can't be confidently parsed, don't relay it at all.
                try {
                    $sFiltered = DnsMessage::stripAaaaRecords($mUpstreamResponse);
                } catch (\Exception $oException) {
                    $this->oLogger->debug('Dns: discarding an unparseable upstream response: ' . $oException->getMessage());
                    $fLocalFallback();
                    return;
                }

                // The response still carries Forwarder's own internal transaction id; rewrite
                // it back to the original client's before relaying it on.
                $sResponse = pack('n', $oQuery->getId()) . substr($sFiltered, 2);
                $this->send($sResponse, $sSrcAddress);
            },
            function (\Throwable $oError) use ($fLocalFallback): void {
                $this->oLogger->debug('Dns: forwarding failed, falling back to local answer: ' . $oError->getMessage());
                $fLocalFallback();
            },
        );
    }

    private function send(string $sData, string $sDestAddress): void
    {
        $this->oSocket->send($sData, $sDestAddress);
    }
}
