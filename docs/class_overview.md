# Class Overview

All classes live under the `HomeLan\FileStore` namespace unless stated
otherwise. The table below uses the short form relative to that root.

---

## Configuration and bootstrap

| Class | Kind | Description |
|---|---|---|
| `config` (global) | class | Static helper that reads application settings from PHP `CONFIG_*` constants or `.conf` files in the configured directory |

`src/include/config.inc.php` — defines the default `CONFIG_*` PHP constants; not a class.  
`src/include/system.inc.php` — bootstraps Composer autoloading and includes `config.inc.php`; not a class.

---

## Entry points / console commands

| Class | Kind | Description |
|---|---|---|
| `Command\React` | class | Main entry point (`filestore` command): initialises the ReactPHP event loop and brings up AUN, WebSocket, Piconet, and admin HTTP services |
| `Command\MakeCatalogueArchive` | class | CLI tool (`mkcatarchive`): packs a local directory into a tar archive + versioned `index.json` for the Catalogue VFS plugin |
| `Command\S3Upload` | class | CLI tool (`s3upload`): uploads local files with `.inf` sidecars directly to a configured S3 mapping |
| `Console\SingleCommandApplication` | class | Symfony console wrapper that makes one command the implicit default so it runs without naming it |

---

## Network encapsulation

Each encapsulation wraps the transport-neutral `EconetPacket` for a specific
wire or connection type. All three implement `EncapsulationInterface`.

| Class | Kind | Description |
|---|---|---|
| `Encapsulation\EncapsulationInterface` | interface | Contract for all encapsulation types: `decode`, `buildEconetPacket`, `getPacketType`, `getPort`, `getData`, `toString` |
| `Encapsulation\EncapsulationAdminInterface` | interface | Contract for encapsulation admin adapters: `getId`, `getName`, `getDescription`, `getStatus`, `getEntityTypes`, `getEntityFields`, `getEntities` |
| `Aun\AunPacket` | class | AUN (Econet over UDP) encapsulation — decodes binary AUN headers and builds ACK / ImmediateReply responses |
| `Piconet\PiconetPacket` | class | Piconet (EconetUSB serial hardware) encapsulation |
| `WebSocket\JsonPacket` | class | WebSocket encapsulation — decodes the JSON envelope used by browser-based BBC emulators |
| `Encapsulation\EncapsulationTypeMap` | class (singleton) | Decides the correct outbound transport for an `EconetPacket` based on destination address: WebSocket > Piconet > AUN |
| `Encapsulation\PacketDispatcher` | class (singleton) | Routes outbound `EconetPacket` objects to the appropriate transport handler |

### Encapsulation admin adapters

| Class | Kind | Description |
|---|---|---|
| `Aun\Admin` | class | Admin adapter for AUN: host mappings (IP ↔ network.station) and subnet mappings |
| `WebSocket\Admin` | class | Admin adapter for WebSocket: connected clients and configured dynamic network ranges |
| `Piconet\Admin` | class | Admin adapter for Piconet: registered Econet network numbers |
| `RemoteBridge\Admin` | class | Admin adapter for RemoteBridge: live peer connections, configured server entries, and configured client entries (shared secrets are stripped before display) |

---

## Transport handlers

| Class | Kind | Description |
|---|---|---|
| `Aun\HandleInterface` | interface | Contract for AUN socket handlers: `setSocket`, `receive`, `send`, `timer`, `onClose` |
| `Aun\Handler` | class | AUN UDP socket handler — per-host send queue with retry/backoff and duplicate-packet detection by sequence number |
| `Aun\Map` | class | Bidirectional static mapping of IP addresses and subnets to Econet network/station numbers; used by the AUN handler for routing |
| `Piconet\Handler` | class | Piconet serial handler — frames packets to/from the EconetUSB hardware device and dispatches inbound data to the service layer |
| `Piconet\Map` | class | Static set of Econet network numbers reachable via the Piconet hardware interface |
| `WebSocket\Handler` | class | Ratchet WebSocket handler — accepts client connections, dispatches inbound JSON packets to the service layer, and routes outbound frames back to connected clients |
| `WebSocket\Map` | class | Maps WebSocket connections to Econet network/station pairs for outbound packet routing |
| `React\UnixSerialDeviceConnector` | final class | ReactPHP `ConnectorInterface` implementation that opens a Unix serial device file as a non-blocking stream |

---

## Messages and packets

### Core packet

