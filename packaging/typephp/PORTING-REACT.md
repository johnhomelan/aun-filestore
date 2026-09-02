# Plan: compiling the ReactPHP event loop + Ratchet so the critical path is native

Status: **DONE.** The native daemon (`build/typephp/aun_filestored`) builds with `TYPEPHP_DRY=0 make typephp`, boots the AUN UDP listener + the Ratchet WebSocket bridge + both relay listeners on the compiled `StreamSelectLoop`, and stays up. Both packet paths smoke-tested end to end. Prereq
reading: [`README.md`](README.md) in this directory (what compiles today, the
shim model, the container).

## Progress

| Stage | State | Evidence |
|---|---|---|
| 0 scaffolding | **done** | `build-typephp.sh` stages `src/vendor` React/Ratchet into `build/typephp/stage/` and applies `vendor-patches/*.patch` when the project file references the stage dir; `TYPEPHP_PROJECT` env selects the project file. Default `make typephp` unchanged (155 files). |
| 1 event loop core | **done** | `project.react.yml` links evenement + react/promise + react/event-loop (`StreamSelectLoop`) + react/stream as a native `.so`. `smoke/loop.php` -> native binary drives two timers, periodic fires exactly 10x in ~1.05s, one-shot stops the loop: **PASS**. |
| 2 datagram + TCP | **done** | Same project file adds react/socket (server + TCP/Unix connectors, no DNS/TLS/HappyEyeBalls) + react/datagram. `smoke/datagram.php` -> native binary binds a UDP socket via `React\Datagram\Socket`, round-trips one packet to a client socket, payload verified: **PASS**. |
| 3 wire Econet path | **done** | `project.react-app.yml` (~153 domain files + Stage 1-2 vendor set) + `smoke/econet.php` link to a 13 MB native binary. Boots `config` + a `StderrLogger` + `PacketDispatcher` + `ServiceDispatcher`, binds the AUN UDP socket, enters `StreamSelectLoop`, receives a real UDP packet, drives it `AunHandler::receive()` -> `ServiceDispatcher::inboundPacket()` -> provider. **All compiled - no interpreted fallback on the packet path.** |
| 3b full provider set + FS round trip | **done** | `smoke/econet.php` now wires the whole `src/filestored` provider list (`FileServer`, `PrintServer`, `Bridge`, `IPv4`, `BeebTerm`, `Torchnet`, `MaceMail`, `Teletext`, `Viewdata`, `EcoSyslog`, `ProxyProvider`) + `Security::init()` + `WebSocketMap::init()`. A synthetic FS `*I AM SYST` packet (port 0x99) runs the **complete request/reply cycle** natively: AUN decode -> `FsRequest` decode (`EC_FS_FUNC_CLI`) -> OSCLI parse -> `FileServer::login()` -> `Security::login()` -> `AuthPluginFile` (dynamic `$class::` dispatch, Zend fallback) -> reject-reply -> `PacketDispatcher::sendPacket()` -> `EncapsulationTypeMap::getType()` -> AUN encapsulation -> `AunHandler` transmit + retransmit. |
| 4 Ratchet WebSocket | **done** | `guzzlehttp/psr7` + `ratchet/rfc6455` + `cboden/ratchet` (Ratchet needs **no** react/http, no Symfony). `WebSocket/Handler.php` + both relay `RelayServer`s compile. |
| 4b Ratchet runtime | **done** | `smoke/websocket.php`: an in-process client does the HTTP/1.1 upgrade (guzzle/psr7 parse + rfc6455 `ServerNegotiator`), gets `101`, sends a masked text frame; the frame is decoded by `MessageBuffer` and reaches `WebSocket\Handler::onMessage` -> `JsonPacket::decode`. Patches `0006`-`0008` + a `trigger_deprecation` shim. |
| consolidation | **done** | `project.yml` + `main.php` rewritten as the real daemon (AUN + WebSocket + 2 relays + **Piconet** + the 3 periodic timers + `-c`/`-d`/`-p` args). `make typephp` builds it (244 files). `react/child-process` added (patch `0009`) so `Teletext`'s background imports work. |
| Piconet | **done (compiled, untested)** | `src/include/classes/React/UnixSerialDeviceConnector.php` added to `sources`; `main.php` runs a de-dynamised `piconetService()`. With no serial device the `stty` config fails and `PiconetHandler::scheduleReconnect()` retries (5s→10→…→300s cap) - all in compiled code. Needs hardware for a live test. |
| 6 RemoteBridge client | **planned** | transpiles today; needs `new \React\Socket\Connector` swapped for `TcpConnector` + synchronous `gethostbyname()` (no `react/dns`), the `&$this->aEntryState[$key]` write-back confirmed, and `remoteBridgeService()` wired into `main.php`. ~0.5 day, no new vendor patches. See **Stage 6** below. |
| 7 native `sharefsd` | **done** | `make sharefs-typephp` -> `build/typephp/sharefsd` (193 files). `main-sharefsd.php` + `project.sharefsd.yml` + `ratchet/pawl` (3 files, compiled clean) + un-`ignore`d `RemoteSocket/{Client,RelayedUdpTransport}`. **Zero new vendor patches.** Runs: service identity `SVC` logs in via `AuthPluginFile`, binds Freeway/AccessPlus/ShareFS-data UDP (32770/32771/49171), enters the loop, stays up. |
| 8 native `dnsd` / `ntpd` / `ecosyslogd` | **done** | `make {dns,ntp,ecosyslog}-typephp` -> `build/typephp/{dnsd,ntpd,ecosyslogd}` (193 / 193 / 273 files). All three start, wire their relay client, and log the reconnect backoff with nothing listening - the full outbound connect path (pawl -> `React\Socket\Connector` -> HappyEyeBalls -> react/dns -> `TcpConnector`) runs natively. One new patch `0013` (2-arg `set_error_handler` closures in `TcpConnector`/`UnixServer`/`FdServer`) - the outbound connect was never exercised before. `RemoteProvider/Client.php` got the same 4 edits as `RemoteSocket/Client.php`; new `shims/SyslogLogger.php`. |
| 9 Acorn disk-image VFS plugins | **done** | 6 files from `homelan/{acorn-disk,l3fsreader,mdfs-disk-reader}` added to every `project*.yml` `sources` (`AdfsReader`, `AdfsReaderHD`, `DfsReader`, `L3fsReader`, `MdfsReader`, `MdfsWriter`). Self-contained (`use Exception` only), **zero patches**. See **Stage 9** below. |
| teletext fetch scripts | **done** | `make teletext-typephp` -> `build/typephp/teletext_import` (~32 files), ONE binary dispatching on `argv[1]` ∈ `news`/`teefax`/`tvguide`/`weather`/`webfax`. Plain CLI, no ReactPHP. `main-teletext.php` + `project.teletext.yml` + `teletext/{shim-console,shim-safe-define,cli,runners}.php` replace the five `src/util/*-import` Symfony Console `Application` wrappers; `build-typephp.sh` grew a `stage/cmd/` step (strips `include_once system.inc.php` + neutralises `$http_response_header`). `news --feed bbc` runs end to end natively: RSS fetch -> `NewsFeedParser` (`simplexml`) -> per-article fetch -> `ArticleExtractor` (`DOMDocument` + `DOMXPath`) -> `NewsPageComposer` -> 280 `.dat` teletext pages written. `weather` likewise (`simplexml` -> `WeatherPageComposer`). One `src/` edit: DOM type hints stripped from `ArticleExtractor` signatures (see Rules learned). |
| LDAP auth backend | **done** | `shims/ldap_openldap.cc` implements the 12 `ldap_*` functions `LdapClient.php` uses against OpenLDAP (`libldap`/`liblber`); `project.yml` + `project.sharefsd.yml` get `link-libs: [ldap, lber]` and 4 shim files (`.cc`, `.stub.php`, `ldap_classes.php`, `ldap_constants.php`). `\LDAP\Connection` / `\LDAP\Result` become compile-only classes carrying an int handle. Both binaries build + link libldap; verified end to end against a live directory (bind → search → `ldap_get_entries` shape). `main.php` / `main-sharefsd.php` call `AuthPluginLdap::init()` when `ldap` is configured. No `src/` change. |
| S3 VFS plugin | **done** | `shims/aws_s3_client.php` — a **pure-PHP** compile-only `Aws\S3\S3Client` (curl + SigV4 via `hash_hmac`, `ListObjectsV2` parsed with `simplexml_load_string`) + a bare `Aws\S3\Exception\S3Exception`. No C, no new deps — the tpc libphp already has curl/hash/openssl/SimpleXML. Added to `project.yml` + `project.sharefsd.yml` `sources`. `Vfs/Plugin/S3.php` compiles unchanged; verified against real AWS S3 (SigV4 test vector + list/head/get). No `src/` change. |
| Command classes | **done (dnsd, ntpd, sql-serverd)** | `shims/symfony_console.php` (reusable `Command` base + `AsCommand` + `InputOption`/`InputArgument` + `Input`/`OutputInterface`, with a `run()` → protected `execute()`), `shims/console_runtime.php` (`ArgvInput`/`StdoutOutput`), `shims/react_eventloop_factory.php` (`Factory::create()` → `StreamSelectLoop`), `shims/safe_define.php`. `main-dnsd.php` / `main-ntpd.php` / `main-sql-serverd.php` drop from ~90-line ports to ~15 lines that just `(new Dnsd($logger))->run(...)` the staged real command class. Small `src/` change per command (event-listener / timer-callback trailing arg for strict AOT callback arity). teletext migrated onto the same three console shims (its local copies deleted). `React` / `ShareFsd` / `EcoSyslogd` stay ported — Symfony HttpKernel / `DatagramFactory` / Monolog respectively. |
| SQL remote service | **done** | `make sql-typephp` → `build/typephp/sql_serverd` (283 files). `project.sql-serverd.yml` = `project.ecosyslogd.yml` minus `SyslogLogger` plus the console shims + `stage/cmd/SqlServerd.php`; `main-sql-serverd.php` = `Security::init` + `AuthPluginFile::init` + `(new SqlServerd($logger))->run(...)`. Hosts `Services\Provider\SqlServer` over the Remote Provider Protocol (reuses `RemoteProvider\Client`/`Host` + pawl from ecosyslogd). **SQLite + PostgreSQL + MySQL/MariaDB** — the `Containerfile` adds `pdo_pgsql` + `pdo_mysql` to the stock-`pdo_sqlite` `php:8.4-cli-bookworm` base via `docker-php-ext-install` (+ `libpq-dev`); they load through the embed SAPI's `conf.d` scan. This was never a TypePHP limit — `tpc` doesn't touch PDO, it just links whatever `libphp` provides. 2-line `src/` change (`SqlServerd.php` timer-callback arity). Verified against live Postgres 16 + MariaDB 11 + `sqlite::memory:`: `ConnectionFactory` → `new \PDO(...)` → `BufferedCursor` over a `\PDOStatement` → incremental `fetchNext()`; `PgsqlCursor`'s server-side `DECLARE … CURSOR` / `FETCH n` paging; `PDO::MYSQL_ATTR_USE_BUFFERED_QUERY` now resolves. The `\PDO` / `\PDOStatement` signature hints compile and run natively. |
| 5 harden / CI | pending | dual-build CI job (patched vendor still runs interpreted - spot-checked OK), `TYPEPHP_STAGE` matrix, fold smokes into `smoke/`. |

