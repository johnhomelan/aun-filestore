# Econet File Server Protocol

The file server implements the Acorn Level 3 / Level 4 Econet file server protocol. Clients send function-code requests to port 0x99 (FileServerCommand). Bulk data transfers use a dynamically allocated stream port. Replies are sent to the reply port specified in each request.

## Ports

| Port | Direction | Purpose                                        |
|------|-----------|------------------------------------------------|
| 0x99 | Inbound   | File server command requests                   |
| 0x90 | Outbound  | File server replies (FileServerReply)          |
| 0x91 | Outbound  | File server streaming data (FileServerData)    |
| configurable | Both | Dynamic stream port for bulk transfers   |

## Request Packet Layout

All requests are sent to port 0x99. The first five bytes of the data payload are a fixed header:

```
Offset  Size  Field
------  ----  -----
0       1     Reply port (uint8) — server sends responses here
1       1     Function code (uint8)
2       1     URD handle (uint8) — user root directory
3       1     CSD handle (uint8) — current selected directory
4       1     LIB handle (uint8) — library directory
5+      n     Per-function payload
```

## Reply Packet Layout

Most replies begin with two bytes:

```
Offset  Size  Field
------  ----  -----
0       1     Reply type (uint8)
1       1     Error/status code (uint8) — 0x00 = success
2+      n     Per-function data
```

### Reply Types

| Value | Name       |
|-------|-----------|
| 0     | Done       |
| 1     | Save       |
| 2     | Load       |
| 3     | Cat        |
| 4     | Info       |
| 5     | Login      |
| 6     | SDisc      |
| 7     | Dir        |
| 8     | Unrec      |
| 9     | Lib        |
| 10    | Discs      |

### Error Reply Format

When `status != 0x00`, the packet is an error reply:

```
[0x00, error_code, error_message_string, 0x0D]
```

### Common Error Codes

| Code | Meaning                              |
|------|--------------------------------------|
| 0x8E | Bad INFO or OPT argument             |
| 0x8F | Not implemented / Bad RDARGS argument|
| 0x99 | No such file / unimplemented command |
| 0xAE | Not logged on (DoneNoton)            |
| 0xBB | Incorrect password                   |
| 0xBE | Not a directory                      |
| 0xBF | Who are you? (not authenticated)     |
| 0xC3 | File already open                    |
| 0xD6 | Not found                            |
| 0xFF | No such file or other fatal error    |

## Date Encoding

File dates are encoded in a 2-byte format throughout the protocol:

```
Byte 0: Day of month (1–31, binary)
Byte 1: (year_2digit << 4) | month  — high nibble = year mod 100, low nibble = month (1–12)
```

## Function Codes

### 0x00 — EC_FS_FUNC_CLI

Sent as either a unicast or broadcast. The data payload is a raw CLI command string. The server parses and dispatches to the appropriate CLI handler. Broadcasts are accepted without an authenticated session (used for disc discovery).

Supported CLI commands: `BYE`, `CAT`, `CDIR`, `DELETE`, `DIR`, `FSOPT`, `I AM`, `INFO`, `LIB`, `LOAD`, `LOGOFF`, `OPT`, `PASS`, `RENAME`, `SDISC`, `NEWUSER`, `PRIV`, `REMUSER`, `CHROOT`, `CHROOTOFF`.

### 0x01 — EC_FS_FUNC_SAVE

```
Request payload:
  [1..4]  Load address (uint32 LE)
  [5..8]  Exec address (uint32 LE)
  [9..11] File size (uint24 LE)
  [12+]   Path string (CR-terminated)
  URD     Ack port for data stream
```

Initial reply — instructs client to send data:
```
[0x01, 0x00, data_stream_port, block_size_lo, block_size_hi]
```
Maximum block size advertised: 968 bytes (little-endian).

Completion reply — sent after all data received:
```
[0x00, 0x00, access_byte, day, year_month]
```

### 0x02 — EC_FS_FUNC_LOAD

```
Request payload:
  URD     Port to stream file data to
  [1+]    Path string (CR-terminated)
```

