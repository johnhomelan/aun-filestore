# Aims #
The main aim of this project is to create an Econet fileserver implementation, which communicates using Acorns AUN protocol (Econet over IP/UDP).

Our target platform is Linux and Unix like environments, with Windows as a target for later development (trying not to build things in away that will never work with windows).

## Main features ##

### User authentication ###
* Plugable auth system, that allows the server to use multiple password backends. 
    * The system should map econet fileserver users to unix user.

### Storage ###
* The storage system should be plugable with the flowing plugins 
    * Files can be stored on a natvie unix fs (with meta data stored in files)
    * Disk image files (ssd,adl) can be stored in a directory on unix and used as a directory on the acorn side
    * Directories mounted from http servers (for public shared directories between servers)

### Print support ###
* Print to modern printers (using cups)
* Print jobs converted to a pdf
    * PDF e-mailed to the enduser
    * PDF stored in a per user directory 

## It would be nice if ##
* The other feature it would be nice to have is to operate as a bridge and allow econet frames to be passed over the public internet to other bridges securely (tcp socket, using ssl).

# Todo #
While all the auth and file serving features are complete, there are some outstanding area that need implementing.

The print server is still very basic all print jobs are just dumped to a directory. I've yet to figure out how to convert the BBC's printout put to postscript. 

The soap interface and control client has yet to be implemented. 

# Install #

At the moment there are no rpm and deb packages built for easy install (this will happen before the release of the version 0.1).  However it can be run from source, your machine will need to have php installed and the php-pcntl module.
  
* Check out the source from GIT  
* Make the file filestored executable (chmod u+x filestored)
* Create a directory to act as root of your econet file system
* Create a directory to hold your config files 
* Write a basic config file (see the config section)
* Run the server (./filestored -c <conifg_dir>)