### Reflection spike (Stage 1 pre-req) - PASSED

`ReflectionFunction($closure)->getNumberOfParameters()` returns the correct arity
(0, 2) at runtime on AOT-compiled closures, and an arity-branch on the result
works. Our codebase also has **no typed `->catch()` / `->otherwise()`**, so
`react/promise`'s `_checkTypehint()` type-matching path is dead code for us. The
plan's biggest risk is retired.

### Vendor patches (`packaging/typephp/vendor-patches/`)

All fourteen are semantic no-ops under the interpreter — verified with `php -l` on
every changed file plus functional checks (`_reflectCallable`, guzzle `Query`
round-trip). Applied to `build/typephp/stage/` at build time; `src/vendor` is
never touched.

| Patch | File(s) | Change |
|---|---|---|
| `0001-react-promise-reflection` | `react/promise` `Promise.php`, `functions.php` | Local can't retype across `ReflectionMethod`/`ReflectionFunction`; extracted `_reflectCallable()`. Also spelled out one deliberate `switch` fall-through. |
| `0002-react-event-loop-streamselect` | `react/event-loop` `StreamSelectLoop.php` | `streamSelect(&$read, &$write, …)` wrapped `stream_select()` in try/catch + a chained error handler + Windows `exceptfds`; TypePHP can't carry by-ref params across that lambda/try boundary. Reduced to a direct call with a variadic-arg error handler that swallows the EINTR warning (Linux-only build). |
| `0003-react-stream-util-funcgetargs` | `react/stream` `Util.php` | `Util::forwardEvents()` listener called `func_get_args()` → explicit `...$args`. |
| `0004-react-loop-callback-arity` | `react/stream` `Readable/WritableResourceStream.php`, `react/datagram` `Socket.php`, `Buffer.php` | 4 read/write stream callbacks declared `()`; the loop calls them with the stream resource → strict arg-count. Added ignored `$stream = null`. |
| `0005-guzzle-psr7-query-decoder` | `guzzlehttp/psr7` `Query.php` | `$decoder`/`$encoder` local is a string callable in some branches, a `Closure` in others → every branch a `Closure`. |
| `0006-react-socket-socketserver-ctor` | `react/socket` `SocketServer.php` | Facade constructor's `$server` local held `Unix/Fd/Tcp/SecureServer` in turn → assign each straight into `$this->server`. (Needed for `SocketServer::accept()`, which `TcpServer` calls.) |
| `0007-cboden-ratchet-ioserver-errhandler` | `cboden/ratchet` `IoServer.php` | `set_error_handler(function () {})` (0 args) around the dynamic-`decor`-property assignment → `function (...$a) {}`. |
| `0008-ratchet-ws-callback-arity` | `cboden/ratchet` `WsServer.php`, `ratchet/rfc6455` `MessageBuffer.php` | `MessageBuffer` calls its message/control callbacks with `($x, $this)`; the `WsServer` closures declared 1 param, the default control handler 0 → ignored trailing params. |
| `0009-react-child-process-pipe-retype` | `react/child-process` `Process.php` | `$stream` local held `Duplex/Writable/ReadableResourceStream` in turn when wiring child pipes → assign each straight into `$this->pipes[$n]`. |
| `0010-react-dns-switch-fallthrough` | `react/dns` `BinaryDumper.php`, `Resolver.php` | Two `switch` blocks with a bare `default:` (no `break;`) → explicit `break;`. |
| `0011-react-socket-connector-argswap` | `react/socket` `Connector.php` | Legacy 1-arg `new Connector($loop)` detection used `\func_num_args() <= 1` (tpc can't see the caller's arg count) → `$loop === null`. |
| `0012-react-dns-socket-timer-arity` | `react/dns` `CoopExecutor`/`TimeoutExecutor`/`TcpTransportExecutor`, `react/socket` `TimeoutConnector`/`HappyEyeBallsConnectionBuilder` | 0-arg `->then()` / `addTimer()` callbacks that the caller invokes with a value or `TimerInterface` → ignored trailing param. |
| `0013-react-socket-errhandler-arity` | `react/socket` `TcpConnector.php`, `UnixServer.php`, `FdServer.php` | 2-arg `set_error_handler(function ($_, $error) …)` → 4-arg (tpc calls PHP error handlers with all 4). `SocketServer.php`'s own :116 handler left as-is (patch `0006` already edits that file). |
| `0014-ratchet-pawl-callback-arity` | `ratchet/pawl` `Connector.php`, `WebSocket.php`, `ratchet/rfc6455` `MessageBuffer.php` | The **inbound pawl handshake path** (only exercised once a relay peer actually accepts): `Connector.php`'s post-handshake `$futureWsConn->promise()->then(function() …)` is resolved with the `WebSocket` → `+$mIgnoredConn = null`. `WebSocket.php`'s `MessageBuffer` message/control callbacks (declared 1 / 1 param) are called with `($x, $buffer)` → ignored trailing param, same shape as `0008` did for `WsServer`. `MessageBuffer::onData(string $data)` is registered as a `data` listener and pawl re-`emit()`s that event with `[$body, $stream]` (Connector.php:110) → `+$mIgnoredConn = null`. Without these, `sql-serverd` (and any pawl client — `ecosyslogd`, `dnsd`, `ntpd`) throws `ArgumentCountError` the moment the relay completes the WebSocket upgrade. |

### Rules learned (feed into Stage 4+)

- **PHP-Dom node classes segfault as parameter / return type hints.** A function
  or method whose signature declares `\DOMNode` / `\DOMElement` / `\DOMNodeList`
  (as a param type, `?T`, or return type) **segfaults at call time** when a real
  DOM object is passed through it — even though `instanceof \DOMNode`, property
  access (`->childNodes`, `->nodeName`, `->wholeText`), `\DOMXPath` and
  `\DOMDocument` *as hints*, and `\DOMXPath::query()` with a context node all
  work fine. Fix: drop the hint (keep it in PHPDoc so PHPStan is unaffected).
  Done in `Services/Provider/Teletext/ArticleExtractor.php` (7 signatures) for the
  `teletext_import` build.

- **`toString()` / `toInt()` / `toArray()` / `toBool()` are TypePHP reserved
  keyword-methods.** A project class (or interface) that defines
  `public function toString(): string` for its own purposes compiles, but a call
  to it — especially through an interface type — is lowered to an object->string
  cast and **segfaults** the binary if there is no `__toString` (and still
  segfaults if you add a delegating `__toString`). Fix is to rename the method
  (`toString` -> `asString` here, 19 files). Grep any new tree for
  `function to(String|Int|Array|Bool)\(` before adding it to `sources`.
- **`class_exists($name, false)` is always true for an AOT class** (registered
  at module init). Code that gates one-time init on `if (!class_exists($name, false)) { $name::init(); }`
  - `Security::_getAuthPlugins()`, `Vfs::getVfsPlugins()` - therefore never
  initialises. `main.php` / `main-sharefsd.php` call `AuthPluginFile::init($logger)`
  explicitly.
- **A shim implementing a vendor interface must match that interface's exact
  signatures.** `StderrLogger implements Psr\Log\LoggerInterface` was rejected
  for declaring `string|\Stringable $message` where the vendored psr/log has
  untyped `$message` — TypePHP enforces LSP strictly, including narrowing.
- **TypePHP enforces strict argument count on dynamic/closure calls.** Every
  event listener, timer callback and promise handler in the `main.php` port
  must declare *exactly* the parameters the emitter passes (or a by-value
  variadic). `react/datagram`'s `message` event carries `($data, $peer, $socket)`
  - a 1-param listener throws `ArgumentCountError`. Our existing `React.php`
  listeners mostly already match; audit each when porting.
- **`react/datagram`'s `Factory` eagerly builds a `react/dns` resolver in its
  constructor.** Bypass it: `new React\Datagram\Socket($loop, stream_socket_server('udp://IP:port', ...))`
  directly. `Socket`/`Buffer` need no DNS. (This is `main.php` code, not a vendor patch.)
- **`react/dns` / `react/cache` are not on the Econet path.** Listen addresses
  are IP literals; only the outbound `RemoteBridge` client and the Ratchet relay
  *clients* (Stage 4) can take hostnames.
- Closure **by-ref capture** `use (&$x)` compiles fine; only by-ref *parameters*
  in closures don't. Regular-method by-ref params are fine *except* when they
  must cross a try/catch or nested-closure boundary (patch `0002`).
- **A missing ext (`ldap`, …) is shimmable like `pcntl`.** A local `.stub.php`
  (no `@library-import`) declares `foo()` → tpc calls C++ `php_foo(...)` with
  ABI types (`string`→`Str`, `int`→`Int`, `bool`→`Bool`, `array`→`Array`,
  `mixed`→`Var`); the `.cc` goes in `sources`, its libs in `link-libs:`. Where
  the real ext hands back an opaque object (`\LDAP\Connection`), ship a
  compile-only class holding an int handle and `newObject("LDAP\\Connection")`
  from C++ — `?\LDAP\Connection` hints and `instanceof` then compile unchanged.
  Default params in a local stub work (`= ""`, `= 0`). See `shims/ldap_*`.
- **A missing *vendor package* often needs no C at all — reimplement it in
  compile-only PHP.** The tpc libphp has `curl`, `hash`/`hash_hmac`, `openssl`,
  `SimpleXML`, `date`. `shims/aws_s3_client.php` replaces `aws/aws-sdk-php`'s
  `S3Client` with ~250 lines doing SigV4 + `curl_*` + `simplexml_load_string`,
  as an ordinary bracketed-multi-namespace source file (not a `.stub.php`).
  Same non-autoload rule keeps the real SDK for interpreted runs. Watch the
  known traps: no `\SimpleXMLElement` / `\DOMNode` in a signature (segfault),
  `constant()`-style ext constants (`CURLOPT_*`) resolve at run time which is
  fine, and `\DateTimeImmutable` return values compile OK.

## Goal

Get `make typephp` to produce a native binary whose **hot path runs compiled C++
end to end**:

```
UDP socket  ->  react/datagram  ->  StreamSelectLoop  ->  PacketDispatcher
            ->  Encapsulation\* ->  ServiceDispatcher ->  Services\Provider\*
            ->  reply EconetPacket -> react/datagram -> UDP socket
```

plus the same for the Ratchet WebSocket listeners (BBC-micro bridge, remote-socket
and remote-provider relays). Today the encapsulation and provider *classes* compile
(see `README.md`) but nothing calls them without the interpreted ReactPHP loop, so
the binary only runs a smoke-test `main()`.

Non-goals: the Symfony admin UI (`Admin/`, `ShareFs/` — separate problem), and the
`Command/*` CLI wrappers (replaced by `main.php`).

## The runtime-model shift

Two things change versus the current "compile leaf classes" approach:

1. **`main.php` becomes a real port of `Command\React::MainLoop()`**
   (`src/include/classes/Command/React.php:116`). `bin` mode allows a `main()` that
   blocks: it builds `new StreamSelectLoop()`, wires the services, calls
   `$loop->run()`, and returns `void` when the loop exits. `pcntl_fork()` for
   `daemonize()` is already shimmed.

