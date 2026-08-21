# sharefsd — Developer Guide

`sharefsd` is a standalone daemon that serves ShareFS, Freeway discovery, and
Access+ authentication — see `docs/protocols/sharefs.md` for the wire
protocols themselves, which are **UDP/IP, not an Econet encapsulation**. This
document covers how the daemon and its code are put together: process model,
class structure, configuration, and the admin web interface.

Normally that means `sharefsd` needs its own IP reachability to whatever
network its ShareFS clients are on. An optional feature closes that gap for
Econet clients specifically: the **Remote Socket Protocol** relay (see
[Receiving traffic via a Remote Socket Protocol
relay](#receiving-traffic-via-a-remote-socket-protocol-relay) below) lets
`filestored` forward ShareFS/Freeway/Access+ UDP traffic arriving at an
EconetA interface across a WebSocket connection to `sharefsd`, so an Econet
client with an IP stack (see `docs/protocols/ipv4.md`) can reach ShareFS
shares without `sharefsd` needing any Econet transport of its own.

---

## Why a separate daemon

Every other transport this project speaks (AUN, Piconet, WebSocket,
RemoteBridge — see `docs/encapsulation.md`) carries `EconetPacket`s into the
single `filestored` process, gets routed by `ServiceDispatcher` to a port-owning
provider (see `docs/service-providers.md`), and shares `filestored`'s
`ServiceDispatcher`/`PacketDispatcher`/`Vfs`/`Security` state.

ShareFS has no Econet transport to route through `ServiceDispatcher` in the
first place — it's a UDP/IP-native protocol suite. So `sharefsd` is:

- its **own executable** (`src/sharefsd`) and **own OS process**, started and
  supervised independently of `filestored`;
- its **own ReactPHP event loop**, with its own three UDP sockets (Freeway,
  Access+, ShareFS data) — none of it goes through `PacketDispatcher` or
  `EncapsulationTypeMap`;
- its **own copy of `Vfs`'s and `Security`'s static state** — this only
  matters in that it needs its own login (see "The service identity" below);
  it reads the same on-disk user database and VFS tree `filestored` does.

What it *does* reuse from `filestored` is the `Vfs`, `Authentication\Security`,
and `config` classes directly (not copies), and the on-disk files they manage
— a ShareFS client sees the same files, at the same paths, as an Econet
client of the same server.

---

## The service identity

Real ShareFS/Access+ has no per-user login at all (see
`docs/protocols/sharefs.md`'s Access+ section) — a `Protected` share is
gated by its own password, not by who's asking. But every `Vfs`/`Security`
call in this codebase is scoped to a logged-in user at an Econet
network/station, so something has to be logged in for `Vfs` to work at all.

`sharefsd` resolves this by logging in **one fixed identity** at startup
(`sharefs_service_username`/`sharefs_service_password`, at the synthetic
address `sharefs_service_network`/`sharefs_service_station`) and using it for
every ShareFS operation from every client, for the lifetime of the process.
`Command\ShareFsd::execute()` refuses to start if that login fails. This is
deliberately not a per-client thing — see `ShareFsHandler::serviceAddress()`.

Per-share and per-command access control still applies on top of this (see
`Share::isProtected()`/`isReadOnly()` and `ShareAuthTable`) — the service
identity only answers "who does `Vfs` think is asking", not "is this client
allowed to do this".

---

## Architecture overview

```
UDP: Freeway (32770)    UDP: Access+ (32771)    UDP: ShareFS data (49171)
        │                        │                         │
  FreewayHandler          AccessPlusHandler          ShareFsHandler
        │                        │                         │
        │                 ShareAuthTable ◄──────────────────┘
        │                        │
    ShareList                    │
   (share config)                │
                                  ▼
                    Authentication\Security (one fixed
                    service identity, logged in at startup)
                                  │
                                 Vfs
                                  │
                         (same VFS tree filestored uses)

HTTP: admin web UI (8081) ── ShareFs\Admin\Kernel (separate Symfony micro-app)
```

`Command\ShareFsd::MainLoop()` wires all four services (three UDP sockets plus
the admin HTTP server) into one ReactPHP loop, alongside two periodic timers:
Freeway broadcast (every `FreewayPacket::DEFAULT_BROADCAST_INTERVAL` seconds)
and housekeeping (`ShareAuthTable::houseKeeping()`, `ShareFsHandler::houseKeeping()`
— expires stale streaming `RREAD`/`RWRITE`/`RRENAME` transactions — and
`Vfs::houseKeeping()`, on `housekeeping_interval`).

### Receiving traffic via a Remote Socket Protocol relay

Each of `FreewayHandler`, `AccessPlusHandler`, and `ShareFsHandler` is given
its traffic source through `setSocket(React\Datagram\SocketInterface)`, and
knows nothing about where that traffic actually comes from. Normally that's a
real UDP socket bound directly to the relevant port, as the diagram above
shows.

Set `sharefs_remote_socket_relay_enabled = true` to switch all three
handlers over to the relay instead: `Command\ShareFsd` connects a
`RemoteSocket\Client` (namespace `HomeLan\FileStore\RemoteSocket`, shared
with `filestored`) to a `filestored` instance's relay server, and gives each
handler a `RemoteSocket\RelayedUdpTransport` (one per port) — a
`SocketInterface` implementation backed by the WebSocket connection instead
of a UDP socket. The handlers' own code is unchanged either way; they never
know which kind of `SocketInterface` they were given.

This exists because an EconetA interface — an IP address reachable only over
Econet — is owned by `filestored`'s `IPv4` service provider, not by
`sharefsd`. Without the relay, an Econet client with an IP stack has no way
to reach `sharefsd`'s ShareFS ports at all, since nothing is listening on
them from that side. With the relay enabled, `filestored` forwards UDP
traffic addressed to one of its interfaces on the Freeway/Access+/ShareFS
data ports across the WebSocket connection to `sharefsd`, and relays its
replies back out over Econet addressed to the original sender — see
[docs/protocols/remote-socket.md](protocols/remote-socket.md) for the wire
protocol, and `docs/protocols/ipv4.md` → "UDP Relay" for the `filestored`
side of this.

---

## Classes

All classes below live under `HomeLan\FileStore\ShareFs` unless stated
otherwise.

### Shares

| Class | Role |
|---|---|
| `Share` | One configured share: name, VFS path, and independent `protected`/`readonly`/`hidden` attributes (see its own docblock for exactly what each does, and which the reference server itself doesn't actually enforce) |
| `ShareList` | Static holder for the configured `Share`s, loaded from the share list file. **Not** an encapsulation-style `Map` class — see [Naming](#naming-sharelist-vs-map) below |

### Authentication

| Class | Role |
|---|---|
| `ShareAuthTable` | Tracks which (client IP, share name) pairs have passed Access+ for a `Protected` share - a 10-minute sliding cache, nothing more. Deliberately not a session/identity table - see `docs/protocols/sharefs.md` → Access+ |

### Protocol handlers

| Class | Role |
|---|---|
| `FreewayHandler` | Periodically broadcasts every advertised share |
| `AccessPlusHandler` | Checks a share-key request's folded PIN against every `Protected` share's own password, recording a match in `ShareAuthTable` |
| `ShareFsHandler` | Dispatches the ShareFS RPC commands onto `Vfs`, running as the fixed service identity; tracks its own handle-ownership table so clients can't touch each other's handles |
| `ShareFsException` | Thrown by `ShareFsHandler` internals to report a specific POSIX errno back to the client, rather than the generic `EIO` used for unexpected failures |

Each handler's `setSocket()` takes a `React\Datagram\SocketInterface`, not the
concrete `React\Datagram\Socket` class — see [Receiving traffic via a Remote
Socket Protocol relay](#receiving-traffic-via-a-remote-socket-protocol-relay)
above for why. `RemoteSocket\Client` and `RemoteSocket\RelayedUdpTransport`
(namespace `HomeLan\FileStore\RemoteSocket`, shared with `filestored`) are
documented in [docs/protocols/remote-socket.md](protocols/remote-socket.md).

### Conversions

| Class | Role |
|---|---|
| `RiscOsMeta` | RISC OS timestamp/filetype/load-exec/attribute conversions shared by the packet layer and the handler - see `docs/protocols/sharefs.md` for the exact formulas |

### Wire messages

**Namespace:** `HomeLan\FileStore\ShareFs\Messages`

| Class | Role |
|---|---|
| `FreewayPacket` | Freeway broadcast packet encode/decode |
| `AccessPlusPacket` | Access+ share-key request/reply encode/decode, plus the PIN-folding algorithm (`foldPassword()`) |
| `ShareFsPacket` | ShareFS RPC request/reply envelope, `FileDesc` encode/decode, and per-command payload helpers |

### Admin adapters

These implement `HomeLan\FileStore\Encapsulation\EncapsulationAdminInterface`
directly (the same interface the AUN/WebSocket/Piconet/RemoteBridge admin
pages use — see `docs/encapsulation.md`) even though `sharefsd` has no
Econet encapsulation of its own; the interface's shape (name/status/entity
tables, no enable-disable) fits a read-only status view regardless.

| Class | `getId()` | Entity types |
|---|---|---|
| `ShareAdmin` | `shares` | `share` — every configured share, its path, and its protected/readonly/hidden attributes |
| `FreewayAdmin` | `freeway` | `advertisement` — the shares currently being advertised |
| `AccessPlusAdmin` | `accessplus` | `authentication` — active `ShareAuthTable` entries (client IP, share, expiry) |
| `ShareFsDataAdmin` | `sharefsdata` | `handle` — every open `Vfs` handle (network/station/handle/path/type), via `Vfs::getOpenHandles()` |

### Entry point

| Class | Role |
|---|---|
| `Command\ShareFsd` | The Symfony `Command` (`src/include/classes/Command/ShareFsd.php`) that logs in the service identity, then builds the ReactPHP loop, the three UDP services, the admin HTTP server, and the two periodic timers |

`src/sharefsd` is the executable wrapper script (mirrors `src/filestored`):
it builds a logger and runs `Command\ShareFsd` via
`Console\SingleCommandApplication`.

### Naming: `ShareList`, not `Map`

Every `Map` class elsewhere in this codebase (`Aun\Map`, `WebSocket\Map`,
`Piconet\Map`, `RemoteBridge\Map`) translates between an Econet
network/station address and some encapsulation-specific address (an IP, a
websocket connection, a TCP connection). `ShareFs\ShareList` does nothing of
the sort — ShareFS has no Econet transport to translate to or from (see
`docs/protocols/sharefs.md`'s opening note); it's just the configured list of
shares, so it's named accordingly rather than as a `Map`.

---

## Configuration

Full details and defaults: `docs/Config.md` → "ShareFS / Access+". Summary:

| Key | Default | Purpose |
|---|---|---|
| `sharefs_share_list_file` | `sharelist.txt` | Path to the `ShareList` config file (`SHARE <name> <path> [attributes] [password]` lines) |
| `sharefs_service_username` / `sharefs_service_password` | *(none — required)* | The one fixed identity every ShareFS operation runs as |
| `sharefs_service_network` / `sharefs_service_station` | `254` / `1` | The synthetic address that identity logs in at |
| `sharefs_listen_address` | `0.0.0.0` | Bind address for all three UDP sockets |
| `sharefs_freeway_broadcast_address` | `255.255.255.255` | Where Freeway broadcasts are sent |
| `sharefs_freeway_port` | `32770` | Freeway discovery UDP port |
| `sharefs_accessplus_port` | `32771` | Access+ authentication UDP port |
| `sharefs_sharefsdata_port` | `49171` | ShareFS file-data RPC UDP port |
| `sharefs_host_name` | *(empty → OS hostname)* | Host name this server advertises in Freeway broadcasts |
| `sharefs_webadmin_listen_address` | `0.0.0.0` | Bind address for the admin web UI |
| `sharefs_webadmin_listen_port` | `8081` | Admin web UI port — deliberately different from `filestored`'s `webadmin_listen_port` (`8080`) so both can run on the same host |
| `sharefs_remote_socket_relay_enabled` | `false` | Receive UDP traffic via a `filestored` Remote Socket Protocol relay instead of binding UDP sockets directly — see above |
| `sharefs_remote_socket_relay_address` | `127.0.0.1:8091` | `host:port` of the relay server |
| `sharefs_remote_socket_relay_secret` | *(none — required if the relay is enabled)* | Shared secret; must match the relay server's `remote_socket_relay_secret`. A mismatch is fatal only when the relay is enabled at all — see `docs/protocols/remote-socket.md` → "Authentication Failure" |

`sharefsd` also reads `vfs_plugins`, `security_mode`, `security_*`, and
`housekeeping_interval` — the same keys `filestored` uses, since it's the same
`Vfs`/`Security`/`config` machinery.

---

## Share list file format

```
SHARE <name> <econet_vfs_path> [attribute,attribute,...] [password]
```

Attributes (comma-separated, all optional): `protected`, `readonly`,
`hidden` — see `Share`'s own docblock. `password` is required if and only if
`protected` is present.

```
# Fully open, advertised via Freeway
SHARE DISC0 $.DISC0

# Mountable, advertised, but rejects write-type commands
SHARE ARCHIVE $.ARCHIVE readonly

# Mountable by name, but never advertised
SHARE SPARE $.SPARE hidden

# Requires an Access+ share-password match before any use; never advertised
SHARE PRIVATE $.PRIVATE protected secretpw

# Both at once
SHARE BACKUP $.BACKUP protected,readonly backuppw
```

---

## Admin web interface

`sharefsd` runs its own, entirely separate Symfony micro-app for its admin UI
— **not** `filestored`'s `Admin\Kernel`, which hardcodes its `$projectDir` and
cache/log paths relative to its own location and can't be pointed at a second
app's config/templates. Its cache and log directories
(`var/sharefs_cache`, `var/sharefs_log`) and template compile directory
(`var/sharefs_templates_c/`) are named distinctly from `filestored`'s so both
admin UIs can run on the same host without colliding.

| File | Role |
|---|---|
| `ShareFs\Admin\Kernel` | Symfony micro-kernel, own `config/`, own `templates/` |
| `ShareFs\Admin\Service\Smarty` | Constructs the Smarty engine, pointed at `ShareFs/Admin/templates/` |
| `ShareFs\Admin\Controller\IndexController` | Front page — lists the four admin components (`ShareAdmin`, `FreewayAdmin`, `AccessPlusAdmin`, `ShareFsDataAdmin`) |
| `ShareFs\Admin\Controller\ComponentController` | Per-component detail page (`/component?type=…`), generic over `EncapsulationAdminInterface` |
| `ShareFs\Admin\templates\component.tpl` | Same generic entity-table rendering as `filestored`'s `encapsulation.tpl` |

`Command\ShareFsd::adminService()` wires this into the ReactPHP loop the same
way `Command\React::adminService()` does for the main app's admin UI: a
`React\Http\HttpServer` callback that bridges a PSR-7 request into a Symfony
`Request`, calls the kernel, and bridges the response back.

No session/cookie handling is set up for this admin app (unlike
`filestored`'s, which needs `SessionCookie`/`ReactSessionStorage` for its
login-gated forms) — every page here is a read-only status view built
straight from `EncapsulationAdminInterface`, so there's no per-visitor state
to keep.

---

## Key files at a glance

| File | Role |
|---|---|
| `src/sharefsd` | Executable entry point |
| `src/include/classes/Command/ShareFsd.php` | Service-identity login and event loop wiring |
| `src/include/classes/ShareFs/Share.php` | One configured share |
| `src/include/classes/ShareFs/ShareList.php` | Configured share list (loaded from `sharefs_share_list_file`) |
| `src/include/classes/ShareFs/ShareAuthTable.php` | Per (client IP, share) Access+ authentication cache |
| `src/include/classes/ShareFs/RiscOsMeta.php` | RISC OS timestamp/filetype/attribute conversions |
| `src/include/classes/ShareFs/FreewayHandler.php` | Freeway discovery broadcast |
| `src/include/classes/ShareFs/AccessPlusHandler.php` | Access+ per-share-password authentication |
| `src/include/classes/ShareFs/ShareFsHandler.php` | ShareFS RPC protocol |
| `src/include/classes/ShareFs/ShareFsException.php` | Errno-carrying exception for `ShareFsHandler` |
| `src/include/classes/ShareFs/Messages/*.php` | Wire packet encode/decode |
| `src/include/classes/ShareFs/ShareAdmin.php`, `FreewayAdmin.php`, `AccessPlusAdmin.php`, `ShareFsDataAdmin.php` | Admin adapters |
| `src/include/classes/ShareFs/Admin/` | Separate Symfony micro-app for the admin web UI |
| `src/include/classes/RemoteSocket/Client.php` | Connects to a `filestored` relay server; used when `sharefs_remote_socket_relay_enabled` is set (shared namespace with `filestored`, not under `ShareFs`) |
| `src/include/classes/RemoteSocket/RelayedUdpTransport.php` | `SocketInterface` given to the protocol handlers when the relay is in use |
| `unit-tests/sharefs/` | Test suite |
| `unit-tests/remotesocket/` | Test suite for `RemoteSocket\Client`/`RelayedUdpTransport`/`RelayServer`/`Frame` |
