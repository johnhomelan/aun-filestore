# dnsd — Developer Guide

`dnsd` is a standalone daemon that answers DNS queries (RFC 1035) from a Unix-style hosts file
— see `docs/protocols/dns.md` for exactly what subset of DNS is implemented. This document
covers how the daemon and its code are put together.

It is IPv4-only throughout, deliberately: EconetA, its only client, has no IPv6 support, and
IPv6 information reaching an Econet client is likely to break it. This isn't only "not
implemented" - a response forwarded from an external DNS server has every `AAAA` record actively
stripped out before being relayed on, not merely left unserved locally. See
`docs/protocols/dns.md` for exactly where this is enforced.

Unlike `sharefsd`, `dnsd` has **no transport of its own at all**: it never binds a real UDP
socket. It always receives its traffic over a Remote Socket Protocol connection to a `filestored`
instance's EconetA interface (see `docs/protocols/remote-socket.md`) — a plain, unauthenticated,
Econet-reachable DNS server has no use case this project needs to support, so there was no reason
to build the direct-UDP code path `sharefsd` keeps for compatibility with real ShareFS clients
that expect to find it on a conventional port.

---

## Why a separate daemon

Same underlying reason as `sharefsd` (see `docs/SHAREFSD.md` → "Why a separate daemon"): DNS is
a UDP/IP-native protocol with nothing to route through `ServiceDispatcher`, `PacketDispatcher`,
or `EncapsulationTypeMap` — there is no `EconetPacket` involved anywhere in answering a query.

`dnsd` is lighter than `sharefsd` in one respect: it shares none of `filestored`'s state. It
doesn't touch `Vfs`, `Authentication\Security`, or the user database — a hosts file lookup has no
concept of a logged-in user, so there's no equivalent of `sharefsd`'s "one fixed service
identity" to set up. Its only shared dependency with the rest of this project is the `config`
class and, structurally, the `RemoteSocket\Client`/`RelayedUdpTransport` classes it uses to
receive traffic, which live in a namespace shared with `filestored` (`HomeLan\FileStore\RemoteSocket`)
rather than under `Dns`.

---

## Architecture overview

```
                    filestored: IPv4 provider (owns an EconetA interface)
                                  │
                          Remote Socket Protocol
                          (WebSocket, UDP/53 registered)
                                  │
                     RemoteSocket\Client / RelayedUdpTransport
                                  │
                            Dns\Handler ───────► Dns\DomainFilter
                             │        │           (dns_forwarder_allowed_domains)
                             ▼        ▼
                      Dns\HostsFile   Dns\Forwarder ───► external DNS server
                    (loaded once        (only reached when HostsFile has no
                     at startup)         answer and forwarding is enabled)
```

`Command\Dnsd::MainLoop()` connects the `RemoteSocket\Client`, registers for `dns_port` (default
`53`), and wires the resulting `RelayedUdpTransport`'s `'message'` event straight to
`Dns\Handler::receive()`; if `dns_forwarder_enabled` is set it also builds a `Dns\Forwarder` and
`Dns\DomainFilter` and gives them to the `Handler`. There is no periodic timer (no broadcast, no
housekeeping; a hosts file has nothing that goes stale the way a streaming file transfer or an
Access+ auth cache entry does) — `Forwarder` does use one-shot timers, but only per in-flight
forwarded query, to enforce `dns_forwarder_timeout`.

---

## Classes

All classes below live under `HomeLan\FileStore\Dns` unless stated otherwise.