2. **No Composer autoloader at runtime.** Every class the loop touches must be in
   `project.yml` `sources` (or a shim). Composer `files` autoloads
   (`react/promise/src/functions_include.php`, which runs top-level code — illegal
   in `bin` mode) are replaced by listing the real file (`functions.php`) directly.
   The loop-backend `Factory` (`class_exists('EvLoop')…`) is not compiled;
   `main.php` instantiates `StreamSelectLoop` directly.

## What was already verified (probe builds, 2026-08-29)

Ran throwaway `tpc` builds against the real `src/vendor` tree. Findings that shape
the plan:

| Construct | Result | Notes |
|---|---|---|
| `new \SplQueue()`, `new \SplObjectStorage()`, `->attach/->enqueue/->count` | **compiles + links** | used by `event-loop/Tick/FutureTickQueue` and all of Ratchet's connection sets |
| Closure **by-reference capture** `function() use (&$x)` | **compiles + links** | this is most of `react/promise`'s combinators — NOT a blocker |
| Spread into a callable variable `$listener(...$args)` | **compiles + links** | `Evenement\EventEmitterTrait::emit()` |
| Traits | supported | evenement, guzzle `MessageTrait`/`StreamDecoratorTrait`, Ratchet `CloseResponseTrait` |
| Closure **by-reference parameter** `function(&$x)` | **unsupported** (hard) | `INCOMPATIBLE_PHP_FEATURES.md`; but **grep of react-core + Ratchet + psr7 found zero** — only regular methods use `&$param` (`StreamSelectLoop::streamSelect`), which *is* supported |
| `func_get_args()` | supported but **does not relax arg count** | 3 sites in react-core, 1 in Ratchet/http/psr7 — each needs a look |
| `ReflectionFunction`/`ReflectionMethod` on user closures | **unproven, likely a blocker** | `react/promise` `Promise::call()` + `functions.php` use it for callback-arity / typehint inspection — see Stage 1 |

First hard stop hit: `react/promise/src/Promise.php:266` re-assigns `$ref` from
`ReflectionMethod` to `ReflectionFunction` (scalar/object retype — same class of
fix as the `Vfs/DirectoryEntry` port). Behind it sits the Reflection question.

## Vendor surface on the critical path

From `src/vendor`, non-test PHP LOC:

| Tier | Packages | ~LOC | Needed for |
|---|---|---|---|
| 1 — loop core | evenement (193), react/event-loop **StreamSelectLoop path only** (~1.2k of 2.8k), react/promise (1.2k), react/stream (1.7k) | **~4.3k** | anything event-driven |
| 2 — datagram + TCP | react/socket (3.3k), react/datagram (0.5k), react/dns (3.0k — *maybe skippable*), react/cache (0.4k) | **~4–7k** | AUN UDP, TCP relays, Piconet serial |
| 3 — WebSocket + HTTP | cboden/ratchet (3.0k), ratchet/rfc6455 (2.0k), react/http (5.9k), guzzlehttp/psr7 (8.4k), psr/http-message + psr/http-factory + fig util + getallheaders (1.6k) | **~21k** | websocket bridge, both relays |

react/dns is only reached when a hostname (not an IP:port) is passed to
`react/socket`'s `Connector`. AUN listens/sends on configured `IP:port` literals,
and the relays connect to configured hosts — check whether any of those are
hostnames. If not, `main.php` can use `TcpConnector`/`TcpServer` directly and
`react/dns` + `react/cache` drop out of Tier 2 entirely.

## Where the vendored code lives

Do **not** edit files under `src/vendor` (composer would clobber them, and it
muddies `git status`). Instead:

- Add `packaging/typephp/vendor-patches/` holding unified diffs, applied by
  `build-typephp.sh` into the staged tree it already builds for template
  pre-compilation (mirror the phar build's staging). Keep each patch tiny and
  annotated with the upstream file + reason.
- Or, if patches pile up past ~10 files, fork the 2–3 offending packages into
  `packaging/typephp/vendor-src/` and point `sources` there. Decide at the end of
  Stage 1 based on the patch count.

`project.yml` `sources` gains the vendor dirs; `build-typephp.sh` gains a
"stage + patch" step before it invokes `tpc`.

---

## Stage 0 — scaffolding — DONE

`build-typephp.sh` now:
- stages `src/vendor/{evenement,react,ratchet,cboden,guzzlehttp,psr,fig,ralouphie}`
  into `build/typephp/stage/vendor/` and applies `vendor-patches/*.patch` to that
  copy — **only when the active project file references `build/typephp/stage/`**,
  so the default `make typephp` is untouched;
- takes `TYPEPHP_PROJECT=<repo-relative path>` (default `packaging/typephp/project.yml`).

`src/vendor` is never modified. `project.react.yml` + `smoke/*.php` +
`project.smoke-*.yml` are the dev harness for Stages 1-3; `project.yml` stays the
shipped one and grows only when a stage lands end to end.

The `TYPEPHP_STAGE={0..4}` CI selector is deferred to Stage 5.

## Stage 1 — event loop core — DONE

In `sources` (via `project.react.yml`): `evenement/evenement/src`,
`react/promise/src` (minus `functions_include.php`), `react/event-loop/src`
`{TimerInterface,LoopInterface,SignalsHandler,StreamSelectLoop}.php` + `Timer/` +
`Tick/`, `react/stream/src`. `Factory.php` + `Ext*Loop.php` excluded.

Patches needed: `0001` (promise reflection retype + one switch fall-through),
`0002` (StreamSelectLoop `streamSelect` simplification), `0003` (Util
`func_get_args`). `pcntl_async_signals` stays absent -> `pcntlPoll` mode, as
designed.

`smoke/loop.php` -> `project.smoke-loop.yml` -> native binary: periodic timer
fires 10x in ~1.05 s, one-shot stops the loop. **PASS.**

## Stage 2 — datagram + TCP sockets — DONE

Added to `project.react.yml`: `react/socket/src` `{ConnectionInterface,
ServerInterface, ConnectorInterface, Connection, TcpServer, UnixServer,
TcpConnector, UnixConnector, FixedUriConnector, LimitingServer}.php` +
`react/datagram/src`. **`react/dns`, `react/cache`, `SocketServer`, `Connector`,
`Secure*`, `HappyEyeBalls*`, `StreamEncryption`, `TimeoutConnector`, `FdServer`
excluded** — none are on the Econet path.

Patch needed: `0004` (loop-callback arity — 4 zero-arg stream handlers get an
ignored `$stream` param). `stream_socket_server` / `stream_socket_recvfrom` /
`stream_set_blocking` all worked with no `.cc` shim.

`smoke/datagram.php` -> `project.smoke-datagram.yml` -> native binary: binds a UDP
socket via `React\Datagram\Socket` (Factory bypassed to avoid its eager
`react/dns` resolver), round-trips one packet to a client socket, payload
verified. **PASS.**

## Stage 3 — wire the Econet critical path through the native loop — DONE

`project.react-app.yml` merges `project.yml`'s ~153 domain files with the Stage
1-2 vendor set (`bin` mode); `smoke/econet.php` is the `main()`. **189 files
compile + link into a 13 MB native binary.**

Running it: boots `config`, a `StderrLogger`, `EncapsulationTypeMap::create()`,
`PacketDispatcher::create($typeMap, $loop)`,
`ServiceDispatcher::create($logger, [new EcoSyslog($logger)])`; binds the AUN UDP
socket with `new React\Datagram\Socket($loop, stream_socket_server('udp://127.0.0.1:0', ...))`;
enters `StreamSelectLoop::run()`; receives one real UDP packet and drives it
`AunHandler::receive()` -> `ServiceDispatcher::inboundPacket()` ->
`EcoSyslog::unicastPacketIn()`, which decodes the Econet payload (severity byte +
text) and logs it. Every layer is compiled C++.

The exit criterion was a `*I AM` / load / save round trip; that needs
`FileServer`'s full constructor graph wired by hand (auth, VFS, the Symfony
container builds it today) and is deferred to a Stage 3b. `EcoSyslog` proves the
same architecture end to end - inbound UDP -> decode -> dispatch -> provider -> reply
path - with a one-dependency provider.

### What it took

| Item | Resolution |
|---|---|
| `config.inc.php` = 159 `safe_define()` calls (executable statements, illegal at file scope) | `build-typephp.sh` rewrites them to `const` declarations in `build/typephp/stage/config_defines.php` every build; the project lists that file. `config.php` itself joined `sources` and compiled unchanged (`constant()` / `defined()` / `parse_ini_file()` all work). |
| `ServiceDispatcher` needs a real `Psr\Log\LoggerInterface` (Monolog is out of scope) | `shims/StderrLogger.php` - `implements Psr\Log\LoggerInterface`, one stderr line per record. **Signatures must match the vendored psr/log exactly** (untyped `$message`, `array $context = array()`) - TypePHP enforces LSP strictly and rejected a `string\|\Stringable` narrowing. |
| `React\Datagram\Factory` eagerly builds a `react/dns` resolver in its constructor | bypassed - `main()` calls `stream_socket_server()` + `new React\Datagram\Socket($loop, $stream)` directly. |
| **`toString()` is a TypePHP reserved keyword-method.** 9 project classes + `EncapsulationInterface` declared `public function toString(): string` as a debug-dump. Calling it through the interface **segfaulted** the compiled binary (TypePHP lowered `$pkt->toString()` to an object->string cast; no `__toString` -> crash; adding `__toString` that delegates also segfaulted). | Renamed `toString()` -> `asString()` across 19 files (9 classes, 1 interface, ~8 internal callers, ~9 test callers). Pure rename, `toString` is not a PHP magic method. phpstan clean, full suite (3451) green. |
| callback arity | every timer / `message` / promise closure in `smoke/econet.php` declares exactly the emitter's args (`TimerInterface $t`; `string $data, string $peer, Socket $s`). No surprises. |

### Stage 3b — DONE

Turned out there is **no Symfony DI graph for the daemon** - `src/filestored`
just does `new FileServer($oLogger)` etc., every provider taking only a
`Psr\Log\LoggerInterface`. So `smoke/econet.php` wires the exact `src/filestored`
list directly. `FileServer::__construct` -> `Cli`/`UserAdmin`/`FileHandles`/
`Catalog`/`vfsInit()` all compile; `Vfs::init()` and `Security::_getAuthPlugins()`
build class names as strings and `$class::init()` on them - dynamic static calls,
which route through the Zend fallback at boot (not the packet path).

One runtime fix in the smoke: `EncapsulationTypeMap::getType()` calls
`WebSocketMap::ecoAddrToSocket()` on **every** outbound packet, which logs
through `WebSocketMap::$oLogger`; that static is unset unless a websocket service
ran, so `main()` must call `WebSocketMap::init($oLogger)` even with no websocket.

Result: a synthetic `*I AM SYST` FS packet drives the full request/reply cycle
natively (decode -> OSCLI -> login -> auth -> reject reply -> outbound AUN
encapsulation + retransmit). The login is *rejected* (no users file in the
container) but every line of the FileServer path executed compiled. A positive
`*I AM` + load/save would just need a `users.txt` + a VFS root mounted into the
run - not a code problem.

Still deferred: `piconetService()` (needs a serial device) and
`remoteBridgeService()` (`RemoteBridge/ServerHandler` uses the excluded
`React\Socket\SocketServer` - swap for `TcpServer` or add + patch it). Neither
blocks Stage 4.

## Stage 4 — Ratchet WebSocket — DONE

