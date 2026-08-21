# ntpd — Developer Guide

`ntpd` is a standalone daemon that answers NTP client requests (RFC 5905) from the host system
clock — see `docs/protocols/ntp.md` for exactly what subset of NTP is implemented. This document
covers how the daemon and its code are put together.

Like `dnsd`, it has **no transport of its own at all**: it never binds a real UDP socket. It
always receives its traffic over a Remote Socket Protocol connection to a `filestored` instance's
EconetA interface (see `docs/protocols/remote-socket.md`) — an Econet client is `ntpd`'s only
reason to exist, so there was no reason to build the direct-UDP code path `sharefsd` keeps for
compatibility with real ShareFS clients that expect to find it on a conventional port.

---

## Why a separate daemon

Same underlying reason as `sharefsd` and `dnsd` (see `docs/SHAREFSD.md` → "Why a separate
daemon"): NTP is a UDP/IP-native protocol with nothing to route through `ServiceDispatcher`,
`PacketDispatcher`, or `EncapsulationTypeMap` — there is no `EconetPacket` involved anywhere in
answering a request.

`ntpd` is the simplest of the three daemons: it shares no state with `filestored` at all, not
even a local data file the way `dnsd` has a hosts file. Answering a request needs nothing but the
host system clock and the two configured values (`ntp_stratum`, `ntp_reference_id`) — there's no
`Vfs`, `Authentication\Security`, or `HostsFile`-equivalent involved. Its only shared dependency
with the rest of this project is the `config` class and, structurally, the
`RemoteSocket\Client`/`RelayedUdpTransport` classes it uses to receive traffic, which live in a
namespace shared with `filestored` (`HomeLan\FileStore\RemoteSocket`) rather than under `Ntp`.

---

## Architecture overview

```
            filestored: IPv4 provider (owns an EconetA interface)
                          │
                  Remote Socket Protocol
                  (WebSocket, UDP/123 registered)
                          │
             RemoteSocket\Client / RelayedUdpTransport
                          │
                     Ntp\Handler
                          │
              host system clock (microtime(true))
```

`Command\Ntpd::MainLoop()` connects the `RemoteSocket\Client`, registers for `ntp_port` (default
`123`), and wires the resulting `RelayedUdpTransport`'s `'message'` event straight to
`Ntp\Handler::receive()`. There is no periodic timer of any kind, and no state carried between
requests — every request is answered independently from whatever the system clock reads at that
moment.

---

## Classes

All classes below live under `HomeLan\FileStore\Ntp` unless stated otherwise.

| Class | Role |
|---|---|
| `Handler` | Given its traffic source through `setSocket(React\Datagram\SocketInterface)`, exactly like `Dns\Handler`. Decodes each request, ignores anything that isn't a mode-3 client request, and replies with a mode-4 server response built from the current system time and the configured stratum/reference ID |
| `Messages\NtpMessage` | Decodes the 48-byte request header and encodes the matching response — the RFC 5905 wire format itself, including the fixed-point NTP timestamp encoding |

### Entry point

| Class | Role |
|---|---|
| `Command\Ntpd` | The Symfony `Command` (`src/include/classes/Command/Ntpd.php`) that connects the relay client and runs the ReactPHP loop |

`src/ntpd` is the executable wrapper script (mirrors `src/filestored`, `src/sharefsd`, and
`src/dnsd`): it builds a logger and runs `Command\Ntpd` via `Console\SingleCommandApplication`.

---

## Configuration

Full details and defaults: `docs/Config.md` → "NTP". Summary:

| Key | Default | Purpose |
|---|---|---|
| `ntp_port` | `123` | The UDP port registered with the relay server — the port Econet clients must address their NTP requests to |
| `ntp_stratum` | `1` | Stratum reported in every reply — see `docs/protocols/ntp.md` → "Time source and stratum" |
| `ntp_reference_id` | `LOCL` | Reference ID reported in every reply (4 ASCII characters; longer is truncated, shorter is zero-padded) |
| `ntp_remote_socket_relay_address` | `127.0.0.1:8091` | `host:port` of the `filestored` relay server |
| `ntp_remote_socket_relay_secret` | *(none — required)* | Shared secret; must match the relay server's `remote_socket_relay_secret`. A mismatch is fatal — see `docs/protocols/remote-socket.md` → "Authentication Failure" |

`ntpd` reads nothing else from the shared config — no `vfs_plugins`, no `security_*`, no
`housekeeping_interval`, since none of that machinery applies to reading the system clock.

---

## Key files at a glance

| File | Role |
|---|---|
| `src/ntpd` | Executable entry point |
| `src/include/classes/Command/Ntpd.php` | Relay connection setup and event loop wiring |
| `src/include/classes/Ntp/Handler.php` | Request handling: decode, check mode, encode, reply |
| `src/include/classes/Ntp/Messages/NtpMessage.php` | RFC 5905 wire format encode/decode |
| `src/include/classes/RemoteSocket/Client.php` | Connects to a `filestored` relay server (shared namespace with `filestored`, not under `Ntp`) |
| `src/include/classes/RemoteSocket/RelayedUdpTransport.php` | `SocketInterface` given to `Ntp\Handler` |
| `unit-tests/ntp/` | Test suite |
