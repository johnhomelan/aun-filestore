<?php
safe_define('CONFIG_aun_listen_address','0.0.0.0');
safe_define('CONFIG_aun_listen_port',32768);

safe_define('CONFIG_websocket_listen_address','0.0.0.0');
safe_define('CONFIG_websocket_listen_port','8090');
safe_define('CONFIG_websocket_network_address','128');
safe_define('CONFIG_websocket_station_address','254');
safe_define('CONFIG_websocketmap_dynamic_network_range_file','websocketmap_dynamic_network_range.txt');



safe_define('CONFIG_webadmin_listen_address','0.0.0.0');
safe_define('CONFIG_webadmin_listen_port','8080');


safe_define('CONFIG_econet_data_stream_port',0x97);
safe_define('CONFIG_bbc_default_pkg_sleep',40000);

safe_define('CONFIG_aunmap_file','aunmap.txt');
safe_define('CONFIG_websocketmap_file','websocket_map.cfg');
safe_define('CONFIG_aunmap_autonet',200);
safe_define('CONFIG_aun_default_port',32768);
safe_define('CONFIG_version','1.01');
safe_define('CONFIG_version_major', 1);
safe_define('CONFIG_version_minor', 1);
safe_define('CONFIG_housekeeping_interval',300);

safe_define('CONFIG_security_auth_plugins','file');
safe_define('CONFIG_security_plugin_file_user_file','users-live.txt');
safe_define('CONFIG_security_plugin_file_default_crypt','bcrypt');
safe_define('CONFIG_security_plugin_l3password_file','Passwords');
safe_define('CONFIG_security_plugin_l3password_homedir_prefix','');
safe_define('CONFIG_security_plugin_mdfspassword_file','');
safe_define('CONFIG_security_plugin_mdfspassword_homedir_prefix','');
safe_define('CONFIG_security_plugin_mdfspassword_accounts_file','');
safe_define('CONFIG_security_plugin_ldap_uri','');
safe_define('CONFIG_security_plugin_ldap_start_tls',FALSE);
safe_define('CONFIG_security_plugin_ldap_bind_dn','');
safe_define('CONFIG_security_plugin_ldap_bind_password','');
safe_define('CONFIG_security_plugin_ldap_base_dn','');
safe_define('CONFIG_security_plugin_ldap_user_filter','(&(objectClass=econetAccount)(uid=%s))');
safe_define('CONFIG_security_plugin_ldap_create_dn_template','');
safe_define('CONFIG_security_plugin_ldap_cache_ttl',300);
safe_define('CONFIG_security_plugin_ldap_negative_cache_ttl',30);
safe_define('CONFIG_security_plugin_ldap_default_crypt','bcrypt');
safe_define('CONFIG_security_plugin_ldap_network_timeout',5);
safe_define('CONFIG_security_default_unix_uid',500);
safe_define('CONFIG_security_max_session_idle',2400);

safe_define('CONFIG_library_path','$.LIBRARY');

safe_define('CONFIG_vfs_plugins','AFS,DfsSsd,AdfsAdl,AdfsHD,Mdfs,LocalFile');
safe_define('CONFIG_vfs_plugin_localfile_root','/var/lib/aun-filestore-root');
safe_define('CONFIG_vfs_disc_name','VFSROOT');
safe_define('CONFIG_vfs_home_dir_path','$.home');
safe_define('CONFIG_vfs_default_disc_free',0x9000);
safe_define('CONFIG_vfs_default_disc_size',0x9000);

safe_define('CONFIG_vfs_plugin_localdfsssd_root','/var/lib/aun-filestore-root');
safe_define('CONFIG_vfs_plugin_localadfsadl_root','/var/lib/aun-filestore-root');
safe_define('CONFIG_vfs_plugin_localadfshd_root','/var/lib/aun-filestore-root');
safe_define('CONFIG_vfs_plugin_afs_root','/var/lib/aun-filestore-root');
safe_define('CONFIG_vfs_plugin_mdfs_root','/var/lib/aun-filestore-root');
safe_define('CONFIG_vfs_plugin_mdfs_write_enabled', false);

