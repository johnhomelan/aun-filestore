<?php
/**
 * This file contains the fileserver path-based catalog/metadata handler
 *
 * @author John Brown <john@home-lan.co.uk>
 * @package core
*/
namespace HomeLan\FileStore\Services\Provider\FileServer;

use HomeLan\FileStore\Services\Provider\FileServer;
use HomeLan\FileStore\Messages\FsRequest;

use config;
use Exception;

/**
 * Handles operations addressed by an Econet path (as opposed to an already-open
 * file handle): directory listings, file/directory metadata (*INFO, *.),
 * *CAT, creating/deleting/renaming by path, and changing the caller's current
 * directory/library. Reachable both via raw FS function codes and via "*" CLI
 * commands dispatched from Cli::runCli().
 *
 * @package core
*/
class Catalog {

	public function __construct(private readonly FileServer $oProvider)
	{
	}

	/**
	  * Handles the *info command
	  *
	  * @param fsrequest $oFsRequest
	*/
	public function cmdInfo(FsRequest $oFsRequest,string $sFile): void
	{
		$this->oProvider->getLogger()->debug("cmdInfo for path ".$sFile."");
		$oReply = $oFsRequest->buildReply();
		if(!is_object($this->oProvider->secGetUser($oFsRequest->getSourceNetwork(),$oFsRequest->getSourceStation()))){
			$oReply->setError(0xbf,"Who are you?");
			$this->oProvider->addReplyToBuffer($oReply->buildEconetpacket());
			return;
		}
		try {
			$oMeta = $this->oProvider->vfsGetMeta($oFsRequest->getSourceNetwork(),$oFsRequest->getSourceStation(),$sFile);
			$sReplyData =  sprintf("%-10.10s %08X %08X   %06X   %-6.6s  %02d:%02d:%02d %06x\r\x80",$sFile,$oMeta->getLoadAddr(),$oMeta->getExecAddr(),$oMeta->getSize(),$oMeta->getEconetMode(),$oMeta->getDay(),$oMeta->getMonth(),$oMeta->getYear(),$oMeta->getSin());
			$this->oProvider->getLogger()->debug("INFO ".$sFile." Load: ".$oMeta->getLoadAddr()." Exec: ".$oMeta->getExecAddr()." Size:".$oMeta->getSize());
			$oReply->InfoOk();
			//Append Type
			$oReply->appendString($sReplyData);
		}catch(Exception){
			$oReply->setError(0xff,"No such file");
		}
		$this->oProvider->addReplyToBuffer($oReply->buildEconetpacket());
	}

