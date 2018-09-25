<?php

namespace HomeLan\FileStore\Vfs\Plugin; 

/**
 * This file contains the adfs adl image vfs plugin
 *
 */

use HomeLan\FileStore\Vfs\Exception as VfsException;
use HomeLan\FileStore\Vfs\Vfs;
use HomeLan\FileStore\Vfs\DirectoryEntry;
use HomeLan\FileStore\Vfs\FileDescriptor;
use HomeLan\FileStore\Vfs\FilePath;
use HomeLan\Retro\Acorn\Disk\AdfsReader;
use config; 

/**
 * The AdfsAdl class acts as a vfs plugin to provide access to files stored in a adfs filing system stored in a adl image.
 * 
 *
 * @package corevfs
 * @authour John Brown <john@home-lan.co.uk>
*/
class AdfsAdl implements PluginInterface {

	protected static $aImageReaders = array();

	protected static $aFileHandles = array();

	protected static $iFileHandle = 0;

	protected static $oLogger;

	protected static $bMultiuser;

	public static function init(\Psr\Log\LoggerInterface $oLogger, bool $bMultiuser = false)
	{
		self::$oLogger = $oLogger;
		self::$bMultiuser = $bMultiuser;
	}

	public static function houseKeeping()
	{
	}

	protected  static function _setUid($oUser)
	{
		if(self::$bMultiuser){
			posix_seteuid($oUser->getUnixUid());
		}
	}
	
	protected static function _returnUid()
	{
		if(self::$bMultiuser){
			 posix_seteuid(config::getValue('system_user_id'));
		}
	}

