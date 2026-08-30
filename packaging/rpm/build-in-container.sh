#!/bin/bash
#
# Distro setup + RPM build, meant to be run *inside* a Fedora / Enterprise Linux
# container with the repo bind-mounted at /work. Used by .github/workflows/rpm.yml
# but works locally too:
#
#   docker run --rm -v "$PWD":/work -w /work \
#     -e RPM_DIST=.el9 -e OUTPUT_DIR=/work/build/rocky-9 \
#     rockylinux:9 bash packaging/rpm/build-in-container.sh
#
# Honoured env vars: RPM_VERSION, RPM_RELEASE, RPM_DIST, RPM_PHP_MIN, OUTPUT_DIR,
# HOST_UID / HOST_GID (chown the results back to that uid/gid when set).

set -euxo pipefail

. /etc/os-release
DISTRO_ID="${ID:-unknown}"
DISTRO_VER="${VERSION_ID%%.*}"

PM=dnf
command -v dnf >/dev/null 2>&1 || PM=yum

install_remi_release() {
	rpm -q remi-release >/dev/null 2>&1 && return 0
	$PM install -y "https://rpms.remirepo.net/enterprise/remi-release-${1}.rpm"
}

enable_remi_php() {
	# Prefer PHP 8.4 (what the code needs); fall back so the job still produces
	# *something* on distributions where remi has not got 8.4.
	if command -v dnf >/dev/null 2>&1; then
		dnf -y module reset php || true
		dnf -y module enable php:remi-8.4 \
			|| dnf -y module enable php:remi-8.3 \
			|| true
	else
		yum -y install yum-utils
		yum-config-manager --enable remi-php84 \
			|| yum-config-manager --enable remi-php83 \
			|| true
	fi
}

write_vault_repo() {
	# $1 = repo file body
	rm -f /etc/yum.repos.d/CentOS-*.repo
	printf '%s\n' "$1" > /etc/yum.repos.d/vault.repo
}

case "${DISTRO_ID}-${DISTRO_VER}" in
	fedora-*)
		# Fedora 42+ ships PHP 8.4 natively - nothing extra to do.
		;;

	centos-7)
		# CentOS 7 is EOL; its content lives on vault.centos.org now.
		write_vault_repo '[base]
name=CentOS-7 Base (vault)
baseurl=http://vault.centos.org/7.9.2009/os/x86_64/
gpgcheck=0
enabled=1

[updates]
name=CentOS-7 Updates (vault)
baseurl=http://vault.centos.org/7.9.2009/updates/x86_64/
gpgcheck=0
enabled=1

[extras]
name=CentOS-7 Extras (vault)
baseurl=http://vault.centos.org/7.9.2009/extras/x86_64/
gpgcheck=0
enabled=1'
		yum -y install epel-release \
			|| yum -y install "https://archives.fedoraproject.org/pub/archive/epel/7/x86_64/Packages/e/epel-release-7-14.noarch.rpm"
		install_remi_release 7
		enable_remi_php
		;;

	centos-8|centos-stream-8|rhel-8|rocky-8|almalinux-8)
		# CentOS Stream 8 is EOL; point at the vault.
		if ls /etc/yum.repos.d/CentOS-* >/dev/null 2>&1; then
			write_vault_repo '[baseos]
name=CentOS Stream 8 BaseOS (vault)
baseurl=https://vault.centos.org/8-stream/BaseOS/x86_64/os/
gpgcheck=0
enabled=1

[appstream]
name=CentOS Stream 8 AppStream (vault)
baseurl=https://vault.centos.org/8-stream/AppStream/x86_64/os/
gpgcheck=0
enabled=1'
		fi
		$PM install -y epel-release \
			|| $PM install -y "https://dl.fedoraproject.org/pub/epel/epel-release-latest-8.noarch.rpm"
		install_remi_release 8
		enable_remi_php
		;;

	rocky-9|rhel-9|almalinux-9|centos-9|centos-stream-9)
		$PM install -y epel-release \
			|| $PM install -y "https://dl.fedoraproject.org/pub/epel/epel-release-latest-9.noarch.rpm"
		install_remi_release 9
		enable_remi_php
		;;

	rocky-10|rhel-10|almalinux-10|centos-10|centos-stream-10)
		$PM install -y epel-release \
			|| $PM install -y "https://dl.fedoraproject.org/pub/epel/epel-release-latest-10.noarch.rpm"
		install_remi_release 10
		enable_remi_php
		;;

	*)
		echo "unsupported distro: ${DISTRO_ID} ${VERSION_ID}" >&2
		exit 1
		;;
esac

# --- build tool-chain --------------------------------------------------
$PM install -y \
	rpm-build rsync make git tar gzip findutils which unzip \
	php-cli php-json php-mbstring php-xml php-phar \
	|| $PM install -y rpm-build rsync make git tar gzip findutils which unzip php-cli php-xml php-mbstring

# systemd RPM macros: a dedicated package on modern distros, part of systemd on EL7.
$PM install -y systemd-rpm-macros || $PM install -y systemd

# --- composer -------------------------------------------------------------
if ! command -v composer >/dev/null 2>&1; then
	php -r "copy('https://getcomposer.org/installer', '/tmp/composer-setup.php');"
	php /tmp/composer-setup.php --install-dir=/usr/local/bin --filename=composer
	rm -f /tmp/composer-setup.php
fi

php --version
composer --version

# --- build --------------------------------------------------------------
cd /work
git config --global --add safe.directory /work 2>/dev/null || true

make rpm

# --- hand artefacts back to the host user -----------------------------
if [ -n "${HOST_UID:-}" ]; then
	chown -R "${HOST_UID}:${HOST_GID:-${HOST_UID}}" "${OUTPUT_DIR:-/work/build}" 2>/dev/null || true
fi
