# Econet Bridge Protocol

The bridge service implements the Acorn Econet bridge discovery protocol. Bridges allow stations to reach network numbers they are not directly attached to. The bridge protocol lets stations ask which network they are on and whether a particular remote network is reachable.

## Ports

| Port | Direction      | Purpose                                      |
|------|----------------|----------------------------------------------|
| 0x9C | Inbound        | Bridge-to-bridge queries (peer discovery)    |
| 0x9D | Inbound        | Station-to-bridge queries                    |

Replies are sent to the reply port specified within each request payload.

## Request Packet Layout

All bridge requests share a common 8-byte header:

```
Offset  Size  Field
------  ----  -----
0       1     Function code (uint8)
1       6     Magic string — ASCII "Bridge" (6 bytes)
7       1     Reply port (uint8)
8+      n     Per-function data
```

If the magic string does not match, the packet is silently discarded.

## Function Codes

### 0x80 — EC_BR_QUERY (bridge-to-bridge)

Sent by a peer bridge to announce the networks it knows about and request the same information in return. The payload after the header is a list of network numbers (one byte each).

The server records each network number from the payload as a known peer network. It replies with its own local network number as a single byte.

### 0x81 — EC_BR_QUERY2 (bridge-to-bridge, extended)

Identical semantics to EC_BR_QUERY. Used by some bridge implementations as an updated version of the discovery handshake.

### 0x82 — EC_BR_LOCALNET (station → bridge)

A station asks the bridge which Econet network it is on.

**No additional payload.**

Reply (1 byte per field):

```
Byte 0: The source network number of the requesting station
Byte 1: Bridge firmware version (reported as 128)
```

The station uses this to determine its own network number when it has not been preconfigured.

### 0x83 — EC_BR_NETKNOWN (station → bridge)

A station asks whether a given network number is reachable through this bridge.

**Payload:**

```
Offset  Size  Field
------  ----  -----
0       1     Network number to query (uint8)
```

If the network is known (present in the AUN map or learned from a peer bridge query), the server sends a reply to the requesting station's reply port. The presence of a reply means "yes, reachable". If the network is not known, no reply is sent — absence of a reply means "no".

## Network Discovery

When the server receives a bridge-to-bridge query it learns the networks the peer knows about. This allows the bridge to answer EC_BR_NETKNOWN queries for networks it has not been configured with explicitly, as long as a peer bridge has announced them.
