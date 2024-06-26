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

	protected $aCommands = ['BYE', 'CAT', 'CDIR', 'DELETE', 'DIR', 'FSOPT', 'INFO', 'I AM', 'LIB', 'LOAD', 'LOGOFF', 'PASS', 'RENAME', 'SAVE', 'SDISC', 'NEWUSER', 'PRIV', 'REMUSER', 'i.' ,'i .', 'CHROOTOFF', 'CHROOT'];
	
	protected $aReplyBuffer = [];

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

	public function addReplyToBuffer(EconetPacket $oReply): void
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
		return [0x99, config::getValue('econet_data_stream_port')];
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
			switch($oReply::class){
				case \HomeLan\FileStore\Messages\FsReply::class:
					$aReturn[] = $oReply->buildEconetpacket();
					break;
				case \HomeLan\FileStore\Messages\EconetPacket::class:
					$aReturn[] = $oReply;
					break;
				default:
					$this->oLogger->warning("Service provider filestore produced a reply of the invalid type ".$oReply::class." dropping");
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
	private function addStream(int $iNetwork, int $iStation,StreamIn $oStream): void
	{
		if(!array_key_exists($iNetwork,$this->aStreamsIn) OR !is_array($this->aStreamsIn[$iNetwork])){
			$this->aStreamsIn[$iNetwork]=[];
		}
		$this->aStreamsIn[$iNetwork][$iStation]=$oStream;
	}

	/**
	 * Frees an existing io stream
	*/
	private function freeStream(int $iNetwork, int $iStation, StreamIn $oStream): void
	{
		unset($this->aStreamsIn[$iNetwork][$iStation]);
		unset ($oStream);
	}

	public function getStreams(): array
	{
		$aStreams = [];
		foreach ($this->aStreamsIn as $iNetwork=>$aStations){
			foreach($aStations as $iStation=>$oStream){
				$aStreams[] = ['network'=>$iNetwork,'station'=>$iStation,'stream'=>$oStream];
			}
		}
		return $aStreams;
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
	 * @param fsrequest $oFsRequest
	*/
	public function processRequest(FsRequest $oFsRequest): void
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

		}

		//Function where the user must be logged in
		if(!Security::isLoggedIn($oFsRequest->getSourceNetwork(),$oFsRequest->getSourceStation())){
			$oReply = $oFsRequest->buildReply();
			$oReply->setError(0xbf,"Who are you?");
			$this->addReplyToBuffer($oReply->buildEconetpacket());
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
				$this->setOpt($oFsRequest);
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
			case 'EC_FS_FUNC_WHO_AM_I':
				$this->whoAmI($oFsRequest);
				break;
			default:
				$this->oLogger->debug("Un-handled fs function ".$sFunction);
				break;
				
		}
	}

	/**
	 * Reads which file handle is stored in the requests csd and lib byte, and updates the users csd and lib 
	*/
	public function updateCsdLib(FsRequest $oFsRequest): void
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
		$sCommand = null;
  		$sData = $oFsRequest->getData();
		$aDataAs8BitInts = unpack('C*',(string) $sData);
		$sDataAsString = "";
		foreach($aDataAs8BitInts as $iChar){
			$sDataAsString = $sDataAsString.chr($iChar);
		}

		$this->oLogger->debug("Command: ".$sDataAsString.".");

		foreach($this->aCommands as $sCommand){
			$iPos = stripos($sDataAsString,(string) $sCommand);
			if($iPos===0){
				//Found cli command found
				$iOptionsPos = $iPos+strlen((string) $sCommand);
				$sOptions = substr($sDataAsString,$iOptionsPos);
				$this->runCli($oFsRequest,$sCommand,trim($sOptions));
				return;
			}			
		}
		$oReply = $oFsRequest->buildReply();
		$oReply->UnrecognisedOk();
		$oReply->appendString($sCommand);
		$this->addReplyToBuffer($oReply->buildEconetpacket());
	}

	/**
	 * This method runs the cli command, or delegate to an approriate method
	 *
	 * @param fsrequest $oFsRequest The fsrequest
	 * @param string $sCommand The command to run
	 * @param string $sOptions The command arguments
	*/
	public function runCli(FsRequest $oFsRequest,string $sCommand,string $sOptions): void
	{
		switch(strtoupper($sCommand)){
				case 'BYE':
			case 'LOGOFF':
				$this->logout($oFsRequest);
				break;
			case 'I AM':
			case 'I .':
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
				$this->addReplyToBuffer($oReply->buildEconetpacket());
				break;
		}
	}

	/**
	 * Handles loading *COMMANDs stored on the server
	 * 
	 * @param fsrequest $oFsRequest
	*/
	public function loadCommand(FsRequest $oFsRequest): void
	{
		$this->loadFile($oFsRequest);	
	}

	/**
	 * Handles login requests (*I AM)
	 *
	 * @param fsrequest $oFsRequest
	 * @param ?string $sOptions The arguments passed to *I AM (e.g. username password)
	*/
	public function login(FsRequest $oFsRequest,?string $sOptions): void
	{
		$this->oLogger->debug("fileserver: Login called ".$sOptions);
		$aOptions = explode(" ",$sOptions);
		if(strlen($sOptions)>0){
			//Creditials supplied, decode username and password
			$sUser = $aOptions[0];
			if(array_key_exists(1,$aOptions)){
				$sPass = trim($aOptions[1]);
				if(substr_count($sPass,"\r")>0){
					[$sPass] = explode("\r",$sPass);
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
			$this->addReplyToBuffer($oReply->buildEconetpacket());
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
			}catch(Exception){
				$this->oLogger->info("fileserver: Login unable to open homedirectory for user ".$oUser->getUsername());
				$oUrd = Vfs::createFsHandle($oFsRequest->getSourceNetwork(),$oFsRequest->getSourceStation(),'$');
				$oCsd = Vfs::createFsHandle($oFsRequest->getSourceNetwork(),$oFsRequest->getSourceStation(),'$');
			}
			try {
				$oLib = Vfs::createFsHandle($oFsRequest->getSourceNetwork(),$oFsRequest->getSourceStation(),$oUser->getLib());
			}catch(Exception){
				$this->oLogger->info("fileserver: Login unable to open library dir setting library to $ for user ".$oUser->getUsername());
				$oLib = Vfs::createFsHandle($oFsRequest->getSourceNetwork(),$oFsRequest->getSourceStation(),'');
			}
			//Handles are now build send the reply 
			$oReply = $oFsRequest->buildReply();
			$this->oLogger->debug("fileserver: Login ok urd:".$oUrd->getId()." csd:".$oCsd->getId()." lib:".$oLib->getId());
			$oReply->loginRespone($oUrd->getId(),$oCsd->getId(),$oLib->getId(),$oUser->getBootOpt());
			$this->addReplyToBuffer($oReply->buildEconetpacket());
		}else{
			//Login failed
			$oReply = $oFsRequest->buildReply();

			//Send Wrong Password
			$this->oLogger->info("Login Failed: For user ".$sUser." invalid password/no such user");
			$oReply->setError(0xbb,"Incorrect password");
			$this->addReplyToBuffer($oReply->buildEconetpacket());
		}
			
	}

	/**
	 * Handle logouts (*bye)
	 *
	 * We can be called as a cli command (*bye) and by its own function call
	 * @param fsrequest $oFsRequest
	*/
	public function logout(FsRequest $oFsRequest): void
	{
		try{
			Security::logout($oFsRequest->getSourceNetwork(),$oFsRequest->getSourceStation());
			$oReply = $oFsRequest->buildReply();	
			$oReply->DoneOk();
		}catch(Exception){
			$oReply = $oFsRequest->buildReply();
			$oReply->setError(0xbf,"Who are you?");
		}
		$this->addReplyToBuffer($oReply->buildEconetpacket());
	}

	/**
  * Handles the *info command
  *
  * @param fsrequest $oFsRequest
  */
 public function cmdInfo(FsRequest $oFsRequest,string $sFile): void
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
		}catch(Exception){
			$oReply->setError(0xff,"No such file");
		}
		$this->addReplyToBuffer($oReply->buildEconetpacket());
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
				}catch(Exception){
					$oReply->DoneOk();
					$oReply->appendByte(0x00);
					$oReply->appendByte(0x00);
					$oReply->appendByte(0x00);
				}
				$this->addReplyToBuffer($oReply->buildEconetpacket());
				return;
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
				}catch(Exception){
					$oReply->DoneOk();
					$oReply->appendByte(0x00);
					$oReply->append32bitIntLittleEndian(0x0);
					$oReply->append32bitIntLittleEndian(0x0);
					$oReply->append24bitIntLittleEndian(0x0);
					$oReply->appendByte(0x00);
					$oReply->appendByte(0x00);
					$oReply->appendByte(0x00);
				}	
				$this->addReplyToBuffer($oReply->buildEconetpacket());
				return;
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
				}catch(Exception){
					$oReply->DoneOk();
					$oReply->appendByte(0x00);
					$oReply->appendByte(0x00);
					$oReply->appendByte(0x00);
				}
				$this->addReplyToBuffer($oReply->buildEconetpacket());
				return;
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
				}catch(Exception){
					$oReply->DoneOk();
					$oReply->appendByte(0x00);
					$oReply->appendByte(0x00);
					$oReply->appendByte(0x00);
				}
				$this->addReplyToBuffer($oReply->buildEconetpacket());
				return;
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
				}catch(Exception){
					$oReply->DoneOk();
					$oReply->appendByte(0x00);
					$oReply->appendByte(0x00);
					$oReply->appendByte(0x00);
				}
				$this->addReplyToBuffer($oReply->buildEconetpacket());
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
						$oFd = Vfs::getFsHandle($oFsRequest->getSourceNetwork(),$oFsRequest->getSourceStation(),$oFsRequest->getCsd());
						$oReply->appendString(str_pad(substr((string) $oFd->getEconetDirName(),0,10),10,' '));
					}else{
						$oReply->appendString(str_pad(substr((string) $sDir,0,10),10,' '));
					}

					//FS_DIR_ACCESS_PUBLIC
					$oReply->appendByte(0xff);

					//Cyle  always 0 probably should not be 
					$oReply->appendByte(0);

					$this->addReplyToBuffer($oReply->buildEconetpacket());

				}catch(Exception){
					$oReply = $oFsRequest->buildReply();
					$oReply->setError(0x8e,"Bad INFO argument");
					$this->addReplyToBuffer($oReply->buildEconetpacket());
				}
				return;
			case 7:
				//EC_FS_GET_INFO_UID
				break;
			default:
				//Don't do any thing fall to the bad info reply below
				break;
		}
		$oReply = $oFsRequest->buildReply();
		$oReply->setError(0x8e,"Bad INFO argument");
		$this->addReplyToBuffer($oReply->buildEconetpacket());
	}

	public function setInfo(FsRequest $oFsRequest): void
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
		$this->addReplyToBuffer($oReply->buildEconetpacket());
	}

	public function eof(FsRequest $oFsRequest): void
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
		$this->addReplyToBuffer($oReply->buildEconetpacket());
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
		$iStart = $oFsRequest->getByte(2);
		$iCount = $oFsRequest->getByte(3);
		$this->oLogger->debug("Examine Type ".$iArg);
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
					$oReply->appendString(str_pad(substr((string) $oFile->getEconetName(),0,11),11,' '));
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
					$oReply->appendByte(10);
					//Append the file name (limit 10 chars)
					$oReply->appendString(str_pad(substr((string) $oFile->getEconetName(),0,11),11,' '));
				}
	

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
		$this->addReplyToBuffer($oReply->buildEconetpacket());
	}

	public function getArgs(FsRequest $oFsRequest): void
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
				if(is_array($aStat) AND array_key_exists('size',$aStat)){
					$iSize = $aStat['size'];
				}else{
					$iSize = 0;
				}
				$oReply->DoneOk();
				$oReply->append24bitIntLittleEndian($iSize);
				break;
			case 2:
				//EC_FS_ARG_SIZE
				$oReply = $oFsRequest->buildReply();
				$oFd = Vfs::getFsHandle($oFsRequest->getSourceNetwork(),$oFsRequest->getSourceStation(),$iHandle);
				$aStat = $oFd->fsFStat();
				if(is_array($aStat) AND array_key_exists('size',$aStat)){
					$iSize = $aStat['size'];
				}else{
					$iSize = 0;
				}
				$oReply->DoneOk();
				$oReply->append24bitIntLittleEndian($iSize);
				break;
			default:
				$oReply = $oFsRequest->buildReply();
				$oReply->setError(0x8f,"Bad RDARGS argumen");
				$oReply->DoneOk();
				break;
		}
		$this->addReplyToBuffer($oReply->buildEconetpacket());
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
		$oReply->appendString(str_pad(substr((string) config::getValue('vfs_disc_name'),0,16),16,' '));
		try {	
			//csd Leaf name String 10 bytes
			$oCsd = Vfs::getFsHandle($oFsRequest->getSourceNetwork(),$oFsRequest->getSourceStation(),$oFsRequest->getCsd());	
			$oReply->appendString(str_pad(substr((string) $oCsd->getEconetDirName(),0,10),10,' '));

			//lib leaf name String 10 bytes
			$oLib = Vfs::getFsHandle($oFsRequest->getSourceNetwork(),$oFsRequest->getSourceStation(),$oFsRequest->getLib());	
			$oReply->appendString(str_pad(substr((string) $oLib->getEconetDirName(),0,10),10,' '));
		}catch(Exception $oException){
			$oReply = $oFsRequest->buildReply();
			$oReply->setError(0xff,$oException->getMessage());
		}

		$this->addReplyToBuffer($oReply->buildEconetpacket());
	}

	/**
	 * Changes the csd
	 *
	 * This method is invoked by the *DIR command
	*/
	public function changeDirectory(FsRequest $oFsRequest,$sOptions): void
	{
		$oReply = $oFsRequest->buildReply();
		$oUser = Security::getUser($oFsRequest->getSourceNetwork(),$oFsRequest->getSourceStation());

		//Chech the user is logged in
		if(!is_object($oUser)){
			$oReply->setError(0xbf,"Who are you?");
			$this->addReplyToBuffer($oReply->buildEconetpacket());
			return;
		}
		
		if(strlen((string) $sOptions)>0){
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
						$this->addReplyToBuffer($oReply->buildEconetpacket());
						return;
					}
				}
				Vfs::closeFsHandle($oFsRequest->getSourceNetwork(),$oFsRequest->getSourceStation(),$oFsRequest->getCsd());
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
				$oNewCsd = Vfs::createFsHandle($oFsRequest->getSourceNetwork(),$oFsRequest->getSourceStation(),$oUser->getHomedir());
				Vfs::closeFsHandle($oFsRequest->getSourceNetwork(),$oFsRequest->getSourceStation(),$oFsRequest->getCsd());
				$oReply->DirOk();
				$oReply->appendByte($oNewCsd->getID());
				$oUser->setCsd($oNewCsd->getEconetPath());
			}catch(Exception){
				$oReply->setError(0xff,"No such directory.");	
			}
		}
		$this->addReplyToBuffer($oReply->buildEconetpacket());


	}

	/**
	 * Changes the library
	 *
	 * This method is invoked by the *LIB command
	*/
	public function changeLibrary(FsRequest $oFsRequest,?string $sOptions): void
	{
		$oReply = $oFsRequest->buildReply();
		
		if(strlen((string) $sOptions)>0){
			try {
				$oNewLib = Vfs::createFsHandle($oFsRequest->getSourceNetwork(),$oFsRequest->getSourceStation(),$sOptions);	
				if(!$oNewLib->isDir()){
					$this->oLogger->debug("User tryed to change the library to ".$oNewLib->getEconetDirName()." however its not a directory.");
					$oReply->setError(0xbe,"Not a directory");
					$this->addReplyToBuffer($oReply->buildEconetpacket());
					return;
				}
				Vfs::closeFsHandle($oFsRequest->getSourceNetwork(),$oFsRequest->getSourceStation(),$oFsRequest->getLib());
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
		$this->addReplyToBuffer($oReply->buildEconetpacket());


	}

	/**
	 * Creates a new directory
	 *
	 * This method in invoked by the *CDIR command
	*/
	public function createDirectory(FsRequest $oFsRequest,?string $sOptions): void
	{
		$oReply = $oFsRequest->buildReply();
		if(strlen((string) $sOptions)<1){
			$oReply->setError(0xff,"Syntax");
			$this->addReplyToBuffer($oReply->buildEconetpacket());
			return;
		}
		if(strlen((string) $sOptions)>10){
			$oReply->setError(0xff,"Maximum directory name length is 10");
			$this->addReplyToBuffer($oReply->buildEconetpacket());
			return;
		}

		try {
			Vfs::createDirectory($oFsRequest->getSourceNetwork(),$oFsRequest->getSourceStation(),$sOptions);
			$oReply->DoneOk();
		}catch(Exception){
			$oReply->setError(0xff,"Unable to create directory");
		}
		$this->addReplyToBuffer($oReply->buildEconetpacket());
	}

	/**
	 * Deletes a given file
	 *
	 * This method is invoked as either a cli or a file server command depending on the nfs version
	*/
	public function deleteFile(FsRequest $oFsRequest,?string $sOptions): void
	{
		$oReply = $oFsRequest->buildReply();
		if(strlen((string) $sOptions)<1){
			$oReply->setError(0xff,"Syntax");
		}else{
			try{
				Vfs::deleteFile($oFsRequest->getSourceNetwork(),$oFsRequest->getSourceStation(),$sOptions);
				$oReply->DoneOk();
			}catch(Exception){
				$oReply->setError(0xff,"Unable to delete");
			}
		}
		$this->addReplyToBuffer($oReply->buildEconetpacket());
	}

	/**
	 * Renames a given file
	 *
	 * This method is invoked as the cli command *RENAME
	*/ 
	public function renameFile(FsRequest $oFsRequest,?string $sOptions): void
	{
		$oReply = $oFsRequest->buildReply();
		if(strlen((string) $sOptions)<2){
			$oReply->setError(0xff,"Syntax");
		}else{
			try{
				[$sFrom, $sTo] = explode(' ',(string) $sOptions);
				Vfs::moveFile($oFsRequest->getSourceNetwork(),$oFsRequest->getSourceStation(),$sFrom,$sTo);
				$oReply->DoneOk();
			}catch(Exception){
				$oReply->setError(0xff,"No such file");
			}
		}
		$this->addReplyToBuffer($oReply->buildEconetpacket());
		
	}

	/**
	 * Opens a file
	 *
	*/
	public function openFile(FsRequest $oFsRequest): void
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
		}catch(Exception){
			$oReply->setError(0xff,"No such file");
		}
		$this->addReplyToBuffer($oReply->buildEconetpacket());
	}

	/**
	 * Closes a file
	 *
	*/
	public function closeFile(FsRequest $oFsRequest): void
	{
		$iHandle = $oFsRequest->getByte(1);
		Vfs::closeFsHandle($oFsRequest->getSourceNetwork(),$oFsRequest->getSourceStation(),$iHandle);
		$oReply = $oFsRequest->buildReply();
		$oReply->DoneOk();
		$this->addReplyToBuffer($oReply->buildEconetpacket());
	}

	public function getBytes(FsRequest $oFsRequest): void
	{
		//The urd becomes the port to send the data to
		//The urd handle in the request is not the urd when load is called but denotes the port to stream the data to
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
		$this->addReplyToBuffer($oReply->buildEconetpacket());

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
			if(strlen($sBlock)>0){

				$oEconetPacket = new EconetPacket();
				$oEconetPacket->setDestinationNetwork($oFsRequest->getSourceNetwork());
				$oEconetPacket->setDestinationStation($oFsRequest->getSourceStation());
				$oEconetPacket->setFlags(0);
				$oEconetPacket->setPort($iDataPort);
				$oEconetPacket->setData($sBlock);

				$this->addReplyToBuffer($oEconetPacket);
				$this->oServiceDispatcher->sendPackets($this);
			}else{
				//No data to move so send the packet to say we are done, and return
				$oReply2 = $oFsRequest->buildReply();
				$oReply2->DoneOk();
				$_this->addReplyToBuffer($oReply2->buildEconetpacket());
				$oServiceDispatcher->sendPackets($_this);
				return;
			}

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
						$oEconetPacket->setFlags(0);
						$oEconetPacket->setData(str_pad("",$iBytesToRead,"0"));
						$_this->addReplyToBuffer($oEconetPacket);
						$oReply2->appendByte(0x80);
						$oReply2->setFlags(0);
					}else{
						$oReply2->appendByte(0);
					}
					//Number of bytes sent
					$oReply2->append24bitIntLittleEndian($iBytes-$iBytesToRead);
					$_this->addReplyToBuffer($oReply2->buildEconetpacket());
					$oServiceDispatcher->sendPackets($_this);

				}else{
					if($iBytesToRead>256){
						$sBlock = $oFsHandle->read(256);
					}else{
						$sBlock = $oFsHandle->read($iBytesToRead);
					}
					$iBytesToRead = $iBytesToRead-strlen((string) $sBlock);

					$oEconetPacket = new EconetPacket();
					$oEconetPacket->setDestinationNetwork($oFsRequest->getSourceNetwork());
					$oEconetPacket->setDestinationStation($oFsRequest->getSourceStation());
					$oEconetPacket->setFlags(0);
					$oEconetPacket->setPort($iDataPort);
					$oEconetPacket->setData($sBlock);

					$_this->addReplyToBuffer($oEconetPacket);
					$oServiceDispatcher->addAckEvent($oFsRequest->getSourceNetwork(),$oFsRequest->getSourceStation(),function($oAckPacket) use ($_this, $oFsRequest, $oServiceDispatcher, $iBytes, $iBytesToRead, $oFsHandle, $iDataPort, $cAckHandler){
						($cAckHandler)($oAckPacket,$_this, $oFsRequest, $oServiceDispatcher, $iBytes, $iBytesToRead, $oFsHandle, $iDataPort, $cAckHandler);
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

	public function putBytes(FsRequest $oFsRequest): void
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
		$this->addReplyToBuffer($oReply->buildEconetpacket());

		$_this = $this;

		$this->addStream($oFsRequest->getSourceNetwork(),$oFsRequest->getSourceStation(), 
			new StreamIn(
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
					$_this->addReplyToBuffer($oReply2->buildEconetpacket());
					$_this->freeStream($oFsRequest->getSourceNetwork(),$oFsRequest->getSourceStation(),$oStream);
				},
				function($oStream, $sError) use($oFsRequest, $_this) {
					$_this->oLogger->debug("Putbytes waiting for data (".$sError.")");
					$_this->freeStream($oFsRequest->getSourceNetwork(),$oFsRequest->getSourceStation(),$oStream);
					$oFailReply=$oFsRequest->buildReply();
					$oFailReply->setError(0xff,"Timeout");
					$this->addReplyToBuffer($oFailReply->buildEconetpacket());
				},
				60,
				$oFsHandle->getEconetPath(),
				Security::getUser($oFsRequest->getSourceNetwork(),$oFsRequest->getSourceStation())->getUsername()

			)
		);

	}

	public function getByte(FsRequest $oFsRequest): void
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
			$oReply->appendByte($oFsHandle->read(1));
			$oReply->appendByte(0);
		}

		$this->addReplyToBuffer($oReply->buildEconetpacket());
		
	}

	public function putByte(FsRequest $oFsRequest): void
	{
		$iHandle = $oFsRequest->getByte(1);
		$iByte = $oFsRequest->getByte(2);
		$oReply = $oFsRequest->buildReply();

		$this->oLogger->debug("Putbyte handle ".$iHandle." ");
		//Writes a byte to the file handle
		$oFsHandle = Vfs::getFsHandle($oFsRequest->getSourceNetwork(),$oFsRequest->getSourceStation(),$iHandle);	
		$oFsHandle->write($iByte);

		$oReply->DoneOk();
		$this->addReplyToBuffer($oReply->buildEconetpacket());
	}

	/**
	 * Saves data to a file
	 *
	 * This method if invoked by the use saving a basic program 
	*/
	public function saveFile(FsRequest $oFsRequest): void
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
				$iSize,
				function($oStream,$oPacket) use ($oFsRequest, $iAckPort, $_this) {
					$oReply = $oFsRequest->buildReply();
					$oReply->DoneOk();
					$oReplyEconetPacket = $oReply->buildEconetpacket();
					$oReplyEconetPacket->setPort($iAckPort);
					$_this->addReplyToBuffer($oReplyEconetPacket);
					$_this->oLogger->debug("Replay sent for block of ".strlen((string) $oPacket->getData()));
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
					$_this->addReplyToBuffer($oReply2->buildEconetpacket());
					$_this->freeStream($oFsRequest->getSourceNetwork(),$oFsRequest->getSourceStation(),$oStream);
				},
				function($oStream, $sError) use($oFsRequest, $_this) {
					$_this->oLogger->debug("Filesave failed (".$sError.")");
					$_this->freeStream($oFsRequest->getSourceNetwork(),$oFsRequest->getSourceStation(),$oStream);
					$oFailReply=$oFsRequest->buildReply();
					$oFailReply->setError(0xff,"Timeout");
					$this->addReplyToBuffer($oFailReply->buildEconetpacket());
				},
				60,
				$sPath,
				Security::getUser($oFsRequest->getSourceNetwork(),$oFsRequest->getSourceStation())->getUsername()
				
			)
		);
		
	}

	/**
	 * Loads data from a file
	 *
	 * This methos is invoked by the use of LOAD "filename"
	*/
	public function loadFile(FsRequest $oFsRequest): void
	{
		//The urd handle in the request is not the urd when load is called but denotes the port to stream the data to
		$iDataPort = $oFsRequest->getUrd();
		$sPath = $oFsRequest->getString(1);

		$oReply = $oFsRequest->buildReply();
		
		try {
			$sFileData = Vfs::getFile($oFsRequest->getSourceNetwork(),$oFsRequest->getSourceStation(),$sPath);
			$oMeta = Vfs::getMeta($oFsRequest->getSourceNetwork(),$oFsRequest->getSourceStation(),$sPath);
		}catch(Exception){
			$oReply->setError(0x99,"No such file");
			$this->addReplyToBuffer($oReply->buildEconetpacket());
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

		$oServiceDispatcher = $this->oServiceDispatcher;
		$_this = $this;

		$this->oServiceDispatcher->addAckEvent($oFsRequest->getSourceNetwork(),$oFsRequest->getSourceStation(),function() use ($_this, $sFileData, $oFsRequest, $iDataPort, $oServiceDispatcher){
			//Build a 256 byte block
			$sBlock = substr((string) $sFileData,0,256);
			//Remove 256 byte from the string
			$sFileData=substr((string) $sFileData,256);

			$oEconetPacket = new EconetPacket();
			$oEconetPacket->setDestinationNetwork($oFsRequest->getSourceNetwork());
			$oEconetPacket->setDestinationStation($oFsRequest->getSourceStation());
			$oEconetPacket->setPort($iDataPort);
			$oEconetPacket->setFlags(0);
			$oEconetPacket->setData($sBlock);

			$_this->addReplyToBuffer($oEconetPacket);
			$_this->oServiceDispatcher->sendPackets($_this);

			$cAckHandler = function($oAckPacket, $_this, $oFsRequest, $oServiceDispatcher,  $sFileData, $iDataPort, &$cAckHandler){
				if(strlen((string) $sFileData)>0){
					//Build a 256 byte block
					$sBlock = substr((string) $sFileData,0,256);
					//Remove 256 byte from the string
					$sFileData=substr((string) $sFileData,256);

					$oEconetPacket = new EconetPacket();
					$oEconetPacket->setDestinationNetwork($oFsRequest->getSourceNetwork());
					$oEconetPacket->setDestinationStation($oFsRequest->getSourceStation());
					$oEconetPacket->setPort($iDataPort);
					$oEconetPacket->setFlags(0);
					$oEconetPacket->setData($sBlock);

					$_this->addReplyToBuffer($oEconetPacket);
					$oServiceDispatcher->addAckEvent($oFsRequest->getSourceNetwork(),$oFsRequest->getSourceStation(),function($oAckPacket) use ($_this, $oFsRequest, $oServiceDispatcher, $sFileData, $iDataPort, $cAckHandler){
						($cAckHandler)($oAckPacket, $_this, $oFsRequest, $oServiceDispatcher, $sFileData, $iDataPort, $cAckHandler);
					});
					$oServiceDispatcher->sendPackets($_this);
				}else{
					$oReply2 = $oFsRequest->buildReply();
					$oReply2->DoneOk();
					$_this->addReplyToBuffer($oReply2->buildEconetpacket());
					$oServiceDispatcher->sendPackets($_this);
				}

			};

			$oServiceDispatcher->addAckEvent($oFsRequest->getSourceNetwork(),$oFsRequest->getSourceStation(),function($oAckPacket) use (&$cAckHandler, $_this, $oFsRequest, $oServiceDispatcher, $sFileData, $iDataPort) {
				($cAckHandler)($oAckPacket, $_this, $oFsRequest, $oServiceDispatcher, $sFileData, $iDataPort, $cAckHandler) ;
			});
		});


	}

	/**
	 * Set the current users password
	 *
	 * This method is invoked by the *PASS command
	*/
	public function setPassword(FsRequest $oFsRequest,string $sOptions): void
	{
		$aOptions = explode(' ',$sOptions);
		$oReply = $oFsRequest->buildReply();
		if(count($aOptions)!=2){
			$oReply->setError(0xff,"Syntax");
		}else{
			//Filter out the string added by the use of *pass :
			if(substr_count($aOptions[0],"\r")>0){
				[$aOptions[0]] = explode("\r",$aOptions[0]);
			}
			if(substr_count($aOptions[1],"\r")>0){
				[$aOptions[1]] = explode("\r",$aOptions[1]);
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
		$this->addReplyToBuffer($oReply->buildEconetpacket());
	}

	/**
	 * Creates a new user (*NEWUSER)
	 *
	*/
	public function createUser(FsRequest $oFsRequest,$sOptions): void
	{
		$oReply = $oFsRequest->buildReply();
		if(strlen((string) $sOptions)<1){
			$oReply->setError(0xff,"Syntax");
		}else{
			$aOptions = explode(' ',(string) $sOptions);
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
		$this->addReplyToBuffer($oReply->buildEconetpacket());
	}

	/**
	 * Removes a user (*REMUSER)
	 *
	*/
	public function removeUser(FsRequest $oFsRequest,?string $sOptions): void
	{
		$oReply = $oFsRequest->buildReply();
		if(strlen((string) $sOptions)<1 OR !ctype_alnum((string) $sOptions)){
			$oReply->setError(0xff,"Syntax");
		}else{
			try {
				if(Security::removeUser($oFsRequest->getSourceNetwork(),$oFsRequest->getSourceStation(),$sOptions)){
					$oReply->DoneOk();
				}else{
					$oReply->setError(0xff,"No such user");
				}
			}catch(Exception){
				$oReply->setError(0xff,"You do not have admin rights");
			}
		}
		$this->addReplyToBuffer($oReply->buildEconetpacket());
	}

	/**
	 * Set the privalage of a given user
	 *
	*/
	public function privUser(FsRequest $oFsRequest, ?string $sOptions): void
	{
		$aOptions = explode(' ',(string) $sOptions);
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
		$this->addReplyToBuffer($oReply->buildEconetpacket());
	}

	/**
	 * Implements the commnad sdisc
	 *
	*/
	public function sDisc(FsRequest $oFsRequest,$sOptions): void
	{
		//As we can only ever have one disc this command has rather little todo
		$oReply = $oFsRequest->buildReply();
		$oReply->DoneOk();
		$oReply->appendByte($oFsRequest->getUrd());
		$oReply->appendByte($oFsRequest->getCsd());
		$oReply->appendByte($oFsRequest->getLib());
		$this->addReplyToBuffer($oReply->buildEconetpacket());
	}

	public function chroot(FsRequest $oFsRequest,string $sOptions): void
	{
		$oReply = $oFsRequest->buildReply();
		$oUser = security::getUser($oFsRequest->getSourceNetwork(),$oFsRequest->getSourceStation());
		try {
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
				$this->addReplyToBuffer($oReply->buildEconetpacket());
				return;
			}
		}catch(Exception $oException){
			$this->oLogger->debug("User tryed to chroot to ".$sOptions." however that is not a valid path.");
			$oReply->setError(0xbe,"Invalid path");
			$this->addReplyToBuffer($oReply->buildEconetpacket());
			return;
		}

		$this->oLogger->debug("User ".$oUser->getUsername()." chroot to ".$oNewRootDir->getEconetPath());
		$oUser->setRoot($oNewRootDir->getEconetPath());
		
		//Vfs::closeFsHandle($oFsRequest->getSourceNetwork(),$oFsRequest->getSourceStation(),$oFsRequest->getCsd());
		Vfs::closeFsHandle($oFsRequest->getSourceNetwork(),$oFsRequest->getSourceStation(),$oNewRootDir->getId());
		$oNewCsd = Vfs::createFsHandle($oFsRequest->getSourceNetwork(),$oFsRequest->getSourceStation(),'$');
		$oUser->setCsd('$');
		Vfs::replaceFsHandle($oFsRequest->getSourceNetwork(),$oFsRequest->getSourceStation(),$oFsRequest->getCsd(),$oNewCsd->getId());
		$oReply->DirOk();
		$oReply->appendByte($oNewCsd->getID());
		$oUser->setCsd($oNewCsd->getEconetPath());
		$this->addReplyToBuffer($oReply->buildEconetpacket());
			
	}

	/**
	 * Turns off the chroot feature reverting back to the true root of the filestore 
	 *
	*/
	public function chrootoff(FsRequest $oFsRequest,$sOptions): void
	{
		$oReply = $oFsRequest->buildReply();
		$oUser = security::getUser($oFsRequest->getSourceNetwork(),$oFsRequest->getSourceStation());
		$oCsd = Vfs::getFsHandle($oFsRequest->getSourceNetwork(),$oFsRequest->getSourceStation(),$oFsRequest->getCsd());
		$sNewPath = str_replace('$',$oUser->getRoot(),(string) $oCsd->getEconetDirName());
		$oUser->setRoot('$');
		Vfs::closeFsHandle($oFsRequest->getSourceNetwork(),$oFsRequest->getSourceStation(),$oFsRequest->getCsd());
		$oNewCsd = Vfs::createFsHandle($oFsRequest->getSourceNetwork(),$oFsRequest->getSourceStation(),$sNewPath);
		$oReply->DirOk();
		$oReply->appendByte($oNewCsd->getID());
		$oUser->setCsd($oNewCsd->getEconetPath());
		$this->addReplyToBuffer($oReply->buildEconetpacket());
	}

	/**
	 * Lists the users logged in
	 *
	*/
	public function usersOnline(FsRequest $oFsRequest): void
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
				//@phpstan-ignore-next-line
				if($iStart <= $i AND $i <= ($iStart+$iCount)){
					$oUser = $aData['user'];
					$oReply->appendByte($iNetwork);
					$oReply->appendByte($iStation);
					$oReply->appendString(substr((string) $oUser->getUsername(),0,10));
					$oReply->appendByte(0x0d);
					if($oUser->isAdmin()){
						$oReply->appendByte(1);
					}else{
						$oReply->appendByte(0);
					}
				}
				//@phpstan-ignore-next-line
				if($i>($iStart+$iCount)){
					$this->addReplyToBuffer($oReply->buildEconetpacket());
					return;
				}
			}
		}
		$this->addReplyToBuffer($oReply->buildEconetpacket());
	}

	/**
	 * Get the network and station number for a given user
	*/
	public function getUsersStation(FsRequest $oFsRequest): void
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
		$this->addReplyToBuffer($oReply->buildEconetpacket());	
	}

	/**
	 * Gets a list of discs
	*/
	public function getDiscs(FsRequest $oFsRequest): void
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
			$oReply->appendString(str_pad(substr((string) config::getValue('vfs_disc_name'),0,16),16,' '));
		}else{
			//Indicate that no more discs are present
			$oReply->appendByte(0);
		}
		$this->addReplyToBuffer($oReply->buildEconetpacket());	
	}

	/**
	 * Gets the free space for a disc
	 * 
	 * The answer is fake a BBCs can't handle the same sizes as Linux
	*/
	public function getDiscFree(FsRequest $oFsRequest): void
	{
		$sDisc = $oFsRequest->getString(1);
		$oReply = $oFsRequest->buildReply();

		$oReply->DoneOk();
		$oReply->append32bitIntLittleEndian(config::getValue('vfs_default_disc_free'));
		$oReply->append32bitIntLittleEndian(config::getValue('vfs_default_disc_size'));
		$this->addReplyToBuffer($oReply->buildEconetpacket());
	}

	/**
	 * Gets the version of the server
	 *
	*/
	public function getVersion(FsRequest $oFsRequest): void
	{
		$oReply = $oFsRequest->buildReply();
		$oReply->DoneOk();
		$oReply->appendString("aunfs_srv ".config::getValue('version'));
		$this->addReplyToBuffer($oReply->buildEconetpacket());
	}

	/**
	 * Gets the time
	*/
	public function getTime(FsRequest $oFsRequest): void
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
		$oReply->appendByte(ltrim(date('i',$iTime),"0"));

		$this->addReplyToBuffer($oReply->buildEconetpacket());
	}

	/**
	 * Creates a file
	 *
	*/
	public function createFile(FsRequest $oFsRequest): void
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
		
		$this->addReplyToBuffer($oReply->buildEconetpacket());
	}

	/**
	 * Gets the disk space free value for a user
	 *
	 * Given we can't map the scale of Linux storage sizes to bbc storage sizes, the amount of free space is just a constant.
	 * Maybe at some point this could be mapped to a unix users quota if the system has quotas setup
	*/
	public function getUserDiscFree(FsRequest $oFsRequest): void
	{
		//Username
		$sUsername = $oFsRequest->getString(1);

		$oReply = $oFsRequest->buildReply();
		$oReply->DoneOk();
		$oReply->append24bitIntLittleEndian(config::getValue('vfs_default_disc_free'));

		$this->addReplyToBuffer($oReply->buildEconetpacket());
	}

	public function setOpt(FsRequest $oFsRequest): void
	{
		//Opt
		Security::setOpt($oFsRequest->getSourceNetwork(),$oFsRequest->getSourceStation(),(string) $oFsRequest->getByte(1));

		$oReply = $oFsRequest->buildReply();
		$oReply->DoneOk();
		$oReply->append24bitIntLittleEndian(config::getValue('vfs_default_disc_free'));

		$this->addReplyToBuffer($oReply->buildEconetpacket());

	}

	public function whoAmI(FsRequest $oFsRequest): void
	{
		$oReply = $oFsRequest->buildReply();
		$oUser = Security::getUser($oFsRequest->getSourceNetwork(),$oFsRequest->getSourceStation());
		if(is_object($oUser)){
			$oReply->DoneOk();
			$oReply->appendString($oUser->getUsername());
			$oReply->appendByte(0x0d);
		}else{
			$oReply->setError(0xbf,"Who are you?");
		}
		$this->addReplyToBuffer($oReply->buildEconetpacket());	
	}

	public function getJobs(): array
	{
		return [];
	}
}
