# Building #

As aun-filestore is written in PHP no compiling needs to take place in order to produce a working version of the code.   It is possible to run the version checked out from SVN directly.   However this is not the nicest why to install and run aun-filestore.  

There are build scripts for creating a version of aun-filestore that can be more easily distributed and installed.

Our current build targets are

* **rpm** (for rpm based Linux distrobutions e.g. Fedora, RedHat Enterpris, CentOS, Scientific Linux)
* **synopackage** (for Synology NAS units, work has started on this but it's not finished yet)
* **phar** (A PHP phar archive that bundles all the php code together to create a simple version that can be run from a directory.  While this currently only works on Unix based systems it should soon also work on Windows)

Currently missing form the list a deb package for Debian based Linux disrobutions (e.g. Ubuntu, Crunchbang, Debian), and a windows installer.  If anyone wants to take on putting together the  missing build scripts please get in contact with me.  

## Requirements ##

In order to run the build scripts you will need to ant and ant contrib installed.  Some targets also require the package build tools installed (e.g. rpms need rpmbuild install, the phar archive needs php installed). 


## RPM ##


Run the following commands to build the rpm package 

    https://github.com/johnhomelan/aun-filestore.git
    cd aun-filestore
    ant rpm

Once the build script has run you should find an rpm in the build directory.

## Phar ##

Run the following commands to build the phar distribution 

    https://github.com/johnhomelan/aun-filestore.git
    cd aun=filestore
    ant phar 

Once the build script has finished you will find a tgz file in the build directory (aun-filestored-<version>-phar.tgz) that can now be distributed to others. 
