# Remote Provider Protocol

The Remote Provider Protocol relays whole Econet packets addressed to ports reserved on
`filestored`'s `ServiceDispatcher` to a `HomeLan\FileStore\Services\ProviderInterface`
implementation running in a separate process, and its replies (or unsolicited/async output) back
again. It sits one layer up the stack from the [Remote Socket Protocol](remote-socket.md): that
one relays raw UDP traffic arriving at an interface owned by `IPv4`; this one relays
`EconetPacket`s arriving at a port owned (on `filestored`'s behalf) by
`Services\Provider\ProxyProvider`. Its first and, so far, only use is `ecosyslogd` — see
[EcoSyslog](ecosyslog.md) — but nothing in the protocol or in `ProxyProvider`/`RelayServer`/
`Client`/`Host` is specific to it. `EcoSyslog` itself is a pure request/no-reply provider and so
doesn't exercise the Stream Claims or Ack Relay machinery below at all — that exists for the
benefit of a future `FileServer`-style provider needing GETBYTES/PUTBYTES-shaped flow control.

## Overview

```
BBC Micro ── Econet ── [filestored: ServiceDispatcher + ProxyProvider] ══ WebSocket ══ [ecosyslogd: ServiceDispatcher + EcoSyslog]
```

`ServiceDispatcher::addService()` requires a provider's ports to be fixed at startup.
`Services\Provider\ProxyProvider` exists to satisfy that: it statically reserves a superset of
Econet port numbers (configured directly in `src/filestored`, one entry per remotely-hosted
provider you intend to run), and stands in for whichever provider process registers for each of
those ports over this protocol's relay. A reserved port with nothing currently connected and
registered for it behaves exactly like a port nothing has claimed at all — packets addressed to
it are dropped silently.

On the far side, a host process (e.g. `ecosyslogd`) runs a **second, independent
`ServiceDispatcher` instance**, constructed exactly as `filestored`'s is — with the real
`ProviderInterface` implementation(s) it wants to run. This is the key design choice that makes
hosting a provider elsewhere possible with no changes to the provider's own code: housekeeping
tasks (`addHousingKeepingTask`), admin enable/disable
(`ServiceDispatcher::create()->disableService()`/`enableService()`), and `getServiceByPort()` all
keep working unmodified — only the transport underneath is swapped, from
`PacketDispatcher`/AUN/WebSocket to `RemoteProvider\Client`/`Host`.

`filestored` (`HomeLan\FileStore\RemoteProvider\RelayServer`) is always the listening side;
the provider host process (`HomeLan\FileStore\RemoteProvider\Client`) is always the connecting
side — the same fixed direction as the Remote Socket Protocol, and for the same reason: the
process that owns the Econet transport is the one with something worth connecting to.

## Feature Gate

Disabled by default on both sides.

On `filestored`:

| Config key | Meaning |
|---|---|
| `remote_provider_relay_enabled` | Must be `true` to start the relay server at all. |
| `remote_provider_relay_listen_address` | Address the relay WebSocket server binds to. Default `0.0.0.0`. |
| `remote_provider_relay_listen_port` | Port the relay WebSocket server binds to. Default `8092` — separate from both `websocket_listen_port` and `remote_socket_relay_listen_port` (`8091`). |
| `remote_provider_relay_secret` | Shared secret. No default; must be set for the feature to work. |

A provider host process (e.g. `ecosyslogd`) has its own `<prefix>_remote_provider_relay_address`/
`<prefix>_remote_provider_relay_secret` keys — see [EcoSyslog](ecosyslog.md) for the concrete
example.

## Framing

Every frame is a single JSON object, sent as one WebSocket text message, with a `type` field
naming the frame and additional type-specific fields alongside it — the same shape as the Remote
Socket Protocol:

```json
{"type": "hello", "versions": ["1.0"], "secret": "..."}
```

## Versioning

Identical scheme to the Remote Socket Protocol: `hello` carries the connecting side's supported
versions, highest first; the listening side picks the highest one it also supports and confirms
it in `hello_ok`, or sends `version_reject` and closes if there's no overlap. This protocol is
unpublished: version 1.0 is edited in place while under development — see
`Frame::PROTOCOL_VERSION` in `src/include/classes/RemoteProvider/Messages/Frame.php`.

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

`secret` is compared with `hash_equals()`; no frame other than `hello` is processed before
authentication succeeds — exactly as in the Remote Socket Protocol.

## Registration

Once authenticated, the client declares which Econet port numbers it wants to provide service
for, out of the superset `ProxyProvider` has reserved:

```
CLIENT → SERVER:  register       {ports: [182, ...]}
SERVER → CLIENT:  register_ok    {port}                (one per entry, on success)
SERVER → CLIENT:  register_fail  {port, reason}         (one per entry, on failure)
```

Only one connection may be registered for a given port at a time — a second `register` for a
port already claimed by another connection gets `register_fail`, matching
`ServiceDispatcher::addService()`'s own "port already in use" rule. Registering for a port
`ProxyProvider` hasn't reserved also gets `register_fail`. Registrations are released when the
connection that made them closes.

`Host` sends one `register` frame covering every port its hosted providers' own
`getServicePorts()` declare, immediately on `hello_ok` (and again on every reconnect).

## Packet Frames

Once registered, Econet traffic for that port flows as `packet` frames in either direction:

```json
{
  "type": "packet",
  "kind": "unicast",
  "srcNet": 0,
  "srcStn": 42,
  "dstNet": 0,
  "dstStn": 254,
  "port": 182,
  "flags": 128,
  "payload": "aGVsbG8gd29ybGQ="
}
```

`kind` is `"unicast"` or `"broadcast"`. It only drives routing on the filestored→remote-provider
leg — which of `unicastPacketIn()`/`broadcastPacketIn()` `Host` calls on delivery; on the reply
leg it is carried for symmetry but not otherwise interpreted, since AUN/broadcast framing is
already implied by a destination station of `255` (see `EconetPacket::_getAunRaw()`).
`srcNet`/`srcStn`/`dstNet`/`dstStn`/`port`/`flags` map directly onto `EconetPacket`'s own
getters/setters. `payload` is the packet's data, base64-encoded.

Unlike the Remote Socket Protocol's `data` frame, there is no separate "local vs remote address"
distinction to preserve — an Econet packet already carries its own full addressing in both
directions, so the same four address fields describe a request and its reply without needing to
be echoed or looked up.

There is no acknowledgement of a `packet` frame at the protocol level; delivery relies on the
underlying WebSocket/TCP connection, the same as the Remote Socket Protocol.

## Stream Claims

`ServiceDispatcher::addService()` fixes a provider's ports at startup — fine for a provider with a
small, known set of ports, but GETBYTES/PUTBYTES-style flow control (`FileServer`) needs an
ephemeral port chosen *at request time* from a shared pool (`ServiceDispatcher::claimStreamPort()`,
ports 20–39). There's no way to reserve one of those in advance the way `ProxyProvider`'s regular
ports are reserved. Instead, a connection asks the listening side to claim one on the real
`ServiceDispatcher` at runtime:

```
CLIENT → SERVER:  claim_stream        {requestId, timeout}
SERVER → CLIENT:  stream_claimed      {requestId, port}
SERVER → CLIENT:  stream_claim_failed {requestId, reason}
```

This is the protocol's only request/response exchange — every other frame is fire-and-forget.
`requestId` (an opaque string) correlates the reply, since more than one claim can be in flight on
a connection at once; `timeout` is the lease `ServiceDispatcher::claimStreamPort()` should give the
port on the `filestored` side.

On success, the claimed port is registered to the requesting connection exactly as an explicit
`register` would have — there is no separate follow-up round trip. The connecting side, on
receiving `stream_claimed`, binds the *same* port number on its own local `ServiceDispatcher`
(`ServiceDispatcher::bindStreamPort()`) — a small addition alongside `claimStreamPort()`, since the
two processes' independent port counters have no reason to agree on the same number otherwise.
From then on, ordinary `packet` frames for that port flow exactly like any other registered port.

`RelayServer::releaseStreamPort()` drops a claimed port's registration once
`Services\Provider\ProxyProvider` notices, via a housekeeping task, that `ServiceDispatcher`'s own
expiry has freed it on the `filestored` side — otherwise a later claim reusing the same port
number could route to the stale connection that held it before. See `ProxyProvider::
sweepExpiredStreamPorts()`'s own docblock for the one-housekeeping-cycle lag this involves (harmless
— it only widens an already-timing-bounded window).

