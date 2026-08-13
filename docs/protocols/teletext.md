# Teletext Server Protocol

This document describes the Econet teletext server protocol — the wire
protocol spoken by vintage Prestel/Ceefax-style teletext client programs
(e.g. the BBC Micro `TELETEXT` program) to a teletext server station. It is
sometimes referred to as "TSERV" after the server program name.

Unlike a hardware teletext receiver (which decodes pages continuously off
an incoming broadcast signal and caches whichever ones happen to have been
seen recently), this project's implementation always serves pages from a
local directory of pre-saved page files — the same approach used by the
teletext server built into the [PiEconetBridge](https://github.com/cr12925/PiEconetBridge)
project, whose author describes it as talking "the same protocol as the
'proper' server software that receives pages with the cheese wedge", just
backed by "a bunch of binary page data dumps instantaneously available in a
directory" rather than a real receiver. See
[This project's implementation](#this-projects-implementation) below for
how that maps onto the wire protocol, [Teefax import](#teefax-import) for
how one channel can be kept automatically populated from a real teletext
archive, and [Provenance and confidence](#provenance-and-confidence) for
sourcing.

## Ports

| Port  | Direction       | Purpose                                             |
|-------|-----------------|------------------------------------------------------|
| 0xB0  | Client → Server | Discovery broadcast ("find a teletext server")       |
| 0xB1  | Server → Client | Discovery reply                                       |
| 0xB2  | Server → Client | Replies to operation requests                         |
| 0xB3  | Client → Server | Operation requests (all of the &80–&8B operations)    |
| 0xB4  | Server → Client | Page data transmission                                |
| 0xB5  | Server → Client | Continuous "currently displayed page" broadcast       |

Ports 0xB2–0xB5 are described in the source document as "four consecutive
ports (base through base+3)", with 0xB2 as the base — the discovery reply
(see below) reports this base port explicitly, in case an installation
ever runs on a different block. Discovery itself always happens on the
fixed pair 0xB0/0xB1, outside that block.

Every request/reply payload described below is prefixed with a **control
byte** — this is the first byte of the Econet packet's *data* payload, not
the underlying Econet frame's own control-byte field. For an operation
request this control byte carries the operation code (0x80–0x8B); for a
reply it carries a status/error code (0 = success).

## Discovery (ports 0xB0/0xB1)

A client wanting to find a teletext server broadcasts on port 0xB0:

```
Offset  Size  Field
------  ----  -----
0       1     Control byte, 0x80
1       8     Server type filter: ASCII "TELETEXT", or 8 spaces to match any server
```

Any matching server replies (unicast, to the broadcaster's station) on port
0xB1:

```
Offset  Size  Field
------  ----  -----
0       1     Error number, or 0 for success
1       1     Base port number (see Ports above — normally 0xB2)
2       1     Binary version number
3       8     Server type (ASCII, e.g. "TELETEXT")
11      1     Server name length, N
12      N     Server name (ASCII, optional — present only if N > 0)
```

The whole reply is at most 0x20 (32) bytes.

## Operation requests (port 0xB3 → port 0xB2 reply)

Every operation below is sent by the client to port 0xB3, addressed to the
server station discovered above; the server's reply always comes back on
port 0xB2, addressed to the requesting station. A reply's first byte is
always the status: 0 for success, or one of the [error codes](#error-codes)
below, in which case any bytes after it are a CR-terminated human-readable
error message rather than the operation-specific reply data shown here.

| Op    | Name                    | Request payload (after the control byte)          | Reply payload (after the status byte) |
|-------|-------------------------|-----------------------------------------------------|----------------------------------------|
| 0x80  | Read version            | *(none)*                                             | Version string, CR-terminated |
| 0x81  | Issue page request      | Channel (1 ASCII char), page number (3 ASCII chars), subpage (4 ASCII chars, optional) | Queue position (1 byte) |
| 0x82  | Cancel request          | *(none)*                                             | *(none)* |
| 0x83  | Read max users          | *(none)*                                             | Max users per channel (1 byte) |
| 0x84  | Read date/time          | *(none)*                                             | `"HH:MM:SSDD/MM/YYYY"` (no separator between time and date) |
| 0x85  | Logoff                  | *(none)*                                             | *(none)* |
| 0x86  | Page request with delay | Same as 0x81                                         | Same as 0x81 |
| 0x87  | Read port data          | Port number (1 byte, 0–255)                          | Port value (2 bytes) |
| 0x88  | View screen             | *(none)*                                             | *(none)* |
| 0x89  | Disc page               | Same as 0x81                                         | Same as 0x81 |
| 0x8A  | Toggle service on/off   | *(none)*                                             | Bit 0 of 1 byte: 0 = suspended, 1 = active |
| 0x8B  | Toggle header           | *(none)*                                             | Bit 0 of 1 byte: 0 = off, 1 = on |

A page request (0x81/0x86/0x89) that succeeds is followed shortly
afterwards by the actual page data arriving separately on port 0xB4 (see
below) — the 0x81/0x86/0x89 reply itself only confirms the request was
accepted and reports a queue position, it does not carry page data.

## Page data (port 0xB4)

Sent by the server once a requested page is ready, unicast to whichever
station asked for it:

```
Offset   Size  Field
-------  ----  -----
0        1     Control byte: 0x80 (success) or 0x81 (error / "timed out")
1        1024  Page data — a raw 1K MODE 7 screen dump (as produced by `*SAVE` of Mode 7 screen memory)
```

Two bytes inside that 1024-byte block are reserved for the subpage number,
BCD-encoded:

```
Offset (within the 1024-byte block)  Field
------------------------------------  -----
0x3FE                                  Subpage number, high byte (BCD)
0x3FF                                  Subpage number, low byte (BCD)
```

## Current page broadcast (port 0xB5)

The server continuously broadcasts a 4-byte packet announcing whatever page
it is currently displaying/cycling through (e.g. for a client watching a
carousel without having explicitly requested a specific page):

```
Offset  Size  Field
------  ----  -----
0       1     Channel (ASCII)
1       3     Page number (ASCII)
```

## Error codes

| Code | Meaning                        |
|------|----------------------------------|
| 1    | Bad page number                 |
| 2    | Bad channel                     |
| 3    | Channel busy                    |
| 4    | Time unavailable                |
| 5    | Bad port                        |
| 6    | Not found                       |
| 7    | Service suspended                |
| 8    | Insufficient privilege           |
| 9    | Not supported                   |
| 10   | Unknown function                |
| 11   | Teletext server error            |
| 12   | Who?                            |

## This project's implementation

The upstream document describes a server built around a live teletext
receiver: pages are fetched from an incoming broadcast into a cache, hence
the queue position on a page request, and the distinction between
originally-broadcast pages and 0x89 ("disc") pages saved from disc earlier.
This project has no receiver — like PiEconetBridge's teletext server, every
page always comes from local storage, so:

- 0x81, 0x86, and 0x89 (page request, page-request-with-delay, and disc
  page) are all handled identically: a synchronous lookup against the page
  store, always reporting queue position 0 (the "queue" is never actually
  backed up), immediately followed by the 0xB4 transmission.
- 0x82 (cancel) is a no-op that always reports success, since there is
  never an in-flight page fetch to cancel.
- 0x87 (read port data) reports error 9 ("Not supported") — it describes
  reading status from a physical tuner/decoder "port" on real receiver
  hardware, which has no equivalent here.
- 0x88 (view screen) is a no-op that always reports success — it describes
  switching a local physical monitor between the server's live-off-air feed
  and other input on real hardware, neither of which applies here.
- 0x8A (toggle service) and 0x8B (toggle header) are implemented as simple
  in-memory flags reported back on subsequent 0x8A/0x8B calls; toggling the
  service off does not stop the underlying `ProviderInterface` from running
  (use the admin web front end to actually disable the service), it only
  changes what 0x8A reports.
- Storage layout: one directory per channel, one 1024-byte file per page
  inside it. The plain `{page}.dat` file is always subpage 1 (the default
  used when a request omits the subpage field, or sends all-zeroes);
  additional subpages live alongside it as `{page}_{N}.dat` (e.g. `100.dat`
  is page 100 subpage 1, `100_2.dat` is subpage 2). A page with only one
  subpage — the overwhelming majority — needs no `_N` file at all, so this
  is purely additive over the original "one file per page" layout rather
  than a breaking change to it. Unlike PiEconetBridge's own teletext server
  (which the forum thread cited in
  [Provenance and confidence](#provenance-and-confidence) describes as
  unable to dial a specific subpage), this server does parse the request's
  optional subpage field and serves the matching file, reporting whichever
  subpage it actually served back in the BCD bytes at offset 0x3FE/0x3FF —
  requesting a subpage that doesn't exist gets the same error 6 ("not
  found") as requesting a page that doesn't exist. See
  `docs/service-providers.md` for the full storage contract.
- Page numbers: the magazine digit (the first of the three) must be plain
  decimal 1-8, but the other two digits also accept hex (A-F) — some page
  sources use the extra numbering capacity that gives (the "TFSHEX"
  convention mentioned in the PiEconetBridge forum thread cited below is
  exactly this). Requests are case-insensitive; page numbers are always
  normalised to uppercase before being looked up or reported back (e.g. in
  the current-page broadcast), matching the `.dat` filenames on disk.

## Teefax import

The page store described above is normally populated by hand, but the
server can also keep one channel automatically stocked from
[Teefax](https://magazine.raspberrypi.com/articles/teefax), a
fan-maintained teletext archive, entirely through its own housekeeping
mechanism — no separate cron job or scheduler needed.

**How it fetches Teefax.** The original `teastop.plus.com` SVN server isn't
reliably reachable, so this imports from its GitHub mirror,
[`opless/teefax-mirror`](https://github.com/opless/teefax-mirror), via a
single non-blocking download of GitHub's tarball endpoint (no `git`/`svn`
binary needed) — see `teletext_teefax_source` below to point at a
different source.

**Format conversion.** Teefax pages are `.tti` files (MRG Systems'
Teletext Text Interchange format, used by most modern teletext editing and
broadcast tools, e.g. [VBIT2](https://github.com/peterkvt80/vbit2/wiki/Page-files))
— plain text, not the raw 1024-byte Mode 7 dumps this server stores.
`TeefaxTtiParser` converts each one: `PN` lines give the magazine/page/
subpage number (page digits are hex, which is where this server's hex
page-number support above comes from), `OL` lines give each screen row's
40 characters (reversing whichever of the three ways teletext control
codes 0x00-0x1F can be embedded in the text file — literal, with bit 7
set, or as a two-byte viewdata-style escape), and every other tag (`DE`,
`CT`, `SC`, `PS`, `FL`, `RE`, `PF`, ...) is metadata that doesn't affect
the raw screen buffer and is ignored. A single `.tti` file can contain a
whole subpage carousel (repeated `PN` lines), which becomes several pages
in the store.

**How the download runs in the background.** `Teletext::registerService()`
registers a housekeeping task (the same generic mechanism every provider
uses for periodic work) that runs `checkTeefaxRefresh()` on every tick: if
a channel is configured and its last import (recorded in that channel's
own `.imported` marker file, written by the importer itself) is older than
`teletext_teefax_refresh_interval`, it spawns
[`src/util/teefax-import`](../../src/util/teefax-import) as a detached
background OS process — the same `React\ChildProcess\Process` mechanism
`BeebTerm` already uses for its own sessions — so the download and
`.tti` conversion, which can take a while, never blocks the event loop or
any in-flight Econet traffic. A simple "already running" guard stops a
slow import from being started twice in a row. The admin web front end's
"Refresh Teefax Now" button (on the Teletext service page) triggers the
same background process on demand, bypassing the due-time check.

**Installing what it downloads.** The importer writes into a staging
directory first and only `rename()`s it over the live channel directory
once every page has been converted — an atomic swap, so the running
server only ever sees either the complete old channel or the complete new
one, never a half-imported mix.

**Configuration:**

| Key | Default | Purpose |
|---|---|---|
| `teletext_teefax_channel` | *(empty)* | Which channel (0-9) to keep populated. Empty disables the feature entirely — nothing is downloaded unless this is set. |
| `teletext_teefax_source` | the GitHub mirror's tarball URL | Where to download from. |
| `teletext_teefax_refresh_interval` | `86400` (1 day) | Minimum seconds between automatic refreshes. |

Once a channel is configured, the server makes outbound HTTPS requests to
GitHub on this schedule — worth knowing before enabling it, since this is
the one thing in this provider that reaches out to a third party on its
own.

Run `src/util/teefax-import --help` to use the importer directly (e.g.
`--dry-run` to see what a refresh would do without writing anything).

## Provenance and confidence

This document is derived from the protocol specification published at
[mdfs.net/Docs/Comp/Econet/Protocols/Teletext](https://mdfs.net/Docs/Comp/Econet/Protocols/Teletext),
cross-referenced against public discussion of the PiEconetBridge project's
teletext server implementation on the
[stardot.org.uk forum](https://www.stardot.org.uk/forums/viewtopic.php?t=31973)
for how a filesystem-backed server (as opposed to a live receiver) maps
onto the same wire protocol. The port allocation, operation code table, and
page/discovery payload layouts are taken directly from the source document.

Two things are worth flagging explicitly:

- The source document's own port section is internally a little
  inconsistent — it describes "four consecutive ports (base through
  base+3)" for 0xB2–0xB5, but separately lists 0xB0/0xB1 for discovery,
  i.e. six port numbers in total rather than four. This document resolves
  that the way the source text's individual port descriptions do: 0xB0/0xB1
  fixed for discovery, 0xB2–0xB5 as the "four consecutive" operational
  block.
- Operation 0x87 ("read port data")'s exact real-world meaning is not
  spelled out beyond its byte layout — inferred here to be reading status
  from a hardware receiver's tuner input, based on how equivalent teletext
  reception systems of the era are documented elsewhere, hence this
  project reporting it as unsupported rather than guessing at a value.

This document does not attempt to specify the on-screen Mode 7/teletext
character-and-control-code encoding of the 1024-byte page data itself —
this server treats a page's contents as an opaque blob it stores and
serves unmodified, the same way PiEconetBridge does. The one place this
project *does* need to understand that encoding is converting Teefax's
`.tti` files back into it (see [Teefax import](#teefax-import) above); that
format is documented at [zxnet.co.uk's TTI format PDF](https://zxnet.co.uk/teletext/documents/ttiformat.pdf)
and the [VBIT2 wiki](https://github.com/peterkvt80/vbit2/wiki/Page-files),
and Teefax's own content comes from the
[`opless/teefax-mirror`](https://github.com/opless/teefax-mirror) GitHub
mirror of the original `teastop.plus.com` SVN repository.
