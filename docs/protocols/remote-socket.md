# Remote Socket Protocol

The Remote Socket Protocol relays UDP (and, in future, TCP) traffic arriving at one process's network interface to another process over a WebSocket connection, and its replies back again. It is transport-agnostic by design and generic across ports: its first use was letting `sharefsd` receive ShareFS/Freeway/Access+ traffic for an EconetA interface owned by `filestored` (see `docs/SHAREFSD.md`); `dnsd`, a standalone DNS server answering queries from a hosts file (see `docs/DNSD.md`), and `ntpd`, a standalone NTP server answering requests from the host system clock (see `docs/NTPD.md`), are further independent consumers, registered on UDP ports 53 and 123 respectively. None of them required any change to the relay itself — nothing in the protocol is specific to any one of them.

## Overview

`filestored`'s `IPv4` service provider owns one or more EconetA interfaces, each with its own IP address (see [IPv4 over Econet](ipv4.md)). Traffic on ports nothing local is listening on would otherwise simply be dropped. The Remote Socket Protocol relay server lets a separate process — typically `sharefsd`, running as its own daemon with no Econet transport of its own (see [sharefsd](../SHAREFSD.md)) — register interest in specific (protocol, port) pairs and receive that traffic instead, replying back through the same connection so `filestored` can send the reply back out over Econet addressed correctly.

```
BBC Micro ── EconetA ── [filestored: IPv4 provider] ══ WebSocket ══ [sharefsd: RemoteSocket\Client]
```

`filestored` (`HomeLan\FileStore\RemoteSocket\RelayServer`) is always the listening side; `sharefsd` (`HomeLan\FileStore\RemoteSocket\Client`) is always the connecting side. This is fixed by the protocol's use case — the process that owns the network interface is the one with something worth connecting to — not something either side negotiates.

## Feature Gate

Disabled by default on both sides.

On `filestored`:

| Config key | Meaning |
|---|---|
| `remote_socket_relay_enabled` | Must be `true` to start the relay server at all. |
| `remote_socket_relay_listen_address` | Address the relay WebSocket server binds to. Default `0.0.0.0`. |
| `remote_socket_relay_listen_port` | Port the relay WebSocket server binds to. Default `8091` — a separate port from `websocket_listen_port`, which is filestored's client-facing (AUN-over-WebSocket) service. |
| `remote_socket_relay_secret` | Shared secret. No default; must be set for the feature to work. |

On `sharefsd`:

| Config key | Meaning |
|---|---|
| `sharefs_remote_socket_relay_enabled` | Must be `true` for sharefsd to connect to a relay instead of binding its own UDP sockets for Freeway/Access+/ShareFS data. |
| `sharefs_remote_socket_relay_address` | `host:port` of the filestored relay server. Default `127.0.0.1:8091`. |
| `sharefs_remote_socket_relay_secret` | Shared secret. Must match `remote_socket_relay_secret` on the filestored side. |

When `sharefs_remote_socket_relay_enabled` is `false` (the default), sharefsd binds its own UDP sockets exactly as if the relay didn't exist — the relay is purely additive.

## Framing

Every frame is a single JSON object, sent as one WebSocket text message, with a `type` field naming the frame and additional type-specific fields alongside it:

```json
{"type": "hello", "versions": ["1.0"], "secret": "..."}
```

## Versioning

`hello` carries the list of protocol versions the connecting side supports, highest first. The listening side picks the highest version it also supports and confirms it in `hello_ok`; if there is no overlap it sends `version_reject` (listing its own supported versions) and closes the connection.

Version strings follow `MAJOR.MINOR`. This document describes version **1.0**. This protocol is unpublished: version 1.0 is edited in place while under development, rather than incremented. A new version number is only cut once a version has been published outside this project — see `Frame::PROTOCOL_VERSION` in `src/include/classes/RemoteSocket/Messages/Frame.php`.

## Handshake

```
CLIENT → SERVER:  hello       {versions, secret}
SERVER → CLIENT:  hello_ok    {version}
```

or, on failure, one of:

```
SERVER → CLIENT:  version_reject  {versions}     (no version in common; connection then closed)
SERVER → CLIENT:  auth_fail        {}             (secret did not match; connection then closed)
```

`secret` is compared with a constant-time comparison (`hash_equals()`); the server never distinguishes "wrong secret" from any other auth failure in its response, to avoid giving an attacker a signal.

No frame other than `hello` is processed before authentication succeeds — a `register` or `data` frame sent before `hello_ok` is silently ignored.

## Registration

Once authenticated, the client declares which (protocol, port) pairs it wants relayed traffic for:

```
CLIENT → SERVER:  register       {services: [{protocol, port}, ...]}
SERVER → CLIENT:  register_ok    {protocol, port}       (one per entry, on success)
SERVER → CLIENT:  register_fail  {protocol, port, reason}  (one per entry, on failure)
```

Only one client may be registered for a given (protocol, port) pair at a time — this mirrors `ServiceDispatcher`'s own "port already in use" rule for local port claims. A second `register` for a pair already claimed by another connection gets `register_fail`. Registrations are released when the connection that made them closes.

A client may register for multiple pairs in one `register` frame, or send further `register` frames later to add more.

## Data Frames

Once registered, traffic for that (protocol, port) flows as `data` frames in either direction:

```json
{
  "type": "data",
  "protocol": "UDP",
  "localAddr": "192.168.0.1",
  "localPort": 32770,
  "remoteAddr": "192.168.0.5",
  "remotePort": 41230,
  "streamId": null,
  "payload": "ZnJlZXdheSBicm9hZGNhc3Q="
}
```

