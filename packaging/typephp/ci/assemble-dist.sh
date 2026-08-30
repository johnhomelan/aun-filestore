#!/usr/bin/env bash
#
# Assemble the packaging/typephp native binaries plus everything they need at
# run time into a portable tarball:
#
#   aun-filestore-typephp-<version>-<arch>/
#     bin/<name>        - launcher scripts (set LD_LIBRARY_PATH / PHPRC, exec the ELF)
#     libexec/<name>    - the actual AOT-compiled ELF binaries
#     lib/              - libphp.so, libphpx.so + the full non-glibc .so closure
#     lib/php-ext/      - the PHP shared extensions (pdo_pgsql, pdo_mysql, ...)
#     etc/php/conf.d/   - their `extension=` ini files
#     configure.sh      - one-time: point the bundled libphp at lib/php-ext
#     README.md
#
# Prereqs: the 7 binaries already built under build/typephp/ (make native-daemons
# teletext-typephp) and the toolchain image present. Run from anywhere.
#
# Usage: assemble-dist.sh <arch-label>          # e.g. x86_64 | arm64
# Env:   TYPEPHP_IMAGE (default localhost/aun-filestore-typephp)
#        PODMAN         (default podman; set to docker in CI)
#        RELEASE_TAG / GITHUB_REF_NAME - version string; else `git describe`

set -euo pipefail

ARCH="${1:?usage: assemble-dist.sh <arch-label>}"
IMAGE="${TYPEPHP_IMAGE:-localhost/aun-filestore-typephp}"
PODMAN="${PODMAN:-podman}"

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO="$(cd "${SCRIPT_DIR}/../../.." && pwd)"
BIN_DIR="${REPO}/build/typephp"
DIST="${REPO}/dist"

# The ELFs produced by `make native-daemons teletext-typephp`. tpc normalises
# '-' to '_' in the output name; the launcher we ship uses the '-' spelling.
BINS=(aun_filestored sharefsd dnsd ntpd ecosyslogd sql_serverd teletext_import)

VERSION="${RELEASE_TAG:-${GITHUB_REF_NAME:-}}"
VERSION="${VERSION#refs/tags/}"
if [ -z "${VERSION}" ]; then
	VERSION="$(git -C "${REPO}" describe --tags --always --dirty 2>/dev/null || echo dev)"
fi

NAME="aun-filestore-typephp-${VERSION}-${ARCH}"
STAGE="${DIST}/${NAME}"

log() { printf '  [assemble] %s\n' "$*" >&2; }

command -v "${PODMAN}" >/dev/null 2>&1 || { echo "error: '${PODMAN}' not found" >&2; exit 1; }

rm -rf "${DIST}"
mkdir -p "${STAGE}"/{bin,libexec,lib/php-ext,etc/php/conf.d}

# --- the AOT binaries ------------------------------------------------------
for b in "${BINS[@]}"; do
	[ -x "${BIN_DIR}/${b}" ] || { echo "error: missing binary ${BIN_DIR}/${b} (run 'make native-daemons teletext-typephp')" >&2; exit 1; }
	install -m 0755 "${BIN_DIR}/${b}" "${STAGE}/libexec/${b}"
done

# --- PHP embed runtime + phpx + shared extensions, copied out of the image
log "extracting the PHP embed runtime from ${IMAGE}"
cid="$("${PODMAN}" create "${IMAGE}" /bin/true)"
cleanup() { "${PODMAN}" rm -f "${cid}" >/dev/null 2>&1 || true; }
trap cleanup EXIT

"${PODMAN}" cp "${cid}:/opt/typephp/vendor/swoole/phpx/lib/." "${STAGE}/lib/"
"${PODMAN}" cp "${cid}:/usr/local/lib/libphp.so"              "${STAGE}/lib/"

EXT_DIR="$("${PODMAN}" run --rm --network=none --entrypoint php "${IMAGE}" -r 'echo ini_get("extension_dir");')"
"${PODMAN}" cp "${cid}:${EXT_DIR}/."                    "${STAGE}/lib/php-ext/" 2>/dev/null || true
"${PODMAN}" cp "${cid}:/usr/local/etc/php/conf.d/."     "${STAGE}/etc/php/conf.d/" 2>/dev/null || true
GLIBC="$("${PODMAN}" run --rm --network=none --entrypoint bash "${IMAGE}" -c 'ldd --version | awk "NR==1{print \$NF}"')"

cleanup; trap - EXIT

