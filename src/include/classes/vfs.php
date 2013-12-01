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

	static protected $aFileHandleIDs = array();

	public static function init()
	{
		$aPlugins = vfs::getVfsPlugins();
	}

	/**
	 * Get a list of all the vfsplugin we should be using 
	 *
	 * It also calls the init method of each one when the class is loaded for the first time
	 *
	**/
	public static function getVfsPlugins()
	{
		$aReturn = array();
		$aVfsPlugins = explode(',',config::getValue('vfs_plugins'));
		foreach($aVfsPlugins as $sPlugin){
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
	
	static protected function _buildFiledescriptorFromEconetPath($oUser,$sCsd,$sEconetPath,$bMustExist,$bReadOnly)
	{
		$aPlugins = vfs::getVfsPlugins();
		$oHandle=NULL;
		foreach($aPlugins as $sPlugin){
			try {
				$oHandle = $sPlugin::_buildFiledescriptorFromEconetPath($oUser,$sCsd,$sEconetPath,$bMustExist,$bReadOnly);
			}catch(VfsException $oVfsException){
				//If it's a hard error abort the operation
				if($oVfsException->isHard()){
					throw $oVfsException;
				}
			}
		}
		if(!is_object($oHandle)){
			throw new Exception("vfs: File/Dir not found (".$sEconetPath.")");
		}
		return $oHandle;
	}

	static public function getDirectoryListing($oFd)
	{
		$sPath = $oFd->getEconetPath();
		$aDirectoryListing = array();
		$aPlugins = vfs::getVfsPlugins();
		foreach($aPlugins as $sPlugin){
			try {
				$aDirectoryListing = $sPlugin::getDirectoryListing($sPath,$aDirectoryListing);	
			}catch(VfsException $oVfsException){	
				if($oVfsException->isHard()){
					return array();
				}
			}
		}
		return $aDirectoryListing;
	}

	static public function getFreeFileHandleID($oUser)
	{
		if(!array_key_exists($oUser->getUserName(),vfs::$aFileHandleIDs)){
			vfs::$aFileHandleIDs[$oUser->getUserName()]=0;
		}
		vfs::$aFileHandleIDs[$oUser->getUserName()]++;
		if(vfs::$aFileHandleIDs[$oUser->getUserName()]>254){
			vfs::$aFileHandleIDs[$oUser->getUserName()]=1;
		}
		return vfs::$aFileHandleIDs[$oUser->getUserName()];
	}		

	static public function createDirectory($iNetwork,$iStation,$sEconetPath)
	{
		if(!security::isLoggedIn($iNetwork,$iStation)){
			logger::log("vfs: Un-able to create a handle for a station that is not logged in (Who are you?)",LOG_DEBUG);
			throw new Exception("vfs: Un-able to create a handle for a station that is not logged in (Who are you?)");
		}
		$oUser = security::getUser($iNetwork,$iStation);
		$sCsd = $oUser->getCsd();
		$aPlugins = vfs::getVfsPlugins();
		$oHandle=NULL;
		foreach($aPlugins as $sPlugin){
			try {
				if($sPlugin::createDirectory($oUser,$sCsd,$sEconetPath)){
					return;
				}
			}catch(VfsException $oVfsException){
				//If it's a hard error abort the operation
				if($oVfsException->isHard()){
					throw $oVfsException;
				}
			}
		}
		throw new Exception("vfs: Unable to create directory (".$sEconetPath.")");
	}

	static public function deleteFile($iNetwork,$iStation,$sEconetPath)
	{
		if(!security::isLoggedIn($iNetwork,$iStation)){
			logger::log("vfs: Un-able to create a handle for a station that is not logged in (Who are you?)",LOG_DEBUG);
			throw new Exception("vfs: Un-able to create a handle for a station that is not logged in (Who are you?)");
		}
		$oUser = security::getUser($iNetwork,$iStation);
		$sCsd = $oUser->getCsd();
		$aPlugins = vfs::getVfsPlugins();
		$oHandle=NULL;
		foreach($aPlugins as $sPlugin){
			try {
				if($sPlugin::deleteFile($oUser,$sCsd,$sEconetPath)){
					return;
				}
			}catch(VfsException $oVfsException){
				//If it's a hard error abort the operation
				if($oVfsException->isHard()){
					throw $oVfsException;
				}
			}
		}
		throw new Exception("vfs: Unable to delete file (".$sEconetPath.")");
	}

	static public function moveFile($iNetwork,$iStation,$sEconetPathFrom,$sEconetPathTo)
	{
		if(!security::isLoggedIn($iNetwork,$iStation)){
			logger::log("vfs: Un-able to create a handle for a station that is not logged in (Who are you?)",LOG_DEBUG);
			throw new Exception("vfs: Un-able to create a handle for a station that is not logged in (Who are you?)");
		}
		$oUser = security::getUser($iNetwork,$iStation);
		$sCsd = $oUser->getCsd();
		$aPlugins = vfs::getVfsPlugins();
		$oHandle=NULL;
		foreach($aPlugins as $sPlugin){

			try {
				if($sPlugin::moveFile($oUser,$sCsd,$sEconetPathFrom,$sEconetPathTo)){
					return;
				}
			}catch(VfsException $oVfsException){
				//If it's a hard error abort the operation
				if($oVfsException->isHard()){
					throw $oVfsException;
				}
			}
		}
		throw new Exception("vfs: Unable to move file (".$sEconetPathFrom.")");
	}

	static public function saveFile($iNetwork,$iStation,$sEconetPath,$sData,$iLoadAddr,$iExecAddr)
	{
		if(!security::isLoggedIn($iNetwork,$iStation)){
			logger::log("vfs: Un-able to create a handle for a station that is not logged in (Who are you?)",LOG_DEBUG);
			throw new Exception("vfs: Un-able to create a handle for a station that is not logged in (Who are you?)");
		}
		$oUser = security::getUser($iNetwork,$iStation);
		$sCsd = $oUser->getCsd();
		$aPlugins = vfs::getVfsPlugins();
		$oHandle=NULL;
		foreach($aPlugins as $sPlugin){
			try {
				if($sPlugin::saveFile($oUser,$sCsd,$sEconetPath,$sData,$iLoadAddr,$iExecAddr)){
					return;
				}
			}catch(VfsException $oVfsException){
				//If it's a hard error abort the operation
				if($oVfsException->isHard()){
					throw $oVfsException;
				}
			}
		}
		throw new Exception("vfs: Unable to save file (".$sEconetPath.")");
	}

	static public function createFile($iNetwork,$iStation,$sEconetPath,$iSize,$iLoadAddr,$iExecAddr)
	{
		if(!security::isLoggedIn($iNetwork,$iStation)){
			logger::log("vfs: Un-able to create a handle for a station that is not logged in (Who are you?)",LOG_DEBUG);
			throw new Exception("vfs: Un-able to create a handle for a station that is not logged in (Who are you?)");
		}
		$oUser = security::getUser($iNetwork,$iStation);
		$sCsd = $oUser->getCsd();
		$aPlugins = vfs::getVfsPlugins();
		$oHandle=NULL;
		foreach($aPlugins as $sPlugin){
			try {
				if($sPlugin::createFile($oUser,$sCsd,$sEconetPath,$iSize,$iLoadAddr,$iExecAddr)){
					return;
				}
			}catch(VfsException $oVfsException){
				//If it's a hard error abort the operation
				if($oVfsException->isHard()){
					throw $oVfsException;
				}
			}
		}
		throw new Exception("vfs: Unable to save file (".$sEconetPath.")");
	}

	static public function getFile($iNetwork,$iStation,$sEconetPath)
	{
		if(!security::isLoggedIn($iNetwork,$iStation)){
			logger::log("vfs: Un-able to create a handle for a station that is not logged in (Who are you?)",LOG_DEBUG);
			throw new Exception("vfs: Un-able to create a handle for a station that is not logged in (Who are you?)");
		}
		$oUser = security::getUser($iNetwork,$iStation);
		$sCsd = $oUser->getCsd();
		$aPlugins = vfs::getVfsPlugins();
		$oHandle=NULL;
		foreach($aPlugins as $sPlugin){
			try {
				return $sPlugin::getFile($oUser,$sCsd,$sEconetPath);
			}catch(VfsException $oVfsException){
				//If it's a hard error abort the operation
				if($oVfsException->isHard()){
					throw $oVfsException;
				}
			}
		}
		throw new Exception("vfs: Unable to get file (".$sEconetPath.")");

	}

	/**
	 * Gets the meta data for a given file
	 *
	 * @param int $iNetwork
	 * @param int $iStation
	 * @param string $sEconetPath	
	*/
	static public function getMeta($iNetwork,$iStation,$sEconetPath)
	{	
		if(!security::isLoggedIn($iNetwork,$iStation)){
			logger::log("vfs: Un-able to create a handle for a station that is not logged in (Who are you?)",LOG_DEBUG);
			throw new Exception("vfs: Un-able to create a handle for a station that is not logged in (Who are you?)");
		}
		logger::log("vfs: getMeta for ".$sEconetPath,LOG_DEBUG);
		if(strpos($sEconetPath,'$')===0){
			//Absolute path
			$aPath = explode('.',$sEconetPath);
			$sFile = array_pop($aPath);
			$sDir = join('.',$aPath);
		}elseif(strpos($sEconetPath,'.')!==FALSE){
			//Relitive path
			$oUser = security::getUser($iNetwork,$iStation);
			$aPath = explode('.',$sEconetPath);
			$sFile = array_pop($aPath);
			$sDir = $oUser->getCsd().'.'.join('.',$aPath);
		}else{
			//No path
			$oUser = security::getUser($iNetwork,$iStation);
			$sFile = $sEconetPath;
			$sDir = $oUser->getCsd();
		}

		$aDirectoryListing = array();
		$aPlugins = vfs::getVfsPlugins();
		foreach($aPlugins as $sPlugin){
			try {
				$aDirectoryListing = $sPlugin::getDirectoryListing($sDir,$aDirectoryListing);	
			}catch(VfsException $oVfsException){	
				if($oVfsException->isHard()){
					throw $oVfsException;
				}
			}
		}
		if(array_key_exists($sFile,$aDirectoryListing)){
			return $aDirectoryListing[$sFile];
		}else{
			logger::log("VFS: getMeta no such file ".$sFile." in dir ".$sDir."",LOG_DEBUG);
			throw new Exception("No such file");
		}
	}

	/**
	 * Sets the meta data for a given file
	 *
	 * As all files must have a load exec and access mode setting the values to NULL means leave unchanged
	 * @param int $iNetwork
	 * @param int $iStation
	 * @param string $sEconetPath	
	 * @param int $iLoad
	 * @param int $iExec
	 * @param int $iAccess
	*/
	static public function setMeta($iNetwork,$iStation,$sEconetPath,$iLoad,$iExec,$iAccess)
	{	
		if(!security::isLoggedIn($iNetwork,$iStation)){
			logger::log("vfs: Un-able to create a handle for a station that is not logged in (Who are you?)",LOG_DEBUG);
			throw new Exception("vfs: Un-able to create a handle for a station that is not logged in (Who are you?)");
		}
		if(strpos($sEconetPath,'$')===0){
			//Absolute path
			$sPath = $sEconetPath;
		}else{
			$oUser = security::getUser($iNetwork,$iStation);
			$sPath = $oUser->getCsd().'.'.trim($sEconetPath,'.');
		}

		$aPlugins = vfs::getVfsPlugins();
		foreach($aPlugins as $sPlugin){
			try {
				$sPlugin::setMeta($sPath,$iLoad,$iExec,$iAccess);	
			}catch(VfsException $oVfsException){	
				if($oVfsException->isHard()){
					throw $oVfsException;
				}
			}
		}
	}

	/**
	 * Creates file handle for a given network/station to a given file path
	 *
	 * The file path supplied is a file path as seen by the client which must be converted to a local file path
	 *
	 * @param int $iNetwork
	 * @param int $iStation
	 * @param string $sEconetPath
	 * @param boolean $bMustExist
	 * @param boolean $bReadOnly
	*/
	static public function createFsHandle($iNetwork,$iStation,$sEconetPath,$bMustExist=TRUE,$bReadOnly=TRUE)
	{
		if(!security::isLoggedIn($iNetwork,$iStation)){
			logger::log("vfs: Un-able to create a handle for a station that is not logged in (Who are you?)",LOG_DEBUG);
			throw new Exception("vfs: Un-able to create a handle for a station that is not logged in (Who are you?)");
		}
		$oUser = security::getUser($iNetwork,$iStation);
		$sCsd = $oUser->getCsd();
		$oHandle = vfs::_buildFiledescriptorFromEconetPath($oUser,$sCsd,$sEconetPath,$bMustExist,$bReadOnly);

		//Store the handel for later use
		if(!array_key_exists($iNetwork,vfs::$aHandles)){
			vfs::$aHandles[$iNetwork]=array();
		}
		if(!array_key_exists($iStation,vfs::$aHandles[$iNetwork])){
			vfs::$aHandles[$iNetwork][$iStation]=array();
		}
		vfs::$aHandles[$iNetwork][$iStation][$oHandle->getID()]=$oHandle;

		//Return the handle 
		return $oHandle;
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
		if(array_key_exists($iNetwork,vfs::$aHandles) AND array_key_exists($iStation,vfs::$aHandles[$iNetwork]) AND array_key_exists($iHandle,vfs::$aHandles[$iNetwork][$iStation])){
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
		if(array_key_exists($iNetwork,vfs::$aHandles) AND array_key_exists($iStation,vfs::$aHandles[$iNetwork]) AND array_key_exists($iHandle,vfs::$aHandles[$iNetwork][$iStation])){
			vfs::$aHandles[$iNetwork][$iStation][$iHandle]->close();
			unset(vfs::$aHandles[$iNetwork][$iStation][$iHandle]);
		}
	}


}

?>
