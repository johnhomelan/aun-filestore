FROM phpearth/php:7.3-nginx

MAINTAINER john@home-lan.co.uk

RUN apk add --no-cache rsync make bash composer git php7.3-dba php7.3-soap php7.3-posix php7.3-pcntl

#Install the ev pecl extension so React can use event over select
ENV PHPIZE_DEPS="file re2c autoconf zlib-dev g++ libevent-dev openssl-dev libev-dev"
RUN apk add --no-cache ${PHPIZE_DEPS} "php7.3-dev"
RUN apk add --no-cache libevent openssl libev
RUN sed -i "$ s|\-n||g" /usr/bin/pecl
RUN pecl install event ev
RUN echo "extension=event" >/etc/php/7.3/conf.d/01_event.ini
#RUN echo "extension=ev" >/etc/php/7.3/conf.d/01_ev.ini
RUN apk del ${PHPIZE_DEPS}


RUN mkdir -p /etc/aun-filestored-default-config
RUN mkdir -p /etc/aun-filestored
RUN mkdir -p /var/lib/aun-filestore-root
RUN mkdir -p /var/spool/aun-filestore-print
RUN mkdir -p /usr/share/aun-filestored/include

ADD src/include /usr/share/aun-filestored/include
ADD src/composer.json /usr/share/aun-filestored
ADD src/composer.lock /usr/share/aun-filestored
COPY src/react-test /usr/sbin/filestored


COPY packaging/docker/default.conf /etc/aun-filestored-default-config/default.conf
COPY packaging/docker/aunmap.txt /etc/aun-filestored-default-config/aunmap.txt
COPY packaging/docker/users.txt /etc/aun-filestored-default-config/users.txt
COPY packaging/docker/entrypoint.sh /

RUN cd /usr/share/aun-filestored; composer install --no-dev
RUN chmod u+x /usr/sbin/filestored
RUN chmod u+x /entrypoint.sh

EXPOSE 32768/udp 8080/tcp


VOLUME ["/var/lib/aun-filestore-root", "/var/log","/etc/aun-filestored","/var/spool/aun-filestore-print"]

CMD ["/entrypoint.sh"]