safe_define('CONFIG_print_server_spool_dir','/tmp/econetprint');
safe_define('CONFIG_print_server_conversion_script','/usr/bin/esc2ps -i %source% -o %destination%');
safe_define('CONFIG_print_server_printers_file','printers.cfg');
safe_define('CONFIG_piconet_device','/dev/econet');
safe_define('CONFIG_piconetmap_file','piconetmap.txt');
safe_define('CONFIG_piconet_station','254');
safe_define('CONFIG_piconet_local_network',1);

safe_define('CONFIG_nat_default_station',254);
safe_define('CONFIG_nat_default_network',254);
safe_define('CONFIG_ipv4_routes_file','routes.txt');
safe_define('CONFIG_ipv4_interfaces_file','interfaces.txt');
safe_define('CONFIG_ipv4_nat_file','nat.txt');

safe_define('CONFIG_beeb_term_services_file','beebterm.txt');

safe_define('CONFIG_viewdata_host','glasstty.com');
safe_define('CONFIG_viewdata_port',6502); //6502/6503 = 8 data bits, no parity; 6504 = 7 data bits, even parity

safe_define('CONFIG_remote_bridge_enabled', false);
safe_define('CONFIG_remote_bridge_map_file', 'remotebridge.txt');
safe_define('CONFIG_remote_bridge_server_address', '0.0.0.0');

// Remote Socket Protocol relay server (see docs/protocols/remote-socket.md).
// remote_socket_relay_secret has no default; it must be set explicitly for the feature to work.
safe_define('CONFIG_remote_socket_relay_enabled', true);
safe_define('CONFIG_remote_socket_relay_listen_address', '127.0.0.1');
safe_define('CONFIG_remote_socket_relay_listen_port', '8091');
safe_define('CONFIG_remote_socket_relay_secret', '');

// Remote Provider Protocol relay server (see docs/protocols/remote-provider.md) - the same idea
// one layer up the stack: relays whole Econet packets on ports Services\Provider\ProxyProvider
// has reserved (see src/filestored) to a provider process hosted elsewhere, e.g. ecosyslogd.
// remote_provider_relay_secret has no default; it must be set explicitly for the feature to work.
safe_define('CONFIG_remote_provider_relay_enabled', true);
safe_define('CONFIG_remote_provider_relay_listen_address', '0.0.0.0');
safe_define('CONFIG_remote_provider_relay_listen_port', '8092');
safe_define('CONFIG_remote_provider_relay_secret', '');

safe_define('CONFIG_sharefs_share_list_file', 'sharelist.txt');
safe_define('CONFIG_sharefs_listen_address', '0.0.0.0');
safe_define('CONFIG_sharefs_freeway_broadcast_address', '255.255.255.255');
safe_define('CONFIG_sharefs_freeway_port', 32770);
safe_define('CONFIG_sharefs_accessplus_port', 32771);
safe_define('CONFIG_sharefs_sharefsdata_port', 49171);
safe_define('CONFIG_sharefs_host_name', '');
safe_define('CONFIG_sharefs_webadmin_listen_address', '0.0.0.0');
safe_define('CONFIG_sharefs_webadmin_listen_port', '8081');

// sharefsd has no per-client login (real Access+ has no user-account concept - see
// docs/protocols/sharefs.md); every ShareFS operation runs as this one fixed identity,
// logged in once at daemon startup.
safe_define('CONFIG_sharefs_service_username', '');
safe_define('CONFIG_sharefs_service_password', '');
safe_define('CONFIG_sharefs_service_network', 254);
safe_define('CONFIG_sharefs_service_station', 1);

// When enabled, sharefsd receives its Freeway/Access+/ShareFS UDP traffic over a Remote Socket
// Protocol connection to a filestored instance instead of binding its own UDP sockets (see
// docs/protocols/remote-socket.md). remote_socket_relay_secret must match filestored's
// remote_socket_relay_secret.
safe_define('CONFIG_sharefs_remote_socket_relay_enabled', false);
safe_define('CONFIG_sharefs_remote_socket_relay_address', '127.0.0.1:8091');
safe_define('CONFIG_sharefs_remote_socket_relay_secret', '');