Initial reply — metadata:
```
[0x02, 0x00, load(4 LE), exec(4 LE), size(3 LE), access(1), ctime(2)]
```

File data is then streamed in 256-byte blocks to the URD port. Each block is acknowledged by the client before the next is sent. Final reply:
```
[0x00, 0x00]
```

### 0x03 — EC_FS_FUNC_EXAMINE

```
Request payload:
  [1]  Examine type (0–3)
  [2]  Start offset (pagination)
  [3]  Count (max entries to return)
```

The response contains directory entries starting at `start` for up to `count` items, terminated by `0x80`.

| Type | Name           | Entry format                                                        |
|------|----------------|---------------------------------------------------------------------|
| 0    | EXAMINE_ALL    | `name(11), load(4 LE), exec(4 LE), access(1), ctime(2), sin(3 LE), size(3 LE)` |
| 1    | EXAMINE_LONGTXT| `name(11), formatted_text_line, NUL`                                |
| 2    | EXAMINE_NAME   | `0x0A, name(10)`                                                    |
| 3    | EXAMINE_SHORTTXT| `name(11), 0x20, mode_string(6), NUL`                             |

All types are terminated with a single `0x80` byte.

### 0x04 — EC_FS_FUNC_CAT_HEADER

Returns the disc name, CSD leaf name, and LIB leaf name.

```
Reply: [0x03, 0x00, disc_name(16), 0x0D, csd_leaf(10), 0x0D, lib_leaf(10), 0x0D, 0x80]
```

### 0x05 — EC_FS_FUNC_LOAD_COMMAND

Identical to LOAD (0x02) but triggered by `*LOAD` CLI. Handled by the same code path.

### 0x06 — EC_FS_FUNC_OPEN

```
Request payload:
  [1]   MustExist (0 = create if absent, else must exist)
  [2]   ReadOnly (0 = read/write, else read-only)
  [3+]  Path string (CR-terminated)
```

Success reply:
```
[0x00, 0x00, handle]
```

Error: 0xC3 if the file is already open, 0xFF if not found.

### 0x07 — EC_FS_FUNC_CLOSE

```
Request payload:
  [1]  Handle (0 = close all handles for this session)
```

Reply: `[0x00, 0x00]`

### 0x08 — EC_FS_FUNC_GETBYTE

```
Request payload:
  [1]  Handle
```

Reply: `[0x00, 0x00, byte_value, eof_flag]`  
`eof_flag` is `0x80` when at end of file, `0x00` otherwise.

### 0x09 — EC_FS_FUNC_PUTBYTE

```
Request payload:
  [1]  Handle
  [2]  Byte value
```

Reply: `[0x00, 0x00]`

### 0x0A — EC_FS_FUNC_GETBYTES

```
Request payload:
  URD     Port to stream data to
  [1]     Handle
  [2]     UsePointer (0 = current position, else seek first)
  [3..5]  Byte count (uint24 LE)
  [6..8]  Offset (uint24 LE, used if UsePointer != 0)
```

Immediate reply: `[0x00, 0x00]`

Data is streamed in 256-byte blocks to the specified port. If the file runs out of data before the requested count, the remaining bytes are padded with `0x00` and the EOF flag is set.

Final reply:
```
[0x00, 0x00, eof_flag, bytes_sent(3 LE)]
```
`eof_flag` is `0x80` if EOF was reached, `0x00` otherwise.

### 0x0B — EC_FS_FUNC_PUTBYTES

```
Request payload:
  [1]     Handle
  [2]     UsePointer (0 = current position, else seek first)
  [3..5]  Byte count (uint24 LE)
  [6..8]  Offset (uint24 LE, used if UsePointer != 0)
```

Reply — instructs client to send data:
```
[0x00, 0x00, data_stream_port, block_size_lo, block_size_hi]
```
Maximum block size advertised: 256 bytes. Stream timeout: 60 seconds.

Completion reply:
```
[0x00, 0x00, 0x00, bytes_written(3 LE)]
```

### 0x0C — EC_FS_FUNC_GET_ARGS

