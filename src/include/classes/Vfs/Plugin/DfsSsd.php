<?php

namespace HomeLan\FileStore\Vfs\Plugin; 

/**
 * This file contains the dfs ssd image vfs plugin
 *
*/
use HomeLan\FileStore\Vfs\Exception as VfsException;
use HomeLan\FileStore\Vfs\Vfs;
use HomeLan\FileStore\Vfs\DirectoryEntry;
use HomeLan\FileStore\Vfs\FileDescriptor;
use HomeLan\FileStore\Vfs\FilePath;
use HomeLan\Retro\Acorn\Disk\DfsReader;
use config; 

/**
 * The DfsSsd class acts as a vfs plugin to provide access to files stored in a dfs ssd image.
 * 
 *
 * @package corevfs
 * @authour John Brown <john@home-lan.co.uk>
*/
class DfsSsd implements PluginInterface {

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
		if(!array_key_exists($sImageFile,DfsSsd::$aImageReaders)){
			DfsSsd::$aImageReaders[$sImageFile] = new DfsReader($sImageFile);
		}
		return DfsSsd::$aImageReaders[$sImageFile];
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
		$sUnixPath = config::getValue('vfs_plugin_localdfsssd_root').DIRECTORY_SEPARATOR.$sUnixPath;

		
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
							}elseif(strtolower((string) $sFile)===strtolower($sDirPart).'.ssd'){
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
			if(is_file($sUnixPath.".ssd")){
				return $sUnixPath.".ssd";
			}elseif($sUnixPath==config::getValue('vfs_plugin_localdfsssd_root')){
				return "";
			}
			$sFilePathPart = array_pop($aUnixPath);
		}
		return "";
	}

	protected static function _getPathInsideImage($sEconetPath,$sImageFile): string
	{
		self::$oLogger->debug("Called _getPathInsideImage(".$sEconetPath.",".$sImageFile.")");
		//Trim leading $.
		$sEconetPath = substr((string) $sEconetPath,2);

		$sPathPreFix = substr((string) $sImageFile,0,strlen((string) $sImageFile)-4);
		$sPathPreFix = str_ireplace((string) config::getValue('vfs_plugin_localdfsssd_root'),'',$sPathPreFix);
		$sPathPreFix = str_ireplace(DIRECTORY_SEPARATOR,'.',ltrim($sPathPreFix,'/'));
		return ltrim(str_ireplace($sPathPreFix,'',$sEconetPath),'.');
	} 

	protected static function _checkImageFileExists($sImageFile,$sPathInsideImage): bool
	{
		$oDfs = DfsSsd::_getImageReader($sImageFile);
		$aCat = $oDfs->getCatalogue();
		$aPathInsideImage = explode('.',(string) $sPathInsideImage);
		$bFound = FALSE;
		if((is_countable($aPathInsideImage) ? count($aPathInsideImage) : 0)==2){
			if(array_key_exists($aPathInsideImage[0],$aCat)){
				if(array_key_exists($aPathInsideImage[1],$aCat[$aPathInsideImage[0]])){
					$bFound = TRUE;
				}
			}
		}
		if((is_countable($aPathInsideImage) ? count($aPathInsideImage) : 0)==1){
			if(array_key_exists($aPathInsideImage[0],$aCat['$'])){
				$bFound = TRUE;
			}
			if(array_key_exists($aPathInsideImage[0],$aCat)){
				$bFound = TRUE;
			}
		}
		return $bFound;
	}

	public static function _buildFiledescriptorFromEconetPath($oUser,FilePath $oEconetPath,$bMustExist,$bReadOnly): \HomeLan\FileStore\Vfs\FileDescriptor
	{
		$sImageFile = DfsSsd::_getImageFile($oEconetPath->getFilePath());
		if(strlen($sImageFile)>0){
			$sPathInsideImage = DfsSsd::_getPathInsideImage($oEconetPath->getFilePath(),$sImageFile);
			if(DfsSsd::_checkImageFileExists($sImageFile,$sPathInsideImage)){
				$iEconetHandle = Vfs::getFreeFileHandleID($oUser);
				$iVfsHandle = DfsSsd::$iFileHandle++;
				DfsSsd::$aFileHandles[$iVfsHandle]=['image-file'=>$sImageFile, 'path-inside-image'=>$sPathInsideImage, 'pos'=>0];
				$oDfs =  DfsSsd::_getImageReader($sImageFile);
				return new FileDescriptor(self::$oLogger,'HomeLan\FileStore\Vfs\Plugin\DfsSsd',$oUser,$sImageFile,$oEconetPath->getFilePath(),$iVfsHandle,$iEconetHandle,$oDfs->isFile($sPathInsideImage),$oDfs->isDir($sPathInsideImage));
			}
		}

		//Scan the unix dir, see of there is a diskimage in that directory to see if it need changing to a directory
		$sUnixPath = DfsSsd::_econetToUnix($oEconetPath->getFilePath());
		if(file_exists($sUnixPath.'.ssd')){
			//Disk Image found
			$iEconetHandle = Vfs::getFreeFileHandleID($oUser);
			$iVfsHandle = DfsSsd::$iFileHandle++;
			DfsSsd::$aFileHandles[$iVfsHandle]=['image-file'=>$sUnixPath.'.ssd', 'path-inside-image'=>'', 'pos'=>0];
			return new FileDescriptor(self::$oLogger,'HomeLan\FileStore\Vfs\Plugin\DfsSsd',$oUser,$sUnixPath.'.ssd',$oEconetPath->getFilePath(),$iVfsHandle,$iEconetHandle,FALSE,TRUE);
		}
		throw new VfsException("No such file");
	
	}


	public static function getDirectoryListing(string $sEconetPath,array $aDirectoryListing): array
	{
		$sImageFile = DfsSsd::_getImageFile($sEconetPath);
	
		//Produce a directory listing for file inside the image if the selected path is inside the image
		if(strlen($sImageFile)>0){
			$sPathInsideImage = DfsSsd::_getPathInsideImage($sEconetPath,$sImageFile);
			$oDfs = DfsSsd::_getImageReader($sImageFile);
			$aCat = $oDfs->getCatalogue();
			if(strlen($sPathInsideImage)>0){
				if(array_key_exists($sPathInsideImage,$aCat)){
					$aImageStat = stat($sImageFile);
					foreach($aCat[$sPathInsideImage] as $sFile=>$aMeta){
						$aDirectoryListing[$sFile] = new DirectoryEntry($sFile,$sImageFile,'HomeLan\FileStore\Vfs\Plugin\DfsSsd',$aMeta['loadaddr'],$aMeta['execaddr'],$aMeta['size'],$sEconetPath.'.'.$sFile,$aImageStat['ctime'],'-r/-r', FALSE);
					}
				}
			}else{
				$aImageStat = stat($sImageFile);
				foreach($aCat['$'] as $sFile=>$aMeta){
					$aDirectoryListing[$sFile] = new DirectoryEntry($sFile,$sImageFile,'HomeLan\FileStore\Vfs\Plugin\DfsSsd',$aMeta['loadaddr'],$aMeta['execaddr'],$aMeta['size'],$sEconetPath.'.'.$sFile,$aImageStat['ctime'],'-r/-r', FALSE);
				}
				foreach(array_keys($aCat) as $sDir){
					if($sDir!='$'){
						$aDirectoryListing[$sDir] = new DirectoryEntry($sDir,$sImageFile,'HomeLan\FileStore\Vfs\Plugin\DfsSsd',NULL,NULL,0,$sEconetPath.'.'.$sDir,$aImageStat['ctime'],'-r/-r', TRUE);
					}
				}
			}
		}
		
		//Scan the unix dir, see of there is a diskimage in that directory to see if it need changing to a directory
		$sUnixPath = DfsSsd::_econetToUnix($sEconetPath);
		if(is_dir($sUnixPath)){
			$aFiles = scandir($sUnixPath);
			foreach($aFiles as $sFile){
				if(stripos((string) $sFile,'.ssd')!==FALSE){
					//Disk Image found
					if(!array_key_exists(substr((string) $sFile,0,strlen((string) $sFile)-4),$aDirectoryListing)){
						$aStat = stat($sUnixPath.DIRECTORY_SEPARATOR.$sFile);
						$aDirectoryListing[$sFile]=new DirectoryEntry(substr((string) $sFile,0,strlen((string) $sFile)-4),$sFile,'HomeLan\FileStore\Vfs\Plugin\DfsSsd',NULL,NULL,0,$sEconetPath.'.'.substr((string) $sFile,0,strlen((string) $sFile)-4),$aStat['ctime'],'-r/-r', TRUE);
					}
				}
			}
		}


		//Rip out and .ssd files from the list
		$aReturn = [];
		foreach($aDirectoryListing as $sFile => $oFile){
			if(stripos($sFile,"\/ssd")===FALSE){
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
		$sImageFile = DfsSsd::_getImageFile($oEconetPath->getFilePath());
		if(strlen($sImageFile)>0){
			$sPathInsideImage = DfsSsd::_getPathInsideImage($oEconetPath->getFilePath(),$sImageFile);
			self::$oLogger->debug("DfsSsd: Checking for file ".$sPathInsideImage." in ".$sImageFile);
			if(DfsSsd::_checkImageFileExists($sImageFile,$sPathInsideImage)){
				$oDfs = DfsSsd::_getImageReader($sImageFile);
				return $oDfs->getFile($sPathInsideImage);
			}
		}
		throw new VfsException("No such file");
	}

	public static function setMeta(string $sEconetPath,$iLoad,$iExec,int $iAccess): void
	{
	}

	public static function fsFtell($oUser,$fLocalHandle)
	{
		if(array_key_exists($fLocalHandle,DfsSsd::$aFileHandles)){
			return DfsSsd::$aFileHandles[$fLocalHandle]['pos'];
		}
		throw new VfsException("Invalid handle");
	}

	public static function fsFStat($oUser,$fLocalHandle): array
	{
		if(array_key_exists($fLocalHandle,DfsSsd::$aFileHandles)){
			$oDfs = DfsSsd::_getImageReader(DfsSsd::$aFileHandles[$fLocalHandle]['image-file']);
			$aStat = $oDfs->getStat(DfsSsd::$aFileHandles[$fLocalHandle]['path-inside-image']);
			return ['dev'=>null, 'ino'=>$aStat['sector'], 'size'=>$aStat['size'], 'nlink'=>1];
		}
		throw new VfsException("Invalid handle");
	}

	public static function isEof($oUser,$fLocalHandle): bool
	{
		if(array_key_exists($fLocalHandle,DfsSsd::$aFileHandles)){
			$oDfs = DfsSsd::_getImageReader(DfsSsd::$aFileHandles[$fLocalHandle]['image-file']);
			$aStat = $oDfs->getStat(DfsSsd::$aFileHandles[$fLocalHandle]['path-inside-image']);
			if(DfsSsd::$aFileHandles[$fLocalHandle]['pos']>=$aStat['size']){
				return TRUE;
			}
			return FALSE;
		}
		throw new VfsException("Invalid handle");
	}

	public static function setPos($oUser,$fLocalHandle,$iPos): bool
	{
		if(array_key_exists($fLocalHandle,DfsSsd::$aFileHandles)){
			DfsSsd::$aFileHandles[$fLocalHandle]['pos']=$iPos;
			return TRUE;
		}
		throw new VfsException("Invalid handle");
	}
	
	public static function read($oUser,$fLocalHandle,$iLength): string
	{
		if(array_key_exists($fLocalHandle,DfsSsd::$aFileHandles)){
			$oDfs = DfsSsd::_getImageReader(DfsSsd::$aFileHandles[$fLocalHandle]['image-file']);
			$sFileData = $oDfs->getFile(DfsSsd::$aFileHandles[$fLocalHandle]['path-inside-image']);
			return substr((string) $sFileData,DfsSsd::$aFileHandles[$fLocalHandle]['pos'],$iLength);
		}
		throw new VfsException("Invalid handle");
	}

	public static function write($oUser,$fLocalHandle,$sData): void
	{
		self::$oLogger->debug("DfsSsd: Write bytes to file handle ".$fLocalHandle);
	}

	public static function fsClose($oUser,$fLocalHandle): void
	{
		if(array_key_exists($fLocalHandle,DfsSsd::$aFileHandles)){
			unset(DfsSsd::$aFileHandles[$fLocalHandle]);
		}
	}

	public static function _getAccessMode($iGid,$iUid,$iMode): string
	{
		return "-r/-r";
	}

}
