<?php

namespace HomeLan\FileStore\Vfs; 
/**
 * This file contains the vfs class
 *
 * @package corevfs
 *
*/

use Exception;
use HomeLan\FileStore\Vfs\Exception as VfsException;
use HomeLan\FileStore\Authentication\Security; 
use HomeLan\FileStore\Vfs\FilePath;

/**
 * The vfs class handles all file operations carried out by the file server.
 *
 * It make use of a number of vfs plugins to carry out the work with local or remote file systems.
 *
 * @package corevfs
 * @author John Brown <john@home-lan.co.uk>
*/
class Vfs {

	static protected $aHandles = [];

	static protected $aFileHandleIDs = [];

	static protected $aSinMapping = [];

	static protected $iSin = 1;

	static protected $oLogger;

	static protected $sVfsPlugins;

	protected static $bMultiuser;

	public static function init(\Psr\Log\LoggerInterface $oLogger,string $sVfsPlugins, bool $bMultiuser = false): void
	{
		self::$oLogger = $oLogger;
		self::$sVfsPlugins = $sVfsPlugins;
		self::$bMultiuser = $bMultiuser;
		$aPlugins = Vfs::getVfsPlugins();
	}


	/**
	 * Runs the house keeping functions for all the vfs plugins
	 *
	 *
	*/
	public static function houseKeeping(): void
	{
		//Call house keeping on each plugin 
		$aPlugins = Vfs::getVfsPlugins();
		foreach($aPlugins as $sPlugin){
			$sPlugin::houseKeeping();
		}

		//Clean up any file handles for session that are nolonger logged in
		foreach(self::$aHandles as $iNetwork=>$aStations){
			foreach($aStations as $iStation=>$aHandles){
				foreach($aHandles as $iHandle=>$oHandle){
					$oUser = Security::getUser($iNetwork,$iStation);
					if(!is_object($oUser)){
						self::$oLogger->debug("vfs: Removing handle ".$iHandle." for station ".$iNetwork.":".$iStation." as the session has expired");
						$oHandle->close();
						unset(self::$aHandles[$iNetwork][$iStation][$iHandle]);
					}
				}
			}
		}

		//Clear up sin history
		$aUsers = Security::getUsersOnline();
		if(count($aUsers)==0){
			self::$oLogger->debug("vfs: No users logged in resetting sin history");
			self::$aSinMapping = [];
			self::$iSin = 1;
		}
	}

	/**
	 * Converts Econet style path to unix Abosolute of relative path to a fullpath
	 *
	 * This takes chroot in to account converting absolute chrooted path to real absolute path
	 * @return FilePath
	 */ 
	private static function buildFullPath(int $iNetwork,int $iStation,string $sEconetPath): FilePath
	{
		$oUser = security::getUser($iNetwork,$iStation);
		if(str_starts_with($sEconetPath, '&')){
			$sEconetPath = str_replace('&','$',$sEconetPath);
		}
		echo "Econet path is ".$sEconetPath."\n";
  		if(str_starts_with($sEconetPath, '$')){
			//Absolute path
			$aPath = explode('.',$sEconetPath);
			$sFile = array_pop($aPath);
			$sDir = join('.',$aPath);
		}elseif(str_contains($sEconetPath,'.')){
			//Relitive path
			$aPath = explode('.',$sEconetPath);
			$sFile = array_pop($aPath);
			$sDir = $oUser->getCsd().'.'.join('.',$aPath);
		}else{
			//No path
			$sFile = $sEconetPath;
			$sDir = $oUser->getCsd();
		}
		if($oUser->getRoot()!='$'){
			echo "Dir is ".$sDir."\n";
			if(strpos($sDir,$oUser->getRoot())!==0){
				//If the path is abosulte but does not start with the chroot prefix
				$sDir = str_replace('$',$oUser->getRoot(),(string) $sDir);
			}
			if($sFile=='$'){				
				$sDir = $oUser->getRoot();
				$sFile =  '';
			}
			self::$oLogger->debug("User is chroot'd to ".$oUser->getRoot()." changeing path to ".$sDir);
		}
		if(strpos($sDir,'*')!==false){
			//Deal with unsolvled directory path 
			$sDir = self::_resolveFullPath($sDir,$iNetwork,$iStation);
		}
			
		return new FilePath($sDir, $sFile);
	}

