# ShareFS / Freeway / Access+ Protocols

**This is a UDP/IP protocol suite, not an Econet encapsulation.** Every other
document in this directory (`aun.md`, `piconet.md`, `remote-bridge.md`, …)
describes a way of *carrying Econet packets*. Freeway, Access+, and the
ShareFS data protocol carry no Econet packets at all — they are RISC OS's
native UDP/IP-based file-sharing suite. There is no `EconetPacket`, no
network/station addressing on the wire, and no relationship to
`Encapsulation\EncapsulationInterface`. See `docs/SHAREFSD.md` for how this
keeps the standalone `sharefsd` daemon architecturally separate from
`filestored`.

## Source

Acorn's own ShareFS/Access+/Freeway source is closed or binary-only. Ports,
endianness, framing, `FileDesc` layout, and every command's request/reply
shape below match a real, working, open-source server instead:
**andrewtimmins/riscos-access-server** (GPL-3.0, C), specifically its shipped
source rather than its own prose documentation.

A handful of things are deliberately **not** implemented — each is called out
at its own section below.

## Ports

| Port | Protocol |
|---|---|
| 32770 (UDP) | Freeway discovery broadcast |
| 32771 (UDP) | Access+ authentication |
| 49171 (UDP) | ShareFS file-data RPC |

All multi-byte integers on all three ports are **little-endian**.

---

## Freeway discovery (UDP port 32770)

**Class:** `HomeLan\FileStore\ShareFs\FreewayHandler`
**Packet:** `HomeLan\FileStore\ShareFs\Messages\FreewayPacket`

`sharefsd` periodically re-broadcasts every advertised share, unprompted —
matching the reference server exactly, it does **not** listen for or reply to
a discovery request on this port at all. `Protected` and `Hidden` shares are
never broadcast here (`Share::isAdvertised()`); `Protected` shares are
announced separately, only on a successful Access+ match (see below).

### Packet layout

```
Offset  Size  Field
0       4     word0 = (type << 16) | minor
4       4     version/flags, fixed 0x00010000
8       4     lengths = (descLength << 16) | nameLength  (each includes its trailing null)
12      N     name, null-terminated (nameLength bytes)
12+N    M     description, null-terminated (descLength bytes)
```

`type`: `1` = disc (the only one `sharefsd` uses), `2` = printer.
`minor`: `1` = startup/request, `2` = available (what `sharefsd` sends,
repeatedly, on every broadcast tick — there is no separate "refresh" message,
the same "available" message is just re-sent), `3` = removed (not sent by
this daemon), `4` = periodic (only sent by Access+ on a successful match, see
below — not used for the general broadcast).

---

## Access+ authentication (UDP port 32771)

**Class:** `HomeLan\FileStore\ShareFs\AccessPlusHandler`
**Packet:** `HomeLan\FileStore\ShareFs\Messages\AccessPlusPacket`
**State:** `HomeLan\FileStore\ShareFs\ShareAuthTable`

Real Access+ has **no user-account concept at all** — there is nothing here
resembling a login with a username. A `Protected` share carries its own
password (max 6 characters); a client wanting it folds that password into a
PIN and sends it as a "share key". If the folded key matches the server's own
fold of that share's configured password, the requester's **IP address**
(not IP:port — just the IP) is recorded as authenticated for that share for a
sliding **10-minute** window, refreshed on every subsequent use.

A non-matching key gets **silence**, not an error reply — a real client is
expected to just keep trying different password guesses.

### PIN folding

Uppercase the password; each character maps to a value (digit → 1..10,
letter → 11..36, anything else → 0); fold left to right:

```
pin = 0
for each of the first 6 characters:
    pin = pin*37 + value(character)
```

(`HomeLan\FileStore\ShareFs\Messages\AccessPlusPacket::foldPassword()`.)

### Packet layout

```
Share-key request (client -> server):
  Offset  Size  Field
  0       4     messageType = 0x00010001
  4       4     shareType   = 0x00010001
  8       4     clientKey (the folded PIN)

Protected-share reply (server -> client, only on a match):
  Offset  Size  Field
  0       4     messageType = 0x00010004
  4       4     shareType   = 0x00010001
  8       4     lengths = (0x0001 << 16) | nameLength   - note: NOT a real description
                                                           length like the Freeway packet's
                                                           equivalent field, just a fixed 1
  12      4     shareKey (echoes the matched PIN)
  16      N     name (nameLength bytes, not itself null-terminated)
  16+N    1     attributes byte (0x01=protected, 0x02=readonly, 0x04=hidden)
  17+N    1     null terminator
```

