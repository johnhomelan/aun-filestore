<?
/**
 * @package coreutils
 */

/**
 * Class for dealing with logging
 *
 * @author John Brown <john@home-lan.co.uk>
 * @package coreutils
*/
openlog("log", LOG_PID | LOG_PERROR, LOG_LOCAL0);

class logger
{
	static $aErrorLevels = array(LOG_EMERG => "EMERG",LOG_ALERT => "ALERT", LOG_CRIT => "CRIT", LOG_ERR => "ERR", LOG_WARNING => "WARN", LOG_NOTICE => "NOTICE" , LOG_INFO => "INFO", LOG_DEBUG => "DEBUG");

	/**
	 * Writes a message to the log
	 * 
	 * @param string $sMessage Message to log
	 * @param int $iErrorLevel Error level specified as syslog log level
	*/
	static public function log($sMessage, $iErrorLevel = LOG_INFO)
	{
		$iLogLevel = config::getValue("loglevel");
		if ($iErrorLevel <=  $iLogLevel )
		{
			$sDateTime = date ('D j M Y H:i:s');
			$iPid = getmypid();
			if (is_null($sMessage)){
				$sMessage = "----BLANK----";
			}
			$sErrorLevel = self::$aErrorLevels[$iErrorLevel];
			$sLogMsg = $sDateTime." [".$iPid."] ".$sErrorLevel.": ".$sMessage;

			switch(strtolower(config::getValue('logbackend'))){
				case 'syslog':
					logger::_logSyslog($sLogMsg,$iErrorLevel);
					break;
				case 'logfile':
				default:
					logger::_logFile($sLogMsg,$iErrorLevel);
					break;
			}
			$bLogStderr = config::getValue("logstderr");
			if ($bLogStderr){
				fwrite(STDERR,$sLogMsg);
			}
		}
	}

	static protected function _logSyslog($sLogMsg, $iErrorLevel = LOG_INFO)
	{
		syslog($iErrorLevel,$sLogMsg);
	}

	static protected function _logFile($sLogMsg, $iErrorLevel = LOG_INFO)
	{
		$sLogFile = config::getValue("logfile");	
		if(strlen($sLogFile)>0){
			error_log($sLogMsg, 3, $sLogFile);
		}else{
			error_log($sLogMsg);
		}
	}

}


?>