	/**
	 * Handles requests for information on directories and files
	 *
	 * This method is called when the client uses *. to produce the directory header
	 * @param fsrequest $oFsRequest
	*/
	public function getInfo(FsRequest $oFsRequest): void
	{
		$sDir = $oFsRequest->getString(2);
		$this->oProvider->getLogger()->debug("getInfo for path ".$sDir." (".$oFsRequest->getByte(1).")");
		switch($oFsRequest->getByte(1)){
			case 1:
				//EC_FS_GET_INFO_LOAD
				$oReply = $oFsRequest->buildReply();
				try {
					$oMeta = $this->oProvider->vfsGetMeta($oFsRequest->getSourceNetwork(),$oFsRequest->getSourceStation(),$sDir);
					$oReply->DoneOk();
					if($oMeta->isDir()){
						$oReply->appendByte(0x02);
					}else{
						$oReply->appendByte(0x01);
					}
					$oReply->append32bitIntLittleEndian($oMeta->getLoadAddr() ?? 0);
				}catch(Exception){
					$oReply->setError(0xd6,"Not found");
				}
				$this->oProvider->addReplyToBuffer($oReply->buildEconetpacket());
				return;
			case 2:
				//EC_FS_GET_INFO_EXEC
				$oReply = $oFsRequest->buildReply();
				try {
					$oMeta = $this->oProvider->vfsGetMeta($oFsRequest->getSourceNetwork(),$oFsRequest->getSourceStation(),$sDir);
					$oReply->DoneOk();
					if($oMeta->isDir()){
						$oReply->appendByte(0x02);
					}else{
						$oReply->appendByte(0x01);
					}
					$oReply->append32bitIntLittleEndian($oMeta->getExecAddr() ?? 0);
				}catch(Exception){
					$oReply->setError(0xd6,"Not found");
				}
				$this->oProvider->addReplyToBuffer($oReply->buildEconetpacket());
				return;
			case 4:
				//EC_FS_GET_INFO_ACCESS
				$oReply = $oFsRequest->buildReply();
				try {
					$oMeta = $this->oProvider->vfsGetMeta($oFsRequest->getSourceNetwork(),$oFsRequest->getSourceStation(),$sDir);
					$oReply->DoneOk();
					//Append Type
					if($oMeta->isDir()){
						$oReply->appendByte(0x02);
					}else{
						$oReply->appendByte(0x01);
					}
					$oReply->appendByte($oMeta->getAccess());
				}catch(Exception){
					$oReply->setError(0xd6,"Not found");
				}
				$this->oProvider->addReplyToBuffer($oReply->buildEconetpacket());
				return;
			case 5:
				//EC_FS_GET_INFO_ALL
				$oReply = $oFsRequest->buildReply();
				try {
					$oMeta = $this->oProvider->vfsGetMeta($oFsRequest->getSourceNetwork(),$oFsRequest->getSourceStation(),$sDir);
					$oReply->DoneOk();
					//Append Type
					if($oMeta->isDir()){
						$oReply->appendByte(0x02);
					}else{
						$oReply->appendByte(0x01);
					}
					$oReply->append32bitIntLittleEndian($oMeta->getLoadAddr() ?? 0);
					$oReply->append32bitIntLittleEndian($oMeta->getExecAddr() ?? 0);
					$oReply->append24bitIntLittleEndian($oMeta->getSize());
					$oReply->appendByte($oMeta->getAccess());
					//Add current date
					$oReply->appendRaw($oMeta->getCTime());
				}catch(Exception){
					$oReply->setError(0xd6,"Not found");
				}
				$this->oProvider->addReplyToBuffer($oReply->buildEconetpacket());
				return;
			case 3:
				//EC_FS_GET_INFO_SIZE
				$oReply = $oFsRequest->buildReply();
				try {
					$oMeta = $this->oProvider->vfsGetMeta($oFsRequest->getSourceNetwork(),$oFsRequest->getSourceStation(),$sDir);
					$oReply->DoneOk();
					//Append Type
					if($oMeta->isDir()){
						$oReply->appendByte(0x02);
					}else{
						$oReply->appendByte(0x01);
					}
					$oReply->append24bitIntLittleEndian($oMeta->getSize());
				}catch(Exception){
					$oReply->setError(0xd6,"Not found");
				}
				$this->oProvider->addReplyToBuffer($oReply->buildEconetpacket());
				return;
			case 6:
				//EC_FS_GET_INFO_DIR
				try {
					$oReply = $oFsRequest->buildReply();
					$oReply->DoneOk();
					//undef0
					$oReply->appendByte(0);
					//zero
					$oReply->appendByte(0);
					//ten  need by beeb nfs
					$oReply->appendByte(10);

					//dir name fixed to 10 bytes right padded with spaces
					if($sDir==""){
						//No dir requested so use csd
						$oFd = $this->oProvider->vfsGetFsHandle($oFsRequest->getSourceNetwork(),$oFsRequest->getSourceStation(),$oFsRequest->getCsd());
						$oReply->appendString(str_pad(substr((string) $oFd->getEconetDirName(),0,10),10,' '));
						$oMeta = $this->oProvider->vfsGetMeta($oFsRequest->getSourceNetwork(),$oFsRequest->getSourceStation(),$oFd->getEconetPath());
					}else{
						$oReply->appendString(str_pad(substr((string) $sDir,0,10),10,' '));
						$oMeta = $this->oProvider->vfsGetMeta($oFsRequest->getSourceNetwork(),$oFsRequest->getSourceStation(),$sDir);
					}

					$oReply->appendByte($oMeta->getAccess());

					//Cyle  always 0 probably should not be
					$oReply->appendByte(0);

					$this->oProvider->addReplyToBuffer($oReply->buildEconetpacket());

				}catch(Exception){
					$oReply = $oFsRequest->buildReply();
					$oReply->setError(0x8e,"Bad INFO argument");
					$this->oProvider->addReplyToBuffer($oReply->buildEconetpacket());
				}
				return;
			case 7:
				//EC_FS_GET_INFO_UID
				$oReply = $oFsRequest->buildReply();
				try {
					$oMeta = $this->oProvider->vfsGetMeta($oFsRequest->getSourceNetwork(),$oFsRequest->getSourceStation(),$sDir);
					$oReply->DoneOk();
					if($oMeta->isDir()){
						$oReply->appendByte(0x02);
					}else{
						$oReply->appendByte(0x01);
					}
					$oReply->append24bitIntLittleEndian($oMeta->getSin());
				}catch(Exception){
					$oReply->setError(0xd6,"Not found");
				}
				$this->oProvider->addReplyToBuffer($oReply->buildEconetpacket());
				return;
			default:
				//Don't do any thing fall to the bad info reply below
				break;
		}
		$oReply = $oFsRequest->buildReply();
		$oReply->setError(0x8e,"Bad INFO argument");
		$this->oProvider->addReplyToBuffer($oReply->buildEconetpacket());
	}