---

## ShareFS file-data RPC (UDP port 49171)

**Class:** `HomeLan\FileStore\ShareFs\ShareFsHandler`
**Packet:** `HomeLan\FileStore\ShareFs\Messages\ShareFsPacket`
**Conversions:** `HomeLan\FileStore\ShareFs\RiscOsMeta`

Every command (other than `RVERSION`) resolves a `"<share>.<path>"` client
path against `ShareList`. If the share is `Protected`, the request is
rejected with `EACCES` unless `ShareAuthTable::check()` says that client IP
has already passed Access+ for that share.

There is still no per-client identity beyond that IP check: every operation
actually touches `Vfs` as one fixed **service identity**, logged in once at
daemon startup (`sharefs_service_username`/`sharefs_service_password` — see
`docs/SHAREFSD.md`). `ShareFsHandler` tracks its own handle-ownership table
(`Vfs` handle id → owning client's `ip:port`) purely so one client can't
operate on a handle another client opened — `Vfs` itself has no notion of
"client" to enforce that.

### Request framing

```
'A' rid[3] code(4) <command-specific body>   - main RPC
'F' rid[3] code(4) handle(4)                 - no-path queries (RVERSION, RDEADHANDLES)
```

`rid` is a 3-byte transaction id the client picks and the server echoes back
on every reply belonging to that transaction (including the multi-packet
`D`/`r` and `w`/`d` streaming exchanges below).

**Not implemented:** an alternate `'B'`-command framing some real clients use
(an immediate full directory catalogue on `ROPENDIR`, and a single-shot
`S`+`B` reply for `RREAD` instead of the streaming ping-pong). Only the
primary `'A'`/`'F'` framing is handled; a `'B'` packet is logged and ignored.

### Reply framing

```
'R' rid[3] payload...          success
'E' rid[3] errno(4)            failure - standard POSIX errno values, regardless of host OS
```

### `FileDesc` (20 bytes)

```
Offset  Size  Field
0       4     load address
4       4     exec address
8       4     length (undefined for directories)
12      4     attrs: 0x01=R(owner read) 0x02=W(owner write) 0x08=L(locked) 0x10=r(public read) 0x20=w(public write)
16      4     type_and_flags: 1=file 2=dir, bit 8 always set ("buffered")
```

Load/exec encode a RISC OS filetype and timestamp exactly the way this
codebase's own Econet file server already does (`RiscOsMeta`), so no
conversion is needed going into or out of `Vfs` — the two protocols share the
same load/exec convention.

`attrs` has no direct equivalent in this codebase's own Econet-style
`DirectoryEntry` access byte (owner/public bits are laid out differently and
there is no separate "owner" once every client shares one service identity)
— `RiscOsMeta::econetAccessToShareFsAttrs()` does a documented best-effort
conversion: owner-read is always asserted, owner-write tracks "not locked".

### Command table

| Code | Name | Request body (after `code`, offset 8) | Reply payload |
|---|---|---|---|
| 0x00 | RFIND | handle(4, unused) + path(z) | FileDesc — **stat only, allocates no handle** |
| 0x01 | ROPENIN | handle(4, unused) + path(z) | FileDesc + handle(4) — open existing, read |
| 0x02 | ROPENUP | handle(4, unused) + path(z) | FileDesc + handle(4) — open existing, read/write |
| 0x03 | ROPENDIR | handle(4, unused) + path(z) | handle(4) + token(4, always 0 - no listing cache to invalidate) |
| 0x04 | RCREATE | handle(4, unused) + path(z) | FileDesc + handle(4) |
| 0x05 | RCREATEDIR | handle(4, unused) + path(z) | FileDesc + handle(4) |
| 0x06 | RDELETE | handle(4, unused) + path(z) | FileDesc **of the deleted item** |
| 0x07 | RACCESS | attrs(4) + path(z) at offset 16 | FileDesc |
| 0x08 | RFREESPACE | *(none)* | free(4) + largest(4) + total(4) — fake values from `vfs_default_disc_free`/`vfs_default_disc_size`, same as the Econet file server reports |
| 0x09 | RRENAME | newNameLength(4) + oldPath(z) at offset 16 | two-step, see below |
| 0x0a | RCLOSE | handle(4) | *(empty)* |
| 0x0b | RREAD | handle(4) + offset(4) + amount(4) | streaming `D`/`r`, then `R` bytesSent(4)+newSeqPtr(4) |
| 0x0c | RWRITE | handle(4) + offset(4) + amount(4) | streaming `w`/`d`, then empty `R` |
| 0x0d | RREADDIR | handle(4) + startEntry(4) | one page of a combined `S`+`B` catalogue |
| 0x0e | RENSURE | handle(4) + size(4) | size(4) — **grow-only**, see below |
| 0x0f | RSETLENGTH | handle(4) + size(4) | size(4) — **grow-only**, see below |
| 0x10 | RSETINFO | handle(4) + load(4) + exec(4) | FileDesc — renames to add/refresh a `,xxx` suffix if the new filetype needs one |
| 0x11 | RGETSEQPTR | handle(4) | position(4) |
| 0x12 | RSETSEQPTR | handle(4) + position(4) | position(4) |
| 0x13 | RDEADHANDLES | handle(4, unused), via `'F'` | count(4)=0 — see below |
| 0x14 | RZERO | handle(4) + offset(4) + length(4) | new length(4) |
| 0x15 | RVERSION | *(none)*, via `'A'` or `'F'` | version(4) = 2 |

Any other code gets `ENOSYS`.

### RREAD — streaming `D`/`r`

```
1. Client -> Server: 'A' code=0x0b, handle, offset, amount
2. Server -> Client: 'D' rid relativeOffset(4) data...   (up to 1024 bytes)
3. Client -> Server: 'r' rid                              (ack, no payload)
4. Repeat 2-3 until `amount` is satisfied or a chunk comes back empty
5. Server -> Client: 'R' rid bytesSent(4) newSeqPtr(4)
```

`offset = 0xFFFFFFFF` means "continue from the handle's current position"
rather than an absolute offset.

### RWRITE — streaming `w`/`d`

```
1. Client -> Server: 'A' code=0x0c, handle, offset, amount
2. Server -> Client: 'w' rid relativePos(4) 0(4) relativeEnd(4)   (requests up to 1024 bytes)
3. Client -> Server: 'd' rid relativePos(4) data...
4. Repeat 2-3 until `amount` bytes have been written
5. Server -> Client: 'R' rid   (empty payload)
```

### RRENAME — two-step, reuses the `w`/`d` exchange

```
1. Client -> Server: 'A' code=0x09, newNameLength, oldPath
2. Server -> Client: 'w' rid 0(4) 0(4) newNameLength(4)   (requests the new name)
3. Client -> Server: 'd' rid 0(4) newName...
4. Server -> Client: 'R' rid   (or 'E' errno on failure)
```

Only one rename may be in flight for the whole daemon at a time (matching the
reference server's single global pending-rename slot) — a second `RRENAME`
arm while one is outstanding gets `EPERM`.

### RREADDIR — paginated `S`+`B` catalogue

```
'S' rid entriesLength(4) 0x0c(4) entry entry entry...
'B' rid entriesLength(4) 0xFFFFFFFF(4)
```

Each entry is a `FileDesc` (20 bytes) followed by a null-terminated name,
padded to a 4-byte boundary. A page stops once it would exceed roughly 1400
bytes; the client re-issues `RREADDIR` with a larger `startEntry` for more.
Unlike the reference server, `sharefsd` does not cache a directory's listing
across pages — each `RREADDIR` call re-reads it fresh from `Vfs`, sorted the
same way (case-insensitive, alphabetical) so pagination stays consistent as
long as the directory doesn't change mid-listing.

### RENSURE / RSETLENGTH / RZERO — the grow-only limitation

`Vfs`/`FileDescriptor` expose `read()`/`write()`/`setPos()` but no truncate
primitive. `RENSURE` and `RSETLENGTH` can therefore only **grow** a file (by
writing zero bytes out to the target length); `RSETLENGTH` asked to shrink a
file replies `ENOSYS` rather than silently doing nothing. `RZERO` (write
zeros over a byte range, extending if needed) is unaffected, since it was
always a write operation.

### RDEADHANDLES

Always replies with a count of 0. The reference server proactively broadcasts
handles it decided to reap (e.g. on client idle-timeout) to every known
client; `sharefsd` does not track "dead" handles or broadcast this
proactively — only the query reply path is implemented.

### Filetypes

Filetype resolution is suffix-based only (`,xxx` on a name, per
`RiscOsMeta::filetypeFromSuffix()`/`appendTypeSuffix()`), defaulting to
`0xFFD` (data) for a newly created file with no suffix. The reference
server's extension→filetype `[mimemap]` config table is not implemented.

---

## Configuration

See `docs/Config.md` → "ShareFS / Access+" for the full list of `sharefs_*`
config keys (ports, listen addresses, the share list file, and the service
identity every operation runs as). See `docs/SHAREFSD.md` for how the daemon
that speaks these protocols is put together.