```
Request payload:
  [1]  Handle
  [2]  Argument type
```

| Type | Name         | Reply data        |
|------|--------------|-------------------|
| 0    | EC_FS_ARG_PTR  | `ptr(3 LE)`     |
| 1    | EC_FS_ARG_EXT  | `size(3 LE)`    |
| 2    | EC_FS_ARG_SIZE | `size(3 LE)`    |

Reply: `[0x00, 0x00, value(3 LE)]`

### 0x0D — EC_FS_FUNC_SET_ARGS

```
Request payload:
  [1]     Handle
  [2]     Argument type
  [3..5]  Value (uint24 LE)
```

| Type | Name           | Effect             |
|------|----------------|--------------------|
| 0    | EC_FS_ARG_PTR  | Set file pointer   |
| 1    | EC_FS_ARG_EXT  | Set file extent    |

Reply: `[0x00, 0x00]`

### 0x0E — EC_FS_FUNC_GET_DISCS

```
Request payload:
  [1]  First drive index
  [2]  Count requested
```

Reply (for drive 0, one disc):
```
[0x0A, 0x00, 1, 0x00, disc_name(16 bytes padded)]
```

### 0x0F — EC_FS_FUNC_GET_USERS_ON

```
Request payload:
  [1]  Start offset
  [2]  Count
```

Reply:
```
[0x00, 0x00, remaining_count, (net(1), stn(1), username(10), 0x0D, priv_flag(1))×n]
```

### 0x10 — EC_FS_FUNC_GET_TIME

Reply:
```
[0x00, 0x00, day(1), (year_2digit<<4)|month(1), hour(1), min(1), sec(1)]
```

### 0x11 — EC_FS_FUNC_GET_EOF

```
Request payload:
  [1]  Handle
```

Reply: `[0x00, 0x00, 0xFF]` if at EOF, `[0x00, 0x00, 0x00]` if not.

### 0x12 — EC_FS_FUNC_GET_INFO

```
Request payload:
  [1]   Subtype
  [2+]  Path string (CR-terminated)
```

| Subtype | Name                  | Reply data after `[0x04, 0x00, type_byte]`                    |
|---------|-----------------------|---------------------------------------------------------------|
| 1       | EC_FS_GET_INFO_LOAD   | `load(4 LE)`                                                  |
| 2       | EC_FS_GET_INFO_EXEC   | `exec(4 LE)`                                                  |
| 3       | EC_FS_GET_INFO_SIZE   | `size(3 LE)`                                                  |
| 4       | EC_FS_GET_INFO_ACCESS | `access(1)`                                                   |
| 5       | EC_FS_GET_INFO_ALL    | `load(4 LE), exec(4 LE), size(3 LE), access(1), ctime(2)`    |
| 6       | EC_FS_GET_INFO_DIR    | `[0x00, 0x00, 10], dir_name(10 padded), access(1), cycle(1)` |
| 7       | EC_FS_GET_INFO_UID    | `sin(3 LE)`                                                   |

Type byte: `0x01` = file, `0x02` = directory.

### 0x13 — EC_FS_FUNC_SET_INFO

```
Request payload:
  [1]  Subtype
```

| Subtype | Name                  | Additional payload                                         |
|---------|-----------------------|------------------------------------------------------------|
| 1       | EC_FS_SET_INFO_ALL    | `load(4 LE), exec(4 LE), access(1), path(CR-terminated)`  |
| 2       | EC_FS_SET_INFO_LOAD   | `load(4 LE), path(CR-terminated)`                         |
| 3       | EC_FS_SET_INFO_EXEC   | `exec(4 LE), path(CR-terminated)`                         |
| 4       | EC_FS_SET_INFO_ACCESS | `access(1), path(CR-terminated)`                          |

Reply: `[0x00, 0x00]`

### 0x14 — EC_FS_FUNC_DELETE

```
Request payload:
  [1+]  Path string (CR-terminated)
```

Reply: `[0x00, 0x00]`

### 0x15 — EC_FS_FUNC_GET_UENV

Returns the current session environment.