| Class | Kind | Description |
|---|---|---|
| `Messages\EconetPacket` | class | Transport-neutral Econet packet: source/destination network+station, port, flags, binary payload; can serialise to AUN UDP or WebSocket JSON |
| `Messages\Request` | class | Base class for all decoded inbound Econet messages — stores source network/station, flags, raw payload, and a logger |
| `Messages\Reply` | class | Base class for all outbound replies — byte/string/int packing helpers and `buildEconetpacket()` addressing reply back to the source station |

### File server messages

| Class | Kind | Description |
|---|---|---|
| `Messages\FsRequest` | class | Decodes all inbound file server requests (save, load, open, close, getbytes, putbytes, CLI, examine, login, rename, etc.) from the binary wire format |
| `Messages\FsReply` | class | Builds file server protocol reply payloads (login response, done/error codes, dir/lib/cat/info confirmations) |

### Print server messages

| Class | Kind | Description |
|---|---|---|
| `Messages\PrintServerData` | class | Raw binary data block sent to the print server (port 0xD1) from a BBC client |
| `Messages\PrintServerEnquiry` | class | Print server enquiry packet (port 0x9F) asking whether a printer is available |

### IPv4/EconetA messages

| Class | Kind | Description |
|---|---|---|
| `Messages\IPv4Request` | class | Decodes an IPv4 header from a raw `EconetPacket` payload |
| `Messages\ArpRequest` | class | Decodes a DCI-2 ARP who-has request (flags 0x21) |
| `Messages\ArpReply` | class | Builds a DCI-2 ARP is-at reply (flags 0x22) |
| `Messages\ArpIsAt` | class | Represents a received ARP is-at response (DCI-2 0x22 or DCI-4 0xA2) |
| `Messages\ArpWhoHas` | class | Constructs an ARP who-has broadcast used to resolve an IPv4 address |
| `Messages\Dci4ArpRequest` | class | DCI-4 ARP who-has variant (flags 0xA1) for later RiscOS machines |
| `Messages\Dci4ArpReply` | class | DCI-4 ARP is-at reply variant (flags 0xA2) for later RiscOS machines |
| `Messages\IcmpRequest` | class | Parses an ICMP segment from inside an IPv4 `EconetPacket` |
| `Messages\IcmpEchoReply` | class | Builds a checksummed ICMP echo reply (type 0) inside an IPv4 EconetA packet |
| `Messages\IcmpUnreachable` | class | Builds an RFC 792 ICMP Destination Unreachable (type 3) inside an IPv4 EconetA packet |
| `Messages\TCPRequest` | class | Decodes a TCP segment from inside an IPv4 `EconetPacket` — ports, seq/ack numbers, flags, header options |
| `Messages\TcpIPReply` | class | Constructs a full TCP/IPv4 reply with correct IP and TCP checksums (RFC 793 pseudo-header), wrapped in an EconetA packet |

### BeebTerm messages

| Class | Kind | Description |
|---|---|---|
| `Messages\BeebTermRequest` | class | Decodes inbound BeebTerm client messages (DATA, LOGIN, TERMINATE, etc.) on port 0xA2 |

### Econet bridge messages

| Class | Kind | Description |
|---|---|---|
| `Messages\BridgeRequest` | class | Decodes Econet bridge protocol requests (EC_BR_QUERY, EC_BR_LOCALNET, EC_BR_NETKNOWN, etc.) |

### TorchNet messages

| Class | Kind | Description |
|---|---|---|
| `Messages\TorchnetRequest` | class | Decodes inbound TorchNet protocol requests (OPEN, READ_BLOCK, SEARCH_FIRST, MEM_PEEK, etc.) from Torch Communicator workstations |
| `Messages\TorchnetReply` | class | Builds outbound TorchNet response packets (ok, error, open/read/search success/failure) |

---

## Services layer

| Class | Kind | Description |
|---|---|---|
| `Services\ProviderInterface` | interface | Contract all service providers must implement: unicast/broadcast packet ingestion, reply collection, port declaration, job listing, and admin interface access |
| `Services\ServiceDispatcher` | class (singleton) | Routes inbound `EconetPacket` objects to the registered provider for each port; manages enable/disable/housekeeping; collects outbound replies; dispatches ACK event callbacks |
| `Services\StreamIn` | class | Accumulates multi-packet binary data streamed from a BBC client (e.g. during a file save) and fires success/failure callbacks when the full byte count is received or a timeout occurs |
| `Services\Exception` | class | Domain exception for the Services layer |

