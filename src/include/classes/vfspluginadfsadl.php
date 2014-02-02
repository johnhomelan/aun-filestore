<?
/**
 * This file contains the adfs adl image vfs plugin
 *
*/

/**
 * The vfspluginadfsadl class acts as a vfs plugin to provide access to files stored in a adfs filing system stored in a adl image.
 * 
 *
 * @package corevfs
 * @authour John Brown <john@home-lan.co.uk>
*/
class vfspluginadfsadl {

	protected static $aImageReaders = array();

	protected static $aFileHandles = array();

	protected static $iFileHandle = 0;

	public static function init()
	{
	}

	public static function houseKeeping()
	{

	}

	protected  static function _setUid($oUser)
	{
		if(config::getValue('security_mode')=='multiuser'){
			posix_seteuid($this->oUser->getUnixUid());
		}
	}
	
	protected static function _returnUid()
	{
		if(config::getValue('security_mode')=='multiuser'){
			 posix_seteuid(config::getValue('system_user_id'));
		}
	}

	protected static function _getImageReader($sImageFile)
	{
		if(!array_key_exists($sImageFile,vfspluginadfsadl::$aImageReaders)){
			vfspluginadfsadl::$aImageReaders[$sImageFile] = new adfsreader($sImageFile);
		}
		return vfspluginadfsadl::$aImageReaders[$sImageFile];
	}

	protected static function _econetToUnix($sEconetPath)
	{
		//Trim leading $.
		$sEconetPath = substr($sEconetPath,2);
		$aFileParts = explode('.',$sEconetPath);
		$sUnixPath = "";
		foreach($aFileParts as $sPart){
			$sUnixPath = $sUnixPath.str_replace(DIRECTORY_SEPARATOR ,'.',$sPart).DIRECTORY_SEPARATOR;
		}
		$sUnixPath = trim($sUnixPath,DIRECTORY_SEPARATOR);
		$sUnixPath = config::getValue('vfs_plugin_localadfsadl_root').DIRECTORY_SEPARATOR.$sUnixPath;

		
		if(!file_exists($sUnixPath)){
			//The file does not exists see if a case insenstive version of this files exists
			$sDir = dirname($sUnixPath);
			$sTestFileName = strtolower(basename($sUnixPath));
			if(is_dir($sDir)){
				//Just test if dir exists in the correct case and only the file name is case incorrect
				$aFiles = scandir($sDir);
				foreach($aFiles as $sFile){
					if(strtolower($sFile)==$sTestFileName){
						return $sDir.DIRECTORY_SEPARATOR.$sFile;
					}
				}
			}else{
				//The directroy does not exist so walk the directory tree in a case insensitve way an try to find the real dir/file
				$aDirParts = explode(DIRECTORY_SEPARATOR,$sUnixPath);
				$sNewDirPath = "";
				$iMatches = 0;
				foreach($aDirParts as $sDirPart){
					if(is_dir($sNewDirPath.DIRECTORY_SEPARATOR.$sDirPart)){
						$sNewDirPath .= DIRECTORY_SEPARATOR.$sDirPart;
						$iMatches++;
						continue;
					}else{
						$aFiles = scandir($sNewDirPath);
						foreach($aFiles as $sFile){
							if(strtolower($sFile)==strtolower($sDirPart)){
								$iMatches++;
								$sNewDirPath .= DIRECTORY_SEPARATOR.$sFile;
								continue;
							}elseif(strtolower($sFile)===strtolower($sDirPart).'.adl'){
								//The file is inside an image file so just return the Unix path
								$sNewDirPath .= DIRECTORY_SEPARATOR.$sDirPart;
								return $sNewDirPath; 
							}
						}
					}
				}
				if($iMatches==count($aDirParts)){
					return $sNewDirPath;
				}
				
			}

		}
		return $sUnixPath;
	}

