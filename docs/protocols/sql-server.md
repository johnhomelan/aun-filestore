# SQL Server

SqlServer lets an Econet client (e.g. a BBC Micro) authenticate, run a parameterised SQL query
against a server-side PostgreSQL/MySQL/SQLite database, and stream the (paged) result set back to
a port the client itself specifies - designed around how little RAM an 8-bit client actually has.
It runs in its own daemon (`sql-serverd`), entirely separate from `filestored`, hosted over the
[Remote Provider Protocol](remote-provider.md) exactly the way `ecosyslogd` hosts `EcoSyslog` - see
[EcoSyslog](ecosyslog.md) for the smaller example this one builds on.

## Architecture overview

```
BBC Micro ── Econet ── [filestored: ServiceDispatcher + ProxyProvider] ══ WebSocket ══ [sql-serverd: ServiceDispatcher + SqlServer]
                                                                                              │
                                                                                    per-(station,database) PDO connections
                                                                                              │
                                                                              PostgreSQL / MySQL / SQLite
```

`Command\SqlServerd::MainLoop()` builds a real `ServiceDispatcher` with `[new SqlServer($oLogger)]`
and wires a `RemoteProvider\Client`/`Host` pair on top exactly as described in
[remote-provider.md](remote-provider.md#overview) - `SqlServer` itself has no idea it's running
remotely. `filestored`'s own `ProxyProvider` instantiation in `src/filestored` reserves `0xB7` for
it, alongside `0xB6` for `ecosyslogd`.

`SqlServer`'s result streaming needs no Stream Claims machinery: like `FileServer`'s GETBYTES (see
[file-server.md](file-server.md)), it addresses outgoing data blocks directly to the port number
the client itself supplied in the QUERY request, rather than claiming/registering a port of its
own - ordinary reply packets already relay back regardless of destination port. It does rely on
Remote Provider's Ack Relay, since the block-per-ack loop is entirely async, driven by
`ServiceDispatcher::addAckEvent()` firing well after the original QUERY request already returned.

## Ports

Single control port, `sql_server_port` (default `0xB7`), used for authentication, database
listing, query submission, and cancellation. Every request's payload starts with a one-byte
operation code:

| Op | Name | Purpose |
|---|---|---|
| `0x01` | LOGIN | Authenticate this station |
| `0x02` | LOGOUT | End the session |
| `0x03` | LIST_DATABASES | List configured databases this user may query |
| `0x04` | QUERY | Submit a parameterised SQL statement |
| `0x05` | CANCEL | Abort the in-flight query/stream for this station |

Result sets stream to whatever port the client specified in its QUERY request - not a fixed or
server-claimed port.

## Sessions

Authentication goes through the project's existing pluggable auth system (see
[authentication.md](../authentication.md)) unchanged - `Security::login()`/`isLoggedIn()`/
`getUser()`, keyed by (network, station), exactly as every other service already uses it. There is
no separate SqlServer-specific session token.

At most **one in-flight query per (network, station)** - a second QUERY while one is already
running gets `ERROR_BUSY` rather than being queued or given a handle to track.

## Reply status byte

Every reply starts with a one-byte status: `0x00` for success, otherwise one of:

| Code | Name | Meaning |
|---|---|---|
| 1 | `ERROR_NOT_AUTHENTICATED` | LIST_DATABASES/QUERY attempted before LOGIN |
| 2 | `ERROR_BAD_CREDENTIALS` | LOGIN failed |
| 3 | `ERROR_UNKNOWN_DATABASE` | QUERY named a database not in `sql_databases` |
| 4 | `ERROR_ACCESS_DENIED` | QUERY named a database this user isn't in `_allowed_users` for |
| 5 | `ERROR_BUSY` | A query is already in flight for this station |
| 6 | `ERROR_QUERY_FAILED` | The statement itself failed (malformed payload or a real SQL error) |
| 7 | `ERROR_UNKNOWN_OP` | Unrecognised operation code |
| 8 | `ERROR_TOO_MANY_CONNECTIONS` | `sql_max_connections_per_database` reached for that database |
| 9 | `ERROR_NOTHING_TO_CANCEL` | CANCEL sent with no query in flight |

A non-zero status is followed by a CR-terminated error message.

## LOGIN (`0x01`)

```
Request payload (after the op byte):
  [1]  username length (U)
  [U]  username
  [1]  password length (P)
  [P]  password
```

Reply: status only.

## LOGOUT (`0x02`)

No further payload. Ends the session, cancels any in-flight query for this station, and closes
every PDO connection `ConnectionFactory` is holding for it. Reply: status only.

## LIST_DATABASES (`0x03`)

No further payload. Requires an active session.

```
Reply payload (after the status byte):
  [1]  database count (N)
  N x  { [1] name length, name bytes, [1] engine tag }
```

Engine tags: `0` SQLite, `1` MySQL, `2` PostgreSQL. Only databases the authenticated user is
allowed to query (`sql_database_{name}_allowed_users`) are listed.