---

## Service providers

### Provider admin infrastructure

| Class | Kind | Description |
|---|---|---|
| `Services\Provider\AdminInterface` | interface | Contract for provider admin adapters: name, description, enable/disable status, entity types/fields/rows, and navigation command listing |
| `Services\Provider\AdminEntity` | class | Generic data object representing one row of an admin-displayable entity type, with typed field definitions and an optional computed ID |

### Providers

| Class | Kind | Description |
|---|---|---|
| `Services\Provider\FileServer` | class | Core Econet file server implementing the BBC/Acorn NetFS protocol: login, directory operations, file save/load/open/close/getbytes/putbytes, CLI commands, disc and user management |
| `Services\Provider\PrintServer` | class | Econet print server: buffers incoming data from BBC clients and dispatches completed jobs to a configurable shell command |
| `Services\Provider\Bridge` | class | Econet bridge protocol provider: responds to network-topology queries and tracks remote networks announced by other bridges |
| `Services\Provider\IPv4` | class | IPv4-over-Econet (EconetA standard): handles ARP (DCI-2 and DCI-4), ICMP ping, and NAT-proxied TCP connections |
| `Services\Provider\BeebTerm` | class | SJ Research BeebTerm terminal service: spawns a child process per client session and proxies I/O between the process and the Econet client |
| `Services\Provider\Torchnet` | class | TorchNet CP/M file server for Torch Communicator workstations: translates CP/M 8+3 filenames and block-I/O to Acorn VFS paths via `CpmVfs` |

### Provider admin adapters

| Class | Kind | Description |
|---|---|---|
| `Services\Provider\FileServer\Admin` | class | Admin adapter for the file server: logged-in users, open file handles, directory-browsing API |
| `Services\Provider\PrintServer\Admin` | class | Admin adapter for the print server: current print queue and spooled file list |
| `Services\Provider\IPv4\Admin` | class | Admin adapter for IPv4: ARP cache, routing table, interface definitions, NAT rules, connection-tracking entries |
| `Services\Provider\BeebTerm\Admin` | class | Admin adapter for BeebTerm: active terminal sessions and available service definitions |
| `Services\Provider\Torchnet\Admin` | class | Admin adapter for TorchNet: open file handles and drive-letter-to-path mapping |

### IPv4 sub-components

| Class | Kind | Description |
|---|---|---|
| `Services\Provider\IPv4\Arpcache` | class | In-memory timed ARP cache mapping IPv4 addresses to Econet network/station pairs |
| `Services\Provider\IPv4\Interfaces` | class | Loads and manages virtual IPv4 interface definitions (network, station, IP, netmask) used for routing and ARP |
| `Services\Provider\IPv4\NAT` | class | TCP NAT/reverse-proxy: relays TCP segments between Econet clients and real TCP servers via ReactPHP async sockets |
| `Services\Provider\IPv4\Routes` | class | Manages the IPv4 routing table (destination, gateway, metric) loaded from a config file |

### IPv4 exceptions

| Class | Kind | Description |
|---|---|---|
| `Services\Provider\IPv4\Conntrack\Exception` | class | Domain exception for connection-tracking errors within the NAT subsystem |
| `Services\Provider\IPv4\Conntrack\NotReadyException` | class | Thrown when a conntrack entry exists but the TCP handshake is not yet complete |
| `Services\Provider\IPv4\Exceptions\ArpEntryNotFound` | class | Thrown when an ARP lookup finds no entry for the requested IPv4 address |
| `Services\Provider\IPv4\Exceptions\InterfaceNotFound` | class | Thrown when no configured IPv4 interface matches a packet's destination |
| `Services\Provider\IPv4\Exceptions\NatException` | class | Thrown when a NAT operation encounters an unrecoverable error |

---

## VFS (Virtual File System)

### Core VFS

