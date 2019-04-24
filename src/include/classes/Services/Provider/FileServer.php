<?php
/**
 * This file contains the fileserver class
 *
 * @author John Brown <john@home-lan.co.uk>
 * @package core
*/
namespace HomeLan\FileStore\Services\Provider; 

use HomeLan\FileStore\Services\Provider\FileServer\Admin;
use HomeLan\FileStore\Services\ProviderInterface;
use HomeLan\FileStore\Services\ServiceDispatcher;
use HomeLan\FileStore\Services\StreamIn;
use HomeLan\FileStore\Vfs\Vfs; 
use HomeLan\FileStore\Authentication\Security; 
use HomeLan\FileStore\Authentication\User; 
use HomeLan\FileStore\Messages\EconetPacket; 
use HomeLan\FileStore\Messages\FsRequest;
use HomeLan\FileStore\Messages\FsReply;

use config;
use Exception;

/**
 * This class implements the econet fileserver
 *
 * @package core
*/
class FileServer implements ProviderInterface{

	protected $oServiceDispatcher = NULL ;

	protected $aCommands = array('BYE','CAT','CDIR','DELETE','DIR','FSOPT','INFO','I AM','LIB','LOAD','LOGOFF','PASS','RENAME','SAVE','SDISC','NEWUSER','PRIV','REMUSER','i.','CHROOT','CHROOTOFF');
	
	protected $aReplyBuffer = array();

	protected $oLogger;

	protected $aStreamsIn = [];

	/**
	 * Initializes the service
	 *
	*/
	public function __construct(\Psr\Log\LoggerInterface $oLogger)
	{
		$this->oLogger = $oLogger;
		Vfs::init($this->oLogger, config::getValue('vfs_plugins'), config::getValue('security_mode')=='multiuser');
	
	}

	public function getName(): string
	{
		return "File Server";
	}

	/** 
	 * Gets the admin interface Object for this serivce provider 
	 *
	*/
	public function getAdminInterface(): ?AdminInterface
	{
		return new Admin($this);
	}

	public function addReplyToBuffer(FsReply $oReply): void
	{
		$this->aReplyBuffer[]=$oReply;
	}

	/**
	 * Gets the ports this service uses 
	 * 
	 * @return array of int
	*/
	public function getServicePorts(): array
	{
		return [0x9C, config::getValue('econet_data_stream_port')];
	}

	/** 
	 * Filestore messages can come in via broadcast (e.g. sdiscs which is uses to find servers)
	 *
	*/
	public function broadcastPacketIn(EconetPacket $oPacket): void
	{
		$oFsRequest = new FsRequest($oPacket, $this->oLogger);
		switch($oFsRequest->getFunction()){
			case 'EC_FS_FUNC_CLI':
				$this->processRequest($oFsRequest);
				break;
		}
	}

	/** 
	 * All inbound bridge messages come in via broadcast, so unicast should ignore them
	 *
	*/
	public function unicastPacketIn(EconetPacket $oPacket): void
	{
		if($oPacket->getPort()==config::getValue('econet_data_stream_port')){
			$this->streamPacketIn($oPacket);
		}else{
			$this->processRequest(new FsRequest($oPacket, $this->oLogger));
		}
	}


	/**
	 * Called when the service provider is registered with the ServiceDispatcher
	*/
	public function registerService(ServiceDispatcher $oServiceDispatcher): void
	{
		$this->oServiceDispatcher = $oServiceDispatcher;
		$_this = $this;
		$this->oServiceDispatcher->addHousingKeepingTask(function() use ($_this){
			$_this->houseKeeping();
		});
	}

	/**
	 * Retreives all the reply objects built by the fileserver 
	 *
	 * This method removes the replies from the buffer 
	*/
	public function getReplies(): array
	{
		$aReturn = [];
		foreach($this->aReplyBuffer as $oReply){
			switch(get_class($oReply)){
				case 'HomeLan\FileStore\Messages\FsReply':
					$aReturn[] = $oReply->buildEconetpacket();
					break;
				case 'HomeLan\FileStore\Messages\EconetPacket':
					$aReturn[] = $oReply;
					break;
				default:
					$this->oLogger->warning("Service provider filestore produced a reply of the invalid type ".get_class($oReply)." dropping");
					break;
			}
		}
		$this->aReplyBuffer = [];
		return $aReturn;
	}

	/**
	 * Deals with inbound packets for io streams (e.g. save, putbytes) 
	*/
	private function streamPacketIn(EconetPacket $oPacket): void
	{
		if(isset($this->aStreamsIn[$oPacket->getSourceNetwork()][$oPacket->getSourceStation()])){
			$this->aStreamsIn[$oPacket->getSourceNetwork()][$oPacket->getSourceStation()]->inboundPacket($oPacket);
		}	
	}

	/**
	 * Adds a new io stream (e.g. save, putbytes)
	*/
	private function addStream(StreamIn $oStream,int $iNetwork, int $iStation): void
	{
		if(!is_array($this->aStreamsIn[$iNetwork])){
			$this->aStreamsIn[$iNetwork]=[];
		}
		$this->aStreamsIn[$iNetwork][$iStation]=$oStream;
	}

	/**
	 * Frees an existing io stream
	*/
	private function freeStream(StreamIn $oStream,int $iNetwork, int $iStation): void
	{
		unset($this->aStreamsIn[$iNetwork][$iStation]);
		unset ($oStream);
	}

	public function houseKeeping(): void
	{
		$aStreamsToTest =  $this->aStreamsIn;
		foreach($aStreamsToTest as $iNetwork=>$aStations){
			foreach($aStations as $iStation=>$oStream){
				//If the stream has timeout it will call its own fail event that
				//should clean up the stream and references in $this->aStreamsIn 
				$oStream->checkTimedOut();	
			}
		}
	}

	/**
	 * This is the main entry point to this class 
	 *
	 * The fsrequest object contains the request the fileserver must process 
	 * @param object fsrequest $oFsRequest
	*/
	public function processRequest($oFsRequest): void
	{
		$sFunction = $oFsRequest->getFunction();
		$this->oLogger->debug("FS function ".$sFunction);

		//Update the idle timer for this station
		Security::updateIdleTimer($oFsRequest->getSourceNetwork(),$oFsRequest->getSourceStation());


		//Function where you dont always need to be logged in
		switch($sFunction){
			case 'EC_FS_FUNC_CLI':
				$this->cliDecode($oFsRequest);
				return;
				break;

		}

		//Function where the user must be logged in
		if(!Security::isLoggedIn($oFsRequest->getSourceNetwork(),$oFsRequest->getSourceStation())){
			$oReply = $oFsRequest->buildReply();
			$oReply->setError(0xbf,"Who are you?");
			$this->addReplyToBuffer($oReply);
			return;
		}

		//Has the handles for Lib and Csd can be swaped on a per command basis (e.g. *FLIP) every
		//call needs to update the current users csd and lib based on which handle is used in the requests
		//csd and lib byte
		$this->updateCsdLib($oFsRequest);

		switch($sFunction){
			case 'EC_FS_FUNC_LOAD':
				$this->loadFile($oFsRequest);
				break;
			case 'EC_FS_FUNC_SAVE':
				$this->saveFile($oFsRequest);
				break;
			case 'EC_FS_FUNC_EXAMINE':
				$this->examine($oFsRequest);
				break;
			case 'EC_FS_FUNC_CAT_HEADER':
				$this->oLogger->info("Call to obsolete unimplemented function EC_FS_FUNC_CAT_HEADER");
				break;
			case 'EC_FS_FUNC_LOAD_COMMAND':
				$this->loadCommand($oFsRequest);
				break;
			case 'EC_FS_FUNC_OPEN':
				$this->openFile($oFsRequest);
				break;
			case 'EC_FS_FUNC_CLOSE':
				$this->closeFile($oFsRequest);
				break;
			case 'EC_FS_FUNC_GETBYTE':
				$this->getByte($oFsRequest);
				break;
			case 'EC_FS_FUNC_PUTBYTE':
				$this->putByte($oFsRequest);
				break;
			case 'EC_FS_FUNC_GETBYTES':
				$this->getBytes($oFsRequest);
				break;
			case 'EC_FS_FUNC_PUTBYTES':
				$this->putBytes($oFsRequest);
				break;
			case 'EC_FS_FUNC_GET_ARGS':
				$this->getArgs($oFsRequest);
				break;
			case 'EC_FS_FUNC_SET_ARGS':
				break;
			case 'EC_FS_FUNC_GET_EOF':
				$this->eof($oFsRequest);
				break;
			case 'EC_FS_FUNC_GET_DISCS':
				$this->getDiscs($oFsRequest);
				break;
			case 'EC_FS_FUNC_GET_INFO':
				$this->getInfo($oFsRequest);
				break;
			case 'EC_FS_FUNC_SET_INFO':
				$this->setInfo($oFsRequest);
				break;
			case 'EC_FS_FUNC_GET_UENV':
				$this->getUenv($oFsRequest);
				break;
			case 'EC_FS_FUNC_LOGOFF':
				$this->logout($oFsRequest);
				break;
			case 'EC_FS_FUNC_GET_USERS_ON':
				$this->usersOnline($oFsRequest);
				break;
			case 'EC_FS_FUNC_GET_USER':
				$this->getUsersStation($oFsRequest);
				break;
			case 'EC_FS_FUNC_GET_TIME':
				$this->getTime($oFsRequest);
				break;
			case 'EC_FS_FUNC_SET_OPT4':
				break;
			case 'EC_FS_FUNC_DELETE':
				$sFile = $oFsRequest->getString(1);
				$this->deleteFile($oFsRequest,$sFile);
				break;
			case 'EC_FS_FUNC_GET_VERSION':
				$this->getVersion($oFsRequest);
				break;
			case 'EC_FS_FUNC_GET_DISC_FREE':
				$this->getDiscFree($oFsRequest);
				break;
			case 'EC_FS_FUNC_CDIRN':
				$sDir = $oFsRequest->getString(2);
				$this->createDirectory($oFsRequest,$sDir);
				break;
			case 'EC_FS_FUNC_CREATE':
				$this->createFile($oFsRequest);
				break;
			case 'EC_FS_FUNC_GET_USER_FREE':
				$this->getUserDiscFree($oFsRequest);
				break;
			default:
				$this->oLogger->debug("Un-handled fs function ".$sFunction);
				break;
				
		}
	}