	/**
	 * EC_FS_FUNC_SET_INFO — sets a file's load address, exec address, and/or
	 * access mode by path.
	*/
	public function setInfo(FsRequest $oFsRequest): void
	{
		$iArg = $oFsRequest->getByte(1);
		$oReply = $oFsRequest->buildReply();
		switch($iArg){
			case 1:
				//EC_FS_SET_INFO_ALL
				$iLoad = $oFsRequest->get32bitIntLittleEndian(2);
				$iExec = $oFsRequest->get32bitIntLittleEndian(6);
				$iAccess = $oFsRequest->getByte(10);
				$sPath = $oFsRequest->getString(11);
				$this->oProvider->vfsSetMeta($oFsRequest->getSourceNetwork(),$oFsRequest->getSourceStation(),$sPath,$iLoad,$iExec,$iAccess);
				break;
			case 2:
				//EC_FS_SET_INFO_LOAD
				$iLoad = $oFsRequest->get32bitIntLittleEndian(2);
				$sPath = $oFsRequest->getString(6);
				$this->oProvider->vfsSetMeta($oFsRequest->getSourceNetwork(),$oFsRequest->getSourceStation(),$sPath,$iLoad,NULL,NULL);
				break;
			case 3:
				//EC_FS_SET_INFO_EXEC
				$iExec = $oFsRequest->get32bitIntLittleEndian(2);
				$sPath = $oFsRequest->getString(6);
				$this->oProvider->vfsSetMeta($oFsRequest->getSourceNetwork(),$oFsRequest->getSourceStation(),$sPath,NULL,$iExec,NULL);
				break;
			case 4:
				//EC_FS_SET_INFO_ACCESS
				$iAccess =$oFsRequest->getByte(2);
				$sPath = $oFsRequest->getString(3);
				$this->oProvider->vfsSetMeta($oFsRequest->getSourceNetwork(),$oFsRequest->getSourceStation(),$sPath,NULL,NULL,$iAccess);
				break;
			default:
				$oReply->setError(0x8e,"Bad SETINFO argument");
				$this->oProvider->addReplyToBuffer($oReply->buildEconetpacket());
				return;
		}
		$oReply->DoneOk();
		$this->oProvider->addReplyToBuffer($oReply->buildEconetpacket());
	}