**Much smaller than the ~21k-LOC estimate: `cboden/ratchet` does not use
`react/http` at all.** It parses the HTTP upgrade with `guzzlehttp/psr7`
directly and does WS framing with `ratchet/rfc6455`. So Stage 4 =
`psr/http-message` + `psr/http-factory` + `fig/http-message-util` +
`guzzlehttp/psr7` + `ratchet/rfc6455` + `cboden/ratchet` (no react/http, no
Symfony). `project.react-ws.yml` adds those; **239 files compile + link** into
one native binary alongside everything from Stage 3b.

`WebSocket/Handler.php` (`implements Ratchet\MessageComponentInterface`) - the
original blocker from `README.md`'s 5-file list - **compiles, links and runs
natively** (see "runtime proof" below).

### What it took

| Item | Resolution |
|---|---|
| `ralouphie/getallheaders/src/getallheaders.php` - top-level `if (!function_exists()) { function … }` | excluded from `sources`. It's the Apache `getallheaders()` polyfill; Ratchet builds requests from raw socket bytes, never from superglobals, so it's dead. |
| `guzzlehttp/psr7/src/Query.php` `parse()`/`build()` - `$decoder`/`$encoder` local is a string callable in some branches, a `Closure` in others | patch `0005-guzzle-psr7-query-decoder` - every branch a `Closure`. Equivalent. |
| `guzzlehttp/psr7/src/{StreamWrapper,InflateStream}.php` - `$options` array->int retype | excluded. Client-side response-body helpers (stream-wrapper registration, gzip inflate); not on the WS server path. |
| `react/http/*` | dropped entirely - not referenced by Ratchet. |
| `cboden/ratchet` `Session/`, `Wamp/`, `Http/Router.php`, `Http/OriginCheck.php`, `Server/FlashPolicy.php` | excluded - optional Ratchet features (Symfony Session/Routing deps), not used here. |
| `react/socket/src/{SocketServer,SecureServer}.php` - retype across server subclasses | left out of `sources`; `IoServer::factory()` references `SocketServer` but we build the listener with `new TcpServer(...)` in `main()` and never call `factory()`. |

### Runtime proof — done

`main.php` builds `new IoServer(new HttpServer(new WsServer($oWsHandler)), new TcpServer(…), $loop)`. Against the native `aun_filestored`: a raw-socket client sends `GET / HTTP/1.1` + `Upgrade: websocket`, the server answers `101 Switching Protocols` with a correct `Sec-WebSocket-Accept` (and `X-Powered-By: Ratchet/0.4.4`), and a masked text frame `{"type":"status"}` is decoded by the rfc6455 `MessageBuffer` and reaches compiled `WsServer::onMessage` -> `WebSocket\Handler::onMessage` -> `JsonPacket::decode` without the connection dropping. `nm` shows `php_homelan__filestore__websocket__handler__on{open,close,message}` as AOT functions, not Zend stubs.

`RemoteSocket/RelayServer.php` + `RemoteProvider/RelayServer.php` are in `sources` (never ignored for `filestored`) and wired in `main.php` gated on `remote_socket_relay_enabled` / `remote_provider_relay_enabled`; their `on{open,message,close,error}` + handlers are compiled symbols too.

## Stage 6 — RemoteBridge client — DONE

The bridge-to-bridge TCP link (one filestored connecting to another as a
peer). **All six `RemoteBridge/*.php` classes compile and are wired in
`main.php`**, gated on `remote_bridge_enabled`:
`RemoteBridgeMap::init()` -> a `ServerHandler` per `SERVER` map entry -> one
`ClientHandler` for all `CLIENT` entries.

The plan's "skip react/dns, resolve synchronously" was overtaken by events -
Stage 8's work brought the **full** `React\Socket\Connector` facade +
`react/dns` + `react/cache` into `sources` (patches `0010`-`0012`), so
`ClientHandler` uses the real async `new \React\Socket\Connector(['tls' => false], $loop)`
unchanged - hostname bridge targets resolve natively.

