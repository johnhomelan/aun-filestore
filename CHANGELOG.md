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

### Changed
- AUN, Piconet, and WebSocket encapsulations made more robust against bad
  or duplicate packets, alongside changes needed to support the remote
  bridging of networks.
- The bridge now passes out remote network information correctly, with an
  admin interface to view it.
- Various components (the React command, S3 upload, service dispatcher,
  Piconet handling) reworked to be more unit-testable, backed by a large
  expansion of the unit test suite.
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
- Print conversion jobs not starting (the conversion process wasn't being
  launched).
- A VFS plugin not producing directory listings correctly.
- `close(0)` not closing all handles, plus assorted date/time handling
  issues.
- Various malformed, too-short, or null packet handling issues in AUN and
  Piconet.

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
