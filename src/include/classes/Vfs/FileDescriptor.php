<?php
/**
 * This file contains the file descriptor class
 *
 * @author John Brown <john@home-lan.co.uk>
 * @package corevfs
*/
namespace HomeLan\FileStore\Vfs;

use HomeLan\FileStore\Vfs\Exception as VfsException;
use HomeLan\FileStore\Authentication\User;
/**
 * Econet NetFs uses filedecriptor ids with its client for all file operations.  The file handles
 * identitier is a single byte.  This class represents a single file description for the server
 * and maps to a local file handle and remote user.
 *
 * @package corevfs
*/
class FileDescriptor {

	protected int $iHandle;

	protected User $oUser;

	protected string $sVfsPlugin;

	/** @var mixed The plugin-local handle: int for image/array-backed plugins, resource for stream-backed plugins, or a string in test doubles. */
	protected mixed $iVfsHandle = NULL;

	protected string $sUnixFilePath;

	protected string $sEconetFilePath;

	protected bool $bFile;

	protected bool $bDir;

	protected \Psr\Log\LoggerInterface $oLogger;

	public function __construct(\Psr\Log\LoggerInterface $oLogger,string $sVfsPlugin, User $oUser, string $sUnixFilePath, string $sEconetFilePath, mixed $iVfsHandle, int $iEconetHandle, bool $bFile=FALSE, bool $bDir=FALSE)
	{
		$this->oLogger = $oLogger;
		$this->sVfsPlugin = $sVfsPlugin;
		$this->oUser = $oUser;
		$this->sUnixFilePath = $sUnixFilePath;
		$this->sEconetFilePath = $sEconetFilePath;
		$this->iVfsHandle = $iVfsHandle;
		$this->iHandle = $iEconetHandle;
		$this->bFile = $bFile;
		$this->bDir = $bDir;
	}

	public function getEconetPath(): string
	{
		return $this->sEconetFilePath;
	}

	public function getEconetDirName(): ?string
	{
		if(str_contains($this->sEconetFilePath,'.')){
			$aParts = explode('.',$this->sEconetFilePath);
			return array_pop($aParts);
		}elseif(strlen($this->sEconetFilePath)>0){
			return $this->sEconetFilePath;
		}else{
			return '$';
		}
	}

	public function getEconetParentPath(): string
	{
		//Build the path with out the last dir
		$aPathParts = explode('.',$this->sEconetFilePath);
		$sParentPath = "";
		for($i=0;$i<(count($aPathParts)-1);$i++){
			$sParentPath = $sParentPath.$aPathParts[$i].".";
		}
		if(strlen($sParentPath)>0){
			//Our parent is not $ return the parent path but trim the trailing .
			return trim($sParentPath,'.');
		}
		return '$';
	}

	public function changeVfs(): void
	{
		$aPlugins = Vfs::getVfsPlugins();
		$iIndex = array_search($this->sVfsPlugin,$aPlugins);
		$this->iVfsHandle = NULL;
		if($iIndex!==FALSE){
			$iIndex--;
			if(!array_key_exists($iIndex,$aPlugins)){
				throw new VfsException("No vfs pluings left to try",TRUE);
			}
			$sPlugin = $aPlugins[$iIndex];
			$this->oLogger->debug("filedescriptor: Changing vfsplugin to ".$sPlugin." due to softerror from ".$this->sVfsPlugin);

			$this->sVfsPlugin = $sPlugin;
			$sUnixPath = $sPlugin::_getUnixPathFromEconetPath($this->sEconetFilePath);

			if(strlen((string) $sUnixPath)<1){
				//This vfs module can't process the econetpath try the next
				$this->changeVfs();
			}
			$this->sUnixFilePath=$sUnixPath;
			$this->iVfsHandle = $sPlugin::_getHandleFromEconetPath($this->sEconetFilePath);

		}
	}


	public function getID(): int
	{
		return $this->iHandle;
	}

	public function isDir(): bool
	{
		return $this->bDir;
	}

