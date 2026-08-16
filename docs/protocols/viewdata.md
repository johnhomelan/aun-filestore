# Viewdata Protocol

The Viewdata service bridges an Econet station to a remote viewdata/videotex
server — such as [Telstar](https://glasstty.com/telstar/), the open-source
Prestel-style videotex server — over a plain TCP connection. A BBC client
connects, and from that point on it's a full-duplex terminal: keystrokes go
out one at a time as the user types, and page text comes back and is
displayed as it arrives. The server does no character-set translation or
pacing of its own — the upstream viewdata server already throttles page
rendering to an emulated period baud rate, so this is a transparent byte
pipe in both directions.

Unlike BeebTerm, there is exactly one upstream target (configured server-side
— see below) rather than a menu of named local services, so login carries no
service-name payload.

## Port

All Viewdata traffic uses a single port: `0xA3`.

## Packet Structure

The Econet control byte (flags field) identifies the packet type, same
scheme as BeebTerm. Data is carried in the payload.

### Packet Types

| Flags byte | Type           | Direction      | Description                         |
|------------|----------------|----------------|--------------------------------------|
| 0x01       | LOGIN          | Client → Server| Request to open a session           |
| 0x81       | LOGIN          | Client → Server| Login (alternate flags value)       |
| 0x02       | LOGIN_OK       | Server → Client| Session accepted                    |
| 0x82       | LOGIN_OK       | Server → Client| Session accepted (alternate)        |
| 0x03       | LOGIN_REJECT   | Server → Client| Session rejected                    |
| 0x83       | LOGIN_REJECT   | Server → Client| Session rejected (alternate)        |
| 0x04       | TERMINATE      | Either         | Session terminated                  |
| 0x84       | TERMINATE      | Either         | Session terminated (alternate)      |
| 0x00       | DATA           | Either         | Terminal data (in-session)          |
| 0x80       | DATA           | Either         | Terminal data (alternate)           |

As with BeebTerm, the high bit of the flags byte is a parity/alternate-mode
artifact of real Econet hardware (transmitting stations always set it); both
values of each type are treated identically on receipt.

## Login Sequence

### Login Request (Client → Server)

Flags = `0x01`. No payload — the server always connects to the single
configured upstream target (see **Backend Configuration** below).

### Login OK (Server → Client)

Flags = `0x02`. No payload.

### Login Reject (Server → Client)

Flags = `0x03`. Payload is a short human-readable error string (for example
`"Unable to connect to viewdata server"`), sent when the server's outbound
TCP connection to the upstream target could not be established.

## Data Transfer

Once a session is established, data flows in both directions using DATA
packets (flags = `0x00`). Keystrokes are sent to the server one at a time as
they're typed — no line buffering — matching how Telstar (and real Prestel
terminals) expect input.

### Data Packet Payload

```
Offset  Size  Field
------  ----  -----
0       1     RxSeq — last sequence number received from the remote end
1       1     TxSeq — current transmit sequence number
2+      n     Terminal data bytes
```

The sequence numbers are a simple network-level duplicate-suppression
mechanism (filtering out packets Econet/AUN might deliver more than once),
not a reliable-retransmission layer — neither side retries an unacknowledged
send. A packet whose sequence number doesn't advance past what was last seen
is treated as a duplicate and silently dropped, with no reply sent.

Because both sides start their counter at 0, a genuinely-first packet using
0 is indistinguishable from "nothing new since last time" and would be
dropped. Clients must send their first DATA packet with a non-zero sequence
number (1 is fine) to avoid this; correspondingly, a client's own
duplicate-filter for data *from* the server should not treat 0 as an
already-seen value at session start.

Data from the client is written straight to the upstream TCP connection.
Data received on that connection is sent back to the client, unmodified.

## Termination

Either end may send a TERMINATE packet (flags = `0x04`) to close the
session. The server closes the upstream TCP connection and frees the
session; a TCP close initiated by the *upstream* server likewise causes a
TERMINATE to be sent to the Econet client.

## Session Timeout

Sessions that produce no activity for 120 seconds are automatically
terminated.

## Backend Configuration

There is no per-session service selection — every session connects to the
same configured upstream server:

| Config key       | Default        | Meaning                                              |
|-------------------|----------------|-------------------------------------------------------|
| `viewdata_host`   | `glasstty.com` | Hostname/IP of the upstream viewdata/videotex server  |
| `viewdata_port`   | `6502`         | TCP port to connect to                                 |

Telstar specifically offers `6502`/`6503` (8 data bits, no parity) and
`6504` (7 data bits, even parity, for legacy terminal emulation) — pick
whichever framing matches the client software in use. See
[glasstty.com/telstar](https://glasstty.com/telstar/) for details of the
server side.

## See also

- `bbc-tests/VDTEST.BBC` — a BBC BASIC client implementing this protocol.
- `bbc-tests/VDCMD.BBC` — the same client as a `*command` (6502 assembler
  build script), which also accepts a `station` or `network.station`
  argument to override the station it connects to.

Both clients display received pages in MODE 7 (teletext mode) without any
translation — Mode 7's control codes are the same ones Prestel/Viewdata
pages use.
