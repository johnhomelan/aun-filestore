Configuration
=

Once you've installed aun-filestore you will need to configure it.  If you've installed it using one of the packages (rpm, deb) it will have a number of useful defaults and should work more or less out of the box.

For Unix platforms the config is read from the directory `/etc/aun-filestored`; any file ending in `.conf` will be read by the config system.  If two files define the same config key with conflicting values the one in the later file (alphabetical directory order) wins.

Security
==
The security system authenticates users making use of the file and print servers.  It uses one or more authentication plugins that can each use a different backend format.

**security_mode**

Controls whether the server runs in single-user or multi-user mode.  In multi-user mode the LocalFile VFS plugin switches file-system ownership to each user's Unix UID when accessing files, enforcing per-user permissions.  The default is `singleuser`.

Valid values: `singleuser`, `multiuser`

~~~~~~
security_mode = singleuser
~~~~~~

**security_max_session_idle**

How long (in seconds) a user's connection may be idle before they are automatically logged out.  Default is `2400`.

~~~~~~
security_max_session_idle = 2400
~~~~~~

**security_auth_plugins**

Which authentication plugins to use, in order.  Multiple values are comma-separated.  The first plugin in the list is also the one used when the `NEWUSER` CLI command creates an account.  Default is `file`.

~~~~~~
security_auth_plugins = file
~~~~~~

**security_default_unix_uid**

The Unix UID assigned to newly created user accounts when no explicit UID is specified.  Default is `500`.

~~~~~~
security_default_unix_uid = 500
~~~~~~

**system_user_id**

The Unix UID the server process reverts to after impersonating a user (multi-user mode only).  This should be the UID the server itself runs as.  No default — must be set when `security_mode = multiuser`.

~~~~~~
system_user_id = 1001
~~~~~~

##### File auth plugin #####

**security_plugin_file_user_file**

Path to the plain-text user file used by the `file` auth plugin.  Default is `users-live.txt`.

~~~~~~
security_plugin_file_user_file = /etc/aun-filestored/passwd
~~~~~~

**security_plugin_file_default_crypt**

Password hashing algorithm used by the `file` auth plugin when storing new or changed passwords.  Default is `md5`.

Valid values:
* `md5` — unsalted MD5 hash
* `sha1` — unsalted SHA-1 hash
* `plain` — plain text (not recommended)

~~~~~~
security_plugin_file_default_crypt = md5
~~~~~~


Network
==

**local_ip**

The server's own IP address.  Used by the AUN handler to identify inbound packets addressed to this host.  Required — no default.

~~~~~~
local_ip = 192.168.0.10
~~~~~~

**aun_listen_address**

IP address the server binds the AUN UDP socket to.  Use `0.0.0.0` to listen on all interfaces.  Default is `0.0.0.0`.

~~~~~~
aun_listen_address = 0.0.0.0
~~~~~~

**aun_listen_port**

UDP port the server listens on for incoming AUN packets.  Default is `32768`, the standard AUN port.

~~~~~~
aun_listen_port = 32768
~~~~~~

**aun_default_port**

UDP port the server sends AUN packets to when the destination host has no explicit port in the AUN map.  Default is `32768`.

~~~~~~
aun_default_port = 32768
~~~~~~

**aunmap_file**

Path to the AUN map file.  This file maps IP addresses and subnets to Econet network/station numbers.  Default is `aunmap.txt`.

The file format is:

~~~~~~
<ip>/<prefix>  <econet_network>          (subnet entry)
<ip>  <econet_network>.<econet_station>   (host entry)
<ip>:<udp_port>  <econet_network>.<econet_station>
~~~~~~

~~~~~~
aunmap_file = aunmap.txt
~~~~~~

**aunmap_autonet**

Econet network number automatically assigned to any IP address that sends AUN traffic but has no entry in the AUN map.  The station number is derived from the last octet of the IP address.  Default is `200`.

~~~~~~
aunmap_autonet = 200
~~~~~~

**econet_data_stream_port**

Econet port number used for streaming data between the file server and a client during multi-packet operations (GETBYTES, PUTBYTES, LOAD, SAVE).  This is an Econet port, not a UDP/TCP port.  Default is `0x97`.

~~~~~~
econet_data_stream_port = 0x97
~~~~~~

**bbc_default_pkg_sleep**

Microseconds to sleep between sending successive packets to a BBC client.  BBC Micros can be overwhelmed by rapid back-to-back replies.  Default is `40000`.

~~~~~~
bbc_default_pkg_sleep = 40000
~~~~~~

**version**

Version string reported in the file server `INFO` command reply.  Default is `1.01`.

~~~~~~
version = 1.01
~~~~~~

**version_major**

Major version byte returned in AUN echo (Immediate, cb=8) replies.  Default is `1`.

~~~~~~
version_major = 1
~~~~~~

**version_minor**

Minor version byte returned in AUN echo replies.  Default is `1`.

