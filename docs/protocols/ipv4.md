# EconetA IPv4 Protocol

The IPv4 service implements EconetA — the Acorn DCI (Driver Control Interface) standard for carrying IP packets over an Econet network. This allows BBC Micro and Archimedes computers equipped with an EconetA module to participate in a TCP/IP network using Econet as the link layer.

## Port

All IPv4 and ARP traffic uses a single port: `0xD2` (TCPIPProtocolSuite).

The Econet flags/control byte distinguishes the frame type.

## Frame Types

| Flags | Name              | Description                                      |
|-------|-------------------|--------------------------------------------------|
| 0x01  | IPv4 data (DCI-2) | Standard IP packet                               |
| 0x81  | IPv4 data (DCI-4) | IP packet under DCI-4 (Acorn native Econet)      |
| 0x21  | ARP who-has (DCI-2)| ARP request — who has this IP?                 |
| 0x22  | ARP is-at (DCI-2) | ARP reply — that IP is at this station          |
| 0xA1  | ARP who-has (DCI-4)| ARP request under DCI-4                        |
| 0xA2  | ARP is-at (DCI-4) | ARP reply under DCI-4                           |

DCI-2 is the older standard used by RISC OS network software. DCI-4 is the updated standard used by later Acorn network drivers. Both are handled identically; replies are sent in the same DCI variant as the request.

## ARP (Address Resolution Protocol)

EconetA uses a simplified 8-byte ARP payload rather than the standard Ethernet ARP format.

### ARP Payload Layout

```
Offset  Size  Field
------  ----  -----
0       4     Sender IP address (binary, network byte order)
4       4     Target IP address (binary, network byte order)
```

### ARP Who-Has (Request)

Sent as a broadcast to station 255. The server sends this when it needs to forward a packet to an IP address for which it has no Econet station mapping.

### ARP Is-At (Reply)

Sent unicast to the requesting station. The server replies with its own IP address when it receives a who-has for an IP it owns.

When the server receives an is-at reply for an IP it was waiting to resolve, it delivers any queued packets to the now-known Econet station.

### ARP Cache

The server maintains an ARP cache mapping IP addresses to Econet (network, station) pairs. If an ARP entry is missing when a packet needs forwarding, the packet is queued and an ARP who-has broadcast is sent. If the ARP reply does not arrive within 30 seconds, the queued packet is dropped.

## IPv4 Packet Forwarding

When the server receives an IPv4 frame from an Econet station it acts as a router:

1. The TTL field in the IPv4 header is decremented by 1.
2. The IP header checksum is recomputed (ones-complement over the 20-byte IP header).
3. The packet is forwarded to the destination IP address via the appropriate interface.

If the destination IP is on an Econet segment (known via the AUN map or piconet map), the packet is forwarded to that station at port 0xD2 with flags 0x01.

If no route is available, an ICMP Destination Unreachable is returned to the sender.

## ICMP Support

### Echo (Ping)

ICMP Echo Requests (type 8) addressed to the server itself are answered with an ICMP Echo Reply (type 0). The reply carries the same identifier, sequence number, and payload as the request.

### Destination Unreachable

ICMP Destination Unreachable (type 3) packets are generated when:

- No route exists to the destination network (code 0 — Net Unreachable)
- The destination host is not reachable on a known network (code 1 — Host Unreachable)
- The destination port is not handled (code 3 — Port Unreachable)

### ICMP Packet Layout (within IPv4 payload)

```
Offset  Size  Field
------  ----  -----
0       1     Type
1       1     Code
2       2     Checksum (uint16 BE, ones-complement)
4       2     Identifier (uint16 BE) — echo only
6       2     Sequence number (uint16 BE) — echo only
8+      n     Data
```

For Destination Unreachable, bytes 4–7 are reserved (zero) and bytes 8–27 contain the original IP header, followed by the first 8 bytes of the original datagram.

## IPv4 Header Format (reference)

```
Offset  Size  Field
------  ----  -----
0       1     Version (4) + IHL in 32-bit words (normally 5)
1       1     Type of Service
2       2     Total length (uint16 BE)
4       2     Identification (uint16 BE)
6       2     Flags + Fragment offset (uint16 BE)
8       1     TTL
9       1     Protocol (0x01=ICMP, 0x06=TCP, 0x11=UDP)
10      2     Header checksum (uint16 BE)
12      4     Source IP address
16      4     Destination IP address
20+     n     Payload
```

Outgoing IPv4 packets use: TOS=0, TTL=64, DF flag set (flags/offset = 0x4000).

## NAT / TCP Handling

The server can construct full IPv4+TCP packets for NAT operations. TCP headers use the standard 20-byte format with a pseudo-header checksum per RFC 793 (source IP + destination IP + 0x0006 + TCP segment length). Window size is advertised as 65535.
