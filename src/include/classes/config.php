<?
/**
 * @package coreutils
 */

/**
 * Class for retreving config from files / defines 
 *
 * @package coreutils
*/


class config {

	static protected $aFileSettings=NULL;
	static protected $_aConfigCache=array();
	static protected $_aVarSpec=NULL;
	

	/**
	 * Gets a config variable 
	 *
	 * Config sources have an order of presidence, licsense file, dbfile, configfiles, defines
	 * In the case of a key having a numeric value the lowest value allways wins (except that db always overides conf file values).
	 *
	 * @param string $sKey Variable to get
	 * @return mixed
	*/
	static public function getValue($sKey)
	{
		if(array_key_exists($sKey,config::$_aConfigCache)){
			return config::$_aConfigCache[$sKey];
		}

		$mDefinesValue=config::_getDefinedValue($sKey);
		$mFileValue=config::_getConfigFileValue($sKey);
		
		$mReturn = NULL;
		//Start with the lowest precedence source and overwrite with higher precedence sources
		if (!is_null($mDefinesValue)){
			$mReturn = $mDefinesValue;
		}
		if (!is_null($mFileValue)){
			$mReturn = $mFileValue;
		}
		
		config::$_aConfigCache[$sKey]=$mReturn;
		return $mReturn;
	}

	/**
	 * Gets a config variable from constant definitions 
	 *
	 * Constants must be in the form CONFIG_key to be pulled via this system
	 *
	 * @param string $sKey Variable to get
	 * @return mixed
	*/
	static protected function _getDefinedValue($sKey)
	{
		if(defined('CONFIG_'.$sKey)){
			return constant('CONFIG_'.$sKey);
		}			
	}

	/**
	 * Gets a config variable from a collection of config files
	 *
	 * For this method to work the constant CONFIG_CONF_FILE_PATH must be defined as the director where config files should be read from,
	 * any files in the directory will be read in. In the case conflicting keys in multiple files the file last loaded wins (files are loaded in director order).
	 * The config files are in the form key=vaule newline key=value 
	 *
	 * @param string $sKey Variable to get
	 * @return mixed
	*/
	static protected function _getConfigFileValue($sKey)
	{
		//If we have already read in our config do a quick lookup
		if(is_array(config::$aFileSettings)){
			if(array_key_exists($sKey,config::$aFileSettings)){
				return config::$aFileSettings[$sKey];
			}
			return NULL;
		}

		//If we know where our config is stored load it 
		if(defined('CONFIG_CONF_FILE_PATH')){
			if(!file_exists(CONFIG_CONF_FILE_PATH)){
				logger::log("Missing config directory (".CONFIG_CONF_FILE_PATH.")",E_USER_NOTICE);	
				return NULL;
			}

			$aFiles=scandir(CONFIG_CONF_FILE_PATH);
			

			//Produce a list of only files ending in .conf
			$sPat='/\.conf$/';
			$aFiles=preg_grep($sPat,$aFiles);
			$aFiles=array_values($aFiles);

			if(count($aFiles)==0){
				return NULL;
			}

			//Parse Each conf file
			$aSettings=array();
			foreach($aFiles as $sFile){
				$sFile=CONFIG_CONF_FILE_PATH.DIRECTORY_SEPARATOR.$sFile;
				$aSettings = array_merge($aSettings,parse_ini_file($sFile, true));
			}
			//Cache our config for later use 
			config::$aFileSettings=$aSettings;
			if(array_key_exists($sKey,$aSettings)){
				return $aSettings[$sKey];
			}
			return NULL;
		}
		return NULL;
	}

}
?>