// dnsd answers DNS queries from a Unix-style hosts file (see docs/protocols/dns.md). It always
// receives its UDP traffic over a Remote Socket Protocol connection to a filestored instance
// (see docs/protocols/remote-socket.md) rather than binding a real UDP socket.
// dns_remote_socket_relay_secret must match filestored's remote_socket_relay_secret.
safe_define('CONFIG_dns_hosts_file', 'hosts.txt');
safe_define('CONFIG_dns_port', 53);
safe_define('CONFIG_dns_remote_socket_relay_address', '127.0.0.1:8091');
safe_define('CONFIG_dns_remote_socket_relay_secret', '');

// Forwards a query the hosts file can't answer to an external DNS server, asynchronously (see
// docs/protocols/dns.md). dns_forwarder_allowed_domains, when non-empty, restricts forwarding
// to names within those domains (comma-separated, forward and/or in-addr.arpa/ip6.arpa
// entries mixed freely) - leave it empty to forward anything not found locally.
safe_define('CONFIG_dns_forwarder_enabled', false);
safe_define('CONFIG_dns_forwarder_address', '');
safe_define('CONFIG_dns_forwarder_timeout', 2);
safe_define('CONFIG_dns_forwarder_allowed_domains', '');

// ntpd answers NTP client requests from the host system clock (see docs/protocols/ntp.md). It
// always receives its UDP traffic over a Remote Socket Protocol connection to a filestored
// instance (see docs/protocols/remote-socket.md) rather than binding a real UDP socket.
// ntp_remote_socket_relay_secret must match filestored's remote_socket_relay_secret.
safe_define('CONFIG_ntp_port', 123);
safe_define('CONFIG_ntp_stratum', 1);
safe_define('CONFIG_ntp_reference_id', 'LOCL');
safe_define('CONFIG_ntp_remote_socket_relay_address', '127.0.0.1:8091');
safe_define('CONFIG_ntp_remote_socket_relay_secret', '');

safe_define('CONFIG_macemail_store_dir', '/var/lib/aun-filestore-macemail');
safe_define('CONFIG_macemail_usergroup', 'MAIL');
safe_define('CONFIG_macemail_max_slots', 32);

// ecosyslogd is a sample Remote Provider Protocol host (see docs/protocols/ecosyslog.md): it
// hosts Services\Provider\EcoSyslog, which turns Econet packets addressed to port 0xB6 into log
// entries. It always connects to a filestored instance over the relay (see
// docs/protocols/remote-provider.md) - ecosyslog_remote_provider_relay_secret must match
// filestored's remote_provider_relay_secret, and filestored's ProxyProvider must have 0xB6 in
// its reserved port list.
safe_define('CONFIG_ecosyslog_remote_provider_relay_address', '127.0.0.1:8092');
safe_define('CONFIG_ecosyslog_remote_provider_relay_secret', '');

// Where received log messages are stored. Both may be enabled at once. Local storage goes
// through the host OS's own syslog(3); remote storage speaks RFC 5424/3164 syslog over UDP to an
// external log collector, via Monolog\Handler\SyslogUdpHandler - no bespoke forwarding code
// needed.
safe_define('CONFIG_ecosyslog_local_enabled', true);
safe_define('CONFIG_ecosyslog_remote_enabled', false);
safe_define('CONFIG_ecosyslog_remote_host', '');
safe_define('CONFIG_ecosyslog_remote_port', 514);
// Must name a PHP LOG_* syslog facility constant, e.g. LOG_LOCAL0 .. LOG_LOCAL7, LOG_USER, LOG_DAEMON.
safe_define('CONFIG_ecosyslog_remote_facility', 'LOG_LOCAL0');

// sql-serverd is a Remote Provider Protocol host (see docs/protocols/sql-server.md): it hosts
// Services\Provider\SqlServer, which lets Econet clients authenticate, run parameterised SQL
// queries against a configured PostgreSQL/MySQL/SQLite database, and stream paged result sets
// back. It always connects to a filestored instance over the relay (see
// docs/protocols/remote-provider.md) - sql_serverd_remote_provider_relay_secret must match
// filestored's remote_provider_relay_secret, and filestored's ProxyProvider must have
// sql_server_port in its reserved port list.
safe_define('CONFIG_sql_server_port', 0xB7);
safe_define('CONFIG_sql_serverd_remote_provider_relay_address', '127.0.0.1:8092');
safe_define('CONFIG_sql_serverd_remote_provider_relay_secret', '');

