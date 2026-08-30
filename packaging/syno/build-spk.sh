#!/usr/bin/env bash
#
# Build a Synology package (.spk) for aun-filestored.
#
# The resulting package:
#   * installs the PHP application under /var/packages/aun-filestored/target
#   * depends on the Synology PHP package for a PHP CLI runtime
#   * runs filestored as a DSM service (Package Center start/stop/status)
#   * adds a DSM desktop / main-menu icon that opens the admin web front end
#     (served by filestored itself on webadmin_listen_port, default 8080)
#
# Usage:
#   packaging/syno/build-spk.sh
#   SPK_VERSION=2.0.2-1 OUTPUT_DIR=build packaging/syno/build-spk.sh
#
# Env vars:
#   SPK_VERSION   Package version (X.Y.Z-N). Default: derived from
#                 src/include/config.inc.php as <major>.<minor>.0-1
#   OUTPUT_DIR    Where the .spk is written. Default: <repo>/build
#   KEEP_WORK     If set, the temporary build tree is not deleted.

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO_ROOT="$(cd "${SCRIPT_DIR}/../.." && pwd)"
SYNO_DIR="${SCRIPT_DIR}"
SRC_DIR="${REPO_ROOT}/src"
OUTPUT_DIR="${OUTPUT_DIR:-${REPO_ROOT}/build}"

PKGNAME="aun-filestored"
ICON_SRC="${SRC_DIR}/include/classes/Admin/static/favicon.ico"

log() { printf '  [spk] %s\n' "$*" >&2; }
die() { printf 'error: %s\n' "$*" >&2; exit 1; }

# --- tools ----------------------------------------------------------------
command -v composer >/dev/null 2>&1 || die "composer not found on PATH"
if command -v magick >/dev/null 2>&1; then
	IM=(magick)
elif command -v convert >/dev/null 2>&1; then
	IM=(convert)
else
	die "ImageMagick (magick/convert) not found on PATH - needed to build the menu icons"
fi

# --- version ------------------------------------------------------------
if [ -z "${SPK_VERSION:-}" ]; then
	major="$(sed -n "s/.*CONFIG_version_major'[, ]*\([0-9][0-9]*\).*/\1/p" "${SRC_DIR}/include/config.inc.php" | head -1)"
	minor="$(sed -n "s/.*CONFIG_version_minor'[, ]*\([0-9][0-9]*\).*/\1/p" "${SRC_DIR}/include/config.inc.php" | head -1)"
	SPK_VERSION="${major:-1}.${minor:-0}.0-1"
fi
# Synology wants X.Y.Z-N; add a build number if the caller left it off.
case "${SPK_VERSION}" in
	*-*) : ;;
	*)   SPK_VERSION="${SPK_VERSION}-1" ;;
esac
log "version ${SPK_VERSION}"

# --- work tree ----------------------------------------------------------
WORK="$(mktemp -d "${TMPDIR:-/tmp}/aun-spk.XXXXXX")"
STAGE="${WORK}/target"
SPKROOT="${WORK}/spk"
cleanup() { [ -n "${KEEP_WORK:-}" ] || rm -rf "${WORK}"; }
trap cleanup EXIT
mkdir -p "${STAGE}" "${SPKROOT}/ui/images"

# --- assemble the application payload ----------------------------------
log "staging application"
for launcher in filestored sharefsd dnsd ntpd ecosyslogd sql-serverd symfony-console; do
	[ -f "${SRC_DIR}/${launcher}" ] && install -m 0755 "${SRC_DIR}/${launcher}" "${STAGE}/${launcher}"
done

cp -r "${SRC_DIR}/include" "${STAGE}/include"
cp "${SRC_DIR}/composer.json" "${SRC_DIR}/composer.lock" "${STAGE}/"

mkdir -p "${STAGE}/ext-bin"
[ -f "${REPO_ROOT}/ext-bin/esc2ps" ] && install -m 0755 "${REPO_ROOT}/ext-bin/esc2ps" "${STAGE}/ext-bin/esc2ps"

# Symfony admin kernel writes its compiled container / logs here.
mkdir -p "${STAGE}/var/cache" "${STAGE}/var/log"

# Config templates consumed by scripts/postinst.
cp "${SYNO_DIR}/default.conf"                    "${STAGE}/default.conf.example"
cp "${REPO_ROOT}/packaging/etc/aun-filestored/users.txt"  "${STAGE}/users.txt.example"
cp "${REPO_ROOT}/packaging/etc/aun-filestored/aunmap.txt" "${STAGE}/aunmap.txt.example"

log "installing composer dependencies (--no-dev)"
(
	cd "${STAGE}"
	composer install \
		--no-dev --no-interaction --no-progress --no-scripts \
		--optimize-autoloader --classmap-authoritative \
		--ignore-platform-reqs
)
rm -rf "${STAGE}/var/cache"/* "${STAGE}/var/log"/*

# --- payload archive --------------------------------------------------
log "building package.tgz"
tar czf "${SPKROOT}/package.tgz" \
	--owner=0 --group=0 --numeric-owner \
	-C "${STAGE}" .

extractsize="$(du -sk "${STAGE}" | cut -f1)"
checksum="$(md5sum "${SPKROOT}/package.tgz" | cut -d' ' -f1)"

# --- INFO / metadata ------------------------------------------------
log "rendering INFO"
sed -e "s|@version@|${SPK_VERSION}|g" \
    -e "s|@checksum@|${checksum}|g" \
    -e "s|@extractsize@|${extractsize}|g" \
    "${SYNO_DIR}/INFO.in" > "${SPKROOT}/INFO"

cp "${SYNO_DIR}/LICENSE" "${SPKROOT}/LICENSE"

cp -r "${SYNO_DIR}/scripts" "${SPKROOT}/scripts"
chmod 0755 "${SPKROOT}/scripts"/*

install -m 0644 "${SYNO_DIR}/ui/config"    "${SPKROOT}/ui/config"
install -m 0755 "${SYNO_DIR}/ui/index.cgi" "${SPKROOT}/ui/index.cgi"

# --- menu / package icons (generated from the admin favicon) ---------
log "generating icons from $(basename "${ICON_SRC}")"
frame="$("${IM[@]}" identify -format '%p:%w\n' "${ICON_SRC}" 2>/dev/null | sort -t: -k2 -n | tail -1 | cut -d: -f1)"
frame="${frame:-0}"
for size in 16 24 32 48 64 72 256; do
	"${IM[@]}" "${ICON_SRC}[${frame}]" \
		-background none -alpha on \
		-resize "${size}x${size}" \
		-gravity center -extent "${size}x${size}" \
		"${SPKROOT}/ui/images/aun-filestored_${size}.png"
done
cp "${SPKROOT}/ui/images/aun-filestored_72.png"  "${SPKROOT}/PACKAGE_ICON.PNG"
cp "${SPKROOT}/ui/images/aun-filestored_256.png" "${SPKROOT}/PACKAGE_ICON_256.PNG"

# --- the .spk itself (an uncompressed tar) -------------------------------
mkdir -p "${OUTPUT_DIR}"
SPK_PATH="${OUTPUT_DIR}/${PKGNAME}-${SPK_VERSION}.spk"
tar cf "${SPK_PATH}" \
	--owner=0 --group=0 --numeric-owner \
	-C "${SPKROOT}" \
	INFO LICENSE PACKAGE_ICON.PNG PACKAGE_ICON_256.PNG package.tgz scripts ui

log "wrote ${SPK_PATH}"
echo "${SPK_PATH}"
