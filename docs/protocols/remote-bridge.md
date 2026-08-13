# Remote Bridge Protocol

The remote bridge system allows two instances of the filestore application (or compatible implementations) to interconnect over a TCP connection so that Econet clients on one server's network can reach services on the other server's network, and vice versa.

## Overview

Two servers each run their own file, print, and bridge services. By configuring a remote bridge, each server learns which Econet network numbers are reachable via the other, and transparently forwards packets across the TCP connection.

```
BBC Micro (net 2, stn 5) ─── [Server B] ═══ TCP ═══ [Server A] ─── File Server (net 1, stn 254)
```

One end acts as **server** (listens for incoming TCP connections); the other acts as **client** (initiates the TCP connection). Both roles are configurable in the map file. A server instance can hold multiple incoming connections; a client instance can connect to multiple servers.

Authentication uses a shared secret (HMAC-SHA256 with a server-generated nonce). The server validates the client's credentials and rejects connections with incorrect secrets or stale timestamps. Authentication is mandatory — no traffic flows before auth completes.

## Feature Gate

The remote bridge feature is disabled by default. To enable it, set `remote_bridge_enabled = true` in the configuration. When enabled, the piconet interface switches from **LISTEN** mode to **MONITOR** mode so it can see all Econet traffic (including inter-network packets whose destination is not the local station), enabling the server to forward them to the correct remote bridge.

## Map File Format

The map file is a plain-text file, one directive per line. Blank lines and lines starting with `#` are ignored.

### SERVER entry

```
SERVER <port> <secret> <my_networks>
```

Causes the server to listen on `<port>` for incoming remote bridge TCP connections. `<secret>` is the shared HMAC secret. `<my_networks>` is a comma-separated list of Econet network numbers that **this server** serves locally — it is announced to connecting clients after authentication.

Example:
```
# Accept bridge connections on port 8765; this server serves Econet network 1
SERVER 8765 my-shared-secret 1
```

### CLIENT entry

```
CLIENT <host:port> <secret> <my_networks>
```

