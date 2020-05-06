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

	static protected $aHandles = array();

	static protected $aFileHandleIDs = array();

	static protected $aSinMapping = array();

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
			self::$aSinMapping = array();
			self::$iSin = 1;
		}
	}

	/**
	 * Converts Econet style path to unix Abosolute of relative path to a fullpath
	 *
	 * This takes chroot in to account converting absolute chrooted path to real absolute path
	 * @return object FilePath
	 */ 
	private static function buildFullPath(int $iNetwork,int $iStation,string $sEconetPath): FilePath
	{
		if(strpos($sEconetPath,'$')===0){
			//Absolute path
			$aPath = explode('.',$sEconetPath);
			$sFile = array_pop($aPath);
			$sDir = join('.',$aPath);
		}elseif(strpos($sEconetPath,'.')!==FALSE){
			//Relitive path
			$oUser = security::getUser($iNetwork,$iStation);
			$aPath = explode('.',$sEconetPath);
			$sFile = array_pop($aPath);
			$sDir = $oUser->getCsd().'.'.join('.',$aPath);
		}else{
			//No path
			$oUser = security::getUser($iNetwork,$iStation);
			$sFile = $sEconetPath;
			$sDir = $oUser->getCsd();
		}
		if($oUser->getRoot()!='$'){
			$sDir = str_replace('$',$oUser->getRoot(),$sDir);
		}
		return new FilePath($sDir, $sFile);
	}

	/**
	 * Get a list of all the vfsplugin we should be using 
	 *
	 * It also calls the init method of each one when the class is loaded for the first time
	 *
	**/
	public static function getVfsPlugins(): array
	{
		$aReturn = array();
		$aVfsPlugins = explode(',',self::$sVfsPlugins);
		foreach($aVfsPlugins as $sPlugin){
			$sClassname = "\\HomeLan\\FileStore\\Vfs\\Plugin\\".$sPlugin;
			if(!class_exists($sClassname,FALSE)){
				try{
					$sClassname::init(self::$oLogger, self::$bMultiuser);
					$aReturn[]=$sClassname;
				}catch(Exception $oException){
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
	 * @param object $oUser The user the file descriptor is being created for 
	 * @param string $oEconetPath The econet file path
	 * @param boolean $bMustExist The path must exist
	 * @param boolean $bReadOnly If the file descriptor should be read-only
	 * @return object file-descriptor
	*/	
	static protected function _buildFiledescriptorFromEconetPath(object $oUser,FilePath $oEconetPath,bool $bMustExist,bool $bReadOnly): object
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
	 * @param object $oFd
	 * @return array
	*/
	static public function getDirectoryListing(object $oFd): array
	{
		$sPath = $oFd->getEconetPath();
		$aDirectoryListing = array();
		$aPlugins = Vfs::getVfsPlugins();
		foreach($aPlugins as $sPlugin){
			try {
				$aDirectoryListing = $sPlugin::getDirectoryListing($sPath,$aDirectoryListing);	
			}catch(VfsException $oVfsException){	
				if($oVfsException->isHard()){
					return array();
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
	 * @param object user $oUser
	 * @return int
	*/
	static public function getFreeFileHandleID($oUser): int
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
	 * 
	 * @param int $iNetwork
	 * @param int $iStation
	 * @param string $sEconetPath
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
	 *
	 * @param int $iNetwork
	 * @param int $iStation
	 * @param string $sEconetPath
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
	 * @param int $iNetwork
	 * @param int $iStation
	 * @param string $sEconetPathFrom
	 * @param string $sEconetPathTo	 
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
	 * @param int $iNetwork
	 * @param int $iStation
	 * @param string $sEconetPath
	 * @param string $sData The raw file contents
	 * @param int $iLoadAddr
	 * @param int $iExecAddr
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
	 *
	 * @param int $iNetwork
	 * @param int $iStation
	 * @param string $sEconetPath
	 * @param int $iSize
	 * @param int $iLoadAddr
	 * @param int $iExecAddr
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
	 *
	 * @param int $iNetwork
	 * @param int $iStation
	 * @param string $sEconetPath
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
	 *
	 * @param int $iNetwork
	 * @param int $iStation
	 * @param string $sEconetPath	
	*/
	static public function getMeta(int $iNetwork,int $iStation,string $sEconetPath)
	{	
		if(!Security::isLoggedIn($iNetwork,$iStation)){
			self::$oLogger->debug("vfs: Un-able to create a handle for a station that is not logged in (Who are you?)");
			throw new Exception("vfs: Un-able to create a handle for a station that is not logged in (Who are you?)");
		}
		self::$oLogger->debug("vfs: getMeta for ".$sEconetPath);
		$oPath = Vfs::buildFullPath($iNetwork,$iStation,$sEconetPath);

		$aDirectoryListing = array();
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
				if(trim(strtolower($sTestFileName))==trim(strtolower($oPath->sFile))){
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
	static public function setMeta(int $iNetwork,int $iStation,string $sEconetPath,int $iLoad,int $iExec,int $iAccess): void
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
			Vfs::$aHandles[$iNetwork]=array();
		}
		if(!array_key_exists($iStation,Vfs::$aHandles[$iNetwork])){
			Vfs::$aHandles[$iNetwork][$iStation]=array();
		}
		Vfs::$aHandles[$iNetwork][$iStation][$oHandle->getID()]=$oHandle;

		//Return the handle 
		return $oHandle;
	}

	/**
	 * Get a file handle object for a given network/station
	 *
	 * @param int $iNetwork
	 * @param int $iStation
	 * @param int $iHandle The filehandel used by the client
	*/
	static public function getFsHandle(int $iNetwork,int $iStation,int $iHandle)
	{
		if(array_key_exists($iNetwork,Vfs::$aHandles) AND array_key_exists($iStation,Vfs::$aHandles[$iNetwork]) AND array_key_exists($iHandle,Vfs::$aHandles[$iNetwork][$iStation])){
			return Vfs::$aHandles[$iNetwork][$iStation][$iHandle];
		}
		self::$oLogger->debug("vfs: Invalid file handle ".$iHandle." for ".$iNetwork.".".$iStation);
		throw new Exception("vfs: Invalid file handle ".$iHandle." for ".$iNetwork.".".$iStation);
	}

	/**
	 * Closes a file handle for a given network and station
	 *
	 * @param int $iNetwork
	 * @param int $iStation
	 * @param int $iHandle The filehandel used by the client
	*/
	static public function closeFsHandle(int $iNetwork,int $iStation,int $iHandle): void
	{
		if(array_key_exists($iNetwork,Vfs::$aHandles) AND array_key_exists($iStation,Vfs::$aHandles[$iNetwork]) AND array_key_exists($iHandle,Vfs::$aHandles[$iNetwork][$iStation])){
			Vfs::$aHandles[$iNetwork][$iStation][$iHandle]->close();
			unset(Vfs::$aHandles[$iNetwork][$iStation][$iHandle]);
		}
	}


	/**
	 * Gets a sin for a full econet file path
	 *
	 * @param string $sEconetFullFilePath
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