	/**
	 * Takes an unresovled path, and resolves in to a real path
	 *
	 * Acorn's MOS (and RiscOS) allows for directory paths with * in them, 
	 * i.e. $.LIB*.FIX 
	 * The fileserver would expand out LIB* to the first matching directory 
	 * This method does that.
	*/    	
	private static function _resolveFullPath(string $sDir, int $iNetwork, int $iStation):string
	{
		$sLocalDir = $sDir;
		$iExpandPoint = strpos($sLocalDir,'*');
		echo "Dir supplied is ".$sDir."\n";
		//If there is nothing to expand return 
		if($iExpandPoint===false){
			return $sLocalDir;
		}

		//Find the postion of the last path seporator, before the expantion point.
		$iLastPathSeporator = strrpos(substr($sLocalDir,0,$iExpandPoint),'.');

		//Gets the path as a string before the expantion point (i.e. the directory we must search)
		$sPath = substr($sLocalDir,0,($iLastPathSeporator));

		//Get the seach string
		$sSearch = substr($sLocalDir,($iLastPathSeporator+1),($iExpandPoint-$iLastPathSeporator-1));

		if(strlen($sPath)<1){
			//We are only expanding a filename in the Csd (there was no path before the file
			$oUser = security::getUser($iNetwork,$iStation);
			$sPath = $oUser->getCsd();
		}

		echo  "path is ".$sPath." expandpoint is ".$iExpandPoint." last path is ".$iLastPathSeporator." search is ".$sSearch." \n";
		//Build a directory listing from all the plugins 
		$aDirectoryListing = [];
		$aPlugins = Vfs::getVfsPlugins();
		foreach($aPlugins as $sPlugin){
			try {
				$aDirectoryListing = $sPlugin::getDirectoryListing($sPath,$aDirectoryListing);	
			}catch(VfsException $oVfsException){	
				if($oVfsException->isHard()){
					return $sLocalDir;
				}
			}
		}
		
		//Find directory first 
		$bMatched = false;
		foreach($aDirectoryListing as $oFile){
			if($oFile->isDir()){
				if(stripos($oFile->getEconetName(),$sSearch)===0){
					//We have found a match, replace and jump out of the loop
					$sLocalDir =  str_replace($sSearch.'*',$oFile->getEconetName(),$sLocalDir);
					echo "Dir updated to ".$sLocalDir."\n";
					$bMatched = true;
					break;
				}
			}
		}

		//No directory matched so try repeplacing with file
		//NB I have no idea if acorns fileserver gave directories presidence, but it makes sense to me
		if(!$bMatched){
			foreach($aDirectoryListing as $oFile){
				if(stripos($oFile->getEconetName(),$sSearch)===0){
					$sLocalDir = str_replace($sSearch.'*',$oFile->getEconetName(),$sLocalDir);
					$bMatched = true;
					break;
				}
			}
		}

		//Are there other parts the need expanding after the one this call worked on 
		if($bMatched AND strpos($sLocalDir,'*',$iExpandPoint)!==false){
			// I know recussion naughty boy, but this time its fairly neat, 
			// and the limit to an econet path length means this can't accidently call
			// too many time. 
			return self::_resolveFullPath($sLocalDir, $iNetwork, $iStation);
		}
		self::$oLogger->debug("Expanded path from ".$sDir." to ".$sLocalDir);
		return  $sLocalDir;
	}

	/**
	 * Get a list of all the vfsplugin we should be using 
	 *
	 * It also calls the init method of each one when the class is loaded for the first time
	 *
	**/
	public static function getVfsPlugins(): array
	{
		$aReturn = [];
		$aVfsPlugins = explode(',',(string) self::$sVfsPlugins);
		foreach($aVfsPlugins as $sPlugin){
			$sClassname = "\\HomeLan\\FileStore\\Vfs\\Plugin\\".$sPlugin;
			if(!class_exists($sClassname,FALSE)){
				try{
					$sClassname::init(self::$oLogger, self::$bMultiuser);
					$aReturn[]=$sClassname;
				}catch(Exception){
					self::$oLogger->debug("VFS: Unable to load vfs plugin ".$sClassname);
				}
			}else{
				$aReturn[]=$sClassname;
			}
		}
		return $aReturn;
	}

