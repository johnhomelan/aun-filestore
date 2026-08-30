#!/usr/bin/env bash
#
# Build the aun-filestored .deb.
#
# Like the other packagers, the Composer dependencies are installed here
# (--no-dev) and vendored into the source tree, so dpkg-buildpackage only has to
# lay the files out - no network, no build step.
#
# Output (into OUTPUT_DIR, default <repo>/build):
#   aun-filestored_<version>_all.deb
#
# Usage:
#   packaging/deb/build-deb.sh
#   DEB_VERSION=2.0.2 packaging/deb/build-deb.sh
#
# Env vars:
#   DEB_VERSION  Package version. Default: derived from
#                src/include/config.inc.php as <major>.<minor>.0
#   DEB_PHP_MIN  Minimum php-cli version put in Depends. Default: 2:8.4
#                (Debian's PHP has epoch 2:). Lower / clear it for older targets.
#   OUTPUT_DIR   Where the .deb is written. Default: <repo>/build
#   KEEP_WORK    If set, the temporary build tree is not deleted.

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO_ROOT="$(cd "${SCRIPT_DIR}/../.." && pwd)"
SRC_DIR="${REPO_ROOT}/src"
OUTPUT_DIR="${OUTPUT_DIR:-${REPO_ROOT}/build}"
DEB_PHP_MIN="${DEB_PHP_MIN:-2:8.4}"

log() { printf '  [deb] %s\n' "$*" >&2; }
die() { printf 'error: %s\n' "$*" >&2; exit 1; }

command -v dpkg-buildpackage >/dev/null 2>&1 || die "dpkg-buildpackage not found (install 'dpkg-dev')"
command -v dh                >/dev/null 2>&1 || die "dh not found (install 'debhelper')"
command -v composer          >/dev/null 2>&1 || die "composer not found on PATH"
command -v rsync             >/dev/null 2>&1 || die "rsync not found on PATH"

# --- version --------------------------------------------------------------
if [ -z "${DEB_VERSION:-}" ]; then
	major="$(sed -n "s/.*CONFIG_version_major'[, ]*\([0-9][0-9]*\).*/\1/p" "${SRC_DIR}/include/config.inc.php" | head -1)"
	minor="$(sed -n "s/.*CONFIG_version_minor'[, ]*\([0-9][0-9]*\).*/\1/p" "${SRC_DIR}/include/config.inc.php" | head -1)"
	DEB_VERSION="${major:-1}.${minor:-0}.0"
fi
log "version ${DEB_VERSION}"

# --- source tree ----------------------------------------------------
WORK="$(mktemp -d "${TMPDIR:-/tmp}/aun-deb.XXXXXX")"
cleanup() { [ -n "${KEEP_WORK:-}" ] || rm -rf "${WORK}"; }
trap cleanup EXIT
TREE="${WORK}/aun-filestored-${DEB_VERSION}"
mkdir -p "${TREE}/src"

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
	"${SRC_DIR}/" "${TREE}/src/"

log "installing composer dependencies (--no-dev)"
(
	cd "${TREE}/src"
	composer install \
		--no-dev --no-interaction --no-progress --no-scripts \
		--optimize-autoloader --classmap-authoritative \
		--ignore-platform-reqs
)

# Strip non-runtime cruft a few dependencies ship: a Python CI helper (which
# would otherwise pull a python3 dependency into the package) and stray VCS
# control files.
find "${TREE}/src/vendor" -type f \
	\( -name '*.py' -o -name '.gitignore' -o -name '.gitattributes' \) -delete

# --- debian/ ---------------------------------------------------------
cp -r "${SCRIPT_DIR}/debian" "${TREE}/debian"
chmod +x "${TREE}/debian/rules"
mkdir -p "${TREE}/debian/systemd" "${TREE}/debian/config"
cp "${REPO_ROOT}/packaging/systemd/"*.service          "${TREE}/debian/systemd/"
cp "${REPO_ROOT}/packaging/etc/aun-filestored/"*       "${TREE}/debian/config/"

# PHP floor in the runtime dependency
if [ -n "${DEB_PHP_MIN}" ]; then
	sed -i "s|@PHP_MIN@|${DEB_PHP_MIN}|g" "${TREE}/debian/control"
else
	sed -i "s| *php-cli (>= @PHP_MIN@),| php-cli,|g" "${TREE}/debian/control"
fi

# Fresh changelog entry for this build
cat > "${TREE}/debian/changelog" <<EOF
aun-filestored (${DEB_VERSION}) unstable; urgency=medium

  * Automated build.

 -- John Brown <john@home-lan.co.uk>  $(date -R)
EOF

# --- build ------------------------------------------------------------
# -d: skip the build-dependency check. build-in-container.sh installs
# debhelper/dpkg-dev explicitly, and on a non-Debian build host
# dpkg-checkbuilddeps cannot see them in the (absent) dpkg database anyway.
log "running dpkg-buildpackage -b -d"
(
	cd "${TREE}"
	dpkg-buildpackage -b -us -uc -d
)

# --- collect --------------------------------------------------------
mkdir -p "${OUTPUT_DIR}"
mapfile -t BUILT < <(find "${WORK}" -maxdepth 1 -type f -name '*.deb')
[ "${#BUILT[@]}" -gt 0 ] || die "dpkg-buildpackage produced no .deb"

for f in "${BUILT[@]}"; do
	cp "${f}" "${OUTPUT_DIR}/"
	log "wrote ${OUTPUT_DIR}/$(basename "${f}")"
	echo "${OUTPUT_DIR}/$(basename "${f}")"
done

if command -v lintian >/dev/null 2>&1; then
	log "lintian:"
	lintian "${BUILT[@]}" || true
fi
