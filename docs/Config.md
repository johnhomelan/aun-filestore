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

Comma-separated list of VFS plugin names, in priority order (left = highest priority).  Plugin names are case-sensitive.  Default is `AFS,DfsSsd,AdfsAdl,AdfsHD,LocalFile`.

Available plugins: `LocalFile`, `S3`, `Catalogue`, `DfsSsd`, `AdfsAdl`, `AdfsHD`, `AFS`, `AfsImg`

~~~~~~
vfs_plugins = DfsSsd,AdfsAdl,LocalFile
~~~~~~

**vfs_default_disc_free**

Fake free-space value reported to BBC clients (which cannot handle real modern disk sizes).  Value is in Econet disc-space units.  Default is `0x9000`.

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

**print_server_spool_dir**

Directory to which incoming print jobs are spooled.  Per-user subdirectories are created automatically.  Default is `/tmp/econetprint`.

~~~~~~
print_server_spool_dir = /var/spool/aun-filestore-print
~~~~~~

**print_server_conversion_script**

Optional shell command run after each raw `.raw` spool file is written.  Two placeholders are substituted at run time:

* `%source%` — full path to the input `.raw` file
* `%destination%` — full path for the converted output file (`.ps` extension)

If not set, no conversion is performed.  Default is `/usr/bin/esc2ps -i %source% -o %destination%`.

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