	public function isFile(): bool
	{
		return (bool) $this->bFile;
	}

	public function fsFTell(): mixed
	{
		if(!is_null($this->iVfsHandle)){
			$sPlugin = $this->sVfsPlugin;
			try {
				return $sPlugin::fsFTell($this->oUser,$this->iVfsHandle);
			}catch(VfsException $oVfsException){
				if($oVfsException->isHard()){
					throw $oVfsException;
				}
				$this->changeVfs();
				return $this->fsFTell();
			}
		}
		return null;
	}

	public function fsFStat(): mixed
	{
		if(!is_null($this->iVfsHandle)){
	//		try {
				$sPlugin = $this->sVfsPlugin;
				return $sPlugin::fsFStat($this->oUser,$this->iVfsHandle);
	//		}catch(VfsException $oVfsException){
	//			if($oVfsException->isHard()){
	//				throw $oVfsException;
	//			}
	//			$this->changeVfs();
	//			return $this->fsTell();
	//		}
		}
		return null;
	}

	public function isEof(): mixed
	{
		if(!is_null($this->iVfsHandle)){
			try {
				$sPlugin = $this->sVfsPlugin;
				return $sPlugin::isEof($this->oUser,$this->iVfsHandle);
			}catch(VfsException $oVfsException){
				if($oVfsException->isHard()){
					throw $oVfsException;
				}
				$this->changeVfs();
				return $this->isEof();
			}
		}
		return null;
	}

	public function setPos(int $iPos): mixed
	{
		if(!is_null($this->iVfsHandle)){
			try {
				$sPlugin = $this->sVfsPlugin;
				return $sPlugin::setPos($this->oUser,$this->iVfsHandle,$iPos);
			}catch(VfsException $oVfsException){
				if($oVfsException->isHard()){
					throw $oVfsException;
				}
				$this->changeVfs();
				return $this->setPos($iPos);
			}
		}
		return null;
	}

	public function setExt(int $iExt): void
	{
		if(!is_null($this->iVfsHandle)){
			try {
				$sPlugin = $this->sVfsPlugin;
				$sPlugin::setExt($this->oUser,$this->iVfsHandle,$iExt);
			}catch(VfsException $oVfsException){
				if($oVfsException->isHard()){
					throw $oVfsException;
				}
				$this->changeVfs();
				$this->setExt($iExt);
			}
		}
	}

	public function read(int $iLength): mixed
	{
		if(!is_null($this->iVfsHandle)){
			try {
				$sPlugin = $this->sVfsPlugin;
				return $sPlugin::read($this->oUser,$this->iVfsHandle,$iLength);
			}catch(VfsException $oVfsException){
				if($oVfsException->isHard()){
					throw $oVfsException;
				}
				$this->changeVfs();
				return $this->read($iLength);
			}
		}
		return null;
	}

	public function write(string $sData): mixed
	{
		if(!is_null($this->iVfsHandle)){
			try {
				$sPlugin = $this->sVfsPlugin;
				return $sPlugin::write($this->oUser,$this->iVfsHandle,$sData);
			}catch(VfsException $oVfsException){
				if($oVfsException->isHard()){
					throw $oVfsException;
				}
				$this->changeVfs();
				return $this->write($sData);
			}
		}
		return null;
	}

	public function fsLock(bool $bExclusive): void
	{
		if(!is_null($this->iVfsHandle)){
			$sPlugin = $this->sVfsPlugin;
			$sPlugin::fsLock($this->oUser,$this->iVfsHandle,$bExclusive);
		}
	}

	public function fsUnlock(): void
	{
		if(!is_null($this->iVfsHandle)){
			$sPlugin = $this->sVfsPlugin;
			$sPlugin::fsUnlock($this->oUser,$this->iVfsHandle);
		}
	}

	public function close(): mixed
	{
		if(!is_null($this->iVfsHandle)){
			$sPlugin = $this->sVfsPlugin;
			return $sPlugin::fsClose($this->oUser,$this->iVfsHandle);
		}
		return null;
	}
}
