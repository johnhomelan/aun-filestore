<?php
/**
 * File containing the LdapClient class
 *
 * @package coreauth
*/
namespace HomeLan\FileStore\Authentication\Plugins;

/**
 * Real LdapClientContract implementation, wrapping the ext-ldap functions.
 *
 * This is the only file in the auth system that calls ldap_* functions
 * directly; everything else goes through the LdapClientContract interface,
 * which is what makes AuthPluginLdap testable without a real LDAP server.
 *
 * @package coreauth
 * @author John Brown <john@home-lan.co.uk>
*/
class LdapClient implements LdapClientContract {

	protected ?\LDAP\Connection $oConnection = NULL;

	public function bind(string $sUri, string $sBindDn, string $sBindPassword, bool $bStartTls, int $iTimeoutSeconds): bool
	{
		$oConnection = ldap_connect($sUri);
		if($oConnection===FALSE){
			return FALSE;
		}
		ldap_set_option($oConnection, LDAP_OPT_PROTOCOL_VERSION, 3);
		ldap_set_option($oConnection, LDAP_OPT_NETWORK_TIMEOUT, $iTimeoutSeconds);

		if($bStartTls && !@ldap_start_tls($oConnection)){
			return FALSE;
		}

		if(!@ldap_bind($oConnection, $sBindDn, $sBindPassword)){
			return FALSE;
		}

		$this->oConnection = $oConnection;
		return TRUE;
	}

	public function search(string $sBaseDn, string $sFilter): array
	{
		if($this->oConnection===NULL){
			return [];
		}
		$oResult = @ldap_search($this->oConnection, $sBaseDn, $sFilter);
		if(!($oResult instanceof \LDAP\Result)){
			return [];
		}
		$aEntries = ldap_get_entries($this->oConnection, $oResult);
		if($aEntries===FALSE){
			return [];
		}

		$aReturn = [];
		$iCount = is_int($aEntries['count'] ?? NULL) ? $aEntries['count'] : 0;
		for($i=0; $i<$iCount; $i++){
			$mRawEntry = $aEntries[$i];
			if(is_array($mRawEntry)){
				$aReturn[] = static::_normalizeEntry($mRawEntry);
			}
		}
		return $aReturn;
	}

	/**
	 * Converts ldap_get_entries()'s verbose per-entry shape (numeric 'count' keys
	 * mixed in with both upper and lower case attribute names) into a clean
	 * ['dn'=>string, '<attr>'=>string[]] shape.
	 *
	 * @param array<int|string, mixed> $aRawEntry
	 * @return array<string, mixed>
	*/
	protected static function _normalizeEntry(array $aRawEntry): array
	{
		$aEntry = ['dn'=>is_string($aRawEntry['dn'] ?? NULL) ? $aRawEntry['dn'] : ''];
		foreach($aRawEntry as $mKey=>$mValue){
			if(!is_string($mKey) || !is_array($mValue)){
				continue;
			}
			$iValueCount = is_int($mValue['count'] ?? NULL) ? $mValue['count'] : 0;
			$aValues = [];
			for($i=0; $i<$iValueCount; $i++){
				$mScalarValue = $mValue[$i] ?? NULL;
				if(is_scalar($mScalarValue)){
					$aValues[] = (string) $mScalarValue;
				}
			}
			$aEntry[$mKey] = $aValues;
		}
		return $aEntry;
	}

	public function add(string $sDn, array $aAttributes): bool
	{
		if($this->oConnection===NULL){
			return FALSE;
		}
		return @ldap_add($this->oConnection, $sDn, $aAttributes);
	}

	public function modifyReplace(string $sDn, array $aAttributes): bool
	{
		if($this->oConnection===NULL){
			return FALSE;
		}
		return @ldap_mod_replace($this->oConnection, $sDn, $aAttributes);
	}

	public function modifyAdd(string $sDn, array $aAttributes): bool
	{
		if($this->oConnection===NULL){
			return FALSE;
		}
		return @ldap_mod_add($this->oConnection, $sDn, $aAttributes);
	}

	public function modifyDelete(string $sDn, array $aAttributes): bool
	{
		if($this->oConnection===NULL){
			return FALSE;
		}
		return @ldap_mod_del($this->oConnection, $sDn, $aAttributes);
	}

	public function delete(string $sDn): bool
	{
		if($this->oConnection===NULL){
			return FALSE;
		}
		return @ldap_delete($this->oConnection, $sDn);
	}
}