| Class | Kind | Description |
|---|---|---|
| `Vfs\Vfs` | class | Central VFS dispatcher: chains plugins for all file operations, manages per-session file handles, enforces file-level locking, and tracks SIN assignments |
| `Vfs\CpmVfs` | class | CP/M compatibility layer extending `Vfs`: translates between CP/M `\`-separated paths and Acorn `.`-separated paths; used by the TorchNet provider |
| `Vfs\Exception` | class | VFS domain exception with a `$bHard` flag (abort the plugin chain) and a `$bLocked` flag (file-lock conflict) |
| `Vfs\FileDescriptor` | class | An open Econet file or directory handle: maps the client-visible single-byte handle ID to the plugin-level handle, user, and Econet/Unix paths |
| `Vfs\FilePath` | class | Value object pairing a directory component and a filename component of an Econet VFS path |
| `Vfs\DirectoryEntry` | class | A single file or directory entry in the VFS catalogue: Econet name, Unix name, plugin name, load/exec addresses, size, access mode, directory flag |
| `Vfs\CpmDirectoryEntry` | class | CP/M view of a `DirectoryEntry`: translates Acorn `.` separators to CP/M `\` in all name/path outputs |

### VFS plugins

| Class | Kind | Description |
|---|---|---|
| `Vfs\Plugin\PluginInterface` | interface | Contract all VFS plugins must implement: `init`, `houseKeeping`, directory listing, file CRUD, metadata, streaming handle I/O, and advisory locking |
| `Vfs\Plugin\LocalFile` | class | Read/write access to the local Linux filesystem; stores Econet metadata in `.inf` sidecar files; supports `flock()` advisory locking |
| `Vfs\Plugin\S3` | class | Read/write access to Econet files stored as S3 objects with `.inf` sidecars; local disk read-cache; supports multiple bucket/prefix mappings |
| `Vfs\Plugin\Catalogue` | class | Read-only plugin serving files described in a remotely-fetched JSON `index.json` catalogue, with local caching and configurable reload interval |
| `Vfs\Plugin\DfsSsd` | class | Read-only access to files inside Acorn DFS SSD floppy-disk image files |
| `Vfs\Plugin\AdfsAdl` | class | Read-only access to files inside Acorn ADFS ADL floppy-disk image files |
| `Vfs\Plugin\AdfsHD` | class | Read-only access to files inside Acorn ADFS hard-disk image files (matched by `scsi*.dat`) |
| `Vfs\Plugin\AfsImg` | class | Read-only access to files inside AFS (Acorn Level-3 Fileserver) disk image files |
| `Vfs\Plugin\AFS` | class | Read-only access to files inside AFS L3 hard-disk image files (matched by `scsi*.l3`) |

---

## Authentication

| Class | Kind | Description |
|---|---|---|
| `Authentication\Security` | class | Static singleton managing per-station login sessions, delegating to configured auth plugins, and enforcing privilege checks for admin operations |
| `Authentication\User` | class | Value object for an authenticated Econet user: username, Unix UID, home directory, boot option, privilege (S/U), CSD, and library path |
| `Authentication\Plugins\AuthPluginInterface` | interface | Contract for all auth plugins: `init`, `login`, `buildUserObject`, `getAllUsers`, `setPassword`, `createUser`, `removeUser`, `setPriv`, `setOpt` |
| `Authentication\Plugins\AuthPluginFile` | class | Auth plugin backed by a plain-text user file; supports plain/sha1/md5 password hashing; persists all changes back to disk |

---

## Admin web interface

| Class | Kind | Description |
|---|---|---|
| `Admin\Kernel` | class | Symfony micro-kernel that bootstraps the admin web interface |
| `Admin\SessionCookie` | final class | Symfony event subscriber that ensures a PHP session cookie is present on every admin HTTP request |
| `Admin\Service\Smarty` | class | Service that constructs and configures the Smarty template engine instance used by the admin interface |
| `Admin\Session\ReactSessionStorageFactory` | class | Symfony session storage factory that creates `ReactSessionStorage` instances suitable for the ReactPHP event loop |
| `Admin\Session\ReactSessionStorage` | class | Custom session storage that persists session data to disk files (`/tmp/session-*.dat`) to work around PHP native sessions in a long-running process |
| `Admin\Smarty\Extension` | class | Smarty engine extension that registers the `IfIsObjectCompiler` modifier compiler |
| `Admin\Smarty\IfIsObjectCompiler` | class | Smarty modifier compiler that translates the `\|is_object` template modifier into a PHP `is_object()` call |
| `Admin\Controller\IndexController` | class | Symfony controller rendering the admin dashboard and serving the favicon |
| `Admin\Controller\ServiceController` | class | Symfony controller for the per-service admin panel and spooled-file downloads |
| `Admin\Controller\EncapsulationController` | class | Symfony controller for the per-encapsulation admin panel (`/encapsulation?type=…`) |
| `Admin\Controller\FileServerController` | class | Symfony controller for the file server directory browser and file download |
| `Admin\Controller\TorchnetController` | class | Symfony controller for the TorchNet CP/M filesystem browser and file download |