	protected static function _getImageReader($sImageFile)
	{
		if(!array_key_exists($sImageFile,AdfsAdl::$aImageReaders)){
			AdfsAdl::$aImageReaders[$sImageFile] = new AdfsReader($sImageFile);
		}
		return AdfsAdl::$aImageReaders[$sImageFile];
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
		$oAdfs = AdfsAdl::_getImageReader($sImageFile);
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

	public static function _buildFiledescriptorFromEconetPath($oUser,FilePath $oEconetPath,$bMustExist,$bReadOnly)
	{
		$sImageFile = AdfsAdl::_getImageFile($oEconetPath->getFilePath());
		if(strlen($sImageFile)>0){
			$sPathInsideImage = AdfsAdl::_getPathInsideImage($oEconetPath->getFilePath(),$sImageFile);
			if(AdfsAdl::_checkImageFileExists($sImageFile,$sPathInsideImage)){
				$iEconetHandle = Vfs::getFreeFileHandleID($oUser);
				$iVfsHandle = AdfsAdl::$iFileHandle++;
				AdfsAdl::$aFileHandles[$iVfsHandle]=array('image-file'=>$sImageFile,'path-inside-image'=>$sPathInsideImage,'pos'=>0);
				$oAdfs =  AdfsAdl::_getImageReader($sImageFile);
				return new FileDescriptor(self::$oLogger,'AdfsAdl',$oUser,$sImageFile,$oEconetPath->getFilePath(),$iVfsHandle,$iEconetHandle,$oAdfs->isFile($sPathInsideImage),$oAdfs->isDir($sPathInsideImage));
			}
		}

		//Scan the unix dir, see of there is a diskimage in that directory to see if it need changing to a directory
		$sUnixPath = AdfsAdl::_econetToUnix($oEconetPath->getFilePath());
		if(file_exists($sUnixPath.'.adl')){
			//Disk Image found
			$iEconetHandle = Vfs::getFreeFileHandleID($oUser);
			$iVfsHandle = AdfsAdl::$iFileHandle++;
			AdfsAdl::$aFileHandles[$iVfsHandle]=array('image-file'=>$sUnixPath.'.adl','path-inside-image'=>'','pos'=>0);
			return new FileDescriptor(self::$oLogger,'AdfsAdl',$oUser,$sUnixPath.'.adl',$oEconetPath->getFilePath(),$iVfsHandle,$iEconetHandle,FALSE,TRUE);
		}
	
	}


	public static function getDirectoryListing($sEconetPath,$aDirectoryListing)
	{
		$sImageFile = AdfsAdl::_getImageFile($sEconetPath);
	
		//Produce a directory listing for file inside the image if the selected path is inside the image
		if(strlen($sImageFile)>0){
			$sPathInsideImage = AdfsAdl::_getPathInsideImage($sEconetPath,$sImageFile);
			$oAdfs = AdfsAdl::_getImageReader($sImageFile);
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
				$aDirectoryListing[$sFile] = new DirectoryEntry($sFile,$sImageFile,'AdfsAdl',$aMeta['load'],$aMeta['exec'],$aMeta['size'],$aMeta['type']=='dir' ? TRUE : FALSE ,$sEconetPath.'.'.$sFile,$aImageStat['ctime'],'-r/-r');
			}
		}
		
		//Scan the unix dir, see of there is a diskimage in that directory to see if it need changing to a directory
		$sUnixPath = AdfsAdl::_econetToUnix($sEconetPath);
		if(is_dir($sUnixPath)){
			$aFiles = scandir($sUnixPath);
			foreach($aFiles as $sFile){
				if(stripos($sFile,'.adl')!==FALSE){
					//Disk Image found
					if(!array_key_exists(substr($sFile,0,strlen($sFile)-4),$aDirectoryListing)){
						$aStat = stat($sUnixPath.DIRECTORY_SEPARATOR.$sFile);
						$aDirectoryListing[$sFile]=new DirectoryEntry(substr($sFile,0,strlen($sFile)-4),$sFile,'AdfsAdl',NULL,NULL,0,TRUE,$sEconetPath.'.'.substr($sFile,0,strlen($sFile)-4),$aStat['ctime'],'-r/-r');
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

	public static function createDirectory($oUser,FilePath $oPath)
	{
		return FALSE;
	}

	public static function deleteFile($oUser,FilePath $oEconetPath)
	{
		return FALSE;
	}

	public static function moveFile($oUser,FilePath $oEconetPathFrom,FilePath $oEconetPathTo)
	{
		return FALSE;
	}

	public static function saveFile($oUser,FilePath $oEconetPath,$sData,$iLoadAddr,$iExecAddr)
	{
	}

	public static function createFile($oUser,FilePath $oEconetPath,$iSize,$iLoadAddr,$iExecAddr)
	{

	}

	/**
	 * Get the contents of a given file
	 *
	 * @throws VfsException if the file does not exist
	*/
	public static function getFile($oUser,FilePath $oEconetPath)
	{
		$sImageFile = AdfsAdl::_getImageFile($oEconetPath->getFilePath());
		if(strlen($sImageFile)>0){
			$sPathInsideImage = AdfsAdl::_getPathInsideImage($oEconetPath->getFilePath(),$sImageFile);
			if(AdfsAdl::_checkImageFileExists($sImageFile,$sPathInsideImage)){
				$oAdfs = AdfsAdl::_getImageReader($sImageFile);
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
		if(array_key_exists($fLocalHandle,AdfsAdl::$aFileHandles)){
			return AdfsAdl::$aFileHandles[$fLocalHandle]['pos'];
		}
		throw new VfsException("Invalid handle");
	}

	public static function fsFStat($oUser,$fLocalHandle)
	{
		if(array_key_exists($fLocalHandle,AdfsAdl::$aFileHandles)){
			$oAdfs = AdfsAdl::_getImageReader(AdfsAdl::$aFileHandles[$fLocalHandle]['image-file']);
			$aStat = $oAdfs->getStat(AdfsAdl::$aFileHandles[$fLocalHandle]['path-inside-image']);
			return array('dev'=>null,'ino'=>$aStat['sector'],'size'=>$aStat['size'],'nlink'=>1);
		}
		throw new VfsException("Invalid handle");
	}

	public static function isEof($oUser,$fLocalHandle)
	{
		if(array_key_exists($fLocalHandle,AdfsAdl::$aFileHandles)){
			$oAdfs = AdfsAdl::_getImageReader(AdfsAdl::$aFileHandles[$fLocalHandle]['image-file']);
			$aStat = $oAdfs->getStat(AdfsAdl::$aFileHandles[$fLocalHandle]['path-inside-image']);
			if(AdfsAdl::$aFileHandles[$fLocalHandle]['pos']>=$aStat['size']){
				return TRUE;
			}
			return FALSE;
		}
		throw new VfsException("Invalid handle");
	}

	public static function setPos($oUser,$fLocalHandle,$iPos)
	{
		if(array_key_exists($fLocalHandle,AdfsAdl::$aFileHandles)){
			AdfsAdl::$aFileHandles[$fLocalHandle]['pos']=$iPos;
			return TRUE;
		}
		throw new VfsException("Invalid handle");
	}
	
	public static function read($oUser,$fLocalHandle,$iLength)
	{
		if(array_key_exists($fLocalHandle,AdfsAdl::$aFileHandles)){
			$oAdfs = AdfsAdl::_getImageReader(AdfsAdl::$aFileHandles[$fLocalHandle]['image-file']);
			$sFileData = $oAdfs->getFile(AdfsAdl::$aFileHandles[$fLocalHandle]['path-inside-image']);
			return substr($sFileData,AdfsAdl::$aFileHandles[$fLocalHandle]['pos'],$iLength);
		}
		throw new VfsException("Invalid handle");
	}

	public static function write($oUser,$fLocalHandle,$sData)
	{
		self::$oLogger->debug("AdfsAdl: Write bytes to file handle ".$fLocalHandle);
		throw new VfsException("Read Only FS");
	}

	public static function fsClose($oUser,$fLocalHandle)
	{
		if(array_key_exists($fLocalHandle,AdfsAdl::$aFileHandles)){
			unset(AdfsAdl::$aFileHandles[$fLocalHandle]);
		}
	}

	public static function _getAccessMode($iGid,$iUid,$iMode)
	{
		return "-r/-r";
	}
}
