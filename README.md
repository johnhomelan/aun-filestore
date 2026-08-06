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