```
Reply: [0x00, 0x00, 16, disc_name(16 padded), csd_leaf(10 padded), lib_leaf(10 padded)]
```

### 0x16 — EC_FS_FUNC_SET_OPT4

```
Request payload:
  [1]  Boot option (0–3)
```

Reply: `[0x00, 0x00]`

### 0x17 — EC_FS_FUNC_LOGOFF

Logs out the current session. Reply: `[0x00, 0x00]`

### 0x18 — EC_FS_FUNC_GET_USER

```
Request payload:
  [1+]  Username string (CR-terminated)
```

If the user is logged in:
```
[0x00, 0x00, priv_flag(1), network(1), station(1)]
```

If not logged in: `[0x00, 0xAE]` (DoneNoton).

### 0x19 — EC_FS_FUNC_GET_VERSION

```
Reply: [0x00, 0x00, version_string]
```

Version string format: `aunfs_srv <version>`.

### 0x1A — EC_FS_FUNC_GET_DISC_FREE

```
Request payload:
  [1+]  Disc name string
```

Reply: `[0x00, 0x00, free(3 LE), total(3 LE)]`

Values are taken from configuration (`vfs_default_disc_free`, `vfs_default_disc_size`).

### 0x1B — EC_FS_FUNC_CDIRN

```
Request payload:
  [2+]  Directory name (CR-terminated, 1–10 characters)
```

Reply: `[0x00, 0x00]`

### 0x1C — EC_FS_FUNC_RENAME

```
Request payload:
  [1]    Source directory handle (0 = CSD)
  [2+]   Source name (CR-terminated)
  [n]    Destination directory handle (0 = CSD)
  [n+1+] Destination name (CR-terminated)
```

Reply: `[0x00, 0x00]`

### 0x1D — EC_FS_FUNC_CREATE

```
Request payload:
  URD     Ack port
  [1..4]  Load address (uint32 LE)
  [5..8]  Exec address (uint32 LE)
  [9..11] Size (uint24 LE)
  [12+]   Path string (CR-terminated)
```

If size > 0, the server allocates the file and instructs the client to stream data (same reply format as SAVE, max block 968 bytes). If size == 0, no stream is opened:

```
[0x00, 0x00, 15, day, year_month]
```

### 0x1E — EC_FS_FUNC_GET_USER_FREE

```
Request payload:
  [1+]  Username string
```

Reply: `[0x00, 0x00, free(3 LE)]`

Value is taken from configuration (`vfs_default_disc_free`).

### 0x20 — EC_FS_FUNC_WHO_AM_I

Reply: `[0x00, 0x00, username_string, 0x0D]`

### 0x21 / 0x22 — EC_FS_FUNC_USERS_EXT / EC_FS_FUNC_USER_INFO_EXT

Not implemented. Returns error 0x8F "Not implemented".

### 0x23 — EC_FS_FUNC_COPY_DATA

Server-side file copy between two open handles.

```
Request payload:
  [1]     Source handle
  [2..4]  Source offset (uint24 LE)
  [5]     Destination handle
  [6..8]  Destination offset (uint24 LE)
  [9..11] Length (uint24 LE)
```

Reply: `[0x00, 0x00]`

## Login Sequence

Login is performed via the `*I AM` CLI command (function code 0x00). On success the server replies:

```
[0x05, 0x00, urd_handle, csd_handle, lib_handle, boot_opt]
```

All subsequent function codes require an authenticated session. Requests from unauthenticated stations receive error 0xBF "Who are you?".

## Privilege Levels

Two privilege levels are supported:

| Level | Meaning                                           |
|-------|---------------------------------------------------|
| S     | Sysop — can create/remove users and change privileges |
| U     | Normal user                                       |

## Streaming Data Protocol

For operations that transfer a large amount of data (LOAD, SAVE, GETBYTES, PUTBYTES, CREATE), the server allocates a temporary stream port. The stream port number and maximum block size are included in the initial reply. The client sends or receives data in blocks on that port. Each block is acknowledged before the next is sent. Stream ports time out after 60 seconds of inactivity.

Maximum 20 stream ports are available simultaneously across all active sessions.