	/**
	 * Gets the details of a director/file
	 *
	 * This method produces the directory listing for *.
	 * @param fsrequest $oFsRequest
	*/
	public function examine(FsRequest $oFsRequest): void
	{
		$oReply = $oFsRequest->buildReply();
		$iArg = $oFsRequest->getByte(1);
		$iStart = $oFsRequest->getByte(2) ?? 0;
		$iCount = $oFsRequest->getByte(3) ?? 0;
		$this->oProvider->getLogger()->debug("Examine Type ".$iArg);
		try {
		switch($iArg){
			case 0:
				//EXAMINE_ALL
				$oReply->DoneOk();

				//Get the directory listing
				$oFd = $this->oProvider->vfsGetFsHandle($oFsRequest->getSourceNetwork(),$oFsRequest->getSourceStation(),$oFsRequest->getCsd());
				$aDirEntries=$this->oProvider->vfsGetDirectoryListing($oFd);
				$this->oProvider->getLogger()->debug("There are ".count($aDirEntries)." entries in dir ".$oFd->getEconetPath());

				//Return only the entries the client requested (works like sql limit and offset)
				$aDirEntries = array_slice($aDirEntries,$iStart,$iCount);

				//Number of entries 1 Byte
				$oReply->appendByte(count($aDirEntries));

				foreach($aDirEntries as $oFile){
					//Append the file name (limit 10 chars)
					$oReply->appendString(str_pad(substr((string) $oFile->getEconetName(),0,11),11,' '));
					$oReply->append32bitIntLittleEndian($oFile->getLoadAddr() ?? 0);
					$oReply->append32bitIntLittleEndian($oFile->getExecAddr() ?? 0);
					//Access mode
					$oReply->appendByte($oFile->getAccess());
					//Append 2 byte ctime Day,year+month
					$oReply->appendRaw($oFile->getCTime());
					$oReply->append24bitIntLittleEndian($oFile->getSin());
					$oReply->append24bitIntLittleEndian($oFile->getSize());
				}
				//Close the set	with 0x80
				$oReply->appendByte(0x80);
				break;
			case 1:
				//EXAMINE_LONGTXT
				$oReply->DoneOk();

				//Get the directory listing
				$oFd = $this->oProvider->vfsGetFsHandle($oFsRequest->getSourceNetwork(),$oFsRequest->getSourceStation(),$oFsRequest->getCsd());
				$aDirEntries=$this->oProvider->vfsGetDirectoryListing($oFd);
				$this->oProvider->getLogger()->debug("There are ".count($aDirEntries)." entries in dir ".$oFd->getEconetPath());

				//Return only the entries the client requested (works like sql limit and offset)
				$aDirEntries = array_slice($aDirEntries,$iStart,$iCount);

				//Number of entries 1 Byte
				$oReply->appendByte(count($aDirEntries));
				//Undefined but riscos needs it
				$oReply->appendByte(0);

				foreach($aDirEntries as $oFile){
					//Append the file name (limit 10 chars)
					$oReply->appendString(str_pad(substr((string) $oFile->getEconetName(),0,11),11,' '));
					$oReply->appendString(sprintf("%08X %08X   %06X   %-6.6s  %02d:%02d:%02d %06x",$oFile->getLoadAddr(),$oFile->getExecAddr(),$oFile->getSize(),$oFile->getEconetMode(),$oFile->getDay(),$oFile->getMonth(),$oFile->getYear(),$oFile->getSin()));
					//End this directory entry
					$oReply->appendByte(0);

				}
				//Close the set	with 0x80
				$oReply->appendByte(0x80);
				break;
			case 2:
				//EXAMINE_NAME
				$oReply->DoneOk();
				//Get the directory listing
				$oFd = $this->oProvider->vfsGetFsHandle($oFsRequest->getSourceNetwork(),$oFsRequest->getSourceStation(),$oFsRequest->getCsd());
				$aDirEntries=$this->oProvider->vfsGetDirectoryListing($oFd);
				$this->oProvider->getLogger()->debug("There are ".count($aDirEntries)." entries in dir ".$oFd->getEconetPath());

				//Return only the entries the client requested (works like sql limit and offset)
				$aDirEntries = array_slice($aDirEntries,$iStart,$iCount);

				//Number of entries 1 Byte
				$oReply->appendByte(count($aDirEntries));
				//Undefined but riscos needs it
				$oReply->appendByte(0);

				foreach($aDirEntries as $oFile){
					$oReply->appendByte(10);
					//Append the file name (10 chars to match the length prefix above)
					$oReply->appendString(str_pad(substr((string) $oFile->getEconetName(),0,10),10,' '));
				}
				//Close the set with 0x80
				$oReply->appendByte(0x80);
				break;
			case 3:
				//EXAMINE_SHORTTXT

				$oReply->DoneOk();

				//Get the directory listing
				$oFd = $this->oProvider->vfsGetFsHandle($oFsRequest->getSourceNetwork(),$oFsRequest->getSourceStation(),$oFsRequest->getCsd());
				$aDirEntries=$this->oProvider->vfsGetDirectoryListing($oFd);
				$this->oProvider->getLogger()->debug("There are ".count($aDirEntries)." entries in dir ".$oFd->getEconetPath());

				//Return only the entries the client requested (works like sql limit and offset)
				$aDirEntries = array_slice($aDirEntries,$iStart,$iCount);

				//Number of entries 1 Byte
				$oReply->appendByte(count($aDirEntries));
				//Undefined but riscos needs it
				$oReply->appendByte(0);

				foreach($aDirEntries as $oFile){
					//Append the file name (limit 10 chars)
					$oReply->appendString(str_pad(substr((string) $oFile->getEconetName(),0,11),11,' '));
					//Add 0x20
					$oReply->appendByte(0x20);
					//Add the file mode e.g DRW/r   (alway 6 bytes space padded)
					$oReply->appendString($oFile->getEconetMode());
					//End this directory entry
					$oReply->appendByte(0);

				}
				//Close the set	with 0x80
				$oReply->appendByte(0x80);
				break;
		}
		}catch(Exception){
			$oReply = $oFsRequest->buildReply();
			$oReply->setError(0xff,"Examine failed");
		}
		$this->oProvider->addReplyToBuffer($oReply->buildEconetpacket());
	}