	protected static function _getImageFile($sEconetPath)
	{
		$sUnixPath = self::_econetToUnix($sEconetPath);
		$aUnixPath = explode(DIRECTORY_SEPARATOR,$sUnixPath);
		while(count($aUnixPath)>0){
			$sUnixPath = implode(DIRECTORY_SEPARATOR,$aUnixPath);
			if(is_file($sUnixPath.".adl")){
				return $sUnixPath.".adl";
			}elseif($sUnixPath==config::getValue('vfs_plugin_localadfsadl_root')){
				return;
			}
			$sFilePathPart = array_pop($aUnixPath);
		}
		return;
	}

	protected static function _getPathInsideImage($sEconetPath,$sImageFile)
	{
		//Trim leading $.
		$sEconetPath = substr($sEconetPath,2);

		$sPathPreFix = substr($sImageFile,0,strlen($sImageFile)-4);
		$sPathPreFix = str_ireplace(config::getValue('vfs_plugin_localadfsadl_root'),'',$sPathPreFix);
		$sPathPreFix = str_ireplace(DIRECTORY_SEPARATOR,'.',ltrim($sPathPreFix,'/'));
		return ltrim(str_ireplace($sPathPreFix,'',$sEconetPath),'.');
	} 

	protected static function _checkImageFileExists($sImageFile,$sPathInsideImage)
	{
		$oAdfs = vfspluginadfsadl::_getImageReader($sImageFile);
		$aCat = $oAdfs->getCatalogue();
		$aPathInsideImage = explode('.',$sPathInsideImage);
		$bFound = FALSE;
		$iCount = 0;
		foreach($aPathInsideImage as $sPathPart){
			$aKeys = array_keys($aCat);
			foreach($aKeys as $sKey){
				if(strtoupper($sKey)==strtoupper($sPathPart)){
					$iCount++;
					if($aCat[$sKey]['type']='dir'){
						$aCat=$aCat[$sKey]['dir'];
					}
					break;
				}
			}
		}
		if($iCount==count($aPathInsideImage)){
			return TRUE;
		}
		return FALSE;
	}

	public static function _buildFiledescriptorFromEconetPath($oUser,$sCsd,$sEconetPath,$bMustExist,$bReadOnly)
	{
		if(strpos($sEconetPath,'$')===0){
			//Absolute Path
			$sImageFile = vfspluginadfsadl::_getImageFile($sEconetPath);
		}else{
			//Relative path
			$sEconetPath = $sCsd.'.'.$sEconetPath;
			$sImageFile = vfspluginadfsadl::_getImageFile($sEconetPath);
		}
		if(strlen($sImageFile)>0){
			$sPathInsideImage = vfspluginadfsadl::_getPathInsideImage($sEconetPath,$sImageFile);
			if(vfspluginadfsadl::_checkImageFileExists($sImageFile,$sPathInsideImage)){
				$iEconetHandle = vfs::getFreeFileHandleID($oUser);
				$iVfsHandle = vfspluginadfsadl::$iFileHandle++;
				vfspluginadfsadl::$aFileHandles[$iVfsHandle]=array('image-file'=>$sImageFile,'path-inside-image'=>$sPathInsideImage,'pos'=>0);
				$oAdfs =  vfspluginadfsadl::_getImageReader($sImageFile);
				return new filedescriptor('vfspluginadfsadl',$oUser,$sImageFile,$sEconetPath,$iVfsHandle,$iEconetHandle,$oAdfs->isFile($sPathInsideImage),$oAdfs->isDir($sPathInsideImage));
			}
		}

		//Scan the unix dir, see of there is a diskimage in that directory to see if it need changing to a directory
		$sUnixPath = vfspluginadfsadl::_econetToUnix($sEconetPath);
		if(file_exists($sUnixPath.'.adl')){
			//Disk Image found
			$iEconetHandle = vfs::getFreeFileHandleID($oUser);
			$iVfsHandle = vfspluginadfsadl::$iFileHandle++;
			vfspluginadfsadl::$aFileHandles[$iVfsHandle]=array('image-file'=>$sUnixPath.'.adl','path-inside-image'=>'','pos'=>0);
			return new filedescriptor('vfspluginadfsadl',$oUser,$sUnixPath.'.adl',$sEconetPath,$iVfsHandle,$iEconetHandle,FALSE,TRUE);
		}
	
	}