## QUERY (`0x04`)

```
Request payload (after the op byte):
  [1]        database name length (N)
  [N]        database name
  [2]        stream port (little-endian) - where the client wants the result set streamed
  [1]        parameter count (C)
  C x        { see "Value encoding" below }
  remaining  SQL text (however many bytes are left in the packet - not length-prefixed)
```

Parameters are bound positionally, in order, via `PDOStatement::bindValue()` - the SQL text and
parameter values are never string-concatenated. This is both the requested "parameterised
queries" feature and the project's actual SQL-injection defence.

The whole request (database name + parameters + SQL text) must fit in one packet. There is no
chunked-upload path for an oversized query in this version - if one is ever needed,
`FileServer::saveFile()`'s PUTBYTES-style chunked upload (`ServiceDispatcher::claimStreamPort()` +
`StreamIn`) is the pattern to copy.

**Immediate reply** (status, then):
- **No result set** (INSERT/UPDATE/DELETE/DDL): `[1] 0x00` (no streaming), then `[4] rows affected
  (little-endian)` - from `PDOStatement::rowCount()`, uniform across all three engines.
- **Has a result set** (SELECT, ...): `[1] 0x01` (streaming follows). No further data in this
  reply - the result set streams separately, see below.

### Result streaming

Sent to the port the client specified, in fixed 256-byte blocks (matching FileServer's
GETBYTES/PUTBYTES), one block acked before the next is sent - **this ack-per-block flow control is
the paging mechanism**: the client only ever needs to hold one block in memory, and paces the
whole transfer by when it acks.

The byte stream (irrespective of block boundaries) is:

```
Header:
  [2]  column count (little-endian)
  N x  { [1] name length, name bytes }

Then, for each row, one entry per column - see "Value encoding" below.
```

Column **types** are deliberately not sent - `PDOStatement::getColumnMeta()` is documented as
driver-inconsistent (verified directly: reliable for column *names* across SQLite/MySQL/
PostgreSQL, even for a zero-row result, but not something this protocol leans on for types).
Instead every cell is self-describing.