# --- shared-library dependency closure ----------------------------------
# Resolve every non-glibc .so each ELF pulls in, using ldd *inside the image*
# so we bundle exactly the versions the binaries were linked against. glibc
# itself (libc/libm/pthread/dl/rt/resolv and the loader) is intentionally left
# for the target system.
log "bundling the shared-library closure"
"${PODMAN}" run --rm --network=none -v "${STAGE}:/stage:Z" --entrypoint bash "${IMAGE}" -c '
	set -eu
	copy_closure() {
		ldd "$1" 2>/dev/null | awk "/=> \//{print \$3}" | while read -r so; do
			case "${so##*/}" in
				libc.so.*|libm.so.*|libpthread.so.*|libdl.so.*|librt.so.*|libresolv.so.*|ld-linux*.so.*|libnss_*.so.*)
					continue ;;
			esac
			[ -e "/stage/lib/${so##*/}" ] || cp -Lv "$so" "/stage/lib/${so##*/}"
		done
	}
	for f in /stage/libexec/* /stage/lib/libphp.so /stage/lib/libphpx*.so /stage/lib/php-ext/*.so; do
		[ -e "$f" ] && copy_closure "$f"
	done
	# deps of the freshly copied deps
	for pass in 1 2 3; do
		for f in /stage/lib/*.so*; do [ -e "$f" ] && copy_closure "$f"; done
	done
'
chmod -R u+rwX,go+rX "${STAGE}/lib"

# --- one-time configure + per-binary launchers --------------------------
cat > "${STAGE}/configure.sh" <<'EOS'
#!/bin/sh
# Point the bundled libphp at the bundled PHP extension directory. Run once,
# after unpacking. Only needed for sql-serverd's PostgreSQL / MySQL drivers -
# SQLite is built into libphp and works without this.
set -eu
HERE=$(CDPATH= cd -- "$(dirname -- "$0")" && pwd)
{
	printf 'extension_dir="%s/lib/php-ext"\n' "$HERE"
	for f in "$HERE"/etc/php/conf.d/*.ini; do [ -f "$f" ] && cat "$f"; done
} > "$HERE/etc/php/php.ini"
echo "wrote $HERE/etc/php/php.ini"
EOS
chmod 0755 "${STAGE}/configure.sh"

for b in "${BINS[@]}"; do
	launcher="${b//_/-}"
	cat > "${STAGE}/bin/${launcher}" <<EOS
#!/bin/sh
set -eu
HERE=\$(CDPATH= cd -- "\$(dirname -- "\$0")/.." && pwd)
export LD_LIBRARY_PATH="\$HERE/lib\${LD_LIBRARY_PATH:+:\$LD_LIBRARY_PATH}"
[ -f "\$HERE/etc/php/php.ini" ] && export PHPRC="\$HERE/etc/php/php.ini"
exec "\$HERE/libexec/${b}" "\$@"
EOS
	chmod 0755 "${STAGE}/bin/${launcher}"
done

# --- README -------------------------------------------------------------
cat > "${STAGE}/README.md" <<EOF
# aun-filestore — TypePHP native binaries

Version: \`${VERSION}\`  ·  Arch: \`linux-${ARCH}\`

Ahead-of-time (\`swoole/typephp\`) compiled builds of the \`packaging/typephp\`
targets. No PHP runtime needed on the target - \`libphp\` / \`libphpx\` and the
rest of the shared-library closure are bundled in \`lib/\`.

## Binaries

| \`bin/\` | what it is |
|---|---|
| \`aun-filestored\`  | the file / print / bridge server: AUN UDP + the Ratchet WebSocket bridge + the relay listeners + Piconet |
| \`sharefsd\`        | standalone ShareFS / Level-4 / AccessPlus / Freeway daemon |
| \`dnsd\`            | DNS server, traffic relayed over the Remote Socket Protocol from a filestored instance |
| \`ntpd\`            | NTP server, same relay transport as \`dnsd\` |
| \`ecosyslogd\`      | EcoSyslog provider hosted over the Remote Provider Protocol |
| \`sql-serverd\`     | SQL service (SQLite / PostgreSQL / MySQL) hosted over the Remote Provider Protocol |
| \`teletext-import\` | teletext page fetcher: \`teletext-import <news\\|teefax\\|tvguide\\|weather\\|webfax> [options]\` |

Each \`bin/<name>\` is a small launcher that sets \`LD_LIBRARY_PATH\` to the
bundled \`lib/\` and \`exec\`s the real ELF in \`libexec/\`. They take the same
arguments as the interpreted daemons (\`-c <config-dir>\`, \`-d\`, \`-p <pidfile>\`).

## Use

\`\`\`sh
tar xzf ${NAME}.tar.gz
cd ${NAME}
./configure.sh                         # once - only needed for sql-serverd pgsql/mysql
./bin/aun-filestored -c /etc/aun-filestore
\`\`\`

## Requirements

* linux / ${ARCH}
* **glibc >= ${GLIBC}** - the binaries are built in the \`php:8.4-cli-bookworm\`
  (Debian 12) image; run them on a system at least that new (Debian 12+,
  Ubuntu 24.04+, ...), or inside that container.
* nothing else - curl / openssl / ldap / libpq / xml / sqlite / gmp / ... are all in \`lib/\`.
EOF

# --- tarball ----------------------------------------------------------
mkdir -p "${DIST}"
tar -C "${DIST}" -czf "${DIST}/${NAME}.tar.gz" "${NAME}"
( cd "${DIST}" && sha256sum "${NAME}.tar.gz" > "${NAME}.tar.gz.sha256" )
rm -rf "${STAGE}"

log "wrote dist/${NAME}.tar.gz ($(du -h "${DIST}/${NAME}.tar.gz" | cut -f1))"
if [ -n "${GITHUB_OUTPUT:-}" ]; then
	{
		echo "name=${NAME}"
		echo "tarball=dist/${NAME}.tar.gz"
	} >> "${GITHUB_OUTPUT}"
fi