Causes the server to connect to a remote bridge server at `<host:port>`. `<secret>` is the shared HMAC secret (must match the server's). `<my_networks>` is a comma-separated list of Econet network numbers that **this** client serves locally — it is announced to the server after authentication so the server can route reply packets back correctly.

Example:
```
# Connect to server at 192.168.1.10:8765; this client serves Econet network 2
CLIENT 192.168.1.10:8765 my-shared-secret 2
```

Multiple SERVER and CLIENT entries may appear in the same map file.

### Complete example

Server A map file (`/etc/econet/remotebridge.txt` on server A):
```
# Server A: serves network 1 via piconet
SERVER 8765 our-bridge-secret 1
```

Server B map file:
```
# Server B: serves network 2 via piconet; connects to server A
CLIENT 192.168.1.100:8765 our-bridge-secret 2
```

Config keys: `remote_bridge_map_file`, `remote_bridge_server_address`

## Protocol Versioning

Both sides advertise the set of protocol versions they support during the handshake. The server selects the highest version present in both lists and includes it in the `CHALLENGE` response. If there is no common version the server sends `VERSION_REJECT` and closes the connection.

The current protocol version is **1.1**.

Version strings follow the `MAJOR.MINOR` convention and are compared semantically (e.g. `2.0 > 1.0 > 1.0-beta`). Future minor versions are backwards-compatible; a major-version bump signals a breaking change.

### Version history

| Version | Adds |
|---------|------|
| 1.0     | `HELLO`/`CHALLENGE`/`AUTH`/`AUTH_OK`/`NETWORKS` handshake; `SEND` packet forwarding. |
| 1.1     | `ACK <net> <stn>` message (see [Packet Protocol](#packet-protocol) and [Conformance Requirements](#conformance-requirements-for-third-party-bridge-clients-protocol-11)); `PING`/`PONG` heartbeat (see [Heartbeat](#heartbeat-protocol-11)). Purely additive — a 1.0 peer is fully interoperable with a 1.1 peer, it just never sends or receives `ACK`, `PING`, or `PONG` lines. |

## Authentication Protocol

The client initiates the handshake immediately on TCP connection. All messages are newline-terminated text lines.

### Successful handshake

```
CLIENT → SERVER:  HELLO <unix_timestamp> <ver1>[,<ver2>...]
SERVER → CLIENT:  CHALLENGE <32-hex-char-nonce> <agreed_version>
CLIENT → SERVER:  AUTH <64-hex-char-hmac>
SERVER → CLIENT:  AUTH_OK
                  NETWORKS <my_net1,my_net2,...>
CLIENT → SERVER:  NETWORKS <my_net1,my_net2,...>
```

### Version mismatch

If the server finds no common version it replies instead with:

```
SERVER → CLIENT:  VERSION_REJECT <server_ver1>[,<server_ver2>...]
```

and then closes the connection. The client closes its end on receiving `VERSION_REJECT`.

---

**HELLO** — sends the client's current Unix timestamp (seconds since epoch) and a comma-separated list of supported protocol version strings, highest first. The server rejects timestamps more than 60 seconds in the past or future. A `HELLO` without a version field is treated as advertising version `1.0` only (backwards compatibility).

**CHALLENGE** — 16 random bytes encoded as a 32-character lowercase hex string (generated fresh for each connection), followed by the agreed protocol version string selected by the server.

**VERSION_REJECT** — sent by the server when no common protocol version exists. Contains the server's comma-separated list of supported versions so the operator can diagnose the mismatch. The connection is closed immediately after sending.

**AUTH** — HMAC-SHA256 computed as:
```
hmac_sha256(secret, nonce_hex_string + ":" + timestamp_string)
```
encoded as 64 lowercase hex characters.

**AUTH_OK** — sent immediately by the server on successful authentication. On failure the server sends `AUTH_FAIL <reason>` and closes the connection.

**NETWORKS** — sent by both sides immediately after auth completes. Contains a comma-separated list of Econet network numbers that side can route to. Each side registers the peer's networks in its routing table so subsequent `SEND` messages can be forwarded correctly.

## Packet Protocol

After authentication, both sides may send and receive packet lines at any time:

```
SEND <dst_net> <dst_stn> <src_net> <src_stn> <port> <flags> <base64_data>
```

All numeric fields are unsigned decimal integers. `<base64_data>` is the packet payload encoded with standard base64. An empty payload (zero-length data) is represented by a line with only the 6 numeric fields (no trailing base64 field).

Example:
```
SEND 1 254 2 5 151 0 SGVsbG8gV29ybGQ=
```

The server validates that the destination network in each received `SEND` matches one of its configured local networks. Packets for other networks are silently dropped as a security measure.

### ACK message (protocol 1.1+)

Real Econet traffic is itself acknowledged at the link layer: when a station receives a unicast frame it sends back a hardware-level Ack, which the receiving instance's local encapsulation (AUN, Piconet, or WebSocket) picks up and delivers to its `ServiceDispatcher`. Services such as the file server use that Ack to drive block-by-block transfers — send one block, wait for the Ack, send the next.

Before protocol 1.1, that Ack was purely local: if the block had been forwarded to a client on the *other* side of a bridge, the Ack the remote client generated arrived at whichever instance owned that client's physical network, not at the instance whose service was waiting for it — so the transfer stalled after the first block. `ACK <net> <stn>` closes that gap by relaying the Ack back across the bridge connection that originally carried the request:

```
ACK <net> <stn>
```

Both fields are unsigned decimal integers — the network and station of the real Econet acknowledgement, using the same numbering as `SEND`'s `<src_net> <src_stn>` fields. There is no payload and no correlation to a specific prior `SEND` line; `<net> <stn>` alone is enough for the receiver to match it against its own pending transfer state.

Example:
```
ACK 5 254
```

`ACK` is sent only over connections that negotiated protocol 1.1 or higher — see [Conformance Requirements](#conformance-requirements-for-third-party-bridge-clients-protocol-11) below for exactly when a 1.1 implementation must send and how it must handle receiving one. It carries no destination-network validation of its own (unlike `SEND`): it always means "dispatch this to my own pending transfer state," never "forward this on."

## Heartbeat (protocol 1.1+)

The base protocol has no way to detect a silently-dead TCP connection except reactively, via a failed read or write syscall — a peer process that is killed without a clean FIN, a pulled cable, or a firewall that quietly drops connection state can all leave a connection looking alive indefinitely with no traffic to provoke an error. `PING`/`PONG` closes that gap proactively, once both sides have negotiated protocol 1.1 or higher.

```
PING
PONG
```

Both are no-payload, newline-terminated lines — the command word alone, like `AUTH_OK`.

- After authentication, each side independently starts sending `PING` on a periodic timer (this implementation's default: every 3 seconds).
- Receiving `PING` triggers an immediate `PONG` reply.
- Receiving `PONG` requires no reply — it is simply evidence the peer is alive.
- Any line at all — `SEND`, `ACK`, `PING`, or `PONG` — resets that connection's idle watermark. If no line has been received for a threshold (this implementation's default: 10 seconds, roughly three missed pings), the connection is considered dead and closed. Reconnection then proceeds as described under [Reconnection](#reconnection).
- `PING`/`PONG` are sent and expected only on connections that negotiated protocol 1.1 or higher — the same gating as `ACK`. A connection that negotiated 1.0 never sends or expects them, and is not subject to the idle-timeout close, since a 1.0 peer has no obligation to generate any traffic during quiet periods.
- Malformed or unexpected `PING`/`PONG` lines are tolerated — logged and discarded, never a reason to close the connection — matching the existing tolerance for malformed `ACK`/`SEND` lines.

The exact cadence and timeout are implementation details, not wire-format requirements — a conformant peer only needs to send `PING` often enough that the other side's idle timeout doesn't fire during normal operation, and reply to `PING` with `PONG` promptly.

## Conformance Requirements for Third-Party Bridge Clients (Protocol 1.1)

This section is normative for any implementation other than this project's own that wants to interoperate at protocol 1.1. A 1.1 implementation **must**:

1. **Opt in during the handshake.** Advertise `1.1` in the `HELLO` (or `CHALLENGE`) version list. If `1.1` is not advertised, the connection negotiates down to `1.0` and none of the requirements below apply — `ACK` is simply never sent or expected on that connection.

2. **Send `ACK <net> <stn>` back across the connection a `SEND` for that station arrived on, once its delivery is genuinely acked.** Track, per (network, station), which connection most recently sent this instance a `SEND` destined for a station on one of its *own* locally-served networks. When this instance's own local Econet handling (its own AUN/Piconet/WebSocket layer — not a message received over any bridge connection) subsequently observes a genuine hardware-level acknowledgement from that same station, send `ACK <net> <stn>` back across whichever connection asked for that delivery. This is the reverse of what it may look like at first: the network in question is one *this* instance serves, not one the peer announced — a bridged `SEND` only ever targets a network the receiving side itself serves (see the destination-network check on `SEND` above), so by the time a real ack for it is possible, the peer's `NETWORKS` announcement is irrelevant to this decision. An ack for a station this instance never received a relayed `SEND` for (the overwhelmingly common case — most acks are for purely local traffic no bridge is involved in) is handled entirely locally and never triggers an `ACK` line.

3. **Accept unsolicited `ACK` lines at any time after authentication** and dispatch them directly to this instance's own local pending-transfer state (i.e. treat `<net> <stn>` exactly as if this instance's own local encapsulation had just observed that Ack itself). An `ACK` is never gated by local-network validation the way `SEND` is — it is always meant for this instance's own state, precisely because that state can only exist here.

4. **Never forward a received `ACK` onward to a third connection.** Relaying is single-hop only. If this instance is itself bridging to a further peer, an `ACK` received from one bridge connection must not be re-sent down another — an implementation that chains multiple bridge hops must derive its own multi-hop Ack relay outside of what this protocol version defines, or accept that multi-hop transfers stall after the first block, same as pre-1.1 behaviour.

5. **Drop malformed `ACK` lines without closing the connection.** A line with the wrong command word, the wrong number of fields, or non-numeric fields must be logged and discarded, exactly like a malformed `SEND` — it is not a protocol violation that warrants tearing down the connection.

6. **Never send `ACK` to a peer that negotiated only `1.0`.** This is automatically true if version negotiation is implemented correctly (§1), but implementations must not, for example, cache a client's advertised-but-unnegotiated capabilities and send `ACK` based on that instead of the actually agreed `<agreed_version>` from `CHALLENGE`.

7. **Send periodic `PING` once authenticated, if the connection negotiated 1.1+.** See [Heartbeat](#heartbeat-protocol-11) for the wire format and this implementation's default cadence — the exact interval is not normative, only that it be frequent enough to keep the peer's idle timeout from firing during normal operation.

8. **Reply `PONG` immediately on receiving `PING`, and treat receipt of either `PING` or `PONG` as proof of liveness** (i.e. reset any local idle-timeout watermark on receiving *any* line, not just `PING`/`PONG`).

9. **Drop malformed or unexpected `PING`/`PONG` lines without closing the connection**, the same tolerance as required for malformed `ACK`/`SEND` lines (§5).

A `1.0`-only peer is fully exempt from all of the above: it is not required to implement `ACK` or `PING`/`PONG` at all, will never receive an `ACK`, `PING`, or `PONG`, is never subject to an idle-timeout close, and its bridged multi-block transfers behave exactly as they did before protocol 1.1 existed (the first block may be the only one delivered if the destination is reached via a bridge). Upgrading only one side of a connection is safe and yields no protocol errors — it simply means `ACK` relay and the heartbeat only ever activate once *both* sides advertise `1.1`.

## Reconnection

The client side implements exponential-backoff reconnection: 5 s, 10 s, 20 s, … capped at 300 s (5 minutes). On reconnect the full authentication handshake repeats.

## Outbound Packet Buffering

When a connection drops, the networks it served stay "known" for a short grace period (5 seconds) rather than becoming unreachable immediately. Any outbound packet destined for one of those networks during that window is buffered instead of dropped, FIFO, capped at 32 packets per network. The buffer is flushed — oldest packet first — the moment that network is announced again via a fresh `NETWORKS` message on a newly authenticated connection.

Any buffered packet still waiting once the 5-second grace period elapses is discarded rather than delivered, since a delayed Econet reply is of no use once the requesting station has likely already timed out — replaying it is more likely to confuse a client than help it. If the connection hasn't come back at all once the grace period expires, the network reverts to fully unknown and reconnection continues in the background per the backoff schedule above; further outbound packets are dropped until it reappears.

This buffering is symmetric: it applies equally to the client side reconnecting to a server and to a server-side connection that a remote client re-establishes, since both are tracked identically once authenticated.

## Monitor Mode

When `remote_bridge_enabled = true`, the piconet interface is opened in `SET_MODE MONITOR` (instead of `SET_MODE LISTEN`). In monitor mode the piconet device forwards all Econet frames to the server, regardless of destination station. The server applies the following filter to received `RX_TRANSMIT` frames:

| Destination                                         | Action                                        |
|-----------------------------------------------------|-----------------------------------------------|
| Local network (net 0), addressed to our station     | Dispatch to services normally                 |
| Local network (net 0), broadcast (station 255)      | Dispatch to services normally                 |
| Local network (net 0), other station                | Ignore (not our packet, not a broadcast)      |
| Non-local network, remote bridge connection exists  | Forward via remote bridge `SEND`              |
| Non-local network, no remote bridge connection      | Drop (log at debug level)                     |

`RX_BROADCAST` and `RX_IMMEDIATE` frames are always dispatched to services and are not subject to monitor-mode filtering.

## Security Notes

- The shared secret is stored in plain text in the map file. Use filesystem permissions to restrict access.
- HMAC-SHA256 provides mutual knowledge of the secret without transmitting it.
- Timestamp checking (±60 s) prevents replay attacks.
- TLS is not implemented in the first release; deploy behind a VPN or SSH tunnel if the TCP path is untrusted.
- Per-connection network validation (server only routes packets for its configured networks) limits the blast radius of a misconfigured or misbehaving peer.
