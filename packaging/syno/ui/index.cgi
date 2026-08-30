#!/bin/sh
#
# The DSM desktop icon points here. The admin web front end is served by the
# filestored daemon itself on its own port (webadmin_listen_port, default 8080),
# not through DSM's web server, so this CGI just bounces the browser to
# http://<this-host>:8080/ using whatever host name the user reached DSM on.

PORT="8080"

host=$(printf '%s' "${HTTP_HOST:-${SERVER_NAME:-$(hostname)}}" | cut -d: -f1)
[ -n "${host}" ] || host=$(hostname)

target="http://${host}:${PORT}/"

printf 'Status: 302 Found\r\n'
printf 'Location: %s\r\n' "${target}"
printf 'Content-Type: text/html; charset=utf-8\r\n'
printf '\r\n'
printf '<!doctype html><meta http-equiv="refresh" content="0; url=%s">' "${target}"
printf '<title>AUN Filestore</title><a href="%s">AUN Filestore admin interface</a>\n' "${target}"