Three timer-callback arities were the only fix needed (our code, so `src/`
edits, same shape as Piconet/pawl): `ClientHandler::scheduleReconnect()`'s
`addTimer(..., function () ...)` and `Connection`'s two
`addPeriodicTimer(..., function (): void ...)` (ping + idle) all get
`?TimerInterface $oTimer = null` - `StreamSelectLoop` invokes timer callbacks
with a `TimerInterface`, and strict arg-count rejected the 0-arg form (it
surfaced at run time as a recovered `ArgumentCountError` spin in
`main.php`'s loop-recovery wrapper).

Verified: native `aun_filestored -c <conf>` with `remote_bridge_enabled=true`
and a `CLIENT 127.0.0.1:19999 <secret> 5,6` map entry (nothing listening) logs
`RemoteBridge: connecting to 127.0.0.1:19999` -> `failed to connect ... Connection
refused` -> `will retry ... in 5 seconds` and the loop stays up - the whole
Map-parse -> ClientHandler -> Connector -> TcpConnector -> connect -> backoff
path runs compiled.

---

*(original plan below)*

The last daemon feature not yet on a runtime path. `remote_bridge_enabled`
defaults **false**, so `main.php` never calls a `remoteBridgeService()`.

**Already transpiles.** `RemoteBridge/{ClientHandler,Connection,ServerHandler,Map,BridgePacket,Admin}.php`
are all in `sources` and convert with no fatal today — including
`ClientHandler`'s `$aState = &$this->aEntryState[$sKey]` refs and `Connection`'s
`switch`. What is missing is (a) a compiled *connector* and (b) the wiring +
runtime exercise.

### The blocker: `new \React\Socket\Connector`

`ClientHandler::connect()` builds `new \React\Socket\Connector($this->oLoop)` —
the facade connector, **not** in `sources`. Including it drags in
`DnsConnector` + `HappyEyeBallsConnector(Builder)` + `TimeoutConnector` +
`SecureConnector` + `StreamEncryption` and the whole of `react/dns` (Config,
`Resolver\Factory`, the Query executor chain, the wire Protocol parser, Model)
plus `react/cache`. `react/dns` alone is ~3k lines with ~10 by-ref sites and its
own retype/idiom surface.

**Decision — resolve synchronously, skip `react/dns`.** In `ClientHandler`
(our code, not a vendor patch):

1. Replace `new \React\Socket\Connector($this->oLoop)` with
   `new \React\Socket\TcpConnector($this->oLoop)` (compiled) —
   optionally wrapped in `new \React\Socket\TimeoutConnector($tcp, 10.0, $this->oLoop)`
   for a bounded connect (add `TimeoutConnector.php` to `sources`; it is small
   and by-ref-free in the parts we use).
2. Before building the `tcp://…` URI, turn `$aEntry['host']` into an IP:
   `$sIp = \filter_var($sHost, FILTER_VALIDATE_IP) ? $sHost : \gethostbyname($sHost);`
   `gethostbyname()` is `ext-standard`, blocking — but this runs at
   connect/reconnect time behind the 5→300 s backoff, never on the packet path.
   If it returns the hostname unchanged (lookup failed) log and let the connect
   fail into `scheduleReconnect()`.

`tls://` bridge links are not currently used (`remotebridge.txt` entries are
`tcp`); if they ever are, that is a separate `SecureConnector` + `ext-openssl`
task.

### Work items

| # | Item | Notes |
|---|---|---|
| 1 | `ClientHandler`: `Connector` → `TcpConnector` + `gethostbyname()` prefix | our code; ~10 lines. Keep the `->connect(uri)->then(...)` shape. |
| 2 | `ClientHandler` by-ref array-element refs (`$aState = &$this->aEntryState[$sKey]`, `use (&$aState)`) | transpiles now, but confirm the generated C++ *compiles and the write-back works* once linked. If not: port to value + explicit `$this->aEntryState[$sKey] = $aState;` write-back, and have the success closure update `$this->aEntryState[$sKey]['delay']` directly rather than through `&$aState`. |
| 3 | Callback arity | `scheduleReconnect()`'s `addTimer($d, function () use ($sKey) {…})` is 0-arg → `function (?TimerInterface $t = null)` (same fix as Piconet). Socket-event closures: `data` → `(string $d)`, `close` → `()`, `error` → `(\Exception $e)` — already correct in the source, just verify. |
| 4 | `Connection.php` `switch ($this->sState)` at ~146 | check every non-empty `case` terminates (`break`/`return`); we have hit this before. `fn(...)` arrows at 167/474 are fine. |
| 5 | `ServerHandler` | uses `new \React\Socket\SocketServer(...)` — compiled (patch `0006`). Verify its `connection`/`error` event closures' arity. |
| 6 | Wire into `main.php` | add `aun_filestored_start_remote_bridge($oLoop, $oServices, $oDispatcher, $oLogger)` gated on `config::getValueAsBool('remote_bridge_enabled')`: `RemoteBridgeMap::init($oLogger)`, then `new ServerHandler(...)->start($aEntry, $oLoop)` per server entry and `new ClientHandler($oLogger, $oServices, $oDispatcher, $oLoop)->start()`. |
| 7 | `sources` additions | `react/socket/src/TcpConnector.php` (already in), `TimeoutConnector.php` (new, if used). Nothing else. |

### Test (no peer, like Piconet)

- `smoke/remotebridge.php`: a `main()` that force-enables `remote_bridge_enabled`,
  points `remote_bridge_map_file` at an inline map with one `CLIENT 127.0.0.1:9999`
  entry (nothing listening), runs the loop ~6 s. **Pass = the connect fails and
  `ClientHandler` logs the 5 s / 10 s / … backoff**, loop stays up — the whole
  connector + framing setup path executed in compiled code.
- Optional stronger check: also start an in-process `TcpServer` on a random port,
  add a matching CLIENT entry, and confirm `Connection`'s auth handshake bytes
  (`HELLO …`) are written — proves `Connection::onData`/the state machine runs
  natively, not just the connect.

Exit: with `remote_bridge_enabled=true` and an unreachable peer the native
binary logs the reconnect backoff and stays up; with a local `TcpServer` peer it
gets through `Connection`'s handshake.

### Effort

~0.5 day. No `react/dns`, so no new vendor patches expected beyond possibly one
for the `ClientHandler` by-ref write-back (item 2) — and that is *our* code, so
it is a source edit, not a `vendor-patches/` entry.

## Stage 7 — native `sharefsd` — DONE

`make sharefs-typephp` → `build/typephp/sharefsd` (193 files, `Build
successful`). Runs:

```
[INFO] sharefsd (TypePHP native build) starting
[INFO] Security: Login for SVC using authplugin AuthPluginFile
[INFO] core: event loop is React\EventLoop\StreamSelectLoop
[INFO] Freeway: listening on 127.0.0.1:32770
[INFO] AccessPlus: listening on 127.0.0.1:32771
[INFO] ShareFS-data: listening on 127.0.0.1:49171
[INFO] core: entering primary loop
```

**Zero new vendor patches.** `ratchet/pawl` (3 files), the ShareFS domain
(`FreewayHandler`, `AccessPlusHandler`, `ShareFsHandler`, `ShareList`,
`ShareAuthTable`, `Share`, `RiscOsMeta`, `Messages/*` — no reserved `to*()`
methods, no framework `extends`), and the un-`ignore`d `RemoteSocket/Client.php`
+ `RemoteSocket/RelayedUdpTransport.php` all compiled straight through on top of
the react/dns + Ratchet groundwork.

### What it took

| Item | Done |
|---|---|
| `build-typephp.sh` | `TYPEPHP_OUT` env (default `aun-filestored`) → `-o build/typephp/$TYPEPHP_OUT` |
| `Makefile` | `sharefs-typephp:` target (`TYPEPHP_PROJECT=…project.sharefsd.yml TYPEPHP_OUT=sharefsd`) |
| `packaging/typephp/main-sharefsd.php` | de-dynamised `Command\ShareFsd::MainLoop()` — args, `Vfs`/`Security`/`ShareList` init, service-identity login, 3 UDP services via `stream_socket_server` + `React\Datagram\Socket`, Freeway-broadcast + housekeeping timers. No admin. |
| `packaging/typephp/project.sharefsd.yml` | `project.yml` minus the Econet-only dirs (`Services`, `Aun`, `Encapsulation`, `WebSocket`, `Piconet`, `RemoteProvider`, `RemoteBridge`, `React`); plus `ShareFs` (minus `ShareFs/Admin` + the four `*Admin.php` — Symfony/`EncapsulationAdminInterface`), `ratchet/pawl/src/{Connector,WebSocket,functions}.php`, and `RemoteSocket/Client.php` + `RelayedUdpTransport.php` un-`ignore`d |
| `src/include/classes/RemoteSocket/Client.php` (our code) | `new Connector($loop)` → `new Connector($loop, new \React\Socket\Connector(['tls' => false], $loop))` (pawl over `ws://`, no `SecureConnector`/openssl); `'message'` listener `(MessageInterface $m)` → `+mixed $mConn = null` (pawl emits `[$msg,$conn]`); `'close'` listener `()` → `+3 ignored params` (`[$code,$reason,$conn]`); reconnect timer `function ()` → `function (?TimerInterface $t = null)`. phpstan clean, 3451 tests pass. |
| `main-sharefsd.php` + `main.php` | explicit `AuthPluginFile::init($logger)` — see the `class_exists($name, false)` rule above |

### Relay client — verified against a live relay

The `RemoteSocket` relay *client* path (pawl → `ws://` relay) is compiled and
wired, off by default. **Verified end to end (2026-09)** with the
structurally-identical `dnsd`: native `aun_filestored`
(`remote_socket_relay_enabled`, secret set) + native `dnsd`
(`dns_remote_socket_relay_address` pointed at it, matching secret) → `dnsd` logs
`RemoteSocket\Client: registered UDP/53` and `filestored` logs
`RemoteSocket: registered udp.53` — the full pawl connect → `hello <secret>` →
`hello_ok` → `register` → `register_ok` exchange runs between two tpc binaries.
`sharefsd` / `ntpd` use the same `RemoteSocket\Client`.

(The ShareFS admin UI is native — see the Stage 10d follow-up.)

---

*(original plan below, kept for reference)*

A second daemon, `sharefsd` (the ShareFS / Acorn Level-4 / AccessPlus / Freeway
services), as its own native executable `build/typephp/sharefsd`, built by a new
`make sharefs-typephp`. It runs the same ReactPHP stack, so **the vendor work is
already done** — Stage 7 is a new entry point + the ShareFS domain code + one
small new vendor package (the WebSocket *client*, `ratchet/pawl`).

### What is reused as-is

- `build/typephp/stage/` staging + **all 12 `vendor-patches/`** (react loop,
  sockets, datagram, dns, promise, child-process, guzzle/psr7, rfc6455, Ratchet).
- `build-typephp.sh` (already `TYPEPHP_PROJECT`-parameterised), the
  `config_defines.php` generator, `shims/` (`StderrLogger`, `pcntl_posix`,
  `signals`, `deprecation_contracts`).
- The entire ReactPHP + react/dns + Ratchet **server** `sources` block from
  `project.yml` — `project.sharefsd.yml` copies it verbatim (the Ratchet server
  classes are harmless dead weight, or trim `cboden/ratchet` to just what pawl
  needs).

### What is new

| # | Item | Notes |
|---|---|---|
| 1 | `build-typephp.sh`: `TYPEPHP_OUT` env (default `aun-filestored`) → `-o build/typephp/$TYPEPHP_OUT` | today the output name is hard-coded (line ~141). One-line change. |
| 2 | `Makefile`: `sharefs-typephp:` target | `PODMAN=… OUTPUT_DIR=… TYPEPHP_PROJECT=packaging/typephp/project.sharefsd.yml TYPEPHP_OUT=sharefsd packaging/typephp/build-typephp.sh` |
| 3 | `packaging/typephp/main-sharefsd.php` | de-dynamised port of `Command\ShareFsd::MainLoop()`: `new StreamSelectLoop()`; optional `new RemoteSocket\Client($loop,$logger,$url,$secret)` + `->connect()` gated on `sharefs_remote_socket_relay_enabled`; `freewayService` / `accessPlusService` / `shareFsDataService` (each a `react/datagram` UDP socket on ports 32770 / 32771 / 49171, or a `$relayClient->getTransport($port)` when the relay is on); the Freeway-broadcast + housekeeping periodic timers. **No admin, no ServiceDispatcher/PacketDispatcher.** Same `stream_socket_server` + `new React\Datagram\Socket(...)` bypass of `Datagram\Factory` as `filestored`'s `main.php`. Callback arities audited (`FreewayPacket::DEFAULT_BROADCAST_INTERVAL` timer etc.). |
| 4 | `ratchet/pawl` → `sources` | 3 files (`Connector.php`, `WebSocket.php`, `functions.php`); exclude `functions_include.php` (top-level `if(!function_exists) require`). Grep shows **no by-ref params, no `func_num_args`** — expected to compile clean. If `Pawl\Connector::__construct` has a legacy arg-swap like `React\Socket\Connector`, patch it the same way (`0013`, ≈ `0011`). It builds on `React\Socket\Connector` (compiled, Stage 6) + `rfc6455` `ClientNegotiator` (compiled, Stage 4). |
| 5 | Un-`ignore` `RemoteSocket/Client.php` + `RemoteSocket/RelayedUdpTransport.php` | in `filestored`'s `ignore` only because `Client` uses `Ratchet\Client\Connector` (now compiled via pawl) and `RelayedUdpTransport extends Evenement\EventEmitter` (now compiled since Stage 1). Both should transpile now — verify. `RelayedUdpTransport implements React\Datagram\SocketInterface` (compiled). `Client::connect()` uses the legacy `new Connector($loop)` form → change to `new Connector([], $loop)` or rely on the pawl patch. |
| 6 | `ShareFs/` domain probe | `project.sharefsd.yml` `sources`: `ShareFs` (minus `ShareFs/Admin` — Symfony), `RemoteSocket` (minus `RelayServer.php` — server side), `Vfs`, `Authentication`, `Messages`, `Encapsulation` (if the ShareFS packets need it), `config.php`, `config_defines.php`, the shims. Then run the same **iterative probe loop** as Stages 1-6: add dir → `make sharefs-typephp` (dry) → read the first `Fatal error:` → port it or `ignore` it. Expect the familiar set: reserved `to*()` methods (rename → `asString`/etc., precedent set), int→string retypes, `switch` fall-throughs, 0-arg timer/promise closures. `ShareFs/` is ~15 files; budget 3-6 small ports. |

### Test (no peer, like Piconet / RemoteBridge)

With `sharefs_remote_socket_relay_enabled` **false** (the default) `sharefsd` is
fully self-contained — three local UDP listeners, no outbound connection.
**Pass = the native binary binds 32770 / 32771 / 49171, enters the loop, stays
up, and the Freeway broadcast timer fires** (log line every
`FreewayPacket::DEFAULT_BROADCAST_INTERVAL`).

Stronger check: run a native `filestored` binary (with `remote_socket_relay_enabled`)
in the same container, set `sharefs_remote_socket_relay_address` to its
`127.0.0.1:8091`, and confirm `sharefsd`'s pawl WebSocket client connects and
completes the Remote-Socket auth handshake — exercising `ratchet/pawl` +
`RemoteSocket\Client` + `RelayedUdpTransport` end to end natively.

### Effort

~1 day. `ratchet/pawl` is tiny and idiom-free; the ShareFS domain is small and
follows patterns already ported; `main-sharefsd.php` mirrors the existing
`main.php`. Likely 0-1 new vendor patches (only if pawl's constructor needs the
`0011` treatment). The bulk is the domain probe loop and wiring `main-sharefsd.php`.

## Teletext fetch scripts — native `teletext_import` — DONE

`make teletext-typephp` → `build/typephp/teletext_import` (~32 files), **one**
binary that dispatches on `argv[1]` ∈ `news` / `teefax` / `tvguide` / `weather` /
`webfax`. Plain CLI — no ReactPHP, no event loop — so it shares only the
`stage/config_defines.php` step with the daemons, not the ReactPHP vendor set.

Replaces the five `src/util/*-import` Symfony Console `Application` wrappers:

- `main-teletext.php` — the `main(int, array)` entry; parses `-c`/`--config`
  itself (`\define('CONFIG_CONF_FILE_PATH', …)` before the first config lookup)
  and `match($argv[1])`-dispatches to a Runner.
- `teletext/shim-console.php` — minimal `Symfony\Component\Console\*` shims
  (`Command` base + constants, `#[AsCommand]`, `InputOption` constants,
  `InputInterface` / `OutputInterface`) so the `Command\*Import` classes compile
  unchanged. Multi-namespace file — compiles fine.
- `teletext/shim-safe-define.php` — a bare `function safe_define()` (the
  interpreted app has it in `system.inc.php`, which is not on the compile path).
- `teletext/cli.php` — `ArgvInput` (a tiny `--name value` / `--name=value` /
  `-c dir` parser) + `StdoutOutput` (strips `<info>`/`<error>` style tags).
- `teletext/runners.php` — 5 `*ImportRunner` subclasses exposing each command's
  `protected execute()` as a public `run()`.

`build-typephp.sh` grew a **`stage/cmd/`** step (gated on the project file
referencing `build/typephp/stage/cmd/`): it copies `src/include/classes/Command/*.php`
with `sed` stripping the file-scope `include_once … system.inc.php` (illegal in
`bin` mode) and rewriting `$http_response_header[N] ?? null` → `null` (the magic
local is a tpc **fatal**). `project.teletext.yml` lists the 5 stripped copies.

**One `src/` edit:** `Services/Provider/Teletext/ArticleExtractor.php` — the
`\DOMNode` / `\DOMElement` / `\DOMNodeList` parameter and return **type hints**
were dropped (kept in PHPDoc). tpc segfaults at call time when a DOM object
crosses a signature typed as one of those internal classes; `\DOMXPath` /
`\DOMDocument` hints, `instanceof`, and all property/method access are fine. See
Rules learned. PHPStan level 10 clean, 62 teletext unit tests green.

Verified end to end (podman, `--network=host`): `news --feed bbc -c /conf`
fetches all 10 BBC section feeds, fetches each article, runs the `DOMDocument` +
`DOMXPath` `ArticleExtractor`, composes with `NewsPageComposer`, and writes 280
1024-byte `.dat` teletext pages, installing into `<teletext_store_dir>/2`.
`weather` composes 10 pages from the `simplexml` BBC forecast feed. `teefax` /
`webfax` (`PharData` tarball extract) and `tvguide` (`simplexml` XMLTV) reach
their network boundary cleanly.

## Stage 9 — Acorn disk-image VFS plugins — DONE

The ADFS/DFS/MDFS/AFS VFS plugins (`Vfs/Plugin/{AdfsAdl,AdfsHD,DfsSsd,Mdfs,AFS,AfsImg}.php`)
`use HomeLan\Retro\Acorn\Disk\{AdfsReader, AdfsReaderHD, DfsReader, L3fsReader,
MdfsReader, MdfsWriter}` from the Composer packages `homelan/acorn-disk`,
`homelan/l3fsreader`, `homelan/mdfs-disk-reader` — 6 files, ~2.6k lines, in
`src/vendor/homelan/*/src/`. Until now those were **not in any `sources`**, so the
plugins transpiled but the readers were absent — a real deployment serving from a
`.adl`/`.ssd`/`.mdfs` image would have crashed.

Added the 6 files to `project.yml` + `project.sharefsd.yml` + the derived
`project.{dnsd,ntpd,ecosyslogd}.yml`. **Zero patches** — the readers are
self-contained (`use Exception` only), no by-ref params, no `func_num_args`, no
reserved `to*()` methods, no framework coupling. `filestored` now builds at 274
files, `sharefsd` at 195, both run unchanged.

Not exercised against a real disk image (needs a `.ssd`/`.adl` fixture + VFS
config) — but the readers compile, link, and are self-contained, so instantiation
is the only remaining unknown.

`Vfs/Plugin/S3.php`'s `Aws\S3\*` is still not compiled (the AWS SDK is large and
dynamic; `S3` is not in the default `vfs_plugins`).

