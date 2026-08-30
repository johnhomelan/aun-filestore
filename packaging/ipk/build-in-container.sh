#!/bin/sh
#
# Toolchain setup + .ipk build, meant to run *inside* a Debian container with
# the repo bind-mounted at /work. The .ipk is architecture-independent and
# assembled by hand (ar of debian-binary + control.tar.gz + data.tar.gz), so the
# build host OS does not matter. Used by .github/workflows/ipk.yml; works
# locally too:
#
#   docker run --rm -v "$PWD":/work -w /work \
#     -e OUTPUT_DIR=/work/build debian:stable-slim \
#     sh packaging/ipk/build-in-container.sh
#
# Honoured env vars: IPK_VERSION, IPK_DEPENDS, OUTPUT_DIR,
# HOST_UID / HOST_GID (chown the results back to that uid/gid when set).

set -eux

export DEBIAN_FRONTEND=noninteractive
apt-get update
apt-get install -y --no-install-recommends \
	ca-certificates git make rsync unzip curl xz-utils \
	binutils \
	php-cli php-xml php-mbstring php-zip \
	composer

cd /work
git config --global --add safe.directory /work 2>/dev/null || true

php --version
ar --version | head -1

make ipk

if [ -n "${HOST_UID:-}" ]; then
	chown -R "${HOST_UID}:${HOST_GID:-${HOST_UID}}" "${OUTPUT_DIR:-/work/build}" 2>/dev/null || true
fi
