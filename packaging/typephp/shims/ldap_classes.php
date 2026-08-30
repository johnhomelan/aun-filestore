<?php

/*
 * Compile-only stand-ins for ext-ldap's opaque \LDAP\Connection and
 * \LDAP\Result objects, for TypePHP builds.
 *
 * ldap_connect() / ldap_search() in ldap_openldap.cc return one of these
 * carrying just an integer `id` into the shim's process-global handle tables;
 * the other ldap_* shims read that id back. This keeps LdapClient.php's
 * `?\LDAP\Connection` property type and `instanceof \LDAP\Result` check
 * compiling and working with no source change.
 *
 * Listed only in project*.yml, never autoloaded, so under a normal (interpreted)
 * run the real ext-ldap classes are used and this file is never in scope.
 */

namespace LDAP;

final class Connection
{
    public int $id = 0;
}

final class Result
{
    public int $id = 0;
}
