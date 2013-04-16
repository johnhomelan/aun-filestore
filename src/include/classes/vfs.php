<?
/**
 * This file contains the vfs class
 *
 * @package corevfs
 *
*/

/**
 * The vfs class handles all file operations carried out by the file server.
 *
 * It make use of a number of vfs plugins to carry out the work with local or remote file systems.
 *
 * @package corevfs
 * @author John Brown <john@home-lan.co.uk>
*/
class vfs {

	static protected $aHandles = array();

	/**
	 * Get a list of all the vfsplugin we should be using 
	 *
	 * It also calls the init method of each one when the class is loaded for the first time
	 *
	**/
	protected static function _getVfsPlugins()
	{
		$aReturn = array();
		$aVfsPlugis = explode(',',config::getValue('vfs_plugins'));
		foreach($aVfsPlugis as $sPlugin){
			$sClassname = "vfsplugin".$sPlugin;
			if(!class_exists($sClassname,FALSE)){
				try{
					$sClassname::init();
					$aReturn[]=$sClassname;
				}catch(Exception $oException){
					logger::log("VFS: Unable to load vfsplugin ".$sClassname,LOG_INFO);
				}
			}else{
				$aReturn[]=$sClassname;
			}
		}
		return $aReturn;
	}
	
	protected function _buildFiledescriptorFromEconetPath($sCwd,$sEconetPath)
	{
		$aPlugins = vfs::_getVfsPlugins();
		foreach($aPlugins as $sPlugin){
			try {
				$oHandle = $sPlugin::_buildFiledescriptorFromEconetPath($sCwd,$sEconetPath);
			}catch(Exception $oException){
				
			}
		}
		if(!is_object($oHandle)){
			throw new Exception("vfs: File/Dir not found (".$sEconetPath.")");
		}
		return $oHandle;
	}

	/**
	 * Creates file handle for a given network/station to a given file path
	 *
	 * The file path supplied is a file path as seen by the client which must be converted to a local file path
	 *
	 * @param int $iNetwork
	 * @param int $iStation
	 * @param string $sEconetPath
	*/
	static public function createFsHandle($iNetwork,$iStation,$sEconetPath)
	{
		if(!security::isLoggedIn($iNetwork,$iStation)){
			logger::log("vfs: Un-able to create a handle for a station that is not logged in (Who are you?)",LOG_DEBUG);
			throw new Exception("vfs: Un-able to create a handle for a station that is not logged in (Who are you?)");
		}
		$oUser = security::getUser();
		$sCwd = $oUser->getCwd();
		$oHandle = vfs::_buildFiledescriptorFromEconetPath($sCwd,$sEconetPath);
		if(!array_key_exists($iNetwork,vfs::$aHandles)){
			vfs::$aHandles[$iNetwork]=array();
		}
		if(!array_key_exists($iStation,vfs::$aHandles[$iNetwork])){
			vfs::$aHandles[$iNetwork][$iStation]=array();
		}
		vfs::$aHandles[$iNetwork][$iStation][$oHandle->getID()]=$oHandle;
		return $oHandle->getID();
	}

	/**
	 * Get a file handle object for a given network/station
	 *
	 * @param int $iNetwork
	 * @param int $iStation
	 * @param int $iHandle The filehandel used by the client
	*/
	static public function getFsHandle($iNetwork,$iStation,$iHandle)
	{
		if(array_key_exists($iNetwork,vfs::$aHandles) AND array_key_exists($iStation,vfs::$aHandles[$iNetwork]) AND array_key_exists($iHandle,vfs::$aHandles[$iNetwork][$iStation]){
			return vfs::$aHandles[$iNetwork][$iStation][$iHandle];
		}
		logger::log("vfs: Invalid file handle ".$iHandle." for ".$iNetwork.".".$iStation,LOG_DEBUG);
		throw new Exception("vfs: Invalid file handle ".$iHandle." for ".$iNetwork.".".$iStation);
	}

	/**
	 * Closes a file handle for a given network and station
	 *
	 * @param int $iNetwork
	 * @param int $iStation
	 * @param int $iHandle The filehandel used by the client
	*/
	static public function closeFsHandle($iNetwork,$iStation,$iHandle)
	{
		if(array_key_exists($iNetwork,vfs::$aHandles) AND array_key_exists($iStation,vfs::$aHandles[$iNetwork]) AND array_key_exists($iHandle,vfs::$aHandles[$iNetwork][$iStation]){
			vfs::$aHandles[$iNetwork][$iStation][$iHandle]->close();
			unset(vfs::$aHandles[$iNetwork][$iStation][$iHandle]);
		}
	}


}

?>
