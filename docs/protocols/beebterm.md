# BeebTerm Protocol

BeebTerm provides terminal multiplexing over Econet. A BBC Micro client connects to the server and is given access to a named service — typically a shell or application running as a child process on the server. Multiple services can be configured; the client selects one by name at login.

## Port

All BeebTerm traffic uses a single port: `0xA2`.

## Packet Structure

The Econet control byte (flags field) identifies the packet type. Data is carried in the payload.

### Packet Types

| Flags byte | Type           | Direction      | Description                         |
|------------|----------------|----------------|-------------------------------------|
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

The high bit of the flags byte appears to be a parity or alternate-mode indicator; both values of each type are treated identically.

## Login Sequence

### Login Request (Client → Server)

Flags = `0x01`. Payload is the service name as a plain string.

### Login OK (Server → Client)

Flags = `0x02`. Payload is the service name string, confirming which service was opened.

### Login Reject (Server → Client)

Flags = `0x03`. Payload is the string `"Invalid Service"`.

The server rejects the login if the requested service name does not exist in the configured services file.

## Data Transfer

Once a session is established, data flows in both directions using DATA packets (flags = `0x00`).

### Data Packet Payload

```
Offset  Size  Field
------  ----  -----
0       1     RxSeq — last sequence number received from the remote end
1       1     TxSeq — current transmit sequence number
2+      n     Terminal data bytes
```

Data from the client is written to the child process's stdin. Data produced by the child process on stdout is sent back to the client.

## Termination

Either end may send a TERMINATE packet (flags = `0x04`) to close the session. The child process is killed and the session buffer is freed.

## Session Timeout

Sessions that produce no activity for 120 seconds are automatically terminated.

## Service Configuration

Services are defined in a plain text file pointed to by the `beeb_term_services_file` config key. Each line defines one service:

```
servicename "command to run"
```

Example:
```
shell "/bin/bash"
editor "/usr/bin/vi /home/bbc/document.txt"
```

The service name is matched case-insensitively against the name supplied in the login packet.
