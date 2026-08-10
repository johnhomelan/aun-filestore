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

The current protocol version is **1.0**.

Version strings follow the `MAJOR.MINOR` convention and are compared semantically (e.g. `2.0 > 1.0 > 1.0-beta`). Future minor versions are backwards-compatible; a major-version bump signals a breaking change.

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

## Reconnection

The client side implements exponential-backoff reconnection: 5 s, 10 s, 20 s, … capped at 300 s (5 minutes). On reconnect the full authentication handshake repeats.

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