	/**
	 * Builds a file descriptor object from an econet path
	 * 
	 * @param \HomeLan\FileStore\Authentication\User  $oUser The user the file descriptor is being created for 
	 * @param FilePath $oEconetPath The econet file path
	 * @param boolean $bMustExist The path must exist
	 * @param boolean $bReadOnly If the file descriptor should be read-only
	 * @return object 
	*/	
	static protected function _buildFiledescriptorFromEconetPath(\HomeLan\FileStore\Authentication\User  $oUser,FilePath $oEconetPath,bool $bMustExist,bool $bReadOnly): object
	{
		$aPlugins = Vfs::getVfsPlugins();
		$oHandle=NULL;
		foreach($aPlugins as $sPlugin){
			try {
				$oHandle = $sPlugin::_buildFiledescriptorFromEconetPath($oUser,$oEconetPath,$bMustExist,$bReadOnly);
				if(is_object($oHandle)){
					break;
				}
			}catch(VfsException $oVfsException){
				//If it's a hard error abort the operation
				if($oVfsException->isHard()){
					throw $oVfsException;
				}
			}
		}
		if(!is_object($oHandle)){
			throw new Exception("vfs: File/Dir not found (".$oEconetPath->getFilePath().")");
		}
		return $oHandle;
	}

	/**
	  * Get the directory catalogue from the supplied file-descriptor
	  *
	  * @return array
	 */
	static public function getDirectoryListing(object $oFd): array
	{
		$sPath = $oFd->getEconetPath();
		$aDirectoryListing = [];
		$aPlugins = Vfs::getVfsPlugins();
		foreach($aPlugins as $sPlugin){
			try {
				$aDirectoryListing = $sPlugin::getDirectoryListing($sPath,$aDirectoryListing);	
			}catch(VfsException $oVfsException){	
				if($oVfsException->isHard()){
					return [];
				}
			}
		}
		return $aDirectoryListing;
	}

	/**
	 * Get the next free id for a filehandle for the given user 
	 *
	 * Only 255 file handles a allowed per user, as file handle is identified by a single byte on the client
	 * The orignal file server only allowed 255 file handles total for the server, as we do it per user we
	 * can support as many clients as we want 
	 * @param \HomeLan\FileStore\Authentication\User $oUser
	 * @return int
	*/
	static public function getFreeFileHandleID(\HomeLan\FileStore\Authentication\User $oUser): int
	{
		if(!array_key_exists($oUser->getUserName(),Vfs::$aFileHandleIDs)){
			Vfs::$aFileHandleIDs[$oUser->getUserName()]=0;
		}
		Vfs::$aFileHandleIDs[$oUser->getUserName()]++;
		if(Vfs::$aFileHandleIDs[$oUser->getUserName()]>254){
			Vfs::$aFileHandleIDs[$oUser->getUserName()]=1;
		}
		return Vfs::$aFileHandleIDs[$oUser->getUserName()];
	}		

	/**
  * Creates a directory
  */
 static public function createDirectory(int $iNetwork,int $iStation,string $sEconetPath): void
	{
		if(!Security::isLoggedIn($iNetwork,$iStation)){
			self::$oLogger->debug("vfs: Un-able to create a handle for a station that is not logged in (Who are you?)");
			throw new Exception("vfs: Un-able to create a handle for a station that is not logged in (Who are you?)");
		}

		$oUser = Security::getUser($iNetwork,$iStation);
		$aPlugins = Vfs::getVfsPlugins();
		$oHandle=NULL;
		$oPath = Vfs::buildFullPath($iNetwork,$iStation,$sEconetPath);
		foreach($aPlugins as $sPlugin){
			try {
				if($sPlugin::createDirectory($oUser,$oPath)){
					return;
				}
			}catch(VfsException $oVfsException){
				//If it's a hard error abort the operation
				if($oVfsException->isHard()){
					throw $oVfsException;
				}
			}
		}
		throw new Exception("vfs: Unable to create directory (".$sEconetPath.")");
	}

	/**
  * Deletes a file (or directory)
  */
 static public function deleteFile(int $iNetwork,int $iStation,string $sEconetPath): void
	{
		if(!Security::isLoggedIn($iNetwork,$iStation)){
			self::$oLogger->debug("vfs: Un-able to create a handle for a station that is not logged in (Who are you?)");
			throw new Exception("vfs: Un-able to create a handle for a station that is not logged in (Who are you?)");
		}

		$oUser = Security::getUser($iNetwork,$iStation);
		$oPath = Vfs::buildFullPath($iNetwork,$iStation,$sEconetPath);
		$aPlugins = Vfs::getVfsPlugins();
		$oHandle=NULL;
		foreach($aPlugins as $sPlugin){
			try {
				if($sPlugin::deleteFile($oUser,$oPath)){
					return;
				}
			}catch(VfsException $oVfsException){
				//If it's a hard error abort the operation
				if($oVfsException->isHard()){
					throw $oVfsException;
				}
			}
		}
		throw new Exception("vfs: Unable to delete file (".$sEconetPath.")");
	}

