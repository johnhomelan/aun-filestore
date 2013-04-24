<?
safe_define('CONFIG_aun_listen_address','0.0.0.0');
safe_define('CONFIG_aun_listen_port',32768);

safe_define('CONFIG_loglevel',LOG_DEBUG);
safe_define('CONFIG_logstderr',TRUE);
safe_define('CONFIG_logfile','/tmp/filestore.log');
safe_define('CONFIG_aunmap_file','aunmap.txt');
safe_define('CONFIG_aun_default_port',32768);

safe_define('CONFIG_security_auth_plugins','file');
safe_define('CONFIG_security_plugin_file_user_file','users.txt');
safe_define('CONFIG_security_plugin_file_default_crypt','md5');

safe_define('CONFIG_library_path','$.LIBRARY');
safe_define('CONFIG_vfs_plugins','localfile');
safe_define('CONFIG_vfs_plugin_localfile_root','/tmp/econetroot/');
safe_define('CONFIG_vfs_disc_name','VFSROOT');
?>
