# MaceMail Protocol

MaceMail is an Econet electronic-mail system for the BBC Micro, written
entirely in BBC BASIC (with a handful of tiny 6502 machine-code launcher
utilities). It was published by C.E.T (copyright message "(c) Copyright
C.E.T 1985") and distributes as two DFS disc images: a **Program Disc**
(containing the server, terminal client, and support modules) and a blank
**User Disc** (formatted but empty — used as per-station local storage).

This document describes the wire protocol MaceMail uses between its server
(`SERV`/`SERVN`, run at the mail station) and its terminal client
(`TER`, run at each user's station). It was produced by disassembling the
tokenised BASIC programs on the Program Disc — there is no independent
published specification for this protocol. See
[Provenance and confidence](#provenance-and-confidence) at the end for how
this document was derived and which parts are certain vs inferred.

MaceMail predates AUN. It talks directly to the classic Acorn **NFS/Econet
MOS extension** using the standard `OSWORD &10`/`&11` (TRANSMIT/RECEIVE) and
`OSBYTE &32`/`&33` (poll transmit/poll receive) calls described in the
*Acorn Econet Advanced User Guide* (1983) — the same primitives any
Econet application of the era would use, not a fileserver-specific
mechanism. Unlike most Econet software, though, MaceMail's client
**broadcasts every request** (destination station `&FFFF`) rather than
unicasting to the server's station once discovered; see
[Discovery and the request envelope](#discovery-and-the-request-envelope).

## Transport primitives

MaceMail builds every request/reply on top of two BASIC helper pairs that
are (near-)identically defined in both `SERV` and `TER`:

```basic
DEF FNTX(CB,P,S,Ad1,Ad2)   : REM start a TRANSMIT
  ?CB=&80 : CB?1=P : CB!2=S : CB!4=Ad1 : CB!8=Ad2
  X%=CB MOD256 : Y%=CB DIV256 : A%=&10 : CALL &FFF1
  =?CB

DEF FNRX(CB,P,S,Ad1,Ad2)   : REM open a RECEIVE
  !CB=&7F00 : CB?2=P : CB!3=S : CB!5=Ad1 : CB!9=Ad2
  X%=CB MOD256 : Y%=CB DIV256 : A%=&11 : CALL &FFF1
  =CB?0
```

These match the standard 13-byte Econet control block layout exactly:

**TRANSMIT control block**

| Offset | Size | Field                                    |
|--------|------|-------------------------------------------|
| 0      | 1    | Control byte — `&80` to start; the OS resets this to `0` if the transmission fails to *start* |
| 1      | 1    | Destination port                          |
| 2      | 2    | Destination station (network=hi, station=lo, or `&FFFF` for broadcast) |
| 4      | 4    | Data buffer start address                 |
| 8      | 4    | Data buffer end address                   |

**RECEIVE control block**

| Offset | Size | Field                                    |
|--------|------|-------------------------------------------|
| 0      | 1    | Set to `&00` when opened; the OS writes a block number here that is used later to poll/re-read this block |
| 1      | 1    | Flag — set to `&7F` ("ready") when opened; becomes the sender's TRANSMIT control byte (top bit set) once data has arrived |
| 2      | 1    | Receiving port                            |
| 3      | 2    | Station to accept from (`0` = accept from any station) |
| 5      | 4    | Data buffer start address                 |
| 9      | 4    | Data buffer end address (rewritten by the OS on completion to reflect the *actual* end of the data received) |

Polling uses `OSBYTE &32` (poll transmit — pass nothing, get the transmit
result byte back in X) and `OSBYTE &33` (poll receive — pass the RECEIVE
block number in X, get its status byte back in X). Both `SERV` and `TER`
wrap these as `FNPlTX`/`FNPTX` and `FNPlRX`/`FNPRX`, retrying up to 10 times.
A result byte `<128` means "finished" (`0` = success, `&40`–`&44` = the
standard Econet error codes — network jammed, packet damaged, receiver not
listening, not plugged in, malformed block); `≥128` on a RECEIVE poll means
data has arrived. After a receive completes, both programs call
`OSWORD &11` a second time (`PROCREADRX`/`PROCRRX`) to finalise/read back the
control block, which is when the sender's station number becomes readable
at offset 3–4.

Nearly every reply cycle in both programs follows the same shape:

```basic
REPEAT REPEAT
UNTIL FNTX(TCB, port, station, buffer_start, buffer_end)
UNTIL FNgone          : REM FNgone polls FNPlTX and also times out after WF centiseconds
```

i.e. "keep re-issuing the TRANSMIT until it actually starts, then poll until
it completes or the overall operation times out" (`WF` = 500 centiseconds
server-side, 1200 client-side).

## Ports

Each logical exchange gets its own dedicated port; MaceMail does not
multiplex operations behind a single port/subfunction the way the file
server protocol does (with one exception — the initial "what do you want to
do" request, see below).

| Port    | Direction         | Purpose                                                        |
|---------|-------------------|------------------------------------------------------------------|
| `0x19`  | Client → server   | Quick-command request envelope (see below) — broadcast          |
| `0x1A`  | Server → client   | Quick-command acknowledgement ("MACE" + user-group ID)           |
| `0x1B`  | Client → server   | Logon credentials (password + user number)                       |
| `0x1C`  | Server → client   | Logon reply (user's name, mailbox/store state, dates)            |
| `0x1E`  | Client → server   | Change-password payload                                          |
| `0x1F`  | Server → client   | Directory listing reply (all users, or logged-on users only)     |
| `0x21`  | Server → client   | "Recall store" reply (a previously saved file)                   |
| `0x23`  | Client → server   | "Save store" payload (save a file to one of the user's 8 store slots) |
| `0x25`  | Server → client   | Mail-check reply (unread/read, normal/express counts)             |
| `0x26`  | Client → server   | Post/send mail payload                                           |
| `0x27`  | Server → client   | Mail list reply (new or old, selected by the request code)        |
| `0x28`  | Server → client   | Individual mail item reply (full message + header)               |
| `0x29`  | Server → client   | Mailbox-scan reply (per-message status bitmap, used by "Look")    |
| `0x2A`  | Client → server   | "Look" — which message indices to summarise                      |
| `0x2B`  | Server → client   | "Look" reply — summaries for the requested indices                |
| `0x31`  | Client ↔ client   | Peer-to-peer chat message text (direct, not routed through the server) |
| `0x40`  | Server → client   | Asynchronous notifications: chat invite, system broadcast messages, forced logoff, new-mail alert, network-number reply |

Ports `0x90`–`0x9F`, `0xD0`, and `0xD1` are reserved by convention for other
Econet services (printer/file server); MaceMail correctly avoids that range.

## Discovery and the request envelope

At startup the client asks the user to type a 4-digit "number" (compared
against a hard-coded `AN$="6361"` in `TER`) before it will attempt to
connect — a "dial the mail exchange" UI metaphor rather than an
enforced protocol field (`SERV` never checks it). Connecting, and every
subsequent quick-command, use the *same* function (`FNCO`/`PROCW1` on the
client), which always **broadcasts** to `&FFFF` on port `0x19`:

```basic
DEF FNCO(N,C)
  RBN = FNRX(RCB, &1A, 0, BF, 10)        : REM listen for the server's ack first
  BCB?0 = &80 : BCB?1 = &19 : BCB!2 = &FFFF
  $(BCB+4) = "MAI" + CHR$T%              : REM literal "MAI" + dial code
  BCB?9 = N : BCB?10 = U% : BCB!11 = C
  X% = BCB MOD256 : Y% = BCB DIV256 : A% = &10
  REPEAT
    CALL &FFF1                            : REM re-broadcast every iteration
    X = FNPRX(RBN)
  UNTIL X > 127 OR timed_out
  PROCRRX(RCB)
  = X > 127
```

The 8-byte payload that lands in the server's receive buffer is:

```
Offset  Size  Field
------  ----  -----
0..2    3     Literal ASCII "MAI"
3       1     Dial code (client's AN$ digit; not enforced by this build)
4       1     (unused/uninitialised)
5       1     Operation code (see table below) — "C%" in SERV, "N" in TER
6       1     Requesting user's logged-on user number
7       1     Secondary parameter — meaning depends on the operation ("J%" in SERV, "C" in TER)
```

`SERV` listens for this on port `0x19` with station filter `0` (accept from
anyone) into an 11-byte buffer `BF`; it copies bytes 4–7 of that arrival
into its own 13-byte receive control block at offsets 9–12 as a sanity/copy
step (only when the first 4 bytes of the buffer are non-zero), from which
`RCB?10` and `RCB?12` are then read as the operation code and secondary
parameter respectively — the net effect for the wire protocol is exactly
the byte layout above.

Every quick command gets an immediate reply on port `0x1A`, *before* the
server dispatches to the specific handler — an 11-byte packet containing
the literal ASCII string `"MACE"` at offset 0 and the 4-character
User Group identifier at offset 5. This lets the client both confirm the
server is present and learn its station number (from the RECEIVE control
block's sender field) for anything it needs to reply to directly (e.g. the
chat sub-protocol).

Because every quick command is broadcast rather than unicast, `SERV` relies
entirely on the port number for routing and makes no attempt to filter by
dial code — the build examined has `Only_mail_server_on_the_net = TRUE`
hard-coded, i.e. it assumes it is the only such service on the network.

### Server extension: unicast quick commands

This project's server implementation additionally accepts the quick-command
envelope (port `0x19`) addressed directly to its own station, not just
broadcast to `&FFFF` — for clients that already know the server's
network/station and would rather not broadcast discovery traffic on every
command. This is purely additive: it changes nothing about how an
unmodified, broadcast-only `TER` is served, since replies were always sent
back to the requester's own station either way (a MaceMail reply is never
itself broadcast — see `MaceMailReply`/`Reply::buildEconetpacket()`, which
always addresses the reply to `getSourceStation()`/`getSourceNetwork()` of
whichever packet triggered it). Every operation reachable via a broadcast
quick command is handled identically when the same packet instead arrives
by unicast.

## Operation codes

The operation code (offset 5 of the quick-command payload) selects the
handler. Most operations have a dedicated request and/or reply port that is
opened by the specific handler *after* the quick-command/ack exchange
above; a few (logoff, "who am I", set-availability, delete-store) need no
further data phase at all.

| Code | Name (server PROC)     | Client trigger      | Request port | Reply port | Secondary parameter |
|------|-------------------------|----------------------|---------------|------------|----------------------|
| 0    | *(no dedicated handler)* | Sent once after posting mail | — | — | unused — appears to be a no-op/keepalive |
| 1    | `PROCLOGON`             | Sign-on               | `0x1B`        | `0x1C`     | unused |
| 2    | `PROCLOGOFF`            | Bye                   | —             | —          | unused |
| 3    | `PROCPC` (change password) | Password menu      | `0x1E`        | —          | unused |
| 4    | `PROCDRY(0)` — directory of all registered users | Directory | — | `0x1F` | unused |
| 5    | "Who request"           | Who am I              | —             | —          | unused (server just logs the request; the client already has this info locally) |
| 6    | `PROCSAVEMl` — post/send mail | Send              | `0x26`        | —          | unused |
| 7    | `PROCGETSt` — recall a stored file | Recall store | — | `0x21`     | store slot (0–7) |
| 8    | `PROCSAVESt` — save to a store slot | Store a file | `0x23` | — | store slot (0–7) |
| 9    | `PROCDELSt` — clear store slot(s) | Delete store  | —             | —          | new 8-bit store-usage bitmask |
| 10   | `PROCMlCk` — mail check | Periodic / on demand  | —             | `0x25`     | unused |
| 11   | `PROCDRY(1)` — directory of logged-on users only | On-line | — | `0x1F` | unused |
| 12   | `PROCGETMl(FALSE)` — new mail list | Read → New   | —             | `0x27`     | unused |
| 13   | `PROCGETMl(TRUE)` — old mail list | Read → Old    | —             | `0x27`     | unused |
| 14   | `PROCINDMl` — read one mail item | Reading a message | — | `0x28`   | message index |
| 15   | `PROCch` — chat invite  | Chat                  | —             | *(via `0x40`)* | target user number |
| 16   | `PROCDELMl` — delete a mail item | Delete       | —             | —          | message index |
| 17   | `PROCUMI` — mailbox scan | "Look" (entry)        | —             | `0x29`     | unused |
| 18   | `PROCLOOK` — batch summaries | "Look" (continued) | `0x2A`      | `0x2B`     | batch size |
| 19   | *(internal — notifies another logged-on user, contact type 255, target resolved by station lookup)* | not conclusively identified in `TER` | — | *(via `0x40`)* | station low byte to resolve |
| 20   | Set availability/keepalive flag (`USER?UN = &7E` or `&7F`) | Sent continuously in the client's idle loop | — | — | `1` = available, `0` = busy/in a chat |
| 21   | `PROCNETNO` — query network number | Error recovery (used to check for a bridged network when a transmission keeps failing) | — | *(via `0x40`)* | unused |

Codes 15 and 21's replies arrive asynchronously on the shared notification
port `0x40` rather than a dedicated reply port (see below); code 15's
outcome is additionally signalled to the client through a well-known
memory location (`?&70`) that the client polls after sending the request —
this location is reused for an unrelated purpose (auto-detecting disc vs.
network storage) elsewhere in `SERV`, so treat it as a private
implementation detail rather than part of the wire protocol.

`PROCUMI` (code 17) and `PROCLOOK` (code 18) are both present and fully
implemented in `SERV` — the mailbox-scan/Look byte layouts below were read
directly from that code, not inferred. However, neither the literal hex
port numbers (`&29`/`&2A`/`&2B`) nor operation codes `17`/`18` appear
anywhere in `TER.bas`: the shipped terminal client never actually triggers
this path. It may be exercised by a different/older client not included in
this distribution, or simply be unused/vestigial. This project's server
implementation (`MaceMail::handleMailboxScanOp()`/`handleLookRequest()`)
follows the byte layout below faithfully, but — since no client is known to
call it — it has not been validated against any real MaceMail client.

## Selected payload layouts

These are the request/reply structures that could be determined with
reasonable confidence from the BASIC source. Byte offsets are relative to
the start of the data buffer passed to `FNTX`/`FNRX` for that exchange.

### Logon (`0x1B` request / `0x1C` reply)

Request (6 bytes, port `0x1B`):
```
0..4   Password (space-padded string, ≤4 chars significant)
5      User number (0–31)
```

The server compares the submitted password against the stored record for
that user number, checks the caller's station matches the one already
recorded for that user (or logs them in fresh), and replies on `0x1C` with
the user's display name, registration counts, and dates — read by the
client as (offsets into the reply buffer):
```
0      Name string (null/space terminated, up to 27 chars)
27     Number of registered users (NRU)
28     8-bit store-slot-usage bitmask
29..31 24-bit "disc created" date (day/month/year packed)
32..34 24-bit "last used" date
41..44 Unread/read × normal/express mail counts
```

A single leading status byte `< 128` on reply signals a logon error
(distinguished by value — `&FE`/`&FD`/`&FC` correspond to unknown user,
station mismatch, and wrong password respectively in `SERV`'s logic) rather
than the fields above being present.

### Post/send mail (`0x26` request, 490 bytes)

The request buffer mirrors the server's internal per-message record and
includes, among other fields: recipient list(s) and per-recipient flags
(acknowledgement-requested / reply-requested / express, packed as bit flags
per recipient slot), the message text, and a 29-byte header block that gets
copied verbatim into the recipients' mailbox index entries. The server
fans this out to up to `NRU` (max 32) recipients' individual mailbox
records and, for each recipient who is currently logged on, immediately
sends them an asynchronous "you have mail" notification (operation-style
code `7`) on port `0x40`.

### Individual mail item (`0x28` reply, 768 bytes)

Contains the 29-byte header (sender, addressing flags, date) followed by
the message body (up to 418 bytes) and, for messages carrying
acknowledgement/reply flags, control bytes the client uses to decide
whether to prompt "Reply required" or auto-generate an acknowledgement.

### Async notification (`0x40`, 4 bytes)

Every unsolicited server→client push on the shared notification port —
chat invites, new-mail alerts, system broadcasts, forced logoff, the
network-number reply — is built by the same `PROCCONT(C,M,E1,E2)` helper
and is always exactly 4 bytes:

```
Offset  Size  Field
------  ----  -----
0       1     Type/notification-type byte (see the type list under Chat sub-protocol)
1       1     E1 — meaning depends on the type (e.g. caller's user number for a chat invite; unused/0 for new-mail)
2       1     E2 — meaning depends on the type (e.g. caller's own station number for a chat invite; unused/0 for new-mail)
3       1     Unused (PROCCONT never sets this byte itself)
```

### Store slots (`0x21` reply / `0x23` request, 440 bytes each way)

Each user has 8 general-purpose store slots (`PROCGETSt`/`PROCSAVESt`,
secondary parameter 0–7 selects the slot). The server never interprets a
slot's contents — it reads/writes exactly 440 bytes verbatim to/from its
own per-user sector storage. Save is a two-step exchange: the quick command
(code 8) tells the server which slot to expect data for, then the client
separately opens a transmission on `0x23`; the server has to remember which
slot that follow-up transmission is for using the requesting station's
identity, since the `0x23` payload itself carries no slot number. Delete
(code 9) takes a full replacement 8-bit store-usage bitmask as its
secondary parameter rather than a single slot index — `PROCDELSt` just
overwrites the whole mask (`?(...+28)=GI`), so the client is expected to
have already computed the new mask locally (e.g. from the bitmask returned
in the logon reply) before clearing a slot; the underlying slot data itself
is left untouched by a delete, only its "in use" bit changes.

### Directory (`0x1F` reply)

A leading count byte followed by 30-byte records (29-byte CR-terminated,
space-padded name field + 1-byte user/slot number).

### Mailbox scan / Look (`0x29` reply, `0x2A` request / `0x2B` reply)

`PROCUMI` (mailbox scan, code 17) replies on `0x29` with a raw 512-byte
dump of the user's own per-message-slot sector storage (two 256-byte
sectors read from `UN*2+45`/`+46`) — effectively an opaque, per-slot status
table read straight off disk.

`PROCLOOK` (code 18) is the two-phase follow-up: the client sends a 6-byte
request on `0x2A` (up to 6 message ids, one byte each) and gets back a
fixed 256-byte reply on `0x2B` containing up to six 35-byte summary
records, one per requested id, concatenated with no count/header byte. Each
record is built by locating the message's on-disk record via
`sector = id DIV 7 + 10`, `offset = (id MOD 7) * 35` and copying 35 bytes
verbatim — the same 35-byte-record/7-per-sector addressing scheme
`PROCINDMl` uses to place the sender-slot byte in the `0x28` individual
mail item reply (see above; confirming that reply's offset math was
derived correctly). The internal record's own field layout past the first
few bytes was not traced further (see
[Provenance and confidence](#provenance-and-confidence)); this project's
`0x2B` reply lays out id, sender slot, a locally-defined status-flags byte,
date, and subject rather than replicating the original's exact internal
record fields, which were never fully determined.

## Chat sub-protocol

Chat is peer-to-peer once set up — the server only acts as an introducer:

1. Client A sends operation `15` with the target user number as the
   secondary parameter. The server (`PROCch`) looks up target user B's
   station number, and sends B an asynchronous contact notification (type
   `1`) on port `0x40` carrying A's station/user number.
2. Client B's background poll (`FNCkt`, checked inside its main input
   loop) picks up the notification, prompts "Chat call from user
   `UG$NN` — Chat to this user? Y/N", and if accepted opens a RECEIVE
   directly from A's station on port `0x31`.
3. Both sides then exchange line-based chat text directly on port `0x31`,
   station-to-station, with no further server involvement. A `0xFF` first
   byte on a chat packet signals "hang up".

System-wide broadcasts (closing-down warnings, "all users log off",
forced logoff, etc., issued from the server's system-manager menu) reuse
the same port-`0x40` contact channel, addressed individually to every
currently logged-on user's recorded station, with a notification-type byte
distinguishing the message (types `1`–`11` seen in use, decoded by the
client's `PROCRC` dispatcher: `1`=chat invite, `2`–`5`/`11`=canned system
messages, `6`=clear system message, `7`=new mail arrived, `10`=forced
logoff).

## User and mail database storage

**MaceMail does not rely on shared storage between the server and the
client.** The server is the sole owner of the user/mail/store database;
the client never opens it, locally or over the network — everything it
needs (mail contents, directory listings, mailbox status, store files)
arrives exclusively through the port-based request/reply exchanges
described above.

`SERV` stores its user records, mailboxes, and message store in
fixed-size records addressed by "sector" number (`PROCDS`, read/write).
It supports two interchangeable backing stores *for its own use*,
auto-detected at startup (`PROCNOSS`/`PROCCkDISK`):

- **Local disc** — direct DFS sector read/write via `OSWORD &7F`, from a
  disc in the mail station's own drive.
- **Network** — the record is instead fetched/stored via a remote Econet
  fileserver using the older Level 2/3 filestore protocol (`CALL &FFD1`
  with function `1`/`3` for read/write). Even here it is only the
  *server* that talks to that fileserver — the terminal client is never a
  party to this exchange, and doesn't need network access to that
  fileserver at all.

This split is visible in the Program Disc's own directory layout: the two
directories that carry a server copy of the software — `S` (`SERVN`) and
`D` (`SERV`, a self-contained single-station demo bundle) — each have
their own copy of the `USERS` database file; the terminal client's
directory, `M` (`TER`), has no `USERS` file at all. A terminal station
needs nothing beyond the client program itself (and the screen modules it
`CHAIN`s to, e.g. `MENUT`) to operate — it carries no copy of, and has no
access path to, the mail database.

`TER` does have its own, unrelated, purely local file feature —
`PROCLf`/`PROCSf` let a user save or load a message *draft* to/from
whichever filing system is currently selected at their own station. That
is private local storage for the user's convenience; it is never read by
the server and plays no part in the wire protocol above.

## Provenance and confidence

This document was produced by:

1. Downloading `MaceMail.zip` from flaxcottage.com, which contains two DFS
   disc images (`Mace Mail Program Disc.SSD`, `Mace Mail User Disc.SSD`).
2. Reading the DFS catalogues and extracting every file using this
   project's own `HomeLan\Retro\Acorn\Disk\DfsReader` (already vendored
   here for the `DfsSsd` VFS plugin).
3. Detokenising the tokenised BBC BASIC II programs (`SERV`, `SERVN`,
   `TER`, and supporting modules) with a byte-table cross-checked against
   TobyLobster's `basic_tokens` reference detokenizer.
4. Cross-referencing the resulting source between the server (`SERV.bas`)
   and terminal client (`TER.bas`) — every port and operation code in the
   tables above was confirmed from *both* sides independently, not read
   off one side alone.
5. Verifying the underlying Econet `OSWORD`/`OSBYTE` control-block layout
   against the original *Acorn Econet Advanced User Guide* (1983).

Confidence is high for: the transport mechanism, the full port list, the
operation-code dispatch table, and the request-envelope layout — these
were all cross-validated between client and server. Confidence is lower
(explicitly marked above) for: the exact meaning/trigger of operation code
`19`, and the precise byte-for-byte layout of the larger record-array
replies (directory/mailbox-scan/look/mail-item), which are described
structurally rather than as a definitive byte table, since fully
reconstructing them would require tracing the record layouts against
`FORM`/`FORMN` (the registration/mail-composition screen modules) and the
`USERS` binary database file in more depth than was done here. The
discovery/broadcast envelope's exact interaction with the underlying MOS
driver (specifically, why the payload bytes are read directly out of the
control block itself rather than a separately pointed-to buffer) is
reported as observed from the BASIC source rather than fully explained at
the OS level.

The `SEND`, `SWIPE`, and `R` files on the Program Disc are small 6502
machine-code utilities, not BASIC — `SEND`/`R`'s companion `BMAIL.bas`
shows they are simple keyboard-buffer-injection launchers (e.g. auto-typing
`MODE7` and `CHAIN "$.MAIL.TER"`) rather than protocol code, and were not
disassembled further.
