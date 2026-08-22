<?php
/**
 * File containing the LdapClientContract interface
 *
 * @package coreauth
*/
namespace HomeLan\FileStore\Authentication\Plugins;

/**
 * The subset of an LDAP client's API that AuthPluginLdap calls.
 *
 * _getClient()/setLdapClient() are typed as LdapClientContract rather than a
 * concrete class: unit tests inject a lightweight StubLdapClient (implementing
 * this interface) instead of a real LDAP connection. Modeled directly on
 * \HomeLan\FileStore\Vfs\Plugin\S3ClientContract, which does the same thing
 * for the S3 VFS plugin.
 *
 * @package coreauth
 * @author John Brown <john@home-lan.co.uk>
*/
interface LdapClientContract {

	/**
	 * Connects and binds as the given service account. Returns false (rather than
	 * throwing) on any failure to connect/start TLS/bind, so callers can decide how
	 * to react (AuthPluginLdap treats a failed bind at init() as fatal for the plugin).
	*/
	public function bind(string $sUri, string $sBindDn, string $sBindPassword, bool $bStartTls, int $iTimeoutSeconds): bool;

	/**
	 * Searches the directory. Returns one array per matching entry:
	 * ['dn'=>string, '<attribute name, lowercase>'=>array<string> values, ...]
	 *
	 * @return array<int, array<string, mixed>>
	*/
	public function search(string $sBaseDn, string $sFilter): array;

	/**
	 * @param array<string, string|array<string>> $aAttributes
	*/
	public function add(string $sDn, array $aAttributes): bool;

	/**
	 * @param array<string, string|array<string>> $aAttributes
	*/
	public function modifyReplace(string $sDn, array $aAttributes): bool;

	/**
	 * @param array<string, string|array<string>> $aAttributes
	*/
	public function modifyAdd(string $sDn, array $aAttributes): bool;

	/**
	 * @param array<string, string|array<string>> $aAttributes
	*/
	public function modifyDelete(string $sDn, array $aAttributes): bool;

	public function delete(string $sDn): bool;
}
