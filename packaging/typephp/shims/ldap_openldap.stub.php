<?php

/*
 * Ahead-of-time declarations for the ext-ldap functions LdapClient.php uses,
 * implemented in ldap_openldap.cc against the OpenLDAP client library.
 *
 * TypePHP's .stub.php files only declare the Zend ABI symbols provided by the
 * accompanying C++ (php_<name>); the bodies are intentionally empty and every
 * parameter and the return value must carry an explicit ABI type. This file is
 * compile only (referenced from project*.yml, never autoloaded), so it does not
 * conflict with the real ldap extension under a normal PHP run.
 *
 * `mixed` is used where ext-ldap hands back an object-or-false: ldap_connect()
 * and ldap_search() return an \LDAP\Connection / \LDAP\Result (see
 * ldap_classes.php) or false; ldap_get_entries() returns an array or false.
 * The $ldap / $result parameters are the objects those functions returned.
 */

function ldap_connect(string $uri): mixed {}

function ldap_set_option(mixed $ldap, int $option, mixed $value): bool {}

function ldap_start_tls(mixed $ldap): bool {}

function ldap_bind(mixed $ldap, mixed $dn, mixed $password): bool {}

function ldap_unbind(mixed $ldap): bool {}

function ldap_search(mixed $ldap, string $base_dn, string $filter): mixed {}

function ldap_get_entries(mixed $ldap, mixed $result): mixed {}

function ldap_add(mixed $ldap, string $dn, array $entry): bool {}

function ldap_mod_replace(mixed $ldap, string $dn, array $entry): bool {}

function ldap_mod_add(mixed $ldap, string $dn, array $entry): bool {}

function ldap_mod_del(mixed $ldap, string $dn, array $entry): bool {}

function ldap_delete(mixed $ldap, string $dn): bool {}

function ldap_escape(string $value, string $ignore = "", int $flags = 0): string {}