	/**
	 * Gets the current user enviroment
	 *
	 * Sends a reply with the name of the disc the csd is on the name of the csd and library
	*/
	public function getUenv(FsRequest $oFsRequest): void
	{
		$oReply = $oFsRequest->buildReply();
		$oReply->DoneOk();

		//Discname Max Length <16
		$oReply->appendByte(16);

		//csd Disc name String 16 bytes
		$oReply->appendString(str_pad(substr(config::getValueAsString('vfs_disc_name'),0,16),16,' '));
		try {
			//csd Leaf name String 10 bytes
			$oCsd = $this->oProvider->vfsGetFsHandle($oFsRequest->getSourceNetwork(),$oFsRequest->getSourceStation(),$oFsRequest->getCsd());
			$oReply->appendString(str_pad(substr((string) $oCsd->getEconetDirName(),0,10),10,' '));

			//lib leaf name String 10 bytes
			$oLib = $this->oProvider->vfsGetFsHandle($oFsRequest->getSourceNetwork(),$oFsRequest->getSourceStation(),$oFsRequest->getLib());
			$oReply->appendString(str_pad(substr((string) $oLib->getEconetDirName(),0,10),10,' '));
		}catch(Exception $oException){
			$oReply = $oFsRequest->buildReply();
			$oReply->setError(0xff,$oException->getMessage());
		}

		$this->oProvider->addReplyToBuffer($oReply->buildEconetpacket());
	}

