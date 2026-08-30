#!/usr/bin/env bash
#
# Build filestore.phar and a distributable tarball around it.
#
# Output (into OUTPUT_DIR, default <repo>/build):
#   filestore.phar                          - the standalone archive
#   aun-filestored-<version>-phar.tgz       - phar + run.sh + sample config
#
# Usage:
#   packaging/phar/build-phar.sh
#   PHAR_VERSION=2.0.2 OUTPUT_DIR=build packaging/phar/build-phar.sh
#
# Env vars:
#   PHAR_VERSION  Version string for the tarball name. Default: derived from
#                 src/include/config.inc.php as <major>.<minor>.0
#   OUTPUT_DIR    Where artefacts are written. Default: <repo>/build
#   KEEP_WORK     If set, the temporary staging tree is not deleted.

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO_ROOT="$(cd "${SCRIPT_DIR}/../.." && pwd)"
SRC_DIR="${REPO_ROOT}/src"
PHAR_DIR="${SCRIPT_DIR}"
OUTPUT_DIR="${OUTPUT_DIR:-${REPO_ROOT}/build}"

log() { printf '  [phar] %s\n' "$*" >&2; }
die() { printf 'error: %s\n' "$*" >&2; exit 1; }

command -v composer >/dev/null 2>&1 || die "composer not found on PATH"
command -v php >/dev/null 2>&1 || die "php not found on PATH"
command -v rsync >/dev/null 2>&1 || die "rsync not found on PATH"

# --- version --------------------------------------------------------------
if [ -z "${PHAR_VERSION:-}" ]; then
	major="$(sed -n "s/.*CONFIG_version_major'[, ]*\([0-9][0-9]*\).*/\1/p" "${SRC_DIR}/include/config.inc.php" | head -1)"
	minor="$(sed -n "s/.*CONFIG_version_minor'[, ]*\([0-9][0-9]*\).*/\1/p" "${SRC_DIR}/include/config.inc.php" | head -1)"
	PHAR_VERSION="${major:-1}.${minor:-0}.0"
fi
log "version ${PHAR_VERSION}"

# --- staging tree ------------------------------------------------------
WORK="$(mktemp -d "${TMPDIR:-/tmp}/aun-phar.XXXXXX")"
STAGE="${WORK}/stage"
cleanup() { [ -n "${KEEP_WORK:-}" ] || rm -rf "${WORK}"; }
trap cleanup EXIT
mkdir -p "${STAGE}"

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
	--exclude 'users.txt' \
	--exclude 'users-live.txt' \
	"${SRC_DIR}/" "${STAGE}/"

log "installing composer dependencies (--no-dev)"
(
	cd "${STAGE}"
	composer install \
		--no-dev --no-interaction --no-progress --no-scripts \
		--optimize-autoloader --classmap-authoritative \
		--ignore-platform-reqs
)

log "pre-compiling Smarty templates"
(
	cd "${STAGE}"
	php util/compile-templates
)

log "pre-warming the admin container cache"
(
	cd "${STAGE}"
	php "${PHAR_DIR}/warm-admin-cache.php"
)
# The warmed container is all we need shipped; drop transient bits.
rm -rf "${STAGE}/var/log" "${STAGE}/var/cache"/*.log 2>/dev/null || true
find "${STAGE}/var/cache" -name '*.php.lock' -delete 2>/dev/null || true

# --- build the phar --------------------------------------------------
mkdir -p "${OUTPUT_DIR}"
PHAR_PATH="${OUTPUT_DIR}/filestore.phar"
log "assembling ${PHAR_PATH}"
php -d phar.readonly=0 "${PHAR_DIR}/create-phar.php" "${PHAR_PATH}" "${STAGE}"
chmod 0755 "${PHAR_PATH}"

log "smoke test: php filestore.phar --help"
php "${PHAR_PATH}" --help >/dev/null

# --- distributable tarball ---------------------------------------------
BUNDLE="${WORK}/filestore"
mkdir -p "${BUNDLE}/file-root" "${BUNDLE}/print-spool"
cp "${PHAR_PATH}"                 "${BUNDLE}/filestore.phar"
cp "${PHAR_DIR}/README"           "${BUNDLE}/README"
cp "${PHAR_DIR}/default.conf"     "${BUNDLE}/default.conf"
cp "${PHAR_DIR}/users.txt"        "${BUNDLE}/users.txt"
install -m 0755 "${PHAR_DIR}/run.sh" "${BUNDLE}/run.sh"
: > "${BUNDLE}/file-root/.keep"
: > "${BUNDLE}/print-spool/.keep"

TGZ_PATH="${OUTPUT_DIR}/aun-filestored-${PHAR_VERSION}-phar.tgz"
tar czf "${TGZ_PATH}" \
	--owner=0 --group=0 --numeric-owner \
	-C "${WORK}" filestore

log "wrote ${PHAR_PATH}"
log "wrote ${TGZ_PATH}"
echo "${PHAR_PATH}"
echo "${TGZ_PATH}"
