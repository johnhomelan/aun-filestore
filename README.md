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
    * Basic IPv4 over econet routing, and work has started on NAT to IPv4 Address not on one of the encapsulated econet interfaces

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

