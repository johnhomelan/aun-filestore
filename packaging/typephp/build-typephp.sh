#!/usr/bin/env bash
#
# Run the TypePHP (swoole/typephp) AOT compiler against aun-filestore inside a
# podman container.
#
# By default this does a --dry run: it only transpiles the configured sources to
# C++ under build/typephp/obj/<target>/ and does NOT link a native binary (that needs
# libphp.so / the embed SAPI and is very unlikely to succeed for this app - see
# packaging/typephp/README.md).
#
# Usage:
#   packaging/typephp/build-typephp.sh
#   TYPEPHP_DRY=0 TYPEPHP_OPT=3 packaging/typephp/build-typephp.sh
#
# Env vars:
#   PODMAN          Container CLI. Default: podman
#   TYPEPHP_IMAGE   Image tag to build/use. Default: localhost/aun-filestore-typephp
#   TYPEPHP_DRY     1 = generate C++ only (default), 0 = attempt a full build
#   TYPEPHP_MODE    bin | lib | ext. Default: bin
#   TYPEPHP_OPT     Optimisation level 0-3. Default: 2
#   TYPEPHP_JOBS    Parallel compile jobs. Default: 4
#   TYPEPHP_ARGS    Extra raw arguments appended to the tpc command line
#   TYPEPHP_BUILD_ARGS  Extra raw arguments for "podman build". On a host with a
#                   very large routing table, rootless podman's pasta backend can
#                   fail ("Too many routes to duplicate"); set
#                   TYPEPHP_BUILD_ARGS=--network=host to work around it.
#   OUTPUT_DIR      Where artefacts land (bind-mounted). Default: <repo>/build
#   TYPEPHP_NO_BUILD_IMAGE  If set, skip "podman build" and use the tag as-is
#   TYPEPHP_PROJECT  Path (repo-relative) to the tpc project file.
#                    Default: packaging/typephp/project.yml
#   TYPEPHP_BUILD_DIR  tpc --build-dir (repo-relative). Default:
#                    build/typephp/obj/<TYPEPHP_OUT> - one dir per target, so the
#                    six daemon builds don't invalidate each other's objects and
#                    can run in parallel.
#   TYPEPHP_PREP_ONLY  If set: build the toolchain image (unless
#                    TYPEPHP_NO_BUILD_IMAGE), stage the vendored ReactPHP/Ratchet
#                    tree, apply vendor-patches, regenerate config_defines.php +
#                    stage/cmd/, pre-compile the Smarty templates, then exit
#                    WITHOUT running tpc. Run once ("make typephp-prep") before a
#                    batch build so the per-target builds can skip all of this.
#   TYPEPHP_SKIP_PREP  If set: skip all of the above and go straight to tpc -
#                    assumes a prior TYPEPHP_PREP_ONLY run populated
#                    build/typephp/stage/ and pre-compiled the templates.
#
# Vendored ReactPHP / Ratchet:
#   When the active project file references build/typephp/stage/ (see
#   packaging/typephp/PORTING-REACT.md), this script first stages the needed
#   vendor packages into build/typephp/stage/vendor/ and applies every patch in
#   packaging/typephp/vendor-patches/*.patch to that copy - src/vendor is never
#   touched, so Composer and the interpreted runtime keep working.

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO_ROOT="$(cd "${SCRIPT_DIR}/../.." && pwd)"
OUTPUT_DIR="${OUTPUT_DIR:-${REPO_ROOT}/build}"

PODMAN="${PODMAN:-podman}"
IMAGE="${TYPEPHP_IMAGE:-localhost/aun-filestore-typephp}"
DRY="${TYPEPHP_DRY:-1}"
MODE="${TYPEPHP_MODE:-bin}"
OPT="${TYPEPHP_OPT:-2}"
JOBS="${TYPEPHP_JOBS:-4}"
PROJECT_FILE="${TYPEPHP_PROJECT:-packaging/typephp/project.yml}"
TYPEPHP_OUT="${TYPEPHP_OUT:-aun-filestored}"   # basename of the linked binary under build/typephp/
BUILD_DIR="${TYPEPHP_BUILD_DIR:-build/typephp/obj/${TYPEPHP_OUT}}"
PREP_ONLY="${TYPEPHP_PREP_ONLY:-}"
SKIP_PREP="${TYPEPHP_SKIP_PREP:-}"

# Vendor packages that the React/Ratchet stages pull into `sources`. Staged as a
# group (cheap) whenever the project file opts in; unused entries cost nothing.
STAGE_PKGS=(evenement react ratchet cboden guzzlehttp ringcentral psr fig ralouphie)
STAGE_DIR="${REPO_ROOT}/build/typephp/stage"
PATCH_DIR="${SCRIPT_DIR}/vendor-patches"

