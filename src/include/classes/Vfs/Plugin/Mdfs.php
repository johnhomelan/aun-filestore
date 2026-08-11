<?php

namespace HomeLan\FileStore\Vfs\Plugin;

/**
 * This file contains the mdfs/hdfs disk image vfs plugin
 *
 */

use HomeLan\FileStore\Vfs\Exception as VfsException;
use HomeLan\FileStore\Vfs\Vfs;
use HomeLan\FileStore\Vfs\DirectoryEntry;
use HomeLan\FileStore\Vfs\FileDescriptor;
use HomeLan\FileStore\Vfs\FilePath;
use HomeLan\Retro\Acorn\Disk\MdfsReader;
use HomeLan\Retro\Acorn\Disk\MdfsWriter;
use config;

/**
 * The Mdfs class acts as a vfs plugin to provide access to files stored in SJ Research MDFS
 * floppy/hard-disk images, and in HDFS hard-disk images.
 *
 * It will claim any files matching the pattern *.mdfs (MDFS floppy or hard-disk images) or
 * *.hdfs (HDFS hard-disk images), presenting each image as a directory in the Econet VFS.
 *
 * The plugin is read-only by default. Setting vfs_plugin_mdfs_write_enabled makes it read/write,
 * in the same way the write_enabled flag on the S3 vfs plugin does.
 *
 * @package corevfs
 * @author John Brown <john@home-lan.co.uk>
*/
class Mdfs implements PluginInterface {

	protected const EXTENSIONS = ['mdfs', 'hdfs'];

	protected static array $aImageReaders = [];

	protected static array $aFileHandles = [];

	protected static int $iFileHandle = 0;

	protected static $oLogger;

	protected static $bMultiuser;

	protected static bool $bWriteEnabled = false;

