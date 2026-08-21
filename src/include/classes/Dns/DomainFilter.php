<?php

namespace HomeLan\FileStore\Dns;

/**
 * Restricts which query names Forwarder is allowed to send upstream (see docs/protocols/dns.md
 * → "Forwarding to an external server"). Built from `dns_forwarder_allowed_domains`, a single
 * flat comma-separated list mixing ordinary forward domains and reverse (`in-addr.arpa`)
 * domains - both are just domain-name suffixes as far as matching is concerned, so one list and
 * one matching rule covers both. An `ip6.arpa` entry would be inert (dnsd is IPv4-only - AAAA
 * queries and ip6.arpa names never reach forwarding at all, see Handler), but isn't rejected;
 * this class has no opinion on what a domain suffix means, only on matching one.
 */
class DomainFilter
{
    /** @var list<string> lowercased, no trailing dot; empty means no restriction */
    private readonly array $aAllowedDomains;

    public function __construct(string $sCommaSeparatedList)
    {
        $this->aAllowedDomains = array_values(array_filter(array_map(
            static fn (string $sDomain): string => strtolower(rtrim(trim($sDomain), '.')),
            explode(',', $sCommaSeparatedList)
        ), static fn (string $sDomain): bool => $sDomain !== ''));
    }

    /**
     * True if `$sName` is within one of the allowed domains, or if no filter is configured at
     * all (the feature is opt-in - an empty list means "no restriction", not "allow nothing").
     */
    public function isAllowed(string $sName): bool
    {
        if ($this->aAllowedDomains === []) {
            return true;
        }

        $sName = strtolower(rtrim($sName, '.'));
        foreach ($this->aAllowedDomains as $sDomain) {
            if ($sName === $sDomain || str_ends_with($sName, '.' . $sDomain)) {
                return true;
            }
        }
        return false;
    }
}