log() { printf '  [typephp] %s\n' "$*" >&2; }
die() { printf 'error: %s\n' "$*" >&2; exit 1; }

command -v "${PODMAN}" >/dev/null 2>&1 || die "'${PODMAN}' not found (set PODMAN=...)"

if [ -z "${TYPEPHP_NO_BUILD_IMAGE:-}" ]; then
	log "building image ${IMAGE}"
	build_args=()
	# shellcheck disable=SC2206
	[ -n "${TYPEPHP_BUILD_ARGS:-}" ] && build_args=( ${TYPEPHP_BUILD_ARGS} )
	"${PODMAN}" build "${build_args[@]}" -t "${IMAGE}" -f "${SCRIPT_DIR}/Containerfile" "${SCRIPT_DIR}"
fi

mkdir -p "${OUTPUT_DIR}/typephp"
mkdir -p "${REPO_ROOT}/${BUILD_DIR}"

# --- Stage + patch the vendored ReactPHP / Ratchet tree, if the project opts in.
#
# This whole section (vendor staging + patches + config_defines + stage/cmd/ +
# Smarty pre-compile) is identical for every target, so a batch build runs it
# once via "make typephp-prep" (TYPEPHP_PREP_ONLY=1) and then builds each daemon
# with TYPEPHP_SKIP_PREP=1.
if [ -n "${SKIP_PREP}" ]; then
	log "skipping prep (TYPEPHP_SKIP_PREP set) - reusing build/typephp/stage/"
	[ -f "${STAGE_DIR}/config_defines.php" ] \
		|| die "TYPEPHP_SKIP_PREP set but ${STAGE_DIR#"${REPO_ROOT}/"}/config_defines.php is missing - run 'make typephp-prep' first"