`Client::claimStreamPort()` returns a `React\Promise\PromiseInterface<int>`, rejecting if the
server has none free, the connection drops before a reply arrives, or nothing comes back within
the given timeout. `Host::claimStreamPort(ProviderInterface, timeout)` wraps this and performs the
local `bindStreamPort()` call, so a hosted provider only ever needs to call `Host::claimStreamPort()`
in place of the `ServiceDispatcher::claimStreamPort()` call it would make if it were running
locally.

## Ack Relay

An ACK-type Econet packet is routed by `ServiceDispatcher::inboundPacket()` purely by
`(network, station[, sequence])`, via `ackEvents()` — never by port, so it never reaches
`ProxyProvider`/`unicastPacketIn()` at all. It also always arrives physically at `filestored`,
never at a remote provider host, since that's the process actually connected to the Econet
transport. Something has to bridge the two so a hosted provider's completely ordinary
`ServiceDispatcher::addAckEvent()` callback — registered on its *own*, separate `ServiceDispatcher`
instance — ever gets a chance to fire.

This reuses the same shape of mechanism `RemoteBridge\Map::rememberAckRelay()`/`relayAckIfKnown()`
already provides for bridge peers (see [remote-bridge.md](remote-bridge.md)) — a second, parallel
instance of it, `RemoteProvider\AckRelayMap`, called from the very same
`ServiceDispatcher::ackEvents()` alongside the existing `RemoteBridgeMap` call:

