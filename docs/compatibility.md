# Compatibility with L3, L4, and MDFS File Servers

This document describes the degree of compatibility between this server and the
three major historical Acorn Econet file server implementations:

- **L3** — Level 3 file server, running on a BBC Micro (ROM-based, circa 1984)
- **L4** — Level 4 file server, running on a BBC Master or fileserver ROM (ANFS, circa 1986)
- **MDFS** — Master Disc File Server by SJ Research (dedicated hardware/software, circa 1987–1992)

The L3, L4, and MDFS columns describe what the **original server firmware** supported,
not what clients of that era were capable of requesting. Our server implements all
function codes listed; the N/A entries indicate the original server hardware/software did
not provide that function, so clients of that era would not normally request it.

| Symbol | Meaning |
|--------|---------|
| ✓ | Fully compatible |
| ~ | Partially compatible or differs in minor ways |
| ✗ | Not implemented / not compatible |
| N/A | The original server did not support this; our server does implement it |

---

## File Server Function Codes

All 36 standard function codes (0x00–0x23) are implemented, including the
MDFS-extended codes 0x20–0x23.

| Code | Name | L3 | L4 | MDFS | Notes |
|------|------|----|----|------|-------|
| 0x00 | CLI | ✓ | ✓ | ✓ | See CLI commands table below |
| 0x01 | Load | ✓ | ✓ | ✓ | |
| 0x02 | Save | ✓ | ✓ | ✓ | |
| 0x03 | Examine | ~ | ✓ | ✓ | All four forms (binary, long text, name, short text) implemented. L3 had a simpler form. |
| 0x04 | Cat header | ✓ | ✓ | ✓ | |
| 0x05 | Load command | ✓ | ✓ | ✓ | |
| 0x06 | Open | ✓ | ✓ | ✓ | |
| 0x07 | Close | ✓ | ✓ | ✓ | |
| 0x08 | Get byte | ✓ | ✓ | ✓ | |
| 0x09 | Put byte | ✓ | ✓ | ✓ | |
| 0x0A | Get bytes (GBPB) | ✓ | ✓ | ✓ | |
| 0x0B | Put bytes (GBPB) | ✓ | ✓ | ✓ | |
| 0x0C | Get args (OSARGS) | ✓ | ✓ | ✓ | |
| 0x0D | Set args (OSARGS) | ✓ | ✓ | ✓ | |
| 0x0E | Get EOF status | ✓ | ✓ | ✓ | |
| 0x0F | Get discs | ~ | ✓ | ✓ | Returns exactly one disc. See [Multiple discs](#multiple-discs). |
| 0x10 | Get info (sub 1–7) | ~ | ✓ | ✓ | Sub-functions 1 (load), 2 (exec), 3 (size), 4 (access), 5 (all), 6 (dir), 7 (SIN) implemented. Sub-function 8 (read date) not implemented (falls to "Bad INFO argument"). L3 only supported sub 1–5. |
| 0x11 | Set info (sub 1–4) | ~ | ✓ | ✓ | Sub-functions 1 (all), 2 (load), 3 (exec), 4 (access) implemented. L3 only supported sub 1–3. |
| 0x12 | Get user environment | ✓ | ✓ | ✓ | Returns disc name, CSD leaf, LIB leaf. |
| 0x13 | Logoff | ✓ | ✓ | ✓ | |
| 0x14 | Get users online | N/A | ✓ | ✓ | |
| 0x15 | Get user's station | N/A | ✓ | ✓ | |
| 0x16 | Get date/time | N/A | ✓ | ✓ | Real system clock used. |
| 0x17 | Set boot option | ✓ | ✓ | ✓ | |
| 0x18 | Delete | ✓ | ✓ | ✓ | |
| 0x19 | Get version | N/A | ✓ | ✓ | Returns "aunfs_srv \<version\>". |
| 0x1A | Get disc free | ✓ | ✓ | ✓ | Returns config constants, not real filesystem free space. See [Quota and disc accounting](#quota-and-disc-accounting). |
| 0x1B | Create directory (CDIRN) | ✓ | ✓ | ✓ | |
| 0x1C | Rename by handle | N/A | ✓ | ✓ | |
| 0x1D | Create file | N/A | ✓ | ✓ | |
| 0x1E | Get user disc free | N/A | ✓ | ✓ | Returns per-user quota if set, otherwise config default. |
| 0x1F | Set user disc free | N/A | ✓ | ✓ | Sysop only. Stores quota in auth plugin. |
| 0x20 | Who am I | N/A | N/A | ✓ | |
| 0x21 | Users extended | N/A | N/A | ✓ | Paginated user list with priv flag. |
| 0x22 | User info extended | N/A | N/A | ✓ | Returns priv flag and boot option. |
| 0x23 | Copy data | N/A | N/A | ✓ | Server-side block copy between open handles. |

---

## CLI Commands

| Command | L3 | L4 | MDFS | Status | Notes |
|---------|----|----|------|--------|-------|
| `*I AM` | ✓ | ✓ | ✓ | ✓ | Login with optional password |
| `*BYE` / `*LOGOFF` | ✓ | ✓ | ✓ | ✓ | |
| `*CAT` | ✓ | ✓ | ✓ | ✓ | |
| `*INFO` | ✓ | ✓ | ✓ | ✓ | |
| `*LIB` | ✓ | ✓ | ✓ | ✓ | |
| `*DIR` | ✓ | ✓ | ✓ | ✓ | Supports `^` for parent directory |
| `*SDISC` | N/A | ✓ | ✓ | ~ | Succeeds but only one disc is available. See [Multiple discs](#multiple-discs). |
| `*SAVE` | ✓ | ✓ | ✓ | ~ | Returns an error instructing the client to use the SAVE function code instead of the CLI path. This is correct protocol behaviour; real servers behaved the same way. |
| `*LOAD` | ✓ | ✓ | ✓ | ✓ | Streams file to client over the data port |
| `*CDIR` | ✓ | ✓ | ✓ | ✓ | |
| `*DELETE` | ✓ | ✓ | ✓ | ✓ | |
| `*RENAME` | ✓ | ✓ | ✓ | ✓ | |
| `*PASS` | ✓ | ✓ | ✓ | ✓ | User changes their own password |
| `*OPT` | ✓ | ✓ | ✓ | ~ | Only `*OPT 4,n` (boot option) is handled. `*OPT 1` (user messages on/off) and `*OPT 2` (server log verbosity) are not implemented — return a syntax error. |
| `*NEWUSER` | N/A | ✓ | ✓ | ~ | Requires 3–10 uppercase alpha-only characters. MDFS also allowed digits in usernames. |
| `*REMUSER` | N/A | ✓ | ✓ | ✓ | Sysop only |
| `*PRIV` | N/A | ✓ | ✓ | ~ | Only `S` and `U` priv levels. MDFS also had `C` (Communications). |
| `*SETPASS` | N/A | ✓ | ✓ | ✓ | Sysop password reset for any user |
| `*ACCESS` | ✓ | ✓ | ✓ | ✗ | **Not implemented.** Cannot set file access attributes from the CLI. Access byte can be set via the SET_INFO function code (0x11, sub 4) but there is no `*ACCESS` CLI command. |
| `*FREE` | N/A | ✓ | ✓ | ✗ | **Not implemented.** Cannot query disc or user free space from the CLI. |
| `*TYPE` | N/A | ✓ | ✓ | ✓ | Displays file contents as text |
| `*DUMP` | N/A | ✓ | ✓ | ✓ | Hex dump with offset, hex bytes, and ASCII |
| `*COPY` | N/A | ✓ | ✓ | ✓ | Copies a file, preserving load/exec addresses |
| `*PRINTER` | N/A | ✓ | ✓ | ✓ | With no arguments: lists all enabled virtual printers and their descriptions. With a printer name: confirms the printer is available to the calling user, or returns an error (unknown / disabled / not authorised). |
| `*FSOPT` | N/A | ✓ | ✓ | ~ | With no arguments (or `INFO`): displays disc name and online user count. Numeric options are rejected with "Option not supported". Real MDFS `*FSOPT` had many runtime configuration options. |
| `*BACKUP` | N/A | ✓ | ✓ | ~ | Returns "not supported on this server". Real servers backed up disc images. |
| `*COMPACT` | N/A | ✓ | ✓ | ~ | Returns "not supported on this server". Real servers defragmented disc directories. |
| `*VERIFY` | N/A | ✓ | ✓ | ~ | Returns "not supported on this server". Real servers verified disc integrity. |
| `*MAP` | N/A | N/A | ✓ | ~ | Returns "not supported on this server". MDFS showed disc allocation map. |
| `*CHROOT` | N/A | N/A | N/A | ✓ | **Extension.** Restricts a user's visible filesystem to a subtree. Not present on any historical server. |
| `*CHROOTOFF` | N/A | N/A | N/A | ✓ | **Extension.** Removes a chroot restriction. |

---

## File Locking

File-level read/write locking is enforced by the VFS layer (`Vfs::_acquireLock`):

- A **write handle** is exclusive: blocked if any other reader or writer is open on the same path.
- A **read handle** is blocked only if a write handle is already open.
- Multiple simultaneous read handles on the same file are permitted.
- Locks are released on close or on session logout.

This matches the behaviour of L4 and MDFS. L3 had simpler advisory locking.

---

## Quota and Disc Accounting

| Feature | L3 | L4 | MDFS | Status |
|---------|----|----|------|--------|
| Per-user quota stored | N/A | ✓ | ✓ | ✓ — stored in auth plugin |
| Per-user quota reported (`GET_USER_FREE`) | N/A | ✓ | ✓ | ✓ — returns stored quota or `vfs_default_disc_free` |
| Per-user quota updated (`SET_USER_FREE`) | N/A | ✓ | ✓ | ✓ — sysop only |
| Quota enforced on writes | N/A | ✓ | ✓ | ✗ — **not enforced**. Files can be written regardless of quota. |
| Real disc free space | ✓ | ✓ | ✓ | ✗ — `GET_DISC_FREE` returns the `vfs_default_disc_free` and `vfs_default_disc_size` config constants. The actual filesystem free space is not queried. |

The quota is reported accurately to clients and legacy admin tools, but exceeding it does not
prevent writes. This is a known architectural gap; enforcing it would require VFS-layer
knowledge of per-user byte counts, which is not currently tracked.

---

## Multiple Discs

This server supports exactly **one disc**. The disc name is set by `vfs_disc_name` in the
configuration.

| Behaviour | L3 | L4 | MDFS | Status |
|-----------|----|----|------|--------|
| `GET_DISCS` returns disc list | ✓ | ✓ | ✓ | ~ — always returns exactly 1 disc |
| `*SDISC` switches active disc | N/A | ✓ | ✓ | ~ — accepted without error but only one disc exists |
| Multiple physical disc volumes | N/A | ✓ | ✓ | ✗ |

L3 also supported only one disc. L4 could address up to four discs on the host BBC Master.
MDFS supported a larger number of disc volumes and drive configurations.

---

## User Management and Authentication

| Feature | L3 | L4 | MDFS | Status |
|---------|----|----|------|--------|
| Priv level S (Sysop) | ✓ | ✓ | ✓ | ✓ |
| Priv level U (User) | ✓ | ✓ | ✓ | ✓ |
| Priv level C (Communications) | N/A | N/A | ✓ | ✗ — not implemented. MDFS 'C' privilege allowed a station to act as a gateway node with special network routing rights, without full sysop access. |
| Boot option (0–3) per user | ✓ | ✓ | ✓ | ✓ |
| Idle session timeout | N/A | ✓ | ✓ | ✓ — configurable via `security_max_session_idle` |
| Prevent duplicate logins (one session per user) | N/A | N/A | ✓ | ✗ — the same username can be logged in from multiple stations simultaneously |
| Per-station access control (ban a station) | N/A | N/A | ✓ | ✗ — any station can attempt to log in |
| Username constraints | Alpha only | Alpha only | Alphanumeric | ~ — `*NEWUSER` requires 3–10 uppercase alphabetic characters. MDFS permitted digits. |

---

## Directory Cycle Numbers

The `GET_INFO` sub-function 6 (directory info) includes a one-byte **cycle number** that
should increment whenever the directory's contents change. This allows clients to detect
stale directory caches without re-reading the full listing.

This server always returns **0** for the cycle number. Clients that aggressively cache
directory contents and rely on the cycle number to invalidate that cache may not see
updates promptly. In practice, most BBC Micro clients do not cache directory listings
between user operations, so this is rarely observable.

---

## Print Server

This server implements the Econet print server protocol on ports **0x9F** (enquiry) and
**0xD1** (data).

| Feature | L3 | L4 | MDFS | Status |
|---------|----|----|------|--------|
| Responds to printer enquiries | N/A | ✓ | ✓ | ✓ — replies with status 0 (ready) for known enabled printers; status 6 for disabled; status 5 for unauthorised. No reply for unknown printer names. |
| Receives print data | N/A | ✓ | ✓ | ✓ |
| Buffers job in memory during transfer | N/A | ✓ | ✓ | ✓ |
| Saves completed job to spool directory | N/A | ✓ | ✓ | ✓ — path `{print_server_spool_dir}/{printer}/{user}/`. Jobs saved as `HH-MM-SS-DD-MM-YYYY.raw`. |
| Post-processing conversion script | N/A | N/A | N/A | ✓ — **Extension.** Per-printer or global `print_server_conversion_script` run asynchronously via ReactPHP (never blocks the server). |
| Real printer status (online/offline/jammed) | N/A | ✓ | ✓ | ✗ — no actual printer is driven. Status 0 (ready) is always returned for enabled printers. |
| Print queue management | N/A | ✓ | ✓ | ✗ — no commands to hold, release, cancel, or reprioritise queued jobs. |
| `*PRINTER` CLI command | N/A | ✓ | ✓ | ✓ — implemented. With no argument, lists all enabled printers and their descriptions. With a printer name, confirms availability or reports not-found / not-authorised. |
| Multiple named printer queues | N/A | N/A | ✓ | ✓ — `printers.cfg` defines any number of virtual printers; each has its own spool subdirectory and behaviour (spool / script / discard). |
| Per-user print authorisation | N/A | N/A | ✓ | ✓ — each printer has an `allowed_users` list. Empty list means all users permitted; the server returns status 5 during enquiry for unauthorised users. |
| Print usage accounting | N/A | N/A | ✓ | ✗ — no record is kept of how many pages or bytes each user has printed. |
| Admin web UI: active jobs | N/A | N/A | N/A | ✓ — **Extension.** The admin interface shows jobs currently in transfer with the target printer. |
| Admin web UI: spooled files | N/A | N/A | N/A | ✓ — **Extension.** The admin interface shows completed jobs grouped by printer and user, with download links. |

### MDFS print server detail

MDFS included a significantly more capable print server than L4. It supported:

- **Multiple printer queues** — each queue corresponded to a named printer. Clients could
  enumerate available printers and send to a specific one.
- **Queue management CLI** — sysops could hold, release, delete, and reprioritise jobs from
  the server console.
- **Per-user print privilege** — users could be barred from printing via a flag in the user
  record; the server would return status 5 (not authorised) to such users during enquiry.
- **Print quota / accounting** — MDFS logged pages and bytes per user for charge-back purposes.
- **Integration with `*FSOPT`** — certain FSOPT option numbers controlled print server
  behaviour (e.g. printer port selection, spooling on/off).

Multiple printer queues, per-user authorisation, and `*PRINTER` are now implemented.
Print usage accounting and queue management commands (hold, release, cancel) are not.

---

## MDFS-Specific Extensions: Summary

The following MDFS capabilities are not present in L3 or L4 and are also not implemented
in this server:

| MDFS feature | Notes |
|-------------|-------|
| Priv level C | Communications-tier privilege for gateway stations |
| Multiple disc volumes | Real multi-disc support with proper naming |
| Duplicate login prevention | Reject a second login from the same username |
| Station access control | Block named stations from connecting |
| Alphanumeric usernames | Digits permitted in usernames |
| `*OPT 1` / `*OPT 2` | Server message and logging options |
| `*MAP` | Disc allocation map display |
| Full `*FSOPT` option set | Runtime server configuration options |
| `*BACKUP` / `*COMPACT` / `*VERIFY` | Real disc maintenance operations |
| Print accounting | Usage logging per user (pages / bytes per user) |
| Print queue management | Commands to hold, release, cancel, or reprioritise jobs |

Function codes 0x20–0x23 (WHO AM I, USERS EXT, USER INFO EXT, COPY DATA) originated
with MDFS and were not present in the original Acorn L3/Filestore. Our server implements
all four.

---

## Protocol Extensions in This Server

The following features are **not present on any historical server** and are unique to this
implementation:

| Feature | Description |
|---------|-------------|
| `*CHROOT` | Restricts a session's visible filesystem root to a named subdirectory. Useful for isolating guest or service accounts. |
| `*CHROOTOFF` | Removes a chroot restriction (sysop only). |
| Admin web UI | Full HTTP admin interface for user management, service inspection, printer configuration, and print spool browsing. Not accessible over Econet. |
| Multiple VFS plugins | The VFS layer supports stacking multiple storage backends (AFS, ADFS, DFS, S3, image files). Real servers had a single storage backend. |
| Auth plugin system | Authentication is delegated to pluggable backends; the flat-file plugin is closest to historical behaviour. |