// Comma separated list of configured database names, e.g. "accounts,inventory". Each name then
// needs its own set of sql_database_{name}_* keys (no defaults, since the name is arbitrary):
//   sql_database_{name}_engine        = pgsql|mysql|sqlite
//   sql_database_{name}_dsn           = PDO DSN, e.g. pgsql:host=localhost;dbname=accounts
//   sql_database_{name}_user
//   sql_database_{name}_password
//   sql_database_{name}_allowed_users = comma list of Econet usernames, empty = any authenticated user
safe_define('CONFIG_sql_databases', '');
safe_define('CONFIG_sql_max_rows_per_query', 1000000);
safe_define('CONFIG_sql_query_timeout', 30);
safe_define('CONFIG_sql_max_connections_per_database', 20);

safe_define('CONFIG_teletext_store_dir', '/var/lib/aun-filestore-teletext');
safe_define('CONFIG_teletext_server_name', '');
safe_define('CONFIG_teletext_max_users', 99);
safe_define('CONFIG_teletext_carousel_interval', 4);
safe_define('CONFIG_teletext_teefax_channel', '6');
safe_define('CONFIG_teletext_teefax_source', 'https://github.com/opless/teefax-mirror/archive/refs/heads/master.tar.gz');
safe_define('CONFIG_teletext_teefax_refresh_interval', 86400);
safe_define('CONFIG_teletext_news_bbc_channel', '2');
safe_define('CONFIG_teletext_news_bbc_source', 'https://feeds.bbci.co.uk/news/rss.xml');
safe_define('CONFIG_teletext_news_bbc_refresh_interval', 1800);
safe_define('CONFIG_teletext_news_bbc_max_stories', 40);
safe_define('CONFIG_teletext_news_guardian_channel', '3');
safe_define('CONFIG_teletext_news_guardian_source', 'https://www.theguardian.com/uk/rss');
safe_define('CONFIG_teletext_news_guardian_refresh_interval', 1800);
safe_define('CONFIG_teletext_news_guardian_max_stories', 40);
safe_define('CONFIG_teletext_news_sky_channel', '5');
safe_define('CONFIG_teletext_news_sky_source', 'https://feeds.skynews.com/feeds/rss/home.xml');
safe_define('CONFIG_teletext_news_sky_refresh_interval', 1800);
safe_define('CONFIG_teletext_news_sky_max_stories', 40);
safe_define('CONFIG_teletext_weather_channel', '2');
safe_define('CONFIG_teletext_weather_refresh_interval', 3600);
safe_define('CONFIG_teletext_weather_index_page', 600);
safe_define('CONFIG_teletext_tvguide_channel', '2');
safe_define('CONFIG_teletext_tvguide_refresh_interval', 3600);
safe_define('CONFIG_teletext_tvguide_index_page', 700);
safe_define('CONFIG_teletext_webfax_webfax1_channel', '7');
safe_define('CONFIG_teletext_webfax_webfax1_source', 'https://github.com/Webfax-Teletext/Webfax-Teletext/archive/refs/heads/main.tar.gz');
safe_define('CONFIG_teletext_webfax_webfax1_refresh_interval', 86400);
safe_define('CONFIG_teletext_webfax_webfax2_channel', '8');
safe_define('CONFIG_teletext_webfax_webfax2_source', 'https://github.com/Webfax-Teletext/Webfax2-Teletext/archive/refs/heads/main.tar.gz');
safe_define('CONFIG_teletext_webfax_webfax2_refresh_interval', 86400);
safe_define('CONFIG_teletext_tvguide_source','http://192.168.0.42:9981/');
safe_define('CONFIG_teletext_tvguide_username','teletext');
safe_define('CONFIG_teletext_tvguide_password','ceefax');
