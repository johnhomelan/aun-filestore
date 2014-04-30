<?
/**
 * This file contains the localfile vfs plugin
 *
*/

/**
 * The vfspluginlocalfile class acts as a vfs plugin to provide access to local files using the same on disk 
 * sprows ethernet card uses with a samba server
 *
 * @package corevfs
 * @authour John Brown <john@home-lan.co.uk>
*/
class vfspluginlocalfile implements vfsplugininterface {

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
		$sUnixPath = config::getValue('vfs_plugin_localfile_root').DIRECTORY_SEPARATOR.$sUnixPath;
		if(file_exists($sUnixPath)){
			logger::log("vfspluginlocalfile: Converted econet path ".$sEconetPath. " to ".$sUnixPath,LOG_DEBUG);
		}else{
			//The file does not exists see if a case insenstive version of this files exists
			$sDir = dirname($sUnixPath);
			$sTestFileName = strtolower(basename($sUnixPath));
			if(is_dir($sDir)){
				$aFiles = scandir($sDir);
				foreach($aFiles as $sFile){
					if(strtolower($sFile)==$sTestFileName){
						logger::log("vfspluginlocalfile: Converted econet path ".$sEconetPath. " to ".$sDir.DIRECTORY_SEPARATOR.$sFile,LOG_DEBUG);
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

	public static function _buildFiledescriptorFromEconetPath($oUser,$sCsd,$sEconetPath,$bMustExist,$bReadOnly)
	{
		if(strpos($sEconetPath,'$')===0){
			//Absolute Path
			$sUnixPath = vfspluginlocalfile::_econetToUnix($sEconetPath);
		}else{
			//Relative path
			$sEconetPath = $sCsd.'.'.$sEconetPath;
			$sUnixPath = vfspluginlocalfile::_econetToUnix($sEconetPath);
		}
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
			return new filedescriptor('vfspluginlocalfile',$oUser,$sUnixPath,$sEconetPath,$iVfsHandle,$iEconetHandle,is_file($sUnixPath),is_dir($sUnixPath));
			
		}
	}

	public static function _getAccessMode($iGid,$iUid,$iMode)
	{
		$sAccess = "";
		$sAccess .= (($iMode & 0x0080) ? 'w' : '-');
		$sAccess .= (($iMode & 0x0100) ? 'r' : '-');
		$sAccess .= "/";
		$sAccess .= (($iMode & 0x0002) ? 'w' : '-');
		$sAccess .= (($iMode & 0x0004) ? 'r' : '-');
		return $sAccess;
	}

	public static function getDirectoryListing($sEconetPath,$aDirectoryListing)
	{
		$sUnixPath = vfspluginlocalfile::_econetToUnix($sEconetPath);

		//If the path is not a valid dir return an empty list 
		if(!is_dir($sUnixPath)){
			return $aDirectoryListing;
		}

		//Scan the unix dir, and build a directoryentry for each file
		$aFiles = scandir($sUnixPath);
		foreach($aFiles as $sFile){
			if($sFile=='..' or $sFile=='.'){
				//Skip 
			}elseif(stripos($sFile,'.inf')!==FALSE){
				//Files ending in .inf skip
			}else{
				if(!array_key_exists($sFile,$aDirectoryListing)){
					$aStat = stat($sUnixPath.DIRECTORY_SEPARATOR.$sFile);
					$aDirectoryListing[$sFile]=new directoryentry(str_replace('.','/',$sFile),$sFile,'vfspluginlocalfile',NULL,NULL,$aStat['size'],is_dir($sUnixPath.DIRECTORY_SEPARATOR.$sFile),$sEconetPath.'.'.str_replace('.','/',$sFile),$aStat['ctime'],self::_getAccessMode($aStat['uid'],$aStat['gid'],$aStat['mode']));
				}
				if(is_null($aDirectoryListing[$sFile]) OR is_null($aDirectoryListing[$sFile]->getExecAddr())){
					//If there is a .inf file use it toget the load exec addr
					if(file_exists($sUnixPath.DIRECTORY_SEPARATOR.$sFile.".inf")){
						$sInf = file_get_contents($sUnixPath.DIRECTORY_SEPARATOR.$sFile.".inf");
						$aMatches = array();
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
		$aReturn = array();
		foreach($aDirectoryListing as $sFile => $oFile){
			if(stripos($sFile,"\/inf")===FALSE){
				$aReturn[$sFile]=$oFile;
			}
		}
		return $aReturn;
	}

	public static function createDirectory($oUser,$sCsd,$sEconetPath)
	{
		if(strpos($sEconetPath,'$')===0){
			//Absolute path
			$aPath = explode('.',$sEconetPath);
		}else{
			//Relative path
			$aPath = explode('.',trim($sCsd,'.').'.'.$sEconetPath);
		}
		$sDir = array_pop($aPath);
		$sDirPath = implode('.',$aPath);
		$sUnixDirPath = vfspluginlocalfile::_econetToUnix($sDirPath);
		if(is_dir($sUnixDirPath) AND !file_exists(rtrim($sUnixDirPath,DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.$sDir)){
			return mkdir(rtrim($sUnixDirPath,DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.$sDir);
		}
		return FALSE;
	}

	public static function deleteFile($oUser,$sCsd,$sEconetPath)
	{
		if(strpos($sEconetPath,'$')===0){
			//Absolute path
			$aPath = explode('.',$sEconetPath);
		}else{
			//Relative path
			$aPath = explode('.',trim($sCsd,'.').'.'.$sEconetPath);
		}
		$sFile = array_pop($aPath);
		$sFilePath = implode('.',$aPath);
		$sUnixDirPath = vfspluginlocalfile::_econetToUnix($sFilePath);
		if(is_dir($sUnixDirPath)){
			if(file_exists(rtrim($sUnixDirPath,DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.$sFile)){
				$bReturn =  unlink(rtrim($sUnixDirPath,DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.$sFile);
			}
			if($bReturn AND file_exists(rtrim($sUnixDirPath,DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.$sFile).'.inf'){
				unlink(rtrim($sUnixDirPath,DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.$sFile.'.inf');
			}
		}
		return FALSE;
	}

	public static function moveFile($oUser,$sCsd,$sEconetPathFrom,$sEconetPathTo)
	{
		if(strpos($sEconetPathFrom,'$')===0){
			//Absolute path
			$sPathFrom = $sEconetPathFrom;
		}else{
			//Relative path
			$sPathFrom = trim($sCsd,'.').'.'.$sEconetPathFrom;
		}
		if(strpos($sEconetPathTo,'$')===0){
			//Absolute path
			$sPathTo = $sEconetPathTo;
		}else{
			//Relative path
			$sPathTo = trim($sCsd,'.').'.'.$sEconetPathTo;
		}
		$sUnixFrom = vfspluginlocalfile::_econetToUnix($sPathFrom);
		$sUnixTo = vfspluginlocalfile::_econetToUnix($sPathTo,TRUE);
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

	public static function saveFile($oUser,$sCsd,$sEconetPath,$sData,$iLoadAddr,$iExecAddr)
	{
		if(strpos($sEconetPath,'$')===0){
			//Absolute path
			$aPath = explode('.',$sEconetPath);
		}else{
			//Relative path
			$aPath = explode('.',trim($sCsd,'.').'.'.$sEconetPath);
		}
		$sFile = array_pop($aPath);
		$sFilePath = implode('.',$aPath);
		$sUnixDirPath = vfspluginlocalfile::_econetToUnix($sFilePath);
		if(is_dir($sUnixDirPath)){
			file_put_contents($sUnixDirPath.DIRECTORY_SEPARATOR.$sFile,$sData);
			file_put_contents($sUnixDirPath.DIRECTORY_SEPARATOR.$sFile.'.inf',"TAPE file ".str_pad(dechex($iLoadAddr),8,0,STR_PAD_LEFT)." ".str_pad(dechex($iExecAddr),8,0,STR_PAD_LEFT));
			return TRUE;
		}

	}

	public static function createFile($oUser,$sCsd,$sEconetPath,$iSize,$iLoadAddr,$iExecAddr)
	{
		if(strpos($sEconetPath,'$')===0){
			//Absolute path
			$aPath = explode('.',$sEconetPath);
		}else{
			//Relative path
			$aPath = explode('.',trim($sCsd,'.').'.'.$sEconetPath);
		}
		$sFile = array_pop($aPath);
		$sFilePath = implode('.',$aPath);
		$sUnixDirPath = vfspluginlocalfile::_econetToUnix($sFilePath);
		if(is_dir($sUnixDirPath)){
			$hFile = fopen($sUnixDirPath.DIRECTORY_SEPARATOR.$sFile,'r+');
			ftruncate($hFile,$iSize);
			fclose($hFile);
			file_put_contents($sUnixDirPath.DIRECTORY_SEPARATOR.$sFile.'.inf',"TAPE file ".str_pad(dechex($iLoadAddr),8,0,STR_PAD_LEFT)." ".str_pad(dechex($iExecAddr),8,0,STR_PAD_LEFT));
			return TRUE;
		}

	}

	/**
	 * Get the contents of a given file
	 *
	 * @throws VfsException if the file does not exist
	*/
	public static function getFile($oUser,$sCsd,$sEconetPath)
	{
		if(strpos($sEconetPath,'$')===0){
			//Absolute path
			$sPath = $sEconetPath;
		}else{
			//Relative path
			$sPath = trim($sCsd,'.').'.'.$sEconetPath;
		}
		$sUnixPath = vfspluginlocalfile::_econetToUnix($sPath);
		if(is_file($sUnixPath)){
			return file_get_contents($sUnixPath);
		}
		throw new VfsException("No such file");
	}

	public static function setMeta($sEconetPath,$iLoad,$iExec,$iAccess)
	{
		$sUnixPath = vfspluginlocalfile::_econetToUnix($sEconetPath);
		if(file_exists($sUnixPath) AND file_exists($sUnixPath.'.inf')){
			$sInf = file_get_contents($sUnixPath.".inf");				
			$aMatches = array();
			if(preg_match('/^TAPE file ([0-9a-fA-F]+) ([0-9a-fA-F]+)/',$sInf,$aMatches)>0){
				//Update load / exec addr
				$aMata=array('load'=>$aMatches[1],'exec'=>$aMatches[2]);
			}else{
				$aMata=array('load'=>'ffff0000','exec'=>'ffff0000');
			}
		}else{
			$aMata=array('load'=>'ffff0000','exec'=>'ffff0000');
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
		vfspluginlocalfile::_setUid($oUser);
		$mReturn =  ftell($fLocalHandle);
		vfspluginlocalfile::_returnUid();
		return $mReturn;
	}

	public static function fsFStat($oUser,$fLocalHandle)
	{
		logger::log("vfspluginlocalfile: Get fstat on ".$fLocalHandle,LOG_DEBUG);
		vfspluginlocalfile::_setUid($oUser);
		$mReturn =  fstat($fLocalHandle);
		vfspluginlocalfile::_returnUid();
		return $mReturn;
	}
	public static function isEof($oUser,$fLocalHandle)
	{
		vfspluginlocalfile::_setUid($oUser);
		$mReturn =  feof($fLocalHandle);
		vfspluginlocalfile::_returnUid();
		return $mReturn;
	}

	public static function setPos($oUser,$fLocalHandle,$iPos)
	{
		logger::log("vfspluginlocalfile: Moving file off-set to ".$iPos." bytes for file handle ".$fLocalHandle.LOG_DEBUG);
		vfspluginlocalfile::_setUid($oUser);
		$mReturn =  fseek($fLocalHandle,$iPos,SEEK_SET);
		vfspluginlocalfile::_returnUid();
		return $mReturn;
	}
	
	public static function read($oUser,$fLocalHandle,$iLength)
	{
		logger::log("vfspluginlocalfile: Reading ".$iLength." bytes from file handle ".$fLocalHandle.LOG_DEBUG);
		vfspluginlocalfile::_setUid($oUser);
		$mReturn =  fread($fLocalHandle,$iLength);
		vfspluginlocalfile::_returnUid();
		return $mReturn;
	}

	public static function write($oUser,$fLocalHandle,$sData)
	{
		logger::log("vfspluginlocalfile: Write bytes to file handle ".$fLocalHandle.LOG_DEBUG);
		vfspluginlocalfile::_setUid($oUser);
		$mReturn =  fwrite($fLocalHandle,$sData);
		vfspluginlocalfile::_returnUid();
		return $mReturn;
	}

	public static function fsClose($oUser,$fLocalHandle)
	{
		vfspluginlocalfile::_setUid($oUser);
		$mReturn = fclose($fLocalHandle);
		vfspluginlocalfile::_returnUid();
		return $mReturn;
	}
}

?>
