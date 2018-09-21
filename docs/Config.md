Introduction
=
Once you've install aun-filestore you will need to configure it.  If you've installed it using one of the packages (rpm,deb) it will have a number of useful defaults and should work more or less out of the box.  

For unix platforms the config is read from the directory /etc/aun-filestore, any file ending in .conf will be read by the config system.  If 2 files define the same config key but with conflicting values the one in the later file in the directory listing wins.

Security
==
The security system is used to authenticate users making use of the file/print server. The system uses a number of authentication plugins that can use different formats of auth files.

**security_max_session_idle**

This configures how long a users connection is allowed to be idle before the user is automatically logged out.  The time period in measured in seconds and its default value is 2400.

~~~~~~
e.g.

security_max_session_idle = 2400

~~~~~~

**security_auth_plugins**

This configures which auth plugins are used by the server to check for username/password.  Multiple values are comer separated, and the order is significant as the first entry in the list is the plugin that will be called by the newuser command.
~~~~~~
e.g.

security_auth_plugins = "file"
~~~~~~

#####File auth plugin#####


**security_plugin_file_user_file**

This configures the file location of the user file used by the file-auth plugin 

~~~~~~
e.g.

security_plugin_file_user_file="/etc/aun-filestore-passwd"
~~~~~~


**security_plugin_file_default_crypt**

This configure the password hashing algorithm used by the file-auth plugin the default is md5 the valid options are ;-

*md5 A basic md5 crypt with no salt 
*plain Plain text no crypt at all 
*sha1 A sha1 crypt with no salt

~~~~~~
e.g.

security_plugin_file_default_crypt="md5"

~~~~~~


Network
==
**aun_listen_address**

This controls what ip address server will listen on for AUN packets, the default is 0.0.0.0 which means and interface.  Changing this to the ip addr of an interface will restrict the system to only listening for AUN packets on that interface.

~~~~~~
e.g.

aun_listen_address="0.0.0.0"

~~~~~~

**aun_listen_port**

This controls the port the server will listen on for AUN packets.  The default port is 32768 which is considered the standard port for AUN.

~~~~~~
e.g.

aun_listen_port=32768
~~~~~~

**aun_default_port**

This controls the port the server will use to send AUN packets to.  The default is 32768 which is considered the standard port for AUN.

~~~~~~
e.g.

aun_default_port=32768
~~~~~~

**econet_data_stream_port**

Econet has a concept of ports which is not dissimilar to the ports concept in tcp/udp.  As AUN is an emulation of Econet over UDP it also emulates Econet ports inside the AUN payload.  This value configures the Econet port used for streaming data between fileserver and the client, this value has nothing todo with UDP/TCP ports.  The default value is 0x97 as this is the standard port used by BBC's for client/server data streams.

~~~~~~
e.g.

econet_data_stream_port=0x97
~~~~~~

**bbc_default_pkg_sleep**

Given the processing speed of a BBC very rapid replies from the server can cause problems thus the server sleeps between sending packets to a client to avoid cause issues.  This value sets the sleep period in micro seconds, the default is 40000. 

~~~~~~
e.g.

bbc_default_pkg_sleep'=40000
~~~~~~

**aunmap_file**

The aunmap file allows the system admin to map subnets or individual ips to a econet network and station number.

The files is in the format :-

ipaddr    network.station
network_addr/mask    network


When a subnet is mapped to an Econet network number the station number is the same as the last part of the ip addr. 

This config value set the file path of the map file. 

~~~~~~
e.g.

aunmap_file='aunmap.txt'
~~~~~~

Logging
==
**logbackend**

The server can log to either syslog or a file.  This key controls which backend the system uses vaild values are syslog or logfile, the default is syslog.

~~~~~~
e.g.

logbackend='logfile'
~~~~~~

**loglevel**

Controls the logging level valid values are 0 - 7 with 0 being the least verbose and 7 the most.  The default value is 6 which provides basic information logging about key events such as a user logging in/out etc.

~~~~~~
e.g.

loglevel=7
~~~~~~

**logstderr**

Controls if all logging is echoed to standard error, 1 turns logging to stderr on 0 turns it off, the default is off.

