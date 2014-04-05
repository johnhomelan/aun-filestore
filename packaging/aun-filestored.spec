Name: %{_name}
Version: %{_version}
Release: %{_release}
BuildArch: %{_arch}
Group: Network Servers
License: GPL
Summary: A file server that implelements the NetFS and NetPrint protocols for BBC's and RiscOS, ontop of a Layer 2 emulation for Econet known as AUN.
Source: %{name}-%{version}-%{release}.tar.gz
BuildRoot: /tmp/%{name}-%{version}-%{release}-root
Requires: php php-soap php-process


%description
aun-filestore is a Econet Fileserver implementation (using AUN) for Linux and other platforms. It's written in PHP, provides file and print services to Acorn Risc Machines, and Emulated BBCs.

The project is now at the alpha stage, and works well for BBC clients. It handles all file operations however print operations are not implemented.

A basic text file auth backend is done working, however all filestore users access the unix fs as the user the filestore runs as.

The filestore provides access to file via a VFS layer with plugins have been created for local unix fs, and ssd disk image.

%prep
%setup -c

%post
/sbin/chkconfig --add aun-filestored

if [ $1 == 1 ]; then
	mkdir /var/lib/aun-filestore-root/LIBRARY
	mkdir /var/lib/aun-filestore-root/HOME
	mkdir /var/lib/aun-filestore-root/HOME/SYST
fi

%postun
if [ $1 == 0 ]; then
        /sbin/chkconfig --del aun-filestored
fi


%install
install -d $RPM_BUILD_ROOT/var/lib/aun-filestore-root
install -d $RPM_BUILD_ROOT/var/spool/aun-filestore-print
install -d $RPM_BUILD_ROOT/etc/aun-filestored
install -d $RPM_BUILD_ROOT/etc/rc.d/init.d
install -d $RPM_BUILD_ROOT/usr/sbin/
install -d $RPM_BUILD_ROOT/usr/share/aun-filestored
cp -r src/include $RPM_BUILD_ROOT/usr/share/aun-filestored/
cp -r etc/aun-filestored/* $RPM_BUILD_ROOT/etc/aun-filestored
cp etc/init.d/aun-filestored $RPM_BUILD_ROOT/etc/rc.d/init.d/
install src/filestored $RPM_BUILD_ROOT/usr/sbin/

%files
%defattr(644,root,root)
%attr(755,root,root)/usr/sbin/filestored
/usr/share/aun-filestored
%attr(755,root,root)/etc/rc.d/init.d/aun-filestored
%attr(755,root,root)/var/lib/aun-filestore-root
%attr(755,root,root)/var/spool/aun-filestore-print
%config(noreplace) /etc/aun-filestored
