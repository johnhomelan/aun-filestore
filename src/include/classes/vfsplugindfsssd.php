<?php
/**
 * This file contains the dfs ssd image vfs plugin
 *
*/

/**
 * The vfsplugindfsssd class acts as a vfs plugin to provide access to files stored in a dfs ssd image.
 * 
 *
 * @package corevfs
 * @authour John Brown <john@home-lan.co.uk>
*/
class vfsplugindfsssd implements vfsplugininterface {

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
		if(!array_key_exists($sImageFile,vfsplugindfsssd::$aImageReaders)){
			vfsplugindfsssd::$aImageReaders[$sImageFile] = new dfsreader($sImageFile);
		}
		return vfsplugindfsssd::$aImageReaders[$sImageFile];
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
		$sUnixPath = config::getValue('vfs_plugin_localdfsssd_root').DIRECTORY_SEPARATOR.$sUnixPath;

		
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
							}elseif(strtolower($sFile)===strtolower($sDirPart).'.ssd'){
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
			if(is_file($sUnixPath.".ssd")){
				return $sUnixPath.".ssd";
			}elseif($sUnixPath==config::getValue('vfs_plugin_localdfsssd_root')){
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
		$sPathPreFix = str_ireplace(config::getValue('vfs_plugin_localdfsssd_root'),'',$sPathPreFix);
		$sPathPreFix = str_ireplace(DIRECTORY_SEPARATOR,'.',ltrim($sPathPreFix,'/'));
		return ltrim(str_ireplace($sPathPreFix,'',$sEconetPath),'.');
	} 

	protected static function _checkImageFileExists($sImageFile,$sPathInsideImage)
	{
		$oDfs = vfsplugindfsssd::_getImageReader($sImageFile);
		$aCat = $oDfs->getCatalogue();
		$aPathInsideImage = explode('.',$sPathInsideImage);
		$bFound = FALSE;
		if(count($sPathInsideImage)==2){
			if(array_key_exists($aPathInsideImage[0],$aCat)){
				if(array_key_exists($aPathInsideImage[1],$aCat[$aPathInsideImage[0]])){
					$bFound = TRUE;
				}
			}
		}
		if(count($sPathInsideImage)==1){
			if(array_key_exists($aPathInsideImage[0],$aCat['$'])){
				$bFound = TRUE;
			}
			if(array_key_exists($aPathInsideImage[0],$aCat)){
				$bFound = TRUE;
			}
		}

		return $bFound;
	}

	public static function _buildFiledescriptorFromEconetPath($oUser,$sCsd,$sEconetPath,$bMustExist,$bReadOnly)
	{
		if(strpos($sEconetPath,'$')===0){
			//Absolute Path
			$sImageFile = vfsplugindfsssd::_getImageFile($sEconetPath);
		}else{
			//Relative path
			$sEconetPath = $sCsd.'.'.$sEconetPath;
			$sImageFile = vfsplugindfsssd::_getImageFile($sEconetPath);
		}
		if(strlen($sImageFile)>0){
			$sPathInsideImage = vfsplugindfsssd::_getPathInsideImage($sEconetPath,$sImageFile);
			if(vfsplugindfsssd::_checkImageFileExists($sImageFile,$sPathInsideImage)){
				$iEconetHandle = vfs::getFreeFileHandleID($oUser);
				$iVfsHandle = vfsplugindfsssd::$iFileHandle++;
				vfsplugindfsssd::$aFileHandles[$iVfsHandle]=array('image-file'=>$sImageFile,'path-inside-image'=>$sPathInsideImage,'pos'=>0);
				$oDfs =  vfsplugindfsssd::_getImageReader($sImageFile);
				return new filedescriptor('vfsplugindfsssd',$oUser,$sImageFile,$sEconetPath,$iVfsHandle,$iEconetHandle,$oDfs->isFile($sPathInsideImage),$oDfs->isDir($sPathInsideImage));
			}
		}

		//Scan the unix dir, see of there is a diskimage in that directory to see if it need changing to a directory
		$sUnixPath = vfsplugindfsssd::_econetToUnix($sEconetPath);
		if(file_exists($sUnixPath.'.ssd')){
			//Disk Image found
			$iEconetHandle = vfs::getFreeFileHandleID($oUser);
			$iVfsHandle = vfsplugindfsssd::$iFileHandle++;
			vfsplugindfsssd::$aFileHandles[$iVfsHandle]=array('image-file'=>$sUnixPath.'.ssd','path-inside-image'=>'','pos'=>0);
			return new filedescriptor('vfsplugindfsssd',$oUser,$sUnixPath.'.ssd',$sEconetPath,$iVfsHandle,$iEconetHandle,FALSE,TRUE);
		}
	
	}


	public static function getDirectoryListing($sEconetPath,$aDirectoryListing)
	{
		$sImageFile = vfsplugindfsssd::_getImageFile($sEconetPath);
	
		//Produce a directory listing for file inside the image if the selected path is inside the image
		if(strlen($sImageFile)>0){
			$sPathInsideImage = vfsplugindfsssd::_getPathInsideImage($sEconetPath,$sImageFile);
			$oDfs = vfsplugindfsssd::_getImageReader($sImageFile);
			$aCat = $oDfs->getCatalogue();
			if(strlen($sPathInsideImage)>0){
				if(array_key_exists($sPathInsideImage,$aCat)){
					$aImageStat = stat($sImageFile);
					foreach($aCat[$sPathInsideImage] as $sFile=>$aMeta){
						$aDirectoryListing[$sFile] = new directoryentry($sFile,$sImageFile,'vfsplugindfsssd',$aMeta['loadaddr'],$aMeta['execaddr'],$aMeta['size'],FALSE,$sEconetPath.'.'.$sFile,$aImageStat['ctime'],'-r/-r');
					}
				}
			}else{
				$aImageStat = stat($sImageFile);
				foreach($aCat['$'] as $sFile=>$aMeta){
					$aDirectoryListing[$sFile] = new directoryentry($sFile,$sImageFile,'vfsplugindfsssd',$aMeta['loadaddr'],$aMeta['execaddr'],$aMeta['size'],FALSE,$sEconetPath.'.'.$sFile,$aImageStat['ctime'],'-r/-r');
				}
				foreach(array_keys($aCat) as $sDir){
					if($sDir!='$'){
						$aDirectoryListing[$sDir] = new directoryentry($sDir,$sImageFile,'vfsplugindfsssd',NULL,NULL,0,TRUE,$sEconetPath.'.'.$sDir,$aImageStat['ctime'],'-r/-r');
					}
				}
			}
		}
		
		//Scan the unix dir, see of there is a diskimage in that directory to see if it need changing to a directory
		$sUnixPath = vfsplugindfsssd::_econetToUnix($sEconetPath);
		if(is_dir($sUnixPath)){
			$aFiles = scandir($sUnixPath);
			foreach($aFiles as $sFile){
				if(stripos($sFile,'.ssd')!==FALSE){
					//Disk Image found
					if(!array_key_exists(substr($sFile,0,strlen($sFile)-4),$aDirectoryListing)){
						$aStat = stat($sUnixPath.DIRECTORY_SEPARATOR.$sFile);
						$aDirectoryListing[$sFile]=new directoryentry(substr($sFile,0,strlen($sFile)-4),$sFile,'vfsplugindfsssd',NULL,NULL,0,TRUE,$sEconetPath.'.'.substr($sFile,0,strlen($sFile)-4),$aStat['ctime'],'-r/-r');
					}
				}
			}
		}


		//Rip out and .ssd files from the list
		$aReturn = array();
		foreach($aDirectoryListing as $sFile => $oFile){
			if(stripos($sFile,"\/ssd")===FALSE){
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
			$sImageFile = vfsplugindfsssd::_getImageFile($sEconetPath);
		}else{
			//Relative path
			$sEconetPath = trim($sCsd,'.').'.'.$sEconetPath;
			$sImageFile = vfsplugindfsssd::_getImageFile($sEconetPath);
		}
		if(strlen($sImageFile)>0){
			$sPathInsideImage = vfsplugindfsssd::_getPathInsideImage($sEconetPath,$sImageFile);
			if(vfsplugindfsssd::_checkImageFileExists($sImageFile,$sPathInsideImage)){
				$oDfs = vfsplugindfsssd::_getImageReader($sImageFile);
				return $oDfs->getFile($sPathInsideImage);
			}
		}
		throw new VfsException("No such file");
	}

	public static function setMeta($sEconetPath,$iLoad,$iExec,$iAccess)
	{
	}

	public static function fsFtell($oUser,$fLocalHandle)
	{
		if(array_key_exists($fLocalHandle,vfsplugindfsssd::$aFileHandles)){
			return vfsplugindfsssd::$aFileHandles[$fLocalHandle]['pos'];
		}
		throw new VfsException("Invalid handle");
	}

	public static function fsFStat($oUser,$fLocalHandle)
	{
		if(array_key_exists($fLocalHandle,vfsplugindfsssd::$aFileHandles)){
			$oDfs = vfsplugindfsssd::_getImageReader(vfsplugindfsssd::$aFileHandles[$fLocalHandle]['image-file']);
			$aStat = $oDfs->getStat(vfsplugindfsssd::$aFileHandles[$fLocalHandle]['path-inside-image']);
			return array('dev'=>null,'ino'=>$aStat['sector'],'size'=>$aStat['size'],'nlink'=>1);
		}
		throw new VfsException("Invalid handle");
	}

	public static function isEof($oUser,$fLocalHandle)
	{
		if(array_key_exists($fLocalHandle,vfsplugindfsssd::$aFileHandles)){
			$oDfs = vfsplugindfsssd::_getImageReader(vfsplugindfsssd::$aFileHandles[$fLocalHandle]['image-file']);
			$aStat = $oDfs->getStat(vfsplugindfsssd::$aFileHandles[$fLocalHandle]['path-inside-image']);
			if(vfsplugindfsssd::$aFileHandles[$fLocalHandle]['pos']>=$aStat['size']){
				return TRUE;
			}
			return FALSE;
		}
		throw new VfsException("Invalid handle");
	}

	public static function setPos($oUser,$fLocalHandle,$iPos)
	{
		if(array_key_exists($fLocalHandle,vfsplugindfsssd::$aFileHandles)){
			vfsplugindfsssd::$aFileHandles[$fLocalHandle]['pos']=$iPos;
			return TRUE;
		}
		throw new VfsException("Invalid handle");
	}
	
	public static function read($oUser,$fLocalHandle,$iLength)
	{
		if(array_key_exists($fLocalHandle,vfsplugindfsssd::$aFileHandles)){
			$oDfs = vfsplugindfsssd::_getImageReader(vfsplugindfsssd::$aFileHandles[$fLocalHandle]['image-file']);
			$sFileData = $oDfs->getFile(vfsplugindfsssd::$aFileHandles[$fLocalHandle]['path-inside-image']);
			return substr($sFileData,vfsplugindfsssd::$aFileHandles[$fLocalHandle]['pos'],$iLength);
		}
		throw new VfsException("Invalid handle");
	}

	public static function write($oUser,$fLocalHandle,$sData)
	{
		logger::log("vfsplugindfsssd: Write bytes to file handle ".$fLocalHandle.LOG_DEBUG);
	}

	public static function fsClose($oUser,$fLocalHandle)
	{
		if(array_key_exists($fLocalHandle,vfsplugindfsssd::$aFileHandles)){
			unset(vfsplugindfsssd::$aFileHandles[$fLocalHandle]);
		}
	}

	public static function _getAccessMode($iGid,$iUid,$iMode)
	{
		return "-r/-r";
	}

}