~~~~~~
version_minor = 1
~~~~~~

### WebSocket ###

**websocket_listen_address**

IP address to bind the WebSocket server to.  Default is `0.0.0.0`.

~~~~~~
websocket_listen_address = 0.0.0.0
~~~~~~

**websocket_listen_port**

TCP port for incoming WebSocket connections from browser-based BBC Micro emulators.  Default is `8090`.

~~~~~~
websocket_listen_port = 8090
~~~~~~

**websocket_network_address**

Econet network number the server presents itself as to WebSocket clients.  Default is `128`.

~~~~~~
websocket_network_address = 128
~~~~~~

**websocket_station_address**

Econet station number the server presents itself as to WebSocket clients.  Default is `254`.

~~~~~~
websocket_station_address = 254
~~~~~~

**websocketmap_file**

Path to the WebSocket map configuration file.  The file must exist for the WebSocket transport to initialise; its presence acts as an enable flag.  Default is `websocket_map.cfg`.

~~~~~~
websocketmap_file = websocket_map.cfg
~~~~~~

**websocketmap_dynamic_network_range_file**

Path to a file listing Econet network numbers available for dynamic allocation to connecting WebSocket clients, one per line.  Required when the WebSocket transport is in use — if not set, the transport will have no allocatable addresses.  No default.

~~~~~~
websocketmap_dynamic_network_range_file = websocket_networks.txt
~~~~~~

### Piconet ###

**piconet_device**

Path to the Unix serial device file for the EconetUSB hardware adapter.  Default is `dev/econet`.

~~~~~~
piconet_device = /dev/ttyUSB0
~~~~~~

**piconetmap_file**

Path to the Piconet map file listing the Econet network numbers reachable via the physical hardware interface, one per line.  Default is `piconetmap.txt`.

~~~~~~
piconetmap_file = piconetmap.txt
~~~~~~

**piconet_station**

Econet station number the server presents itself as on the physical Econet wire.  Default is `254`.

~~~~~~
piconet_station = 254
~~~~~~

**piconet_local_network**

The global Econet network number that corresponds to network `0` on the local physical wire.  Outbound packets for this network have their destination network byte replaced with `0` before transmission.  Default is `1`.

~~~~~~
piconet_local_network = 1
~~~~~~

### Bridge ###

**bridge_local_network_number**

The Econet network number this server reports as its local network when responding to bridge topology queries.  No default — must be set if the Bridge service is in use.

~~~~~~
bridge_local_network_number = 1
~~~~~~


Logging
==

**logbackend**

Controls where log output is written.  Default is `syslog`.

Valid values: `syslog`, `logfile`

~~~~~~
logbackend = logfile
~~~~~~

**loglevel**

Logging verbosity level, 0 (least verbose) to 7 (most verbose).  Default is `6`, which logs key events such as user login and logout.

~~~~~~
loglevel = 7
~~~~~~

**logstderr**

Whether to echo all log output to standard error as well as the configured backend.  `1` enables, `0` disables.  Default is `0`.

~~~~~~
logstderr = 0
~~~~~~

**logfile**

Path to the log file.  Only used when `logbackend = logfile`.

~~~~~~
logfile = /var/log/aun-filestored.log
~~~~~~


File System
==
The file system exported by the file server is composed of one or more VFS plugins layered on top of each other.  Plugins are tried in order; the first one that handles a given path wins.

**vfs_plugins**

Comma-separated list of VFS plugin names, in priority order (left = highest priority).  Plugin names are case-sensitive.  Default is `AFS,DfsSsd,AdfsAdl,AdfsHD,Mdfs,LocalFile`.

Available plugins: `LocalFile`, `S3`, `Catalogue`, `DfsSsd`, `AdfsAdl`, `AdfsHD`, `AFS`, `AfsImg`, `Mdfs`

~~~~~~
vfs_plugins = DfsSsd,AdfsAdl,LocalFile
~~~~~~

**vfs_default_disc_free**

Free-space value (in bytes) reported to BBC clients for the disc and for any user whose per-user quota has not been set (BBC clients cannot handle real modern disk sizes, so this is intentionally small).  This value also serves as the default per-user disc quota — it is returned by `EC_FS_FUNC_GET_USER_FREE` (0x1E) for any user whose quota is `0`.  Default is `0x9000` (36,864 bytes).

~~~~~~
vfs_default_disc_free = 0x9000
~~~~~~

**vfs_default_disc_size**

Fake total-disc-size value reported to BBC clients.  Default is `0x9000`.

~~~~~~
vfs_default_disc_size = 0x9000
~~~~~~

**vfs_disc_name**

Name of the virtual disc exported by the file server.  Maximum 16 characters.  Default is `VFSROOT`.

~~~~~~
vfs_disc_name = VFSROOT
~~~~~~

**library_path**

Econet path of the default library directory.  This directory is searched for executables in addition to the user's current selected directory.  Default is `$.LIBRARY`.

