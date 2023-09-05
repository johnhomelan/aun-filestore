<?php

namespace HomeLan\FileStore\Vfs\Plugin; 

/**
 * This file contains the adfs hardisk image vfs plugin
 *
 */

use HomeLan\FileStore\Vfs\Exception as VfsException;
use HomeLan\FileStore\Vfs\Vfs;
use HomeLan\FileStore\Vfs\DirectoryEntry;
use HomeLan\FileStore\Vfs\FileDescriptor;
use HomeLan\FileStore\Vfs\FilePath;
use HomeLan\Retro\Acorn\Disk\AdfsHDReader;
use config; 

/**
 * The AdfsHD class acts as a vfs plugin to provide access to files stored in a adfs filing system stored in a hardisk image file (e.g. scsi0.dat).
 * 
 * It will cliam any files matching the pattern scsi([0-9])+.dat
 *
 * @package corevfs
 * @authour John Brown <john@home-lan.co.uk>
*/
class AdfsHD implements PluginInterface {

	protected static $aImageReaders = [];

	protected static $aFileHandles = [];

	protected static $iFileHandle = 0;

	protected static $oLogger;

	protected static $bMultiuser;

	public static function init(\Psr\Log\LoggerInterface $oLogger, bool $bMultiuser = false): void
	{
		self::$oLogger = $oLogger;
		self::$bMultiuser = $bMultiuser;
	}

	public static function houseKeeping(): void
	{
	}

	protected  static function _setUid($oUser): void
	{
		if(self::$bMultiuser){
			posix_seteuid($oUser->getUnixUid());
		}
	}
	
	protected static function _returnUid(): void
	{
		if(self::$bMultiuser){
			 posix_seteuid(config::getValue('system_user_id'));
		}
	}

	protected static function _getImageReader($sImageFile)
	{
		if(!array_key_exists($sImageFile,AdfsHD::$aImageReaders)){
			AdfsHD::$aImageReaders[$sImageFile] = new AdfsHDReader($sImageFile);
		}
		return AdfsHD::$aImageReaders[$sImageFile];
	}