~~~~~~
e.g.

logstderr=0
~~~~~~

**logfile**

If the system if configure to use a log file for its logging (rather than syslog) this config key sets the path of the file the log is written to.

~~~~~~
e.g.

logfile='/tmp/filestore.log'
~~~~~~

File System
==
The file system exported by the file server is made up of a number vfs plugins.  The plugins are layered ontop of one another to form the final filing system exported by the file server.

The base plugin that should be used is the localfile plugin, it exports a local directory as the root of the exported file system. 


**vfs_plugins**

This configures which vfs plugin are used and in what order.  The pluigns are listed in order and are separated by "," with the right most plugin forming the lowest layer of the file system.  Please note the plugin names are case sensitive.  All the plugins can be found in src/include/classes/Vfs/Plugins. 

~~~~~~
e.g.

vfs_plugins='DfsSsd,AdfsAdl,LocalFile'

~~~~~~

**vfs_default_disc_free**

A BBC can't cope with modern disc sizes so the file server returns a fake value for the amount of disk space free.  This config key sets that value.

~~~~~~
e.g.

vfs_default_disc_free=0x9000

~~~~~~

**vfs_default_disc_size**

A BBC can't cope with modern disc sizes so the file server returns a fake value for the size of the disc.  This config key sets that value.

~~~~~~
e.g.

vfs_default_disc_size=0x9000

~~~~~~

**vfs_disc_name**

In a econet a file server can provide access to multiple disks, each disk has a unique name.  As this server shares a chuck of local disk space that space is repesented as a disk and thus must have a name.  This config value sets the name for that disk,

~~~~~~
e.g.

vfs_disc_name='VFSROOT'

~~~~~~

**library_path**

The Library is used by all clients to load software from regardless of the currently selected directory.  BBC's don't have an equivalent to the UNIX and DOS path allowing the client to have a list of directories to search for given executable name.  Instead BBC's have the concept of a LIBRARY, one directory that can be searched for an executable as well as the current directory.  This config entry sets the path of the default library for the client when the user logs in.

~~~~~~
e.g.

library_path='$.LIBRARY'

~~~~~~

**vfs_home_dir_path**
Each user potentially gets their own home directory, this config value sets the path of the directory any home directories are created under.  The default is $.HOME

~~~~~~
e.g.

vfs_home_dir_path='$.HOME'

~~~~~~

**vfs_plugin_localfile_root**

This config entry is for the localfile vfs plugin.  This plugin allows the server to share an amount of local file system to remote clients. This config value sets which directory is shared out.

~~~~~~
e.g.

vfs_plugin_localfile_root='/var/econetroot/'

~~~~~~

**vfs_plugin_localdfsssd_root**
The vfs plugin localdfsssd allows you to place a DFS ssd disk image on your server and the file server will represent the disk image as a directory.  If a user changes into that directory all the files held in that image will be listed if the user list all the files in that directory.  This config value sets the directory the disk images can be stored.  This should be configured to be that same path that is used as the localgile_root path in most circumstances. 

~~~~~~
e.g.

vfs_plugin_localdfsssd_root='/var/econetroot/'

~~~~~~

**vfs_plugin_localadfsadl_root**
The vfs plugin localadfsadl allows you to place an ADFS adl disk image on your server and the file server will represent the disk image as a directory.  If a user changes into that directory all the files held in that image will be listed as will any of the directories contained in the ADFS image.  This config value sets the directory the disk images can be stored.  This should be configured to be that same path that is used as the localgile_root path in most circumstances. 

~~~~~~
e.g.

vfs_plugin_localadfsadl_root='/var/econetroot/'

~~~~~~

Print Server
==
The server provides a basic print server, and printed jobs just get saved in a directory.   

**print_server_spool_dir**
This config entry sets the directory any print jobs are spooled to. 

~~~~~~
e.g.

print_server_spool_dir='/tmp/econetprint'

~~~~~~

Misc
==

The server performs house keeping tasks (expiring un-used connections, closing un-used file handles etc).  This config value sets the in time in seconds between each house cleaning.
 The default is 300 seconds.

~~~~~~
e.g.

housekeeping_interval=300

~~~~~~