~~~~~~
library_path = $.LIBRARY
~~~~~~

**vfs_home_dir_path**

Econet path of the directory under which per-user home directories are created.  Default is `$.home`.

~~~~~~
vfs_home_dir_path = $.HOME
~~~~~~

##### LocalFile plugin #####

**vfs_plugin_localfile_root**

Absolute Unix path of the local directory exported as the root of the Econet file system.  Default is `/var/lib/aun-filestore-root`.

~~~~~~
vfs_plugin_localfile_root = /var/lib/aun-filestore-root
~~~~~~

##### DfsSsd plugin #####

**vfs_plugin_localdfsssd_root**

Directory in which Acorn DFS `.ssd` floppy-disk image files are stored.  Each image file is presented as a directory in the Econet file system.  Default is `/var/lib/aun-filestore-root`.

~~~~~~
vfs_plugin_localdfsssd_root = /var/lib/aun-filestore-root
~~~~~~

##### AdfsAdl plugin #####

**vfs_plugin_localadfsadl_root**

Directory in which Acorn ADFS `.adl` floppy-disk image files are stored.  Default is `/var/lib/aun-filestore-root`.

~~~~~~
vfs_plugin_localadfsadl_root = /var/lib/aun-filestore-root
~~~~~~

##### AdfsHD plugin #####

**vfs_plugin_localadfshd_root**

Directory in which Acorn ADFS hard-disk image files (matched by the pattern `scsi*.dat`) are stored.  Default is `/var/lib/aun-filestore-root`.

~~~~~~
vfs_plugin_localadfshd_root = /var/lib/aun-filestore-root
~~~~~~

##### AFS plugin #####

**vfs_plugin_afs_root**

Directory in which AFS (Acorn Level-3 Fileserver) hard-disk image files (matched by the pattern `scsi*.l3`) are stored.  Default is `/var/lib/aun-filestore-root`.

~~~~~~
vfs_plugin_afs_root = /var/lib/aun-filestore-root
~~~~~~

##### AfsImg plugin #####

**vfs_plugin_localafsimg_root**

Directory in which AFS disk image files are stored for the AfsImg plugin.  No default — must be set if the `AfsImg` plugin is active.

~~~~~~
vfs_plugin_localafsimg_root = /var/lib/aun-filestore-root
~~~~~~

##### Mdfs plugin #####

**vfs_plugin_mdfs_root**

Directory in which SJ Research MDFS floppy/hard-disk image files (matched by the pattern `*.mdfs`) and HDFS hard-disk image files (matched by the pattern `*.hdfs`) are stored.  Both are read via the `homelan/mdfs-disk-reader` package.  Default is `/var/lib/aun-filestore-root`.

~~~~~~
vfs_plugin_mdfs_root = /var/lib/aun-filestore-root
~~~~~~

**vfs_plugin_mdfs_write_enabled**

