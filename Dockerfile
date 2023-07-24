FROM php:8.1-cli-alpine

MAINTAINER john@home-lan.co.uk

RUN apk add --no-cache rsync make bash composer curl openjdk8-jre postgresql-client autoconf automake gcc g++ make libc-dev
RUN apk add --no-cache postgresql-dev mysql-dev libxml2-dev libpng-dev gpgme-dev libmemcached-dev openldap-dev curl-dev gnu-libiconv openssl-dev gnu-libiconv-dev
RUN docker-php-ext-install pdo_pgsql pdo_mysql soap gd dba pcntl ldap curl iconv mbstring openssl zip phar


RUN mkdir -p /etc/aun-filestored-default-config
RUN mkdir -p /etc/aun-filestored
RUN mkdir -p /var/lib/aun-filestore-root
RUN mkdir -p /var/spool/aun-filestore-print
RUN mkdir -p /usr/share/aun-filestored/include

ADD src/include /usr/share/aun-filestored/include
ADD src/composer.json /usr/share/aun-filestored
ADD src/composer.lock /usr/share/aun-filestored
COPY src/filestored /usr/sbin/filestored


COPY packaging/docker/default.conf /etc/aun-filestored-default-config/default.conf
COPY packaging/docker/aunmap.txt /etc/aun-filestored-default-config/aunmap.txt
COPY packaging/docker/users.txt /etc/aun-filestored-default-config/users.txt
COPY packaging/docker/entrypoint.sh /

RUN cd /usr/share/aun-filestored; composer install --no-dev
RUN chmod u+x /usr/sbin/filestored
RUN chmod u+x /entrypoint.sh

EXPOSE 32768/udp 8080/tcp 8090/tcp


VOLUME ["/var/lib/aun-filestore-root", "/var/log","/etc/aun-filestored","/var/spool/aun-filestore-print"]

CMD ["/entrypoint.sh"]