	/**
	 * Changes the csd
	 *
	 * This method is invoked by the *DIR command
	*/
	public function changeDirectory(FsRequest $oFsRequest,?string $sOptions): void
	{
		$sOptions ??= '';
		$oReply = $oFsRequest->buildReply();
		$oUser = $this->oProvider->secGetUser($oFsRequest->getSourceNetwork(),$oFsRequest->getSourceStation());

		//Chech the user is logged in
		if(!is_object($oUser)){
			$oReply->setError(0xbf,"Who are you?");
			$this->oProvider->addReplyToBuffer($oReply->buildEconetpacket());
			return;
		}

		if(strlen((string) $sOptions)>0){
			try {
				if($sOptions=="^"){
					//Change to parent dir
					$oCsd = $this->oProvider->vfsGetFsHandle($oFsRequest->getSourceNetwork(),$oFsRequest->getSourceStation(),$oFsRequest->getCsd());
					$sParentPath = $oCsd->getEconetParentPath();
					$oNewCsd = $this->oProvider->vfsCreateFsHandle($oFsRequest->getSourceNetwork(),$oFsRequest->getSourceStation(),$sParentPath);
				}else{
					$oNewCsd = $this->oProvider->vfsCreateFsHandle($oFsRequest->getSourceNetwork(),$oFsRequest->getSourceStation(),$sOptions);
					if(!$oNewCsd->isDir()){
						$this->oProvider->getLogger()->debug("User tryed to change to directory ".$oNewCsd->getEconetDirName()." however its not a directory.");
						$oReply->setError(0xbe,"Not a directory");
						$this->oProvider->addReplyToBuffer($oReply->buildEconetpacket());
						return;
					}
				}
				$this->oProvider->vfsCloseFsHandle($oFsRequest->getSourceNetwork(),$oFsRequest->getSourceStation(),$oFsRequest->getCsd());
				$oReply->DirOk();
				//Send new csd handle
				$oReply->appendByte($oNewCsd->getID());
				$oUser->setCsd($oNewCsd->getEconetPath());

			}catch(Exception){
				//The directory did no exist
				$oReply->setError(0xff,"No such directory.");
			}
		}else{
			//No directory selected, change to the users home dir
			try {
				$oNewCsd = $this->oProvider->vfsCreateFsHandle($oFsRequest->getSourceNetwork(),$oFsRequest->getSourceStation(),$oUser->getHomedir() ?? '$');
				$this->oProvider->vfsCloseFsHandle($oFsRequest->getSourceNetwork(),$oFsRequest->getSourceStation(),$oFsRequest->getCsd());
				$oReply->DirOk();
				$oReply->appendByte($oNewCsd->getID());
				$oUser->setCsd($oNewCsd->getEconetPath());
			}catch(Exception){
				$oReply->setError(0xff,"No such directory.");
			}
		}
		$this->oProvider->addReplyToBuffer($oReply->buildEconetpacket());


	}

	/**
	 * Changes the library
	 *
	 * This method is invoked by the *LIB command
	*/
	public function changeLibrary(FsRequest $oFsRequest,?string $sOptions): void
	{
		$sOptions ??= '';
		$oReply = $oFsRequest->buildReply();
		if(!is_object($this->oProvider->secGetUser($oFsRequest->getSourceNetwork(),$oFsRequest->getSourceStation()))){
			$oReply->setError(0xbf,"Who are you?");
			$this->oProvider->addReplyToBuffer($oReply->buildEconetpacket());
			return;
		}
		if(strlen((string) $sOptions)>0){
			try {
				$oNewLib = $this->oProvider->vfsCreateFsHandle($oFsRequest->getSourceNetwork(),$oFsRequest->getSourceStation(),$sOptions);
				if(!$oNewLib->isDir()){
					$this->oProvider->getLogger()->debug("User tryed to change the library to ".$oNewLib->getEconetDirName()." however its not a directory.");
					$oReply->setError(0xbe,"Not a directory");
					$this->oProvider->addReplyToBuffer($oReply->buildEconetpacket());
					return;
				}
				$this->oProvider->vfsCloseFsHandle($oFsRequest->getSourceNetwork(),$oFsRequest->getSourceStation(),$oFsRequest->getLib());
				$oReply->LibOk();
				//Send new csd handle
				$oReply->appendByte($oNewLib->getID());
			}catch(Exception){
				//The directory did no exist
				$oReply->setError(0xff,"No such directory.");
			}
		}else{
			$oReply->setError(0xff,"Syntax ?");
		}
		$this->oProvider->addReplyToBuffer($oReply->buildEconetpacket());


	}

