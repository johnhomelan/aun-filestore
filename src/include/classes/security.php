<?

class securtiy {

	protected static $aSessions = array();

	/**
	 * Get a list of all the authplugs we should be using 
	 *
	 * It also calls the init method of each one
	 *
	**/
	protected function _getAuthPlugins()
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
				if($sPlugin::login($iNetwork,$iStation,$sUser,$sPass)){
					logger::log("Security: Login for ".$sUser." using authplugin ".$sPlugin,LOG_INFO);
					if(!array_key_exists($iNetwork,security::$aSessions)){
						security::$aSessions[$iNetwork];
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
		if(array_key_exists($iNetwork,securtiy::$aSessions) AND array_key_exists($iStation,securtiy::$aSessions[$iNetwork])){
			return securtiy::$aSessions[$iNetwork][$iStation]['user'];
		}
	}

	public static function isLoggedIn($iNetwork,$iStation)
	{
		if(array_key_exists($iNetwork,securtiy::$aSessions) AND array_key_exists($iStation,securtiy::$aSessions[$iNetwork])){
			return TRUE;
		}
		return FALSE;
	}

	public static function setConnectedUsersPassword($iNetwork,$iStation,$sPassword)
	{
		if(array_key_exists($iNetwork,securtiy::$aSessions) AND array_key_exists($iStation,securtiy::$aSessions[$iNetwork])){
			logger::log("Security: Changing password for ".securtiy::$aSessions[$iNetwork][$iStation]['user']->getUsername()." using authplugin ".securtiy::$aSessions[$iNetwork][$iStation]['provider'],LOG_INFO);
			$sPlugin = securtiy::$aSessions[$iNetwork][$iStation]['provider'];
			$sPlugin::setPassword(securtiy::$aSessions[$iNetwork][$iStation]['user']->getUsername(),$sPass);
		}
	}
}

?>