## Stage 10a — native admin HTTP transport (`react/http`) — DONE

The admin HTTP UI plan's first sub-stage: prove `React\Http\HttpServer` (plain
HTTP, as opposed to `Ratchet\Http\HttpServer` which only ever does the WebSocket
upgrade handshake) compiles and runs natively, independent of whatever happens
with the Symfony `HttpKernel` stack itself (Stage 10b). Verified with a new dev
harness, `project.smoke-http.yml` / `smoke/http.php` (same shape as
`project.smoke-ws.yml`): a real `HttpServer` bound to a native `TcpServer`,
hit by an in-process HTTP/1.1 client, both compiled and linked into
`build/typephp/smoke_http` — `podman run` reports
`PASS (statusLine=y body=y)`. Nothing interpreted on this path.

react/http depends on `ringcentral/psr7` (a guzzle/psr7 fork), not
`guzzlehttp/psr7` — a new `STAGE_PKGS` entry in `build-typephp.sh`. Six new
patches, same shape as the existing series:

| Patch | File(s) | Change |
|---|---|---|
| `0015-ringcentral-psr7-stray-defines` | `ringcentral/psr7` `functions.php` | Two top-level `defined(...) or define(...)` guard statements (PHP 5.3 back-compat; both constants are native since PHP 5.4) — illegal stray code in `bin` mode. Deleted; nothing in this tree calls this file's free functions anyway. |
| `0016-ringcentral-react-http-implicit-nullable` | `ringcentral/psr7` `CachingStream.php`/`StreamDecoratorTrait.php`, `react/http` `Io/Sender.php` | Three `Type $x = null` params (implicit nullable, deprecated since PHP 8.4) → `?Type $x = null`. |
| `0017-ringcentral-psr7-query-decoder` | `ringcentral/psr7` `functions.php` | `parse_query()`/`build_query()`'s `$decoder`/`$encoder` locals hold a string callable (`'rawurldecode'`) in one branch, a `Closure` in others — the exact same pattern `0005` already fixed in guzzle's `Query.php`. Every branch now a `Closure`. |
| `0018-react-http-requestheaderparser-retype` | `react/http` `Io/RequestHeaderParser.php` | A `$stream` local held `EmptyBodyStream`/`CloseProtectionStream`/`LengthLimitedStream`/`ChunkedDecoder` across branches, then was read again in a *later*, separately-flow-checked `if ($contentLength === 0)`. Split into one local per wrapping stage (each `withBody()`'d immediately, no cross-branch merge); the empty-body local is now predeclared `null` and the later check reads it directly instead of re-testing `$contentLength`. |
| `0019-react-http-httpserver-ctor-arity` | `react/http` `HttpServer.php` | `__construct($requestHandlerOrLoop)` declares one param but relies on `\func_get_args()` to accept a variadic handler list — strict arg-count rejects a 2-arg call before the body ever runs. Declared `...$requestHandlerArgs`, body rebuilds the same array by hand. |
| `0020-react-stream-resourcestream-aliased-fclose` | `react/stream` `{Readable,Writable,Duplex}ResourceStream.php` | **The same bug as the `main.php`/`sql-serverd` `fclose(): supplied resource is not a valid stream resource` crash reported against the interpreted-vs-native mismatch earlier** — now root-caused, not just worked around. `React\Socket\Connection` wires a `DuplexResourceStream` with an *injected* `WritableResourceStream` over the *same* `$resource` (standard ReactPHP: the duplex delegates its write half to the buffer). Both classes' `close()` end with `if (is_resource($this->stream)) fclose($this->stream);` — under the interpreter the second call's `is_resource()` correctly reports `false` (the fd is already gone) so it's a harmless no-op; under tpc, `is_resource()` does not reliably track liveness across the *aliased* handle held by the sibling object, so the second `fclose()` throws where Zend PHP wouldn't. Reproduced from a genuine, ordinary `Connection: close` request in the `smoke-http` harness — **not** limited to an abnormally-reset peer as first suspected. Fix: wrap each class's closing `fclose()` in a `try`/`catch (\Throwable)` that swallows exactly this "already closed via the aliased handle" case — a no-op under the interpreter, a fix under tpc. This supersedes the loop-recovery wrapper added to `main.php`'s `main()` as a blanket defense — that wrapper stays (cheap insurance against *other* unknown `Throwable`s) but this patch removes the specific trigger.

`main.php` now calls `aun_filestored_start_admin_http()` (mirrors
`aun_filestored_start_ws()`): a native `React\Http\HttpServer` bound to
`webadmin_listen_address:webadmin_listen_port`. Full `aun-filestored` compiles at
322 files, links, and — verified with `curl` against the running binary — the
admin port answers `HTTP/1.1 200` with a plain placeholder body. **Transport
only**: there is no admin *app* behind it yet (Stage 10b/10c/10d).

## Stage 10b — real Symfony `HttpKernel` / DI stack — SPIKED, NOT PURSUED

Per the admin HTTP UI plan, before falling back to a hand-rolled dispatcher we
spiked whether the **pre-warmed / dumped** Symfony container
(`src/var/cache/ContainerDDwwb97/…`, generated by a prior `cache:warmup`) — not
the `ContainerBuilder` / YAML loader that produced it — could compile and resolve
one real service-graph edge under tpc. Probe: `project.probe-symfony.yml` +
`probe-symfony/entry.php` (construct the dumped container, `get()` the
`IndexController`). Dry-compile only; never linked or run.

**Outcome: abandoned.** Two independent, decisive findings:

1. **The documented `class_exists($x, false)` trap, load-bearing this time.** The
   dumped container's own `load()` and `createProxy()` (in
   `HomeLan_FileStore_Admin_KernelProdContainer.php`) have **three** sites of the
   form `class_exists($class = …, false) ? … : (require …)` — i.e. they gate a
   **runtime `require` of an un-`sources`d PHP file** on `class_exists(…, false)`,
   which "is always true for an AOT class" (see *Rules learned* above). Unlike
   `Security::_getAuthPlugins()` — where we could hand-prime the one class — this
   is core, generated Symfony DI machinery that governs *every* lazy service.
2. **Lazy loading is structurally incompatible with `mode: bin`.** A tpc binary
   has no runtime PHP compiler, so the `require $file` fallback can never fire —
   which means **every** service class the container might touch must be in
   `sources` up front. The dumped container for FrameworkBundle's default service
   set is **137 generated files**; only **33** are admin-related. The other ~104
   (cache-pool clearers, the secrets vault, ~40 `console.command.*` lazy stubs,
   router-debug/YAML-lint tooling, session factories) would all have to transpile
   cleanly under tpc's constraints — reserved `to*()` methods, no DOM/`SimpleXML`
   param hints, strict arg-count, no `func_get_args`, no stray file-scope code
   (already hit: `Container.php`'s two `class_exists()` opcache-preload hints,
   `removed-ids.php`'s bare `return`) — purely to serve 27 static admin routes.

Disproportionate and fragile. The native dispatcher (Stage 10d) — static route
table → controller `[class, method]`, direct instantiation, no DI container, no
`HttpKernel` — is the way, behind the Stage 10a transport.

## Stage 10c — real `smarty/smarty` runtime — SPIKED, FAILS THE SAME WAY

Probe: `project.probe-smarty.yml` + `probe-smarty/entry.php` — construct a
`Smarty`, `fetch()` one **pre-compiled** template (`COMPILECHECK_OFF`; the
template *compiler* is never in scope, only the runtime + loader). Dry-compile
only.

**Outcome: not viable under `mode: bin`**, same structural reason as 10b:

- Every one of the ~20 non-trivial `templates_c/*.tpl.php` files opens, at **file
  scope**, with `if ($_smarty_tpl->getCompiled()->isFresh(…)) { function
  content_xxx(\Smarty\Template $_smarty_tpl) { … } }`. tpc rejects it outright:
  `Fatal error: Unsupported statement: Stmt_If in …file_std-head.tpl.php:6`. bin
  mode allows only class / function / const declarations at file scope. So the
  compiled templates **cannot be `sources`**.
- Smarty's loader reaches them only via `include $this->filepath`
  (`Template/Compiled.php:259`) then calls the unit function through a **string
  variable**, `$unifunc($_template)` (`Template/GeneratedPhpFile.php:111`), with
  `function_exists($unifunc)` guards. Runtime PHP file inclusion + variable-function
  dispatch — exactly what an AOT binary structurally lacks (cf. Stage 10b's
  dumped-container `require`).
- (`getRuntime()` / `getModifierCallback()` themselves are plain `switch`/loop —
  *not* the `class_exists` trap — so those were never the problem.)

So Stage 10d cannot lean on the `smarty/smarty` runtime either - it uses a
build-time transform of the compiled templates + a compile-only shim instead.
See **Stage 10d** below (it works). Both probe projects are kept as
documentation; their headers record the outcome.

## Stage 10d — native admin UI — DONE (dispatcher + 3-pass template transform + shims)

The "build-time strip + Smarty shim" path from the Stage 10c note, in three
build-time passes over each compiled `templates_c/*.tpl.php`
(`packaging/typephp/build-admin-templates.php` + `admin-tpl-hoist.php`, run in
`typephp-prep`):

1. **Strip the file-scope `if (isFresh()) { … }` wrapper** (regex) → a bare
   `function content_XXXX(\Smarty\Template $_smarty_tpl) { … }` that CAN be a
   tpc `source`.
2. **Hoist method-call lvalue targets** (AST, nikic/php-parser, format-preserving).
   Smarty compiles `{foreach}` headers and loop counters to
   `$_smarty_tpl->getVariable('x')->value` / `->iteration` used as an
   **assignment / foreach-key-value target**. tpc transpiles it but its C++
   codegen then emits `methodcall(…).attr(name, AttrMode::Update) = …`, which
   g++ rejects — a method-call result is not an lvalue in tpc's generated C++.
   This pass rewrites each to
   `$__sm_oN = $_smarty_tpl->getVariable('x'); $__sm_oN->value = …;` (and the
   `foreach (… as $k->value => $v->value)` header form to a plain
   `as $__sm_kN => $__sm_vN` + two hoisted assigns in the body). 11 of 22
   templates needed it.
3. **De-inline the HTML islands** (`token_get_all`): every `?>…<?php` / `<?= … ?>`
   run becomes `echo '…';`. tpc rejects `Stmt_InlineHTML` inside a function, and
   Smarty's compiled output is built entirely on it.

Output: `stage/admin-templates/` (the transformed `content_*()` functions,
listed as `sources`) + a generated `admin_template_dispatch.php` (name→unifunc
table + `switch()` invoker — no variable-function call, which tpc also lacks).

`packaging/typephp/shims/smarty_runtime.php` — compile-only `\Smarty\Template` /
`\Smarty\Variable` / `\Smarty\Smarty` / `\Smarty\Runtime\ForeachRuntime` + a
replacement `HomeLan\FileStore\Admin\Service\Smarty` — covers exactly the API
surface the transformed templates call: `getValue`/`getVariable`/`setVariable`/
`hasVariable`/`assign`/`getSmarty`/`renderSubTemplate`, `getRuntime('Foreach')`
→ `init`/`restore`, `getModifierCallback('count'|'implodemod'|'ucfirst')`.

Coupling to note: the transform tracks Smarty's *compiled-output* shape (the
`isFresh()` wrapper, `getVariable()->value` lvalues, inline-HTML islands). A
`smarty/smarty` major bump could change any of those; `build-admin-templates.php`
fails loudly (not silently) if a wrapper prefix or an inline-HTML token survives,
so a break is a build error, not a runtime surprise.

