<?
/**
 * This file contains the localfile vfs plugin
 *
*/

/**
 * The vfspluginlocalfile class acts as a vfs plugin to provide access to local files using the same on disk 
 * format as aund.
 *
 * @package corevfs
 * @authour John Brown <john@home-lan.co.uk>
*/
class vfspluginlocalfile {

	public static function init()
	{
	}

	protected  function _setUid($oUser)
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
			$sUnixPath = $sUnixPath.str_replace('/','.',$sPart).DIRECTORY_SEPARATOR;
		}
		$sUnixPath = trim($sUnixPath,DIRECTORY_SEPARATOR);
		$sUnixPath = config::getValue('vfs_plugin_localfile_root').DIRECTORY_SEPARATOR.$sUnixPath;
		if(file_exists($sUnixPath)){
			logger::log("vfspluginlocalfile: Converted econet path ".$sEconetPath. " to ".$sUnixPath,LOG_DEBUG);
			return $sUnixPath;
		}
	}

	public static function _buildFiledescriptorFromEconetPath($oUser,$sCsd,$sEconetPath)
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
				$iVfsHandle = fopen($sUnixPath,'r+');
			}else{
				$iVfsHandle = NULL;
			}
			$iEconetHandle = vfs::getFreeFileHandleID($oUser);
			return new filedescriptor('vfspluginlocalfile',$oUser,$sUnixPath,$sEconetPath,$iVfsHandle,$iEconetHandle,is_file($sUnixPath),is_dir($sUnixPath));
			
		}
	}

	public static function getDirectoryListing($sEconetPath,$aDirectoryListing)
	{
		$sUnixPath = vfspluginlocalfile::_econetToUnix($sEconetPath);
		$aFiles = scandir($sUnixPath);
		foreach($aFiles as $sFile){
			if($sFile=='..' or $sFile=='.'){
				//Skip 
			}elseif(stripos($sFile,'.inf')!==FALSE){
				//Files ending in .inf skip
			}else{
				if(!array_key_exists($sFile,$aDirectoryListing)){
					$aStat = stat($sUnixPath.DIRECTORY_SEPARATOR.$sFile);
					$aDirectoryListing[$sFile]=new directoryentry(str_replace('.','/',$sFile),$sFile,'vfspluginlocalfile',NULL,NULL,$aStat['size'],is_dir($sUnixPath.DIRECTORY_SEPARATOR.$sFile));
				}
				if(is_null($aDirectoryListing[$sFile]) AND is_null($aDirectoryListing[$sFile]->getExecAddr())){
					//If there is a .inf file use it toget the load exec addr
					if(file_exists($sUnixPath.DIRECTORY_SEPARATOR.$sFile.".inf")){
						$sInf = file_get_contents($sUnixPath.DIRECTORY_SEPARATOR.$sFile.".inf");				
						$aMatches = array();
						if(preg_match('/^TAPE file ([0-9a-fA-F]+) ([0-9a-fA-F]+)/',$sInf,$aMatches)>0){
							//Update load / exec addr
							$aDirectoryListing[$sPrefix]->setLoadAddr($aMatches[1]);
							$aDirectoryListing[$sPrefix]->setExecAddr($aMatches[2]);
						}
					}
				}

			}
		}
		//Rip out and .inf files from the list
		$aReturn = array();
		foreach($aDirectoryListing as $sFile => $oFile){
			if(stripos($sFile,'.inf')===FALSE){
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
		if(is_dir($sUnixDirPath)){
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
				return unlink(rtrim($sUnixDirPath,DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.$sFile);
			}
		}
		return FALSE;
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
		vfspluginlocalfile::_setUid($oUser);
		$mReturn =  fstat($fLocalHandle);
		vfspluginlocalfile::_returnUid();
		return $mReturn;
	}

	public static function close($oUser,$fLocalHandle)
	{
		vfspluginlocalfile::_setUid($oUser);
		$mReturn = fclose($fLocalHandle);
		vfspluginlocalfile::_returnUid();
		return $mReturn;
	}
}

?>
