#!/bin/bash
#
# Toolchain setup + .deb build, meant to run *inside* a Debian / Ubuntu
# container with the repo bind-mounted at /work. Used by .github/workflows/deb.yml
# but works locally too:
#
#   docker run --rm -v "$PWD":/work -w /work \
#     -e OUTPUT_DIR=/work/build/debian-13 debian:trixie \
#     bash packaging/deb/build-in-container.sh
#
# Honoured env vars: DEB_VERSION, DEB_PHP_MIN, OUTPUT_DIR,
# HOST_UID / HOST_GID (chown the results back to that uid/gid when set).

set -euxo pipefail

export DEBIAN_FRONTEND=noninteractive

apt-get update
apt-get install -y --no-install-recommends \
	ca-certificates git make rsync unzip curl \
	dpkg-dev debhelper devscripts lintian \
	php-cli php-xml php-mbstring php-zip \
	composer

cd /work
git config --global --add safe.directory /work 2>/dev/null || true

php --version
composer --version
dpkg-buildpackage --version | head -1

make deb

if [ -n "${HOST_UID:-}" ]; then
	chown -R "${HOST_UID}:${HOST_GID:-${HOST_UID}}" "${OUTPUT_DIR:-/work/build}" 2>/dev/null || true
fi