elif [ -n "${PREP_ONLY}" ] || grep -q 'build/typephp/stage/' "${REPO_ROOT}/${PROJECT_FILE}"; then
	[ -d "${REPO_ROOT}/src/vendor" ] || die "src/vendor missing - run 'composer install' in src/ first"
	command -v rsync >/dev/null 2>&1 || die "rsync not found (needed to stage vendor packages)"
	command -v patch >/dev/null 2>&1 || die "patch not found (needed to apply vendor-patches)"

	log "staging vendor packages into ${STAGE_DIR#"${REPO_ROOT}/"}/vendor"
	rm -rf "${STAGE_DIR}/vendor"
	mkdir -p "${STAGE_DIR}/vendor"
	for pkg in "${STAGE_PKGS[@]}"; do
		[ -d "${REPO_ROOT}/src/vendor/${pkg}" ] || continue
		rsync -a --exclude='tests/' --exclude='test/' --exclude='.git/' \
			"${REPO_ROOT}/src/vendor/${pkg}" "${STAGE_DIR}/vendor/"
	done

	if [ -d "${PATCH_DIR}" ]; then
		shopt -s nullglob
		for p in "${PATCH_DIR}"/*.patch; do
			log "applying $(basename "${p}")"
			patch -d "${STAGE_DIR}" -p1 --forward --reject-file=- < "${p}" \
				|| die "vendor patch failed: $(basename "${p}") (rebase it against src/vendor)"
		done
		shopt -u nullglob
	fi

	# config.inc.php is 159 safe_define('CONFIG_x', <scalar>) calls - executable
	# statements, illegal at file scope in `bin` mode. Rewrite them as `const`
	# declarations (which are legal) into a generated file the react-app project
	# lists in `sources`. Regenerated every build, so it tracks config.inc.php.
	log "generating stage/config_defines.php from src/include/config.inc.php"
	{
		echo '<?php'
		echo '// GENERATED by build-typephp.sh from src/include/config.inc.php - do not edit.'
		echo '// safe_define() calls rewritten as const declarations for TypePHP bin mode.'
		sed -nE "s#^[[:space:]]*safe_define\('([A-Za-z0-9_]+)'[[:space:]]*,[[:space:]]*(.+)\)[[:space:]]*;.*\$#const \1 = \2;#p" \
			"${REPO_ROOT}/src/include/config.inc.php"
	} > "${STAGE_DIR}/config_defines.php"
	defcount=$(grep -c '^const ' "${STAGE_DIR}/config_defines.php" || true)
	srccount=$(grep -c 'safe_define(' "${REPO_ROOT}/src/include/config.inc.php" || true)
	[ "${defcount}" = "${srccount}" ] \
		|| die "config_defines.php: rewrote ${defcount} of ${srccount} safe_define() calls - fix the sed in build-typephp.sh"

	# Some Command/*.php files include_once system.inc.php at file scope
	# (illegal in bin mode). Stage stripped copies for any project that opts in
	# by listing build/typephp/stage/cmd/.
	if [ -n "${PREP_ONLY}" ] || grep -q 'build/typephp/stage/cmd/' "${REPO_ROOT}/${PROJECT_FILE}"; then
		log "staging stripped Command/*.php into stage/cmd/"
		mkdir -p "${STAGE_DIR}/cmd"
		for f in "${REPO_ROOT}"/src/include/classes/Command/*.php; do
			sed -E \
				-e "/^[[:space:]]*(include_once|require_once)\(.*system\.inc\.php.*\);/d" \
				-e "s/\\\$http_response_header\[[0-9]+\][[:space:]]*\?\?[[:space:]]*null/null/g" \
				"${f}" > "${STAGE_DIR}/cmd/$(basename "${f}")"
		done
	fi
fi

# Pre-compile the Smarty templates so the Admin code never needs the Smarty
# template compiler at run time (see src/util/compile-templates). Only possible
# once the app's Composer deps are installed.
if [ -n "${SKIP_PREP}" ]; then
	:
elif [ -f "${REPO_ROOT}/src/vendor/autoload.php" ]; then
	log "pre-compiling Smarty templates"
	php "${REPO_ROOT}/src/util/compile-templates" || die "template pre-compilation failed"
else
	log "skipping Smarty pre-compilation (run 'composer install' in src/ first)"
fi

# Strip the file-scope `if (isFresh()) { ... }` wrapper off each pre-compiled
# admin template so tpc (mode: bin) can list the bare content_*() functions in
# `sources`, and emit stage/admin-templates/admin_template_dispatch.php (name ->
# unifunc table + switch() invoker). The native admin UI uses these + the
# compile-only Smarty shim (shims/smarty_runtime.php) instead of the real
# smarty/smarty runtime, which needs run-time `include`/`$unifunc()` (impossible
# in an AOT binary). See PORTING-REACT.md "Stage 10c/10d".
if [ -n "${SKIP_PREP}" ]; then
	:
elif [ -d "${REPO_ROOT}/src/include/classes/Admin/templates_c" ]; then
	log "stripping compiled admin templates -> stage/admin-templates/"
	rm -rf "${STAGE_DIR}/admin-templates"
	mkdir -p "${STAGE_DIR}/admin-templates"
	php "${SCRIPT_DIR}/build-admin-templates.php" \
		"${REPO_ROOT}/src/include/classes/Admin/templates_c" \
		"${STAGE_DIR}/admin-templates" \
		|| die "admin template strip failed"
fi

# Stage the 8 Admin\Controller\* classes with `extends AbstractController` and
# its `use` removed - they never call an AbstractController helper (all the
# `$this->...()` calls are their own private/protected methods), and the base
# class drags in the whole FrameworkBundle. Also inline the two static assets
# (favicon.ico / teletext-render.js) that IndexController::favicon() and
# TeletextController::renderScript() read via `file_get_contents(__DIR__.'/../static/...')`
# - __DIR__ is meaningless in an AOT binary. The native dispatcher
# (packaging/typephp/admin/dispatcher.php) instantiates the controllers directly.
# Same non-invasive "strip into stage/" pattern as stage/cmd/. See
# PORTING-REACT.md "Stage 10d".
if [ -n "${SKIP_PREP}" ]; then
	:
elif [ -d "${REPO_ROOT}/src/include/classes/Admin/Controller" ]; then
	log "staging de-Symfony'd admin controllers -> stage/admin-ctl/"
	rm -rf "${STAGE_DIR}/admin-ctl"
	mkdir -p "${STAGE_DIR}/admin-ctl"

	# admin-static.php: the static assets base64'd into a const map.
	{
		echo '<?php'
		echo '// GENERATED by build-typephp.sh - admin static assets, base64.'
		echo 'namespace HomeLan\FileStore\Admin\Compiled;'
		printf "const ADMIN_STATIC = [\n"
		for a in favicon.ico teletext-render.js; do
			src="${REPO_ROOT}/src/include/classes/Admin/static/${a}"
			[ -f "${src}" ] || continue
			printf "    '%s' => '%s',\n" "${a}" "$(base64 -w0 "${src}")"
		done
		printf "];\n"
	} > "${STAGE_DIR}/admin-static.php"

	for f in "${REPO_ROOT}"/src/include/classes/Admin/Controller/*.php; do
		sed -E \
			-e '/^use Symfony\\Bundle\\FrameworkBundle\\Controller\\AbstractController;$/d' \
			-e 's/^(class [A-Za-z]+Controller) extends AbstractController[[:space:]]*$/\1/' \
			-e "s#file_get_contents\(__DIR__ ?\. ?[\"']/\.\./static/favicon\.ico[\"']\)#base64_decode(\\\\HomeLan\\\\FileStore\\\\Admin\\\\Compiled\\\\ADMIN_STATIC['favicon.ico'])#" \
			-e "s#file_get_contents\(__DIR__ ?\. ?[\"']/\.\./static/teletext-render\.js[\"']\)#base64_decode(\\\\HomeLan\\\\FileStore\\\\Admin\\\\Compiled\\\\ADMIN_STATIC['teletext-render.js'])#" \
			"${f}" > "${STAGE_DIR}/admin-ctl/$(basename "${f}")"
	done
fi

# Same treatment for sharefsd's own (smaller) Symfony admin UI - ShareFs/Admin/.
# 5 templates, 2 controllers, no static assets. Reuses build-admin-templates.php
# and the same shims/dispatcher pattern; the stripped templates keep the
# `HomeLan\FileStore\Admin\Compiled` namespace (each binary compiles only its own
# dispatch file, so there is no clash). See PORTING-REACT.md "Stage 10d".
if [ -n "${SKIP_PREP}" ]; then
	:
elif [ -d "${REPO_ROOT}/src/include/classes/ShareFs/Admin/templates_c" ]; then
	log "stripping compiled sharefs admin templates -> stage/sharefs-admin-templates/"
	rm -rf "${STAGE_DIR}/sharefs-admin-templates"
	mkdir -p "${STAGE_DIR}/sharefs-admin-templates"
	php "${SCRIPT_DIR}/build-admin-templates.php" \
		"${REPO_ROOT}/src/include/classes/ShareFs/Admin/templates_c" \
		"${STAGE_DIR}/sharefs-admin-templates" \
		|| die "sharefs admin template strip failed"

	log "staging de-Symfony'd sharefs admin controllers -> stage/sharefs-admin-ctl/"
	rm -rf "${STAGE_DIR}/sharefs-admin-ctl"
	mkdir -p "${STAGE_DIR}/sharefs-admin-ctl"
	for f in "${REPO_ROOT}"/src/include/classes/ShareFs/Admin/Controller/*.php; do
		sed -E \
			-e '/^use Symfony\\Bundle\\FrameworkBundle\\Controller\\AbstractController;$/d' \
			-e 's/^(class [A-Za-z]+Controller) extends AbstractController[[:space:]]*$/\1/' \
			"${f}" > "${STAGE_DIR}/sharefs-admin-ctl/$(basename "${f}")"
	done
fi

if [ -n "${PREP_ONLY}" ]; then
	log "prep complete (TYPEPHP_PREP_ONLY) - stage/ populated, templates compiled; skipping tpc"
	exit 0
fi

# tpc is invoked with the repo bind-mounted at /src; all paths in project.yml
# are relative to that file, i.e. relative to the repo root.
tpc_args=(
	"${PROJECT_FILE}"
	-m "${MODE}"
	-O "${OPT}"
	-j "${JOBS}"
	--build-dir "${BUILD_DIR}"
	--no-progress
	--no-color
)
if [ "${DRY}" = 1 ]; then
	tpc_args+=( --dry )
else
	tpc_args+=( -o "build/typephp/${TYPEPHP_OUT}" )
fi
# shellcheck disable=SC2206
[ -n "${TYPEPHP_ARGS:-}" ] && tpc_args+=( ${TYPEPHP_ARGS} )

# tpc needs no network (deps are baked into the image, sources are mounted).
# --network=none also dodges rootless podman's pasta backend on hosts with a
# huge routing table. Override with TYPEPHP_RUN_ARGS if you really need network
# (e.g. the interactive libphp.so downloader for a full build).
run_args=( --network=none )
# shellcheck disable=SC2206
[ -n "${TYPEPHP_RUN_ARGS:-}" ] && run_args=( ${TYPEPHP_RUN_ARGS} )

log "running: tpc ${tpc_args[*]}"
"${PODMAN}" run --rm \
	"${run_args[@]}" \
	-v "${REPO_ROOT}":/src:Z \
	-w /src \
	"${IMAGE}" \
	"${tpc_args[@]}"

if [ -n "${HOST_UID:-}" ]; then
	chown -R "${HOST_UID}:${HOST_GID:-${HOST_UID}}" "${OUTPUT_DIR}/typephp" 2>/dev/null || true
fi

log "output under ${OUTPUT_DIR}/typephp/"
