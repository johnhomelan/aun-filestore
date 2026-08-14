<?php
namespace HomeLan\FileStore\Vfs\Plugin; 

/**
 * This file contains the localfile vfs plugin
 *
*/
use HomeLan\FileStore\Vfs\Exception as VfsException;
use HomeLan\FileStore\Vfs\Vfs;
use HomeLan\FileStore\Vfs\DirectoryEntry;
use HomeLan\FileStore\Vfs\FileDescriptor;
use HomeLan\FileStore\Vfs\FilePath;
use HomeLan\FileStore\Authentication\User;
use config;

/**
 * The LocalFile class acts as a vfs plugin to provide access to local files using the same on disk
 * sprows ethernet card uses with a samba server
 *
 * @package corevfs
 * @authour John Brown <john@home-lan.co.uk>
*/
class LocalFile implements PluginInterface {

	protected static \Psr\Log\LoggerInterface $oLogger;

	protected static bool $bMultiuser;

	public static function init(\Psr\Log\LoggerInterface $oLogger, bool $bMultiuser = false): void
	{
		self::$oLogger = $oLogger;
		self::$bMultiuser = $bMultiuser;
	}

	public static function houseKeeping(): void
	{

	}

	protected  static function _setUid(User $oUser): void
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

	protected static function _econetToUnix(string $sEconetPath): string
	{
		//Trim leading $.
		$sEconetPath = substr((string) $sEconetPath,2);
		$aFileParts = explode('.',$sEconetPath);
		$sUnixPath = "";
		foreach($aFileParts as $sPart){
			$sUnixPath = $sUnixPath.str_replace(DIRECTORY_SEPARATOR ,'.',$sPart).DIRECTORY_SEPARATOR;
		}
		$sUnixPath = trim($sUnixPath,DIRECTORY_SEPARATOR);
		$sUnixPath = config::getValue('vfs_plugin_localfile_root').DIRECTORY_SEPARATOR.$sUnixPath;
		if(file_exists($sUnixPath)){
			self::$oLogger->debug("LocalFile: Converted econet path ".$sEconetPath. " to ".$sUnixPath);
		}else{
			//The file does not exists see if a case insenstive version of this files exists
			$sDir = dirname($sUnixPath);
			$sTestFileName = strtolower(basename($sUnixPath));
			if(is_dir($sDir)){
				$aFiles = scandir($sDir);
				foreach($aFiles as $sFile){
					if(strtolower((string) $sFile)==$sTestFileName){
						self::$oLogger->debug("LocalFile: Converted econet path ".$sEconetPath. " to ".$sDir.DIRECTORY_SEPARATOR.$sFile);
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

	public static function _buildFiledescriptorFromEconetPath(User $oUser,FilePath $oEconetPath,bool $bMustExist,bool $bReadOnly): \HomeLan\FileStore\Vfs\FileDescriptor
	{
		$sUnixPath = LocalFile::_econetToUnix($oEconetPath->getFilePath());
		if(strlen($sUnixPath)>0){
			if(is_file($sUnixPath)){
				if($bReadOnly){
					$iVfsHandle = fopen($sUnixPath,'r');
				}else{
					$iVfsHandle = fopen($sUnixPath,'c+');
				}
			}elseif(!$bMustExist){
				if($bReadOnly){
					$iVfsHandle = NULL;
				}else{
					$iVfsHandle = fopen($sUnixPath,'c+');
				}
			}else{
				$iVfsHandle = NULL;
			}
			$iEconetHandle = vfs::getFreeFileHandleID($oUser);
			return new FileDescriptor(self::$oLogger,'HomeLan\FileStore\Vfs\Plugin\LocalFile',$oUser,$sUnixPath,$oEconetPath->getFilePath(),$iVfsHandle,$iEconetHandle,is_file($sUnixPath),is_dir($sUnixPath));
		}
		throw new VfsException("No such file");
	}

	public static function _getAccessMode(int $iGid,int $iUid,int $iMode): string
	{
		$sAccess = "";
		$sAccess .= (($iMode & 0x0080) ? 'w' : '-');
		$sAccess .= (($iMode & 0x0100) ? 'r' : '-');
		$sAccess .= "/";
		$sAccess .= (($iMode & 0x0002) ? 'w' : '-');
		$sAccess .= (($iMode & 0x0004) ? 'r' : '-');
		return $sAccess;
	}

	/**
	 * @param array<string,DirectoryEntry> $aDirectoryListing
	 * @return array<string,DirectoryEntry>
	*/
	public static function getDirectoryListing(string $sEconetPath,array $aDirectoryListing): array
	{
		$sUnixPath = LocalFile::_econetToUnix($sEconetPath);

		//If the path is not a valid dir return an empty list 
		if(!is_dir($sUnixPath)){
			return $aDirectoryListing;
		}

		//Scan the unix dir, and build a DirectoryEntry for each file
		$aFiles = scandir($sUnixPath);
		foreach($aFiles as $sFile){
			if($sFile=='..' or $sFile=='.'){
				//Skip 
			}elseif(stripos((string) $sFile,'.inf')!==FALSE){
				//Files ending in .inf skip
			}else{
				if(!array_key_exists($sFile,$aDirectoryListing)){
					$aStat = stat($sUnixPath.DIRECTORY_SEPARATOR.$sFile);
					$aDirectoryListing[$sFile]=new DirectoryEntry(str_replace('.','/',(string) $sFile),$sFile,'HomeLan\FileStore\Vfs\Plugin\LocalFile',NULL,NULL,$aStat['size'],$sEconetPath.'.'.str_replace('.','/',(string) $sFile),$aStat['ctime'],self::_getAccessMode($aStat['uid'],$aStat['gid'],$aStat['mode']), is_dir($sUnixPath.DIRECTORY_SEPARATOR.$sFile));
				}
				if(is_null($aDirectoryListing[$sFile]->getExecAddr())){
					//If there is a .inf file use it toget the load exec addr
					if(file_exists($sUnixPath.DIRECTORY_SEPARATOR.$sFile.".inf")){
						$sInf = file_get_contents($sUnixPath.DIRECTORY_SEPARATOR.$sFile.".inf");
						$aMatches = [];
						if(preg_match('/^TAPE file ([0-9a-fA-F]+) ([0-9a-fA-F]+)/',$sInf,$aMatches)>0){
							//Update load / exec addr
							$aDirectoryListing[$sFile]->setLoadAddr(hexdec($aMatches[1]));
							$aDirectoryListing[$sFile]->setExecAddr(hexdec($aMatches[2]));
						}
					}
				}

			}
		}
		//Rip out and .inf files from the list
		$aReturn = [];
		foreach($aDirectoryListing as $sFile => $oFile){
			if(stripos($sFile,"\/inf")===FALSE){
				$aReturn[$sFile]=$oFile;
			}
		}
		return $aReturn;
	}

	public static function createDirectory(User $oUser,FilePath $oPath): bool
	{
		$sUnixDirPath = LocalFile::_econetToUnix($oPath->sDir);
		if(is_dir($sUnixDirPath) AND !file_exists(rtrim($sUnixDirPath,DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.$oPath->sFile)){
			return mkdir(rtrim($sUnixDirPath,DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.$oPath->sFile);
		}
		return FALSE;
	}

	public static function deleteFile(User $oUser,FilePath $oEconetPath): bool
	{
		$sUnixDirPath = LocalFile::_econetToUnix($oEconetPath->sDir);
		if(is_dir($sUnixDirPath)){
			if(file_exists(rtrim($sUnixDirPath,DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.$oEconetPath->sFile)){
				$bReturn =  unlink(rtrim($sUnixDirPath,DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.$oEconetPath->sFile);

				if($bReturn AND file_exists(rtrim($sUnixDirPath,DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.$oEconetPath->sFile.'.inf')){
					unlink(rtrim($sUnixDirPath,DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.$oEconetPath->sFile.'.inf');
				}
				return $bReturn;
			}
			
		}
		return FALSE;
	}

	public static function moveFile(User $oUser,FilePath $oEconetPathFrom,FilePath $oEconetPathTo): bool
	{
		$sUnixFrom = LocalFile::_econetToUnix($oEconetPathFrom->getFilePath());
		$sUnixTo = LocalFile::_econetToUnix($oEconetPathTo->getFilePath());
		if(!file_exists($sUnixFrom)){
			throw new VfsException("No such file");
		}
		if(file_exists($sUnixTo)){
			throw new VfsException("Target exisits");
		}
		$bReturn = rename($sUnixFrom,$sUnixTo);
		if($bReturn AND file_exists($sUnixFrom.'.inf') AND !file_exists($sUnixTo.'.inf')){
			rename($sUnixFrom.'.inf',$sUnixTo.'.inf');
		}
		return $bReturn;
	}

	public static function saveFile(User $oUser,FilePath $oEconetPath,string $sData,int $iLoadAddr,int $iExecAddr): bool
	{
		$sUnixDirPath = LocalFile::_econetToUnix($oEconetPath->sDir);
		if(is_dir($sUnixDirPath)){
			file_put_contents($sUnixDirPath.DIRECTORY_SEPARATOR.$oEconetPath->sFile,$sData);
			file_put_contents($sUnixDirPath.DIRECTORY_SEPARATOR.$oEconetPath->sFile.'.inf',"TAPE file ".str_pad(dechex($iLoadAddr),8,"0",STR_PAD_LEFT)." ".str_pad(dechex($iExecAddr),8,"0",STR_PAD_LEFT));
			return TRUE;
		}
		return FALSE;

	}

	public static function createFile(User $oUser,FilePath $oEconetPath,int $iSize,int $iLoadAddr,int $iExecAddr): bool
	{
		$sUnixDirPath = LocalFile::_econetToUnix($oEconetPath->sDir);
		if(is_dir($sUnixDirPath)){
			$hFile = fopen($sUnixDirPath.DIRECTORY_SEPARATOR.$oEconetPath->sFile,'r+');
			ftruncate($hFile,$iSize);
			fclose($hFile);
			file_put_contents($sUnixDirPath.DIRECTORY_SEPARATOR.$oEconetPath->sFile.'.inf',"TAPE file ".str_pad(dechex($iLoadAddr),8,"0",STR_PAD_LEFT)." ".str_pad(dechex($iExecAddr),8,"0",STR_PAD_LEFT));
			return TRUE;
		}
		return FALSE;
	}

	/**
	 * Get the contents of a given file
	 *
	 * @throws VfsException if the file does not exist
	*/
	public static function getFile(User $oUser,FilePath $oEconetPath): string
	{
		$sUnixPath = LocalFile::_econetToUnix($oEconetPath->getFilePath());
		if(is_file($sUnixPath)){
			return file_get_contents($sUnixPath);
		}
		throw new VfsException("No such file");
	}

	public static function setMeta(string $sEconetPath,?int $iLoad,?int $iExec,int $iAccess): void
	{
		$sUnixPath = LocalFile::_econetToUnix($sEconetPath);
		if(file_exists($sUnixPath) AND file_exists($sUnixPath.'.inf')){
			$sInf = file_get_contents($sUnixPath.".inf");				
			$aMatches = [];
			if(preg_match('/^TAPE file ([0-9a-fA-F]+) ([0-9a-fA-F]+)/',$sInf,$aMatches)>0){
				//Update load / exec addr
				$aMata=['load'=>$aMatches[1], 'exec'=>$aMatches[2]];
			}else{
				$aMata=['load'=>'ffff0000', 'exec'=>'ffff0000'];
			}
		}else{
			$aMata=['load'=>'ffff0000', 'exec'=>'ffff0000'];
		}
		if(file_exists($sUnixPath)){
			if(!is_null($iLoad)){
				$aMata['load']=str_pad(dechex($iLoad),8,'0',STR_PAD_LEFT);
			}
			if(!is_null($iExec)){
				$aMata['exec']=str_pad(dechex($iExec),8,'0',STR_PAD_LEFT);;
			}
			file_put_contents($sUnixPath.".inf","TAPE file ".$aMata['load']." ".$aMata['exec']);
		}
	}

	public static function fsFtell(User $oUser,mixed $fLocalHandle): int|false
	{
		LocalFile::_setUid($oUser);
		$mReturn =  ftell($fLocalHandle);
		LocalFile::_returnUid();
		return $mReturn;
	}

	/** @return array<mixed>|false */
	public static function fsFStat(User $oUser,mixed $fLocalHandle): array|false
	{
		self::$oLogger->debug("LocalFile: Get fstat on ".$fLocalHandle);
		LocalFile::_setUid($oUser);
		$mReturn =  fstat($fLocalHandle);
		LocalFile::_returnUid();
		return $mReturn;
	}
	public static function isEof(User $oUser,mixed $fLocalHandle): bool
	{
		LocalFile::_setUid($oUser);
		$mReturn =  feof($fLocalHandle);
		LocalFile::_returnUid();
		return $mReturn;
	}

	public static function setPos(User $oUser,mixed $fLocalHandle,int $iPos): int
	{
		self::$oLogger->debug("LocalFile: Moving file off-set to ".$iPos." bytes for file handle ".$fLocalHandle);
		LocalFile::_setUid($oUser);
		$mReturn =  fseek($fLocalHandle,$iPos,SEEK_SET);
		LocalFile::_returnUid();
		return $mReturn;
	}

	public static function read(User $oUser,mixed $fLocalHandle,int $iLength): string|false
	{
		self::$oLogger->debug("LocalFile: Reading ".$iLength." bytes from file handle ".$fLocalHandle);
		LocalFile::_setUid($oUser);
		$mReturn =  fread($fLocalHandle,$iLength);
		LocalFile::_returnUid();
		return $mReturn;
	}

	public static function write(User $oUser,mixed $fLocalHandle,string $sData): int|false
	{
		self::$oLogger->debug("LocalFile: Write bytes to file handle ".$fLocalHandle);
		LocalFile::_setUid($oUser);
		$mReturn =  fwrite($fLocalHandle,$sData);
		LocalFile::_returnUid();
		return $mReturn;
	}

	public static function setExt(User $oUser,mixed $fLocalHandle,int $iExt): void
	{
		self::$oLogger->debug("LocalFile: Truncating file to ".$iExt." bytes");
		LocalFile::_setUid($oUser);
		ftruncate($fLocalHandle,$iExt);
		LocalFile::_returnUid();
	}

	public static function fsLock(User $oUser,mixed $fLocalHandle,bool $bExclusive): void
	{
		if(!is_resource($fLocalHandle)){
			return;
		}
		self::$oLogger->debug("LocalFile: Acquiring ".($bExclusive ? "exclusive" : "shared")." lock on handle");
		LocalFile::_setUid($oUser);
		flock($fLocalHandle,$bExclusive ? LOCK_EX : LOCK_SH);
		LocalFile::_returnUid();
	}

	public static function fsUnlock(User $oUser,mixed $fLocalHandle): void
	{
		if(!is_resource($fLocalHandle)){
			return;
		}
		self::$oLogger->debug("LocalFile: Releasing lock on handle");
		LocalFile::_setUid($oUser);
		flock($fLocalHandle,LOCK_UN);
		LocalFile::_returnUid();
	}

	public static function fsClose(User $oUser,mixed $fLocalHandle): bool
	{
		LocalFile::_setUid($oUser);
		$mReturn = fclose($fLocalHandle);
		LocalFile::_returnUid();
		return $mReturn;
	}
}
