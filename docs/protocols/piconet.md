# Piconet Interface Protocol

The piconet interface connects the server to a hardware Econet adaptor built around a Raspberry Pi Pico (RP2040). The server communicates with the Pico over a USB CDC serial link using a line-oriented text protocol. The Pico handles all real Econet electrical signalling, collision detection, and low-level acknowledgement, exposing a clean command/event interface to the server.

## Physical Layer

The serial link is configured at 115200 baud, 8 data bits, no parity, 1 stop bit (8N1), raw mode. No CR/LF translation is applied; the kernel line discipline passes bytes through unmodified. The server configures the port directly via `libc` termios calls (using PHP FFI) to avoid depending on `stty`.

## Framing

Each message in both directions is a single line terminated by `\r\r` (two carriage-return bytes). Fields within a line are space-separated. Binary payloads are base64-encoded so they can be embedded in the text protocol without escaping.

## Econet Frame Layout

Scout frames and data frames are encoded as base64 blobs. When decoded, the scout frame has the following layout:

```
Offset  Size  Field
------  ----  -----
0       1     Destination station (uint8)
1       1     Destination network (uint8)
2       1     Source station (uint8)
3       1     Source network (uint8)
4       1     Control byte / flags (uint8)
5       1     Econet port (uint8)
6+      n     Inline data (for broadcast/immediate; see below)
```

For `RX_TRANSMIT` packets the data payload arrives as a separate base64 blob. The first 4 bytes of the decoded data frame are a header appended by the Pico hardware and are discarded; the actual payload starts at byte 5.

Stations on the local Econet always have network number 0 in the scout frame. The server replaces 0 with the value of `piconet_local_network` so that the rest of the stack sees a globally unique address.

## Server → Pico Commands

### STATUS

```
STATUS\r\r
```

Requests the current status of the Pico interface. The Pico replies with a STATUS line.

### SET_STATION

```
SET_STATION <station>\r\r
```

Sets the Econet station number the Pico will claim on the physical network. Sent once during interface bring-up.

### SET_MODE

```
SET_MODE LISTEN\r\r
SET_MODE STOP\r\r
```

`LISTEN` puts the interface into receive mode. `STOP` halts it (sent on connection close).

### TX (Unicast Transmit)

```
TX <dst_station> <dst_network> <flags> <port> <base64_data>\r\r
```

Transmits a unicast Econet packet. `dst_network` is sent as 0 when the destination is on the local network. The Pico performs the two-phase scout/data handshake and replies with a `TX_RESULT` line.

### BCAST (Broadcast)

```
BCAST <base64_data>\r\r
```

Transmits a broadcast packet. No `TX_RESULT` is expected for broadcasts.

## Pico → Server Events

### STATUS

```
STATUS <text>
```

Free-text status information, logged at debug level.

### ERROR

```
ERROR <text>
```

The Pico encountered an error condition. Logged at error level.

### MONITOR

```
MONITOR ...
```

Raw monitor/sniffer output. Silently ignored by the server.

### RX_BROADCAST

```
RX_BROADCAST <base64_scout>
```

An Econet broadcast was received. The scout frame contains all addressing and control information. Data (up to 8 bytes) is taken from bytes 6 onward of the decoded scout.

### RX_IMMEDIATE

```
RX_IMMEDIATE <base64_scout> <base64_data>
```

An Econet immediate operation was received. Data (up to 4 bytes) is taken from bytes 6 onward of the decoded scout.

### RX_TRANSMIT

```
RX_TRANSMIT <base64_scout> <base64_data>
```

A standard unicast Econet packet was received. The data payload is in the second blob; bytes 0–3 of the decoded data are a hardware header and are discarded.

### TX_RESULT

```
TX_RESULT <outcome>
```

Reports the result of the most recent `TX` command.

| Outcome       | Meaning                                        | Server action                    |
|---------------|------------------------------------------------|----------------------------------|
| OK            | Transmission succeeded and was acknowledged    | Dequeue, fire ack event, send next |
| NO_SCOUT_ACK  | Scout was not acknowledged by destination      | Retry (if retries remain)        |
| NO_DATA_ACK   | Data phase was not acknowledged                | Retry (if retries remain)        |
| LINE_JAMMED   | Econet line is jammed                          | Retry (if retries remain)        |
| TIMEOUT       | Transmission timed out                         | Retry (if retries remain)        |
| UNDERRUN      | Pico buffer underrun during transmission       | Retry (if retries remain)        |
| OVERFLOW      | Pico buffer overflow                           | Retry (if retries remain)        |
| MISC          | Miscellaneous transmit error                   | Retry (if retries remain)        |
| UNINITIALISED | Interface not yet initialised                  | Retry (if retries remain)        |
| UNEXPECTED    | Internal Pico error                            | Clear ack event, no retry        |

When all retries are exhausted without `OK`, any pending service-level ack event for that destination is cleared so the service does not wait indefinitely.

## Acknowledgement Handling

The Pico handles Econet acknowledgements in hardware. The server does not need to construct or send Ack frames. `buildAck()` on a PiconetPacket returns an empty string, and no ack is written to the serial port.

The server does maintain its own logical ack events: when a `TX_RESULT OK` is received, the server constructs a synthetic Ack packet and fires it into the service dispatcher so that services which are waiting for a client ack (e.g. the file server's streaming ACK loop) can continue.

## Transmit Queue

Outbound packets are queued per connection (there is one Pico connection). Only one packet is in flight at any time. The next packet is sent only after the current one produces a `TX_RESULT`. On `TX_RESULT OK` the successfully sent entry is dequeued and the next packet is transmitted. On error the same entry is retried (up to the retry limit) before being dropped.

## Address Mapping

The piconet map file lists Econet network numbers, one per line, that are reachable via the piconet interface. Any Econet address whose network number appears in this list is routed to the Pico rather than via AUN. Up to 8 networks are supported.

Map file format — one network number per line:

```
138
139
```

Config key: `piconetmap_file`  
Config key: `piconet_local_network` — the Econet network number assigned to the local physical segment  
Config key: `piconet_station` — the Econet station number the server claims on the physical network

## Encapsulation Routing

When the server needs to send a reply packet, the `EncapsulationTypeMap` selects the transport:

1. If the destination is a WebSocket client — send via WebSocket.
2. If the destination network is in the piconet map — send via the Pico serial link.
3. Otherwise — send via AUN/UDP.

This means the same service code (file server, print server, etc.) works transparently for both real Econet stations attached to the Pico and AUN stations on the IP network.