Once the client acks the final data block, a **completion reply** arrives on the *control* port
(mirroring GETBYTES's own final `DoneOk()`, sent separately from the raw data blocks):

```
[1] status (0x00, or an error if the statement failed partway through)
[1] EOF flag: 0x80 complete, 0x01 error
[4] total rows sent (little-endian)
```

## CANCEL (`0x05`)

No further payload. Aborts the in-flight statement/cursor and stops sending further blocks - the
underlying database connection stays open for the next query in the same session. Reply: status
only (`ERROR_NOTHING_TO_CANCEL` if nothing was running).

## Value encoding

Used both for QUERY's bound parameters and for streamed result cells - one tag-and-value shape,
both directions:

| Tag | Name | Value bytes |
|---|---|---|
| `0x00` | NULL | *(none)* |
| `0x01` | INTEGER | `[8]` signed, little-endian |
| `0x02` | FLOAT | `[8]` IEEE754 double, little-endian |
| `0x03` | TEXT | `[2]` length (LE), then that many bytes |
| `0x04` | BLOB | `[2]` length (LE), then that many bytes |

Parameters arrive pre-tagged by the client. Result cells are tagged by `SqlServer` from the PHP
value PDO hands back (`null`/`int`/`float`/`string`) - a fetched string is always sent as `TEXT`;
`BLOB` exists on the wire purely so a client can bind a parameter it wants treated as binary
(`PDO::PARAM_LOB`).

## Databases

Configured via `sql_databases` (a comma-separated list of names) plus, per name:

| Key | Meaning |
|---|---|
| `sql_database_{name}_engine` | `pgsql`, `mysql`, or `sqlite` |
| `sql_database_{name}_dsn` | A PDO DSN, e.g. `pgsql:host=localhost;dbname=accounts` |
| `sql_database_{name}_user` / `_password` | Credentials |
| `sql_database_{name}_allowed_users` | Comma list of Econet usernames; empty means any authenticated user |

A client never sends a DSN or credentials - only one of these configured names.

### Connections

**One PDO connection per (network, station, database name)** - every authenticated station gets
its own connection to each database it queries, never a connection shared across clients. This
isn't just isolation hygiene: a PostgreSQL server-side cursor (see below) is scoped to one
transaction on one connection, so two stations sharing a connection would corrupt each other's
in-flight cursors the moment both had a query open at once.

A connection is opened on a station's first QUERY against a given database, kept open across
further queries in the same session, and closed on LOGOUT or once housekeeping notices `Security`
no longer has a session for that station (idle timeout, or a client that vanished without LOGOUT -
see `security_max_session_idle` in [authentication.md](../authentication.md)).
`sql_max_connections_per_database` caps how many simultaneous per-station connections one
configured database will accept, so many concurrent clients can't exhaust the real database
server's own connection limit.

### Per-engine paging

A result set is never buffered whole in `sql-serverd`'s own memory, matching the point of the
whole feature - the BBC's RAM constraint doesn't help if the server just holds everything instead:

- **SQLite** - already incremental via SQLite's own step-based API; no special handling.
- **MySQL** - connections are opened with `PDO::MYSQL_ATTR_USE_BUFFERED_QUERY` disabled, so rows
  stream from the server as `PDOStatement::fetch()` is called, instead of PDO loading the whole
  result set up front.
- **PostgreSQL** - PDO's pgsql driver has no equivalent flag, so `PgsqlCursor` opens an explicit SQL
  cursor instead (`BEGIN; DECLARE ... CURSOR FOR <query>; FETCH n FROM cur; ...; CLOSE; COMMIT;`) -
  verified directly against a real PostgreSQL server while building this.

A statement is only run through the cursor path if it looks like it produces a result set (a cheap
keyword check - `SELECT`/`WITH`/`SHOW`/`EXPLAIN`/`TABLE`); anything else is prepared and executed
normally and reported via `rowCount()`. One known limitation this misses: a PostgreSQL
`INSERT ... RETURNING ...` is classified as a plain statement and its returned rows are dropped
rather than streamed.

## Access control

`sql_database_{name}_allowed_users` is checked on every LIST_DATABASES and QUERY - an empty list
means any authenticated user may query that database; a non-empty list is an allow-list of
Econet usernames. This is a real access-control boundary, not cosmetic: without it, any
authenticated station could query *any* configured database.

## Configuration

| Key | Default | Purpose |
|---|---|---|
| `sql_server_port` | `0xB7` | The control port |
| `sql_databases` | *(empty)* | Comma-separated configured database names |
| `sql_database_{name}_engine` / `_dsn` / `_user` / `_password` / `_allowed_users` | - | Per-database settings, see above |
| `sql_max_rows_per_query` | `1000000` | Safety cap regardless of engine |
| `sql_query_timeout` | `30` | Seconds |
| `sql_max_connections_per_database` | `20` | Per database, across all stations |
| `sql_serverd_remote_provider_relay_address` | `127.0.0.1:8092` | `host:port` of the `filestored` Remote Provider relay |
| `sql_serverd_remote_provider_relay_secret` | *(none - required)* | Shared secret; must match `remote_provider_relay_secret` on the `filestored` side |

`filestored`'s own `ProxyProvider` instantiation in `src/filestored` must include `0xB7` in its
reserved port list, and `remote_provider_relay_enabled`/`_secret` (existing keys - see
[remote-provider.md](remote-provider.md)) must be turned on, for any of this to receive traffic at
all.

## Key files at a glance

| File | Role |
|---|---|
| `src/sql-serverd` | Executable entry point |
| `src/include/classes/Command/SqlServerd.php` | `ServiceDispatcher`/relay wiring, event loop |
| `src/include/classes/Services/Provider/SqlServer.php` | The provider: op dispatch, auth, query execution, ack-per-block result streaming |
| `src/include/classes/Services/Provider/SqlServer/DatabaseRegistry.php`, `DatabaseDefinition.php` | Reads `sql_databases`/`sql_database_*` config |
| `src/include/classes/Services/Provider/SqlServer/ConnectionFactory.php` | Per-(station, database) PDO connection lifecycle |
| `src/include/classes/Services/Provider/SqlServer/CursorInterface.php`, `BufferedCursor.php`, `PgsqlCursor.php` | Per-engine incremental result fetching |
| `src/include/classes/Services/Provider/SqlServer/ValueCodec.php` | Type-tagged wire value encode/decode |
| `src/include/classes/Services/Provider/SqlServer/RequestPayloadParser.php`, `QueryPayload.php` | LOGIN/QUERY payload parsing |
| `src/include/classes/Messages/SqlRequest.php`, `SqlReply.php` | Wire message classes |
| `src/include/classes/RemoteProvider/` | Shared with `ecosyslogd`/`filestored` - see [remote-provider.md](remote-provider.md) |

## Scope and limitations

No admin UI in this version - `remote-provider.md`'s own "Scope and Limitations" section notes a
remotely-hosted provider's `AdminInterface` isn't surfaced through `filestored`'s admin UI;
`sharefsd`'s own admin Kernel (`ShareFs\Admin\Kernel`) is the pattern to copy if one is added later.

An `INSERT ... RETURNING ...`-style statement against PostgreSQL has its returned rows silently
dropped rather than streamed (see "Per-engine paging" above).

## See also

- [remote-provider.md](remote-provider.md) - the hosting protocol this runs over
- [ecosyslog.md](ecosyslog.md) - the simpler example this daemon's structure is copied from
- [file-server.md](file-server.md) - GETBYTES/PUTBYTES, the precedent for client-specified stream
  ports and ack-per-block flow control
- [authentication.md](../authentication.md) - the auth-plugin system this reuses unchanged
