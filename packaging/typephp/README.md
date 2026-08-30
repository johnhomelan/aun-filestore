# TypePHP (AOT) build

[TypePHP](https://github.com/swoole/typephp) (`swoole/typephp`, GPL-3.0) is an
ahead-of-time compiler that lowers PHP to C++17 and then to native machine code,
producing a native executable, a PHP extension, or a shared library.

## What builds today

`TYPEPHP_DRY=0 make typephp` compiles and links the **real native daemon**:
`build/typephp/aun_filestored` (~13 MB ELF, `Successfully compiled 243 files`).
It is `main.php` — a de-dynamised port of `HomeLan\FileStore\Command\React::MainLoop()`
— plus the project's own domain code plus the vendored ReactPHP event loop /
sockets / datagram and the Ratchet WebSocket stack (`guzzlehttp/psr7`,
`ratchet/rfc6455`, `cboden/ratchet`).

Run it and it boots for real:

```
[INFO] aun-filestored (TypePHP native build) starting
[INFO] core: event loop is React\EventLoop\StreamSelectLoop
[INFO] AUN: listening on 0.0.0.0:32768
[INFO] WebSocket bridge: listening on 0.0.0.0:8090
[INFO] RemoteSocket relay: listening on 127.0.0.1:8091
[INFO] RemoteProvider relay: listening on 0.0.0.0:8092
[INFO] core: entering primary loop
```

Both packet paths were smoke-tested end to end with no interpreted fallback: a
synthetic AUN `*I AM` FS request runs decode -> OSCLI -> `FileServer::login` ->
`Security` -> reply -> AUN encapsulation -> transmit; a WebSocket client does the
HTTP/1.1 upgrade and its frame reaches `WebSocket\Handler::onMessage`. See
[`PORTING-REACT.md`](PORTING-REACT.md) for the stage-by-stage record and
`packaging/typephp/vendor-patches/` for the nine small vendor changes.

`make typephp` without `TYPEPHP_DRY=0` is a `--dry` transpile (C++ generation,
~239 files, no link) — a fast "does it still compile" check.
`TYPEPHP_PROJECT=packaging/typephp/project.domain-only.yml make typephp` compiles
just the domain classes (the pre-Stage-3 config), faster still.

## Reality check — what is *not* compiled

TypePHP supports **a defined subset of PHP**, so some things stay interpreted or
out entirely:

- **The Symfony admin UI** (`Admin/`, `ShareFs/` — framework-bundle, the DI
  container, Smarty, reflection). Separate problem; `Admin/` and `ShareFs/` are
  not in `sources`.
- The **outbound RemoteBridge client** - transpiles, but `main.php` does not
  wire it and `ClientHandler` uses `new \React\Socket\Connector` (not
  compiled). Plan to finish it: PORTING-REACT.md "Stage 6" (swap for
  `TcpConnector` + synchronous `gethostbyname()`, ~0.5 day, no `react/dns`).
- The **Piconet serial interface** *is* compiled and wired
  (`UnixSerialDeviceConnector` via `react/socket`); it just can't be exercised
  without an Econet serial device (`stty` fails, `PiconetHandler` retries with
  exponential backoff - verified).
- **Monolog** — replaced by `shims/StderrLogger.php` (one stderr line per record).
- **Dynamic dispatch** — the auth/VFS plugin *loaders* (`$class::init()` on a
  string class name), `config`'s `constant()`/`defined()` lookups, and Ratchet's
  dynamic `$conn->decor` property go through TypePHP's Zend runtime fallback
  (interpreted). None are on the packet hot path.

`bin` mode also forbids executable statements at file scope, so `src/filestored`
et al. (top-level code) are replaced by `main.php`, and Composer's `files`
autoloads (`react/promise/functions_include.php`, `symfony/deprecation-contracts`,
`ralouphie/getallheaders`) are handled by listing the real file or a shim.

### The 5 files still excluded, and why

`project.yml` `ignore` (beyond `src/include/external`) now lists only the
ReactPHP / Ratchet network server shells:

| File | Blocker |
|---|---|
| `RemoteProvider/Client.php` | `extends Evenement\EventEmitter` (+ `React\Promise`, `Ratchet\Client` type hints) |
| `RemoteProvider/RelayServer.php` | `implements Ratchet\MessageComponentInterface` |
| `RemoteSocket/RelayServer.php` | `implements Ratchet\MessageComponentInterface` |
| `RemoteSocket/RelayedUdpTransport.php` | `extends Evenement\EventEmitter implements React\Datagram\SocketInterface` |
| `WebSocket/Handler.php` | `implements Ratchet\MessageComponentInterface` |

`tpc` fatal-errors on a parent/interface it can't resolve. `evenement/evenement`
is one small file that could be added to `sources`, and
`Ratchet\MessageComponentInterface` is a behaviour-free 4-method contract that
could be a local stub — but these classes' bodies *are* the React event loop /
Ratchet socket server (`$conn->send()`, `SplObjectStorage` client sets, the
promise API), none of which is compiled, so transpiling them produces inert
code. They stay out unless the goal becomes compiling the event loop itself
(vendoring React + Ratchet + Guzzle PSR-7).

### Blockers that were ported rather than ignored

- **`Psr\Log\LogLevel::*`** — `Services/Provider/EcoSyslog.php`'s `SEVERITY_MAP`
  referenced the PSR-3 level constants. Replaced with their literal string
  values (PSR-3 fixes them), dropping the `psr/log` compile-time dependency.
- **Scalar re-type** — `Vfs/DirectoryEntry::getCTime()` reused `$iYear` as a
  `string` then an `int`; split into `$iYear` / `$iMonthYear` with explicit
  `(int)` casts. `Vfs/CpmDirectoryEntry` only failed because it extends
  `DirectoryEntry`.
- **Closure by-reference parameter** — `FileServer.php`, `FileServer/Cli.php`
  and `FileServer/FileHandles.php` each define a self-recursive ACK handler
  taking `\Closure &$cAckHandler`. The `&` was dead (the closure only *calls*
  the handler and passes it forward, never reassigns it); removing it is
  behaviour-identical and TypePHP-accepts it.
- **`switch` fall-through.** Every non-empty `case` must end in
  `break`/`return`/`continue`/`throw`/`exit`. `WebSocket/JsonPacket.php`,
  `Services/Provider/BeebTerm.php`, `Bridge.php` and `Viewdata.php` each had a
  final `default:` that only logged and fell out — a `break;` was added.
  `Authentication/Plugins/AuthPluginFile.php` used a deliberate
  `plain`→`sha1`→`md5` fall-through cascade in its password check; that is now
  written as an explicit `||` chain per arm, verified equivalent against the
  auth-plugin test fixtures.
- **`foreach`-key shadowing.** `Services/Provider/Teletext/NewsPageComposer.php`
  and `Services/Provider/Torchnet.php` reused a `foreach ($x as $k => $v)` key
  variable that a `string` local already held; the loop key was renamed.

Individual blockers are still being knocked down as prep — see **Smarty** and
**pcntl** below.

## Files

| File | Purpose |
|---|---|
| `project.yml` | `tpc` build config — mode, PHP syntax level, `sources` (the 13 domain directories compiled today) and `ignore` (the 5 ReactPHP/Ratchet shells left out). |
| `main.php` | `bin`-mode entry point (`main(int $argc, array $argv): void`). |
| `Containerfile` | `php:8.4-cli-bookworm` + C++ toolchain + `composer require swoole/typephp`. |
| `build-typephp.sh` | Builds the image and runs `tpc` under podman. |
| `shims/` | Compile-only replacements for functions `tpc`'s libphp lacks (see **pcntl** below). |

## pcntl

TypePHP links a libphp that has the `posix` extension but not `pcntl`.
`posix_seteuid` / `posix_getuid` work natively. The server's own code now uses
only `pcntl_fork` (in `daemonize()`) - the `declare(ticks=1)` + raw
`pcntl_signal` handlers were removed as dead code. `pcntl_signal` /
`pcntl_signal_dispatch` / the `SIG_*` constants are still shimmed because
ReactPHP's `StreamSelectLoop` uses them once `$loop->addSignal()` is called
(e.g. for a graceful-shutdown handler). `shims/`:

| File | What |
|---|---|
| `shims/pcntl_posix.cc` | `php_pcntl_fork` (→ `fork(2)`), `php_pcntl_signal` (→ `sigaction(2)`; accepts a callable or `SIG_DFL`/`SIG_IGN`), `php_pcntl_signal_dispatch` (runs latched handlers). |
| `shims/pcntl_posix.stub.php` | The matching `.stub.php` declarations. |
| `shims/signals.php` | `SIGTERM`, `SIGCHLD`, … and `SIG_DFL` / `SIG_IGN` constants (Linux values). |

These are listed in `project.yml` `sources` and are **never autoloaded**, so a
normal PHP run (with the real `pcntl`) never sees them — no redeclare clash.

Caveat: a C signal handler can't call into Zend, so `pcntl_signal()` only
records deliveries and the handlers run from `pcntl_signal_dispatch()` - which
ReactPHP's `StreamSelectLoop` calls every tick when `pcntl_async_signals` is
absent (it is, deliberately). `declare(ticks=1)` is separately unsupported by
TypePHP; the server no longer uses it.

## LDAP (`AuthPluginLdap` / `LdapClient`)

TypePHP's libphp has no `ldap` extension, so the LDAP auth backend would
transpile but every `ldap_*` call would hit an undefined function at run time.
`shims/ldap_openldap.cc` implements the twelve functions `LdapClient.php` uses
directly against the **OpenLDAP** client library (`libldap` / `liblber`,
`libldap2-dev` in the `Containerfile`); `project.yml` and `project.sharefsd.yml`
carry `link-libs: [ldap, lber]`.

| File | What |
|---|---|
| `shims/ldap_openldap.cc` | `php_ldap_connect` (→ `ldap_initialize`), `ldap_set_option` (protocol version + network-timeout `struct timeval`), `ldap_start_tls`, `ldap_bind` (simple bind via `ldap_sasl_bind_s`), `ldap_unbind`, `ldap_search` (`ldap_search_ext_s`, subtree), `ldap_get_entries` (rebuilds ext-ldap's `count`/lower-cased-attr array shape, then frees the message), `ldap_add` / `ldap_mod_replace` / `ldap_mod_add` / `ldap_mod_del` (`LDAPMod` marshalling; empty value list = strip the attribute), `ldap_delete`, and a pure `ldap_escape`. |
| `shims/ldap_openldap.stub.php` | The matching `.stub.php` ABI declarations. |
| `shims/ldap_classes.php` | Compile-only `\LDAP\Connection` / `\LDAP\Result` classes, each just an integer `id` into the shim's process-global handle tables — so `LdapClient.php`'s `?\LDAP\Connection` property and `instanceof \LDAP\Result` compile and work unchanged. |
| `shims/ldap_constants.php` | `LDAP_OPT_PROTOCOL_VERSION`, `LDAP_OPT_NETWORK_TIMEOUT`, `LDAP_OPT_TIMEOUT`, `LDAP_ESCAPE_FILTER`, `LDAP_ESCAPE_DN`. |

Same non-autoload rule as the pcntl shim: these files are only in `project*.yml`
`sources`, so a normal (interpreted) run uses the real `ext-ldap` and never sees
them. Because `Security::_getAuthPlugins()` gates `AuthPlugin*::init()` on
`class_exists($name, false)` (always true for an AOT class), `main.php` /
`main-sharefsd.php` call `AuthPluginLdap::init($oLogger)` explicitly when `ldap`
is in `security_auth_plugins` — the same treatment `AuthPluginFile` already gets.

Verified end to end against a live directory: `ldap_connect` → simple `ldap_bind`
→ `ldap_search` → `ldap_get_entries` returns the correct nested
`count` / lower-cased-attribute array, and `ldap_escape('a*b(c)\d\0', '',
LDAP_ESCAPE_FILTER)` matches PHP exactly.

## S3 (`Vfs\Plugin\S3`)

`aws/aws-sdk-php` + `guzzlehttp/guzzle` are large and heavily dynamic and are not
on the compile path, so `Vfs/Plugin/S3.php` would transpile with `Aws\S3\S3Client`
/ `Aws\S3\Exception\S3Exception` unresolved (a run-time class lookup that fails
the moment `S3` is added to `vfs_plugins`). Unlike LDAP this needs **no C** — the
tpc-linked libphp already has `curl`, `hash` (`hash_hmac`), `openssl` and
`SimpleXML`, which is everything an S3 client needs.

| File | What |
|---|---|
| `shims/aws_s3_client.php` | Compile-only `Aws\S3\S3Client` (curl transport + AWS Signature V4 via the `hash_hmac('sha256', …)` key chain; `ListObjectsV2` XML parsed with `simplexml_load_string` — kept a local, never a `\SimpleXMLElement` **type hint**, which segfaults tpc) and a bare `Aws\S3\Exception\S3Exception extends \RuntimeException` (only `getMessage()` is ever used). Implements exactly the six calls `S3.php` makes: `doesObjectExist` / `getObject` / `putObject` / `deleteObject` / `copyObject` / `listObjectsV2`. Virtual-hosted and path-style (custom `endpoint`, e.g. MinIO) addressing; anonymous when no credentials. No pagination / multipart / streaming / presigning. |

Listed only in `project.yml` / `project.sharefsd.yml` `sources`, never autoloaded,
so an interpreted run uses the real AWS SDK — same model as the LDAP shim.
`S3.php` compiles and runs **unchanged**; enable it by adding `S3` to
`vfs_plugins`.

Verified against real AWS S3: the SigV4 key chain matches AWS's published
test-vector signature; `listObjectsV2` parses `CommonPrefixes` + `Contents`
(`Key` / `Size` / `LastModified` as a `\DateTimeImmutable`); `doesObjectExist`
(HEAD) and `getObject` round-trip.

## Command classes (Symfony Console)

The `HomeLan\FileStore\Command\*` classes `extend
Symfony\Component\Console\Command\Command`. `symfony/console` is large and
dynamic and is not on the compile path, so historically each `main-*.php`
re-implemented the command's body (a "de-dynamised port"). Instead, a small
reusable shim lets the real command class be compiled and run directly.

| File | What |
|---|---|
| `shims/symfony_console.php` | Compile-only `Command` (the `SUCCESS`/`FAILURE`/`INVALID` constants, fluent `addOption()`/`addArgument()`/`setHelp()`/… returning `$this`, an overridable `configure()`/`execute()`, and a minimal `run(InputInterface, OutputInterface): int` that just calls the protected `execute()`), `#[AsCommand]`, `InputOption::VALUE_*`, `InputArgument::*`, and the `InputInterface` / `OutputInterface` contracts. A bracketed multi-namespace file. |
| `shims/console_runtime.php` | `HomeLan\FileStore\Cli\ArgvInput` (`--long`, `--long=v`, `--long v`, bare `--flag`, and `-c` / `-d` / `-p` shortcuts → `config` / `daemonize` / `pidfile`) + `StdoutOutput` (strips `<info>`/`<error>` style tags). |
| `shims/react_eventloop_factory.php` | `React\EventLoop\Factory::create()` → `new StreamSelectLoop()` (the real one probes for `ExtEventLoop`/`ExtUvLoop`/`ExtEvLoop`, none of which compile). |
| `shims/safe_define.php` | bare `function safe_define()` (see the note under **pcntl** about file-scope code). |

Same non-autoload rule keeps the real Symfony Console for interpreted runs.

**`dnsd` / `ntpd` / `sql-serverd`** now do this: `main-dnsd.php` etc. are ~12-15
lines — build a `StderrLogger` (`sql-serverd` also `Security::init()` +
`AuthPluginFile::init()`), then `(new Dnsd($oLogger))->run(new ArgvInput(...), new
StdoutOutput())`. The staged copy of the command class (file-scope
`system.inc.php` include stripped, `build/typephp/stage/cmd/`) compiles as-is;
`configure()`, `execute()`, `MainLoop()` and `daemonize()` all run natively. Small
`src/` change per command for the AOT build's strict callback arity —
`Command\Dnsd` / `Command\Ntpd`: a trailing `mixed $mSocket = null` on the
`on('message', …)` listener (the transport emits 3 args); `Command\SqlServerd`: a
trailing `?TimerInterface $oTimer = null` on its two `addPeriodicTimer` callbacks.
The teletext build uses the same three console shims.

`sql-serverd` hosts `Services\Provider\SqlServer` over the Remote Provider
Protocol (like `ecosyslogd`). **SQLite, PostgreSQL and MySQL/MariaDB** — the
`Containerfile` builds the `php:8.4-cli-bookworm` base (which ships only
`pdo_sqlite`) with `pdo_pgsql` + `pdo_mysql` added via `docker-php-ext-install`, so all three
drivers are in the libphp `tpc` links (they load through the embed SAPI's
`conf.d` scan exactly as under the CLI). `DatabaseRegistry` still rejects an
engine whose driver is not loaded with a clear error. Verified against live
Postgres 16 + MariaDB 11 + `sqlite::memory:`: `ConnectionFactory` →
`new \PDO(...)` → `BufferedCursor` over a `\PDOStatement` → incremental
`fetchNext()`, plus `PgsqlCursor`'s server-side `DECLARE … CURSOR` / `FETCH n`
paging — the `\PDO` / `\PDOStatement` signature hints and all three drivers
compile and run natively.

**`filestored` (`Command\React`), `sharefsd` (`Command\ShareFsd`),
`ecosyslogd` (`Command\EcoSyslogd`)** still use a bespoke `main*.php` (`ecosyslogd`
unlike `sql-serverd` because of Monolog): `React`
pulls in the Symfony **HttpKernel** admin service and `React\Http`; `ShareFsd`
builds real UDP sockets via `React\Datagram\Factory` (which eagerly constructs a
`react/dns` resolver) and the ShareFS Symfony admin kernel; `EcoSyslogd` builds
a **Monolog** `Logger` with syslog handlers. None of those compile, and they are
more than a loop-factory swap away.

## ext-hash, ext-sockets, ReactPHP, Ratchet

Investigated for the same shim treatment; the outcome is mostly "nothing to
shim":

- **ext-hash** — not a blocker. The linked libphp *has* `hash` (`hash()`,
  `hash_hmac()`, `hash_equals()` all work). The earlier `ext/hash` failure was
  `swoole/phpx` itself not compiling against **PHP 8.5**'s `ext/hash` header;
  that is why the `Containerfile` pins **PHP 8.4** — a shim can't touch phpx's
  own C++ build.

- **ext-sockets** — not a blocker. The libphp has no `sockets` extension, but
  every `socket_*` call in ReactPHP (`react/socket`, `react/dns`) is
  `function_exists()`-guarded and falls back to `stream_socket_*` (which is in
  `ext-standard`, present). `react/datagram` — the AUN UDP path — uses only
  `stream_socket_*`. `cboden/ratchet`'s runtime code uses no raw `socket_*`.
  If a `socket_*` path is ever forced, a `socket.stub.php` + `.cc` shim over the
  Berkeley sockets API would follow the same pattern, but it is not needed now.

- **ReactPHP event loop** — `StreamSelectLoop` needs `pcntl_signal` +
  `pcntl_signal_dispatch` (provided) and passes `SIG_DFL` to unregister
  (handled). It is left in its **poll** mode by *not* providing
  `pcntl_async_signals`, so it calls `pcntl_signal_dispatch()` each tick.
  `stream_select`, `stream_socket_*`, `proc_open` are all `ext-standard`.

- **Remaining React/Ratchet blockers are language-level**, not function-level:
  closure-heavy dynamic dispatch, by-reference parameters, `func_get_args()`,
  `SplObjectStorage`, magic `__invoke`, etc. Those go through TypePHP's Zend
  runtime fallback (interpreted, not compiled) or hit its unsupported-feature
  list — neither is fixable with a shim.

## Usage

```bash
make typephp                       # podman build + a --dry run (generate C++ only)
TYPEPHP_DRY=0 make typephp         # full build: compile + link a native binary
TYPEPHP_OPT=3 TYPEPHP_JOBS=8 make typephp
TYPEPHP_ARGS='--format' make typephp
PODMAN=docker make typephp         # use docker instead of podman
```

Generated C++ and the object / PCH cache land in `build/typephp/obj/`; a native
binary (`TYPEPHP_DRY=0`) in `build/typephp/aun_filestored`.

The compiled binary links `libphpx.so` (and `libphp`), which live in the image
under `/opt/typephp/vendor/swoole/phpx/lib`, so it runs inside the container but
is not self-contained on the host — a real deployment must ship those libraries
alongside it.

### Release tarballs

`.github/workflows/typephp-native.yml` builds every target
(`make native-daemons teletext-typephp`) on a published release, for **x86_64**
and **arm64**, and attaches one tarball per arch
(`aun-filestore-typephp-<version>-<arch>.tar.gz`). Each bundles the seven ELF
binaries in `libexec/`, `bin/` launcher scripts that set `LD_LIBRARY_PATH`, and
the complete non-glibc shared-library closure in `lib/` (libphp, libphpx, curl,
openssl, ldap, libpq, the PHP PDO extensions, …) — the target only needs
**glibc ≥ 2.36** (the `php:8.4-cli-bookworm` / Debian 12 build image; Ubuntu
24.04+, RHEL 10+, …). `packaging/typephp/ci/assemble-dist.sh`
does the packaging and can be run locally against `build/typephp/`. A manual
`workflow_dispatch` run uploads the tarballs as artifacts instead of to a release.

### The image

`Containerfile` is based on **`php:8.4-cli-bookworm`** — PHP 8.4 (not 8.5:
`swoole/phpx` 2.6.x fails to compile against PHP 8.5's `ext/hash` headers) on
Debian 12 (not the default trixie: bookworm's glibc 2.36 is the floor the
native binaries inherit, and it is far more widely deployable than trixie's
2.41). It builds the PHPX native library from source with `-fpermissive`.
Rebuild it only when the `Containerfile` changes; otherwise:

```bash
TYPEPHP_NO_BUILD_IMAGE=1 make typephp
```

Everything runs inside the container — the only host requirement is `podman`
(or `docker` via `PODMAN=docker`).

### Troubleshooting

`podman build` failing with `pasta failed ... Too many routes to duplicate`
means rootless podman can't mirror this host's (large) routing table into the
build namespace. Build with the host network instead:

```bash
TYPEPHP_BUILD_ARGS=--network=host make typephp
```

## Growing `sources`

The 13 domain directories are already in `sources` and `ignore` is down to the
5 framework shells. Growth from here means pulling in a *new* directory:

1. Add the directory to `sources` in `project.yml`.
2. `make typephp` and read the first `Fatal error:` — it names the file and line.
3. Either port that construct (see **Blockers that were ported** above for the
   fix shapes, and
   [`docs/en/INCOMPATIBLE_PHP_FEATURES.md`](https://github.com/swoole/typephp/blob/master/docs/en/INCOMPATIBLE_PHP_FEATURES.md)),
   keeping the change behaviour-preserving and covered by the PHPUnit suite, or
   add the file to `ignore` with a one-line reason and move on.
4. `make typephp` again; once it reaches `Dry run completed`, run
   `TYPEPHP_DRY=0 make typephp` to confirm the C++ still links.

`tpc` tolerates unresolved type hints in signatures, so a file only fails if it
*extends/implements* a missing class, *reads a constant/static* off one, or hits
a subset limit in a method body. `Admin/`, `ShareFs/`, `Command/` and
`Console/` are the remaining trees — each is coupled to Symfony, Smarty or
top-level launcher code and would need that dependency resolved first.

Compiling the ReactPHP loop + Ratchet themselves — so the Econet packet hot path
(datagram -> loop -> encapsulation -> providers) is native end to end — is a
larger staged effort with its own plan: [`PORTING-REACT.md`](PORTING-REACT.md).
