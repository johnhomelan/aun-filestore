FROM phpearth/php:7.2-nginx

MAINTAINER john@home-lan.co.uk

RUN apk add --no-cache rsync make bash composer php7.2-memcached php7.2-dba php7.2-soap php7.2-posix php7.2-pcntl

RUN mkdir -p /etc/aun-filestored-default-config
RUN mkdir -p /etc/aun-filestored
RUN mkdir -p /var/lib/aun-filestore-root
RUN mkdir -p /var/spool/aun-filestore-print
RUN mkdir -p /usr/share/aun-filestored/include

ADD src/include /usr/share/aun-filestored/include
COPY src/filestored /usr/sbin/

COPY packaging/docker/default.conf /etc/aun-filestored-default-config/default.conf
COPY packaging/docker/aunmap.txt /etc/aun-filestored-default-config/aunmap.txt
COPY packaging/docker/users.txt /etc/aun-filestored-default-config/users.txt
COPY packaging/docker/entrypoint.sh /

RUN chmod u+x /usr/sbin/filestored
RUN chmod u+x /entrypoint.sh

EXPOSE 32768/udp


VOLUME ["/var/lib/aun-filestore-root", "/var/log","/etc/aun-filestored","/var/spool/aun-filestore-print"]

CMD ["/entrypoint.sh"]