	/**
  * Moves a file 
  *	 
  */
 static public function moveFile(int $iNetwork,int $iStation,string $sEconetPathFrom,string $sEconetPathTo): void
	{
		if(!Security::isLoggedIn($iNetwork,$iStation)){
			self::$oLogger->debug("vfs: Un-able to create a handle for a station that is not logged in (Who are you?)");
			throw new Exception("vfs: Un-able to create a handle for a station that is not logged in (Who are you?)");
		}
		$oUser = Security::getUser($iNetwork,$iStation);
		$oPathFrom = Vfs::buildFullPath($iNetwork,$iStation,$sEconetPathFrom);
		$oPathTo = Vfs::buildFullPath($iNetwork,$iStation,$sEconetPathTo);

		$aPlugins = Vfs::getVfsPlugins();
		$oHandle=NULL;
		foreach($aPlugins as $sPlugin){

			try {
				if($sPlugin::moveFile($oUser,$oPathFrom,$oPathTo)){
					return;
				}
			}catch(VfsException $oVfsException){
				//If it's a hard error abort the operation
				if($oVfsException->isHard()){
					throw $oVfsException;
				}
			}
		}
		throw new Exception("vfs: Unable to move file (".$sEconetPathFrom.")");
	}

	/**
  * Saves a file
  *
  * @param string $sData The raw file contents
  */
 static public function saveFile(int $iNetwork,int $iStation,string $sEconetPath,string $sData,int $iLoadAddr,int $iExecAddr): void
	{
		if(!Security::isLoggedIn($iNetwork,$iStation)){
			self::$oLogger->debug("vfs: Un-able to create a handle for a station that is not logged in (Who are you?)");
			throw new Exception("vfs: Un-able to create a handle for a station that is not logged in (Who are you?)");
		}
		$oUser = Security::getUser($iNetwork,$iStation);
		$oPath = Vfs::buildFullPath($iNetwork,$iStation,$sEconetPath);
		$aPlugins = Vfs::getVfsPlugins();
		$oHandle=NULL;
		foreach($aPlugins as $sPlugin){
			try {
				if($sPlugin::saveFile($oUser,$oPath,$sData,$iLoadAddr,$iExecAddr)){
					return;
				}
			}catch(VfsException $oVfsException){
				//If it's a hard error abort the operation
				if($oVfsException->isHard()){
					throw $oVfsException;
				}
			}
		}
		throw new Exception("vfs: Unable to save file (".$sEconetPath.")");
	}

	/**
  * Creates a file
  */
 static public function createFile(int $iNetwork,int $iStation,string $sEconetPath,int $iSize,int $iLoadAddr,int $iExecAddr): void
	{
		if(!Security::isLoggedIn($iNetwork,$iStation)){
			self::$oLogger->debug("vfs: Un-able to create a handle for a station that is not logged in (Who are you?)");
			throw new Exception("vfs: Un-able to create a handle for a station that is not logged in (Who are you?)");
		}
		$oUser = Security::getUser($iNetwork,$iStation);
		$oPath = Vfs::buildFullPath($iNetwork,$iStation,$sEconetPath);
		$aPlugins = Vfs::getVfsPlugins();
		$oHandle=NULL;
		foreach($aPlugins as $sPlugin){
			try {
				if($sPlugin::createFile($oUser,$oPath,$iSize,$iLoadAddr,$iExecAddr)){
					return;
				}
			}catch(VfsException $oVfsException){
				//If it's a hard error abort the operation
				if($oVfsException->isHard()){
					throw $oVfsException;
				}
			}
		}
		throw new Exception("vfs: Unable to save file (".$sEconetPath.")");
	}