	/**
	 * Reads which file handle is stored in the requests csd and lib byte, and updates the users csd and lib 
	*/
	public function updateCsdLib($oFsRequest): void
	{
		$oUser = Security::getUser($oFsRequest->getSourceNetwork(),$oFsRequest->getSourceStation());
		try {
			if(!is_null($oFsRequest->getCsd())){
				$oCsd = Vfs::getFsHandle($oFsRequest->getSourceNetwork(),$oFsRequest->getSourceStation(),$oFsRequest->getCsd());
				if(is_object($oCsd)){
					$oUser->setCsd($oCsd->getEconetPath());
				}
			}	
		}catch(Exception $oException){
			$this->oLogger->debug("fileserver: Unable to set users csd to handle ".$oFsRequest->getCsd()." (".$oException->getMessage().")");
		}

		try {
			if(!is_null($oFsRequest->getLib())){
				$oLib = Vfs::getFsHandle($oFsRequest->getSourceNetwork(),$oFsRequest->getSourceStation(),$oFsRequest->getLib());
				if(is_object($oLib)){
					$oUser->setLib($oLib->getEconetPath());
				}
			}	
		}catch(Exception $oException){
			$this->oLogger->debug("fileserver: Unable to set users lib to handle ".$oFsRequest->getLib()." (".$oException->getMessage().")");
		}
	
	}

	/**
	 * Decodes the cli request
	 *
	 * Once the decode is complete the decoded request is passedto the runCli method
	 *
	 * @param object $oFsRequest
	*/
	public function cliDecode(object $oFsRequest): void
	{
		$sData = $oFsRequest->getData();
		$aDataAs8BitInts = unpack('C*',$sData);
		$sDataAsString = "";
		foreach($aDataAs8BitInts as $iChar){
			$sDataAsString = $sDataAsString.chr($iChar);
		}

		$this->oLogger->debug("Command: ".$sDataAsString.".");

		foreach($this->aCommands as $sCommand){
			$iPos = stripos($sDataAsString,$sCommand);
			if($iPos===0){
				//Found cli command found
				$iOptionsPos = $iPos+strlen($sCommand);
				$sOptions = substr($sDataAsString,$iOptionsPos);
				$this->runCli($oFsRequest,$sCommand,trim($sOptions));
				return;
				break;
			}			
		}
		$oReply = $oFsRequest->buildReply();
		$oReply->UnrecognisedOk();
		$oReply->appendString($sCommand);
		$this->addReplyToBuffer($oReply);
	}

	/**
	 * This method runs the cli command, or delegate to an approriate method
	 *
	 * @param object fsrequest $oFsRequest The fsrequest
	 * @param string $sCommand The command to run
	 * @param string $sOptions The command arguments
	*/
	public function runCli($oFsRequest,string $sCommand,string $sOptions): void
	{
		switch(strtoupper($sCommand)){
				case 'BYE':
			case 'LOGOFF':
				$this->logout($oFsRequest);
				break;
			case 'I AM':
				$this->login($oFsRequest,$sOptions);
				break;
			case 'PASS':
				$this->setPassword($oFsRequest,$sOptions);
				break;
			case 'CAT':
				break;
			case 'CDIR':
				$this->createDirectory($oFsRequest,$sOptions);
				break;
			case 'DELETE':
				$this->deleteFile($oFsRequest,$sOptions);
				break;
			case 'DIR':
				$this->changeDirectory($oFsRequest,$sOptions);
				break;
			case 'FSOPT':
				break;
			case 'INFO':
			case 'I.':
				$this->cmdInfo($oFsRequest,$sOptions);
				break;
			case 'LIB':
				$this->changeLibrary($oFsRequest,$sOptions);
				break;
			case 'LOAD':
				break;
			case 'RENAME':
				$this->renameFile($oFsRequest,$sOptions);
				break;
			case 'SAVE':
				break;
			case 'SDISC':
				$this->sDisc($oFsRequest,$sOptions);
				break;
			case 'PRIV':
				$this->privUser($oFsRequest,$sOptions);
				break;
			case 'NEWUSER':
				$this->createUser($oFsRequest,$sOptions);
				break;
			case 'REMUSER':
				$this->removeUser($oFsRequest,$sOptions);
				break;
			case 'CHROOT':
				$this->chroot($oFsRequest,$sOptions);
				break;
			case 'CHROOTOFF':
				$this->chrootoff($oFsRequest,$sOptions);
				break;
			default:
				$this->oLogger->debug("Un-handled command ".$sCommand);
				$oReply = $oFsRequest->buildReply();
				$oReply->setError(0x99,"Un-implemented command");
				$this->addReplyToBuffer($oReply);
				break;
		}
	}

	/**
	 * Handles loading *COMMANDs stored on the server
	 * 
	 * @param object fsrequest $oFsRequest
	*/
	public function loadCommand($oFsRequest): void
	{
		$this->loadFile($oFsRequest);	
	}

	/**
	 * Handles login requests (*I AM)
	 *
	 * @param object fsrequest $oFsRequest
	 * @param string $sOptions The arguments passed to *I AM (e.g. username password)
	*/
	public function login($oFsRequest,string $sOptions): void
	{
		$this->oLogger->debug("fileserver: Login called ".$sOptions);
		$aOptions = explode(" ",$sOptions);
		if(count($aOptions)>0){
			//Creditials supplied, decode username and password
			$sUser = $aOptions[0];
			if(array_key_exists(1,$aOptions)){
				$sPass = trim($aOptions[1]);
				if(substr_count($sPass,"\r")>0){
					list($sPass) = explode("\r",$sPass);
				}
			}else{
				$sPass="";
			}
		}else{
			//No creditials supplied
			$this->oLogger->info("Login Failed: *I AM send but with no username or password");
			//Send Fail Notice
			$oReply = $oFsRequest->buildReply();

			//Send Wrong Password
			$oReply->setError(0xbb,"Incorrect password");
			$this->addReplyToBuffer($oReply);
			return;
		}

		//Do login
		if(Security::login($oFsRequest->getSourceNetwork(),$oFsRequest->getSourceStation(),$sUser,$sPass)){
			//Login success 

			//Create the handles for the csd urd and lib
			$oUser = Security::getUser($oFsRequest->getSourceNetwork(),$oFsRequest->getSourceStation());
			try {
				$oUrd = Vfs::createFsHandle($oFsRequest->getSourceNetwork(),$oFsRequest->getSourceStation(),$oUser->getHomedir());
				$oCsd = Vfs::createFsHandle($oFsRequest->getSourceNetwork(),$oFsRequest->getSourceStation(),$oUser->getHomedir());	
			}catch(Exception $oException){
				$this->oLogger->info("fileserver: Login unable to open homedirectory for user ".$oUser->getUsername());
				$oUrd = Vfs::createFsHandle($oFsRequest->getSourceNetwork(),$oFsRequest->getSourceStation(),'$');
				$oCsd = Vfs::createFsHandle($oFsRequest->getSourceNetwork(),$oFsRequest->getSourceStation(),'$');
			}
			try {
				$oLib = Vfs::createFsHandle($oFsRequest->getSourceNetwork(),$oFsRequest->getSourceStation(),$oUser->getLib());
			}catch(Exception $oException){
				$this->oLogger->info("fileserver: Login unable to open library dir setting library to $ for user ".$oUser->getUsername());
				$oLib = Vfs::createFsHandle($oFsRequest->getSourceNetwork(),$oFsRequest->getSourceStation(),'');
			}
			//Handles are now build send the reply 
			$oReply = $oFsRequest->buildReply();
			$this->oLogger->debug("fileserver: Login ok urd:".$oUrd->getId()." csd:".$oCsd->getId()." lib:".$oLib->getId());
			$oReply->loginRespone($oUrd->getId(),$oCsd->getId(),$oLib->getId(),$oUser->getBootOpt());
			$this->addReplyToBuffer($oReply);
		}else{
			//Login failed
			$oReply = $oFsRequest->buildReply();

			//Send Wrong Password
			$this->oLogger->info("Login Failed: For user ".$sUser." invalid password/no such user");
			$oReply->setError(0xbb,"Incorrect password");
			$this->addReplyToBuffer($oReply);
		}
			
	}

