#!/bin/bash

log(){
    # Log to stderr
    logger -s -t entrypoint.sh "$@"
    }

if [ ! -f /etc/aun-filestored/default.conf ];then 
	cp /etc/aun-filestored-default-config/default.conf /etc/aun-filestored/default.conf
fi

if [ ! -f /etc/aun-filestored/users.txt ];then 
	cp /etc/aun-filestored-default-config/users.txt /etc/aun-filestored/users.txt
fi

if [ ! -f /etc/aun-filestored/aunmap.txt ];then 
	cp /etc/aun-filestored-default-config/aunmap.txt /etc/aun-filestored/aunmap.txt
fi

case "$1" in
    *)
	/usr/sbin/filestored -c /etc/aun-filestored
        ;;
esac