	public static function init(\Psr\Log\LoggerInterface $oLogger, bool $bMultiuser = false): void
	{
		self::$oLogger = $oLogger;
		self::$bMultiuser = $bMultiuser;
		self::$bWriteEnabled = (bool) config::getValue('vfs_plugin_mdfs_write_enabled');
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

	/**
	 * Injects a reader/writer instance for a given image file, bypassing the normal
	 * MdfsReader/MdfsWriter construction. Used by unit tests to mock out the
	 * homelan/mdfs-disk-reader classes.
	 */
	public static function setImageReader(string $sImageFile, object $oReader): void
	{
		self::$aImageReaders[$sImageFile] = $oReader;
	}

	/**
	 * Resets all static state (used in tests).
	 */
	public static function reset(): void
	{
		self::$aImageReaders = [];
		self::$aFileHandles = [];
		self::$iFileHandle = 0;
		self::$bWriteEnabled = false;
	}

	protected static function _isHdfs(string $sImageFile): bool
	{
		return strtolower(substr($sImageFile,-5))==='.hdfs';
	}

	protected static function _getImageReader($sImageFile)
	{
		if(array_key_exists($sImageFile,self::$aImageReaders)){
			return self::$aImageReaders[$sImageFile];
		}
		if(self::$bWriteEnabled){
			$oReader = self::_isHdfs($sImageFile) ? MdfsWriter::createHdfs($sImageFile) : new MdfsWriter($sImageFile);
		}else{
			$oReader = self::_isHdfs($sImageFile) ? MdfsReader::createHdfs($sImageFile) : new MdfsReader($sImageFile);
		}
		self::$aImageReaders[$sImageFile] = $oReader;
		return $oReader;
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
		$sUnixPath = config::getValue('vfs_plugin_mdfs_root').DIRECTORY_SEPARATOR.$sUnixPath;


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
				$aDirParts = array_values(array_filter(explode(DIRECTORY_SEPARATOR,$sUnixPath),fn($sPart) => $sPart!==''));
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
							}elseif(self::_matchesImage((string) $sFile,$sDirPart)){
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

	/**
	 * True if $sFile is $sName with a .mdfs or .hdfs extension (case-insensitive).
	 */
	protected static function _matchesImage(string $sFile,string $sName): bool
	{
		foreach(self::EXTENSIONS as $sExt){
			if(strtolower($sFile)===strtolower($sName).'.'.$sExt){
				return TRUE;
			}
		}
		return FALSE;
	}

	protected static function _getImageFile($sEconetPath): string
	{
		$sUnixPath = self::_econetToUnix($sEconetPath);
		$aUnixPath = explode(DIRECTORY_SEPARATOR,$sUnixPath);
		while(count($aUnixPath)>0){
			$sUnixPath = implode(DIRECTORY_SEPARATOR,$aUnixPath);
			foreach(self::EXTENSIONS as $sExt){
				if(is_file($sUnixPath.'.'.$sExt)){
					return $sUnixPath.'.'.$sExt;
				}
			}
			if($sUnixPath==config::getValue('vfs_plugin_mdfs_root')){
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

		//Both supported extensions (.mdfs / .hdfs) are 5 characters including the dot
		$sPathPreFix = substr((string) $sImageFile,0,strlen((string) $sImageFile)-5);
		$sPathPreFix = str_ireplace((string) config::getValue('vfs_plugin_mdfs_root'),'',$sPathPreFix);
		$sPathPreFix = str_ireplace(DIRECTORY_SEPARATOR,'.',ltrim($sPathPreFix,'/'));
		return ltrim(str_ireplace($sPathPreFix,'',$sEconetPath),'.');
	}

	/**
	 * Walks the image catalogue for the given dot-separated path, returning the
	 * matching entry (as returned by MdfsReader::getCatalogue()) or null.
	 */
	protected static function _findCatalogueEntry($oMdfs,string $sPathInsideImage): ?array
	{
		if($sPathInsideImage===''){
			return null;
		}
		$aCat = $oMdfs->getCatalogue();
		$aPathInsideImage = explode('.',$sPathInsideImage);
		$aEntry = null;
		foreach($aPathInsideImage as $sPathPart){
			$sFoundKey = null;
			foreach(array_keys($aCat) as $sKey){
				if(strtoupper($sKey)==strtoupper($sPathPart)){
					$sFoundKey = $sKey;
					break;
				}
			}
			if(is_null($sFoundKey)){
				return null;
			}
			$aEntry = $aCat[$sFoundKey];
			if($aEntry['type']=='dir'){
				$aCat = $aEntry['dir'] ?? [];
			}
		}
		return $aEntry;
	}

	public static function _buildFiledescriptorFromEconetPath($oUser,FilePath $oEconetPath,$bMustExist,$bReadOnly): \HomeLan\FileStore\Vfs\FileDescriptor
	{
		$sImageFile = self::_getImageFile($oEconetPath->getFilePath());
		if(strlen($sImageFile)>0){
			$sPathInsideImage = self::_getPathInsideImage($oEconetPath->getFilePath(),$sImageFile);

			if($sPathInsideImage===''){
				//The path refers to the image itself — present it as a directory
				$iEconetHandle = Vfs::getFreeFileHandleID($oUser);
				$iVfsHandle = self::$iFileHandle++;
				self::$aFileHandles[$iVfsHandle]=[
					'image-file'=>$sImageFile,
					'path-inside-image'=>'',
					'pos'=>0,
					'data'=>'',
					'dirty'=>FALSE,
					'readonly'=>TRUE,
					'load'=>0,
					'exec'=>0,
					'access'=>0x0C,
				];
				return new FileDescriptor(self::$oLogger,'HomeLan\FileStore\Vfs\Plugin\Mdfs',$oUser,$sImageFile,$oEconetPath->getFilePath(),$iVfsHandle,$iEconetHandle,FALSE,TRUE);
			}

			$oMdfs = self::_getImageReader($sImageFile);
			$aEntry = self::_findCatalogueEntry($oMdfs,$sPathInsideImage);

			if(!is_null($aEntry)){
				$bIsFile = $aEntry['type']=='file';
				$bIsDir = $aEntry['type']=='dir';
				$iEconetHandle = Vfs::getFreeFileHandleID($oUser);
				$iVfsHandle = self::$iFileHandle++;
				self::$aFileHandles[$iVfsHandle]=[
					'image-file'=>$sImageFile,
					'path-inside-image'=>$sPathInsideImage,
					'pos'=>0,
					'data'=>$bIsFile ? $oMdfs->getFile($sPathInsideImage) : '',
					'dirty'=>FALSE,
					'readonly'=>$bReadOnly || !self::$bWriteEnabled,
					'load'=>$aEntry['load'] ?? 0,
					'exec'=>$aEntry['exec'] ?? 0,
					'access'=>$aEntry['access'] ?? 0x0C,
				];
				return new FileDescriptor(self::$oLogger,'HomeLan\FileStore\Vfs\Plugin\Mdfs',$oUser,$sImageFile,$oEconetPath->getFilePath(),$iVfsHandle,$iEconetHandle,$bIsFile,$bIsDir);
			}

			//The entry does not exist yet. If we are writable and the caller does not require
			//it to already exist, hand back a handle for a brand new file inside the image.
			if(!$bMustExist && !$bReadOnly && self::$bWriteEnabled && $sPathInsideImage!==''){
				$iEconetHandle = Vfs::getFreeFileHandleID($oUser);
				$iVfsHandle = self::$iFileHandle++;
				self::$aFileHandles[$iVfsHandle]=[
					'image-file'=>$sImageFile,
					'path-inside-image'=>$sPathInsideImage,
					'pos'=>0,
					'data'=>'',
					'dirty'=>FALSE,
					'readonly'=>FALSE,
					'load'=>0,
					'exec'=>0,
					'access'=>0x0C,
				];
				return new FileDescriptor(self::$oLogger,'HomeLan\FileStore\Vfs\Plugin\Mdfs',$oUser,$sImageFile,$oEconetPath->getFilePath(),$iVfsHandle,$iEconetHandle,FALSE,FALSE);
			}
		}

		throw new VfsException("No such file");
	}


	public static function getDirectoryListing(string $sEconetPath,array $aDirectoryListing): array
	{
		$sImageFile = self::_getImageFile($sEconetPath);

		//Produce a directory listing for files inside the image if the selected path is inside the image
		if(strlen($sImageFile)>0){
			$sPathInsideImage = self::_getPathInsideImage($sEconetPath,$sImageFile);
			$oMdfs = self::_getImageReader($sImageFile);
			$aImageStat = stat($sImageFile);
			$aCat = $oMdfs->getCatalogue();

			if(strlen($sPathInsideImage)>0){
				$aPathParts = explode('.',$sPathInsideImage);
				foreach($aPathParts as $sPart){
					$sFoundKey = null;
					foreach(array_keys($aCat) as $sKey){
						if(strtoupper($sKey)==strtoupper($sPart)){
							$sFoundKey = $sKey;
							break;
						}
					}
					if(is_null($sFoundKey)){
						return $aDirectoryListing;
					}
					$aCat = $aCat[$sFoundKey]['dir'] ?? [];
				}
			}

			$sAccess = self::$bWriteEnabled ? 'wr/wr' : '-r/-r';
			foreach($aCat as $sFile=>$aMeta){
				$aDirectoryListing[$sFile] = new DirectoryEntry($sFile,$sImageFile,'HomeLan\FileStore\Vfs\Plugin\Mdfs',$aMeta['load'] ?? null,$aMeta['exec'] ?? null,$aMeta['size'] ?? 0,$sEconetPath.'.'.$sFile,$aImageStat['ctime'],$sAccess, $aMeta['type']=='dir' ? TRUE : FALSE);
			}
		}

		//Scan the unix dir, see if there is a disk image in that directory to see if it needs changing to a directory
		$sUnixPath = self::_econetToUnix($sEconetPath);
		if(is_dir($sUnixPath)){
			$aFiles = scandir($sUnixPath);
			foreach($aFiles as $sFile){
				$bIsImage = FALSE;
				foreach(self::EXTENSIONS as $sExt){
					if(strtolower(substr((string) $sFile,-strlen($sExt)-1))==='.'.$sExt){
						$bIsImage = TRUE;
						break;
					}
				}
				if($bIsImage){
					$sDisplayName = substr((string) $sFile,0,strlen((string) $sFile)-5);
					if(!array_key_exists($sDisplayName,$aDirectoryListing)){
						$aStat = stat($sUnixPath.DIRECTORY_SEPARATOR.$sFile);
						$aDirectoryListing[$sFile]=new DirectoryEntry($sDisplayName,$sFile,'HomeLan\FileStore\Vfs\Plugin\Mdfs',NULL,NULL,0,$sEconetPath.'.'.$sDisplayName,$aStat['ctime'],'-r/-r', TRUE);
					}
				}
			}
		}

		//Rip out any raw .mdfs/.hdfs entries added by other plugins
		$aReturn = [];
		foreach($aDirectoryListing as $sFile => $oFile){
			if(stripos($sFile,"\/mdfs")===FALSE && stripos($sFile,"\/hdfs")===FALSE){
				$aReturn[$sFile]=$oFile;
			}
		}
		return $aReturn;
	}

	public static function createDirectory($oUser,FilePath $oPath): bool
	{
		if(!self::$bWriteEnabled){
			return FALSE;
		}
		$sEconetPath = $oPath->getFilePath();
		$sImageFile = self::_getImageFile($sEconetPath);
		if(strlen($sImageFile)==0){
			return FALSE;
		}
		$sPathInsideImage = self::_getPathInsideImage($sEconetPath,$sImageFile);
		if($sPathInsideImage===''){
			return FALSE;
		}
		$oWriter = self::_getImageReader($sImageFile);
		if(!($oWriter instanceof MdfsWriter)){
			return FALSE;
		}
		try {
			$oWriter->createDir($sPathInsideImage);
			return TRUE;
		}catch(\Exception $oException){
			self::$oLogger->debug("Mdfs: createDirectory failed: ".$oException->getMessage());
			return FALSE;
		}
	}

	public static function deleteFile($oUser,FilePath $oEconetPath): bool
	{
		if(!self::$bWriteEnabled){
			return FALSE;
		}
		$sEconetPath = $oEconetPath->getFilePath();
		$sImageFile = self::_getImageFile($sEconetPath);
		if(strlen($sImageFile)==0){
			return FALSE;
		}
		$sPathInsideImage = self::_getPathInsideImage($sEconetPath,$sImageFile);
		if($sPathInsideImage===''){
			return FALSE;
		}
		$oWriter = self::_getImageReader($sImageFile);
		if(!($oWriter instanceof MdfsWriter)){
			return FALSE;
		}
		try {
			if($oWriter->isDir($sPathInsideImage)){
				$oWriter->deleteDir($sPathInsideImage);
			}else{
				$oWriter->deleteFile($sPathInsideImage);
			}
			return TRUE;
		}catch(\Exception $oException){
			self::$oLogger->debug("Mdfs: deleteFile failed: ".$oException->getMessage());
			return FALSE;
		}
	}

	public static function moveFile($oUser,FilePath $oEconetPathFrom,FilePath $oEconetPathTo): bool
	{
		if(!self::$bWriteEnabled){
			return FALSE;
		}
		$sFromPath = $oEconetPathFrom->getFilePath();
		$sToPath = $oEconetPathTo->getFilePath();
		$sImageFileFrom = self::_getImageFile($sFromPath);
		$sImageFileTo = self::_getImageFile($sToPath);
		if(strlen($sImageFileFrom)==0 || $sImageFileFrom!==$sImageFileTo){
			//Only renames within the same image are supported, the underlying
			//writer has no cross image move primitive.
			return FALSE;
		}
		$sPathFrom = self::_getPathInsideImage($sFromPath,$sImageFileFrom);
		$sPathTo = self::_getPathInsideImage($sToPath,$sImageFileTo);
		if($sPathFrom===''||$sPathTo===''){
			return FALSE;
		}
		$oWriter = self::_getImageReader($sImageFileFrom);
		if(!($oWriter instanceof MdfsWriter)){
			return FALSE;
		}
		try {
			if(!$oWriter->isFile($sPathFrom)){
				return FALSE;
			}
			$aEntry = self::_findCatalogueEntry($oWriter,$sPathFrom);
			$sData = $oWriter->getFile($sPathFrom);
			$oWriter->writeFile($sPathTo,$sData,$aEntry['load'] ?? 0,$aEntry['exec'] ?? 0,$aEntry['access'] ?? 0x0C);
			$oWriter->deleteFile($sPathFrom);
			return TRUE;
		}catch(\Exception $oException){
			self::$oLogger->debug("Mdfs: moveFile failed: ".$oException->getMessage());
			return FALSE;
		}
	}

	public static function saveFile($oUser,FilePath $oEconetPath,string $sData,int $iLoadAddr,int $iExecAddr): bool
	{
		if(!self::$bWriteEnabled){
			return FALSE;
		}
		$sEconetPath = $oEconetPath->getFilePath();
		$sImageFile = self::_getImageFile($sEconetPath);
		if(strlen($sImageFile)==0){
			return FALSE;
		}
		$sPathInsideImage = self::_getPathInsideImage($sEconetPath,$sImageFile);
		if($sPathInsideImage===''){
			return FALSE;
		}
		$oWriter = self::_getImageReader($sImageFile);
		if(!($oWriter instanceof MdfsWriter)){
			return FALSE;
		}
		try {
			$oWriter->writeFile($sPathInsideImage,$sData,$iLoadAddr,$iExecAddr);
			return TRUE;
		}catch(\Exception $oException){
			self::$oLogger->debug("Mdfs: saveFile failed: ".$oException->getMessage());
			return FALSE;
		}
	}

	public static function createFile($oUser,FilePath $oEconetPath,int $iSize,int $iLoadAddr,int $iExecAddr): bool
	{
		return self::saveFile($oUser,$oEconetPath,str_repeat("\x00",$iSize),$iLoadAddr,$iExecAddr);
	}

	/**
	 * Get the contents of a given file
	 *
	 * @throws VfsException if the file does not exist
	*/
	public static function getFile($oUser,FilePath $oEconetPath): string
	{
		$sImageFile = self::_getImageFile($oEconetPath->getFilePath());
		if(strlen($sImageFile)>0){
			$sPathInsideImage = self::_getPathInsideImage($oEconetPath->getFilePath(),$sImageFile);
			$oMdfs = self::_getImageReader($sImageFile);
			$aEntry = self::_findCatalogueEntry($oMdfs,$sPathInsideImage);
			if(!is_null($aEntry) && $aEntry['type']=='file'){
				return $oMdfs->getFile($sPathInsideImage);
			}
		}
		throw new VfsException("No such file");
	}

	public static function setMeta(string $sEconetPath,$iLoad,$iExec,int $iAccess): void
	{
		if(!self::$bWriteEnabled){
			return;
		}
		$sImageFile = self::_getImageFile($sEconetPath);
		if(strlen($sImageFile)==0){
			return;
		}
		$sPathInsideImage = self::_getPathInsideImage($sEconetPath,$sImageFile);
		if($sPathInsideImage===''){
			return;
		}
		$oWriter = self::_getImageReader($sImageFile);
		if(!($oWriter instanceof MdfsWriter)){
			return;
		}
		try {
			$aEntry = self::_findCatalogueEntry($oWriter,$sPathInsideImage);
			if(is_null($aEntry)){
				return;
			}
			$iNewLoad = is_null($iLoad) ? ($aEntry['load'] ?? 0) : $iLoad;
			$iNewExec = is_null($iExec) ? ($aEntry['exec'] ?? 0) : $iExec;
			$oWriter->setLoadExec($sPathInsideImage,$iNewLoad,$iNewExec);
			$oWriter->setAccess($sPathInsideImage,$iAccess);
		}catch(\Exception $oException){
			self::$oLogger->debug("Mdfs: setMeta failed: ".$oException->getMessage());
		}
	}

	public static function fsFtell($oUser,$fLocalHandle)
	{
		if(array_key_exists($fLocalHandle,self::$aFileHandles)){
			return self::$aFileHandles[$fLocalHandle]['pos'];
		}
		throw new VfsException("Invalid handle");
	}

	public static function fsFStat($oUser,$fLocalHandle): array
	{
		if(array_key_exists($fLocalHandle,self::$aFileHandles)){
			$iSize = strlen(self::$aFileHandles[$fLocalHandle]['data']);
			return ['dev'=>null,'ino'=>null,'size'=>$iSize,'nlink'=>1];
		}
		throw new VfsException("Invalid handle");
	}

	public static function isEof($oUser,$fLocalHandle): bool
	{
		if(array_key_exists($fLocalHandle,self::$aFileHandles)){
			$oHandle = self::$aFileHandles[$fLocalHandle];
			return $oHandle['pos']>=strlen($oHandle['data']);
		}
		throw new VfsException("Invalid handle");
	}

	public static function setPos($oUser,$fLocalHandle,$iPos): bool
	{
		if(array_key_exists($fLocalHandle,self::$aFileHandles)){
			self::$aFileHandles[$fLocalHandle]['pos']=$iPos;
			return TRUE;
		}
		throw new VfsException("Invalid handle");
	}

	public static function read($oUser,$fLocalHandle,$iLength): string
	{
		if(array_key_exists($fLocalHandle,self::$aFileHandles)){
			$oHandle =& self::$aFileHandles[$fLocalHandle];
			$sChunk = substr($oHandle['data'],$oHandle['pos'],$iLength);
			$oHandle['pos'] += strlen($sChunk);
			return $sChunk;
		}
		throw new VfsException("Invalid handle");
	}

	public static function write($oUser,$fLocalHandle,$sData): void
	{
		if(!array_key_exists($fLocalHandle,self::$aFileHandles)){
			throw new VfsException("Invalid handle");
		}
		$oHandle =& self::$aFileHandles[$fLocalHandle];
		if($oHandle['readonly']){
			self::$oLogger->debug("Mdfs: Write bytes to file handle ".$fLocalHandle);
			throw new VfsException("Read Only FS");
		}
		$sData = (string) $sData;
		$iPos = $oHandle['pos'];
		if($iPos > strlen($oHandle['data'])){
			$oHandle['data'] = str_pad($oHandle['data'],$iPos,"\x00");
		}
		$oHandle['data'] = substr_replace($oHandle['data'],$sData,$iPos,strlen($sData));
		$oHandle['pos'] += strlen($sData);
		$oHandle['dirty'] = TRUE;
	}

	public static function setExt($oUser,$fLocalHandle,int $iExt): void
	{
		if(!array_key_exists($fLocalHandle,self::$aFileHandles)){
			throw new VfsException("Invalid handle");
		}
		$oHandle =& self::$aFileHandles[$fLocalHandle];
		if($oHandle['readonly']){
			throw new VfsException("Read Only FS");
		}
		$oHandle['data'] = substr($oHandle['data'],0,$iExt);
		if(strlen($oHandle['data'])<$iExt){
			$oHandle['data'] = str_pad($oHandle['data'],$iExt,"\x00");
		}
		$oHandle['dirty'] = TRUE;
	}

	public static function fsLock($oUser,$fLocalHandle,bool $bExclusive): void {}

	public static function fsUnlock($oUser,$fLocalHandle): void {}

	public static function fsClose($oUser,$fLocalHandle): void
	{
		if(!array_key_exists($fLocalHandle,self::$aFileHandles)){
			return;
		}
		$oHandle = self::$aFileHandles[$fLocalHandle];
		if($oHandle['dirty'] && !$oHandle['readonly'] && $oHandle['path-inside-image']!==''){
			$oWriter = self::_getImageReader($oHandle['image-file']);
			if($oWriter instanceof MdfsWriter){
				try {
					$oWriter->writeFile($oHandle['path-inside-image'],$oHandle['data'],$oHandle['load'],$oHandle['exec'],$oHandle['access']);
				}catch(\Exception $oException){
					self::$oLogger->debug("Mdfs: fsClose write-back failed: ".$oException->getMessage());
				}
			}
		}
		unset(self::$aFileHandles[$fLocalHandle]);
	}

	public static function _getAccessMode($iGid,$iUid,$iMode): string
	{
		return self::$bWriteEnabled ? 'wr/wr' : '-r/-r';
	}
}