	/**
	 * Creates a new directory
	 *
	 * This method in invoked by the *CDIR command
	*/
	public function createDirectory(FsRequest $oFsRequest,?string $sOptions): void
	{
		$sOptions ??= '';
		$oReply = $oFsRequest->buildReply();
		if(!is_object($this->oProvider->secGetUser($oFsRequest->getSourceNetwork(),$oFsRequest->getSourceStation()))){
			$oReply->setError(0xbf,"Who are you?");
			$this->oProvider->addReplyToBuffer($oReply->buildEconetpacket());
			return;
		}
		if(strlen((string) $sOptions)<1){
			$oReply->setError(0xff,"Syntax");
			$this->oProvider->addReplyToBuffer($oReply->buildEconetpacket());
			return;
		}
		if(strlen((string) $sOptions)>10){
			$oReply->setError(0xff,"Maximum directory name length is 10");
			$this->oProvider->addReplyToBuffer($oReply->buildEconetpacket());
			return;
		}

		try {
			$this->oProvider->vfsCreateDirectory($oFsRequest->getSourceNetwork(),$oFsRequest->getSourceStation(),$sOptions);
			$oReply->DoneOk();
		}catch(Exception){
			$oReply->setError(0xff,"Unable to create directory");
		}
		$this->oProvider->addReplyToBuffer($oReply->buildEconetpacket());
	}

	/**
	 * Deletes a given file
	 *
	 * This method is invoked as either a cli or a file server command depending on the nfs version
	*/
	public function deleteFile(FsRequest $oFsRequest,?string $sOptions): void
	{
		$sOptions ??= '';
		$oReply = $oFsRequest->buildReply();
		if(!is_object($this->oProvider->secGetUser($oFsRequest->getSourceNetwork(),$oFsRequest->getSourceStation()))){
			$oReply->setError(0xbf,"Who are you?");
			$this->oProvider->addReplyToBuffer($oReply->buildEconetpacket());
			return;
		}
		if(strlen((string) $sOptions)<1){
			$oReply->setError(0xff,"Syntax");
		}else{
			try{
				$this->oProvider->vfsDeleteFile($oFsRequest->getSourceNetwork(),$oFsRequest->getSourceStation(),$sOptions);
				$oReply->DoneOk();
			}catch(Exception){
				$oReply->setError(0xff,"Unable to delete");
			}
		}
		$this->oProvider->addReplyToBuffer($oReply->buildEconetpacket());
	}

	/**
	 * Returns the directory catalogue header (disc name, CSD leaf, library leaf).
	 * Called by older NFS ROMs that use function code 4 before EXAMINE.
	*/
	public function catHeader(FsRequest $oFsRequest): void
	{
		$oReply = $oFsRequest->buildReply();
		try {
			$oCsd = $this->oProvider->vfsGetFsHandle($oFsRequest->getSourceNetwork(),$oFsRequest->getSourceStation(),$oFsRequest->getCsd());
			$oLib = $this->oProvider->vfsGetFsHandle($oFsRequest->getSourceNetwork(),$oFsRequest->getSourceStation(),$oFsRequest->getLib());

			$oReply->CatOk();
			$oReply->appendString(str_pad(substr(config::getValueAsString('vfs_disc_name'),0,16),16,' '));
			$oReply->appendByte(0x0d);
			$oReply->appendString(str_pad(substr((string) $oCsd->getEconetDirName(),0,10),10,' '));
			$oReply->appendByte(0x0d);
			$oReply->appendString(str_pad(substr((string) $oLib->getEconetDirName(),0,10),10,' '));
			$oReply->appendByte(0x0d);
			$oReply->appendByte(0x80);
		}catch(Exception){
			$oReply->setError(0xff,"No such directory");
		}
		$this->oProvider->addReplyToBuffer($oReply->buildEconetpacket());
	}