### The dispatcher + controllers

- **`build-typephp.sh` `stage/admin-ctl/` step** — copies the 8
  `Admin/Controller/*` classes with `extends AbstractController` + its `use`
  removed (they never call an AbstractController helper — every `$this->…()` is
  their own private/protected method), and rewrites the two
  `file_get_contents(__DIR__ . '/../static/…')` reads (favicon.ico,
  teletext-render.js — `__DIR__` is meaningless in an AOT binary) to
  `base64_decode(ADMIN_STATIC['…'])`, from a generated `stage/admin-static.php`.
  `src/` is untouched. Same non-invasive pattern as `stage/cmd/`.
- **`packaging/typephp/shims/symfony_httpfoundation.php`** — compile-only
  `Request` / `Response` / `RedirectResponse` / `BinaryFileResponse` + param /
  header bags. Thin slice: `->getMethod()`, `->query->get()`, `->request->all()`,
  `new Response($body[,$status[,$headers]])`, `->headers->set()`, `Response::HTTP_OK`.
- **`packaging/typephp/admin/dispatcher.php`** — `Dispatcher::handle(PSR-7) →
  react/http Response`: a 27-arm `switch ($path)` (routes.yaml is all static
  paths) → direct `new XController()->method(…)` → Symfony-shim ↔ PSR-7 adapters,
  wrapped in `try/catch` → 500. No `HttpKernel`, no DI container, no Routing
  component, no session (the interpreted daemon's `SessionCookie` only sets a
  cookie nothing reads — dropped).
- **`main.php`** — `aun_filestored_start_admin_http()` now calls
  `Dispatcher::handle()` in place of the Stage 10a placeholder.

`project.yml` wires in the two shims, the dispatcher, and
`stage/admin-{templates,ctl,static.php}`.

### Verified

`TYPEPHP_DRY=0 make typephp` → `Build successful` (356 files). Running the native
`aun_filestored` and `curl`-ing every route family:

| Route | Result |
|---|---|
| `GET /` | 200 — `index.tpl` with **live** `ServiceDispatcher` data (File Server 153/151, Print Server 159/209, Bridge 156, …) |
| `GET /service?port=153` | 200 — `service.tpl` (nested `{foreach}`) |
| `GET /encapsulation?type=aun` | 200 — `encapsulation.tpl` (nested keyed `{foreach}` + `->iteration`) |
| `GET /encapsulation` (no type) | 200 — `error.tpl` path |
| `GET /users`, `/users/create` | 200 — live `Security::` user table / `users-form.tpl` |
| `GET /service/teletext/browse`, `/service/macemail/broadcast` | 200 |
| `GET /service/torchnet/browse?port=155` | 404 — controller's own "service not found" (correct) |
| `GET /static/teletext-render.js` | 200 `text/javascript` — embedded asset |
| `GET /favicon.ico` | 200 `image/x-icon` — embedded asset |
| `GET /nonesuch` | 404 |

