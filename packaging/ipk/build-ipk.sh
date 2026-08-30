#!/usr/bin/env bash
#
# Build the aun-filestored .ipk (opkg package), for OpenWrt and Alpine.
#
# Like the other packagers, the Composer dependencies are installed here
# (--no-dev) and staged into the payload, so the packaging step is offline.
#
# The package carries both procd (OpenWrt) and OpenRC (Alpine) init scripts
# under /usr/libexec/aun-filestore/init/; the postinst copies the right set into
# /etc/init.d/ for the running init system.
#
# Output (into OUTPUT_DIR, default <repo>/build):
#   aun-filestored_<version>_all.ipk
#
# Usage:
#   packaging/ipk/build-ipk.sh
#   IPK_VERSION=2.0.2 IPK_DEPENDS="php8-cli" packaging/ipk/build-ipk.sh
#
# Env vars:
#   IPK_VERSION  Package version. Default: derived from config.inc.php as
#                <major>.<minor>.0
#   IPK_DEPENDS  Value for the control "Depends:" field. Default: empty (install
#                PHP yourself). On OpenWrt you might set "php8-cli".
#   OUTPUT_DIR   Where the .ipk is written. Default: <repo>/build
#   KEEP_WORK    If set, the temporary build tree is not deleted.

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO_ROOT="$(cd "${SCRIPT_DIR}/../.." && pwd)"
SRC_DIR="${REPO_ROOT}/src"
OUTPUT_DIR="${OUTPUT_DIR:-${REPO_ROOT}/build}"
IPK_DEPENDS="${IPK_DEPENDS:-}"

log() { printf '  [ipk] %s\n' "$*" >&2; }
die() { printf 'error: %s\n' "$*" >&2; exit 1; }

# The .ipk is assembled by hand (gzipped tar of debian-binary + control.tar.gz
# + data.tar.gz), so there is no dependency on opkg-utils.
command -v tar      >/dev/null 2>&1 || die "tar not found"
command -v composer >/dev/null 2>&1 || die "composer not found on PATH"
command -v rsync    >/dev/null 2>&1 || die "rsync not found on PATH"

# --- version --------------------------------------------------------------
if [ -z "${IPK_VERSION:-}" ]; then
	major="$(sed -n "s/.*CONFIG_version_major'[, ]*\([0-9][0-9]*\).*/\1/p" "${SRC_DIR}/include/config.inc.php" | head -1)"
	minor="$(sed -n "s/.*CONFIG_version_minor'[, ]*\([0-9][0-9]*\).*/\1/p" "${SRC_DIR}/include/config.inc.php" | head -1)"
	IPK_VERSION="${major:-1}.${minor:-0}.0"
fi
log "version ${IPK_VERSION}"

# --- staging tree ------------------------------------------------------
WORK="$(mktemp -d "${TMPDIR:-/tmp}/aun-ipk.XXXXXX")"
cleanup() { [ -n "${KEEP_WORK:-}" ] || rm -rf "${WORK}"; }
trap cleanup EXIT
SRCSTAGE="${WORK}/src"
PKG="${WORK}/pkg"        # payload root (becomes data.tar.gz)
CTRL="${WORK}/control"   # CONTROL files (becomes control.tar.gz)
mkdir -p "${SRCSTAGE}" "${CTRL}" \
	"${PKG}/usr/libexec/aun-filestore/init/procd" \
	"${PKG}/usr/libexec/aun-filestore/init/openrc" \
	"${PKG}/usr/share/aun-filestored" \
	"${PKG}/etc/aun-filestored" \
	"${PKG}/var/lib/aun-filestore-root" \
	"${PKG}/var/spool/aun-filestore-print"

log "staging src/"
rsync -a \
	--exclude '/vendor/' \
	--exclude '/var/' \
	--exclude '/dev-tools/' \
	--exclude '.env' \
	--exclude '.env.*' \
	--exclude 'composer.lock.old' \
	--exclude 'symfony.lock' \
	--exclude 'rector.php' \
	--exclude 'users-live.txt' \
	"${SRC_DIR}/" "${SRCSTAGE}/"

log "installing composer dependencies (--no-dev)"
(
	cd "${SRCSTAGE}"
	composer install \
		--no-dev --no-interaction --no-progress --no-scripts \
		--optimize-autoloader --classmap-authoritative \
		--ignore-platform-reqs
)
find "${SRCSTAGE}/vendor" -type f \
	\( -name '*.py' -o -name '.gitignore' -o -name '.gitattributes' \) -delete

