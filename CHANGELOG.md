# Changelog

All notable changes to this project are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project loosely follows [Semantic Versioning](https://semver.org/).

This file was reconstructed from the existing git history. Going forward,
every change of note should be added under **[Unreleased]** as part of the
commit/PR that makes it, using the `Added` / `Changed` / `Fixed` / `Removed`
sections below. When a release is tagged, rename `[Unreleased]` to the new
version and date, and start a fresh `[Unreleased]` section above it.

## [Unreleased]

Work committed to `master` since the `2.0.1` tag.

### Added
- Piconet encapsulation (serial-port based Econet interface), with its own
  send queue, handshake retries and ack events.
- Remote bridge protocol for linking separate `aun-filestored` instances
  together and bridging Econet networks over IP (Piconet wired up first;
  WebSocket and AUN to follow).
- Short-lived outbound packet buffering for the remote bridge: a brief
  disconnect/reconnect (e.g. the remote end restarting) now delays
  in-flight packets for up to 5 seconds instead of dropping them, discarding
  anything that goes stale before the connection comes back.
- Remote bridge protocol version 1.1: a new `ACK <net> <stn>` message relays
  a real Econet-level acknowledgement back across the bridge connection that
  originally carried the request, so `FileServer`'s block-by-block transfer
  chain (see the `Aun\Handler::receive()` fix below) no longer stalls after
  the first block when the client is reached via a bridge rather than
  directly. `ServiceDispatcher::ackEvents()` relays automatically via
  `RemoteBridge\Map::relayAckIfKnown()`, so AUN, Piconet, and WebSocket all
  gain this for free with no changes of their own. Relay eligibility is
  tracked per (network, station) — `Map::rememberAckRelay()` records, for
  every `SEND` accepted onto one of this instance's own local networks,
  which connection asked for that delivery, so the real ack it provokes
  gets relayed back to the right peer. (An earlier version of this keyed
  relay eligibility off the *peer's* announced networks instead — the same
  table used for outbound `SEND` routing — which looked plausible but could
  never actually fire for a real ack, since a relayed `SEND`'s destination
  network is always one this instance itself serves, never one the peer
  announced.) Fully backwards
  compatible — a `1.0` peer negotiates down and simply never sends or
  receives `ACK`. See `docs/protocols/remote-bridge.md` for the wire format
  and, notably, the conformance requirements section describing exactly
  what a third-party bridge client must do to interoperate at 1.1.
- Remote bridge protocol version 1.1 also adds a `PING`/`PONG` heartbeat:
  once authenticated on a 1.1+ connection, each side pings the other
  periodically (default every 3s) and replies `PONG` to a received `PING`;
  any line at all resets an idle watermark, and a connection silent for
  10s (roughly three missed pings) is closed as dead. Closes a gap the base
  protocol otherwise has no way to detect — a peer process killed without a
  clean FIN, a pulled cable, or a firewall silently dropping connection
  state — reactively, via a failed read/write, was previously the only
  detection mechanism. A `1.0` peer is fully exempt, same as `ACK`. See
  `docs/protocols/remote-bridge.md`'s Heartbeat section.
- TorchNet protocol support, including a file browser in the admin
  interface.
- IPv4 service improvements: DCI4-style ARP, limited ICMP support, and much
  improved unit test coverage for ARP/ICMP/NAT.
- S3 VFS plugin (with caching) and an upload utility for getting `.inf`
  style BBC files into an S3 bucket.
- "Catalogue from URL" VFS plugin, for mounting a set of files described and
  hosted by a remote website, plus a utility that builds a `.inf`-style tar
  archive for that format.
- File browsing support in the FileServer admin page (mirroring the
  TorchNet browser, adapted for Acorn MOS vs CP/M differences).
- Print job conversion from ESC/P to PostScript, with `esc2ps` bundled and
  configured in the Docker image; the admin interface can list and download
  spooled jobs, including converted versions.
- Broadcast-based printer discovery.
- Basic file locking, enforced by the `Vfs` class and passed through to
  plugins that can lock at the underlying filesystem level.
- CLI-level `*CAT` and `*OPT` implementations, for older NFS ROMs/clients
  (System 3/5 etc.) that expect the server to provide them.
- Admin interface pages for the encapsulation methods, showing live
  status/details, plus admin API functions for pulling out map information.
- Class overview, protocol, and config documentation committed to the repo
  and linked from the README.
- Correct responses for machine-type and OS-version detection queries.
- A dedicated `aun` user (in the `audio` group) as the default owner of
  Piconet serial ports.
- Multi-queue printer support (new `Printer` and `PrinterRegistry` classes,
  configured via `printers.cfg`), as part of a broader printer support
  refactor for better compatibility with Level 4 and MDFS file servers.
- New admin interface for managing users (create, edit, delete, set
  password).
- Backwards-compatible user quota support (recorded but not yet enforced).
- A compatibility document (`docs/compatibility.md`) covering how the File
  and Print services compare against Level 4/MDFS behaviour.
- `Mdfs` VFS plugin for SJ Research MDFS floppy/hard-disc images (`*.mdfs`)
  and HDFS hard-disc images (`*.hdfs`), built on the new
  `homelan/mdfs-disk-reader` package. Read-only by default; set
  `vfs_plugin_mdfs_write_enabled` to allow writes, the same way the S3
  plugin's `write_enabled` flag does.
- `MaceMail` service provider (in progress, landing in phases) for the
  1985 MaceMail electronic-mail protocol — see `docs/protocols/macemail.md`
  for the reverse-engineered wire format. Authentication and user identity
  are delegated entirely to the existing `Authentication\Security` system
  rather than a separate password store; MaceMail keeps only its own
  slot-number-to-username assignment table and mail/store data, in native
  file storage under the new `macemail_store_dir` config key (no VFS
  involved). Landing in phases: connect/discovery handshake and
  logon/logoff first, then directory listing (all users / online users
  only) and mail check, then sending mail (to an explicit recipient list
  or everyone), listing and reading a mailbox, deleting a message, and a
  push notification to an online recipient when new mail arrives for
  them, then 8 general-purpose per-user store slots (recall/save/delete)
  and the mailbox-scan/Look paged summary mechanism (the latter faithful
  to the server-side wire format found in the original BASIC source, but
  unreachable from the shipped terminal client, which never triggers it —
  see `docs/protocols/macemail.md`), then chat invites and the per-user
  availability flag that gates them (the chat text exchange itself is
  peer-to-peer between clients and needs no server support), and finally
  an admin interface: mail-slot provisioning against existing filestore
  users, forced logoff, and canned system-broadcast messages (the
  vintage client only understands a handful of fixed messages baked into
  its own code, so the admin UI offers a picklist rather than free text).
  The quick-command envelope (port 0x19), which the original client always
  broadcasts, is now also accepted addressed directly to the server's own
  station — for updated clients that already know the server's address and
  send it via unicast instead. Every operation behaves identically either
  way, and replies are unaffected (they were always addressed back to the
  real requester, never broadcast), so unmodified clients keep working
  exactly as before.
- `Teletext` service provider for the Econet teletext server protocol (aka
  "TSERV") — see `docs/protocols/teletext.md` for the wire format, sourced
  from the published mdfs.net specification and cross-checked against how
  PiEconetBridge's own disk-backed teletext server maps onto it. Like
  PiEconetBridge, this server always serves pages from local storage rather
  than a live receiver: discovery, page request (by channel/page/subpage
  number, synchronously — there is never a real queue), version/max-users/
  date-time queries, logoff/cancel/view-screen no-ops, service/header
  toggles, and a periodic "currently displayed page" broadcast (the most
  recently served page, since there is no live carousel to report on).
  Pages live under a new `teletext_store_dir` config key as native file
  storage (one directory per channel, one file per page holding a raw
  1024-byte Mode 7 screen dump, plus `{page}_{N}.dat` files for any
  subpages beyond the first), independent of the VFS plugin system and
  entirely read-only — the protocol has no operation for a client to save a
  page, so populating the store is outside this server's own remit, same
  as PiEconetBridge. Unlike PiEconetBridge (whose own teletext server is
  documented as unable to dial a specific subpage), this server does
  support requesting a specific subpage — page numbers accept hex digits
  (A-F) as well as decimal, for sources (like Teefax, below) that use the
  extra numbering capacity that gives. Includes a minimal admin interface
  (status, a read-only channel/page-count listing, and a "Refresh Teefax
  Now" trigger).
- Automatic Teefax import for the `Teletext` provider: set
  `teletext_teefax_channel` and one channel is kept populated from the
  [Teefax](https://magazine.raspberrypi.com/articles/teefax) teletext
  archive (via a GitHub mirror of its source repository, downloaded as a
  single tarball — no `git`/`svn` binary needed) entirely automatically,
  through the provider's own housekeeping task rather than a separate
  cron job. `TeefaxTtiParser` converts Teefax's `.tti` (Teletext Text
  Interchange) page files — a different format from this project's own
  raw screen-buffer storage — decoding all three ways teletext control
  codes can be embedded in that format. The actual download and
  conversion run as a detached background process (`src/util/teefax-import`,
  a new `TeefaxImport` console command, spawned via
  `React\ChildProcess\Process` the same way `BeebTerm` spawns its own
  sessions) so a slow refresh never blocks the event loop or any in-flight
  Econet traffic; a staging-directory-then-atomic-rename install means the
  running server only ever sees a complete old or new channel, never a
  half-imported mix.

### Changed
- AUN, Piconet, and WebSocket encapsulations made more robust against bad
  or duplicate packets, alongside changes needed to support the remote
  bridging of networks.
- The bridge now passes out remote network information correctly, with an
  admin interface to view it.
- Various components (the React command, S3 upload, service dispatcher,
  Piconet handling) reworked to be more unit-testable, backed by a large
  expansion of the unit test suite.
- The `AFS`, `AdfsHD`, `AfsImg`, `DfsSsd`, and `AdfsAdl` VFS plugins gained a
  `setImageReader()`/`reset()` testing seam (matching the `Mdfs` plugin's),
  so their disk-image readers (`L3fsReader`, `AdfsReaderHD`, `DfsReader`,
  `AdfsReader`) can be mocked in unit tests instead of requiring a real
  synthesised binary disk image — their test suites were rewritten around
  this to cover catalogue traversal, handle I/O, and the path-resolution bug
  above.
- Piconet serial port setup rewritten to avoid FFI, since most PHP installs
  block its use.
- The network bridge now reports the real network number a client is
  mapped to, instead of a fixed value.
- PHPUnit upgraded to v11, fixing coverage reporting; this highlighted how
  much of the codebase lacked tests and led to a large expansion of unit
  test coverage, including for the new features above.
- Composer dependencies updated to remove packages with known
  vulnerabilities.
- More reliable handling of unexpected network behaviour, including
  dropping duplicate traffic.

### Fixed
- `Aun\Handler::receive()` never forwarded real incoming Ack packets to
  `ServiceDispatcher::inboundPacket()`, so `ServiceDispatcher::ackEvents()`
  — the only path that fires `addAckEvent()` callbacks — was unreachable
  for AUN clients. `FileServer`'s block-by-block load/save/get-bytes/
  put-bytes protocol depends entirely on that callback to send each
  subsequent block, so any multi-block transfer over AUN (the project's
  primary transport) hung after the first block. Introduced in the
  November 2023 rewrite of AUN packet handling into its own `Handler`
  class (the pre-rewrite code, inline in `Command/React.php`, dispatched
  every inbound packet — including real acks — unconditionally); the
  rewrite's own follow-up commit noted "the queuing of aun message, and
  acks is still not right" but the gap was never closed. Fixed by having
  `_unQueue()` report whether an ack was actually the one a service was
  waiting on (matched the head of that host's retry queue, or the pending
  final-retry sequence) and only dispatching in that case — stricter than
  even the original pre-rewrite behaviour, which dispatched every
  incoming ack unconditionally with no correlation to what was in flight.
- `ServiceDispatcher::houseKeeping()`'s stream-port expiry sweep unset a
  service's port whenever it fell in the same numeric range used for
  `claimStreamPort()` (20-39) but wasn't actually a claimed stream port —
  it read a missing `aPortTimeLimits` entry as "already expired" instead of
  "not a stream port, leave it alone". `MaceMail` registers several ports
  in that exact range (0x19-0x27), so it silently stopped receiving on
  most of its ports the first time housekeeping ran (every
  `housekeeping_interval`, 300s by default) after startup.
- `Reply::buildEconetpacket()` read the reply port lazily off the shared
  request object instead of snapshotting it when the reply was built. This
  had no effect on protocols using a fixed reply port, but any provider
  that sends more than one reply off the same request via a mutable
  `setReplyPort()` (MaceMail's quick-command ack followed by an
  operation-specific reply; Teletext's page-request ack followed by its
  page-data transmission) would have every already-built reply silently
  pick up whichever port was set *last*, mislabelling earlier replies (e.g.
  MaceMail's ack reporting port 0x1F instead of 0x1A whenever a directory
  request also produced a second reply). Found while adding the Teletext
  provider, which uses the same pattern.
- Print conversion jobs not starting (the conversion process wasn't being
  launched).
- A VFS plugin not producing directory listings correctly.
- `close(0)` not closing all handles, plus assorted date/time handling
  issues.
- Various malformed, too-short, or null packet handling issues in AUN and
  Piconet.
- The disk-image VFS plugins (`AFS`, `AdfsHD`, `AfsImg`, `DfsSsd`, `AdfsAdl`)
  could silently hand back a directory handle for an image's root instead of
  failing with "No such file" when asked to open a nonexistent file or
  directory nested inside an otherwise-valid image (e.g. `$.scsi0.NOSUCHFILE`
  when `scsi0.l3` exists but has no `NOSUCHFILE` entry) — a second,
  looser fallback check re-derived the path and matched on the image alone,
  ignoring the requested sub-path. Also fixed a related double-slash artefact
  in the same plugins' case-insensitive path resolution.
- `AdfsHD` and `AdfsAdl` could emit an "undefined array key" warning when
  listing a directory entry with no `size` in its catalogue metadata (e.g.
  sub-directories), matching the `?? 0` fallback `AFS` already had.

## [2.0.1] - 2024-05-20

Merge of the long-running `php-react` branch: a major modernisation of the
runtime, plus the first IPv4/NAT and additional filesystem/service support.

### Added
- IPv4 service: ARP request/response handling, basic packet forwarding, and
  NAT support.
- ADFS hard disk image support (e.g. BeebEm's `scsi0.dat`) and an AFS
  (Level 3 filesystem) VFS plugin, backed by the new `homelan/l3fsreader`
  library.
- BeebTerm service (A. Gordon's BBC terminal protocol).
- First cut of the Piconet interface: a serial-based encapsulation with a
  send queue that retries packets when the 4-way handshake fails.
- `*opt` support for users to set their boot option.
- Support for dynamically turning individual services off via config.
- Mockery-based handler interfaces/fakes so previously untestable classes
  could be unit tested.
- CI job to check Composer dependencies with composer-dependency-analyser.

### Changed
- Migrated to PHP 8.1 (with PHP 8.3 also trialled), ReactPHP, and
  Symfony 6; the React-based version became the only supported version
  going forward.
- Large type-safety cleanup driven by PHPStan (return types, parameter
  types, class properties) and Rector-assisted upgrades.
- AUN, WebSocket, and Piconet transports unified behind a common
  handler/interface structure, instead of being built around AUN only.
- Load/save byte transfers now wait for each block to be acknowledged by
  the client, rather than firing on a timer.
- Docker image rebuilt on PHP 8.1 with its own Composer and a trimmed
  extension list; container scanning moved to Clair.
- Upgraded to Smarty 5.

### Fixed
- Numerous PHPStan-flagged type and nullability issues across the
  codebase.
- Login with a blank or missing password.
- `CHROOT` / `CHROOTOFF` handling.
- Several IPv4/ARP/NAT packet encoding bugs (byte order, flags, length
  fields).
- An Acorn Electron compatibility issue found at Retrofest 2023.
- Handling of BeebEm's legacy, non-AUN packet format.

## [0.1.4] - 2020-05-06

Start of the migration from the original blocking event loop to ReactPHP,
and the first web admin interface.

### Added
- ReactPHP-based server entry point (`Command/React`), with services
  restructured as pluggable Service Providers.
- Web-based admin frontend, built on Symfony.
- Sentry error-tracking integration.
- A housekeeping task and an ack-event system for reliable packet delivery.
- AFS and AfsImg VFS plugins, via the new `homelan/l3fsreader` dependency.

### Changed
- Upgraded classes to PHP 7.2/7.3 syntax (return types etc.) using Rector.

### Fixed
- The config directory being read before it was set, which meant
  `AuthPluginFile` never actually loaded any users.
- Various issues flagged by PHPStan.

## [0.1.3] - 2019-02-12

The original, pre-React implementation: a from-scratch AUN/Econet
fileserver built up incrementally since 2013.

### Added
- Core AUN/Econet packet handling and the main dispatch loop.
- Pluggable authentication and VFS plugin architecture, with DFS (`.ssd`)
  and ADFS (`.adl`) disk image plugins.
- The core fileserver command set: `*CAT`, `*DIR`, `*LIB`, `*CDIR`,
  `*NEWUSER`, `*PRIV`, `*DEL`, `*PASS`, `*REMUSER`, `*RENAME`, load/save,
  get/put bytes, file info/access, and chroot support.
- Basic printing support.
- Early work on a bridge protocol between Econet segments.
- Composer-based autoloading and namespacing for the whole codebase.
- Docker, RPM, Synology, and Phar packaging.
- Initial PHPUnit test suite and GitLab CI pipeline.

### Changed
- Repeated reorganisation of the codebase into proper namespaces (`Vfs`,
  `Aun\Message`, etc.) as it grew.
- Migrated to Composer autoloading and Symfony Console for the CLI.

### Fixed
- A large number of protocol-compliance and encoding bugs found through
  testing against BeebEm and real clients: date handling, path handling,
  packet counters, and little/big-endian encoding, among others.