	/**
	 * Handle logouts (*bye)
	 *
	 * We can be called as a cli command (*bye) and by its own function call
	 * @param object fsrequest $oFsRequest
	*/
	public function logout($oFsRequest): void
	{
		try{
			Security::logout($oFsRequest->getSourceNetwork(),$oFsRequest->getSourceStation());
			$oReply = $oFsRequest->buildReply();	
			$oReply->DoneOk();
		}catch(Exception $oException){
			$oReply = $oFsRequest->buildReply();
			$oReply->setError(0xbf,"Who are you?");
		}
		$this->addReplyToBuffer($oReply);
	}

	/**
	 * Handles the *info command
	 *
	 * @param object fsrequest $oFsRequest
	 * @param string 
	*/
	public function cmdInfo($oFsRequest,$sFile): void
	{
		$this->oLogger->debug("cmdInfo for path ".$sFile."");
		$oReply = $oFsRequest->buildReply();
		try {
			$oMeta = Vfs::getMeta($oFsRequest->getSourceNetwork(),$oFsRequest->getSourceStation(),$sFile);
			$sReplyData =  sprintf("%-10.10s %08X %08X   %06X   %-6.6s  %02d:%02d:%02d %06x\r\x80",$sFile,$oMeta->getLoadAddr(),$oMeta->getExecAddr(),$oMeta->getSize(),"RW/RW",$oMeta->getDay(),$oMeta->getMonth(),$oMeta->getYear(),$oMeta->getSin());
			$this->oLogger->debug("INFO ".$sFile." Load: ".$oMeta->getLoadAddr()." Exec: ".$oMeta->getExecAddr()." Size:".$oMeta->getSize());
			$oReply->InfoOk();
			//Append Type
			$oReply->appendString($sReplyData);
		}catch(Exception $oException){
			$oReply->setError(0xff,"No such file");
		}
		$this->addReplyToBuffer($oReply);
	}