| Class | Role |
|---|---|
| `HostsFile` | Static holder for the parsed hosts file — see `docs/protocols/dns.md` for the file format. Loaded once at startup via `init()`, the same pattern as `ShareFs\ShareList`. IPv4 addresses only - an IPv6 line is rejected like any other malformed one. Indexes both directions: name → address for `A`, and address → a line's primary name for `PTR` |
| `Handler` | Given its traffic source through `setSocket(React\Datagram\SocketInterface)`, exactly like `ShareFs`'s protocol handlers. Decodes each query, looks it up in `HostsFile`, and either sends back the encoded response directly or - if nothing was found and a `Forwarder` is configured and the name passes `DomainFilter` - forwards it and relays the (asynchronous, `AAAA`-filtered) reply on once it arrives. An `AAAA` query itself always gets `NOTIMP`, before either `HostsFile` or `Forwarder` is even consulted |
| `Forwarder` | Optional. Forwards a query to an external DNS server over a single shared UDP connection, asynchronously on the ReactPHP event loop; demultiplexes concurrent forwarded queries by its own internally-assigned transaction id, independent of whichever id the original client picked. One instance is shared by every query that needs to forward. Purely a byte-level relay - it has no opinion on record content; `Handler` is what calls `DnsMessage::stripAaaaRecords()` on what it returns before relaying it on |
| `DomainFilter` | Optional. Parses `dns_forwarder_allowed_domains` into a domain-suffix allow-list; `isAllowed()` decides whether a given query name may be forwarded. An empty list (the default) means no restriction |
| `Messages\DnsMessage` | Decodes a single-question query and encodes the matching response — the RFC 1035 wire format itself, including reverse-name parsing (`ipFromPtrName()`, IPv4 `in-addr.arpa` only), domain-name wire encoding (`encodeDomainName()`, used for the question section and `PTR` answer RDATA), and `stripAaaaRecords()` - a full compression-aware parse and rebuild of a forwarded response with every `AAAA` record removed, used because a plain byte filter can't safely drop a record from the middle of a packet that uses name compression |

### Entry point

| Class | Role |
|---|---|
| `Command\Dnsd` | The Symfony `Command` (`src/include/classes/Command/Dnsd.php`) that loads the hosts file, connects the relay client, and runs the ReactPHP loop |

`src/dnsd` is the executable wrapper script (mirrors `src/filestored` and `src/sharefsd`): it
builds a logger and runs `Command\Dnsd` via `Console\SingleCommandApplication`.

---

## Configuration

Full details and defaults: `docs/Config.md` → "DNS". Summary:

| Key | Default | Purpose |
|---|---|---|
| `dns_hosts_file` | `hosts.txt` | Path to the hosts file (see `docs/protocols/dns.md` for the format) |
| `dns_port` | `53` | The UDP port registered with the relay server — the port Econet clients must address their DNS queries to |
| `dns_remote_socket_relay_address` | `127.0.0.1:8091` | `host:port` of the `filestored` relay server |
| `dns_remote_socket_relay_secret` | *(none — required)* | Shared secret; must match the relay server's `remote_socket_relay_secret`. A mismatch is fatal — see `docs/protocols/remote-socket.md` → "Authentication Failure" |
| `dns_forwarder_enabled` | `false` | Forward whatever `HostsFile` can't answer to an external DNS server — see `docs/protocols/dns.md` → "Forwarding to an external server" |
| `dns_forwarder_address` | *(none — required if enabled)* | `host:port` of the external DNS server |
| `dns_forwarder_timeout` | `2` | Seconds to wait for the external server before falling back to the local `NXDOMAIN` answer |
| `dns_forwarder_allowed_domains` | *(empty — no restriction)* | Comma-separated allow-list restricting which names may be forwarded (forward and reverse domains mixed freely) |

`dnsd` also reads nothing else from the shared config — no `vfs_plugins`, no `security_*`, no
`housekeeping_interval`, since none of that machinery applies to a hosts-file lookup.

---

## Key files at a glance

| File | Role |
|---|---|
| `src/dnsd` | Executable entry point |
| `src/include/classes/Command/Dnsd.php` | Relay connection setup and event loop wiring |
| `src/include/classes/Dns/HostsFile.php` | Parsed hosts file (loaded from `dns_hosts_file`), forward and reverse |
| `src/include/classes/Dns/Handler.php` | Query handling: decode, look up, encode, reply, and the forwarding decision |
| `src/include/classes/Dns/Forwarder.php` | Async forwarding to an external DNS server |
| `src/include/classes/Dns/DomainFilter.php` | `dns_forwarder_allowed_domains` allow-list matching |
| `src/include/classes/Dns/Messages/DnsMessage.php` | RFC 1035 wire format encode/decode, including reverse-name parsing |
| `src/include/classes/RemoteSocket/Client.php` | Connects to a `filestored` relay server (shared namespace with `filestored`, not under `Dns`) |
| `src/include/classes/RemoteSocket/RelayedUdpTransport.php` | `SocketInterface` given to `Dns\Handler` |
| `unit-tests/dns/` | Test suite |
