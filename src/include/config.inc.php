<?php
safe_define('CONFIG_aun_listen_address','0.0.0.0');
safe_define('CONFIG_aun_listen_port',32768);

safe_define('CONFIG_websocket_listen_address','0.0.0.0');
safe_define('CONFIG_websocket_listen_port','8090');
safe_define('CONFIG_websocket_network_address','128');
safe_define('CONFIG_websocket_station_address','254');



safe_define('CONFIG_webadmin_listen_address','0.0.0.0');
safe_define('CONFIG_webadmin_listen_port','8080');


safe_define('CONFIG_econet_data_stream_port',0x97);
safe_define('CONFIG_bbc_default_pkg_sleep',40000);

safe_define('CONFIG_aunmap_file','aunmap.txt');
safe_define('CONFIG_websocketmap_file','websocket_map.cfg');
safe_define('CONFIG_aunmap_autonet',200);
safe_define('CONFIG_aun_default_port',32768);
safe_define('CONFIG_version','1.01');
safe_define('CONFIG_housekeeping_interval',300);

safe_define('CONFIG_security_auth_plugins','file');
safe_define('CONFIG_security_plugin_file_user_file','users-live.txt');
safe_define('CONFIG_security_plugin_file_default_crypt','md5');
safe_define('CONFIG_security_default_unix_uid',500);
safe_define('CONFIG_security_max_session_idle',2400);

safe_define('CONFIG_library_path','$.LIBRARY');

safe_define('CONFIG_vfs_plugins','DfsSsd,AdfsAdl,AdfsHD,LocalFile');
safe_define('CONFIG_vfs_plugin_localfile_root','/var/lib/aun-filestore-root');
safe_define('CONFIG_vfs_disc_name','VFSROOT');
safe_define('CONFIG_vfs_home_dir_path','$.home');
safe_define('CONFIG_vfs_default_disc_free',0x9000);
safe_define('CONFIG_vfs_default_disc_size',0x9000);

safe_define('CONFIG_vfs_plugin_localdfsssd_root','/var/lib/aun-filestore-root');
safe_define('CONFIG_vfs_plugin_localadfsadl_root','/var/lib/aun-filestore-root');
safe_define('CONFIG_vfs_plugin_localadfshd_root','/var/lib/aun-filestore-root');

safe_define('CONFIG_print_server_spool_dir','/tmp/econetprint');

safe_define('CONFIG_piconet_device','dev/econet');
safe_define('CONFIG_piconetmap_file','piconetmap.txt');
safe_define('CONFIG_piconet_station','1');

safe_define('CONFIG_nat_default_station',254);
safe_define('CONFIG_nat_default_network',254);
safe_define('CONFIG_ipv4_routes_file','routes.txt');
safe_define('CONFIG_ipv4_interfaces_file','interfaces.txt');
safe_define('CONFIG_ipv4_nat_file','nat.txt');
