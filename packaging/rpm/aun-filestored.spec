# Minimum PHP version. Overridable with --define "_php_min X.Y" (build-rpm.sh
# passes RPM_PHP_MIN through) for distributions where the newest available PHP
# is older - e.g. an EL7 build against a remi PHP that is not quite 8.4.
%{!?_php_min: %global _php_min 8.4}

# A couple of vendored dependencies ship CI helper scripts (a python formatter,
# some dev shell scripts). Don't let them leak into the package's dependencies.
%global __requires_exclude ^(/usr/bin/python3|/usr/bin/env)$

Name: %{_name}
Version: %{_version}
Release: %{_release}
BuildArch: %{_arch}
Group: Network Servers
License: GPL
Summary: A file server that implelements the NetFS and NetPrint protocols for BBC's and RiscOS, ontop of a Layer 2 emulation for Econet known as AUN.
Source: %{name}-%{version}-%{release}.tar.gz
BuildRoot: /tmp/%{name}-%{version}-%{release}-root

BuildRequires: systemd-rpm-macros
%{?systemd_requires}

# Runtime dependencies. The server is PHP and needs a fairly wide set of
# extensions (see src/composer.json). Package names below are the Fedora / RHEL
# / remi names.
Requires: php-cli >= %{_php_min}
Requires: php-common
Requires: php-soap
Requires: php-process
Requires: php-pdo
Requires: php-gd
Requires: php-ldap
Requires: php-mbstring
Requires: php-xml
Requires: php-bcmath
Requires: php-sockets
Requires: php-pecl-zip
Requires: php-dba


%description
aun-filestore is a Econet Fileserver implementation (using AUN) for Linux and other platforms. It's written in PHP, provides file and print services to Acorn Risc Machines, and Emulated BBCs.

The project is now at the alpha stage, and works well for BBC clients. It handles all file operations however print operations are not implemented.

A basic text file auth backend is done working, however all filestore users access the unix fs as the user the filestore runs as.

The filestore provides access to file via a VFS layer with plugins have been created for local unix fs, and ssd disk image.

%prep
# The Composer dependencies are already vendored into the source tarball by
# packaging/rpm/build-rpm.sh, so there is nothing to fetch here.
%setup -q -c

%install
rm -rf %{buildroot}

install -d %{buildroot}%{_libexecdir}/aun-filestore
install -d %{buildroot}%{_datadir}/aun-filestored
install -d %{buildroot}%{_unitdir}
install -d %{buildroot}%{_sysconfdir}/aun-filestored
install -d %{buildroot}%{_sharedstatedir}/aun-filestore-root
install -d %{buildroot}%{_localstatedir}/spool/aun-filestore-print

# Application code + Composer dependencies
cp -r src/include %{buildroot}%{_datadir}/aun-filestored/
cp -r src/vendor  %{buildroot}%{_datadir}/aun-filestored/

# Daemons
for f in filestored sharefsd dnsd ntpd ecosyslogd sql-serverd; do
	install -m 0755 src/$f %{buildroot}%{_libexecdir}/aun-filestore/$f
done

# Helper / import scripts
for f in src/util/*; do
	install -m 0755 "$f" %{buildroot}%{_libexecdir}/aun-filestore/
done

# The Symfony console entry point resolves ./vendor and ./include relative to
# itself, so it has to live alongside them under %{_datadir}.
install -m 0755 src/symfony-console %{buildroot}%{_datadir}/aun-filestored/symfony-console

# systemd units (replace the old SysV init script)
install -m 0644 systemd/*.service %{buildroot}%{_unitdir}/

# Config templates
cp -r etc/aun-filestored/* %{buildroot}%{_sysconfdir}/aun-filestored/

%post
%systemd_post aun-filestored.service aun-sharefsd.service aun-dnsd.service aun-ntpd.service aun-ecosyslogd.service aun-sql-serverd.service
if [ "$1" = 1 ]; then
	mkdir -p %{_sharedstatedir}/aun-filestore-root/LIBRARY
	mkdir -p %{_sharedstatedir}/aun-filestore-root/HOME/SYST
fi

%preun
%systemd_preun aun-filestored.service aun-sharefsd.service aun-dnsd.service aun-ntpd.service aun-ecosyslogd.service aun-sql-serverd.service

%postun
%systemd_postun_with_restart aun-filestored.service aun-sharefsd.service aun-dnsd.service aun-ntpd.service aun-ecosyslogd.service aun-sql-serverd.service

%files
%defattr(-,root,root,-)
%{_datadir}/aun-filestored
%{_libexecdir}/aun-filestore
%{_unitdir}/aun-filestored.service
%{_unitdir}/aun-sharefsd.service
%{_unitdir}/aun-dnsd.service
%{_unitdir}/aun-ntpd.service
%{_unitdir}/aun-ecosyslogd.service
%{_unitdir}/aun-sql-serverd.service
%dir %attr(0755,root,root) %{_sharedstatedir}/aun-filestore-root
%dir %attr(0755,root,root) %{_localstatedir}/spool/aun-filestore-print
%config(noreplace) %{_sysconfdir}/aun-filestored