All 8 controllers respond, every template family renders (including the ones the
transform's pass 2 was needed for), against real in-process daemon state.

### sharefsd's own admin UI — also DONE (same machinery)

`sharefsd` has its own small Symfony admin micro-app (`ShareFs\Admin\Kernel`) —
4 routes (`/`, `/component`, `/kube/{live,ready}`), 2 controllers, 5 templates.
Given the same treatment, **reusing without change**: `build-admin-templates.php`
+ `admin-tpl-hoist.php` (the 3-pass template transform), `shims/smarty_runtime.php`
(a second `ShareFs\Admin\Service\Smarty` class added), `shims/symfony_httpfoundation.php`.

New / changed for `sharefsd`:
- `build-typephp.sh` — parallel `stage/sharefs-admin-{templates,ctl}/` steps
  (no static-asset embedding — ShareFs's admin has none). The stripped templates
  keep the `HomeLan\FileStore\Admin\Compiled` namespace; each binary compiles
  only its own `admin_template_dispatch.php`, so there is no clash.
- `packaging/typephp/admin/sharefs_dispatcher.php` — ~85 lines, a 4-arm `switch`.
- `main-sharefsd.php` — `aun_sharefsd_start_admin_http()`, mirrors filestored's,
  bound to `sharefs_webadmin_listen_{address,port}`.
- `project.sharefsd.yml` — adds `ringcentral/psr7` + `react/http` + the shims +
  dispatcher + `stage/sharefs-admin-*`; un-ignores `ShareFs/{Share,Freeway,
  AccessPlus,ShareFsData}Admin.php` (the controllers' `getComponents()` needs
  them) and adds the two support types they use
  (`Encapsulation/EncapsulationAdminInterface.php`,
  `Services/Provider/AdminEntity.php`).

Verified: `TYPEPHP_DRY=0 … project.sharefsd.yml` → `Build successful` (263 files).
Native `sharefsd` (with a service-identity login it can satisfy) serves `GET /`
(all 4 `ShareFs\*Admin` components listed), `GET /component?type=shares` (nested
keyed `{foreach}` entity table), `GET /component?type=bogus` → `error.tpl`,
`/kube/live` → 200, `/nonesuch` → 404.

Still not ported: nothing admin-related. (The filestored / sharefsd admin UIs are
both native now.)

### Stage 10d follow-up — real `symfony/http-foundation` Response side — DONE

`shims/symfony_httpfoundation.php` originally hand-rolled `Request` / `Response` /
`RedirectResponse` / the bags. The **Response side is now the real component**,
compiled: `Response`, `RedirectResponse`, `ResponseHeaderBag`, `HeaderBag`,
`Cookie`, `ParameterBag`, `InputBag`, `Exception\*` — listed in
`project{,.sharefsd}.yml`, compiling **verbatim from `src/vendor`** except two
hand-patched copies in `shims/`:

| Copy | Edit (both semantic no-ops) |
|---|---|
| `symfony_response.php` | drop the file-scope `class_exists(ResponseHeaderBag::class);` preload hint (stray code in bin mode); reduce the PHP 8.4 property hook `public ResponseHeaderBag $headers { set { … } }` to a plain typed property (the hook only fired a deprecation on direct external assignment, which the dispatcher never does) |
| `symfony_headerutils.php` | `parseQuery()` (only called by `Request::__construct`, not compiled) reassigns its `string $query` param to an array and reuses `$k` across two differently-typed `foreach`es — body stubbed to `return []`; `groupParts()` rebinds its `array $matches` param as a loop var — renamed to `$matchGroup` |

Still shimmed, deliberately: **`Request`** (the real one needs another 8.4
property hook, `request_parse_body()`, and the `File\` / `Session\` subtrees — all
dead weight for an input carrier the dispatcher builds itself; the shim is just
two real `InputBag`s + `getMethod()`/`getPathInfo()`) and **`BinaryFileResponse`**
(the real one streams — `getContent()` returns `false` — so a `Response` subclass
that slurps the file; used once, `ServiceController::download`).

Both dispatchers gained a `reactHeaders()` helper: `ResponseHeaderBag::all()` is
`[name => list<?string>]`, react/http's `Response` wants null-free scalar-or-list
(same normalisation `Command\React::adminService()` does). Payoff: real HTTP
status-reason phrases, real header casing/normalisation, real `RedirectResponse`
HTML body, real `Cache-Control`/`Date` headers — for free. Probe:
`project.probe-httpfoundation.yml` → `PASS`. filestored transpiles at 360 files,
sharefsd at 267.

## Stage 8 — native `dnsd`, `ntpd`, `ecosyslogd` — DONE

`make dns-typephp` / `ntp-typephp` / `ecosyslog-typephp` →
`build/typephp/{dnsd,ntpd,ecosyslogd}` (193 / 193 / 273 files). Each starts,
wires its relay client, and logs the reconnect backoff with nothing listening —
the full outbound connect path (pawl → `React\Socket\Connector` → HappyEyeBalls →
react/dns → `TcpConnector`) runs natively.

**One new patch, `0013-react-socket-errhandler-arity`** — the 2-arg
`set_error_handler(function ($_, $error) …)` closures in `TcpConnector`,
`UnixServer`, `FdServer` (PHP invokes error handlers with 4 args → strict
arg-count). The outbound connect path had never been exercised before, so this
also hardens `filestored`/`sharefsd`. `SocketServer::accept()`'s own 2-arg
handler (line ~116) is still unpatched — `0006` already edits that file, so
folding the hunk needs a rebased `0006`; it only fires on a
`stream_socket_accept()` failure.

`RemoteProvider/Client.php` got the same 4 edits as `RemoteSocket/Client.php`
(Stage 7 — plaintext `Connector`, pawl `message`/`close` listener arities,
reconnect-timer arity). New `shims/SyslogLogger.php` — PSR-3 over `openlog()`/
`syslog()` + an optional RFC 3164 UDP line to `ecosyslog_remote_host`.

`dnsd`/`ntpd` reuse `project.sharefsd.yml`'s source set verbatim (swap the entry
point); `ecosyslogd` uses the full `project.yml` set + `RemoteProvider` +
`ratchet/pawl` + `SyslogLogger`.

The Remote Socket path (`dnsd`/`ntpd`) is **verified against a live native
`filestored` relay** (2026-09) - see Stage 7. `ecosyslogd`'s Remote *Provider*
path uses the structurally-identical `RemoteProvider\Client` (same pawl +
`Frame` + auth/register machinery, different frame subclass); a full
EcoSyslog-packet-to-`syslog()` check is still the stronger unrun test.

---

*(original plan below)*

## Stage 8 — native `dnsd`, `ntpd`, `ecosyslogd` (plan)

Three more small daemons, each its own executable, via
`make dns-typephp` / `make ntp-typephp` / `make ecosyslog-typephp`.

### They are almost free

`dnsd` and `ntpd` are ~the same 100-line file: `StreamSelectLoop`, one handler
(`Dns\Handler` / `Ntp\Handler`), an **unconditional** `RemoteSocket\Client` +
`->connect()`, `$client->getTransport($port)->on('message', …)` → `$handler->receive(…)`,
`run()`. **No direct UDP bind, no `Security`, no `ServiceDispatcher`.** The
`RemoteSocket\Client` + `pawl` + `RelayedUdpTransport` they need are already
compiled and edited (Stage 7). New domain code is tiny — `Dns/` (5 files:
`Handler`, `Forwarder`, `DomainFilter`, `HostsFile`, `Messages\DnsMessage` — one
`switch` in `DnsMessage::decodeRdata()` but every arm already `return`s) and
`Ntp/` (2 files: `Handler`, `Messages\NtpMessage`).

`ecosyslogd` is the same shape but on the **other** relay protocol:
`ServiceDispatcher::create($logger, [new EcoSyslog($syslogLogger)])` +
`new RemoteProvider\Client($loop, …)` + `new RemoteProvider\Host($client, $dispatcher, $logger)` +
`$client->connect()` + two periodic timers (`$host->flush()`, `$dispatcher->houseKeeping()`).
`EcoSyslog` is **already compiled** (it's in `filestored`'s build).

### Shared prerequisites (do once)

| # | Item | Notes |
|---|---|---|
| 1 | Un-`ignore` `RemoteProvider/Client.php` + `RemoteProvider/Host.php` (+ probe `AckRelayMap.php`, `RemoteProvider/Messages/`) | `Client extends Evenement\EventEmitter` (compiled) + `Ratchet\Client\Connector` (pawl, compiled) + `React\Promise\Deferred` (compiled) — should transpile now. |
| 2 | `RemoteProvider/Client.php` gets the **same edits as `RemoteSocket/Client.php`** (Stage 7): plaintext `new Connector($loop, new \React\Socket\Connector(['tls'=>false],$loop))`; pawl `'message'` listener `+mixed $conn=null`, `'close'` `+3 ignored`; the reconnect `addTimer(…, function ()…)` → `?TimerInterface $t=null`. Also audit `Client`'s own `emit('packet'|'ack', …)` vs `RemoteProvider\Host`'s listener arities. |
| 3 | New `shims/SyslogLogger.php` — `implements Psr\Log\LoggerInterface`, PSR-3 level → `LOG_*`, writes local via `openlog()`/`syslog()` (`ext-standard`) when `ecosyslog_local_enabled`, and/or a plain RFC 3164 UDP line to `ecosyslog_remote_host:ecosyslog_remote_port` when `ecosyslog_remote_enabled`. Same shape as `StderrLogger`; **match the vendored psr/log signatures exactly** (untyped `$message`, `array $context = array()`). `main-ecosyslogd.php` builds this and passes it as `new EcoSyslog($syslogLogger)`. |

### Per-daemon (×3)

| # | Item |
|---|---|
| a | `packaging/typephp/main-{dnsd,ntpd,ecosyslogd}.php` — de-dynamised `Command\{Dnsd,Ntpd,EcoSyslogd}::MainLoop()`. `dnsd`/`ntpd`: `Dns\HostsFile`/`DomainFilter`/`Forwarder` + handler + `RemoteSocket\Client` + `getTransport(dns_port|ntp_port)`. `ecosyslogd`: `ServiceDispatcher` + `EcoSyslog(new SyslogLogger)` + `RemoteProvider\Client`/`Host` + the two timers (make the `$host->flush()` and `houseKeeping()` timer closures take `?TimerInterface $t = null`). Args `-c`/`-d`/`-p` + `pcntl_fork` like the others. |
| b | `packaging/typephp/project.{dnsd,ntpd,ecosyslogd}.yml` — `project.sharefsd.yml` as the base (it already has the loop + sockets + pawl + `RemoteSocket/Client`), swap the entry point, swap `ShareFs` → `Dns`+`Ntp` (dnsd) / `Ntp` (ntpd) / `Services` + `RemoteProvider` (ecosyslogd), drop `ShareFs`. Add `shims/SyslogLogger.php` for ecosyslogd. |
| c | `Makefile`: `dns-typephp:` / `ntp-typephp:` / `ecosyslog-typephp:` — each `TYPEPHP_PROJECT=…project.<x>.yml TYPEPHP_OUT=<x> packaging/typephp/build-typephp.sh`. Add to `.PHONY`. Optional `native-daemons:` aggregate that depends on all five. |
| d | Probe loop over the new domain dirs (`Dns/`, `Ntp/`, `RemoteProvider/`) — expect the familiar handful: 0-arg timer/promise closures, maybe an int→string retype in the binary `DnsMessage`/`NtpMessage` parsers. |

### Test (no relay peer)

`dnsd`/`ntpd`/`ecosyslogd` all create their relay client **unconditionally**, so
with nothing listening on the relay port they connect-fail and back off.
**Pass = the native binary starts, enters the loop, logs the RemoteSocket /
RemoteProvider reconnect backoff, and stays up.** Stronger: run a native
`filestored` (relays enabled) in the same container, point
`{dns,ntp}d`'s `*_remote_socket_relay_address` / `ecosyslogd`'s
`ecosyslog_remote_provider_relay_address` at it, and for `ecosyslogd` send an
EcoSyslog packet (severity byte + text) and confirm it reaches `syslog()`
(check the container's `/var/log` or a `logger`-tail).

### Effort

~1 day for all three. `dnsd` + `ntpd` are ~2 hours combined (near-identical, all
deps done). `ecosyslogd` is the rest: `RemoteProvider/Client` edits (mirror
`RemoteSocket/Client`), the `SyslogLogger` shim, and its probe. Expected **0
new vendor patches** — everything rides on Stages 1-7.

## Stage 5 — harden

1. **Dual-build guarantee.** The patched vendor tree must still run under the
   interpreter (the `.gitlab-ci.yml` PHP-8.4 jobs, the phar, the deb/rpm). Every
   vendor patch has to be a semantic no-op for standard PHP. Add a CI job that runs
   the full PHPUnit suite with `vendor-patches` applied to a scratch `vendor/`.
2. **CI matrix** for `make typephp`: `TYPEPHP_STAGE` 1→4, each asserting `Build
   successful`, plus running the staged `main.php` smoke binaries.
3. **libphp extension surface.** Confirm `ext-openssl` (if TLS is ever needed),
   `ext-pcntl` (shimmed), `ext-sockets` (not needed — see `README.md`) against the
   linked libphp; document in `README.md`.
4. Fold the throwaway `main.php` smoke variants into `packaging/typephp/smoke/`.
5. Update `README.md`'s "What builds today" + the `sources`/`ignore` tables.

---

## Cross-cutting risks

- **Reflection on compiled closures (Stage 1).** The single biggest unknown. If
  `ReflectionFunction($closure)->getNumberOfParameters()` doesn't work on an AOT
  closure, `react/promise` needs the arity/typehint inspection patched out (the
  `$args = 1` + "all catch handlers run" approach). Prototype this **first**, before
  committing to the rest of Stage 1 — it's a 1-hour spike that de-risks the whole
  plan.
- **Cross-trait dynamic dispatch is not native (perf).** `EventEmitterTrait` is
  mixed into ~everything; `$this->emit()` calls from inside trait code may lower to
  Zend method calls, not C++ direct calls (`INCOMPATIBLE` doc, "编译器自举" section).
  Correctness is fine; the "fully native hot path" claim gets an asterisk on event
  emission. Measure before/after; if it matters, inline the trait into the 2–3
  hot emitters (`react/datagram/Socket`, the AUN handler).
- **Patch-maintenance burden.** Every `composer update` can move a patched line.
  Keep patches minimal and pinned; if Tier 1 needs >10 patched files, fork those
  packages into `vendor-src/` and pin the versions in `composer.json` so updates
  are deliberate.
- **`stream_socket_recvfrom` by-ref out-param.** If `tpc`'s stdlib mapping doesn't
  handle it, Stage 2 needs a Berkeley-sockets `.cc` shim (same pattern as
  `shims/pcntl_posix.cc`). Bounded, but it's C.
- **Scope of Stage 4.** guzzle/psr7 is 8k lines of someone else's code entering the
  compile. If it fights back, the fallback is to keep the WebSocket listeners
  *interpreted* (they're not the Econet packet hot path — that's Stages 1–3) and
  ship a binary that's native for AUN/Econet and falls back to Zend for websocket
  framing. Stages 1–3 deliver the stated goal ("encapsulation and service provider
  code native"); Stage 4 is the stretch.

## Effort estimate

| Stage | Estimate | Actual / status |
|---|---|---|
| 0 scaffolding | 0.5 day | **done** (~0.3 day) |
| 1 loop core (incl. Reflection spike) | 2–4 days | **done** (~0.4 day — spike passed, 3 small patches) |
| 2 sockets | 2–3 days | **done** (~0.3 day — 1 patch, no `.cc` shim, DNS dropped) |
| 3 wire Econet path | 1–2 days | **done** (~0.5 day — config-const rewrite, `StderrLogger` shim, `toString`->`asString` rename) |
| 3b full providers + FS round trip | few hours | **done** (~0.2 day — no DI graph to rebuild; one `WebSocketMap::init` fix) |
| 4 Ratchet compile | 4–8 days | **done** (~0.4 day — Ratchet doesn't pull react/http; 1 patch) |
| 4b Ratchet runtime | — | **done** (~0.5 day — 3 patches + a `trigger_deprecation` shim) |
| consolidation | — | **done** (`project.yml` + `main.php` = the real daemon; +`react/child-process`, patch `0009`) |
| 6 RemoteBridge client | — | **planned** (~0.5 day — `TcpConnector` + sync DNS, no `react/dns`) |
| 5 harden/CI | 1–2 days | pending — dual-build spot-checked OK (`php -l` + functional on all 14 patched files) |

The whole exercise came in at **~3 days** vs the 9-19 budgeted. Reasons: the
Reflection spike passed, closure by-ref *captures* are supported, `react/dns` and
`react/http` both fell out of scope, the domain tree co-compiled with React on the
first try once `config` + a logger were in place, the daemon has no Symfony DI
graph, and Ratchet's own surface was mostly strict-arity fixes.

**Cost: nine vendor patches** (`vendor-patches/0001`–`0009`, ~180 changed lines
total, every one a semantic no-op under the interpreter), **one build-time
transform** (`config_defines.php`), **two shims** (`StderrLogger`,
`trigger_deprecation`), **one domain rename** (`toString`→`asString`, 18 files,
phpstan + 3451 tests green).

The native daemon boots the AUN UDP listener, the Ratchet WebSocket bridge and
both relay listeners on the compiled `StreamSelectLoop` and stays up. A synthetic
AUN `*I AM` FS request runs decode → OSCLI → `FileServer::login` → `Security` →
reply → AUN encapsulation → transmit entirely in compiled code; a WebSocket
client's frame reaches `WebSocket\Handler::onMessage` through the compiled
guzzle/psr7 + rfc6455 + Ratchet stack.
