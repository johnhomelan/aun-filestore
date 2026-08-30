PHPUNIT  := ./src/vendor/bin/phpunit
PHPSTAN  := ./src/vendor/bin/phpstan
PHPCS    := ./src/vendor/bin/phpcs
PHPCBF   := ./src/vendor/bin/phpcbf
COMPOSER := composer
PODMAN   ?= podman

# Version for the Synology package (X.Y.Z-N). Leave empty to derive it from
# src/include/config.inc.php.
SPK_VERSION ?=

# Version string for the phar tarball name. Leave empty to derive it from
# src/include/config.inc.php.
PHAR_VERSION ?=

# RPM version / release. Leave RPM_VERSION empty to derive it from
# src/include/config.inc.php; RPM_RELEASE defaults to 1 in the build script.
RPM_VERSION ?=
RPM_RELEASE ?=

# Debian package version. Leave empty to derive it from src/include/config.inc.php.
DEB_VERSION ?=

# opkg (.ipk) package version. Leave empty to derive it from
# src/include/config.inc.php.
IPK_VERSION ?=

# Where packaging targets drop their artefacts. Overridable from the environment
# (the RPM container build sets a per-distro subdirectory).
OUTPUT_DIR ?= $(CURDIR)/build

.PHONY: all test test-coverage phpstan lint lint-fix deps synopackage spk phar rpm deb ipk typephp sharefs-typephp dns-typephp ntp-typephp ecosyslog-typephp sql-typephp teletext-typephp native-daemons clean

all: test phpstan lint

deps:
	cd src && $(COMPOSER) --no-scripts install

