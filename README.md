[![pipeline status](https://gitlab.home-lan.co.uk:8443/docker/aun-filestore/badges/master/pipeline.svg)](https://gitlab.home-lan.co.uk:8443/docker/aun-filestore/commits/master) 
[![coverage report](https://gitlab.home-lan.co.uk:8443/docker/aun-filestore/badges/master/coverage.svg)](https://gitlab.home-lan.co.uk:8443/docker/aun-filestore/commits/master)

# Aims #
The main aim of this project is to create an Econet fileserver implementation, which communicates using Acorn's AUN protocol (Econet over IP/UDP) and physical Econet using the EconetUSB interface.

Our target platform is Linux and Unix like environments, with Windows as a target for later development (trying not to build things in away that will never work with windows).

# Main features #

## User authentication ##
* Plugable auth system, that allows the server to use multiple password backends. 
    * The system should map econet fileserver users to unix user.

## Storage ###
* The storage system should be plugable with the flowing plugins 
    * Files can be stored on a natvie unix fs (with meta data stored in files)
    * Disk image files (ssd,adl) can be stored in a directory on unix and used as a directory on the acorn side
    * Directories mounted from http servers (for public shared directories between servers)

## Services Model ##
Impliments a number of network services, that sit ontop of the econet encapsulation methods 

* File Server
    * User/Password Login with a priv model which works the same way, as the Acorn filestore
    * Boot flags are modeled 
    * Implements all the Acorn filestore calls, and some of the MDFS extra features 
* Print Service
    * Receives print jobs from BBC/Acorn workstations over Econet
    * Raw print data is spooled to a per-user sub-directory under the configured spool directory
    * Optionally converts raw spool files using a configurable shell script (see Print Server Configuration below)
    * Spooled files are listed and downloadable from the admin web front end
* IPv4
    * Implements the EconetA standard for IPv4 over Econet (AUN)
    * Supports both DCI-2 (BBC Micro IPROM, 0x21/0x22 ARP flags) and DCI-4 (later RiscOS, 0xA1/0xA2 ARP flags) ARP dialects — the server auto-detects which dialect each machine uses and replies in kind
    * Routes IPv4 packets between Econet networks, with a configurable routing table supporting multiple routes and metrics
    * NAT TCP proxy: maps virtual IP:port addresses (reachable from Econet) to real TCP destinations, letting BBC Micros and similar machines reach TCP services on the LAN or internet
    * Limited ICMP support (see below)

* BBCTerm
    * Support for Andrew Gordons, BBC Term. Which allows BBC clients on Econet, to connect a terminal application to one of the configured commands that the service allows to run on the Linux box

* TorchNet
    * File services for Torch Communicator workstations running CP/M, over the TorchNet protocol on Econet ports 0x90 and 0x91.
    * Translates between CP/M 8+3 filenames and the Acorn filesystem naming conventions via the built-in CP/M compatibility layer (CpmVfs).
    * Supported operations:
        * Open file (read-only, write-only, or shared read/write)
        * Create file
        * Close file
        * Read block — random-access 128-byte CP/M sector reads
        * Write block — random-access 128-byte CP/M sector writes
        * Delete file
        * Rename file
        * Search First / Search Next — directory listing with CP/M 8+3 wildcard patterns (`?`)
    * Drive letters map to directories on the Acorn filesystem.  The default mapping for drive `E` is `$.TorchDrives.E`.  Each drive can be overridden in the config file with a key of the form `torchnet_drive_e` (lowercase drive letter):

    ~~~
    torchnet_drive_e = $.TorchDrives.E
    torchnet_drive_f = $.TorchDrives.F
    ~~~

    * Files are stored on the Acorn filesystem with `\` as the CP/M extension separator in the filename (e.g. the CP/M file `MYPROG.COM` is stored with the Acorn name `MYPROG\COM` inside the drive directory). The CP/M compatibility layer translates this transparently so Torch clients see standard `8.3` names.


## Admin Web Front End ##
A browser-based admin interface is included and starts automatically with the server.

* Default address: `http://<host>:8080` (configurable with `webadmin_listen_address` and `webadmin_listen_port`)
* Lists all running services and their current status, with the ability to enable or disable each service
* **File Server** admin page shows currently logged-in sessions, open file streams, and all configured users
* **IPv4** admin page has five tabs:
    * *ARP Table* — live ARP cache mapping Econet station/network pairs to IP addresses
    * *IPv4 Interfaces* — the virtual IP interfaces currently configured on the Econet
    * *Routing Table* — the active routing table with destination, subnet, gateway, and metric
    * *NAT Rules* — the loaded NAT proxy rules (virtual IP:port → real IP:port)
    * *Conn Track Entries* — active TCP proxy sessions showing source/destination addresses, ports, connection state, and last-activity time
* **Print Server** admin page has two tabs:
    * *Print Jobs* — lists jobs currently in progress (data still being received from a workstation)
    * *Spooled Files* — lists all spool files stored on disk, grouped by user, with file size and modification time; each file has a **Download** button to retrieve it directly from the browser

## Network Encapsulation ##

The server supports three ways to carry Econet traffic, each targeting a different physical medium.  All three are active simultaneously; the correct transport is chosen automatically for each outbound packet based on the destination address.  Services (file server, print server, etc.) work entirely with a common internal `EconetPacket` representation and are unaware of which transport was used.

| Encapsulation | Transport | Typical use |
|---|---|---|
| **AUN** | UDP/IP | Standard Acorn Universal Networking over an Ethernet LAN |
| **Piconet** | Serial USB device | Physical Econet wire via an EconetUSB hardware interface |
| **WebSocket** | TCP / WebSocket | Browser-based BBC Micro emulators (e.g. jsbeeb) |

### Outbound routing priority ###

When the server sends a packet to a given Econet network.station pair the routing decision follows a fixed priority order:

1. **WebSocket** — if a WebSocket client has been dynamically allocated that exact network.station, the packet is delivered over the WebSocket connection.
2. **Piconet** — if the destination network number appears in the Piconet map file, the packet is sent to the hardware interface.
3. **AUN** — all other addresses are sent as AUN UDP datagrams.

A Piconet-mapped network always takes precedence over AUN for that network, and an active WebSocket client always takes precedence over Piconet.

---

### AUN — Econet over UDP/IP ###

AUN encodes Econet packets as UDP datagrams on port 32768 (by default).  Each remote station is identified by its IP address, optionally qualified by UDP port.  The AUN map file translates between IP addresses and Econet network.station pairs.

#### AUN configuration ####

| Config key | Default | Description |
|---|---|---|
| `local_ip` | *(required)* | The server's own IP address.  Used to identify inbound packets addressed to this host. |
| `aun_listen_address` | `0.0.0.0` | IP address to bind the AUN UDP socket to.  Use `0.0.0.0` to listen on all interfaces. |
| `aun_listen_port` | `32768` | UDP port to listen on for incoming AUN packets. |
| `aun_default_port` | `32768` | UDP port used when sending AUN packets to a host that has no explicit port in the map. |
| `aunmap_file` | `aunmap.txt` | Path to the AUN map file (see below). |
| `aunmap_autonet` | `200` | Econet network number assigned to any IP address that sends AUN traffic but has no map entry.  The station number is taken from the last octet of the IP address. |
| `version_minor` | *(required)* | Minor version byte returned in AUN echo (Immediate, cb=8) replies. |
| `version_majour` | *(required)* | Major version byte returned in AUN echo replies. |

#### AUN map file ####

The AUN map file (`aunmap_file`) defines how IP addresses are translated to and from Econet network.station pairs.  Two entry types are supported and may be mixed freely in the same file.

**Subnet entry** — maps a whole /24 subnet to an Econet network number.  The Econet station number is taken from the last octet of the IP address.

~~~
<ip>/<prefix>  <econet_network>
~~~

**Host entry** — maps a single IP address (or a specific IP:port combination) to an exact Econet network.station.  A host entry with an explicit port only matches traffic arriving on that UDP port; this is useful when running multiple logical Econet stations on the same host.

~~~
<ip>  <econet_network>.<econet_station>
<ip>:<udp_port>  <econet_network>.<econet_station>
~~~

Host entries take precedence over subnet entries for the same IP address.

Example `aunmap.txt`:

~~~
# Whole 192.168.0.0/24 subnet → Econet network 129.
# 192.168.0.40 becomes Econet 129.40, 192.168.0.1 becomes 129.1, etc.
192.168.0.0/24 129

# A single host at 192.168.2.20 is Econet station 129.29
192.168.2.20 129.29

# A second logical station on localhost, distinguished by UDP port
127.0.0.1:10101 0.101
~~~

Lines that do not match any of these patterns (including lines beginning with `#`) are silently ignored — there is no formal comment syntax, but unrecognised lines cause no harm.

Any IP address that appears in inbound AUN traffic but is not covered by any map entry is automatically assigned to the `aunmap_autonet` network (default 200), with the station number derived from the last octet of the IP.  This means unknown stations are accessible but may appear under an unexpected network number rather than causing an error.

---

### Piconet — Physical Econet via EconetUSB ###

The Piconet transport communicates with an EconetUSB serial USB hardware interface that provides a gateway onto a real physical Econet wire.  Because all traffic from the physical wire arrives through a single hardware device, the Piconet map only needs to list which Econet network numbers are reachable through it — the station address is carried in the Econet packet itself.

#### Piconet configuration ####

| Config key | Default | Description |
|---|---|---|
| `piconet_device` | `dev/econet` | Path to the Unix serial device file for the EconetUSB adapter.  The server opens this path with a `file:///` prefix via the ReactPHP event loop. |
| `piconetmap_file` | `piconetmap.txt` | Path to the Piconet map file (see below). |
| `piconet_station` | `254` | The Econet station number the server presents itself as on the physical Econet wire. |
| `piconet_local_network` | `1` | The global Econet network number that corresponds to "network 0" on the local physical wire.  Outbound packets destined for this network have their destination network byte replaced with `0` before transmission to the device. |

#### Piconet map file ####

The Piconet map file (`piconetmap_file`) lists the Econet network numbers that are reachable via the physical Econet hardware.  Any outbound packet whose destination network appears in this file is sent to the EconetUSB device instead of going via AUN.

Format — one network number per line:

~~~
<econet_network>
~~~

Example `piconetmap.txt`:

~~~
1
2
129
~~~

Lines that contain no digits are ignored.  There is no comment syntax, but non-numeric content on a line does not cause an error.

Up to 8 network numbers may be listed.

---

### WebSocket — Econet for Browser Emulators ###

The WebSocket transport allows browser-based BBC Micro emulators (such as jsbeeb) to participate in Econet as first-class stations.  Each browser tab that connects is dynamically assigned a unique Econet network.station pair from a pool of network numbers configured in the WebSocket map file.  When the tab closes its address is released back to the pool.

#### WebSocket configuration ####

| Config key | Default | Description |
|---|---|---|
| `websocket_listen_address` | `0.0.0.0` | IP address to bind the WebSocket server to. |
| `websocket_listen_port` | `8090` | TCP port for incoming WebSocket connections. |
| `websocket_network_address` | `128` | Econet network number the server presents itself as to WebSocket clients. |
| `websocket_station_address` | `254` | Econet station number the server presents itself as to WebSocket clients. |
| `websocketmap_file` | `websocket_map.cfg` | Path whose **existence** is checked at startup.  Must exist for the WebSocket map to initialise. |
| `websocketmap_dynamic_network_range_file` | *(required if WebSocket is in use)* | Path to the file listing which Econet network numbers are available for dynamic allocation to WebSocket clients. |

#### WebSocket map file ####

The dynamic network range file (`websocketmap_dynamic_network_range_file`) lists the Econet network numbers whose station address space (stations 1–253) may be allocated to connecting WebSocket clients.  The format is identical to the Piconet map file — one network number per line.

~~~
<econet_network>
~~~

Example:

~~~
130
131
~~~

With this configuration up to 506 browser clients (253 stations × 2 networks) can be connected simultaneously.  Addresses are allocated sequentially; when a client disconnects its slot is freed for re-use.

**Note:** Both `websocketmap_file` (existence check) and `websocketmap_dynamic_network_range_file` (content read) must be configured for the WebSocket transport to initialise.  If `websocketmap_dynamic_network_range_file` is not set the WebSocket map will have no networks available and all dynamic allocation requests will fail.

#### Dynamic address allocation ####

When a WebSocket client connects it sends a `ctrl` message requesting an address:

~~~json
{"type": "ctrl", "action": "dynamic_alloction_request"}
~~~

The server responds with the allocated Econet network.station as a string (e.g. `"130.1"`).  Subsequent `pkt` messages from the client must be addressed to the server's `websocket_network_address` / `websocket_station_address`.  The server routes replies to the client using its allocated address.

---

## Print Server Configuration ##

Two config keys control the print server spool and conversion behaviour:

| Config key | Default | Description |
|---|---|---|
| `print_server_spool_dir` | `/tmp/econetprint` | Directory under which per-user spool sub-directories are created |
| `print_server_conversion_script` | *(none)* | Optional shell command run after each job is spooled (see below) |

### Conversion script ###
When `print_server_conversion_script` is set the server runs it as a background process after the raw `.raw` spool file is written.  The command string supports two placeholders that the server substitutes at run time:

| Placeholder | Substituted with |
|---|---|
| `%source%` | Full path to the input `.raw` spool file |
| `%destination%` | Full path for the converted output file (`.ps` extension) |

Example config value using `esc2ps`:

~~~
print_server_conversion_script = /usr/bin/esc2ps -i %source% -o %destination%
~~~

> **Note:** The placeholder spelling `%source%` is intentional — it matches what the server substitutes.  Use it exactly as shown.

## IPv4 Configuration ##

Five config keys control the IPv4 service.  The three file-based keys point to plain-text config files read at startup.

| Config key | Default | Description |
|---|---|---|
| `ipv4_interfaces_file` | `interfaces.txt` | Defines the virtual IP interfaces hosted on the Econet |
| `ipv4_routes_file` | `routes.txt` | Defines the IP routing table |
| `ipv4_nat_file` | `nat.txt` | Defines NAT TCP proxy rules |
| `nat_default_station` | `254` | Econet station number used as the source address for NAT reply packets |
| `nat_default_network` | `254` | Econet network number used as the source address for NAT reply packets |

### Interfaces file ###

Each line defines one virtual IP interface that the server will answer ARP for and route traffic through.  The server will respond to ARP who-has requests for any IP listed here.

Format: `<econet_network> <econet_station> <ip_address> <subnet_mask>`

~~~
# Econet network 1, station 1 owns 10.10.1.1/24
1 1 10.10.1.1 255.255.255.0

# Econet network 129, station 202 owns 10.10.129.202/24
129 202 10.10.129.202 255.255.255.0
~~~

### Routes file ###

Each line defines a static route.  When the server needs to forward a packet to a destination not directly on one of the interface subnets it consults the routing table.  Where multiple routes match, the most specific (longest prefix) wins; ties are broken by metric (lower is preferred).  Lines beginning with `#` are comments.

Format: `<destination_network>/<subnet_mask> <via_gateway_ip> [metric]`

~~~
# Two paths to 192.168.4.0/24 — metric 20 gateway is preferred
192.168.4.0/255.255.255.0 192.168.0.1 30
192.168.4.0/255.255.255.0 192.168.0.10 20

# Default route (0.0.0.0/0.0.0.0 matches everything not more specifically routed)
0.0.0.0/0.0.0.0 192.168.0.103

# Commented-out alternative default route
#0.0.0.0/0.0.0.0 192.168.0.1
~~~

The gateway IP must be reachable on one of the configured interface subnets.

### NAT / TCP Proxy ###

The NAT feature is a **TCP reverse proxy**, not traditional IP masquerading.  Each rule maps a virtual IP address and port — which Econet clients can connect to as if it were a real host — to a real TCP destination.  When the server sees a SYN packet destined for a virtual IP:port it opens a TCP connection to the real destination and proxies bytes in both directions, so an 8-bit BBC Micro can reach a Telnet server, SMTP relay, web server, or any other TCP service.

Each rule is one line in the NAT file.

Format: `<virtual_ip> <real_ip> <virtual_port> <real_port>`

~~~
# Port 25 (SMTP) on virtual IP 192.168.5.1 → real mail relay at 81.23.53.156:25
192.168.5.1 81.23.53.156 25 25

# Port 80 (HTTP) on the same virtual IP → internal web server
192.168.5.1 192.168.0.202 80 80

# Telnet on 192.168.5.2 → a host on the LAN
192.168.5.2 192.168.0.2 23 23

# Port 200 on 192.168.5.3 → a high port on another machine
192.168.5.3 192.168.0.3 200 1024
~~~

The virtual IPs must **not** overlap with any real Econet station's IP or with any interface IP defined in the interfaces file — they are purely fictional addresses that the server conjures up for Econet clients.  The `nat_default_station` and `nat_default_network` config values set the Econet layer-2 address that NAT reply packets appear to come from; this should be a station/network pair that does not conflict with any real Econet device (the default of 254/254 is suitable for most setups).

### ICMP ###

A limited subset of ICMP is implemented.  No configuration is required.

**Supported:**
* **Echo reply (ping)** — the server responds to ICMP echo requests (type 8) sent to any configured interface IP, returning a type-0 echo reply with the identifier, sequence number, and payload copied from the request.
* **Destination Unreachable — Network Unreachable (type 3, code 0)** — sent back to the originating host when an IP packet arrives that has no matching route and no directly connected interface for the destination subnet.
* **Destination Unreachable — Host Unreachable (type 3, code 1)** — sent back when a NAT TCP proxy connection attempt to the real destination fails (e.g. the remote host is down or refusing connections).

**Not supported:**
* The server cannot originate echo requests (it cannot ping out).
* ICMP Time Exceeded (TTL expiry) is not generated when a forwarded packet's TTL reaches zero.
* ICMP Redirect, Timestamp, Router Advertisement, and all other message types are not implemented.
* Fragmentation-related ICMP (Destination Unreachable — Fragmentation Needed) is not generated; the server does not fragment packets.

## VFS (Virtual File System) ##

The file server uses a layered, plugin-based VFS.  Each plugin is a PHP class implementing a common static interface.  Plugins are tried in order; the first one that handles a path wins.  Multiple plugins can be active simultaneously, covering different subtrees of the Econet directory tree.

### VFS Configuration ###

The active plugins are listed (comma-separated) in the `vfs_plugins` config key:

~~~
vfs_plugins = LocalFile,S3
~~~

Plugin class names map to `HomeLan\FileStore\Vfs\Plugin\<Name>`.

### Available Plugins ###

| Plugin | Description |
|---|---|
| `LocalFile` | Stores files on the local filesystem.  This is the standard plugin for most installations. |
| `AFS` | Acorn Filing System — mounts AFS volumes. |
| `DfsSsd` | Mounts Acorn DFS `.ssd` disc images as directories. |
| `AdfsAdl` | Mounts Acorn ADFS `.adl` disc images as directories. |
| `AdfsHD` | Mounts Acorn ADFS hard-disc images as directories. |
| `S3` | Stores files in Amazon S3 (or S3-compatible) buckets (see below). |
| `Catalogue` | Read-only plugin that serves files listed in a remotely-fetched JSON catalogue (see below). |

### .INF sidecar files ###

The `LocalFile` and `S3` plugins store file metadata (load address and exec address) in a sidecar file with a `.inf` extension alongside each data file.  The format is a single line:

~~~
TAPE file LLLLLLLL EEEEEEEE
~~~

where `LLLLLLLL` and `EEEEEEEE` are the load and exec addresses in zero-padded 8-digit hex.

The disk image plugins (`DfsSsd`, `AdfsAdl`, `AdfsHD`, `AFS`) do not use .inf sidecars — load and exec metadata is embedded directly in the native Acorn disc image format.

### LocalFile Plugin Configuration ###

| Config key | Default | Description |
|---|---|---|
| `vfs_plugin_localfile_root` | *(required)* | Absolute path to the root of the local filesystem tree |

### S3 Plugin Configuration ###

The S3 plugin stores Econet files as S3 objects.  Each file has a data object and a sidecar `.inf` object at the same key with `.inf` appended.  Multiple bucket/prefix mappings can be configured, each covering a different subtree of the Econet VFS.

Files fetched from S3 are cached to local disk.  Subsequent reads are served from the cache, avoiding repeated S3 network round-trips.  Write operations invalidate the relevant cache entry.

| Config key | Default | Description |
|---|---|---|
| `vfs_plugin_s3_mappings` | *(none)* | JSON array of mapping objects (see below) |
| `vfs_plugin_s3_cache_dir` | `/var/lib/cache/aun/s3/` | Local directory used to cache files fetched from S3.  Must be writable by the server process.  Set to an empty string to disable the cache (reads always go to S3). |

Each mapping object has the following fields:

| Field | Required | Default | Description |
|---|---|---|---|
| `econet_path` | Yes | — | The Econet VFS path prefix this mapping covers (e.g. `$.s3files`) |
| `bucket` | Yes | — | S3 bucket name |
| `prefix` | Yes | — | S3 key prefix within the bucket (e.g. `econet`) — objects are stored as `prefix/filename` |
| `region` | Yes | — | AWS region (e.g. `eu-west-1`) |
| `write_enabled` | No | `false` | Set to `true` to allow the file server to write, delete, rename, and create files in this mapping.  Omitting this field or setting it to `false` makes the mapping **read-only** — Econet clients can read and list files but all write operations are refused with a hard error. |
| `endpoint` | No | — | Custom S3-compatible endpoint URL (e.g. `http://localhost:9000` for MinIO).  When set, path-style URL addressing is enabled automatically.  Leave unset to use AWS S3. |
| `key` | No | — | AWS access key ID (or MinIO access key).  If omitted when connecting to AWS, the SDK's default credential chain is used (IAM role, environment variables, `~/.aws/credentials`, etc.).  Required for MinIO. |
| `secret` | No | — | AWS/MinIO secret access key.  Required if `key` is set. |

Example configuration with two mappings:

~~~
vfs_plugin_s3_mappings = [{"econet_path":"$.s3files","bucket":"my-econet-bucket","prefix":"econet","region":"eu-west-1"},{"econet_path":"$.archive","bucket":"my-archive-bucket","prefix":"bbc","region":"eu-west-1","key":"AKIAIOSFODNN7EXAMPLE","secret":"wJalrXUtnFEMI/K7MDENG/bPxRfiCYEXAMPLEKEY"}]
~~~

#### Connecting to MinIO or another local S3-compatible server ####

Set `endpoint` to the base URL of the server and supply explicit credentials.  Path-style URL addressing is enabled automatically when `endpoint` is present.

~~~
vfs_plugin_s3_mappings = [{"econet_path":"$.local","bucket":"econet","prefix":"files","region":"us-east-1","endpoint":"http://localhost:9000","key":"minioadmin","secret":"minioadmin","write_enabled":true}]
~~~

The `region` value is not checked by MinIO but must be present to satisfy the SDK.  `us-east-1` is a safe default.

Econet path dots are converted to S3 key slashes.  For example, the Econet file `$.s3files.docs.readme` is stored as the S3 object `econet/docs/readme` (with its metadata in `econet/docs/readme.inf`).

Subdirectories are represented by zero-byte S3 objects with a trailing `/`.

#### Local disk cache ####

Files fetched from S3 are stored in `{vfs_plugin_s3_cache_dir}/{bucket}/{s3_key}`, mirroring the S3 namespace under the local directory.  The cache directory is created automatically if it does not exist.

**Invalidation rules:**

* Opening a file handle for writing, calling `saveFile`, `createFile`, `deleteFile`, or `moveFile` immediately removes the cached copy for the affected key.
* While a write handle is open for a file, any concurrent read handles for the same file bypass the cache and fetch the current content directly from S3.  No new cache entry is written until the write handle is closed.
* After the write handle is closed the cache fence is lifted and subsequent reads cache normally.

If the cache directory is not writable the plugin logs a debug message and continues without caching; S3 reads work normally.

#### Uploading files to S3 ####

The `src/util/s3upload` script uploads local files (with .inf sidecars) to one of the configured S3 mappings.  It writes directly to S3 and is not subject to the `write_enabled` flag — it always has write access regardless of how the mapping is configured for the file server.

~~~
src/util/s3upload --mapping=$.s3files --config=/etc/aun-filestored /local/path/to/files
~~~

Options:

| Option | Description |
|---|---|
| `--mapping` | Econet VFS path of the target S3 mapping (must match the `econet_path` in the config) |
| `--config` | Path to the config directory (same as the main server's `-c` option) |
| `--dry-run` | Show what would be uploaded without actually uploading anything |

The source files must follow the same `.inf` sidecar convention as the LocalFile plugin.  If no `.inf` file exists alongside a source file the load and exec addresses default to `0xFFFF0000`.

### Catalogue Plugin Configuration ###

The Catalogue plugin is a **read-only** VFS plugin that exposes files described in a remotely-fetched JSON catalogue.  The catalogue is fetched over HTTP or HTTPS at startup and periodically refreshed.  When a file is first accessed its content is downloaded from the URL recorded in the catalogue and stored in a local disk cache; subsequent reads are served from the cache.  If the catalogue is reloaded and a file's version number has changed the cached copy is discarded and the file is re-fetched on next access.

Use the `mkcatarchive` utility (see [mkcatarchive — Building Catalogue Archives](#mkcatarchive--building-catalogue-archives)) to package a local directory tree into a tar archive containing the catalogue `index.json` and all associated files, ready to be served over HTTP.  Re-running `mkcatarchive` with `--existing-tar` carries version numbers forward so that only changed files are re-downloaded by clients.

Multiple catalogue URLs can be mapped to different subtrees of the Econet VFS.

| Config key | Default | Description |
|---|---|---|
| `vfs_plugin_catalogue_mappings` | *(none)* | JSON array of mapping objects (see below) |
| `vfs_plugin_catalogue_cache_dir` | `/var/lib/cache/aun/catalogue/` | Local directory used to cache downloaded files.  Must be writable by the server process. |
| `vfs_plugin_catalogue_reload_interval` | `3600` | Default catalogue reload interval in seconds.  Can be overridden per mapping. |

Each mapping object has the following fields:

| Field | Required | Default | Description |
|---|---|---|---|
| `econet_path` | Yes | — | The Econet VFS path prefix this mapping covers (e.g. `$.apps`) |
| `catalogue_url` | Yes | — | HTTP or HTTPS URL of the **directory** that contains `index.json`; the plugin appends `/index.json` automatically |
| `reload_interval` | No | global default | Reload interval in seconds for this specific mapping, overrides `vfs_plugin_catalogue_reload_interval` |

Example configuration:

~~~
vfs_plugin_catalogue_mappings = [{"econet_path":"$.apps","catalogue_url":"https://example.com/apps"},{"econet_path":"$.games","catalogue_url":"https://example.com/games","reload_interval":7200}]
vfs_plugin_catalogue_cache_dir = /var/lib/cache/aun/catalogue/
vfs_plugin_catalogue_reload_interval = 3600
~~~

#### Catalogue JSON format ####

The catalogue file must be a JSON object with a `files` key containing an object where each key is the file path relative to the mapping's `econet_path`, using `.` as the directory separator:

~~~json
{
  "files": {
    "game": {
      "version": 1,
      "md5sum":  "d41d8cd98f00b204e9800998ecf8427e",
      "load":    4294901760,
      "exec":    4294901760,
      "size":    16384,
      "url":     "https://example.com/files/game"
    },
    "utils.editor": {
      "version": 3,
      "md5sum":  "abc123def456",
      "load":    0,
      "exec":    0,
      "size":    8192,
      "url":     "https://example.com/files/utils/editor"
    }
  }
}
~~~

| Field | Description |
|---|---|
| `version` | Integer version number, starting at `1` and incrementing by `1` with each new version of the file.  When the catalogue is reloaded and this value has changed, the locally-cached copy is invalidated and the file is re-fetched on next access. |
| `md5sum` | MD5 checksum of the file (informational; the plugin does not currently verify it but it is included for client tooling use). |
| `load` | Econet load address (integer). |
| `exec` | Econet execute address (integer). |
| `size` | File size in bytes. |
| `url` | HTTP or HTTPS URL from which the file content can be downloaded. |

Directories are inferred automatically from path prefixes — they do not need to be listed explicitly in the catalogue.  In the example above, `utils` appears as a directory in the listing of `$.apps` because the file `utils.editor` exists.

#### Local disk cache ####

Downloaded files are stored in `{vfs_plugin_catalogue_cache_dir}/{md5_of_catalogue_url}/{file_path}`, where `file_path` replaces `.` separators with `/`.  Alongside each cached file a `.ver` sidecar stores the version string from the catalogue.

**Invalidation rules:**

* When the catalogue is reloaded (on startup or via housekeeping), any file whose version string in the new catalogue differs from the version stored in the local `.ver` sidecar is removed from the cache.  The file is re-fetched on next access.
* A stale version tag discovered during a read (`.ver` content does not match the in-memory catalogue) also triggers immediate invalidation and a fresh fetch.

If the cache directory is not writable the plugin logs a debug message and continues without caching; files are always fetched from their URL.

#### Relative file URLs ####

File `url` values in the catalogue may be either absolute or relative:

* **Absolute** — any URL containing `://` (e.g. `https://cdn.example.com/game`) is used unchanged.
* **Relative** — a path without a scheme (e.g. `game`, `utils/editor`) is resolved relative to the mapping's `catalogue_url` (which is already the directory).  For example, if `catalogue_url` is `https://example.com/myfiles`, the relative URL `utils/editor` resolves to `https://example.com/myfiles/utils/editor`.

The `mkcatarchive` utility (see below) always generates relative URLs, so the same archive can be served from any base URL without editing the catalogue.

#### HouseKeeping ####

The Catalogue plugin participates in the server's regular housekeeping cycle.  Each call to `houseKeeping()` checks whether the elapsed time since the last successful catalogue fetch exceeds the configured `reload_interval` (per-mapping or global).  If it does, the catalogue is re-fetched and version checks are run against all cached files.

### mkcatarchive — Building Catalogue Archives ###

`src/util/mkcatarchive` builds a tar archive from a local directory tree that is ready to be served as a Catalogue VFS source.  The archive contains all the files from the directory (`.inf` sidecars excluded) plus an `index.json` catalogue at the archive root.

~~~
src/util/mkcatarchive [--output=<path>] [--existing-tar=<path>] <source>
~~~

| Option | Description |
|---|---|
| `source` | Path to the local directory to archive (required) |
| `--output` / `-o` | Output tar file path.  Defaults to `<dirname>.tar` in the current directory. |
| `--existing-tar` / `-e` | Path to a previously-built tar archive.  Its `index.json` is used to carry version numbers forward (see below). |

#### What gets archived ####

Every file in the source tree is added to the archive, preserving subdirectory structure.  `.inf` sidecar files are read for load/exec address metadata but are not included in the archive.  The `index.json` is generated automatically and added at the archive root.

#### index.json format ####

Each file entry is keyed by its path relative to the archive root, using the Econet `.` separator.  The file URL is the same path using `/` as the separator (relative to `index.json`, so the archive can be hosted at any URL).

Example output for a directory containing `game` (with a `.inf` sidecar) and `utils/editor`:

~~~json
{
  "files": {
    "game": {
      "version": 1,
      "md5sum": "d41d8cd98f00b204e9800998ecf8427e",
      "load": 4294901760,
      "exec": 4294901760,
      "size": 16384,
      "url": "game"
    },
    "utils.editor": {
      "version": 1,
      "md5sum": "abc123def456",
      "load": 0,
      "exec": 0,
      "size": 8192,
      "url": "utils/editor"
    }
  }
}
~~~

#### Version numbering ####

Version numbers are integers starting at `1`.  When `--existing-tar` is not supplied, every file receives version `1`.

When `--existing-tar` is supplied, the tool extracts `index.json` from the existing archive and compares MD5 checksums:

| Situation | Version assigned |
|---|---|
| File not in old `index.json` (new file) | `1` |
| File in old `index.json` with same MD5 | Old version number (unchanged) |
| File in old `index.json` with different MD5 | Old version number `+ 1` |

This lets the Catalogue VFS plugin detect which cached files need to be re-downloaded when an updated archive is deployed.

#### Typical workflow ####

~~~
# Build the initial archive from a directory of BBC Micro software.
src/util/mkcatarchive --output=myfiles.tar /srv/bbcfiles

# After updating some files, rebuild — carry version numbers forward.
src/util/mkcatarchive --output=myfiles-new.tar --existing-tar=myfiles.tar /srv/bbcfiles
mv myfiles-new.tar myfiles.tar
~~~

## Developer Documentation ##

Guides for programmers who want to extend the server:

* [Traffic Encapsulation](docs/encapsulation.md) — how AUN, Piconet, and WebSocket encapsulations work internally; how to add a new encapsulation
* [Service Providers and the Admin System](docs/service-providers.md) — how service providers and port-based routing work; how to write a new provider; how the admin web front end integrates with providers and how to add admin support
* [Virtual File System (VFS)](docs/vfs.md) — how the plugin chain, exception model, and file locking work; how to write a new VFS plugin
* [Authentication System](docs/authentication.md) — how the plugin-based auth layer and session management work; how to write a new auth plugin

## It would be nice if ##
* Work has started on a WebSocket Interface for
    * Javascript BBC Emulators
    * Operating as a bridge and allow econet frames to be passed over the public internet to other bridges securely (tcp socket, using ssl).

# Todo #
While all the auth and file serving features are complete, there are some outstanding areas that need implementing.

The rest interface and control client has yet to be implemented, however there is now a basic web front end (see Admin Web Front End above).

# Install #
## Docker Install ##
There is a docker image pre-built read for use on dockerhub.

~~~
docker run --name=filestored -p 32768/udp -d crowly/aun-filestore
~~~
The udp port 32768 needs exposing as this is the port AUN uses for the passing a emulate Econet traffic.   

The docker image can also be built localy 

~~~
git clone https://github.com/johnhomelan/aun-filestore.git
cd aun-filestore
docker build -t aun-filestore .
docker run --name=filestored -p 32768/udp -d aun-filestore
~~~

The image has a number of volumes 
* /var/lib/aun-filestore-root
** The root of the fs exported by the filestore
* /var/spool/aun-filestore-print
** Where print jobs submitted to the filestore a saved
* /etc/aun-filestored
** The config directory (see the Config.md file in docs for details of the config options)
* /var/log
** The directory used for log storage 

~~~
docker run --name=filestored -p 32768/udp -v /storage/root:/var/lib/aun-filestore-root -v /storage/print:/var/spool/aun-filestore-print -v /storage/config:/etc/aun-filestored -v /storage/log:/var/log -d crowly/aun-filestore
~~~

## Install From Source ##

At the moment there are no rpm and deb packages built for easy install (this will happen before the release of the version 0.1).  However it can be run from source, your machine will need to have php installed and the php-pcntl module.
  
* Check out the source from GIT  
* Make the file filestored executable (chmod u+x filestored)
* Run "composer install" to fetch the external libraries, and build the autoloader
* Create a directory to act as root of your econet file system
* Create a directory to hold your config files 
* Write a basic config file (see the config section)
* Run the server (./filestored -c <conifg_dir>)

## RPM ##

An rpm can be built using ant from the source.

~~~
git clone https://github.com/johnhomelan/aun-filestore.git
cd aun-filestore
ant rpm
~~~

