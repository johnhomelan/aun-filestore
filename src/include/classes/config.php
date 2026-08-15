<?php
/**
 * @package coreutils
 */

/**
 * Class for retreving config from files / defines 
 *
 * @package coreutils
*/


class config {

	/**
 	 * @var array<string, string>
 	*/ 	
	static protected ?array $aFileSettings=NULL;

	/**
 	 * @var array<string, mixed>
 	*/
 	static protected array $_aConfigCache=[];

	

	/**
	 * Gets a config variable 
	 *
	 * Config sources have an order of presidence, licsense file, dbfile, configfiles, defines
	 * In the case of a key having a numeric value the lowest value allways wins (except that db always overides conf file values).
	 *
	 * @param string $sKey Variable to get
	 * @return mixed
	*/
	static public function getValue(string $sKey)
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
	 * Gets a config variable as a string
	 *
	 * A non-scalar config entry (e.g. an INI section, or an unset key) has
	 * no sensible string form and is treated as an empty string.
	*/
	static public function getValueAsString(string $sKey): string
	{
		$mValue = config::getValue($sKey);
		return is_scalar($mValue) ? (string) $mValue : '';
	}

	/**
	 * Gets a config variable as an int
	*/
	static public function getValueAsInt(string $sKey): int
	{
		$mValue = config::getValue($sKey);
		return is_scalar($mValue) ? (int) $mValue : 0;
	}

	/**
	 * Gets a config variable as a float
	*/
	static public function getValueAsFloat(string $sKey): float
	{
		$mValue = config::getValue($sKey);
		return is_scalar($mValue) ? (float) $mValue : 0.0;
	}

	/**
	 * Gets a config variable as a bool
	*/
	static public function getValueAsBool(string $sKey): bool
	{
		return (bool) config::getValue($sKey);
	}

	/**
	 * Gets a config variable as an array (e.g. an INI section); a
	 * non-array value (or an unset key) is treated as an empty array.
	 *
	 * @return array<mixed>
	*/
	static public function getValueAsArray(string $sKey): array
	{
		$mValue = config::getValue($sKey);
		return is_array($mValue) ? $mValue : [];
	}

	static public function overrideValue(string $sKey,string|int $sValue): void
	{
		config::$_aConfigCache[$sKey] = $sValue;
	}

	static public function resetValue(string $sKey): void
	{
		if(array_key_exists($sKey, config::$_aConfigCache)){
			unset(config::$_aConfigCache[$sKey]);
		}
	}

	/**
	 * Gets a config variable from constant definitions 
	 *
	 * Constants must be in the form CONFIG_key to be pulled via this system
	 *
	 * @param string $sKey Variable to get
	 * @return mixed
	*/
	static protected function _getDefinedValue(string $sKey)
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
	static protected function _getConfigFileValue(string $sKey)
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
			$sConfDirPath = is_scalar(CONFIG_CONF_FILE_PATH) ? (string) CONFIG_CONF_FILE_PATH : '';
			if(!file_exists($sConfDirPath)){
				return NULL;
			}

			$aFiles=scandir($sConfDirPath);
			if($aFiles === false){
				return NULL;
			}

			//Produce a list of only files ending in .conf
			$sPat='/\.conf$/';
			$aFiles=preg_grep($sPat,$aFiles);
			if($aFiles === false){
				return NULL;
			}
			$aFiles=array_values($aFiles);

			if(count($aFiles)==0){
				return NULL;
			}

			//Parse Each conf file
			$aSettings=[];
			foreach($aFiles as $sConfFile){
				$sFile=$sConfDirPath.DIRECTORY_SEPARATOR.(is_scalar($sConfFile) ? (string) $sConfFile : '');
				$aParsedFile = parse_ini_file($sFile, true);
				if($aParsedFile === false){
					continue;
				}
				foreach($aParsedFile as $sSettingKey=>$mSettingValue){
					$aSettings[(string) $sSettingKey] = is_scalar($mSettingValue) ? (string) $mSettingValue : '';
				}
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