	public static function getDirectoryListing($sEconetPath,$aDirectoryListing)
	{
		$sImageFile = vfspluginadfsadl::_getImageFile($sEconetPath);
	
		//Produce a directory listing for file inside the image if the selected path is inside the image
		if(strlen($sImageFile)>0){
			$sPathInsideImage = vfspluginadfsadl::_getPathInsideImage($sEconetPath,$sImageFile);
			$oAdfs = vfspluginadfsadl::_getImageReader($sImageFile);
			$aImageStat = stat($sImageFile);
			$aCat = $oAdfs->getCatalogue();

			if(strlen($sPathInsideImage)>0){
				$aPathParts = explode('.',$sPathInsideImage);
				foreach($aPathParts as $sPart){
					if(array_key_exists($sPart,$aCat)){
						$aCat = $aCat[$sPart]['dir'];
					}else{
						return $aDirectoryListing;
					}
				}
			}

			foreach($aCat as $sFile=>$aMeta){
				$aDirectoryListing[$sFile] = new directoryentry($sFile,$sImageFile,'vfspluginadfsadl',$aMeta['load'],$aMeta['exec'],$aMeta['size'],$aMeta['type']=='dir' ? TRUE : FALSE ,$sEconetPath.'.'.$sFile,$aImageStat['ctime'],'-r/-r');
			}
		}
		
		//Scan the unix dir, see of there is a diskimage in that directory to see if it need changing to a directory
		$sUnixPath = vfspluginadfsadl::_econetToUnix($sEconetPath);
		if(is_dir($sUnixPath)){
			$aFiles = scandir($sUnixPath);
			foreach($aFiles as $sFile){
				if(stripos($sFile,'.adl')!==FALSE){
					//Disk Image found
					if(!array_key_exists(substr($sFile,0,strlen($sFile)-4),$aDirectoryListing)){
						$aStat = stat($sUnixPath.DIRECTORY_SEPARATOR.$sFile);
						$aDirectoryListing[$sFile]=new directoryentry(substr($sFile,0,strlen($sFile)-4),$sFile,'vfspluginadfsadl',NULL,NULL,0,TRUE,$sEconetPath.'.'.substr($sFile,0,strlen($sFile)-4),$aStat['ctime'],'-r/-r');
					}
				}
			}
		}


		//Rip out and .adl files from the list
		$aReturn = array();
		foreach($aDirectoryListing as $sFile => $oFile){
			if(stripos($sFile,"\/adl")===FALSE){
				$aReturn[$sFile]=$oFile;
			}
		}
		return $aReturn;
	}

	public static function createDirectory($oUser,$sCsd,$sEconetPath)
	{
		return FALSE;
	}

	public static function deleteFile($oUser,$sCsd,$sEconetPath)
	{
		return FALSE;
	}

	public static function moveFile($oUser,$sCsd,$sEconetPathFrom,$sEconetPathTo)
	{
		return FALSE;
	}

	public static function saveFile($oUser,$sCsd,$sEconetPath,$sData,$iLoadAddr,$iExecAddr)
	{
	}

	public static function createFile($oUser,$sCsd,$sEconetPath,$iSize,$iLoadAddr,$iExecAddr)
	{

	}