# --- payload -------------------------------------------------------------
LIBEXEC="${PKG}/usr/libexec/aun-filestore"
DATADIR="${PKG}/usr/share/aun-filestored"

cp -r "${SRCSTAGE}/include" "${DATADIR}/"
cp -r "${SRCSTAGE}/vendor"  "${DATADIR}/"
install -m 0755 "${SRCSTAGE}/symfony-console" "${DATADIR}/symfony-console"

for f in filestored sharefsd dnsd ntpd ecosyslogd sql-serverd; do
	install -m 0755 "${SRCSTAGE}/${f}" "${LIBEXEC}/${f}"
done
for f in "${SRCSTAGE}"/util/*; do
	install -m 0755 "${f}" "${LIBEXEC}/"
done

cp "${REPO_ROOT}/packaging/etc/aun-filestored/"* "${PKG}/etc/aun-filestored/"

# --- init scripts (procd + openrc), generated from the templates -------
# name|description|role   (role: standalone or provider)
SERVICES="
filestored|Econet NetFS/NetPrint file server|standalone
sharefsd|RISC OS ShareFS / Access+ file server|standalone
dnsd|Econet DNS service provider|provider
ntpd|Econet NTP (time) service provider|provider
ecosyslogd|Econet syslog-style logging service provider|provider
sql-serverd|Econet SQL service provider|provider
"
render() {
	# render <template> <daemon> <desc> <out>
	sed -e "s|@DAEMON@|$2|g" -e "s|@DESC@|$3|g" "$1" > "$4"
	chmod 0755 "$4"
}
echo "${SERVICES}" | while IFS='|' read -r name desc role; do
	[ -n "${name}" ] || continue
	render "${SCRIPT_DIR}/init/procd-${role}.template"  "${name}" "${desc}" "${LIBEXEC}/init/procd/aun-${name}"
	render "${SCRIPT_DIR}/init/openrc-${role}.template" "${name}" "${desc}" "${LIBEXEC}/init/openrc/aun-${name}"
done

# --- CONTROL ----------------------------------------------------------
installed_size="$(du -sb "${PKG}" | cut -f1)"
if [ -n "${IPK_DEPENDS}" ]; then
	sed -e "s|@VERSION@|${IPK_VERSION}|g" -e "s|@DEPENDS@|Depends: ${IPK_DEPENDS}|" \
		"${SCRIPT_DIR}/control.in" > "${CTRL}/control"
else
	sed -e "s|@VERSION@|${IPK_VERSION}|g" -e "/@DEPENDS@/d" \
		"${SCRIPT_DIR}/control.in" > "${CTRL}/control"
fi
printf 'Installed-Size: %s\n' "${installed_size}" >> "${CTRL}/control"
install -m 0755 "${SCRIPT_DIR}/CONTROL/postinst"  "${CTRL}/postinst"
install -m 0755 "${SCRIPT_DIR}/CONTROL/prerm"     "${CTRL}/prerm"
install -m 0755 "${SCRIPT_DIR}/CONTROL/postrm"    "${CTRL}/postrm"
install -m 0644 "${SCRIPT_DIR}/CONTROL/conffiles" "${CTRL}/conffiles"

# --- assemble the .ipk -------------------------------------------------
# An .ipk is a gzip-compressed tar (the OpenWrt-canonical form, also read by
# newer libarchive-based opkg) whose members are debian-binary, control.tar.gz
# and data.tar.gz, in that order.
mkdir -p "${OUTPUT_DIR}"
IPK="${OUTPUT_DIR}/aun-filestored_${IPK_VERSION}_all.ipk"
rm -f "${IPK}"

INNER_TAR="--owner=0 --group=0 --numeric-owner --sort=name --format=gnu --mtime=@${SOURCE_DATE_EPOCH:-0}"

printf '2.0\n' > "${WORK}/debian-binary"
# shellcheck disable=SC2086
tar ${INNER_TAR} -czf "${WORK}/control.tar.gz" -C "${CTRL}" .
# shellcheck disable=SC2086
tar ${INNER_TAR} -czf "${WORK}/data.tar.gz"    -C "${PKG}"  .

# Outer archive: keep the member order explicit (do NOT sort).
tar --owner=0 --group=0 --numeric-owner --format=gnu --mtime=@${SOURCE_DATE_EPOCH:-0} \
	-czf "${IPK}" -C "${WORK}" ./debian-binary ./control.tar.gz ./data.tar.gz

[ -f "${IPK}" ] || die "failed to assemble ${IPK}"
log "wrote ${IPK}"
echo "${IPK}"
