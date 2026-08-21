# DNS

`dnsd` answers DNS queries (RFC 1035) from a Unix-style hosts file, optionally forwarding
whatever it can't answer there to an external DNS server. It is a resolver of last resort for
Econet clients with an IP stack, not a general-purpose nameserver — see `docs/DNSD.md` for why
it's a separate daemon and how it receives its traffic (always over the Remote Socket Protocol
relay, never a bound UDP socket — see `docs/protocols/remote-socket.md`).

**IPv4 only.** EconetA, dnsd's only client, has no IPv6 support at all, and IPv6 information
reaching an Econet client is likely to break it outright rather than simply go unused. So this
isn't just "IPv6 not implemented yet" — it's actively excluded end to end: the hosts file never
indexes an IPv6 address, `AAAA` queries always get `NOTIMP`, `ip6.arpa` reverse names are never
recognised, and — the case that needs active filtering rather than simply not implementing
something — a response forwarded from an external DNS server has every `AAAA` record stripped
out before it ever reaches a client. See [Forwarding to an external server](#forwarding-to-an-external-server)
below for exactly how.

## What's implemented

- Single-question queries (`QDCOUNT` must be 1 — see [What's not implemented](#whats-not-implemented))
- `A` (IPv4) forward lookups, answered from the configured hosts file (`dns_hosts_file`)
- `PTR` (reverse) lookups for `in-addr.arpa` names — see [Reverse lookups](#reverse-lookups)
- `IN` class only
- Multiple records for the same name (round-robin): every matching hosts file line contributes
  one forward answer
- `NXDOMAIN` for a name that appears nowhere in the hosts file (forward or reverse)
- `NOTIMP` for anything this server doesn't serve at all: a non-`QUERY` opcode, a non-`IN`
  class, or a query type other than `A`/`PTR` — this includes `AAAA`, always, regardless of
  whether the name has an `A` record
- Optional forwarding to an external DNS server for anything the hosts file can't answer, with
  every `AAAA` record stripped from the response — see
  [Forwarding to an external server](#forwarding-to-an-external-server)
- The response always echoes the query's `ID` and question section, and its `RD` (Recursion
  Desired) bit; `RA` (Recursion Available) is always `0` for a hosts-file answer — this server
  never recurses itself. A *forwarded* response otherwise reflects whatever the upstream server
  decided (its `RA`/`RD`/`RCODE`/answers), minus any `AAAA` records.

All hosts-file answers use a fixed TTL (`Dns\Handler::DEFAULT_TTL`, 300 seconds) — there is no
per-record TTL configuration, since a hosts file has no concept of one either. A forwarded
answer's TTL is whatever the upstream server sent, unchanged.

## Reverse lookups

A `PTR` query name is parsed as `<reversed-octets>.in-addr.arpa` (e.g.
`5.0.168.192.in-addr.arpa` → `192.168.0.5`). An `ip6.arpa` name is not recognised as a reverse
name at all — it isn't merely "unparsed", it's a scheme this server doesn't know about, so it
falls through to the same `NXDOMAIN` as any other unparseable `PTR` name.

The resulting IP is looked up against the *reverse* index built from the hosts file at startup:
for an IP appearing on a hosts file line, the answer is that line's **first** name — any further
names on the line are aliases, included in forward lookups but not used as `PTR` answers —
matching the convention glibc's `files` NSS module uses for `/etc/hosts`. If the same IP appears
on more than one line, every line's primary name is returned as a separate answer.

A `PTR` query name that doesn't parse as `in-addr.arpa` gets `NXDOMAIN` immediately — there's no
address to forward on the client's behalf in that case, so it's not attempted.

## Forwarding to an external server

Set `dns_forwarder_enabled = true` and `dns_forwarder_address` (e.g. `8.8.8.8:53`) to forward any
query the hosts file can't answer — forward or reverse — to an external DNS server, over UDP,
asynchronously on the ReactPHP event loop; a forwarded lookup never blocks the daemon from
handling other queries while it's in flight.

The forwarded query is the client's own packet, unchanged, except for its transaction ID: `dnsd`
assigns its own internal ID for the single shared connection to the upstream server (so two
different Econet clients that happen to pick the same random query ID can never collide there).
Before relaying the reply on, two things happen to the upstream's raw response, in this order:

1. **Every `AAAA` record is stripped** — from the answer, authority, *and* additional sections
   alike, via `DnsMessage::stripAaaaRecords()`. This is a real parse-and-rebuild, not a byte
   filter: each retained record's `NAME`, and any domain name embedded in its `RDATA`
   (`CNAME`/`NS`/`PTR`/`SOA`/`MX` — the RFC 1035 types that carry one, e.g. a `CNAME` chain
   terminating in the `A` record actually being asked for, which is common for real-world
   domains), is re-encoded fully expanded rather than reusing the original packet's compression,
   since a compression pointer is an offset into a packet whose layout removing records
   necessarily changes. Every other record type's `RDATA` (including `A` itself) is opaque bytes,
   copied verbatim. If the response can't be confidently parsed at all, it is **not** relayed —
   the query falls back to the same local answer forwarding would have produced if it weren't
   configured, rather than risk anything IPv6 slipping through unfiltered. See
   `Dns\Messages\DnsMessage::stripAaaaRecords()`.
2. **The transaction ID is rewritten** back to the original client's own query ID (the response
   up to that point still carries `dnsd`'s own internal forwarding ID).

Everything else in the upstream's response — flags, RCODE, question, and the RDATA of every
non-`AAAA` record — is relayed through unmodified.

If the upstream server doesn't reply within `dns_forwarder_timeout` seconds (default 2), or there
is currently no connection to it at all, the query falls back to exactly the answer it would have
gotten with forwarding disabled (`NXDOMAIN`).

Forwarding only ever happens for a query type this server itself understands (`A`/`PTR` - never
`AAAA`, which is refused before forwarding is even considered) and only once the hosts file has
already been checked and had nothing to offer — a query type this server doesn't handle at all
(e.g. `MX`) still gets an immediate `NOTIMP`, forwarder or not.

### Restricting which domains get forwarded

`dns_forwarder_allowed_domains` is an optional, comma-separated allow-list of domains (see
`docs/Config.md` → "DNS"). When empty (the default), forwarding is unrestricted — any name the
hosts file can't answer may be sent upstream. When set, a query name is only forwarded if it is
one of the listed domains, or a subdomain of one; anything else falls straight back to the local
`NXDOMAIN` answer without ever contacting the upstream server. The same list, and the same
matching rule, covers both forward and reverse names — `example.com` and
`168.192.in-addr.arpa` can sit in the same list, since both are just domain-name suffixes as far
as matching is concerned.

## What's not implemented

- **IPv6, in every form** — see above; this is a deliberate, permanent exclusion, not a gap.
- **Zone transfers, `NS`/`TXT`/`SRV`/`CNAME` records as a query type, and everything else RFC
  1035 defines beyond `A`/`PTR`.** A hosts file has no representation for any of these, and they
  get `NOTIMP` even when forwarding is enabled (forwarding only covers types this server
  understands from its own hosts file — see above). Note this is different from
  `stripAaaaRecords()` *recognising* `CNAME`/`NS`/`SOA`/`MX` well enough to re-encode their
  RDATA correctly inside a forwarded response — that's about safely relaying what an upstream
  server included alongside an `A`/`PTR` answer, not about serving those types as queries in
  their own right.
- **Multiple questions per packet.** A query with `QDCOUNT != 1` is dropped without a reply.
- **DNS compression pointers in a query's own question name.** A compliant stub resolver never
  sends one (there is nothing earlier in the packet for it to point at) — its presence is
  treated as malformed input and the packet is dropped. (A *response* being filtered for `AAAA`
  records is a different code path that does follow compression pointers, since real-world
  responses use them constantly — see `stripAaaaRecords()` above.)
- **EDNS0, DNSSEC, TCP fallback.** Only plain UDP queries under 512 bytes are handled from a
  client — which is unavoidably enough for anything a hosts file can answer.
- **Caching of forwarded answers.** Every query that isn't in the hosts file and is eligible for
  forwarding is forwarded again; there is no local cache of upstream responses.

## Hosts file format

Standard Unix `/etc/hosts` syntax: one address per line, followed by one or more names, `#`
starts a comment (to end of line), blank lines are ignored. **IPv4 addresses only** — an IPv6
address on a line is rejected the same way any other malformed line is (logged and skipped; it
does not prevent a *different* line for the same name from being read).

```
# The file server's Econet-facing IP, and a couple of aliases
192.168.0.1  fileserver fs

# A second interface on the same file server, for round-robin
192.168.0.2  fileserver
```

Name matching is case-insensitive and a trailing dot on the query name (`fileserver.`) is
stripped before lookup, matching normal DNS conventions. The same applies to `PTR` lookups in
reverse: see [Reverse lookups](#reverse-lookups) above for exactly which name a line contributes.

## Wire format quick reference

Standard RFC 1035 §4 layout — a 12-byte header, then the question section, then zero or more
answer resource records:

```
Offset  Size  Field
------  ----  -----
0       2     ID
2       2     Flags: QR(1) Opcode(4) AA(1) TC(1) RD(1) RA(1) Z(3) RCODE(4)
4       2     QDCOUNT
6       2     ANCOUNT
8       2     NSCOUNT
10      2     ARCOUNT
12+     n     Question section: QNAME (length-prefixed labels, zero-terminated), QTYPE, QCLASS
```

Each answer resource record this server generates itself (i.e. not a forwarded response, which is
relayed through `stripAaaaRecords()` rather than built fresh): a 2-byte name (always the
compression pointer `0xC0 0x0C`, pointing back at the question name at offset 12 — this server's
own answers are always for the one name that was asked about), `TYPE` (2 bytes), `CLASS`
(2 bytes, always `IN`), `TTL` (4 bytes), `RDLENGTH` (2 bytes), then `RDLENGTH` bytes of `RDATA`
(4 bytes for `A`, a wire-encoded domain name for `PTR`).

See `Dns\Messages\DnsMessage` for the exact encode/decode.
