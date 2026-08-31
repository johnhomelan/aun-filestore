#!/usr/bin/env bash
#
# Build the aun-filestored RPM.
#
# Mirrors the other packagers: the Composer dependencies are installed here
# (--no-dev) and baked into the source tarball, so rpmbuild only has to lay the
# files out - it never needs network access or Composer.
#
# Output (into OUTPUT_DIR, default <repo>/build):
#   aun-filestored-<version>-<release>.<arch>.rpm
#   aun-filestored-<version>-<release>.src.rpm      (only with RPM_SRPM=1)
#
# Usage:
#   packaging/rpm/build-rpm.sh
#   RPM_VERSION=2.0.2 RPM_RELEASE=1 packaging/rpm/build-rpm.sh
#
# Env vars:
#   RPM_VERSION  Package version. Default: derived from
#                src/include/config.inc.php as <major>.<minor>.0
#   RPM_RELEASE  Release number. Default: 1
#   RPM_ARCH     Package arch. Default: noarch
#   RPM_DIST     Dist tag appended to the release (e.g. .fc43). Default: empty
#   RPM_PHP_MIN  Minimum PHP version the package requires. Default: 8.4 (set in
#                the spec). Lower it for older targets, e.g. an EL7 remi build.
#   RPM_SRPM     If set to 1, also build and collect the source RPM
#   RPM_SIGN     If set to 1, sign the finished RPM(s) with `rpm --addsign`
#                (needs %_gpg_name configured in ~/.rpmmacros)
#   OUTPUT_DIR   Where the RPM(s) are written. Default: <repo>/build
#   KEEP_WORK    If set, the temporary build tree is not deleted.

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO_ROOT="$(cd "${SCRIPT_DIR}/../.." && pwd)"
SRC_DIR="${REPO_ROOT}/src"
SPEC="${SCRIPT_DIR}/aun-filestored.spec"
OUTPUT_DIR="${OUTPUT_DIR:-${REPO_ROOT}/build}"

RPM_RELEASE="${RPM_RELEASE:-1}"
RPM_ARCH="${RPM_ARCH:-noarch}"
RPM_DIST="${RPM_DIST:-}"

log() { printf '  [rpm] %s\n' "$*" >&2; }
die() { printf 'error: %s\n' "$*" >&2; exit 1; }

command -v rpmbuild >/dev/null 2>&1 || die "rpmbuild not found (install the 'rpm-build' package)"
command -v composer >/dev/null 2>&1 || die "composer not found on PATH"
command -v rsync    >/dev/null 2>&1 || die "rsync not found on PATH"

# --- version --------------------------------------------------------------
if [ -z "${RPM_VERSION:-}" ]; then
	major="$(sed -n "s/.*CONFIG_version_major'[, ]*\([0-9][0-9]*\).*/\1/p" "${SRC_DIR}/include/config.inc.php" | head -1)"
	minor="$(sed -n "s/.*CONFIG_version_minor'[, ]*\([0-9][0-9]*\).*/\1/p" "${SRC_DIR}/include/config.inc.php" | head -1)"
	RPM_VERSION="${major:-1}.${minor:-0}.0"
fi
RELEASE_FULL="${RPM_RELEASE}${RPM_DIST}"
log "version ${RPM_VERSION}-${RELEASE_FULL} (${RPM_ARCH})"

# --- rpmbuild tree ------------------------------------------------------
WORK="$(mktemp -d "${TMPDIR:-/tmp}/aun-rpm.XXXXXX")"
cleanup() { [ -n "${KEEP_WORK:-}" ] || rm -rf "${WORK}"; }
trap cleanup EXIT
mkdir -p "${WORK}"/{SOURCES,SPECS,BUILD,BUILDROOT,RPMS,SRPMS}

# --- source tarball ---------------------------------------------------
# Layout matches what the spec's %install expects: src/ (with vendor/),
# etc/aun-filestored/ (config templates) and systemd/ (unit files). There is no
# top-level directory - the spec uses `%setup -c` to create one.
STAGE="${WORK}/stage"
mkdir -p "${STAGE}/src" "${STAGE}/etc" "${STAGE}/systemd"

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
	"${SRC_DIR}/" "${STAGE}/src/"

log "installing composer dependencies (--no-dev)"
(
	cd "${STAGE}/src"
	composer install \
		--no-dev --no-interaction --no-progress --no-scripts \
		--optimize-autoloader --classmap-authoritative \
		--ignore-platform-reqs
)

# A couple of vendored deps (e.g. aws/aws-crt-php) ship Python CI helper
# scripts. None are used at runtime by this PHP app, and rpmbuild's
# brp-python-bytecompile step blindly compiles every *.py in the buildroot -
# on EL7 that runs Python 2 and chokes on any Python 3 syntax (f-strings),
# failing %install. Strip them here so the package never contains them.
log "pruning vendored python helpers"
find "${STAGE}/src/vendor" \
	\( -name '*.py' -o -name '*.pyc' -o -name '*.pyo' -o -name '__pycache__' \) \
	-print -exec rm -rf {} + 2>/dev/null || true

cp -r "${REPO_ROOT}/packaging/etc/aun-filestored" "${STAGE}/etc/"
cp "${REPO_ROOT}/packaging/systemd/"*.service     "${STAGE}/systemd/"

TARBALL="${WORK}/SOURCES/aun-filestored-${RPM_VERSION}-${RELEASE_FULL}.tar.gz"
log "building $(basename "${TARBALL}")"
tar czf "${TARBALL}" --owner=0 --group=0 --numeric-owner -C "${STAGE}" src etc systemd

cp "${SPEC}" "${WORK}/SPECS/"

# --- rpmbuild ------------------------------------------------------------
BUILD_MODE="-bb"
[ "${RPM_SRPM:-}" = 1 ] && BUILD_MODE="-ba"

RPMBUILD_ARGS=(
	"${BUILD_MODE}" "${WORK}/SPECS/$(basename "${SPEC}")"
	--define "_topdir ${WORK}"
	--define "_name aun-filestored"
	--define "_version ${RPM_VERSION}"
	--define "_release ${RELEASE_FULL}"
	--define "_arch ${RPM_ARCH}"
)
[ -n "${RPM_PHP_MIN:-}" ] && RPMBUILD_ARGS+=(--define "_php_min ${RPM_PHP_MIN}")

log "running rpmbuild ${BUILD_MODE}"
rpmbuild "${RPMBUILD_ARGS[@]}"

# --- collect artefacts -------------------------------------------------
mkdir -p "${OUTPUT_DIR}"
mapfile -t BUILT < <(find "${WORK}/RPMS" "${WORK}/SRPMS" -type f -name '*.rpm' 2>/dev/null)
[ "${#BUILT[@]}" -gt 0 ] || die "rpmbuild produced no rpm files"

RESULT=()
for f in "${BUILT[@]}"; do
	cp "${f}" "${OUTPUT_DIR}/"
	RESULT+=("${OUTPUT_DIR}/$(basename "${f}")")
done

if [ "${RPM_SIGN:-}" = 1 ]; then
	log "signing"
	rpm --addsign "${RESULT[@]}"
fi

for f in "${RESULT[@]}"; do
	log "wrote ${f}"
	echo "${f}"
done
