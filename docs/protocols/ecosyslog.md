# EcoSyslog

EcoSyslog is an Econet version of syslog: any station can transmit a single unicast packet to
port `0xB6` to log a message, and there is no reply — exactly like real UDP syslog, which
tolerates a dropped datagram the same way. It doubles as the sample provider for the
[Remote Provider Protocol](remote-provider.md): it runs in its own daemon (`ecosyslogd`),
entirely separate from `filestored`, and is a good first example precisely because it needs none
of that protocol's excluded features (no ACK'd streams, no reply queueing at all).

## Wire format

The packet's data (`EconetPacket::getData()`) is:

```
byte 0:      severity — a standard syslog level, 0 (Emergency) to 7 (Debug)
bytes 1..n:  message text (arbitrary length, no particular encoding assumed beyond being text)
```

| Severity | Meaning |
|---|---|
| 0 | Emergency |
| 1 | Alert |
| 2 | Critical |
| 3 | Error |
| 4 | Warning |
| 5 | Notice |
| 6 | Informational |
| 7 | Debug |

An empty payload is ignored. An out-of-range or unrecognised severity byte falls back to
Informational rather than being rejected — a malformed severity shouldn't cost the message.

There is no session, no login, and no acknowledgement — a station transmits and moves on. The
sending station's own `network.station` (from the packet's source address) is attached to the log
entry automatically; a client does not need to identify itself in the payload.

Both unicast and broadcast delivery are treated identically — a station broadcasting a log
message (e.g. "this happened, and I don't know who if anyone is listening") works the same as
addressing it directly.

## Where messages go

`Services\Provider\EcoSyslog` itself has no storage logic at all — it just calls
`LoggerInterface::log()` with the decoded severity and message. Where that ends up is entirely a
property of which handlers `Command\EcoSyslogd` puts on the `Monolog\Logger` instance the
provider is constructed with:

| Config key | Effect |
|---|---|
| `ecosyslog_local_enabled` (default `true`) | Pushes `Monolog\Handler\SyslogHandler` — messages go to the local OS syslog. |
| `ecosyslog_remote_enabled` (default `false`) | Pushes `Monolog\Handler\SyslogUdpHandler`, pointed at `ecosyslog_remote_host`:`ecosyslog_remote_port` — messages are shipped as RFC 5424/3164 syslog over UDP to a remote collector, for centralised storage. |

Both can be enabled at once — a message then goes to both the local syslog and the remote
collector, since Monolog fans a record out to every handler pushed onto the logger. No bespoke
forwarding code was needed for the remote case; it's the same handler class any other PHP
application on this stack would use to talk to a remote syslog server.

The daemon's own operational log (connection state, reconnect attempts) uses a *separate* logger
instance (`$this->oLogger`, the one `Command\EcoSyslogd` itself was constructed with) — so relayed
Econet log traffic never gets interleaved with the daemon's own lifecycle noise.

## Why a separate daemon

Same underlying reasoning as `dnsd`/`ntpd` (see `docs/NTPD.md` → "Why a separate daemon"):
`EcoSyslog` shares no state with `filestored` and needs nothing `ServiceDispatcher` doesn't
already provide on its own — no `Vfs`, no `Authentication\Security`, no local data file. It exists
as a daemon mainly to *demonstrate* the Remote Provider Protocol end to end with the smallest
possible real provider, not because logging inherently demands its own process.

## Architecture overview

```
            filestored: ServiceDispatcher + ProxyProvider (reserves port 0xB6)
                          │
                  Remote Provider Protocol
                  (WebSocket, Econet port 0xB6 registered)
                          │
             RemoteProvider\Client / Host
                          │
                Services\Provider\EcoSyslog
                          │
              Monolog\Logger (local syslog and/or remote UDP collector)
```

`Command\EcoSyslogd::MainLoop()` builds the dedicated Monolog logger, constructs a real
`ServiceDispatcher` with `[new EcoSyslog($oEcoLogger)]`, and wires a `RemoteProvider\Client` /
`Host` pair on top of it exactly as described in
[remote-provider.md](remote-provider.md#overview).

## Classes

| Class | Role |
|---|---|
| `HomeLan\FileStore\Services\Provider\EcoSyslog` | The provider itself — decodes the severity byte, logs the rest. `getReplies()` always returns `[]`. |
| `HomeLan\FileStore\Command\EcoSyslogd` | The Symfony `Command` (`src/include/classes/Command/EcoSyslogd.php`) that builds the logger, the local `ServiceDispatcher`, and the relay connection, and runs the ReactPHP loop. |

`src/ecosyslogd` is the executable wrapper script (mirrors `src/dnsd`, `src/ntpd`): it builds a
logger and runs `Command\EcoSyslogd` via `Console\SingleCommandApplication`.

## Configuration

Full details and defaults: `docs/Config.md` → "EcoSyslog". Summary:

| Key | Default | Purpose |
|---|---|---|
| `ecosyslog_remote_provider_relay_address` | `127.0.0.1:8092` | `host:port` of the `filestored` Remote Provider relay |
| `ecosyslog_remote_provider_relay_secret` | *(none — required)* | Shared secret; must match `remote_provider_relay_secret` on the `filestored` side |
| `ecosyslog_local_enabled` | `true` | Store via the local OS syslog |
| `ecosyslog_remote_enabled` | `false` | Also/instead ship to a remote syslog collector |
| `ecosyslog_remote_host` / `ecosyslog_remote_port` | *(none)* / `514` | The remote syslog collector |
| `ecosyslog_remote_facility` | `LOG_LOCAL0` | Syslog facility for forwarded messages — must name a PHP `LOG_*` constant |

`filestored`'s own `ProxyProvider` instantiation in `src/filestored` must include `0xB6` in its
reserved port list for any of this to receive traffic at all — see
[remote-provider.md](remote-provider.md#overview).

## Key files at a glance

| File | Role |
|---|---|
| `src/ecosyslogd` | Executable entry point |
| `src/include/classes/Command/EcoSyslogd.php` | Logger setup, `ServiceDispatcher`/relay wiring, event loop |
| `src/include/classes/Services/Provider/EcoSyslog.php` | The provider: severity decode, log |
| `src/include/classes/RemoteProvider/` | Shared with `filestored`, not under `EcoSyslog` — see [remote-provider.md](remote-provider.md) |

## See also

- `bbc-tests/ECOLOG.BBC` — a BBC BASIC client implementing this protocol: `FNekolog(severity,
  message$)` and one convenience wrapper per severity (`FNekolog_emergency`, `FNekolog_alert`, …
  `FNekolog_debug`), meant to be copied into another program. Running it directly sends one test
  message at every severity to prove connectivity.