`localAddr`/`localPort` is always the interface-facing side — whichever process owns the network interface the packet arrived at (`filestored`, for EconetA). `remoteAddr`/`remotePort` is always the far side (the Econet client, in ShareFS's case). A reply going the other way echoes the same four fields unchanged, exactly as a real bound UDP socket's own `sendto()`/`recvfrom()` pair already would — the receiving side does not need to be told which frame a reply corresponds to, only where it came from and where it's going.

`streamId` is reserved and always `null` for UDP. A future TCP mode, which needs to tell multiple concurrent connections between the same address pair apart, will use it — its presence in the wire format from version 1.0 onward means adding TCP support later needs no breaking change.

`payload` is the raw packet payload (the UDP payload past the 8-byte UDP header, in the ShareFS case), base64-encoded.

There is no acknowledgement of a `data` frame at the protocol level; delivery relies on the underlying WebSocket/TCP connection.

## Heartbeat

```
ping
pong
```

Either side may send `ping`; a `pong` reply is sent immediately. Neither carries any fields. This is a purely optional liveness check — the protocol does not mandate a cadence or an idle-timeout close on either side.

## Reconnection

If the connection to the relay server drops, `Client` reconnects automatically after a fixed delay (5 seconds) and repeats the full handshake — `hello`, then `register` for every port a caller previously asked for via `getTransport()`. Traffic is simply unavailable on that port for the duration of the outage; there is no buffering of packets that arrive at the interface while a client is disconnected — `RelayServer::relayInbound()` returns `false` and the caller drops the packet, exactly as if nothing were registered for that port at all.

This applies to a dropped or refused *connection* — `filestored` restarting or not yet being up.

## Authentication Failure

A rejected *secret* is handled differently from a dropped connection: `Client::handleFrame()` throws `RemoteSocket\Exceptions\AuthenticationFailedException` on `auth_fail` instead of reconnecting, since a wrong secret won't fix itself on retry. Every daemon built on `Client` (`sharefsd`, `dnsd`, `ntpd`) wraps its event loop's `run()` call in a catch for that exception, logs it, and exits — for `dnsd`/`ntpd` this is always reachable, since they always connect; for `sharefsd` it's only reachable when `sharefs_remote_socket_relay_enabled` is set, since otherwise no `Client` is ever constructed and no `hello` is ever sent at all.

## Class Reference

| Class | Role |
|---|---|
| `HomeLan\FileStore\RemoteSocket\Messages\Frame` | Encodes/decodes a single JSON frame; defines the frame type constants and `PROTOCOL_VERSION`. |
| `HomeLan\FileStore\RemoteSocket\RelayServer` | The listening side (`filestored`). A Ratchet `MessageComponentInterface`; tracks per-connection auth state and the (protocol, port) → connection registration table. `relayInbound()` is called by whichever component owns a network interface, to forward a packet out to a registered client; a `data` frame coming back from a client invokes the reply callback given to the constructor (see `Services\Provider\IPv4::injectRelayReply()` below). |
| `HomeLan\FileStore\RemoteSocket\Client` | The connecting side (`sharefsd`, `dnsd`, `ntpd`). Owns the `Ratchet\Client\Connector` connection, the handshake/registration state machine, and reconnection. Throws `Exceptions\AuthenticationFailedException` on `auth_fail` — see [Authentication Failure](#authentication-failure) above. |
| `HomeLan\FileStore\RemoteSocket\RelayedUdpTransport` | A `React\Datagram\SocketInterface` stand-in returned by `Client::getTransport()`, one per local port. Lets a ShareFS handler call `setSocket()`/`send()`/listen for `'message'` exactly as it would on a real `React\Datagram\Socket`, without knowing whether it's talking to a real UDP socket or a relay. Caches which local interface a given peer's traffic last arrived on, so a reply can be addressed back through the same interface. |
| `HomeLan\FileStore\RemoteSocket\Exceptions\AuthenticationFailedException` | Thrown by `Client::handleFrame()` on `auth_fail` — see [Authentication Failure](#authentication-failure) above. |

On the `filestored` side, `Services\Provider\IPv4` is the only current caller: `unicastPacketIn()` calls `handleUdpForInterface()` for UDP addressed to an interface IP, which calls `RelayServer::relayInbound()`; `injectRelayReply()` is the callback given to `RelayServer`'s constructor, called when a `data` frame comes back from the client, and builds the reply as a `UdpEconetReply` addressed using the interface the original request arrived on (`Services\Provider\IPv4\Interfaces::getInterfaceFor()`) and the requester's Econet address (`Services\Provider\IPv4\Arpcache::getNetworkAndStation()`). If either lookup fails — an unrecognised local interface, or no arp entry for the remote IP — the reply is dropped silently and logged at debug level, the same tolerance `IPv4` already applies elsewhere for packets it cannot address.

## Security Notes

- The shared secret is mandatory — there is no way to run the relay server without one. A missing secret on either side leaves the feature unusable rather than silently unauthenticated.
- The secret is stored in plain text in configuration. Use filesystem permissions to restrict access, the same as any other credential in this project's config.
- Comparison uses `hash_equals()` to avoid a timing side-channel.
- TLS is not implemented; deploy behind a VPN, SSH tunnel, or on a trusted loopback/local network if the path between the two processes is not otherwise trusted. The default listen address (`0.0.0.0`) and default client target (`127.0.0.1:8091`) assume same-host or trusted-LAN deployment; restrict `remote_socket_relay_listen_address` if that isn't the case.
