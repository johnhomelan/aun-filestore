<?
/**
 * This file contains the interface for authplugins 
 *
 * @package coreauth
*/

/**
 * The authplugininterface must be impelmented by all auth plugins
 *
 * @package coreauth
 * @author John Brown <john@home-lan.co.uk>
*/
interface authplugininterface {

	/**
	 * Intiailizes this plugins data structures
	 *
	 * @param string $sUser The users details should be suppliable as a string for unit tests
	*/
	static public function init($sUsers=NULL);


	/**
	 * Checks the username and password 
	 *
	 * @param string $sUsername
	 * @param string $sPassword 
	 * @param int $iNetwork A plugin can restrict optionally by network
	 * @param int $iStation A plugin can restrict optionally by station
	 * @return boolean
	*/
	static public function login($sUsername,$sPassword,$iNetwork=NULL,$iStation=NULL);

	/**
	 * Creates a user object based of the auth data used by the plugin
	 *
	 * @param string $sUsername
	 * @return object user
	*/
	static public function buildUserObject($sUsername);

	/**
	 * Set the password for a given user
	 *
	 * @param string $sUsername
	 * @param string $sPassword
	*/
	static public function setPassword($sUsername,$sPassword);

}
?>
