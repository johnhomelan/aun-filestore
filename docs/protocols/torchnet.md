# TorchNet Protocol

TorchNet is the file-sharing protocol used by Torch computers (CP/M-based systems built on the BBC Micro). It provides record-oriented file access to a CP/M file system stored on the server, allowing Torch clients to share files across a network.

## Ports

| Port | Direction | Purpose                    |
|------|-----------|----------------------------|
| 0x90 | Inbound   | TorchNet command requests  |
| 0x91 | Outbound  | TorchNet command replies   |

## Packet Layout

### Request

```
Offset  Size  Field
------  ----  -----
0       1     Command code (uint8)
1+      n     Per-command payload
```

### Reply

```
Offset  Size  Field
------  ----  -----
0       1     Status byte (0x00 = success, 0xFF = general error)
1+      n     Per-command data (if success)
```

## Filename Convention

Torch/CP/M filenames use an 8.3 format with `.` as the extension separator (e.g. `MYPROG.COM`). On the Acorn/Unix server side the extension separator is `\` (backslash) so the filename is stored as `MYPROG\COM`. The server converts between these conventions automatically. Filenames are stored in the directory padded to 11 characters (8 name + 3 extension).

## Drive Mapping

Each CP/M drive letter is mapped to an Acorn file path. The default mapping is:

```
Drive E → $.TorchDrives.E
```

Drives can be individually configured using the `torchnet_drive_{letter}` config key (lowercase), for example `torchnet_drive_e`.

## Record and Sector Size

All block I/O uses 128-byte records. Record offsets are zero-indexed 16-bit integers (range 0–65535), giving a maximum file size of 8 MB.

## Access Modes

| Value | Mode       |
|-------|------------|
| 0x01  | Read-only  |
| 0x02  | Write-only |
| 0x03  | Read/write |

## File Handle Allocation

Handles are allocated from the range 1–254. Handle 0xFF is used as an error sentinel (invalid handle). Handles wrap around at 0xFE.

## Command Reference

### 0x01 — TORCH_OPEN

Opens a file for reading, writing, or both. Creates the file if it does not exist (write or read/write mode) and the file is absent.

```
Request:
  [1]     Drive ID (ASCII letter, e.g. 'E')
  [2]     Access mode (see above)
  [3..13] Filename (11 bytes, space-padded)

Reply success: [0x00, handle(1)]
Reply failure: [0xFF, 0x00]
```

### 0x02 — TORCH_CLOSE

Closes an open file handle.

```
Request:
  [1]  File handle

Reply: [0x00]
```

### 0x03 — TORCH_READ_BLOCK

Reads one or more 128-byte records from an open file.

```
Request:
  [1]     File handle
  [2..3]  Record offset (uint16 LE, zero-indexed)
  [4]     Maximum records to return

Reply (data available):
  [0x00, record_count(1), data(record_count × 128)]

Reply (at EOF):
  [0x01, 0x00]
```

Status byte `0x00` indicates data follows. Status byte `0x01` indicates end of file.

### 0x04 — TORCH_WRITE_BLOCK

Writes exactly one 128-byte record to an open file at the given record offset.

```
Request:
  [1]      File handle
  [2..3]   Record offset (uint16 LE, zero-indexed)
  [4]      Length (always 128)
  [5..132] Data (128 bytes)

Reply: [0x00]
```

### 0x05 — TORCH_DELETE

Deletes a file from the specified drive.

```
Request:
  [1]     Drive ID
  [2]     User group (ignored in current implementation)
  [3..13] Filename (11 bytes, space-padded)

Reply: [0x00] on success, [0xFF] on failure
```

### 0x06 — TORCH_SEARCH_FIRST

Begins a directory search using a filename mask. Wildcards are supported using CP/M conventions (`?` matches any single character, `*` or a blank extension matches any extension).

```
Request:
  [1]     Drive ID
  [2]     User group
  [3..13] Filename mask (11 bytes, space-padded)
```

The first matching entry is returned, or `[0xFF]` if no match is found.

```
Reply (found):
  [0x00, name(8 padded), ext(3 padded), record_count(1), alloc_bitmap(4)]

Reply (not found):
  [0xFF]
```

`alloc_bitmap` is returned as four bytes of `0xFF` (placeholder; allocation bitmap is not maintained).

### 0x07 — TORCH_SEARCH_NEXT

Returns the next matching entry from a directory search initiated by TORCH_SEARCH_FIRST.

```
Request:
  [1]     Drive ID
  [2]     User group
  [3..13] Filename mask (11 bytes)
```

Reply format is identical to TORCH_SEARCH_FIRST. Returns `[0xFF]` when no more matches exist.

### 0x08 — TORCH_CONSOLE_NOTIFY

A notification from the client about console state. No reply is sent.

### 0x09 — TORCH_PRINT_REDIRECT

A notification from the client about print redirection. No reply is sent.

### 0x0D — TORCH_CREATE

Creates a new empty file. If the file already exists it is truncated.

```
Request:
  [1]     Drive ID
  [2]     Access mode
  [3..13] Filename (11 bytes, space-padded)

Reply success: [0x00, handle(1)]
Reply failure: [0xFF, 0x00]
```

### 0x0E — TORCH_RENAME

Renames a file on the specified drive.

```
Request:
  [1]      Drive ID
  [2..12]  Old filename (11 bytes, space-padded)
  [13..23] New filename (11 bytes, space-padded)

Reply: [0x00] on success, [0xFF] on failure
```

### 0x10 — TORCH_MEM_PEEK

Requests a read of the client's memory. This operation accesses client hardware directly and cannot be fulfilled by the server. No reply is sent.

### 0x11 — TORCH_MEM_POKE

Requests a write to the client's memory. Cannot be fulfilled by the server. No reply is sent.

### 0x1A — TORCH_CONTROL_ACTION

A hardware control request that targets the client. Cannot be fulfilled by the server. No reply is sent.
