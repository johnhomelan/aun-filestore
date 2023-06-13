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
use config; 

/**
 * The LocalFile class acts as a vfs plugin to provide access to local files using the same on disk 
 * sprows ethernet card uses with a samba server
 *
 * @package corevfs
 * @authour John Brown <john@home-lan.co.uk>
*/
class LocalFile implements PluginInterface {

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

	public static function _buildFiledescriptorFromEconetPath($oUser,FilePath $oEconetPath,$bMustExist,$bReadOnly): \HomeLan\FileStore\Vfs\FileDescriptor
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
			return new FileDescriptor(self::$oLogger,'LocalFile',$oUser,$sUnixPath,$oEconetPath->getFilePath(),$iVfsHandle,$iEconetHandle,is_file($sUnixPath),is_dir($sUnixPath));
		}
	}

	public static function _getAccessMode($iGid,$iUid,$iMode): string
	{
		$sAccess = "";
		$sAccess .= (($iMode & 0x0080) ? 'w' : '-');
		$sAccess .= (($iMode & 0x0100) ? 'r' : '-');
		$sAccess .= "/";
		$sAccess .= (($iMode & 0x0002) ? 'w' : '-');
		$sAccess .= (($iMode & 0x0004) ? 'r' : '-');
		return $sAccess;
	}

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
					$aDirectoryListing[$sFile]=new DirectoryEntry(str_replace('.','/',(string) $sFile),$sFile,'LocalFile',NULL,NULL,$aStat['size'],$sEconetPath.'.'.str_replace('.','/',(string) $sFile),$aStat['ctime'],self::_getAccessMode($aStat['uid'],$aStat['gid'],$aStat['mode']), is_dir($sUnixPath.DIRECTORY_SEPARATOR.$sFile));
				}
				if(is_null($aDirectoryListing[$sFile]) OR is_null($aDirectoryListing[$sFile]->getExecAddr())){
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

	public static function createDirectory($oUser,FilePath $oPath): bool
	{
		$sUnixDirPath = LocalFile::_econetToUnix($oPath->sDir);
		if(is_dir($sUnixDirPath) AND !file_exists(rtrim($sUnixDirPath,DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.$oPath->sFile)){
			return mkdir(rtrim($sUnixDirPath,DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.$oPath->sFile);
		}
		return FALSE;
	}

	public static function deleteFile($oUser,FilePath $oEconetPath): bool
	{
		$sUnixDirPath = LocalFile::_econetToUnix($oEconetPath->sDir);
		if(is_dir($sUnixDirPath)){
			if(file_exists(rtrim($sUnixDirPath,DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.$oEconetPath->sFile)){
				$bReturn =  unlink(rtrim($sUnixDirPath,DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.$oEconetPath->sFile);

				if($bReturn AND file_exists(rtrim($sUnixDirPath,DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.$oEconetPath->sFile).'.inf'){
					unlink(rtrim($sUnixDirPath,DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.$oEconetPath->sFile.'.inf');
				}
			}
		}
		return FALSE;
	}

	public static function moveFile($oUser,FilePath $oEconetPathFrom,FilePath $oEconetPathTo): bool
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

	public static function saveFile($oUser,FilePath $oEconetPath,string $sData,int $iLoadAddr,int $iExecAddr): bool
	{
		$sUnixDirPath = LocalFile::_econetToUnix($oEconetPath->sDir);
		if(is_dir($sUnixDirPath)){
			file_put_contents($sUnixDirPath.DIRECTORY_SEPARATOR.$oEconetPath->sFile,$sData);
			file_put_contents($sUnixDirPath.DIRECTORY_SEPARATOR.$oEconetPath->sFile.'.inf',"TAPE file ".str_pad(dechex($iLoadAddr),8,0,STR_PAD_LEFT)." ".str_pad(dechex($iExecAddr),8,0,STR_PAD_LEFT));
			return TRUE;
		}
		return FALSE;

	}

	public static function createFile($oUser,FilePath $oEconetPath,int $iSize,int $iLoadAddr,int $iExecAddr): bool
	{
		$sUnixDirPath = LocalFile::_econetToUnix($oEconetPath->sDir);
		if(is_dir($sUnixDirPath)){
			$hFile = fopen($sUnixDirPath.DIRECTORY_SEPARATOR.$oEconetPath->sFile,'r+');
			ftruncate($hFile,$iSize);
			fclose($hFile);
			file_put_contents($sUnixDirPath.DIRECTORY_SEPARATOR.$oEconetPath->sFile.'.inf',"TAPE file ".str_pad(dechex($iLoadAddr),8,0,STR_PAD_LEFT)." ".str_pad(dechex($iExecAddr),8,0,STR_PAD_LEFT));
			return TRUE;
		}
		return FALSE;
	}

	/**
	 * Get the contents of a given file
	 *
	 * @throws VfsException if the file does not exist
	*/
	public static function getFile($oUser,FilePath $oEconetPath): string
	{
		$sUnixPath = LocalFile::_econetToUnix($oEconetPath->getFilePath());
		if(is_file($sUnixPath)){
			return file_get_contents($sUnixPath);
		}
		throw new VfsException("No such file");
	}

	public static function setMeta(string $sEconetPath,$iLoad,$iExec,int $iAccess): void
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

	public static function fsFtell($oUser,$fLocalHandle)
	{
		LocalFile::_setUid($oUser);
		$mReturn =  ftell($fLocalHandle);
		LocalFile::_returnUid();
		return $mReturn;
	}

	public static function fsFStat($oUser,$fLocalHandle)
	{
		self::$oLogger->debug("LocalFile: Get fstat on ".$fLocalHandle);
		LocalFile::_setUid($oUser);
		$mReturn =  fstat($fLocalHandle);
		LocalFile::_returnUid();
		return $mReturn;
	}
	public static function isEof($oUser,$fLocalHandle): bool
	{
		LocalFile::_setUid($oUser);
		$mReturn =  feof($fLocalHandle);
		LocalFile::_returnUid();
		return $mReturn;
	}

	public static function setPos($oUser,$fLocalHandle,$iPos): int
	{
		self::$oLogger->debug("LocalFile: Moving file off-set to ".$iPos." bytes for file handle ".$fLocalHandle);
		LocalFile::_setUid($oUser);
		$mReturn =  fseek($fLocalHandle,$iPos,SEEK_SET);
		LocalFile::_returnUid();
		return $mReturn;
	}
	
	public static function read($oUser,$fLocalHandle,$iLength)
	{
		self::$oLogger->debug("LocalFile: Reading ".$iLength." bytes from file handle ".$fLocalHandle);
		LocalFile::_setUid($oUser);
		$mReturn =  fread($fLocalHandle,$iLength);
		LocalFile::_returnUid();
		return $mReturn;
	}

	public static function write($oUser,$fLocalHandle,$sData)
	{
		self::$oLogger->debug("LocalFile: Write bytes to file handle ".$fLocalHandle);
		LocalFile::_setUid($oUser);
		$mReturn =  fwrite($fLocalHandle,(string) $sData);
		LocalFile::_returnUid();
		return $mReturn;
	}

	public static function fsClose($oUser,$fLocalHandle): bool
	{
		LocalFile::_setUid($oUser);
		$mReturn = fclose($fLocalHandle);
		LocalFile::_returnUid();
		return $mReturn;
	}
}