	/**
  * Gets the contents of a file
  */
 static public function getFile(int $iNetwork,int $iStation,string $sEconetPath)
	{
		if(!Security::isLoggedIn($iNetwork,$iStation)){
			self::$oLogger->debug("vfs: Un-able to create a handle for a station that is not logged in (Who are you?)");
			throw new Exception("vfs: Un-able to create a handle for a station that is not logged in (Who are you?)");
		}
		$oUser = Security::getUser($iNetwork,$iStation);
		$oPath = Vfs::buildFullPath($iNetwork,$iStation,$sEconetPath);
		$aPlugins = Vfs::getVfsPlugins();
		$oHandle=NULL;
		foreach($aPlugins as $sPlugin){
			try {
				return $sPlugin::getFile($oUser,$oPath);
			}catch(VfsException $oVfsException){
				//If it's a hard error abort the operation
				if($oVfsException->isHard()){
					throw $oVfsException;
				}
			}
		}
		throw new Exception("vfs: Unable to get file (".$sEconetPath.")");

	}

	/**
  * Gets the meta data for a given file	
  */
 static public function getMeta(int $iNetwork,int $iStation,string $sEconetPath)
	{	
		if(!Security::isLoggedIn($iNetwork,$iStation)){
			self::$oLogger->debug("vfs: Un-able to create a handle for a station that is not logged in (Who are you?)");
			throw new Exception("vfs: Un-able to create a handle for a station that is not logged in (Who are you?)");
		}
		self::$oLogger->debug("vfs: getMeta for ".$sEconetPath);
		$oPath = Vfs::buildFullPath($iNetwork,$iStation,$sEconetPath);

		$aDirectoryListing = [];
		$aPlugins = Vfs::getVfsPlugins();
		foreach($aPlugins as $sPlugin){
			try {
				$aDirectoryListing = $sPlugin::getDirectoryListing($oPath->sDir,$aDirectoryListing);	
			}catch(VfsException $oVfsException){	
				if($oVfsException->isHard()){
					throw $oVfsException;
				}
			}
		}
		if(array_key_exists($oPath->sFile,$aDirectoryListing)){
			return $aDirectoryListing[$oPath->sFile];
		}else{
			//Try case insensative search
			foreach($aDirectoryListing as $sTestFileName => $oFile){
				if(trim(strtolower((string) $sTestFileName))==trim(strtolower((string) $oPath->sFile))){
					return $oFile;
				}
			}
			self::$oLogger->debug("VFS: getMeta no such file ".$oPath->sFile." in dir ".$oPath->sDir."");
			throw new Exception("No such file");
		}
	}

	/**
	 * Sets the meta data for a given file
	 *
	 * As all files must have a load exec and access mode setting the values to NULL means leave unchanged
	 * @param int $iNetwork
	 * @param int $iStation
	 * @param string $sEconetPath	
	 * @param int $iLoad
	 * @param int $iExec
	 * @param int $iAccess
	*/
	static public function setMeta(int $iNetwork,int $iStation,string $sEconetPath,?int $iLoad,?int $iExec,?int $iAccess): void
	{	
		if(!Security::isLoggedIn($iNetwork,$iStation)){
			self::$oLogger->debug("vfs: Un-able to create a handle for a station that is not logged in (Who are you?)");
			throw new Exception("vfs: Un-able to create a handle for a station that is not logged in (Who are you?)");
		}
		$oPath = Vfs::buildFullPath($iNetwork,$iStation,$sEconetPath);

		$aPlugins = Vfs::getVfsPlugins();
		foreach($aPlugins as $sPlugin){
			try {
				$sPlugin::setMeta($oPath->getFilePath(),$iLoad,$iExec,$iAccess);	
			}catch(VfsException $oVfsException){	
				if($oVfsException->isHard()){
					throw $oVfsException;
				}
			}
		}
	}

	/**
	 * Creates file handle for a given network/station to a given file path
	 *
	 * The file path supplied is a file path as seen by the client which must be converted to a local file path
	 *
	 * @param int $iNetwork
	 * @param int $iStation
	 * @param string $sEconetPath
	 * @param boolean $bMustExist
	 * @param boolean $bReadOnly
	*/
	static public function createFsHandle(int $iNetwork,int $iStation,string $sEconetPath,bool $bMustExist=TRUE,bool $bReadOnly=TRUE)
	{
		if(!Security::isLoggedIn($iNetwork,$iStation)){
			self::$oLogger->debug("vfs: Un-able to create a handle for a station that is not logged in (Who are you?)");
			throw new Exception("vfs: Un-able to create a handle for a station that is not logged in (Who are you?)");
		}

		$oUser = Security::getUser($iNetwork,$iStation);
		$oPath = Vfs::buildFullPath($iNetwork,$iStation,$sEconetPath);
		$oHandle = Vfs::_buildFiledescriptorFromEconetPath($oUser,$oPath,$bMustExist,$bReadOnly);

		//Store the handel for later use
		if(!array_key_exists($iNetwork,Vfs::$aHandles)){
			Vfs::$aHandles[$iNetwork]=[];
		}
		if(!array_key_exists($iStation,Vfs::$aHandles[$iNetwork])){
			Vfs::$aHandles[$iNetwork][$iStation]=[];
		}
		Vfs::$aHandles[$iNetwork][$iStation][$oHandle->getID()]=$oHandle;

		//Return the handle 
		return $oHandle;
	}

