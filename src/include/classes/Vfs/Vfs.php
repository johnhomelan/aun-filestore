<?php

namespace HomeLan\FileStore\Vfs; 
/**
 * This file contains the vfs class
 *
 * @package corevfs
 *
*/

use security;
use logger; 
use Exception;
use config;
use HomeLan\FileStore\Vfs\Exception as VfsException;

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

	public static function init()
	{
		$aPlugins = Vfs::getVfsPlugins();
	}


	/**
	 * Runs the house keeping functions for all the vfs plugins
	 *
	 *
	*/
	public static function houseKeeping()
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
					$oUser = security::getUser($iNetwork,$iStation);
					if(!is_object($oUser)){
						logger::log("vfs: Removing handle ".$iHandle." for station ".$iNetwork.":".$iStation." as the session has expired",LOG_DEBUG);
						$oHandle->close();
						unset(self::$aHandles[$iNetwork][$iStation][$iHandle]);
					}
				}
			}
		}

		//Clear up sin history
		$aUsers = security::getUsersOnline();
		if(count($aUsers)==0){
			logger::log("vfs: No users logged in resetting sin history",LOG_DEBUG);
			self::$aSinMapping = array();
			self::$iSin = 1;
		}
	}

	/**
	 * Get a list of all the vfsplugin we should be using 
	 *
	 * It also calls the init method of each one when the class is loaded for the first time
	 *
	**/
	public static function getVfsPlugins()
	{
		$aReturn = array();
		$aVfsPlugins = explode(',',config::getValue('vfs_plugins'));
		foreach($aVfsPlugins as $sPlugin){
			$sClassname = "\\HomeLan\\FileStore\\Vfs\\Plugin\\".$sPlugin;
			if(!class_exists($sClassname,FALSE)){
				try{
					$sClassname::init();
					$aReturn[]=$sClassname;
				}catch(Exception $oException){
					logger::log("VFS: Unable to load vfs plugin ".$sClassname,LOG_INFO);
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
	 * @param string $sCsd The currently selected director path
	 * @param string $sEconetPath The econet file path
	 * @param boolean $bMustExist The path must exist
	 * @param boolean $bReadOnly If the file descriptor should be read-only
	 * @return object file-descriptor
	*/	
	static protected function _buildFiledescriptorFromEconetPath($oUser,$sCsd,$sEconetPath,$bMustExist,$bReadOnly)
	{
		$aPlugins = Vfs::getVfsPlugins();
		$oHandle=NULL;
		foreach($aPlugins as $sPlugin){
			try {
				$oHandle = $sPlugin::_buildFiledescriptorFromEconetPath($oUser,$sCsd,$sEconetPath,$bMustExist,$bReadOnly);
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
			throw new Exception("vfs: File/Dir not found (".$sEconetPath.")");
		}
		return $oHandle;
	}

	/**
	 * Get the directory catalogue from the supplied file-descriptor
	 *
	 * @param object $oFd
	 * @return array
	*/
	static public function getDirectoryListing($oFd)
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
	static public function getFreeFileHandleID($oUser)
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
	static public function createDirectory($iNetwork,$iStation,$sEconetPath)
	{
		if(!security::isLoggedIn($iNetwork,$iStation)){
			logger::log("vfs: Un-able to create a handle for a station that is not logged in (Who are you?)",LOG_DEBUG);
			throw new Exception("vfs: Un-able to create a handle for a station that is not logged in (Who are you?)");
		}
		$oUser = security::getUser($iNetwork,$iStation);
		$sCsd = $oUser->getCsd();
		$aPlugins = Vfs::getVfsPlugins();
		$oHandle=NULL;
		foreach($aPlugins as $sPlugin){
			try {
				if($sPlugin::createDirectory($oUser,$sCsd,$sEconetPath)){
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
	static public function deleteFile($iNetwork,$iStation,$sEconetPath)
	{
		if(!security::isLoggedIn($iNetwork,$iStation)){
			logger::log("vfs: Un-able to create a handle for a station that is not logged in (Who are you?)",LOG_DEBUG);
			throw new Exception("vfs: Un-able to create a handle for a station that is not logged in (Who are you?)");
		}
		$oUser = security::getUser($iNetwork,$iStation);
		$sCsd = $oUser->getCsd();
		$aPlugins = Vfs::getVfsPlugins();
		$oHandle=NULL;
		foreach($aPlugins as $sPlugin){
			try {
				if($sPlugin::deleteFile($oUser,$sCsd,$sEconetPath)){
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
	static public function moveFile($iNetwork,$iStation,$sEconetPathFrom,$sEconetPathTo)
	{
		if(!security::isLoggedIn($iNetwork,$iStation)){
			logger::log("vfs: Un-able to create a handle for a station that is not logged in (Who are you?)",LOG_DEBUG);
			throw new Exception("vfs: Un-able to create a handle for a station that is not logged in (Who are you?)");
		}
		$oUser = security::getUser($iNetwork,$iStation);
		$sCsd = $oUser->getCsd();
		$aPlugins = Vfs::getVfsPlugins();
		$oHandle=NULL;
		foreach($aPlugins as $sPlugin){

			try {
				if($sPlugin::moveFile($oUser,$sCsd,$sEconetPathFrom,$sEconetPathTo)){
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
	static public function saveFile($iNetwork,$iStation,$sEconetPath,$sData,$iLoadAddr,$iExecAddr)
	{
		if(!security::isLoggedIn($iNetwork,$iStation)){
			logger::log("vfs: Un-able to create a handle for a station that is not logged in (Who are you?)",LOG_DEBUG);
			throw new Exception("vfs: Un-able to create a handle for a station that is not logged in (Who are you?)");
		}
		$oUser = security::getUser($iNetwork,$iStation);
		$sCsd = $oUser->getCsd();
		$aPlugins = Vfs::getVfsPlugins();
		$oHandle=NULL;
		foreach($aPlugins as $sPlugin){
			try {
				if($sPlugin::saveFile($oUser,$sCsd,$sEconetPath,$sData,$iLoadAddr,$iExecAddr)){
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
	static public function createFile($iNetwork,$iStation,$sEconetPath,$iSize,$iLoadAddr,$iExecAddr)
	{
		if(!security::isLoggedIn($iNetwork,$iStation)){
			logger::log("vfs: Un-able to create a handle for a station that is not logged in (Who are you?)",LOG_DEBUG);
			throw new Exception("vfs: Un-able to create a handle for a station that is not logged in (Who are you?)");
		}
		$oUser = security::getUser($iNetwork,$iStation);
		$sCsd = $oUser->getCsd();
		$aPlugins = Vfs::getVfsPlugins();
		$oHandle=NULL;
		foreach($aPlugins as $sPlugin){
			try {
				if($sPlugin::createFile($oUser,$sCsd,$sEconetPath,$iSize,$iLoadAddr,$iExecAddr)){
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
	static public function getFile($iNetwork,$iStation,$sEconetPath)
	{
		if(!security::isLoggedIn($iNetwork,$iStation)){
			logger::log("vfs: Un-able to create a handle for a station that is not logged in (Who are you?)",LOG_DEBUG);
			throw new Exception("vfs: Un-able to create a handle for a station that is not logged in (Who are you?)");
		}
		$oUser = security::getUser($iNetwork,$iStation);
		$sCsd = $oUser->getCsd();
		$aPlugins = Vfs::getVfsPlugins();
		$oHandle=NULL;
		foreach($aPlugins as $sPlugin){
			try {
				return $sPlugin::getFile($oUser,$sCsd,$sEconetPath);
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
	static public function getMeta($iNetwork,$iStation,$sEconetPath)
	{	
		if(!security::isLoggedIn($iNetwork,$iStation)){
			logger::log("vfs: Un-able to create a handle for a station that is not logged in (Who are you?)",LOG_DEBUG);
			throw new Exception("vfs: Un-able to create a handle for a station that is not logged in (Who are you?)");
		}
		logger::log("vfs: getMeta for ".$sEconetPath,LOG_DEBUG);
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

		$aDirectoryListing = array();
		$aPlugins = Vfs::getVfsPlugins();
		foreach($aPlugins as $sPlugin){
			try {
				$aDirectoryListing = $sPlugin::getDirectoryListing($sDir,$aDirectoryListing);	
			}catch(VfsException $oVfsException){	
				if($oVfsException->isHard()){
					throw $oVfsException;
				}
			}
		}
		if(array_key_exists($sFile,$aDirectoryListing)){
			return $aDirectoryListing[$sFile];
		}else{
			//Try case insensative search
			foreach($aDirectoryListing as $sTestFileName => $oFile){
				if(trim(strtolower($sTestFileName))==trim(strtolower($sFile))){
					return $oFile;
				}
			}
			logger::log("VFS: getMeta no such file ".$sFile." in dir ".$sDir."",LOG_DEBUG);
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
	static public function setMeta($iNetwork,$iStation,$sEconetPath,$iLoad,$iExec,$iAccess)
	{	
		if(!security::isLoggedIn($iNetwork,$iStation)){
			logger::log("vfs: Un-able to create a handle for a station that is not logged in (Who are you?)",LOG_DEBUG);
			throw new Exception("vfs: Un-able to create a handle for a station that is not logged in (Who are you?)");
		}
		if(strpos($sEconetPath,'$')===0){
			//Absolute path
			$sPath = $sEconetPath;
		}else{
			$oUser = security::getUser($iNetwork,$iStation);
			$sPath = $oUser->getCsd().'.'.trim($sEconetPath,'.');
		}

		$aPlugins = Vfs::getVfsPlugins();
		foreach($aPlugins as $sPlugin){
			try {
				$sPlugin::setMeta($sPath,$iLoad,$iExec,$iAccess);	
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
	static public function createFsHandle($iNetwork,$iStation,$sEconetPath,$bMustExist=TRUE,$bReadOnly=TRUE)
	{
		if(!security::isLoggedIn($iNetwork,$iStation)){
			logger::log("vfs: Un-able to create a handle for a station that is not logged in (Who are you?)",LOG_DEBUG);
			throw new Exception("vfs: Un-able to create a handle for a station that is not logged in (Who are you?)");
		}
		$oUser = security::getUser($iNetwork,$iStation);
		$sCsd = $oUser->getCsd();
		$oHandle = Vfs::_buildFiledescriptorFromEconetPath($oUser,$sCsd,$sEconetPath,$bMustExist,$bReadOnly);

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
	static public function getFsHandle($iNetwork,$iStation,$iHandle)
	{
		if(array_key_exists($iNetwork,Vfs::$aHandles) AND array_key_exists($iStation,Vfs::$aHandles[$iNetwork]) AND array_key_exists($iHandle,Vfs::$aHandles[$iNetwork][$iStation])){
			return Vfs::$aHandles[$iNetwork][$iStation][$iHandle];
		}
		logger::log("vfs: Invalid file handle ".$iHandle." for ".$iNetwork.".".$iStation,LOG_DEBUG);
		throw new Exception("vfs: Invalid file handle ".$iHandle." for ".$iNetwork.".".$iStation);
	}

	/**
	 * Closes a file handle for a given network and station
	 *
	 * @param int $iNetwork
	 * @param int $iStation
	 * @param int $iHandle The filehandel used by the client
	*/
	static public function closeFsHandle($iNetwork,$iStation,$iHandle)
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
	static public function getSin($sEconetFullFilePath)
	{
		if(!array_key_exists($sEconetFullFilePath,Vfs::$aSinMapping)){
			Vfs::$iSin++;
			Vfs::$aSinMapping[$sEconetFullFilePath]=Vfs::$iSin;
		}
		return Vfs::$aSinMapping[$sEconetFullFilePath];
	}


}
