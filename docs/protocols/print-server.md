# Econet Print Server Protocol

The print server implements the Acorn Econet print server protocol. Clients discover the printer via an enquiry on port 0x9F and submit print jobs on port 0xD1.

## Ports

| Port | Direction | Purpose                    |
|------|-----------|----------------------------|
| 0x9F | Inbound   | Printer enquiry            |
| 0x9E | Outbound  | Printer enquiry reply      |
| 0xD1 | Inbound   | Print data                 |
| 0xD0 | Outbound  | Print data reply           |

## Printer Discovery (Port 0x9F)

Clients send enquiry packets to port 0x9F, either unicast or broadcast. Broadcast allows a client to find a print server without knowing its address.

### Enquiry Request Layout

```
Offset  Size  Field
------  ----  -----
0       6     Printer name (ASCII, padded with spaces or nulls)
6       2     Request code (uint16 LE)
```

### Enquiry Reply Layout

The server replies to port 0x9E (PrinterServerEnquiryReply) with a 2-byte status word:

```
Offset  Size  Field
------  ----  -----
0       2     Status word (uint16 LE)
```

The status word is split into two fields:

```
Bits 0–2: Input status (client → print server path)
Bits 3–4: Output status (print server → printer path)
Bits 5–7: Reserved (always 0)
```

#### Input Status Values (bits 0–2)

| Value | Meaning                                          |
|-------|--------------------------------------------------|
| 0     | Ready — printer accepting data                   |
| 1     | Busy — temporarily unable to accept data         |
| 2     | Jammed (software problem)                        |
| 3     | Jammed (printer offline — hardware problem)      |
| 4     | Jammed (disc full, directory full, etc.)         |
| 5     | User not authorised to use this printer          |
| 6     | Going offline — operator has barred input        |
| 7     | Reserved                                         |

#### Output Status Values (bits 3–4)

| Value | Meaning                                          |
|-------|--------------------------------------------------|
| 0     | Ready                                            |
| 1     | Printer offline                                  |
| 2     | Printer jammed (not accepted data for a long time)|

The server currently always returns status 0 (ready).

## Print Data Protocol (Port 0xD1)

Print jobs consist of three phases: job start, data transfer, and job end. All print data packets receive a single-byte `[0x00]` acknowledgement reply to port 0xD0.

### Job Start

A packet of exactly 1 byte containing `0x00` signals the start of a new print job. The server allocates a per-station buffer for the job.

```
Data: [0x00]
```

### Data Transfer

Any packet that is not a single `0x00` byte is treated as print data and appended to the station's buffer. Multiple data packets may be sent for a single job.

### Job End Detection

The end of a print job is detected when the last byte of a data packet is `0x03` (ASCII ETX). On detection:

1. The server resolves the spool directory (`print_server_spool_dir` config key).
2. The authenticated user for the source station is looked up. If logged in, the job is spooled to `<spool_dir>/<username>/`. If not logged in, it is spooled to `<spool_dir>/anon-<network>-<station>/`.
3. The per-user subdirectory is created if it does not exist.
4. The raw job data is written to `<spool_dir>/<user>/<HH-MM-SS-DD-MM-YYYY>.raw`.
5. If a conversion script is configured (`print_server_conversion_script`), it is launched asynchronously to convert the file. The output path uses `,ps` as the suffix (Acorn comma-type convention).
6. The in-memory buffer for that station is released.

If the spool directory does not exist when a job ends, the job data is discarded and a warning is logged. No error is reported to the client.

## File Conversion

The optional conversion script is specified in config as a command line containing two substitution tokens:

| Token         | Replaced with            |
|---------------|--------------------------|
| `%source%`    | Path to the `.raw` file  |
| `%destination%`| Path to the `,ps` output file |

The script runs as a background child process. Success and failure are logged but not reported to the client.

## Spooled Job Tracking

The admin interface exposes two views of print server state:

**Active jobs** (`jobs`) — print jobs currently being received into memory:
- Network, station
- Timestamp when the job started
- Bytes buffered so far

**Spooled files** (`spooled`) — completed jobs on disk:
- Username (or `anon-<net>-<stn>`)
- Filename and size
- Modification timestamp
- Relative path within the spool directory
