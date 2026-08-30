<?php

/*
 * The ext-ldap constants LdapClient.php / AuthPluginLdap.php reference.
 *
 * The ldap extension normally defines these; TypePHP builds link a libphp that
 * has no ldap extension, so this compile-only file (listed in project*.yml,
 * never autoloaded) supplies them. It is never loaded by a normal PHP run, so
 * it can never clash with the real ldap constants.
 *
 * Values match ext-ldap / the OpenLDAP headers.
 */

const LDAP_OPT_PROTOCOL_VERSION = 0x0011; // 17
const LDAP_OPT_NETWORK_TIMEOUT  = 0x5005; // 20485
const LDAP_OPT_TIMEOUT          = 0x5002; // 20482

const LDAP_ESCAPE_FILTER = 1;
const LDAP_ESCAPE_DN     = 2;