`true` to allow the plugin to write, delete, rename, and create files inside `.mdfs`/`.hdfs` images (via the package's `MdfsWriter` class).  Default `false` (read-only), the same way the S3 plugin's `write_enabled` flag works.

~~~~~~
vfs_plugin_mdfs_write_enabled = true
~~~~~~

##### S3 plugin #####

**vfs_plugin_s3_mappings**

JSON array defining the S3 bucket/prefix mappings.  Each object in the array maps an Econet VFS path prefix to an S3 bucket.  No default — must be set if the `S3` plugin is active.

Fields per mapping object:

| Field | Required | Description |
|---|---|---|
| `econet_path` | Yes | Econet path prefix this mapping covers (e.g. `$.s3files`) |
| `bucket` | Yes | S3 bucket name |
| `prefix` | Yes | S3 key prefix within the bucket |
| `region` | Yes | AWS region (e.g. `eu-west-1`) |
| `write_enabled` | No | `true` to allow write operations; default `false` (read-only) |
| `endpoint` | No | Custom S3-compatible endpoint URL (e.g. for MinIO) |
| `key` | No | AWS/MinIO access key ID |
| `secret` | No | AWS/MinIO secret access key |

~~~~~~
vfs_plugin_s3_mappings = [{"econet_path":"$.s3files","bucket":"my-bucket","prefix":"econet","region":"eu-west-1"}]
~~~~~~

**vfs_plugin_s3_cache_dir**

Local directory used to cache files fetched from S3.  Must be writable by the server process.  Set to an empty string to disable the cache.  Default is `/var/lib/cache/aun/s3/`.

~~~~~~
vfs_plugin_s3_cache_dir = /var/lib/cache/aun/s3/
~~~~~~

##### Catalogue plugin #####

**vfs_plugin_catalogue_mappings**

JSON array defining the catalogue URL mappings.  Each object maps an Econet VFS path prefix to a URL hosting an `index.json` catalogue file.  No default — must be set if the `Catalogue` plugin is active.

Fields per mapping object:

| Field | Required | Description |
|---|---|---|
| `econet_path` | Yes | Econet path prefix this mapping covers (e.g. `$.apps`) |
| `catalogue_url` | Yes | Base URL of the directory containing `index.json` |
| `reload_interval` | No | Override the global reload interval (seconds) for this mapping |

~~~~~~
vfs_plugin_catalogue_mappings = [{"econet_path":"$.apps","catalogue_url":"https://example.com/apps"}]
~~~~~~

**vfs_plugin_catalogue_cache_dir**

Local directory used to cache files downloaded from catalogue URLs.  Must be writable by the server process.  Default is `/var/lib/cache/aun/catalogue/`.

~~~~~~
vfs_plugin_catalogue_cache_dir = /var/lib/cache/aun/catalogue/
~~~~~~

**vfs_plugin_catalogue_reload_interval**

How often (in seconds) the catalogue `index.json` is re-fetched to check for updated file versions.  Can be overridden per mapping in `vfs_plugin_catalogue_mappings`.  Default is `3600`.

~~~~~~
vfs_plugin_catalogue_reload_interval = 3600
~~~~~~


Print Server
==

**print_server_printers_file**

Path to the virtual printer configuration file.  This file defines the named printers that Econet clients can send jobs to.  Default is `printers.cfg`.

The file uses an INI-with-sections format — one `[SECTION]` per printer.  The section name is the printer name as seen by Econet clients (1–6 uppercase characters).

Fields per printer:

| Field | Values | Description |
|-------|--------|-------------|
| `description` | string | Human-readable label shown by `*PRINTER` and the admin UI |
| `enabled` | `yes` / `no` | Whether the printer accepts jobs |
| `behavior` | `spool`, `script`, `discard` | What to do when a job completes |
| `script` | path string | Conversion command (see `print_server_conversion_script`); blank falls back to the global default |
| `allowed_users` | comma-separated names | Users permitted to send to this printer; blank allows everyone |

Behaviour values:

* `spool` — save the raw print data to the spool directory.  No conversion.
* `script` — save the raw data, then launch the per-printer or global conversion script asynchronously.  The script never blocks the server.
* `discard` — accept the job and drop it.  Useful for a `/dev/null` queue.

If this file does not exist, the server falls back to a single default `PRINT` printer using `script` behaviour (which preserves the pre-multi-printer semantics: save + optional conversion).

Scripts are **always run asynchronously** via ReactPHP — they never delay replies to other Econet clients.  Both `%source%` and `%destination%` are substituted at run time.

~~~~~~
print_server_printers_file = printers.cfg
~~~~~~

Example `printers.cfg`:

~~~~~~
[PRINT]
description   = Default printer
enabled       = yes
behavior      = script
script        =
allowed_users =

[PDF]
description   = PDF output
enabled       = yes
behavior      = script
script        = /usr/bin/gs -q -dBATCH -sDEVICE=pdfwrite -sOutputFile=%destination% %source%
allowed_users =

[NULL]
description   = Discard (testing)
enabled       = yes
behavior      = discard
script        =
allowed_users = ADMIN
~~~~~~

**print_server_spool_dir**

Base directory to which incoming print jobs are spooled.  Jobs are stored under `{spool_dir}/{printer}/{user}/`.  The printer and user subdirectories are created automatically.  Default is `/tmp/econetprint`.

~~~~~~
print_server_spool_dir = /var/spool/aun-filestore-print
~~~~~~

**print_server_conversion_script**

Global fallback conversion command used when a printer's `script` field is blank.  Two placeholders are substituted at run time:

* `%source%` — full path to the input `.raw` file
* `%destination%` — full path for the converted output file (`.ps` extension)

The script is run asynchronously and never blocks the server.  If not set and a printer has an empty `script` field, no conversion is performed.  Default is `/usr/bin/esc2ps -i %source% -o %destination%`.

~~~~~~
print_server_conversion_script = /usr/bin/esc2ps -i %source% -o %destination%
~~~~~~


IPv4 / EconetA
==
The IPv4 service implements IPv4-over-Econet (the EconetA standard), handling ARP, ICMP, and NAT-proxied TCP connections.

**ipv4_interfaces_file**

Path to the file defining the virtual IPv4 interfaces hosted on the Econet.  Each line has the form `<network> <station> <ip> <netmask>`.  Default is `interfaces.txt`.

~~~~~~
ipv4_interfaces_file = interfaces.txt
~~~~~~

**ipv4_routes_file**

Path to the IPv4 routing table file.  Each line has the form `<destination>/<mask> <gateway> [metric]`.  Default is `routes.txt`.

~~~~~~
ipv4_routes_file = routes.txt
~~~~~~

**ipv4_nat_file**

Path to the NAT TCP proxy rules file.  Each line has the form `<virtual_ip> <real_ip> <virtual_port> <real_port>`.  Default is `nat.txt`.

~~~~~~
ipv4_nat_file = nat.txt
~~~~~~

**nat_default_station**

Econet station number used as the source address for NAT reply packets.  Should not conflict with any real Econet device.  Default is `254`.

~~~~~~
nat_default_station = 254
~~~~~~

**nat_default_network**

Econet network number used as the source address for NAT reply packets.  Default is `254`.

~~~~~~
nat_default_network = 254
~~~~~~


BeebTerm
==
The BeebTerm service implements the SJ Research BeebTerm terminal protocol, allowing BBC clients to connect terminal sessions to commands running on the server.

**beeb_term_services_file**

Path to the BeebTerm services definition file.  Each line defines one service using the format:

~~~~~~
<service_name> "<shell_command>"
~~~~~~

For example:

~~~~~~
BBC "/usr/bin/bbcbasic"
~~~~~~

Default is `beebterm.txt`.

~~~~~~
beeb_term_services_file = beebterm.txt
~~~~~~


TorchNet
==
The TorchNet service provides CP/M file services for Torch Communicator workstations.  Each CP/M drive letter maps to an Econet VFS path.

**torchnet_drive_\<letter\>**

Maps a CP/M drive letter to an Econet VFS path.  The config key uses a lowercase drive letter suffix, e.g. `torchnet_drive_e` for drive E.  If a drive is not explicitly configured it defaults to `$.TorchDrives.<LETTER>`.

~~~~~~
torchnet_drive_e = $.TorchDrives.E
torchnet_drive_f = $.TorchDrives.F
~~~~~~


Admin Web Interface
==
A browser-based admin interface is included and starts automatically with the server.

**webadmin_listen_address**

IP address to bind the admin web server to.  Default is `0.0.0.0` (all interfaces).

~~~~~~
webadmin_listen_address = 0.0.0.0
~~~~~~

**webadmin_listen_port**

TCP port for the admin web interface.  Default is `8080`.

~~~~~~
webadmin_listen_port = 8080
~~~~~~


Misc
==

**housekeeping_interval**

Interval in seconds between housekeeping runs.  Housekeeping expires idle sessions, closes stale file handles, and runs per-plugin maintenance tasks.  Default is `300`.

~~~~~~
housekeeping_interval = 300
~~~~~~


Remote Bridge
==

**remote_bridge_enabled**

Enables the remote bridge encapsulation system.  When `true`, the piconet interface switches to monitor mode so it can observe all Econet traffic (not just packets addressed to this station), and the remote bridge server and client handlers are started according to the map file.  Default is `false`.

~~~~~~
remote_bridge_enabled = false
~~~~~~

**remote_bridge_map_file**

Path to the remote bridge map file.  The map file lists `SERVER` entries (ports to listen on) and `CLIENT` entries (servers to connect to).  See [docs/protocols/remote-bridge.md](protocols/remote-bridge.md) for the full file format.  Default is `remotebridge.txt`.

~~~~~~
remote_bridge_map_file = remotebridge.txt
~~~~~~

**remote_bridge_server_address**

IP address to bind the remote bridge TCP server listeners to.  Default is `0.0.0.0` (all interfaces).

~~~~~~
remote_bridge_server_address = 0.0.0.0
~~~~~~

Remote Socket Protocol
==

These keys configure the listening (`filestored`) side of the Remote Socket Protocol relay — a WebSocket relay that forwards UDP traffic arriving at an EconetA interface to a registered client process such as `sharefsd`, and its replies back again.  See [docs/protocols/remote-socket.md](protocols/remote-socket.md) for the wire protocol, and the `sharefsd`-side keys under [ShareFS / Access+](#sharefs--access) below.

**remote_socket_relay_enabled**

Enables the relay server.  When `true`, a WebSocket listener is started (on a separate port from `websocket_listen_port`) and UDP traffic to an interface IP on a port a client has registered for is relayed instead of dropped.  Default is `false`.

~~~~~~
remote_socket_relay_enabled = false
~~~~~~

**remote_socket_relay_listen_address**

IP address the relay WebSocket server binds to.  Default is `0.0.0.0`.

~~~~~~
remote_socket_relay_listen_address = 0.0.0.0
~~~~~~

**remote_socket_relay_listen_port**

TCP port the relay WebSocket server binds to.  Default is `8091`.

~~~~~~
remote_socket_relay_listen_port = 8091
~~~~~~

**remote_socket_relay_secret**

Shared secret clients must present in their `hello` frame.  Mandatory — there is no way to run the relay unauthenticated.  No default; must be set for the feature to work, and must match the corresponding `*_remote_socket_relay_secret` key on every client (`sharefs_remote_socket_relay_secret` for `sharefsd`, `dns_remote_socket_relay_secret` for `dnsd`, `ntp_remote_socket_relay_secret` for `ntpd`).

~~~~~~
remote_socket_relay_secret = a-strong-shared-secret
~~~~~~

ShareFS / Access+
==

These keys configure `sharefsd`, a **separate daemon and process** from `filestored` (own executable `src/sharefsd`, own config, own admin web UI) that serves Freeway discovery, Access+ authentication, and the ShareFS data protocol — all three are UDP/IP, not Econet encapsulations.  See [docs/SHAREFSD.md](SHAREFSD.md) for the daemon's code structure and [docs/protocols/sharefs.md](protocols/sharefs.md) for the wire protocols.  `sharefsd` also reads `vfs_plugins`, `security_mode`, `security_*`, and `housekeeping_interval` from the sections above, since it reuses the same `Vfs`/`Security`/`config` classes `filestored` does.

**sharefs_share_list_file**

Path to the ShareFS share list file.  This file defines the named shares exposed to ShareFS clients, and their attributes (protected/readonly/hidden).  See [docs/SHAREFSD.md](SHAREFSD.md) for the file format.  Default is `sharelist.txt`.

~~~~~~
sharefs_share_list_file = sharelist.txt
~~~~~~

**sharefs_service_username** / **sharefs_service_password**

Real ShareFS/Access+ has no per-client login of its own — see [docs/protocols/sharefs.md](protocols/sharefs.md).  Every ShareFS file operation instead runs as one fixed identity, logged into the existing user database (the same one `filestored` uses) once at daemon startup.  `sharefsd` refuses to start if this login fails.  No default — must be set to a valid account.

~~~~~~
sharefs_service_username = sharefs
sharefs_service_password = a-strong-password
~~~~~~

**sharefs_service_network** / **sharefs_service_station**

The synthetic Econet network/station address the service identity above logs in at.  Only meaningful internally (there is no real Econet traffic involved) — any values not otherwise in use are fine.  Default is network `254`, station `1`.

~~~~~~
sharefs_service_network = 254
sharefs_service_station = 1
~~~~~~

**sharefs_listen_address**

IP address the server binds all three ShareFS UDP sockets (Freeway, Access+, ShareFS data) to.  Default is `0.0.0.0`.

~~~~~~
sharefs_listen_address = 0.0.0.0
~~~~~~

**sharefs_freeway_broadcast_address**

Broadcast address used for the periodic Freeway share-availability announcements.  Default is `255.255.255.255`.

~~~~~~
sharefs_freeway_broadcast_address = 255.255.255.255
~~~~~~

**sharefs_freeway_port**

UDP port for Freeway discovery broadcasts.  Default is `32770`.

~~~~~~
sharefs_freeway_port = 32770
~~~~~~

**sharefs_accessplus_port**

UDP port for Access+ per-share-password authentication.  Default is `32771`.

~~~~~~
sharefs_accessplus_port = 32771
~~~~~~

**sharefs_sharefsdata_port**

UDP port for the ShareFS file-data RPC protocol.  Default is `49171`.

~~~~~~
sharefs_sharefsdata_port = 49171
~~~~~~

**sharefs_host_name**

Host name this server advertises in Freeway broadcasts.  Empty by default, which falls back to the OS hostname (`gethostname()`).

~~~~~~
sharefs_host_name =
~~~~~~

**sharefs_webadmin_listen_address**

IP address to bind sharefsd's admin web server to.  Default is `0.0.0.0` (all interfaces).

~~~~~~
sharefs_webadmin_listen_address = 0.0.0.0
~~~~~~

**sharefs_webadmin_listen_port**

TCP port for sharefsd's admin web interface.  Deliberately different from `webadmin_listen_port` so both daemons' admin UIs can run on the same host at once.  Default is `8081`.

~~~~~~
sharefs_webadmin_listen_port = 8081
~~~~~~

**sharefs_remote_socket_relay_enabled**

When `true`, `sharefsd` receives its Freeway/Access+/ShareFS-data UDP traffic over a Remote Socket Protocol connection to a `filestored` relay instead of binding its own UDP sockets on `sharefs_freeway_port`/`sharefs_accessplus_port`/`sharefs_sharefsdata_port`.  See [Remote Socket Protocol](#remote-socket-protocol) above and [docs/protocols/remote-socket.md](protocols/remote-socket.md).  Purely additive: when `false` (the default), sharefsd behaves exactly as if this feature didn't exist.  Default is `false`.

~~~~~~
sharefs_remote_socket_relay_enabled = false
~~~~~~

**sharefs_remote_socket_relay_address**

`host:port` of the `filestored` relay server to connect to.  Default is `127.0.0.1:8091`.

~~~~~~
sharefs_remote_socket_relay_address = 127.0.0.1:8091
~~~~~~

**sharefs_remote_socket_relay_secret**

Shared secret sent in the relay connection's `hello` frame.  Must match `remote_socket_relay_secret` on the `filestored` side.  No default.

~~~~~~
sharefs_remote_socket_relay_secret = a-strong-shared-secret
~~~~~~

DNS
==

These keys configure `dnsd`, a **separate daemon and process** from `filestored` (own executable `src/dnsd`, own config) that answers DNS queries from a Unix-style hosts file, optionally forwarding what it can't answer to an external server.  See [docs/DNSD.md](DNSD.md) for the daemon's code structure and [docs/protocols/dns.md](protocols/dns.md) for exactly what subset of DNS is implemented.  Unlike `sharefsd`, `dnsd` has no direct-UDP mode at all — it always receives its traffic over the Remote Socket Protocol relay (see [Remote Socket Protocol](#remote-socket-protocol) above), so the first four keys below (`dns_hosts_file` through `dns_remote_socket_relay_secret`) are required for it to do anything; the `dns_forwarder_*` keys are optional.

**dns_hosts_file**

Path to the hosts file.  Standard Unix `/etc/hosts` syntax — see [docs/protocols/dns.md](protocols/dns.md) for the format.  Default is `hosts.txt`.

~~~~~~
dns_hosts_file = hosts.txt
~~~~~~

**dns_port**

The UDP port registered with the relay server — the port Econet clients must address their DNS queries to.  Default is `53`.

~~~~~~
dns_port = 53
~~~~~~

**dns_remote_socket_relay_address**

`host:port` of the `filestored` relay server to connect to.  Default is `127.0.0.1:8091`.

~~~~~~
dns_remote_socket_relay_address = 127.0.0.1:8091
~~~~~~

**dns_remote_socket_relay_secret**

Shared secret sent in the relay connection's `hello` frame.  Must match `remote_socket_relay_secret` on the `filestored` side.  No default.

~~~~~~
dns_remote_socket_relay_secret = a-strong-shared-secret
~~~~~~

**dns_forwarder_enabled**

When `true`, a query the hosts file can't answer (forward or reverse) is forwarded to an external DNS server instead of getting `NXDOMAIN`/`NODATA`.  Forwarding is asynchronous and never blocks the daemon from handling other queries.  See [docs/protocols/dns.md](protocols/dns.md) → "Forwarding to an external server".  Default is `false`.

~~~~~~
dns_forwarder_enabled = false
~~~~~~

**dns_forwarder_address**

`host:port` of the external DNS server to forward unanswered queries to.  No default — required if `dns_forwarder_enabled` is `true`.

~~~~~~
dns_forwarder_address = 8.8.8.8:53
~~~~~~

**dns_forwarder_timeout**

Seconds to wait for the external server to reply before falling back to the local `NXDOMAIN`/`NODATA` answer.  Default is `2`.

~~~~~~
dns_forwarder_timeout = 2
~~~~~~

**dns_forwarder_allowed_domains**

Optional comma-separated allow-list of domains eligible for forwarding.  When empty (the default), forwarding is unrestricted.  When set, a query name is only forwarded if it is one of the listed domains or a subdomain of one; anything else falls straight back to the local answer without contacting the upstream server.  Forward and reverse domains (e.g. `example.com` and `in-addr.arpa` entries) can be mixed freely in the same list — both are matched the same way.  `dnsd` is IPv4-only, so an `ip6.arpa` entry would never actually match anything — `AAAA` queries and `ip6.arpa` names are refused before forwarding is ever considered, see [docs/protocols/dns.md](protocols/dns.md).

~~~~~~
dns_forwarder_allowed_domains = example.com,168.192.in-addr.arpa
~~~~~~

NTP
==

These keys configure `ntpd`, a **separate daemon and process** from `filestored` (own executable `src/ntpd`, own config) that answers NTP client requests from the host system clock.  See [docs/NTPD.md](NTPD.md) for the daemon's code structure and [docs/protocols/ntp.md](protocols/ntp.md) for exactly what subset of NTP is implemented.  Like `dnsd`, `ntpd` has no direct-UDP mode at all — it always receives its traffic over the Remote Socket Protocol relay (see [Remote Socket Protocol](#remote-socket-protocol) above), so all five keys below are required for it to do anything.

**ntp_port**

The UDP port registered with the relay server — the port Econet clients must address their NTP requests to.  Default is `123`.

~~~~~~
ntp_port = 123
~~~~~~

**ntp_stratum**

Stratum reported in every reply.  Default is `1` — see [docs/protocols/ntp.md](protocols/ntp.md) → "Time source and stratum" for why.

~~~~~~
ntp_stratum = 1
~~~~~~

**ntp_reference_id**

Reference ID reported in every reply.  Four ASCII characters; a shorter value is zero-padded, a longer one truncated.  Default is `LOCL`, the conventional value for a self-referencing clock with no external upstream.

~~~~~~
ntp_reference_id = LOCL
~~~~~~

**ntp_remote_socket_relay_address**

`host:port` of the `filestored` relay server to connect to.  Default is `127.0.0.1:8091`.

~~~~~~
ntp_remote_socket_relay_address = 127.0.0.1:8091
~~~~~~

**ntp_remote_socket_relay_secret**

Shared secret sent in the relay connection's `hello` frame.  Must match `remote_socket_relay_secret` on the `filestored` side.  No default.

~~~~~~
ntp_remote_socket_relay_secret = a-strong-shared-secret
~~~~~~

MaceMail
==

See [docs/protocols/macemail.md](protocols/macemail.md) for the wire protocol. Authentication, sessions, and idle timeout are handled entirely by the existing `security_*` configuration and auth plugins — MaceMail keeps no password store of its own, only a slot-number-to-username assignment table.

**macemail_store_dir**

Root directory for MaceMail's own native storage — the slot registry, per-user metadata, mailboxes, and store-slot files.  This is independent of the VFS layer (no `vfs_plugin_*` settings apply here).  No default — must be set for the `MaceMail` provider to work.

~~~~~~
macemail_store_dir = /var/lib/aun-filestore-macemail
~~~~~~

**macemail_usergroup**

The 4-character "user group" identifier sent to clients during the connect handshake.  The original protocol used this to partition multiple installations sharing one physical disc; this server does not use multiple usergroups, so the value only needs to satisfy the vintage client's own client-side check of it.  Default is `MAIL`.

~~~~~~
macemail_usergroup = MAIL
~~~~~~

**macemail_max_slots**

Number of MaceMail user slots (0 to this value minus 1) available for assignment.  The original hardware-constrained implementation fixed this at 32; the wire protocol's 1-byte slot field and the vintage client's 2-digit entry field allow up to 100.  Default is `32`.

~~~~~~
macemail_max_slots = 32
~~~~~~

Teletext
==

See [docs/protocols/teletext.md](protocols/teletext.md) for the wire protocol. The server always serves pages from local storage — there is no live receiver — so populate the store directory directly (one sub-directory per channel, one `.dat` file per page, each holding a raw 1024-byte Mode 7 screen dump); the server itself is read-only and never writes there.

**teletext_store_dir**

Root directory for the teletext page store — a directory per channel, a `.dat` file per page inside it (`{teletext_store_dir}/{channel}/{page}.dat`).  A page with more than one subpage adds `{page}_{N}.dat` files alongside it for subpage 2 onwards (`{page}.dat` is always subpage 1).  This is independent of the VFS layer (no `vfs_plugin_*` settings apply here).

~~~~~~
teletext_store_dir = /var/lib/aun-filestore-teletext
~~~~~~

**teletext_server_name**

Optional server name reported in the discovery reply (port 0xB1), alongside the fixed `TELETEXT` server type. Empty by default, meaning no name is sent.

~~~~~~
teletext_server_name =
~~~~~~

**teletext_max_users**

The "max users per channel" value reported by the read-max-users operation (0x83) — informational only, not enforced. Default is `99`.

~~~~~~
teletext_max_users = 99
~~~~~~

**teletext_carousel_interval**

How often, in seconds, the server broadcasts the "currently displayed page" packet on port 0xB5 (see docs/protocols/teletext.md for what this server reports, since it has no live carousel of its own). Default is `4`.

~~~~~~
teletext_carousel_interval = 4
~~~~~~

**teletext_teefax_channel**

Which channel (0-9) to automatically keep populated from the [Teefax](https://magazine.raspberrypi.com/articles/teefax) teletext archive — see the "Teefax import" section of docs/protocols/teletext.md.  Empty by default, which disables the feature entirely: nothing is downloaded from anywhere unless this is set.

~~~~~~
teletext_teefax_channel =
~~~~~~

**teletext_teefax_source**

Tarball URL the importer downloads Teefax's content from.  Defaults to a GitHub mirror of the original SVN repository, since that isn't always reliably reachable.

~~~~~~
teletext_teefax_source = https://github.com/opless/teefax-mirror/archive/refs/heads/master.tar.gz
~~~~~~

**teletext_teefax_refresh_interval**

Minimum number of seconds between automatic Teefax refreshes, checked on every housekeeping tick.  Default is `86400` (one day).

~~~~~~
teletext_teefax_refresh_interval = 86400
~~~~~~


Viewdata
==

See [docs/protocols/viewdata.md](protocols/viewdata.md) for the wire protocol. There is no per-session service selection — every session bridges to the same configured upstream viewdata/videotex server (e.g. [Telstar](https://glasstty.com/telstar/)) over a plain TCP connection.

**viewdata_host**

Hostname or IP address of the upstream viewdata/videotex server. Default is `glasstty.com`.

~~~~~~
viewdata_host = glasstty.com
~~~~~~

**viewdata_port**

TCP port to connect to on `viewdata_host`. Telstar offers `6502`/`6503` (8 data bits, no parity) and `6504` (7 data bits, even parity, for legacy terminal emulation). Default is `6502`.

~~~~~~
viewdata_port = 6502
~~~~~~