	/**
	 * Handles requests for information on directories and files
	 *
	 * This method is called when the client uses *. to produce the directory header
	 * @param objects fsrequest $oFsRequest
	*/
	public function getInfo($oFsRequest): void
	{
		$sDir = $oFsRequest->getString(2);
		$this->oLogger->debug("getInfo for path ".$sDir." (".$oFsRequest->getByte(1).")");
		switch($oFsRequest->getByte(1)){
			case 4:
				//EC_FS_GET_INFO_ACCESS
				$oReply = $oFsRequest->buildReply();
				try {
					$oMeta = Vfs::getMeta($oFsRequest->getSourceNetwork(),$oFsRequest->getSourceStation(),$sDir);
					$oReply->DoneOk();
					//Append Type
					if($oMeta->isDir()){
						$oReply->appendByte(0x02);
					}else{
						$oReply->appendByte(0x01);
					}
					$oReply->appendByte($oMeta->getAccess());
				}catch(Exception $oException){
					$oReply->DoneOk();
					$oReply->appendByte(0x00);
					$oReply->appendByte(0x00);
					$oReply->appendByte(0x00);
				}
				$this->addReplyToBuffer($oReply);
				return;
				break;
			case 5:
				//EC_FS_GET_INFO_ALL
				$oReply = $oFsRequest->buildReply();
				try {
					$oMeta = Vfs::getMeta($oFsRequest->getSourceNetwork(),$oFsRequest->getSourceStation(),$sDir);
					$oReply->DoneOk();
					//Append Type
					if($oMeta->isDir()){
						$oReply->appendByte(0x02);
					}else{
						$oReply->appendByte(0x01);
					}
					$oReply->append32bitIntLittleEndian($oMeta->getLoadAddr());
					$oReply->append32bitIntLittleEndian($oMeta->getExecAddr());
					$oReply->append24bitIntLittleEndian($oMeta->getSize());
					$oReply->appendByte($oMeta->getAccess());
					//Add current date
					$oReply->appendRaw($oMeta->getCTime());
				}catch(Exception $oException){
					$oReply->DoneOk();
					$oReply->appendByte(0x00);
					$oReply->append32bitIntLittleEndian(0x0);
					$oReply->append32bitIntLittleEndian(0x0);
					$oReply->append24bitIntLittleEndian(0x0);
					$oReply->appendByte(0x00);
					$oReply->appendByte(0x00);
					$oReply->appendByte(0x00);
				}	
				$this->addReplyToBuffer($oReply);
				return;
				break;
			case 1:
				//EC_FS_GET_INFO_CTIME
				$oReply = $oFsRequest->buildReply();
				try {
					$oMeta = Vfs::getMeta($oFsRequest->getSourceNetwork(),$oFsRequest->getSourceStation(),$sDir);
					$oReply->DoneOk();
					//Append Type
					if($oMeta->isDir()){
						$oReply->appendByte(0x02);
					}else{
						$oReply->appendByte(0x01);
					}
					$oReply->appendRaw($oMeta->getCTime());
				}catch(Exception $oException){
					$oReply->DoneOk();
					$oReply->appendByte(0x00);
					$oReply->appendByte(0x00);
					$oReply->appendByte(0x00);
				}
				$this->addReplyToBuffer($oReply);
				return;
				break;
			case 2:
				//EC_FS_GET_INFO_META
				$oReply = $oFsRequest->buildReply();
				try {
					$oMeta = Vfs::getMeta($oFsRequest->getSourceNetwork(),$oFsRequest->getSourceStation(),$sDir);
					$oReply->DoneOk();
					//Append Type
					if($oMeta->isDir()){
						$this->oLogger->debug("isDir");
						$oReply->appendByte(0x02);
					}else{
						$this->oLogger->debug("isFile");
						$oReply->appendByte(0x01);
					}
					$oReply->append32bitIntLittleEndian($oMeta->getLoadAddr());
					$oReply->append32bitIntLittleEndian($oMeta->getExecAddr());
				}catch(Exception $oException){
					$oReply->DoneOk();
					$oReply->appendByte(0x00);
					$oReply->appendByte(0x00);
					$oReply->appendByte(0x00);
				}
				$this->addReplyToBuffer($oReply);
				return;
	
				break;
			case 3:
				//EC_FS_GET_INFO_SIZE
				$oReply = $oFsRequest->buildReply();
				try {
					$oMeta = Vfs::getMeta($oFsRequest->getSourceNetwork(),$oFsRequest->getSourceStation(),$sDir);
					$oReply->DoneOk();
					//Append Type
					if($oMeta->isDir()){
						$oReply->appendByte(0x02);
					}else{
						$oReply->appendByte(0x01);
					}
					$oReply->append24bitIntLittleEndian($oMeta->getSize());
				}catch(Exception $oException){
					$oReply->DoneOk();
					$oReply->appendByte(0x00);
					$oReply->appendByte(0x00);
					$oReply->appendByte(0x00);
				}
				$this->addReplyToBuffer($oReply);
				return;
				
				break;
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
						$oFd = Vfs::getFsHandle($oFsRequest->getSourceNetwork(),$oFsRequest->getSourceStation(),$oFsRequest->getCsd());
						$oReply->appendString(str_pad(substr($oFd->getEconetDirName(),0,10),10,' '));
					}else{
						$oReply->appendString(str_pad(substr($sDir,0,10),10,' '));
					}

					//FS_DIR_ACCESS_PUBLIC
					$oReply->appendByte(0xff);

					//Cyle  always 0 probably should not be 
					$oReply->appendByte(0);

					$this->addReplyToBuffer($oReply);

				}catch(Exception $oException){
					$oReply = $oFsRequest->buildReply();
					$oReply->setError(0x8e,"Bad INFO argument");
					$this->addReplyToBuffer($oReply);
				}
				return;
				break;
			case 7:
				//EC_FS_GET_INFO_UID
				break;
			default:
				//Don't do any thing fall to the bad info reply below
				break;
		}
		$oReply = $oFsRequest->buildReply();
		$oReply->setError(0x8e,"Bad INFO argument");
		$this->addReplyToBuffer($oReply);
	}

	public function setInfo($oFsRequest): void
	{
		$iArg = $oFsRequest->getByte(1);
		$oReply = $oFsRequest->buildReply();
		switch($iArg){
			case 1:
				//EC_FS_SET_INFO_ALL
				$iLoad = $oFsRequest->get32bitIntLittleEndian(2);
				$iExec = $oFsRequest->get32bitIntLittleEndian(6);
				$iAccess =$oFsRequest->getByte(7);
				$sPath = $oFsRequest->getString(8);
				Vfs::setMeta($oFsRequest->getSourceNetwork(),$oFsRequest->getSourceStation(),$sPath,$iLoad,$iExec,$iAccess);
				break;
			case 2:
				//EC_FS_SET_INFO_LOAD
				$iLoad = $oFsRequest->get32bitIntLittleEndian(2);
				$sPath = $oFsRequest->getString(6);
				Vfs::setMeta($oFsRequest->getSourceNetwork(),$oFsRequest->getSourceStation(),$sPath,$iLoad,NULL,NULL);
				break;
			case 3:
				//EC_FS_SET_INFO_EXEC
				$iExec = $oFsRequest->get32bitIntLittleEndian(2);
				$sPath = $oFsRequest->getString(6);
				Vfs::setMeta($oFsRequest->getSourceNetwork(),$oFsRequest->getSourceStation(),$sPath,NULL,$iExec,NULL);
				break;
			case 4:
				//EC_FS_SET_INFO_ACCESS
				$iAccess =$oFsRequest->getByte(1);
				$sPath = $oFsRequest->getString(2);
				Vfs::setMeta($oFsRequest->getSourceNetwork(),$oFsRequest->getSourceStation(),$sPath,NULL,NULL,$iAccess);
				break;
		}
		$oReply->DoneOk();
		$this->addReplyToBuffer($oReply);
	}

	public function eof($oFsRequest): void
	{
		$this->oLogger->debug("Eof Called by ".$oFsRequest->getSourceNetwork().".".$oFsRequest->getSourceStation());
		//Get the file handle id
		$iHandle = $oFsRequest->getByte(1);
		$oReply = $oFsRequest->buildReply();
		$oReply->DoneOk();
	
		//Get the file handle
		$oFsHandle = Vfs::getFsHandle($oFsRequest->getSourceNetwork(),$oFsRequest->getSourceStation(),$iHandle);	
		if($oFsHandle->isEof()){
			$oReply->appendByte(0xFF);
		}else{
			$oReply->appendByte(0);
		}
		$this->addReplyToBuffer($oReply);
	}

	/**
	 * Gets the details of a director/file 
	 * 
 	 * This method produces the directory listing for *.
	 * @param object fsrequest $oFsRequest
	*/
	public function examine($oFsRequest): void
	{
		$oReply = $oFsRequest->buildReply();
		$iArg = $oFsRequest->getByte(1);
		$iStart = $oFsRequest->getByte(2);
		$iCount = $oFsRequest->getByte(3);
		$this->oLogger->debug("Examine Type ".$iArg." (only 3,1 is implemented)");
		switch($iArg){
			case 0:
				//EXAMINE_ALL
				$oReply->DoneOk();

				//Get the directory listing
				$oFd = Vfs::getFsHandle($oFsRequest->getSourceNetwork(),$oFsRequest->getSourceStation(),$oFsRequest->getCsd());
				$aDirEntries=Vfs::getDirectoryListing($oFd);
				$this->oLogger->debug("There are ".count($aDirEntries)." entries in dir ".$oFd->getEconetPath());

				//Return only the entries the client requested (works like sql limit and offset)
				$aDirEntries = array_slice($aDirEntries,$iStart,$iCount);

				//Number of entries 1 Byte
				$oReply->appendByte(count($aDirEntries));

				foreach($aDirEntries as $oFile){
					//Append the file name (limit 10 chars)
					$oReply->appendString(str_pad(substr($oFile->getEconetName(),0,11),11,' '));
					$oReply->append32bitIntLittleEndian($oFile->getLoadAddr());
					$oReply->append32bitIntLittleEndian($oFile->getExecAddr());
					//Access mode
					$oReply->appendByte(0);
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
				$oFd = Vfs::getFsHandle($oFsRequest->getSourceNetwork(),$oFsRequest->getSourceStation(),$oFsRequest->getCsd());
				$aDirEntries=Vfs::getDirectoryListing($oFd);
				$this->oLogger->debug("There are ".count($aDirEntries)." entries in dir ".$oFd->getEconetPath());

				//Return only the entries the client requested (works like sql limit and offset)
				$aDirEntries = array_slice($aDirEntries,$iStart,$iCount);

				//Number of entries 1 Byte
				$oReply->appendByte(count($aDirEntries));
				//Undefined but riscos needs it 
				$oReply->appendByte(0);

				foreach($aDirEntries as $oFile){
					//Append the file name (limit 10 chars)
					$oReply->appendString(str_pad(substr($oFile->getEconetName(),0,11),11,' '));
					$oReply->appendString(sprintf("%08X %08X   %06X   %-6.6s  %02d:%02d:%02d %06x",$oFile->getLoadAddr(),$oFile->getExecAddr(),$oFile->getSize(),$oFile->getEconetMode(),$oFile->getDay(),$oFile->getMonth(),$oFile->getYear(),$oFile->getSin()));
					//End this directory entry
					$oReply->appendByte(0);
					
				}
				//Close the set	with 0x80
				$oReply->appendByte(0x80);
				break;
	
				break;
			case 2:
				//EXAMINE_NAME
				break;
			case 3:
				//EXAMINE_SHORTTXT

				$oReply->DoneOk();

				//Get the directory listing
				$oFd = Vfs::getFsHandle($oFsRequest->getSourceNetwork(),$oFsRequest->getSourceStation(),$oFsRequest->getCsd());
				$aDirEntries=Vfs::getDirectoryListing($oFd);
				$this->oLogger->debug("There are ".count($aDirEntries)." entries in dir ".$oFd->getEconetPath());

				//Return only the entries the client requested (works like sql limit and offset)
				$aDirEntries = array_slice($aDirEntries,$iStart,$iCount);

				//Number of entries 1 Byte
				$oReply->appendByte(count($aDirEntries));
				//Undefined but riscos needs it 
				$oReply->appendByte(0);

				foreach($aDirEntries as $oFile){
					//Append the file name (limit 10 chars)
					$oReply->appendString(str_pad(substr($oFile->getEconetName(),0,11),11,' '));
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
		$this->addReplyToBuffer($oReply);
	}

	public function getArgs($oFsRequest): void
	{
		$iHandle = $oFsRequest->getByte(1);
		$iArg = $oFsRequest->getByte(2);
		
		switch($iArg){
			case 0:
				//EC_FS_ARG_PTR
				$oReply = $oFsRequest->buildReply();
				$oFd = Vfs::getFsHandle($oFsRequest->getSourceNetwork(),$oFsRequest->getSourceStation(),$iHandle);
				$iPos = $oFd->fsFTell();
				$oReply->DoneOk();
				$oReply->append24bitIntLittleEndian($iPos);
				break;
			case 1:
				//EC_FS_ARG_EXT
				$oReply = $oFsRequest->buildReply();
				$oFd = Vfs::getFsHandle($oFsRequest->getSourceNetwork(),$oFsRequest->getSourceStation(),$iHandle);
				$aStat = $oFd->fsFStat();
				$iSize = $aStat['size'];
				$oReply->DoneOk();
				$oReply->append24bitIntLittleEndian($iSize);
				break;
			case 2:
				//EC_FS_ARG_SIZE
				$oReply = $oFsRequest->buildReply();
				$oFd = Vfs::getFsHandle($oFsRequest->getSourceNetwork(),$oFsRequest->getSourceStation(),$iHandle);
				$aStat = $oFd->fsFStat();
				$iSize = $aStat['size'];
				$oReply->DoneOk();
				$oReply->append24bitIntLittleEndian($iSize);
				break;
			default:
				$oReply = $oFsRequest->buildReply();
				$oReply->setError(0x8f,"Bad RDARGS argumen");
				$oReply->DoneOk();
				break;
		}
		$this->addReplyToBuffer($oReply);
	}

	/**
	 * Gets the current user enviroment
	 *
	 * Sends a reply with the name of the disc the csd is on the name of the csd and library
	*/
	public function getUenv($oFsRequest): void
	{
		$oReply = $oFsRequest->buildReply();
		$oReply->DoneOk();
		
		//Discname Max Length <16
		$oReply->appendByte(16);

		//csd Disc name String 16 bytes
		$oReply->appendString(str_pad(substr(config::getValue('vfs_disc_name'),0,16),16,' '));
		try {	
			//csd Leaf name String 10 bytes
			$oCsd = Vfs::getFsHandle($oFsRequest->getSourceNetwork(),$oFsRequest->getSourceStation(),$oFsRequest->getCsd());	
			$oReply->appendString(str_pad(substr($oCsd->getEconetDirName(),0,10),10,' '));

			//lib leaf name String 10 bytes
			$oLib = Vfs::getFsHandle($oFsRequest->getSourceNetwork(),$oFsRequest->getSourceStation(),$oFsRequest->getLib());	
			$oReply->appendString(str_pad(substr($oLib->getEconetDirName(),0,10),10,' '));
		}catch(Exception $oException){
			$oReply = $oFsRequest->buildReply();
			$oReply->setError(0xff,$oException->getMessage());
		}

		$this->addReplyToBuffer($oReply);
	}

	/**
	 * Changes the csd
	 *
	 * This method is invoked by the *DIR command
	*/
	public function changeDirectory($oFsRequest,$sOptions): void
	{
		$oReply = $oFsRequest->buildReply();
		$oUser = Security::getUser($oFsRequest->getSourceNetwork(),$oFsRequest->getSourceStation());

		//Chech the user is logged in
		if(!is_object($oUser)){
			$oReply->setError(0xbf,"Who are you?");
			$this->addReplyToBuffer($oReply);
			return;
		}
		
		if(strlen($sOptions)>0){
			try {
				if($sOptions=="^"){
					//Change to parent dir
					$oCsd = Vfs::getFsHandle($oFsRequest->getSourceNetwork(),$oFsRequest->getSourceStation(),$oFsRequest->getCsd());
					$sParentPath = $oCsd->getEconetParentPath();
					$oNewCsd = Vfs::createFsHandle($oFsRequest->getSourceNetwork(),$oFsRequest->getSourceStation(),$sParentPath);
				}else{
					$oNewCsd = Vfs::createFsHandle($oFsRequest->getSourceNetwork(),$oFsRequest->getSourceStation(),$sOptions);	
					if(!$oNewCsd->isDir()){
						$this->oLogger->debug("User tryed to change to directory ".$oNewCsd->getEconetDirName()." however its not a directory.");
						$oReply->setError(0xbe,"Not a directory");
						$this->addReplyToBuffer($oReply);
						return;
					}
				}
				Vfs::closeFsHandle($oFsRequest->getSourceNetwork(),$oFsRequest->getSourceStation(),$oFsRequest->getCsd());
				$oReply->DirOk();
				//Send new csd handle
				$oReply->appendByte($oNewCsd->getID());
				$oUser->setCsd($oNewCsd->getEconetPath());

			}catch(Exception $oException){
				//The directory did no exist
				$oReply->setError(0xff,"No such directory.");	
			}
		}else{
			//No directory selected, change to the users home dir
			try {
				$oNewCsd = Vfs::createFsHandle($oFsRequest->getSourceNetwork(),$oFsRequest->getSourceStation(),$oUser->getHomedir());
				Vfs::closeFsHandle($oFsRequest->getSourceNetwork(),$oFsRequest->getSourceStation(),$oFsRequest->getCsd());
				$oReply->DirOk();
				$oReply->appendByte($oNewCsd->getID());
				$oUser->setCsd($oNewCsd->getEconetPath());
			}catch(Exception $oException){
				$oReply->setError(0xff,"No such directory.");	
			}
		}
		$this->addReplyToBuffer($oReply);


	}

	/**
	 * Changes the library
	 *
	 * This method is invoked by the *LIB command
	*/
	public function changeLibrary($oFsRequest,$sOptions): void
	{
		$oReply = $oFsRequest->buildReply();
		
		if(strlen($sOptions)>0){
			try {
				$oNewLib = Vfs::createFsHandle($oFsRequest->getSourceNetwork(),$oFsRequest->getSourceStation(),$sOptions);	
				if(!$oNewLib->isDir()){
					$this->oLogger->debug("User tryed to change the library to ".$oNewLib->getEconetDirName()." however its not a directory.");
					$oReply->setError(0xbe,"Not a directory");
					$this->addReplyToBuffer($oReply);
					return;
				}
				Vfs::closeFsHandle($oFsRequest->getSourceNetwork(),$oFsRequest->getSourceStation(),$oFsRequest->getLib());
				$oReply->LibOk();
				//Send new csd handle
				$oReply->appendByte($oNewLib->getID());
			}catch(Exception $oException){
				//The directory did no exist
				$oReply->setError(0xff,"No such directory.");	
			}
		}else{
			$oReply->setError(0xff,"Syntax ?");	
		}
		$this->addReplyToBuffer($oReply);


	}

	/**
	 * Creates a new directory
	 *
	 * This method in invoked by the *CDIR command
	*/
	public function createDirectory($oFsRequest,$sOptions): void
	{
		$oReply = $oFsRequest->buildReply();
		if(strlen($sOptions)<1){
			$oReply->setError(0xff,"Syntax");
			$this->addReplyToBuffer($oReply);
			return;
		}
		if(strlen($sOptions)>10){
			$oReply->setError(0xff,"Maximum directory name length is 10");
			$this->addReplyToBuffer($oReply);
			return;
		}

		try {
			Vfs::createDirectory($oFsRequest->getSourceNetwork(),$oFsRequest->getSourceStation(),$sOptions);
			$oReply->DoneOk();
		}catch(Exception $oException){
			$oReply->setError(0xff,"Unable to create directory");
		}
		$this->addReplyToBuffer($oReply);
	}

	/**
	 * Deletes a given file
	 *
	 * This method is invoked as either a cli or a file server command depending on the nfs version
	*/
	public function deleteFile($oFsRequest,$sOptions): void
	{
		$oReply = $oFsRequest->buildReply();
		if(strlen($sOptions)<1){
			$oReply->setError(0xff,"Syntax");
		}else{
			try{
				Vfs::deleteFile($oFsRequest->getSourceNetwork(),$oFsRequest->getSourceStation(),$sOptions);
				$oReply->DoneOk();
			}catch(Exception $oException){
				$oReply->setError(0xff,"Unable to delete");
			}
		}
		$this->addReplyToBuffer($oReply);
	}

	/**
	 * Renames a given file
	 *
	 * This method is invoked as the cli command *RENAME
	*/ 
	public function renameFile($oFsRequest,$sOptions): void
	{
		$oReply = $oFsRequest->buildReply();
		if(strlen($sOptions)<2){
			$oReply->setError(0xff,"Syntax");
		}else{
			try{
				list($sFrom,$sTo) = explode(' ',$sOptions);
				Vfs::moveFile($oFsRequest->getSourceNetwork(),$oFsRequest->getSourceStation(),$sFrom,$sTo);
				$oReply->DoneOk();
			}catch(Exception $oException){
				$oReply->setError(0xff,"No such file");
			}
		}
		$this->addReplyToBuffer($oReply);
		
	}

	/**
	 * Opens a file
	 *
	*/
	public function openFile($oFsRequest): void
	{
		$iMustExist = $oFsRequest->getByte(1);
		$iReadOnly = $oFsRequest->getByte(2);
		$sPath = $oFsRequest->getString(3);
		$oReply = $oFsRequest->buildReply();
		if($iMustExist===0){
			$bMustExist = FALSE;
		}else{
			$bMustExist = TRUE;
		}
		if($iReadOnly===0){
			$bReadOnly = FALSE;
		}else{
			$bReadOnly = TRUE;
		}
		try {
			$oFsHandle = Vfs::createFsHandle($oFsRequest->getSourceNetwork(),$oFsRequest->getSourceStation(),$sPath,$bMustExist,$bReadOnly);
			$oReply->DoneOk();
			$oReply->appendByte($oFsHandle->getID());
		}catch(Exception $oException){
			$oReply->setError(0xff,"No such file");
		}
		$this->addReplyToBuffer($oReply);
	}

	/**
	 * Closes a file
	 *
	*/
	public function closeFile($oFsRequest): void
	{
		$iHandle = $oFsRequest->getByte(1);
		Vfs::closeFsHandle($oFsRequest->getSourceNetwork(),$oFsRequest->getSourceStation(),$iHandle);
		$oReply = $oFsRequest->buildReply();
		$oReply->DoneOk();
		$this->addReplyToBuffer($oReply);
	}

	public function getBytes($oFsRequest): void
	{
		//The urd becomes the port to send the data to
		$iDataPort = $oFsRequest->getUrd();
		//File handle
		$iHandle = $oFsRequest->getByte(1);
		//Use pointer
		$iUserPtr = $oFsRequest->getByte(2);
		//Number of bytes to get 
		$iBytes = $oFsRequest->get24bitIntLittleEndian(3);
		//Offset (only use if $iUserPtr!=0)
		$iOffset = $oFsRequest->get24bitIntLittleEndian(6);
	
		$this->oLogger->debug("Getbytes handle ".$iHandle." size ".$iBytes." prt ".$iUserPtr." offset ".$iOffset.".");

		$oFsHandle = Vfs::getFsHandle($oFsRequest->getSourceNetwork(),$oFsRequest->getSourceStation(),$iHandle);	
		
		//Send reply directly
		$oReply = $oFsRequest->buildReply();
		$oReply->DoneOk();
		$this->addReplyToBuffer($oReply);

		$_this = $this;
		$oServiceDispatcher = $this->oServiceDispatcher;
	
		$this->oServiceDispatcher->addAckEvent($oFsRequest->getSourceNetwork(),$oFsRequest->getSourceStation(),function() use ($_this, $oFsHandle, $oFsRequest, $iBytes, $iOffset, $iDataPort, $oServiceDispatcher){
			$oFsHandle->setPos($iOffset);
			$iBytesToRead = $iBytes;
			if($iBytesToRead>256){
				$sBlock = $oFsHandle->read(256);
				$iBytesToRead=$iBytesToRead-256;
			}else{
				$sBlock = $oFsHandle->read($iBytesToRead);
				$iBytesToRead = $iBytesToRead-strlen($sBlock);
			}

			$oEconetPacket = new EconetPacket();
			$oEconetPacket->setDestinationNetwork($oFsRequest->getSourceNetwork());
			$oEconetPacket->setDestinationStation($oFsRequest->getSourceStation());
			$oEconetPacket->setPort($iDataPort);
			$oEconetPacket->setData($sBlock);

			$this->addReplyToBuffer($oEconetPacket);
			$this->oServiceDispatcher->sendPackets($this);

			$cAckHandler = function($oAckPacket, $_this, $oFsRequest, $oServiceDispatcher, $iBytes, $iBytesToRead, $oFsHandle, $iDataPort, &$cAckHandler){
				if($iBytesToRead==0 OR $oFsHandle->isEof()){
					$oReply2 = $oFsRequest->buildReply();
					$oReply2->DoneOk();
					//Flag
					if($oFsHandle->isEof()){
						//As we have hit EOF the number of bytes sent has fallen short of the ammount requested send the remaining bytes
						$oEconetPacket = new EconetPacket();
						$oEconetPacket->setDestinationNetwork($oFsRequest->getSourceNetwork());
						$oEconetPacket->setDestinationStation($oFsRequest->getSourceStation());
						$oEconetPacket->setPort($iDataPort);
						$oEconetPacket->setData(str_pad("",$iBytesToRead,0));
						$_this->addReplyToBuffer($oEconetPacket);
						$oReply2->appendByte(0x80);
					}else{
						$oReply2->appendByte(0);
					}
					//Number of bytes sent
					$oReply2->append24bitIntLittleEndian($iBytes-$iBytesToRead);
					$oReply2->setFlags($oAckPacket->getFlags());
					$_this->addReplyToBuffer($oReply2);
					$oServiceDispatcher->sendPackets($_this);

				}else{
					if($iBytesToRead>256){
						$sBlock = $oFsHandle->read(256);
					}else{
						$sBlock = $oFsHandle->read($iBytesToRead);
					}
					$iBytesToRead = $iBytesToRead-strlen($sBlock);

					$oEconetPacket = new EconetPacket();
					$oEconetPacket->setDestinationNetwork($oFsRequest->getSourceNetwork());
					$oEconetPacket->setDestinationStation($oFsRequest->getSourceStation());
					$oEconetPacket->setPort($iDataPort);
					$oEconetPacket->setData($sBlock);

					$_this->addReplyToBuffer($oEconetPacket);
					$oServiceDispatcher->addAckEvent($oFsRequest->getSourceNetwork(),$oFsRequest->getSourceStation(),function() use ($_this, $oFsRequest, $oServiceDispatcher, $iBytes, $iBytesToRead, $oFsHandle, $iDataPort, $cAckHandler){
						($cAckHandler)($_this, $oFsRequest, $oServiceDispatcher, $iBytes, $iBytesToRead, $oFsHandle, $iDataPort, $cAckHandler);
					});
					$oServiceDispatcher->sendPackets($_this);
				}

			};

			$oServiceDispatcher->addAckEvent($oFsRequest->getSourceNetwork(),$oFsRequest->getSourceStation(),function($oAckPacket) use (&$cAckHandler, $_this, $oFsRequest, $oServiceDispatcher, $iBytes, $iBytesToRead, $oFsHandle, $iDataPort) {
				($cAckHandler)($oAckPacket, $_this, $oFsRequest, $oServiceDispatcher, $iBytes, $iBytesToRead, $oFsHandle, $iDataPort, $cAckHandler) ;
			});
		});

		$this->oServiceDispatcher->sendPackets($this);

	}

	public function putBytes($oFsRequest): void
	{
		//File handle
		$iHandle = $oFsRequest->getByte(1);
		//Use pointer
		$iUserPtr = $oFsRequest->getByte(2);
		//Number of bytes to get 
		$iBytes = $oFsRequest->get24bitIntLittleEndian(3);
		//Offset (only use if $iUserPtr!=0)
		$iOffset = $oFsRequest->get24bitIntLittleEndian(6);
		$this->oLogger->debug("Putbytes handle ".$iHandle." size ".$iBytes." prt ".$iUserPtr." offset ".$iOffset.".");

		$oFsHandle = Vfs::getFsHandle($oFsRequest->getSourceNetwork(),$oFsRequest->getSourceStation(),$iHandle);	

		if($iUserPtr!=0){
			$this->oLogger->debug("Moving point ".$iOffset." bytes along the file ");
			//Move the file pointer to offset
			$oFsHandle->setPos($iOffset);
		}
		
		$oReply = $oFsRequest->buildReply();
		$oReply->DoneOk();
		$oReply->appendByte(config::getValue('econet_data_stream_port'));
		//Add max block size
		$oReply->append16bitIntLittleEndian(256);

		//Send reply directly
		$this->addReplyToBuffer($oReply);

		$_this = $this;

		$this->addStream($oFsRequest->getSourceNetwork(),$oFsRequest->getSourceStation(), 
			new StreamIn(
				config::getValue('econet_data_stream_port'),
				$iBytes,
				function($oStream,$oPacket) use ($oFsRequest, $_this) {
					$oAck = $oFsRequest->buildReply();
					$oAck->DoneOk();
					$oAckPackage = $oAck->buildEconetpacket();
					$oAckPackage->setPort(0x91);
					$_this->addReplyToBuffer($oAckPackage);
				},
				function($oStream,$sData) use ($oFsRequest, $oFsHandle, $_this){
					$oFsHandle->write($sData);
					usleep(config::getValue('bbc_default_pkg_sleep'));
					$oReply2 = $oFsRequest->buildReply();
			                $oReply2->DoneOk();
			                $oReply2->appendByte(0);
					$oReply2->append24bitIntLittleEndian(strlen($sData));
					$_this->addReplyToBuffer($oReply2);
					$_this->freeStream($oStream,$oFsRequest->getSourceNetwork(),$oFsRequest->getSourceStation());
				},
				function($oStream, $sError) use($oFsRequest, $_this) {
					$_this->oLogger->debug("Putbytes waiting for data (".$sError.")");
					$_this->freeStream($oStream,$oFsRequest->getSourceNetwork(),$oFsRequest->getSourceStation());
					$oFailReply=$oFsRequest->buildReply();
					$oFailReply->setError(0xff,"Timeout");
					$this->addReplyToBuffer($oFailReply);
				}
			)
		);

	}

	public function getByte($oFsRequest): void
	{
		$iHandle = $oFsRequest->getByte(1);
		$oReply = $oFsRequest->buildReply();
		$oReply->DoneOk();

		$this->oLogger->debug("Getbyte handle ".$iHandle." ");
		//Reads a byte from the file handle
		$oFsHandle = Vfs::getFsHandle($oFsRequest->getSourceNetwork(),$oFsRequest->getSourceStation(),$iHandle);	
		if($oFsHandle->isEof()){
			$oReply->appendByte(0);
			$oReply->appendByte(0x80);
		}else{
			$oReply->appendByte($oFsRequest->read(1));
			$oReply->appendByte(0);
		}

		$this->addReplyToBuffer($oReply);
		
	}

	public function putByte($oFsRequest): void
	{
		$iHandle = $oFsRequest->getByte(1);
		$iByte = $oFsRequest->getByte(2);
		$oReply = $oFsRequest->buildReply();

		$this->oLogger->debug("Putbyte handle ".$iHandle." ");
		//Writes a byte to the file handle
		$oFsHandle = Vfs::getFsHandle($oFsRequest->getSourceNetwork(),$oFsRequest->getSourceStation(),$iHandle);	
		$oFsRequest->write($iByte);

		$oReply->DoneOk();
		$this->addReplyToBuffer($oReply);
	}

	/**
	 * Saves data to a file
	 *
	 * This method if invoked by the use saving a basic program 
	*/
	public function saveFile($oFsRequest): void
	{
		//For save operation the urd is replaced with the ackport
		$iAckPort = $oFsRequest->getUrd();

		//Load 4 bytes
		$iLoad = $oFsRequest->get32bitIntLittleEndian(1);

		//Exec 4 bytes
		$iExec =  $oFsRequest->get32bitIntLittleEndian(5);

		//Size
		$iSize = $oFsRequest->get24bitIntLittleEndian(9);

		//Path
		$sPath = $oFsRequest->getString(12);


		//Set port for the client to stream data to
		$oReply = $oFsRequest->buildReply();
		$oReply->DoneOk();
		//Add the port to stream to
		$oReply->appendByte(config::getValue('econet_data_stream_port'));
		//Add max block size
		$oReply->append16bitIntLittleEndian(968);

		$this->oLogger->debug("Save File ".$sPath." of size ".$iSize);
		//Send reply directly
		$oReplyEconetPacket = $oReply->buildEconetpacket();
		$this->addReplyToBuffer($oReplyEconetPacket);	
		$sData = '';
		$_this = $this;

		$this->addStream($oFsRequest->getSourceNetwork(),$oFsRequest->getSourceStation(), 
			new StreamIn(
				config::getValue('econet_data_stream_port'),
				$iSize,
				function($oStream,$oPacket) use ($oFsRequest, $iAckPort, $_this) {
					$oReply = $oFsRequest->buildReply();
					$oReply->DoneOk();
					$oReplyEconetPacket = $oReply->buildEconetpacket();
					$oReplyEconetPacket->setPort($iAckPort);
					$_this->addReplyToBuffer($oReplyEconetPacket);
					$_this->oLogger->debug("Replay sent for block of ".strlen($oPacket->getData()));
				},
				function($oStream,$sData) use ($oFsRequest, $sPath, $iLoad, $iExec, $_this){
					Vfs::saveFile($oFsRequest->getSourceNetwork(),$oFsRequest->getSourceStation(),$sPath,$sData,$iLoad,$iExec);
				
					//File is saved 	
					$oReply2 = $oFsRequest->buildReply();
					$oReply2->DoneOk();
					$oReply2->appendByte(15);

					//Add current date	
					$iDay = date('j',time());
					$oReply2->appendByte($iDay);

					//The last byte is month and year, first 4 bits year, last 4 bits month
					$iYear= date('y',time());
					$iYear << 4;
					$iYear = $iYear+date('n',time());
					$oReply2->appendByte($iYear);
					$_this->addReplyToBuffer($oReply2);
					$_this->freeStream($oStream,$oFsRequest->getSourceNetwork(),$oFsRequest->getSourceStation());
				},
				function($oStream, $sError) use($oFsRequest, $_this) {
					$_this->oLogger->debug("Filesave failed (".$sError.")");
					$_this->freeStream($oStream,$oFsRequest->getSourceNetwork(),$oFsRequest->getSourceStation());
					$oFailReply=$oFsRequest->buildReply();
					$oFailReply->setError(0xff,"Timeout");
					$this->addReplyToBuffer($oFailReply);
				}
			)
		);
		
	}

	/**
	 * Loads data from a file
	 *
	 * This methos is invoked by the use of LOAD "filename"
	*/
	public function loadFile($oFsRequest): void
	{
		//The urd handle in the request is not the urd when load is called but denotes the port to stream the data to
		$iDataPort = $oFsRequest->getUrd();
		$sPath = $oFsRequest->getString(1);

		$oReply = $oFsRequest->buildReply();
		
		try {
			$sFileData = Vfs::getFile($oFsRequest->getSourceNetwork(),$oFsRequest->getSourceStation(),$sPath);
			$oMeta = Vfs::getMeta($oFsRequest->getSourceNetwork(),$oFsRequest->getSourceStation(),$sPath);
		}catch(Exception $oException){
			$oReply->setError(0x99,"No such file");
			$this->addReplyToBuffer($oReply);
			return;			
		}

		//Send the first reply 
		$oReply->DoneOk();
		$oReply->append32bitIntLittleEndian($oMeta->getLoadAddr());
		$oReply->append32bitIntLittleEndian($oMeta->getExecAddr());
		$oReply->append24bitIntLittleEndian($oMeta->getSize());
		$oReply->appendByte($oMeta->getAccess());
		//Add ctime 2 bytes day,year+month
		$oReply->appendRaw($oMeta->getCTime());
		$oReplyEconetPacket = $oReply->buildEconetpacket();
		$this->addReplyToBuffer($oReplyEconetPacket);	

		$_this = $this;
		$oLoop = $this->oServiceDispatcher->getLoop();
		$fTime = config::getValue('bbc_default_pkg_sleep');
		$oServiceDispatcher = $this->oServiceDispatcher;

		//Break the data into blocks and send it
		while(strlen($sFileData)>0){
			
			//Build a 256 byte block
			$sBlock = substr($sFileData,0,256);
			//Remote 256 byte from the string
			$sFileData=substr($sFileData,256);
			$oEconetPacket = new EconetPacket();
			$oEconetPacket->setDestinationNetwork($oFsRequest->getSourceNetwork());
			$oEconetPacket->setDestinationStation($oFsRequest->getSourceStation());
			$oEconetPacket->setPort($iDataPort);
			$oEconetPacket->setData($sBlock);
	
			$oLoop->setTimer($fTime/1000000,function() use ($_this, $oEconetPacket, $oServiceDispatcher){
				$_this->addReplyToBuffer($oEconetPacket);
				$oServiceDispatcher->sendPackets($_this);
			});
			$fTime = $fTime + config::getValue('bbc_default_pkg_sleep');
		}


		$oReply2 = $oFsRequest->buildReply();
		$oReply2->DoneOk();
		$oLoop->setTimer($fTime/1000000,function() use ($_this, $oReply2){
			$_this->addReplyToBuffer($oReply2);
		});

	}

	/**
	 * Set the current users password
	 *
	 * This method is invoked by the *PASS command
	*/
	public function setPassword($oFsRequest,$sOptions): void
	{
		$aOptions = explode(' ',$sOptions);
		$oReply = $oFsRequest->buildReply();
		if(count($aOptions)!=2){
			$oReply->setError(0xff,"Syntax");
		}else{
			//Filter out the string added by the use of *pass :
			if(substr_count($aOptions[0],"\r")>0){
				list($aOptions[0]) = explode("\r",$aOptions[0]);
			}
			if(substr_count($aOptions[1],"\r")>0){
				list($aOptions[1]) = explode("\r",$aOptions[1]);
			}


			//Get the old password
			if($aOptions[0]=='""'){
				$sOldPassword = NULL;
			}else{
				$sOldPassword = $aOptions[0];
			}

			//Get the new password
			if($aOptions[1]=='""'){
				$sPassword = NULL;
			}else{
				$sPassword = $aOptions[1];
			}

			try {
				//Change the password
				Security::setConnectedUsersPassword($oFsRequest->getSourceNetwork(),$oFsRequest->getSourceStation(),$sOldPassword,$sPassword);
				$oReply->DoneOk();
			}catch(Exception $oException){
				$oReply->setError(0xff,$oException->getMessage());
			}
		}
		$this->addReplyToBuffer($oReply);
	}

	/**
	 * Creates a new user (*NEWUSER)
	 *
	*/
	public function createUser($oFsRequest,$sOptions): void
	{
		$oReply = $oFsRequest->buildReply();
		if(strlen($sOptions)<1){
			$oReply->setError(0xff,"Syntax");
		}else{
			$aOptions = explode(' ',$sOptions);
			if(strlen($aOptions[0])>3 AND strlen($aOptions[0])<11 AND ctype_upper($aOptions[0]) AND ctype_alpha($aOptions[0])){
				$oUser = new User();
				$oUser->setUsername($aOptions[0]);
				if(!is_null(config::getValue('vfs_home_dir_path'))){
					$oUser->setHomedir(config::getValue('vfs_home_dir_path').'.'.$aOptions[0]);
					try {
						Vfs::createDirectory($oFsRequest->getSourceNetwork(),$oFsRequest->getSourceStation(),config::getValue('vfs_home_dir_path').'.'.$aOptions[0]);
					}catch(Exception $oException){
					}
				}else{
					$oUser->setHomedir('$');
				}
				$oUser->setUnixUid(config::getValue('Security_default_unix_uid'));
				$oUser->setPriv('U');
				try{
					Security::createUser($oFsRequest->getSourceNetwork(),$oFsRequest->getSourceStation(),$oUser);
					$oReply->DoneOk();
				}catch(Exception $oException){
					$oReply->setError(0xff,$oException->getMessage());
				}
			}else{
				$oReply->setError(0xff,"Username must be between 3-10 chars and only contain the chars A-Z");
			}
		
		}
		$this->addReplyToBuffer($oReply);
	}

	/**
	 * Removes a user (*REMUSER)
	 *
	*/
	public function removeUser($oFsRequest,$sOptions): void
	{
		$oReply = $oFsRequest->buildReply();
		if(strlen($sOptions)<1 OR !ctype_alnum($sOptions)){
			$oReply->setError(0xff,"Syntax");
		}else{
			try {
				if(Security::removeUser($oFsRequest->getSourceNetwork(),$oFsRequest->getSourceStation(),$sOptions)){
					$oReply->DoneOk();
				}else{
					$oReply->setError(0xff,"No such user");
				}
			}catch(Exception $oException){
				$oReply->setError(0xff,"You do not have admin rights");
			}
		}
		$this->addReplyToBuffer($oReply);
	}

	/**
	 * Set the privalage of a given user
	 *
	*/
	public function privUser($oFsRequest,$sOptions): void
	{
		$aOptions = explode(' ',$sOptions);
		$oReply = $oFsRequest->buildReply();
		if(count($aOptions)!=2){
			$oReply->setError(0xff,"Syntax");
		}else{
			$oMyUser = Security::getUser($oFsRequest->getSourceNetwork(),$oFsRequest->getSourceStation());
			if($oMyUser->isAdmin()){
				if($aOptions[1]!='S' AND $aOptions[1]!='U'){
					$oReply->setError(0xff,"The only valid priv is S or U");
				}else{
					Security::setPriv($oFsRequest->getSourceNetwork(),$oFsRequest->getSourceStation(),$aOptions[0],$aOptions[1]);
					$oReply->DoneOk();
				}
			}else{
				$oReply->setError(0xff,"Only user with priv S can use *PRIV");
			}
			
		}
		$this->addReplyToBuffer($oReply);
	}

	/**
	 * Implements the commnad sdisc
	 *
	*/
	public function sDisc($oFsRequest,$sOptions): void
	{
		//As we can only ever have one disc this command has rather little todo
		$oReply = $oFsRequest->buildReply();
		$oReply->DoneOk();
		$oReply->appendByte($oFsRequest->getUrd());
		$oReply->appendByte($oFsRequest->getCsd());
		$oReply->appendByte($oFsRequest->getLib());
		$this->addReplyToBuffer($oReply);
	}

	public function chroot($oFsRequest,$sOptions): void
	{
		$oReply = $oFsRequest->buildReply();
		$oUser = security::getUser($oFsRequest->getSourceNetwork(),$oFsRequest->getSourceStation());

		if($sOptions=="^"){
			//Change to parent dir
			$oCsd = Vfs::getFsHandle($oFsRequest->getSourceNetwork(),$oFsRequest->getSourceStation(),$oFsRequest->getCsd());
			$sParentPath = $oCsd->getEconetParentPath();
			$oNewRootDir = Vfs::createFsHandle($oFsRequest->getSourceNetwork(),$oFsRequest->getSourceStation(),$sParentPath);
		}else{
			$oNewRootDir = Vfs::createFsHandle($oFsRequest->getSourceNetwork(),$oFsRequest->getSourceStation(),$sOptions);	
		}

		if(!$oNewRootDir->isDir()){
			$this->oLogger->debug("User tryed to change to directory ".$oNewRootDir->getEconetDirName()." however its not a directory.");
			$oReply->setError(0xbe,"Not a directory");
			$this->addReplyToBuffer($oReply);
			return;
		}

		$oUser->setRoot($oNewRootDir->getEconetDirName());

		Vfs::closeFsHandle($oFsRequest->getSourceNetwork(),$oFsRequest->getSourceStation(),$oFsRequest->getCsd());
		Vfs::closeFsHandle($oFsRequest->getSourceNetwork(),$oFsRequest->getSourceStation(),$oNewRootDir);
		$oNewCsd = Vfs::createFsHandle($oFsRequest->getSourceNetwork(),$oFsRequest->getSourceStation(),'$');
		$oReply->DirOk();
		$oReply->appendByte($oNewCsd->getID());
		$oUser->setCsd($oNewCsd->getEconetPath());
		$this->addReplyToBuffer($oReply);
			
	}

	/**
	 * Turns off the chroot feature reverting back to the true root of the filestore 
	 *
	*/
	public function chrootoff($oFsRequest,$sOptions): void
	{
		$oReply = $oFsRequest->buildReply();
		$oUser = security::getUser($oFsRequest->getSourceNetwork(),$oFsRequest->getSourceStation());
		$oCsd = Vfs::getFsHandle($oFsRequest->getSourceNetwork(),$oFsRequest->getSourceStation(),$oFsRequest->getCsd());
		$sNewPath = str_replace('$',$oUser->getRoot(),$oCsd->getEconetDirName());
		$oUser->setRoot('$');
		Vfs::closeFsHandle($oFsRequest->getSourceNetwork(),$oFsRequest->getSourceStation(),$oFsRequest->getCsd());
		$oNewCsd = Vfs::createFsHandle($oFsRequest->getSourceNetwork(),$oFsRequest->getSourceStation(),$sNewPath);
		$oReply->DirOk();
		$oReply->appendByte($oNewCsd->getID());
		$oUser->setCsd($oNewCsd->getEconetPath());
		$this->addReplyToBuffer($oReply);
	}

	/**
	 * Lists the users logged in
	 *
	*/
	public function usersOnline($oFsRequest): void
	{
		$iStart = $oFsRequest->getByte(1);
		$iCount	= $oFsRequest->getByte(2);
		$oReply = $oFsRequest->buildReply();
		$oReply->DoneOK();
		$aUsers = Security::getUsersOnline();
		$this->oLogger->debug("usersOnline: There are ".count($aUsers)." on-line, the clients request details of (".$iStart."/".$iCount.")");
		$iUsersRemaining = count($aUsers)-$iStart;
		if($iUsersRemaining>0){
			$oReply->appendByte($iUsersRemaining);
		}else{
			$oReply->appendByte(0);
		}
		$i = 0;
		foreach($aUsers as $iNetwork=>$aStationUsers){
			foreach($aStationUsers as $iStation=>$aData){
				if($iStart <= $i AND $i <= ($iStart+$iCount)){
					$oUser = $aData['user'];
					$oReply->appendByte($iNetwork);
					$oReply->appendByte($iStation);
					$oReply->appendString(substr($oUser->getUsername(),0,10));
					$oReply->appendByte(0x0d);
					if($oUser->isAdmin()){
						$oReply->appendByte(1);
					}else{
						$oReply->appendByte(0);
					}
				}
				if($i>($iStart+$iCount)){
					$this->addReplyToBuffer($oReply);
					return;
				}
			}
		}
		$this->addReplyToBuffer($oReply);
	}

	/**
	 * Get the network and station number for a given user
	*/
	public function getUsersStation($oFsRequest): void
	{
		$sUser = $oFsRequest->getString(1);
		$oReply = $oFsRequest->buildReply();

		$aStation = Security::getUsersStation($sUser);
		if(array_key_exists('network',$aStation) AND array_key_exists('station',$aStation)){
			$oUser = Security::getUser($aStation['network'],$aStation['station']);
			if(is_object($oUser) AND $oUser->isAdmin()){
				$oReply->appendByte(1);
			}else{
				$oReply->appendByte(0);
			}
			$oReply->appendByte($aStation['network']);
			$oReply->appendByte($aStation['station']);	
		}else{
			$oReply->DoneNoton();
		}
		$this->addReplyToBuffer($oReply);	
	}

	/**
	 * Gets a list of discs
	*/
	public function getDiscs($oFsRequest): void
	{
		$iDrive = $oFsRequest->getByte(1);
		$iNDrives = $oFsRequest->getByte(2);

		$oReply = $oFsRequest->buildReply();
		$oReply->DiscsOk();

		if($iDrive == 0 AND $iNDrives > 0){
			//Add the number of discs 
			$oReply->appendByte(1);
			//Add the drive number
			$oReply->appendByte(0);
			//Add the drive name
			$oReply->appendString(str_pad(substr(config::getValue('vfs_disc_name'),0,16),16,' '));
		}else{
			//Indicate that no more discs are present
			$oReply->appendByte(0);
		}
		$this->addReplyToBuffer($oReply);	
	}

	/**
	 * Gets the free space for a disc
	 * 
	 * The answer is fake a BBCs can't handle the same sizes as Linux
	*/
	public function getDiscFree($oFsRequest): void
	{
		$sDisc = $oFsRequest->getString(1);
		$oReply = $oFsRequest->buildReply();

		$oReply->DoneOk();
		$oReply->append32bitIntLittleEndian(config::getValue('vfs_default_disc_free'));
		$oReply->append32bitIntLittleEndian(config::getValue('vfs_default_disc_size'));
		$this->addReplyToBuffer($oReply);
	}

	/**
	 * Gets the version of the server
	 *
	*/
	public function getVersion($oFsRequest): void
	{
		$oReply = $oFsRequest->buildReply();
		$oReply->DoneOk();
		$oReply->appendString("aunfs_srv ".config::getValue('version'));
		$this->addReplyToBuffer($oReply);
	}

	/**
	 * Gets the time
	*/
	public function getTime($oFsRequest): void
	{
		$oReply = $oFsRequest->buildReply();
		$iTime = time();

		$oReply->DoneOk();
		//Day
		$oReply->appendByte(date('j',$iTime));
		//Hi 4bits year, low 4bits Month
		$oReply->appendByte( ((date('y',$iTime)<<4)+date('n',$iTime)) );
		//Hour
		$oReply->appendByte(date('G',$iTime));
		//Min
		$oReply->appendByte(ltrim(date('i',$iTime),0));

		$this->addReplyToBuffer($oReply);
	}

	/**
	 * Creates a file
	 *
	*/
	public function createFile($oFsRequest): void
	{
		$iAckPort = $oFsRequest->getUrd();

		//Load 4 bytes
		$iLoad = $oFsRequest->get32bitIntLittleEndian(1);

		//Exec 4 bytes
		$iExec =  $oFsRequest->get32bitIntLittleEndian(5);

		//Size
		$iSize = $oFsRequest->get24bitIntLittleEndian(9);

		//Path
		$sPath = $oFsRequest->getString(12);

		//Create the file
		Vfs::createFile($oFsRequest->getSourceNetwork(),$oFsRequest->getSourceStation(),$sPath,$iSize,$iLoad,$iExec);

		$oReply = $oFsRequest->buildReply();
		$oReply->DoneOk();
		$oReply->appendByte(15);
		
		//Add current date
		$iDay = date('j',time());
		$oReply->appendByte($iDay);
		//The last byte is month and year, first 4 bits year, last 4 bits month
		$iYear= date('y',time());
		$iYear << 4;
		$iYear = $iYear+date('n',time());
		$oReply->appendByte($iYear);
		
		$this->addReplyToBuffer($oReply);
	}

	/**
	 * Gets the disk space free value for a user
	 *
	 * Given we can't map the scale of Linux storage sizes to bbc storage sizes, the amount of free space is just a constant.
	 * Maybe at some point this could be mapped to a unix users quota if the system has quotas setup
	*/
	public function getUserDiscFree($oFsRequest): void
	{
		//Username
		$sUsername = $oFsRequest->getString(1);

		$oReply = $oFsRequest->buildReply();
		$oReply->DoneOk();
		$oReply->append24bitIntLittleEndian(config::getValue('vfs_default_disc_free'));

		$this->addReplyToBuffer($oReply);
	}
}