	protected static function _econetToUnix($sEconetPath): string
	{
		//Trim leading $.
		$sEconetPath = substr((string) $sEconetPath,2);
		$aFileParts = explode('.',$sEconetPath);
		$sUnixPath = "";
		foreach($aFileParts as $sPart){
			$sUnixPath = $sUnixPath.str_replace(DIRECTORY_SEPARATOR ,'.',$sPart).DIRECTORY_SEPARATOR;
		}
		$sUnixPath = trim($sUnixPath,DIRECTORY_SEPARATOR);
		$sUnixPath = config::getValue('vfs_plugin_localadfshd_root').DIRECTORY_SEPARATOR.$sUnixPath;

		
		if(!file_exists($sUnixPath)){
			//The file does not exists see if a case insenstive version of this files exists
			$sDir = dirname($sUnixPath);
			$sTestFileName = strtolower(basename($sUnixPath));
			if(is_dir($sDir)){
				//Just test if dir exists in the correct case and only the file name is case incorrect
				$aFiles = scandir($sDir);
				foreach($aFiles as $sFile){
					if(strtolower((string) $sFile)==$sTestFileName){
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
							if(strtolower((string) $sFile)==strtolower($sDirPart)){
								$iMatches++;
								$sNewDirPath .= DIRECTORY_SEPARATOR.$sFile;
								continue;
							}elseif(strtolower((string) $sFile)===strtolower($sDirPart).'.dat'){
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

	protected static function _getImageFile($sEconetPath): string
	{
		$sUnixPath = self::_econetToUnix($sEconetPath);
		$aUnixPath = explode(DIRECTORY_SEPARATOR,$sUnixPath);
		while(count($aUnixPath)>0){
			$sUnixPath = implode(DIRECTORY_SEPARATOR,$aUnixPath);
			if(is_file($sUnixPath.".dat")){
				return $sUnixPath.".dat";
			}elseif($sUnixPath==config::getValue('vfs_plugin_localadfshd_root')){
				return '';
			}
			$sFilePathPart = array_pop($aUnixPath);
		}
		return '';
	}

	protected static function _getPathInsideImage($sEconetPath,$sImageFile): string
	{
		//Trim leading $.
		$sEconetPath = substr((string) $sEconetPath,2);

		$sPathPreFix = substr((string) $sImageFile,0,strlen((string) $sImageFile)-4);
		$sPathPreFix = str_ireplace((string) config::getValue('vfs_plugin_localadfshd_root'),'',$sPathPreFix);
		$sPathPreFix = str_ireplace(DIRECTORY_SEPARATOR,'.',ltrim($sPathPreFix,'/'));
		return ltrim(str_ireplace($sPathPreFix,'',$sEconetPath),'.');
	} 

	protected static function _checkImageFileExists($sImageFile,$sPathInsideImage): bool
	{
		$oAdfs = AdfsHD::_getImageReader($sImageFile);
		$aCat = $oAdfs->getCatalogue();
		$aPathInsideImage = explode('.',(string) $sPathInsideImage);
		$bFound = FALSE;
		$iCount = 0;
		foreach($aPathInsideImage as $sPathPart){
			$aKeys = array_keys($aCat);
			foreach($aKeys as $sKey){
				if(strtoupper($sKey)==strtoupper($sPathPart)){
					$iCount++;
					if($aCat[$sKey]['type']=='dir'){
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

	public static function _buildFiledescriptorFromEconetPath($oUser,FilePath $oEconetPath,$bMustExist,$bReadOnly): \HomeLan\FileStore\Vfs\FileDescriptor
	{
		$sImageFile = AdfsHD::_getImageFile($oEconetPath->getFilePath());
		if(strlen($sImageFile)>0){
			$sPathInsideImage = AdfsHD::_getPathInsideImage($oEconetPath->getFilePath(),$sImageFile);
			if(AdfsHD::_checkImageFileExists($sImageFile,$sPathInsideImage)){
				$iEconetHandle = Vfs::getFreeFileHandleID($oUser);
				$iVfsHandle = AdfsHD::$iFileHandle++;
				AdfsHD::$aFileHandles[$iVfsHandle]=['image-file'=>$sImageFile, 'path-inside-image'=>$sPathInsideImage, 'pos'=>0];
				$oAdfs =  AdfsHD::_getImageReader($sImageFile);
				return new FileDescriptor(self::$oLogger,'AdfsHD',$oUser,$sImageFile,$oEconetPath->getFilePath(),$iVfsHandle,$iEconetHandle,$oAdfs->isFile($sPathInsideImage),$oAdfs->isDir($sPathInsideImage));
			}
		}

		//Scan the unix dir, see of there is a diskimage in that directory to see if it need changing to a directory
		$sUnixPath = AdfsHD::_econetToUnix($oEconetPath->getFilePath());
		if(file_exists($sUnixPath.'.dat')){
			//Disk Image found
			$iEconetHandle = Vfs::getFreeFileHandleID($oUser);
			$iVfsHandle = AdfsHD::$iFileHandle++;
			AdfsHD::$aFileHandles[$iVfsHandle]=['image-file'=>$sUnixPath.'.dat', 'path-inside-image'=>'', 'pos'=>0];
			return new FileDescriptor(self::$oLogger,'AdfsHD',$oUser,$sUnixPath.'.dat',$oEconetPath->getFilePath(),$iVfsHandle,$iEconetHandle,FALSE,TRUE);
		}
		throw new VfsException("No such file");
			
	}


	public static function getDirectoryListing(string $sEconetPath,array $aDirectoryListing): array
	{
		$sImageFile = AdfsHD::_getImageFile($sEconetPath);
	
		//Produce a directory listing for file inside the image if the selected path is inside the image
		if(strlen($sImageFile)>0){
			$sPathInsideImage = AdfsHD::_getPathInsideImage($sEconetPath,$sImageFile);
			$oAdfs = AdfsHD::_getImageReader($sImageFile);
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
				$aDirectoryListing[$sFile] = new DirectoryEntry($sFile,$sImageFile,'AdfsHD',$aMeta['load'],$aMeta['exec'],$aMeta['size'] ,$sEconetPath.'.'.$sFile,$aImageStat['ctime'],'-r/-r', $aMeta['type']=='dir' ? TRUE : FALSE);
			}
		}
		
		//Scan the unix dir, see of there is a diskimage in that directory to see if it need changing to a directory
		$sUnixPath = AdfsHD::_econetToUnix($sEconetPath);
		if(is_dir($sUnixPath)){
			$aFiles = scandir($sUnixPath);
			foreach($aFiles as $sFile){
				if(stripos((string) $sFile,'.dat')!==FALSE){
					//Disk Image found
					if(!array_key_exists(substr((string) $sFile,0,strlen((string) $sFile)-4),$aDirectoryListing)){
						$aStat = stat($sUnixPath.DIRECTORY_SEPARATOR.$sFile);
						$aDirectoryListing[$sFile]=new DirectoryEntry(substr((string) $sFile,0,strlen((string) $sFile)-4),$sFile,'AdfsHD',NULL,NULL,0,$sEconetPath.'.'.substr((string) $sFile,0,strlen((string) $sFile)-4),$aStat['ctime'],'-r/-r', TRUE);
					}
				}
			}
		}


		//Rip out and .dat files from the list
		$aReturn = [];
		foreach($aDirectoryListing as $sFile => $oFile){
			if(stripos($sFile,"\/dat")===FALSE){
				$aReturn[$sFile]=$oFile;
			}
		}
		return $aReturn;
	}

	public static function createDirectory($oUser,FilePath $oPath): bool
	{
		return FALSE;
	}

	public static function deleteFile($oUser,FilePath $oEconetPath): bool
	{
		return FALSE;
	}

	public static function moveFile($oUser,FilePath $oEconetPathFrom,FilePath $oEconetPathTo): bool
	{
		return FALSE;
	}

	public static function saveFile($oUser,FilePath $oEconetPath,string $sData,int $iLoadAddr,int $iExecAddr): void
	{
	}

	public static function createFile($oUser,FilePath $oEconetPath,int $iSize,int $iLoadAddr,int $iExecAddr): void
	{

	}

	/**
	 * Get the contents of a given file
	 *
	 * @throws VfsException if the file does not exist
	*/
	public static function getFile($oUser,FilePath $oEconetPath): string
	{
		$sImageFile = AdfsHD::_getImageFile($oEconetPath->getFilePath());
		if(strlen($sImageFile)>0){
			$sPathInsideImage = AdfsHD::_getPathInsideImage($oEconetPath->getFilePath(),$sImageFile);
			if(AdfsHD::_checkImageFileExists($sImageFile,$sPathInsideImage)){
				$oAdfs = AdfsHD::_getImageReader($sImageFile);
				return $oAdfs->getFile($sPathInsideImage);
			}
		}
		throw new VfsException("No such file");
	}

	public static function setMeta(string $sEconetPath,$iLoad,$iExec,int $iAccess): void
	{
	}

	public static function fsFtell($oUser,$fLocalHandle)
	{
		if(array_key_exists($fLocalHandle,AdfsHD::$aFileHandles)){
			return AdfsHD::$aFileHandles[$fLocalHandle]['pos'];
		}
		throw new VfsException("Invalid handle");
	}

	public static function fsFStat($oUser,$fLocalHandle): array
	{
		if(array_key_exists($fLocalHandle,AdfsHD::$aFileHandles)){
			$oAdfs = AdfsHD::_getImageReader(AdfsHD::$aFileHandles[$fLocalHandle]['image-file']);
			$aStat = $oAdfs->getStat(AdfsHD::$aFileHandles[$fLocalHandle]['path-inside-image']);
			return ['dev'=>null, 'ino'=>$aStat['sector'], 'size'=>$aStat['size'], 'nlink'=>1];
		}
		throw new VfsException("Invalid handle");
	}

	public static function isEof($oUser,$fLocalHandle): bool
	{
		if(array_key_exists($fLocalHandle,AdfsHD::$aFileHandles)){
			$oAdfs = AdfsHD::_getImageReader(AdfsHD::$aFileHandles[$fLocalHandle]['image-file']);
			$aStat = $oAdfs->getStat(AdfsHD::$aFileHandles[$fLocalHandle]['path-inside-image']);
			if(AdfsHD::$aFileHandles[$fLocalHandle]['pos']>=$aStat['size']){
				return TRUE;
			}
			return FALSE;
		}
		throw new VfsException("Invalid handle");
	}

	public static function setPos($oUser,$fLocalHandle,$iPos): bool
	{
		if(array_key_exists($fLocalHandle,AdfsHD::$aFileHandles)){
			AdfsHD::$aFileHandles[$fLocalHandle]['pos']=$iPos;
			return TRUE;
		}
		throw new VfsException("Invalid handle");
	}
	
	public static function read($oUser,$fLocalHandle,$iLength): string
	{
		if(array_key_exists($fLocalHandle,AdfsHD::$aFileHandles)){
			$oAdfs = AdfsHD::_getImageReader(AdfsHD::$aFileHandles[$fLocalHandle]['image-file']);
			$sFileData = $oAdfs->getFile(AdfsHD::$aFileHandles[$fLocalHandle]['path-inside-image']);
			return substr((string) $sFileData,AdfsHD::$aFileHandles[$fLocalHandle]['pos'],$iLength);
		}
		throw new VfsException("Invalid handle");
	}

	public static function write($oUser,$fLocalHandle,$sData): never
	{
		self::$oLogger->debug("AdfsHD: Write bytes to file handle ".$fLocalHandle);
		throw new VfsException("Read Only FS");
	}

	public static function fsClose($oUser,$fLocalHandle): void
	{
		if(array_key_exists($fLocalHandle,AdfsHD::$aFileHandles)){
			unset(AdfsHD::$aFileHandles[$fLocalHandle]);
		}
	}

	public static function _getAccessMode($iGid,$iUid,$iMode): string
	{
		return "-r/-r";
	}
}