test: deps
	cp test-config/* .
	mkdir -p coverage
	$(PHPUNIT) --log-junit junit.xml --colors=never

test-coverage: deps
	cp test-config/* .
	mkdir -p coverage
	XDEBUG_MODE=coverage $(PHPUNIT) --coverage-html coverage/ --log-junit junit.xml --colors=never

phpstan: deps
	$(PHPSTAN) analyse -n --no-ansi --no-progress src/include src/filestored src/sharefsd src/dnsd src/ntpd src/ecosyslogd --level 10 --memory-limit 512M

# Coding-standard check (PSR-12 derived: tabs, "if(" style) - see phpcs.xml.dist.
lint: deps
	$(PHPCS)

# Auto-fix what the coding standard can fix.
lint-fix: deps
	$(PHPCBF)

# Build a Synology package (.spk) into build/. The package installs the PHP
# app under /var/packages/aun-filestored, runs filestored as a DSM service and
# adds a main-menu icon that opens the admin web front end.
synopackage:
	SPK_VERSION="$(SPK_VERSION)" OUTPUT_DIR="$(OUTPUT_DIR)" packaging/syno/build-spk.sh

spk: synopackage

# Build the standalone filestore.phar and its distribution tarball into build/.
# The build stages src/, installs Composer deps (--no-dev) and pre-warms the
# Symfony admin cache so the phar runs without writing to itself.
phar:
	PHAR_VERSION="$(PHAR_VERSION)" OUTPUT_DIR="$(OUTPUT_DIR)" packaging/phar/build-phar.sh

# Build the RPM into build/. Composer deps are vendored into the source tarball
# by the build script, so rpmbuild needs no network. Needs the rpm-build package.
rpm:
	RPM_VERSION="$(RPM_VERSION)" RPM_RELEASE="$(RPM_RELEASE)" OUTPUT_DIR="$(OUTPUT_DIR)" packaging/rpm/build-rpm.sh

# Build the .deb into build/. Composer deps are vendored into the source tree by
# the build script, so dpkg-buildpackage needs no network. Needs dpkg-dev +
# debhelper.
deb:
	DEB_VERSION="$(DEB_VERSION)" OUTPUT_DIR="$(OUTPUT_DIR)" packaging/deb/build-deb.sh

# Run the TypePHP (swoole/typephp) AOT compiler against the project inside a
# podman container. Defaults to a --dry run (C++ generation only); pass
# TYPEPHP_DRY=0 to compile + link the native daemon (build/typephp/aun_filestored).
# Builds main.php (a de-dynamised port of Command\React::MainLoop) + the domain
# code + the vendored ReactPHP loop/sockets + the Ratchet WebSocket stack. The
# Symfony admin UI is not compiled; the Piconet serial interface is (untested -
# needs an Econet serial device).
# See packaging/typephp/README.md and packaging/typephp/PORTING-REACT.md.
typephp:
	PODMAN="$(PODMAN)" OUTPUT_DIR="$(OUTPUT_DIR)" packaging/typephp/build-typephp.sh

# Same as `typephp`, but builds the sharefsd daemon (ShareFS / Level-4 /
# AccessPlus / Freeway) into build/typephp/sharefsd. Reuses the whole ReactPHP /
# Ratchet vendor set + vendor-patches. See packaging/typephp/PORTING-REACT.md.
sharefs-typephp:
	PODMAN="$(PODMAN)" OUTPUT_DIR="$(OUTPUT_DIR)" \
		TYPEPHP_PROJECT=packaging/typephp/project.sharefsd.yml TYPEPHP_OUT=sharefsd \
		packaging/typephp/build-typephp.sh

# Native dnsd / ntpd / ecosyslogd (build/typephp/{dnsd,ntpd,ecosyslogd}).
# All reuse the same ReactPHP / Ratchet vendor set + vendor-patches. dnsd and
# ntpd receive UDP relayed over the Remote Socket Protocol; ecosyslogd hosts the
# EcoSyslog provider over the Remote Provider Protocol and writes to syslog.
dns-typephp:
	PODMAN="$(PODMAN)" OUTPUT_DIR="$(OUTPUT_DIR)" \
		TYPEPHP_PROJECT=packaging/typephp/project.dnsd.yml TYPEPHP_OUT=dnsd \
		packaging/typephp/build-typephp.sh

ntp-typephp:
	PODMAN="$(PODMAN)" OUTPUT_DIR="$(OUTPUT_DIR)" \
		TYPEPHP_PROJECT=packaging/typephp/project.ntpd.yml TYPEPHP_OUT=ntpd \
		packaging/typephp/build-typephp.sh

ecosyslog-typephp:
	PODMAN="$(PODMAN)" OUTPUT_DIR="$(OUTPUT_DIR)" \
		TYPEPHP_PROJECT=packaging/typephp/project.ecosyslogd.yml TYPEPHP_OUT=ecosyslogd \
		packaging/typephp/build-typephp.sh

# Native sql-serverd (build/typephp/sql_serverd) - hosts Services\Provider\SqlServer
# over the Remote Provider Protocol, like ecosyslogd, but runs the real
# Command\SqlServerd class directly. SQLite / PostgreSQL / MySQL - the
# Containerfile builds the libphp with all three PDO drivers.
sql-typephp:
	PODMAN="$(PODMAN)" OUTPUT_DIR="$(OUTPUT_DIR)" \
		TYPEPHP_PROJECT=packaging/typephp/project.sql-serverd.yml TYPEPHP_OUT=sql-serverd \
		packaging/typephp/build-typephp.sh

# Build the five teletext fetch scripts (news / teefax / tvguide / weather /
# webfax import) as ONE native binary, build/typephp/teletext_import,
# dispatching on argv[1]. Plain CLI - no ReactPHP - so this shares only the
# stage/config_defines step with the daemons, not the ReactPHP vendor set.
# Replaces the five Symfony Console wrappers under src/util/*-import.
teletext-typephp:
	PODMAN="$(PODMAN)" OUTPUT_DIR="$(OUTPUT_DIR)" \
		TYPEPHP_PROJECT=packaging/typephp/project.teletext.yml TYPEPHP_OUT=teletext-import \
		packaging/typephp/build-typephp.sh

# Build every native daemon.
native-daemons: typephp sharefs-typephp dns-typephp ntp-typephp ecosyslog-typephp sql-typephp

# Build the .ipk (opkg package) for OpenWrt / Alpine into build/. Needs
# opkg-utils (opkg-build), composer and rsync.
ipk:
	IPK_VERSION="$(IPK_VERSION)" OUTPUT_DIR="$(OUTPUT_DIR)" packaging/ipk/build-ipk.sh

clean:
	rm -f junit.xml
	rm -rf coverage
	rm -f $(OUTPUT_DIR)/*.spk
	rm -f $(OUTPUT_DIR)/filestore.phar $(OUTPUT_DIR)/*-phar.tgz
	rm -f $(OUTPUT_DIR)/*.rpm
	rm -f $(OUTPUT_DIR)/*.deb
	rm -f $(OUTPUT_DIR)/*.ipk
	rm -rf $(OUTPUT_DIR)/fedora-* $(OUTPUT_DIR)/centos-* $(OUTPUT_DIR)/rocky-* $(OUTPUT_DIR)/debian-* $(OUTPUT_DIR)/ubuntu-* $(OUTPUT_DIR)/alpine-* $(OUTPUT_DIR)/openwrt-*
	rm -rf $(OUTPUT_DIR)/typephp
