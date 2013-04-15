<?

class security {

	protected static $aSessions = array();

	/**
	 * Get a list of all the authplugs we should be using 
	 *
	 * It also calls the init method of each one
	 *
	**/
	protected static function _getAuthPlugins()
	{
		$aReturn = array();
		$aAuthPlugis = explode(',',config::getValue('security_auth_plugins'));
		foreach($aAuthPlugis as $sPlugin){
			$sClassname = "authplugin".$sPlugin;
			if(!class_exists($sClassname,FALSE)){
				try{
					$sClassname::init();
					$aReturn[]=$sClassname;
				}catch(Exception $oException){
					logger::log("Security: Unable to load authplugin ".$sClassname,LOG_INFO);
				}
			}else{
				$aReturn[]=$sClassname;
			}
		}
		return $aReturn;
	}
	
	/**
	 * Performs the login operation for a give user on a network and station
	 *
	 * @param int $iNetwork
	 * @param int $iStation
	 * @param string $sUser
	 * @param string $sPass
	*/
	public static function login($iNetwork,$iStation,$sUser,$sPass)
	{
		$aPlugins = security::_getAuthPlugins();
		foreach($aPlugins as $sPlugin){
			try {
				if($sPlugin::login($sUser,$sPass,$iNetwork,$iStation)){
					logger::log("Security: Login for ".$sUser." using authplugin ".$sPlugin,LOG_INFO);
					if(!array_key_exists($iNetwork,security::$aSessions)){
						security::$aSessions[$iNetwork]=array();
					}
					security::$aSessions[$iNetwork][$iStation]=array('datetime'=>time(),'provider'=>$sPlugin,'user'=>$sPlugin::buildUserObject($sUser));
					return TRUE;
				}else{
					logger::log("Security: Login failed for ".$sUser." using authplugin ".$sPlugin,LOG_INFO);
				}
			}catch(Exception $oException){
				logger::log("Security: Exception thrown during login attempt by authplugin ".$sPlugin." (".$oException->getMessage().").",LOG_INFO);
			}
		}
		return FALSE;
	}

	public static function getUser($iNetwork,$iStation)
	{
		if(array_key_exists($iNetwork,security::$aSessions) AND array_key_exists($iStation,security::$aSessions[$iNetwork])){
			return security::$aSessions[$iNetwork][$iStation]['user'];
		}
	}

	public static function isLoggedIn($iNetwork,$iStation)
	{
		if(array_key_exists($iNetwork,security::$aSessions) AND array_key_exists($iStation,security::$aSessions[$iNetwork])){
			return TRUE;
		}
		return FALSE;
	}

	public static function setConnectedUsersPassword($iNetwork,$iStation,$sPassword)
	{
		if(array_key_exists($iNetwork,security::$aSessions) AND array_key_exists($iStation,security::$aSessions[$iNetwork])){
			logger::log("Security: Changing password for ".security::$aSessions[$iNetwork][$iStation]['user']->getUsername()." using authplugin ".security::$aSessions[$iNetwork][$iStation]['provider'],LOG_INFO);
			$sPlugin = security::$aSessions[$iNetwork][$iStation]['provider'];
			$sPlugin::setPassword(security::$aSessions[$iNetwork][$iStation]['user']->getUsername(),$sPassword);
		}
	}
}

?>