	/**
	 * Get the contents of a given file
	 *
	 * @throws VfsException if the file does not exist
	*/
	public static function getFile($oUser,$sCsd,$sEconetPath)
	{
		if(strpos($sEconetPath,'$')===0){
			//Absolute Path
			$sImageFile = vfspluginadfsadl::_getImageFile($sEconetPath);
		}else{
			//Relative path
			$sEconetPath = trim($sCsd,'.').'.'.$sEconetPath;
			$sImageFile = vfspluginadfsadl::_getImageFile($sEconetPath);
		}
		if(strlen($sImageFile)>0){
			$sPathInsideImage = vfspluginadfsadl::_getPathInsideImage($sEconetPath,$sImageFile);
			if(vfspluginadfsadl::_checkImageFileExists($sImageFile,$sPathInsideImage)){
				$oAdfs = vfspluginadfsadl::_getImageReader($sImageFile);
				return $oAdfs->getFile($sPathInsideImage);
			}
		}
		throw new VfsException("No such file");
	}

	public static function setMeta($sEconetPath,$iLoad,$iExec,$iAccess)
	{
	}

	public static function fsFtell($oUser,$fLocalHandle)
	{
		if(array_key_exists($fLocalHandle,vfspluginadfsadl::$aFileHandles)){
			return vfspluginadfsadl::$aFileHandles[$fLocalHandle]['pos'];
		}
		throw new VfsException("Invalid handle");
	}

	public static function fsFStat($oUser,$fLocalHandle)
	{
		if(array_key_exists($fLocalHandle,vfspluginadfsadl::$aFileHandles)){
			$oAdfs = vfspluginadfsadl::_getImageReader(vfspluginadfsadl::$aFileHandles[$fLocalHandle]['image-file']);
			$aStat = $oAdfs->getStat(vfspluginadfsadl::$aFileHandles[$fLocalHandle]['path-inside-image']);
			return array('dev'=>null,'ino'=>$aStat['sector'],'size'=>$aStat['size'],'nlink'=>1);
		}
		throw new VfsException("Invalid handle");
	}

	public static function isEof($oUser,$fLocalHandle)
	{
		if(array_key_exists($fLocalHandle,vfspluginadfsadl::$aFileHandles)){
			$oAdfs = vfspluginadfsadl::_getImageReader(vfspluginadfsadl::$aFileHandles[$fLocalHandle]['image-file']);
			$aStat = $oAdfs->getStat(vfspluginadfsadl::$aFileHandles[$fLocalHandle]['path-inside-image']);
			if(vfspluginadfsadl::$aFileHandles[$fLocalHandle]['pos']>=$aStat['size']){
				return TRUE;
			}
			return FALSE;
		}
		throw new VfsException("Invalid handle");
	}

	public static function setPos($oUser,$fLocalHandle,$iPos)
	{
		if(array_key_exists($fLocalHandle,vfspluginadfsadl::$aFileHandles)){
			vfspluginadfsadl::$aFileHandles[$fLocalHandle]['pos']=$iPos;
			return TRUE;
		}
		throw new VfsException("Invalid handle");
	}
	
	public static function read($oUser,$fLocalHandle,$iLength)
	{
		if(array_key_exists($fLocalHandle,vfspluginadfsadl::$aFileHandles)){
			$oAdfs = vfspluginadfsadl::_getImageReader(vfspluginadfsadl::$aFileHandles[$fLocalHandle]['image-file']);
			$sFileData = $oAdfs->getFile(vfspluginadfsadl::$aFileHandles[$fLocalHandle]['path-inside-image']);
			return substr($sFileData,vfspluginadfsadl::$aFileHandles[$fLocalHandle]['pos'],$iLength);
		}
		throw new VfsException("Invalid handle");
	}

	public static function write($oUser,$fLocalHandle,$sData)
	{
		logger::log("vfspluginadfsadl: Write bytes to file handle ".$fLocalHandle.LOG_DEBUG);
		throw new VfsException("Read Only FS");
	}

	public static function fsClose($oUser,$fLocalHandle)
	{
		if(array_key_exists($fLocalHandle,vfspluginadfsadl::$aFileHandles)){
			unset(vfspluginadfsadl::$aFileHandles[$fLocalHandle]);
		}
	}
}

?>