1. Whenever `RelayServer` relays a `packet` frame *from* a connection out as a real reply, it
   remembers, via `AckRelayMap::rememberAckRelay()`, that connection against the reply's
   destination (network, station) and sequence — skipped for a broadcast reply (destination
   station `255`), since nothing acks a broadcast.
2. When a real ack for that (network, station) later arrives at `filestored`,
   `ServiceDispatcher::ackEvents()` calls `AckRelayMap::relayAckIfKnown()`, which sends:
   ```
   SERVER → CLIENT:  ack   {net, stn, seq}
   ```
   to the remembered connection. `seq` is `null` when the encapsulation that observed the real ack
   had no sequence concept of its own (raw hardware Econet) — see
   `EncapsulationInterface::getSequence()`.
3. `Client` emits an `'ack'` event on receipt; `Host` subscribes and calls
   `ServiceDispatcher::fireAckEvent(network, station, seq)` — a small new primitive, independent of
   `ackEvents()`'s own body, that does the same lookup-and-fire `ackEvents()` does, but from bare
   values instead of a real `EncapsulationInterface`. It invokes the registered callback with a
   synthetic `RemoteProvider\Messages\RelayedAck` standing in for one — every current callback (see
   `FileServer`'s GETBYTES/PUTBYTES streaming) ignores its `EncapsulationInterface` argument
   entirely, so this is safe, but a callback should not rely on decoding real data from it.

Net effect: a remotely-hosted `FileServer`-style provider calls
`$this->oServiceDispatcher->claimStreamPort(...)` and `addAckEvent(...)` exactly as it would inside
`filestored` — those calls hit its own local `ServiceDispatcher` instance, completely unmodified.
Only `Host` (via `claimStreamPort()`/the `'ack'` subscription) knows anything crossed a process
boundary.

## Heartbeat

```
ping
pong
```

Identical to the Remote Socket Protocol: either side may send `ping`; a `pong` reply is sent
immediately. Purely optional.

## Reconnection

If the connection to the relay server drops, `Client` reconnects automatically after a fixed
delay (5 seconds) and repeats the full handshake — `hello`, then `register` for every port `Host`
was constructed with. Traffic is simply unavailable on that port for the duration of the outage;
there is no buffering — `ProxyProvider::relay()` (via `RelayServer::relayInbound()`) drops the
packet exactly as if nothing were registered for that port at all.

## Authentication Failure

Handled exactly as in the Remote Socket Protocol: a rejected *secret* makes `Client::handleFrame()`
throw `RemoteProvider\Exceptions\AuthenticationFailedException` instead of reconnecting, since a
wrong secret won't fix itself on retry. `ecosyslogd` wraps its event loop's `run()` call in a
catch for that exception, logs it, and exits.

## Class Reference

| Class | Role |
|---|---|
| `HomeLan\FileStore\RemoteProvider\Messages\Frame` | Encodes/decodes a single JSON frame; defines the frame type/kind constants and `PROTOCOL_VERSION`. |
| `HomeLan\FileStore\RemoteProvider\RelayServer` | The listening side (`filestored`). A Ratchet `MessageComponentInterface`; tracks per-connection auth state and the port → connection registration table. `relayInbound()` is called by `Services\Provider\ProxyProvider` to forward a packet out to a registered connection; a `packet` frame coming back from a connection invokes the reply callback given to the constructor (`ProxyProvider::injectReply()`) and remembers an ack relay for it (`AckRelayMap::rememberAckRelay()`). `claim_stream` frames are handled via the `$fClaimStreamPort` callback given to the constructor (`ProxyProvider::claimStreamPort()`); `releaseStreamPort()` drops a stale claim's registration. |
| `HomeLan\FileStore\RemoteProvider\Client` | The connecting side (`ecosyslogd`, and any future provider host). An `Evenement\EventEmitter`, emitting `'packet'` (`string $sKind, EconetPacket $oPacket`) for each `packet` frame received and `'ack'` (`int $iNetwork, int $iStation, ?int $iSeq`) for each `ack` frame. Owns the `Ratchet\Client\Connector` connection, the handshake/registration state machine, and reconnection. `claimStreamPort()` is the protocol's only request/response call, tracked via a requestId → `React\Promise\Deferred` table. Throws `Exceptions\AuthenticationFailedException` on `auth_fail`. |
| `HomeLan\FileStore\RemoteProvider\Host` | Not present in the Remote Socket Protocol — wraps a `Client` and a local `ServiceDispatcher` instance. On a `'packet'` event, looks up the matching provider by port (`ServiceDispatcher::getServiceByPort()`), calls `unicastPacketIn()`/`broadcastPacketIn()`, and forwards whatever it queued via `getReplies()` back as `packet` frames. `flush()` does the same for every hosted provider unconditionally — a periodic-timer counterpart for output not tied to handling an inbound packet (an async broadcast, say), mirroring `Command\React`'s own 1-second `getReplies()` drain. Subscribes to `'ack'` to call `ServiceDispatcher::fireAckEvent()`; `claimStreamPort()` wraps `Client::claimStreamPort()` with the matching local `ServiceDispatcher::bindStreamPort()` call. |
| `HomeLan\FileStore\RemoteProvider\AckRelayMap` | Mirrors `RemoteBridge\Map`'s `rememberAckRelay()`/`relayAckIfKnown()` pair (see § Ack Relay above) — a static registry `ServiceDispatcher::ackEvents()` calls unconditionally, without needing to know anything about remote provider hosts specifically. |
| `HomeLan\FileStore\RemoteProvider\Messages\RelayedAck` | A synthetic `EncapsulationInterface`, standing in for a real Ack packet that never reached the remote process, passed to an `addAckEvent()` callback by `ServiceDispatcher::fireAckEvent()`. |
| `HomeLan\FileStore\RemoteProvider\Exceptions\AuthenticationFailedException` | Thrown by `Client::handleFrame()` on `auth_fail`. |
| `HomeLan\FileStore\Services\Provider\ProxyProvider` | The `filestored`-side `ProviderInterface` implementation. Statically reserves a configured list of ports; `unicastPacketIn()`/`broadcastPacketIn()` relay to whichever connection is registered for the packet's port (or drop it, logged at debug level, if none is); `getReplies()` drains a buffer filled by `injectReply()`, the callback given to `RelayServer`'s constructor. `claimStreamPort()` claims a port on the real `ServiceDispatcher` on a connection's behalf; `sweepExpiredStreamPorts()`, run as a housekeeping task, releases `RelayServer`'s registration once that claim expires. |

## Security Notes

Identical posture to the Remote Socket Protocol (see
[remote-socket.md § Security Notes](remote-socket.md#security-notes)): the shared secret is
mandatory and compared with `hash_equals()`; it is stored in plain text in configuration, so rely
on filesystem permissions; TLS is not implemented, so deploy behind a VPN, SSH tunnel, or a
trusted loopback/local network.

## Scope and Limitations

This is deliberately a smaller slice of `ProviderInterface`'s full contract than running inside
`filestored` directly gets you:

- **No admin-entity relay.** A remotely-hosted provider's `AdminInterface` (if it has one) is
  served from *its own* process's admin port, the same pattern `sharefsd` already uses
  (`ShareFs\Admin\Kernel`) — nothing about its admin data is surfaced through `filestored`'s admin
  UI. `ProxyProvider`'s own (minimal) admin page only shows which reserved ports currently have a
  live remote connection.
- **Single active connection per port**, exactly like the Remote Socket Protocol — no fan-out or
  failover between multiple hosts registering for the same port. The same applies to stream
  claims and ack relay: at most one remotely-hosted transfer per station is assumed, which matches
  how a real station only ever has one open GETBYTES/PUTBYTES transfer at a time.
- **No cross-host stream/ack correlation.** A stream port claim and the ack relay for traffic on
  it are both scoped to whichever single connection made the claim / sent the reply — there is no
  mechanism for a second remote host to observe or take over another's in-flight stream.