	/**
	 * Replaces one vfs handle with another
	 *
	 *
	*/
	static public function replaceFsHandle(int $iNetwork,int $iStation, $iHandleToReplace, $iNewHandle)
	{
		if(array_key_exists($iNetwork,Vfs::$aHandles) AND array_key_exists($iStation,Vfs::$aHandles[$iNetwork]) AND array_key_exists($iHandleToReplace,Vfs::$aHandles[$iNetwork][$iStation])){
			//Found the handle to replace
			if(array_key_exists($iNetwork,Vfs::$aHandles) AND array_key_exists($iStation,Vfs::$aHandles[$iNetwork]) AND array_key_exists($iNewHandle,Vfs::$aHandles[$iNetwork][$iStation])){
				//New handle exists
				Vfs::$aHandles[$iNetwork][$iStation][$iHandleToReplace] = Vfs::$aHandles[$iNetwork][$iStation][$iNewHandle];
			}
		}
	} 	

	/**
	  * Get a file handle object for a given network/station
	  *
	  * @param int $iHandle The filehandel used by the client
	*/
	static public function getFsHandle(int $iNetwork,int $iStation, $iHandle)
	{
		if(array_key_exists($iNetwork,Vfs::$aHandles) AND array_key_exists($iStation,Vfs::$aHandles[$iNetwork]) AND array_key_exists($iHandle,Vfs::$aHandles[$iNetwork][$iStation])){
			return Vfs::$aHandles[$iNetwork][$iStation][$iHandle];
		}
		//This is needed for older versions of NFS that sets up 2 directory handles for the CSD and LIBRARY but sends file handle 0 (which does not exist) for all directory listing requests :-0 
		if(array_key_exists($iNetwork,Vfs::$aHandles) AND array_key_exists($iStation,Vfs::$aHandles[$iNetwork]) AND count(Vfs::$aHandles[$iNetwork][$iStation])>0){
			//Assume the broken NFS rom means the first handle it set up CSD, not the one is actually asked for (which does not exist).
			$aIndex = array_keys(Vfs::$aHandles[$iNetwork][$iStation]);
			return Vfs::$aHandles[$iNetwork][$iStation][$aIndex[0]];
		}
		self::$oLogger->debug("vfs: Invalid file handle ".$iHandle." for ".$iNetwork.".".$iStation);
		throw new Exception("vfs: Invalid file handle ".$iHandle." for ".$iNetwork.".".$iStation);
	}

	/**
	  * Closes a file handle for a given network and station
	  *
	  * @param int $iHandle The filehandel used by the client
	*/
	static public function closeFsHandle(int $iNetwork,int $iStation, $iHandle): void
	{
		if(array_key_exists($iNetwork,Vfs::$aHandles) AND array_key_exists($iStation,Vfs::$aHandles[$iNetwork]) AND array_key_exists($iHandle,Vfs::$aHandles[$iNetwork][$iStation])){
			Vfs::$aHandles[$iNetwork][$iStation][$iHandle]->close();
			unset(Vfs::$aHandles[$iNetwork][$iStation][$iHandle]);
		}
	}


	/**
	  * Gets a sin for a full econet file path
	  *
	  * @return int A uniqe 24bit int for a file
	*/
	static public function getSin(string $sEconetFullFilePath): int
	{
		if(!array_key_exists($sEconetFullFilePath,Vfs::$aSinMapping)){
			Vfs::$iSin++;
			Vfs::$aSinMapping[$sEconetFullFilePath]=Vfs::$iSin;
		}
		return Vfs::$aSinMapping[$sEconetFullFilePath];
	}


}
