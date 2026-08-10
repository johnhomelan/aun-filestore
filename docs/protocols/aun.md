# AUN — Acorn Universal Networking Protocol

AUN encapsulates Econet packets inside UDP datagrams, allowing Econet services to run over a standard IP network. Each UDP payload begins with an 8-byte header followed by the Econet data payload.

## UDP Transport

All AUN traffic is sent and received on a single UDP socket. The default port is controlled by the `aun_default_port` configuration key. Individual hosts may be mapped to a different port in the AUN map file.

## Packet Header

```
Offset  Size  Field
------  ----  -----
0       1     Packet type (uint8)
1       1     Destination Econet port (uint8)
2       1     Control byte / flags (uint8)
3       1     Retransmission padding (uint8, normally 0)
4       4     Sequence number (uint32, little-endian)
8+      n     Data payload (variable)
```

### Packet Types

| Value | Name           | Description                                    |
|-------|----------------|------------------------------------------------|
| 1     | Broadcast      | Sent to all stations                           |
| 2     | Unicast        | Addressed to a single station                  |
| 3     | Ack            | Acknowledgement of a received unicast          |
| 4     | Reject         | Packet rejected                                |
| 5     | Immediate      | Immediate operation request                    |
| 6     | ImmediateReply | Reply to an immediate operation                |

## Sequence Numbers

The sequence number is a 32-bit unsigned integer stored little-endian. It is incremented by 4 for each outbound packet from a given source. The receiver echoes the sender's sequence number back in Ack and ImmediateReply packets.

The server tracks the last inbound sequence number seen per source address and discards any duplicate.

## Acknowledgement

For every received Unicast packet the sender expects an Ack in reply. The Ack header is:

```
Type = 3 (Ack)
Port = 0
Control = 0
Padding = 0
Sequence = echo of original sequence number
Data = empty
```

## Immediate Operations (Machine Peek)

Immediate packets (type 5) carry a control byte that selects the operation. The server responds with an ImmediateReply (type 6) with the same sequence number.

| Control byte | Operation      | Reply data bytes (4 bytes)                          |
|-------------|---------------|-----------------------------------------------------|
| 0x00        | Machine type  | `[0x40, 0x00, 0x00, 0x00]` — FS01 FileStore type   |
| 0x01        | OS version    | `[major, minor, 0x00, 0x00]`                        |
| 0x08        | Echo          | `[0x40, 0x66, version_minor, version_major]`        |

## Retransmission

Outbound unicast packets are queued per destination host. After transmitting, the sender waits for an Ack. If no Ack arrives within a timeout period the packet is retransmitted. The backoff delay between attempts is `attempt_number × 400` timer ticks. The default retry count is 3. After all retries are exhausted the packet is dropped.

Only one packet per destination host is in flight at any time; subsequent packets to the same host queue behind the current one.

## Address Mapping

Econet addresses (network, station) are mapped to UDP endpoints by the AUN map, which supports two formats.

### Subnet Mapping

Maps an entire Econet network to a CIDR /24 subnet. The station number becomes the last octet of the IP address.

```
192.168.0.0/24 138
```

This maps Econet network 138 to the subnet 192.168.0.0/24 — station 40 on network 138 resolves to 192.168.0.40.

### Host Mapping

Maps a specific Econet address to an explicit IP and optional port.

```
192.168.0.40 138.40
192.168.0.40:32768 138.40
```

### Auto-net Fallback

If an inbound packet's source IP is not in the map, the server assigns it a dynamic address using the configured auto-net network number with the last octet of the IP as the station number.

## Port Name Reference

The following well-known Econet port numbers are relevant to this server's services.

| Port | Name                       |
|------|----------------------------|
| 0x00 | Immediate operation        |
| 0x90 | File server reply          |
| 0x91 | File server data           |
| 0x99 | File server command        |
| 0x9C | Bridge (bridge-to-bridge)  |
| 0x9D | Bridge (station-to-bridge) |
| 0x9E | Print server enquiry reply |
| 0x9F | Print server enquiry       |
| 0xD0 | Print server reply         |
| 0xD1 | Print server data          |
| 0xD2 | TCP/IP protocol suite      |