	/**
	 * Implements *CAT — sends a text-mode directory listing to the client.
	*/
	public function cat(FsRequest $oFsRequest, string $sOptions): void
	{
		$oUser = $this->oProvider->secGetUser($oFsRequest->getSourceNetwork(),$oFsRequest->getSourceStation());
		if(!is_object($oUser)){
			$oReply = $oFsRequest->buildReply();
			$oReply->setError(0xbf,"Who are you?");
			$this->oProvider->addReplyToBuffer($oReply->buildEconetpacket());
			return;
		}

		$this->oProvider->updateCsdLib($oFsRequest);
		$oReply = $oFsRequest->buildReply();
		try {
			if(strlen(trim($sOptions)) > 0){
				$oFd = $this->oProvider->vfsCreateFsHandle($oFsRequest->getSourceNetwork(),$oFsRequest->getSourceStation(),trim($sOptions));
			}else{
				$oFd = $this->oProvider->vfsGetFsHandle($oFsRequest->getSourceNetwork(),$oFsRequest->getSourceStation(),$oFsRequest->getCsd());
			}
			$aDirEntries = $this->oProvider->vfsGetDirectoryListing($oFd);

			$sDiscName = config::getValueAsString('vfs_disc_name');
			$sDirName  = (string) $oFd->getEconetDirName();

			$sCat  = "\r";
			$sCat .= str_pad($sDiscName,16).$sDirName."\r\r";

			$i = 0;
			foreach($aDirEntries as $oFile){
				$sCat .= str_pad(substr((string) $oFile->getEconetName(),0,10),20);
				$i++;
				if($i % 4 === 0){
					$sCat .= "\r";
				}
			}
			if($i % 4 !== 0){
				$sCat .= "\r";
			}
			$sCat .= "\r";

			$oReply->UnrecognisedOk();
			$oReply->appendString($sCat);
			$oReply->appendByte(0x80);
		}catch(Exception){
			$oReply->setError(0xff,"No such directory");
		}
		$this->oProvider->addReplyToBuffer($oReply->buildEconetpacket());
	}

	/**
	 * Renames a given file
	 *
	 * This method is invoked as the cli command *RENAME
	*/
	public function renameFile(FsRequest $oFsRequest,?string $sOptions): void
	{
		$oReply = $oFsRequest->buildReply();
		if(!is_object($this->oProvider->secGetUser($oFsRequest->getSourceNetwork(),$oFsRequest->getSourceStation()))){
			$oReply->setError(0xbf,"Who are you?");
			$this->oProvider->addReplyToBuffer($oReply->buildEconetpacket());
			return;
		}
		$aParts = explode(' ', trim((string) $sOptions), 2);
		if(count($aParts) !== 2 || strlen($aParts[0]) === 0 || strlen($aParts[1]) === 0){
			$oReply->setError(0xff,"Syntax");
		}else{
			try{
				$this->oProvider->vfsMoveFile($oFsRequest->getSourceNetwork(),$oFsRequest->getSourceStation(),$aParts[0],$aParts[1]);
				$oReply->DoneOk();
			}catch(Exception){
				$oReply->setError(0xff,"No such file");
			}
		}
		$this->oProvider->addReplyToBuffer($oReply->buildEconetpacket());

	}

}
