<?php
/**
 * This file contains the fileserver class
 *
 * @author John Brown <john@home-lan.co.uk>
 * @package core
*/
namespace HomeLan\FileStore\Services\Provider; 

use HomeLan\FileStore\Encapsulation\EncapsulationInterface;
use HomeLan\FileStore\Services\Provider\FileServer\Admin;
use HomeLan\FileStore\Services\Provider\FileServer\Catalog;
use HomeLan\FileStore\Services\Provider\FileServer\Cli;
use HomeLan\FileStore\Services\Provider\FileServer\FileHandles;
use HomeLan\FileStore\Services\Provider\FileServer\UserAdmin;
use HomeLan\FileStore\Services\Provider\PrintServer\PrinterRegistry;
use HomeLan\FileStore\Services\ProviderInterface;
use HomeLan\FileStore\Services\ServiceDispatcher;
use HomeLan\FileStore\Services\StreamIn;
use HomeLan\FileStore\Vfs\Vfs;
use HomeLan\FileStore\Vfs\Exception as VfsException;
use HomeLan\FileStore\Vfs\FilePath;
use HomeLan\FileStore\Vfs\DirectoryEntry;
use HomeLan\FileStore\Vfs\FileDescriptor;
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
 *
 * @phpstan-import-type SecuritySession from Security
*/
class FileServer implements ProviderInterface{

	protected ?ServiceDispatcher $oServiceDispatcher = NULL;

	/** @var array<int,FsReply|EconetPacket> */
	protected array $aReplyBuffer = [];

	protected \Psr\Log\LoggerInterface $oLogger;

	/** @var array<int,array<int,StreamIn>> */
	protected array $aStreamsIn = [];

	protected Cli $oCli;

	protected UserAdmin $oUserAdmin;

	protected FileHandles $oFileHandles;

	protected Catalog $oCatalog;

	/**
	 * Initializes the service
	 *
	*/
	public function __construct(\Psr\Log\LoggerInterface $oLogger)
	{
		$this->oLogger = $oLogger;
		$this->oCli = new Cli($this);
		$this->oUserAdmin = new UserAdmin($this);
		$this->oFileHandles = new FileHandles($this);
		$this->oCatalog = new Catalog($this);
		$this->vfsInit();
	}

	/**
	 * Gets the service dispatcher this provider is registered with, if any.
	 * Used by Cli::cliLoad() to schedule ack-driven packet streaming.
	*/
	public function getServiceDispatcher(): ?ServiceDispatcher
	{
		return $this->oServiceDispatcher;
	}

	public function getLogger(): \Psr\Log\LoggerInterface
	{
		return $this->oLogger;
	}

	/**
	 * Gets the handler for password/quota/privilege/boot-option account settings.
	 * Used by Cli::runCli() for the *PASS/*PRIV/*OPT commands.
	*/
	public function getUserAdmin(): UserAdmin
	{
		return $this->oUserAdmin;
	}

	/**
	 * Gets the handler for path-based catalog/metadata operations (directory
	 * listings, file/directory info, create/delete/rename by path).
	 * Used by Cli::runCli() for the *CAT/*CDIR/*DELETE/*DIR/*INFO/*LIB/*RENAME commands.
	*/
	public function getCatalog(): Catalog
	{
		return $this->oCatalog;
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
	 * @return array<int,int>
	*/
	public function getServicePorts(): array
	{
		return [0x99, config::getValueAsInt('econet_data_stream_port')];
	}

	/** 
	 * Filestore messages can come in via broadcast (e.g. sdiscs which is uses to find servers)
	 *
	*/
	public function broadcastPacketIn(EconetPacket $oPacket): void
	{
		if($oPacket->getSourceNetwork() === null || $oPacket->getSourceStation() === null){
			$this->oLogger->warning("FileServer: dropping broadcast packet with no resolvable source network/station");
			return;
		}
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
		if($oPacket->getPort()==config::getValueAsInt('econet_data_stream_port')){
			$this->streamPacketIn($oPacket);
		}else{
			if($oPacket->getSourceNetwork() === null || $oPacket->getSourceStation() === null){
				$this->oLogger->warning("FileServer: dropping packet with no resolvable source network/station");
				return;
			}
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
	 *
	 * @return array<int,EconetPacket>
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
	public function addStream(int $iNetwork, int $iStation,StreamIn $oStream): void
	{
		if(!array_key_exists($iNetwork,$this->aStreamsIn)){
			$this->aStreamsIn[$iNetwork]=[];
		}
		$this->aStreamsIn[$iNetwork][$iStation]=$oStream;
	}

	/**
	 * Frees an existing io stream
	*/
	public function freeStream(int $iNetwork, int $iStation): void
	{
		unset($this->aStreamsIn[$iNetwork][$iStation]);
	}

	/**
	 * @return array<int,array{network:int,station:int,stream:StreamIn}>
	*/
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
				$oStream->checkTimeout();
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
		$this->secUpdateIdleTimer($oFsRequest->getSourceNetwork(),$oFsRequest->getSourceStation());

		//Function where you dont always need to be logged in
		switch($sFunction){
			case 'EC_FS_FUNC_CLI':
				$this->oCli->cliDecode($oFsRequest);
				return;

		}

		//Function where the user must be logged in
		if(!$this->secIsLoggedIn($oFsRequest->getSourceNetwork(),$oFsRequest->getSourceStation())){
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
				$this->oCatalog->examine($oFsRequest);
				break;
			case 'EC_FS_FUNC_CAT_HEADER':
				$this->oCatalog->catHeader($oFsRequest);
				break;
			case 'EC_FS_FUNC_LOAD_COMMAND':
				$this->loadCommand($oFsRequest);
				break;
			case 'EC_FS_FUNC_OPEN':
				$this->oFileHandles->openFile($oFsRequest);
				break;
			case 'EC_FS_FUNC_CLOSE':
				$this->oFileHandles->closeFile($oFsRequest);
				break;
			case 'EC_FS_FUNC_GETBYTE':
				$this->oFileHandles->getByte($oFsRequest);
				break;
			case 'EC_FS_FUNC_PUTBYTE':
				$this->oFileHandles->putByte($oFsRequest);
				break;
			case 'EC_FS_FUNC_GETBYTES':
				$this->oFileHandles->getBytes($oFsRequest);
				break;
			case 'EC_FS_FUNC_PUTBYTES':
				$this->oFileHandles->putBytes($oFsRequest);
				break;
			case 'EC_FS_FUNC_GET_ARGS':
				$this->oFileHandles->getArgs($oFsRequest);
				break;
			case 'EC_FS_FUNC_SET_ARGS':
				$this->oFileHandles->setArgs($oFsRequest);
				break;
			case 'EC_FS_FUNC_GET_EOF':
				$this->oFileHandles->eof($oFsRequest);
				break;
			case 'EC_FS_FUNC_GET_DISCS':
				$this->getDiscs($oFsRequest);
				break;
			case 'EC_FS_FUNC_GET_INFO':
				$this->oCatalog->getInfo($oFsRequest);
				break;
			case 'EC_FS_FUNC_SET_INFO':
				$this->oCatalog->setInfo($oFsRequest);
				break;
			case 'EC_FS_FUNC_GET_UENV':
				$this->oCatalog->getUenv($oFsRequest);
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
				$this->oUserAdmin->setOpt($oFsRequest);
				break;
			case 'EC_FS_FUNC_DELETE':
				$sFile = $oFsRequest->getString(1);
				$this->oCatalog->deleteFile($oFsRequest,$sFile);
				break;
			case 'EC_FS_FUNC_GET_VERSION':
				$this->getVersion($oFsRequest);
				break;
			case 'EC_FS_FUNC_GET_DISC_FREE':
				$this->getDiscFree($oFsRequest);
				break;
			case 'EC_FS_FUNC_CDIRN':
				$sDir = $oFsRequest->getString(2);
				$this->oCatalog->createDirectory($oFsRequest,$sDir);
				break;
			case 'EC_FS_FUNC_RENAME':
				$this->oFileHandles->renameFileByHandle($oFsRequest);
				break;
			case 'EC_FS_FUNC_CREATE':
				$this->createFile($oFsRequest);
				break;
			case 'EC_FS_FUNC_GET_USER_FREE':
				$this->oUserAdmin->getUserDiscFree($oFsRequest);
				break;
			case 'EC_FS_FUNC_SET_USER_FREE':
				$this->oUserAdmin->setUserDiscFree($oFsRequest);
				break;
			case 'EC_FS_FUNC_WHO_AM_I':
				$this->whoAmI($oFsRequest);
				break;
			case 'EC_FS_FUNC_USERS_EXT':
				$this->usersExt($oFsRequest);
				break;
			case 'EC_FS_FUNC_USER_INFO_EXT':
				$this->userInfoExt($oFsRequest);
				break;
			case 'EC_FS_FUNC_COPY_DATA':
				$this->oFileHandles->copyData($oFsRequest);
				break;
			default:
				$this->oLogger->debug("Un-handled fs function ".$sFunction);
				$oReply = $oFsRequest->buildReply();
				$oReply->setError(0x8f, "Not implemented");
				$this->addReplyToBuffer($oReply->buildEconetpacket());
				break;

		}
	}

	/**
	 * Reads which file handle is stored in the requests csd and lib byte, and updates the users csd and lib 
	*/
	public function updateCsdLib(FsRequest $oFsRequest): void
	{
		$oUser = $this->secGetUser($oFsRequest->getSourceNetwork(),$oFsRequest->getSourceStation());
		if(!is_object($oUser)){
			return;
		}
		try {
			if(!is_null($oFsRequest->getCsd())){
				$oCsd = $this->vfsGetFsHandle($oFsRequest->getSourceNetwork(),$oFsRequest->getSourceStation(),$oFsRequest->getCsd());
				$oUser->setCsd($oCsd->getEconetPath());
			}
		}catch(Exception $oException){
			$this->oLogger->debug("fileserver: Unable to set users csd to handle ".$oFsRequest->getCsd()." (".$oException->getMessage().")");
		}

		try {
			if(!is_null($oFsRequest->getLib())){
				$oLib = $this->vfsGetFsHandle($oFsRequest->getSourceNetwork(),$oFsRequest->getSourceStation(),$oFsRequest->getLib());
				$oUser->setLib($oLib->getEconetPath());
			}
		}catch(Exception $oException){
			$this->oLogger->debug("fileserver: Unable to set users lib to handle ".$oFsRequest->getLib()." (".$oException->getMessage().")");
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
		$sOptions ??= '';
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
		if($this->secLogin($oFsRequest->getSourceNetwork(),$oFsRequest->getSourceStation(),$sUser,$sPass)){
			//Login success

			//Create the handles for the csd urd and lib
			$oUser = $this->secGetUser($oFsRequest->getSourceNetwork(),$oFsRequest->getSourceStation());
			if($oUser === null){
				$this->oLogger->error("fileserver: Login succeeded but no user session found for ".$oFsRequest->getSourceNetwork().".".$oFsRequest->getSourceStation());
				$oReply = $oFsRequest->buildReply();
				$oReply->setError(0xbb,"Incorrect password");
				$this->addReplyToBuffer($oReply->buildEconetpacket());
				return;
			}
			try {
				$oUrd = $this->vfsCreateFsHandle($oFsRequest->getSourceNetwork(),$oFsRequest->getSourceStation(),$oUser->getHomedirPath(),bDirectory: true);
				$oCsd = $this->vfsCreateFsHandle($oFsRequest->getSourceNetwork(),$oFsRequest->getSourceStation(),$oUser->getHomedirPath(),bDirectory: true);
			}catch(Exception){
				$this->oLogger->info("fileserver: Login unable to open homedirectory for user ".$oUser->getUsername());
				$oUrd = $this->vfsCreateFsHandle($oFsRequest->getSourceNetwork(),$oFsRequest->getSourceStation(),'$',bDirectory: true);
				$oCsd = $this->vfsCreateFsHandle($oFsRequest->getSourceNetwork(),$oFsRequest->getSourceStation(),'$',bDirectory: true);
			}
			try {
				$oLib = $this->vfsCreateFsHandle($oFsRequest->getSourceNetwork(),$oFsRequest->getSourceStation(),$oUser->getLib() ?? '',bDirectory: true);
			}catch(Exception){
				$this->oLogger->info("fileserver: Login unable to open library dir setting library to $ for user ".$oUser->getUsername());
				$oLib = $this->vfsCreateFsHandle($oFsRequest->getSourceNetwork(),$oFsRequest->getSourceStation(),'',bDirectory: true);
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
			$this->secLogout($oFsRequest->getSourceNetwork(),$oFsRequest->getSourceStation());
			$oReply = $oFsRequest->buildReply();	
			$oReply->DoneOk();
		}catch(Exception){
			$oReply = $oFsRequest->buildReply();
			$oReply->setError(0xbf,"Who are you?");
		}
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
		if($iAckPort === null){
			$oReply = $oFsRequest->buildReply();
			$oReply->setError(0xff,"Syntax ?");
			$this->addReplyToBuffer($oReply->buildEconetpacket());
			return;
		}

		$oUser = $this->secGetUser($oFsRequest->getSourceNetwork(),$oFsRequest->getSourceStation());
		if($oUser === null){
			$oReply = $oFsRequest->buildReply();
			$oReply->setError(0xbf,"Who are you?");
			$this->addReplyToBuffer($oReply->buildEconetpacket());
			return;
		}

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
		$oReply->appendByte(config::getValueAsInt('econet_data_stream_port'));
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
				function(StreamIn $oStream, EconetPacket $oPacket) use ($oFsRequest, $iAckPort, $_this) {
					$oReply = $oFsRequest->buildReply();
					$oReply->DoneOk();
					$oReplyEconetPacket = $oReply->buildEconetpacket();
					$oReplyEconetPacket->setPort($iAckPort);
					$_this->addReplyToBuffer($oReplyEconetPacket);
					$_this->oLogger->debug("Replay sent for block of ".strlen((string) $oPacket->getData()));
				},
				function(StreamIn $oStream, string $sData) use ($oFsRequest, $sPath, $iLoad, $iExec, $_this){
					$this->vfsSaveFile($oFsRequest->getSourceNetwork(),$oFsRequest->getSourceStation(),$sPath,$sData,$iLoad,$iExec);

					//File is saved
					$iAccess = 0;
					try {
						$oSavedMeta = $this->vfsGetMeta($oFsRequest->getSourceNetwork(),$oFsRequest->getSourceStation(),$sPath);
						$iAccess = $oSavedMeta->getAccess();
					}catch(Exception){}
					$oReply2 = $oFsRequest->buildReply();
					$oReply2->DoneOk();
					$oReply2->appendByte($iAccess);

					//Add current date
					$iDay = (int) date('j',time());
					$oReply2->appendByte($iDay);

					//The last byte is month and year, first 4 bits year, last 4 bits month
					$iYear= (int) date('y',time());
					$iYear <<= 4;
					$iYear = $iYear + (int) date('n',time());
					$oReply2->appendByte($iYear);
					$_this->addReplyToBuffer($oReply2->buildEconetpacket());
					$_this->freeStream($oFsRequest->getSourceNetwork(),$oFsRequest->getSourceStation());
				},
				function(string $sError) use($oFsRequest, $_this) {
					$_this->oLogger->debug("Filesave failed (".$sError.")");
					$_this->freeStream($oFsRequest->getSourceNetwork(),$oFsRequest->getSourceStation());
					$oFailReply=$oFsRequest->buildReply();
					$oFailReply->setError(0xff,"Timeout");
					$this->addReplyToBuffer($oFailReply->buildEconetpacket());
				},
				60,
				$sPath,
				$oUser->getUsername() ?? ''

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

		if($iDataPort === null){
			$oReply->setError(0xff,"Syntax ?");
			$this->addReplyToBuffer($oReply->buildEconetpacket());
			return;
		}

		try {
			$sFileData = $this->vfsGetFile($oFsRequest->getSourceNetwork(),$oFsRequest->getSourceStation(),$sPath);
			$oMeta = $this->vfsGetMeta($oFsRequest->getSourceNetwork(),$oFsRequest->getSourceStation(),$sPath);
		}catch(Exception){
			$oReply->setError(0x99,"No such file");
			$this->addReplyToBuffer($oReply->buildEconetpacket());
			return;			
		}

		//Send the first reply 
		$oReply->DoneOk();
		$oReply->append32bitIntLittleEndian($oMeta->getLoadAddr() ?? 0);
		$oReply->append32bitIntLittleEndian($oMeta->getExecAddr() ?? 0);
		$oReply->append24bitIntLittleEndian($oMeta->getSize());
		$oReply->appendByte($oMeta->getAccess());
		//Add ctime 2 bytes day,year+month
		$oReply->appendRaw($oMeta->getCTime());
		$oReplyEconetPacket = $oReply->buildEconetpacket();
		$this->addReplyToBuffer($oReplyEconetPacket);	

		$oServiceDispatcher = $this->oServiceDispatcher;
		$_this = $this;

		if($oServiceDispatcher === null){
			$this->oLogger->error("FileServer: no ServiceDispatcher registered — cannot stream file data");
			return;
		}

		$oServiceDispatcher->addAckEvent($oFsRequest->getSourceNetwork(),$oFsRequest->getSourceStation(),$oReplyEconetPacket->getSequence(),function() use ($_this, $sFileData, $oFsRequest, $iDataPort, $oServiceDispatcher){
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
			$iSentSeq = $oEconetPacket->getSequence();
			$oServiceDispatcher->sendPackets($_this);

			$cAckHandler = function(EncapsulationInterface $oAckPacket, FileServer $_this, FsRequest $oFsRequest, ServiceDispatcher $oServiceDispatcher, string $sFileData, int $iDataPort, \Closure &$cAckHandler): void {
				if(strlen($sFileData)>0){
					//Build a 256 byte block
					$sBlock = substr($sFileData,0,256);
					//Remove 256 byte from the string
					$sFileData=substr($sFileData,256);

					$oEconetPacket = new EconetPacket();
					$oEconetPacket->setDestinationNetwork($oFsRequest->getSourceNetwork());
					$oEconetPacket->setDestinationStation($oFsRequest->getSourceStation());
					$oEconetPacket->setPort($iDataPort);
					$oEconetPacket->setFlags(0);
					$oEconetPacket->setData($sBlock);

					$_this->addReplyToBuffer($oEconetPacket);
					$oServiceDispatcher->addAckEvent($oFsRequest->getSourceNetwork(),$oFsRequest->getSourceStation(),$oEconetPacket->getSequence(),function(EncapsulationInterface $oAckPacket) use ($_this, $oFsRequest, $oServiceDispatcher, $sFileData, $iDataPort, $cAckHandler){
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

			$oServiceDispatcher->addAckEvent($oFsRequest->getSourceNetwork(),$oFsRequest->getSourceStation(),$iSentSeq,function(EncapsulationInterface $oAckPacket) use ($cAckHandler, $_this, $oFsRequest, $oServiceDispatcher, $sFileData, $iDataPort) {
				($cAckHandler)($oAckPacket, $_this, $oFsRequest, $oServiceDispatcher, $sFileData, $iDataPort, $cAckHandler) ;
			});
		});


	}

	/**
	 * Creates a new user (*NEWUSER)
	 *
	*/
	public function createUser(FsRequest $oFsRequest,?string $sOptions): void
	{
		$oReply = $oFsRequest->buildReply();
		$oMyUser = $this->secGetUser($oFsRequest->getSourceNetwork(),$oFsRequest->getSourceStation());
		if(!is_object($oMyUser) || !$oMyUser->isAdmin()){
			$oReply->setError(0xff,"Only user with priv S can use *NEWUSER");
			$this->addReplyToBuffer($oReply->buildEconetpacket());
			return;
		}
		if(strlen((string) $sOptions)<1){
			$oReply->setError(0xff,"Syntax");
		}else{
			$aOptions = explode(' ',(string) $sOptions);
			if(strlen($aOptions[0])>=3 AND strlen($aOptions[0])<11 AND ctype_upper($aOptions[0]) AND ctype_alpha($aOptions[0])){
				$oUser = new User();
				$oUser->setUsername($aOptions[0]);
				if(config::getValueAsString('vfs_home_dir_path')!==''){
					$oUser->setHomedir(config::getValueAsString('vfs_home_dir_path').'.'.$aOptions[0]);
					try {
						$this->vfsCreateDirectory($oFsRequest->getSourceNetwork(),$oFsRequest->getSourceStation(),config::getValueAsString('vfs_home_dir_path').'.'.$aOptions[0]);
					}catch(Exception){
					}
				}else{
					$oUser->setHomedir('$');
				}
				$oUser->setUnixUid(config::getValueAsInt('security_default_unix_uid'));
				$oUser->setPriv('U');
				try{
					$this->secCreateUser($oFsRequest->getSourceNetwork(),$oFsRequest->getSourceStation(),$oUser);
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
		$sOptions ??= '';
		$oReply = $oFsRequest->buildReply();
		if(strlen((string) $sOptions)<1 OR !ctype_alnum((string) $sOptions)){
			$oReply->setError(0xff,"Syntax");
		}else{
			try {
				if($this->secRemoveUser($oFsRequest->getSourceNetwork(),$oFsRequest->getSourceStation(),$sOptions)){
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
	 * Implements the commnad sdisc
	 *
	*/
	public function sDisc(FsRequest $oFsRequest,?string $sOptions): void
	{
		//As we can only ever have one disc this command has rather little todo
		$oReply = $oFsRequest->buildReply();
		$oReply->DoneOk();
		$oReply->appendByte($oFsRequest->getUrd() ?? 0);
		$oReply->appendByte($oFsRequest->getCsd() ?? 0);
		$oReply->appendByte($oFsRequest->getLib() ?? 0);
		$this->addReplyToBuffer($oReply->buildEconetpacket());
	}

	public function chroot(FsRequest $oFsRequest,string $sOptions): void
	{
		$oReply = $oFsRequest->buildReply();
		$oUser = $this->secGetUser($oFsRequest->getSourceNetwork(),$oFsRequest->getSourceStation());
		if($oUser === null){
			$oReply->setError(0xbf,"Who are you?");
			$this->addReplyToBuffer($oReply->buildEconetpacket());
			return;
		}
		try {
			if($sOptions=="^"){
				//Change to parent dir
				$oCsd = $this->vfsGetFsHandle($oFsRequest->getSourceNetwork(),$oFsRequest->getSourceStation(),$oFsRequest->getCsd());
				$sParentPath = $oCsd->getEconetParentPath();
				$oNewRootDir = $this->vfsCreateFsHandle($oFsRequest->getSourceNetwork(),$oFsRequest->getSourceStation(),$sParentPath,bDirectory: true);
			}else{
				$oNewRootDir = $this->vfsCreateFsHandle($oFsRequest->getSourceNetwork(),$oFsRequest->getSourceStation(),$sOptions,bDirectory: true);

			}

			if(!$oNewRootDir->isDir()){
				$this->oLogger->debug("User tryed to change to directory ".$oNewRootDir->getEconetDirName()." however its not a directory.");
				$oReply->setError(0xbe,"Not a directory");
				$this->addReplyToBuffer($oReply->buildEconetpacket());
				return;
			}
		}catch(Exception){
			$this->oLogger->debug("User tryed to chroot to ".$sOptions." however that is not a valid path.");
			$oReply->setError(0xbe,"Invalid path");
			$this->addReplyToBuffer($oReply->buildEconetpacket());
			return;
		}

		$this->oLogger->debug("User ".$oUser->getUsername()." chroot to ".$oNewRootDir->getEconetPath());
		$oUser->setRoot($oNewRootDir->getEconetPath());
		
		//$this->vfsCloseFsHandle($oFsRequest->getSourceNetwork(),$oFsRequest->getSourceStation(),$oFsRequest->getCsd());
		$this->vfsCloseFsHandle($oFsRequest->getSourceNetwork(),$oFsRequest->getSourceStation(),$oNewRootDir->getId());
		$oNewCsd = $this->vfsCreateFsHandle($oFsRequest->getSourceNetwork(),$oFsRequest->getSourceStation(),'$',bDirectory: true);
		$oUser->setCsd('$');
		$this->vfsReplaceFsHandle($oFsRequest->getSourceNetwork(),$oFsRequest->getSourceStation(),$oFsRequest->getCsd(),$oNewCsd->getId());
		$oReply->DirOk();
		$oReply->appendByte($oNewCsd->getID());
		$oUser->setCsd($oNewCsd->getEconetPath());
		$this->addReplyToBuffer($oReply->buildEconetpacket());
			
	}

	/**
	 * Turns off the chroot feature reverting back to the true root of the filestore 
	 *
	*/
	public function chrootoff(FsRequest $oFsRequest,?string $sOptions): void
	{
		$oReply = $oFsRequest->buildReply();
		$oUser = $this->secGetUser($oFsRequest->getSourceNetwork(),$oFsRequest->getSourceStation());
		if($oUser === null){
			$oReply->setError(0xbf,"Who are you?");
			$this->addReplyToBuffer($oReply->buildEconetpacket());
			return;
		}
		$oCsd = $this->vfsGetFsHandle($oFsRequest->getSourceNetwork(),$oFsRequest->getSourceStation(),$oFsRequest->getCsd());
		//The CSD's stored econet path is always already the real, fully-resolved
		//absolute path (Vfs::buildFullPath() resolves the chroot prefix in when the
		//handle is built) — no need to reconstruct it via getEconetDirName(), which
		//only returns the last path segment and would silently produce a bogus
		//relative path here.
		$sNewPath = $oCsd->getEconetPath();
		$oUser->setRoot('$');
		try {
			$this->vfsCloseFsHandle($oFsRequest->getSourceNetwork(),$oFsRequest->getSourceStation(),$oFsRequest->getCsd());
			$oNewCsd = $this->vfsCreateFsHandle($oFsRequest->getSourceNetwork(),$oFsRequest->getSourceStation(),$sNewPath,bDirectory: true);
		}catch(Exception){
			$this->oLogger->debug("chrootoff: Unable to re-open ".$sNewPath." after leaving the chroot.");
			$oReply->setError(0xbe,"Invalid path");
			$this->addReplyToBuffer($oReply->buildEconetpacket());
			return;
		}
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
		$aUsers = $this->secGetUsersOnline();
		$iTotalUsers = array_sum(array_map(count(...),$aUsers));
		$this->oLogger->debug("usersOnline: There are ".$iTotalUsers." on-line, the clients request details of (".$iStart."/".$iCount.")");
		$iUsersRemaining = max(0, $iTotalUsers - $iStart);
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
					$oReply->appendString(str_pad(substr((string) $oUser->getUsername(),0,10),10,' '));
					$oReply->appendByte(0x0d);
					if($oUser->isAdmin()){
						$oReply->appendByte(1);
					}else{
						$oReply->appendByte(0);
					}
				}
				$i++;
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

		$aStation = $this->secGetUsersStation($sUser);
		if(array_key_exists('network',$aStation) AND array_key_exists('station',$aStation)){
			$oReply->DoneOk();
			$oUser = $this->secGetUser($aStation['network'],$aStation['station']);
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
			$oReply->appendString(str_pad(substr(config::getValueAsString('vfs_disc_name'),0,16),16,' '));
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
		$oReply->append24bitIntLittleEndian(config::getValueAsInt('vfs_default_disc_free'));
		$oReply->append24bitIntLittleEndian(config::getValueAsInt('vfs_default_disc_size'));
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
		$oReply->appendString("aunfs_srv ".config::getValueAsString('version'));
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
		$oReply->appendByte((int) date('j',$iTime));
		//Hi 4bits year, low 4bits Month
		$oReply->appendByte( ((int) date('y',$iTime) << 4) + (int) date('n',$iTime) );
		//Hour
		$oReply->appendByte((int) date('G',$iTime));
		//Min
		$oReply->appendByte((int) date('i',$iTime));
		//Sec
		$oReply->appendByte((int) date('s',$iTime));

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
		$this->vfsCreateFile($oFsRequest->getSourceNetwork(),$oFsRequest->getSourceStation(),$sPath,$iSize,$iLoad,$iExec);

		if($iSize > 0){
			if($iAckPort === null){
				$oReply = $oFsRequest->buildReply();
				$oReply->setError(0xff,"Syntax ?");
				$this->addReplyToBuffer($oReply->buildEconetpacket());
				return;
			}
			$oUser = $this->secGetUser($oFsRequest->getSourceNetwork(),$oFsRequest->getSourceStation());
			if($oUser === null){
				$oReply = $oFsRequest->buildReply();
				$oReply->setError(0xbf,"Who are you?");
				$this->addReplyToBuffer($oReply->buildEconetpacket());
				return;
			}

			//Tell client to stream data to the data port
			$oReply = $oFsRequest->buildReply();
			$oReply->DoneOk();
			$oReply->appendByte(config::getValueAsInt('econet_data_stream_port'));
			$oReply->append16bitIntLittleEndian(968);
			$this->addReplyToBuffer($oReply->buildEconetpacket());

			$_this = $this;
			$this->addStream($oFsRequest->getSourceNetwork(),$oFsRequest->getSourceStation(),
				new StreamIn(
					$iSize,
					function(StreamIn $oStream, EconetPacket $oPacket) use ($oFsRequest, $iAckPort, $_this) {
						$oAck = $oFsRequest->buildReply();
						$oAck->DoneOk();
						$oAckPacket = $oAck->buildEconetpacket();
						$oAckPacket->setPort($iAckPort);
						$_this->addReplyToBuffer($oAckPacket);
					},
					function(StreamIn $oStream, string $sData) use ($oFsRequest, $sPath, $iLoad, $iExec, $_this){
						$this->vfsSaveFile($oFsRequest->getSourceNetwork(),$oFsRequest->getSourceStation(),$sPath,$sData,$iLoad,$iExec);

						$oReply2 = $oFsRequest->buildReply();
						$oReply2->DoneOk();
						$oReply2->appendByte(15);
						$iDay = (int) date('j',time());
						$oReply2->appendByte($iDay);
						$iYear = (int) date('y',time());
						$iYear <<= 4;
						$iYear = $iYear + (int) date('n',time());
						$oReply2->appendByte($iYear);
						$_this->addReplyToBuffer($oReply2->buildEconetpacket());
						$_this->freeStream($oFsRequest->getSourceNetwork(),$oFsRequest->getSourceStation());
					},
					function(string $sError) use($oFsRequest, $_this) {
						$_this->oLogger->debug("Createfile stream failed (".$sError.")");
						$_this->freeStream($oFsRequest->getSourceNetwork(),$oFsRequest->getSourceStation());
						$oFailReply=$oFsRequest->buildReply();
						$oFailReply->setError(0xff,"Timeout");
						$_this->addReplyToBuffer($oFailReply->buildEconetpacket());
					},
					60,
					$sPath,
					$oUser->getUsername() ?? ''
				)
			);
		}else{
			//Zero-length file: just confirm creation
			$oReply = $oFsRequest->buildReply();
			$oReply->DoneOk();
			$oReply->appendByte(15);
			$iDay = (int) date('j',time());
			$oReply->appendByte($iDay);
			$iYear = (int) date('y',time());
			$iYear <<= 4;
			$iYear = $iYear + (int) date('n',time());
			$oReply->appendByte($iYear);
			$this->addReplyToBuffer($oReply->buildEconetpacket());
		}
	}

	/**
	 * EC_FS_FUNC_USERS_EXT (0x21) — paginated list of all registered users
	 *
	 * Request payload: [1] start_index  [2] count
	 * Reply:           [0x00, 0x00, remaining, (name(10)+0x0D+priv_flag)*n]
	*/
	public function usersExt(FsRequest $oFsRequest): void
	{
		$oReply = $oFsRequest->buildReply();
		if(!$this->secIsLoggedIn($oFsRequest->getSourceNetwork(),$oFsRequest->getSourceStation())){
			$oReply->setError(0xbf,"Who are you?");
			$this->addReplyToBuffer($oReply->buildEconetpacket());
			return;
		}
		$iStart = $oFsRequest->getByte(1) ?? 0;
		$iCount = $oFsRequest->getByte(2) ?? 0;

		$aAllUsers = $this->secGetAllUsers();
		$iTotalUsers = count($aAllUsers);
		$aPage = array_slice($aAllUsers, $iStart, $iCount);
		$iRemaining = max(0, $iTotalUsers - $iStart - count($aPage));

		$oReply->DoneOk();
		$oReply->appendByte($iRemaining);
		foreach($aPage as $aEntry){
			$oUser = $aEntry['user'];
			$oReply->appendString(str_pad(substr((string) $oUser->getUsername(),0,10),10,' '));
			$oReply->appendByte(0x0d);
			$oReply->appendByte($oUser->isAdmin() ? 1 : 0);
		}
		$this->addReplyToBuffer($oReply->buildEconetpacket());
	}

	/**
	 * EC_FS_FUNC_USER_INFO_EXT (0x22) — extended info for a named user
	 *
	 * Request payload: username (CR-terminated)
	 * Reply OK:        [0x00, 0x00, priv_flag(1), boot_opt(1)]
	 * Reply not found: [0x00, 0xBF]
	*/
	public function userInfoExt(FsRequest $oFsRequest): void
	{
		$oReply = $oFsRequest->buildReply();
		if(!$this->secIsLoggedIn($oFsRequest->getSourceNetwork(),$oFsRequest->getSourceStation())){
			$oReply->setError(0xbf,"Who are you?");
			$this->addReplyToBuffer($oReply->buildEconetpacket());
			return;
		}
		$sUsername = $oFsRequest->getString(1);
		$oUser = $this->secGetUserByName($sUsername);
		if(!is_object($oUser)){
			$oReply->DoneNoton();
			$this->addReplyToBuffer($oReply->buildEconetpacket());
			return;
		}
		$oReply->DoneOk();
		$oReply->appendByte($oUser->isAdmin() ? 1 : 0);
		$oReply->appendByte($oUser->getBootOpt());
		$this->addReplyToBuffer($oReply->buildEconetpacket());
	}

	/**
	 * Returns the printer registry used by Cli::cliPrinter().
	 * Override in test subclasses to inject a registry from a string.
	*/
	public function getFileServerPrinterRegistry(): PrinterRegistry
	{
		return new PrinterRegistry();
	}

	public function whoAmI(FsRequest $oFsRequest): void
	{
		$oReply = $oFsRequest->buildReply();
		$oUser = $this->secGetUser($oFsRequest->getSourceNetwork(),$oFsRequest->getSourceStation());
		if(is_object($oUser)){
			$oReply->DoneOk();
			$oReply->appendString($oUser->getUsername() ?? '');
			$oReply->appendByte(0x0d);
		}else{
			$oReply->setError(0xbf,"Who are you?");
		}
		$this->addReplyToBuffer($oReply->buildEconetpacket());	
	}

	/**
	 * @return array<int,array<string,mixed>>
	*/
	public function getJobs(): array
	{
		return [];
	}

	// -------------------------------------------------------------------------
	// Admin browsing helpers
	// -------------------------------------------------------------------------

	/**
	 * List a directory by Acorn path, querying VFS plugins directly.
	 * Bypasses the authentication layer so it is safe to call from the admin UI.
	 *
	 * @return array<string,DirectoryEntry>
	 */
	public function getAdminDirectoryListing(string $sAcornPath): array
	{
		$aListing = [];
		foreach (Vfs::getVfsPlugins() as $sPlugin) {
			try {
				$aListing = $sPlugin::getDirectoryListing($sAcornPath, $aListing);
			} catch (VfsException $e) {
				if ($e->isHard()) {
					break;
				}
			} catch (\Throwable $e) {
				$this->oLogger->error('Admin directory listing failed for plugin '.$sPlugin.' path '.$sAcornPath.': '.$e->getMessage().' in '.$e->getFile().':'.$e->getLine());
			}
		}
		return $aListing;
	}

	/**
	 * Return the raw contents of a file by Acorn path, querying VFS plugins directly.
	 * Returns null if no plugin can serve the file.
	 */
	public function getAdminFileContents(string $sAcornPath): ?string
	{
		$iLastDot = strrpos($sAcornPath, '.');
		if ($iLastDot === false) {
			return null;
		}
		$oPath      = new FilePath(substr($sAcornPath, 0, $iLastDot), substr($sAcornPath, $iLastDot + 1));
		$oDummyUser = new User();
		$oDummyUser->setUsername('_admin');
		$oDummyUser->setUnixUid(posix_getuid());

		foreach (Vfs::getVfsPlugins() as $sPlugin) {
			try {
				return $sPlugin::getFile($oDummyUser, $oPath);
			} catch (VfsException $e) {
				if ($e->isHard()) {
					return null;
				}
			} catch (\Throwable) {
			}
		}
		return null;
	}

	// -------------------------------------------------------------------------
	// Protected wrappers — override in tests to avoid real Vfs/Security/config
	// -------------------------------------------------------------------------

	protected function vfsInit(): void
	{
		Vfs::init($this->oLogger, config::getValueAsString('vfs_plugins'), config::getValueAsString('security_mode')=='multiuser');
	}

	/**
	 * @return DirectoryEntry
	*/
	public function vfsGetMeta(int $iNet, int $iStn, string $sPath)
	{ return Vfs::getMeta($iNet, $iStn, $sPath); }

	public function vfsSetMeta(int $iNet, int $iStn, string $sPath, ?int $iLoad, ?int $iExec, ?int $iAccess): void
	{ Vfs::setMeta($iNet, $iStn, $sPath, $iLoad, $iExec, $iAccess); }

	/**
	 * @return FileDescriptor
	*/
	public function vfsGetFsHandle(int $iNet, int $iStn, ?int $iHandle)
	{
		if($iHandle === null){
			throw new Exception("vfs: No file handle supplied");
		}
		return Vfs::getFsHandle($iNet, $iStn, $iHandle);
	}

	/**
	 * @return FileDescriptor
	*/
	public function vfsCreateFsHandle(int $iNet, int $iStn, string $sPath, bool $bMustExist=false, bool $bReadOnly=false, bool $bDirectory=false)
	{ return Vfs::createFsHandle($iNet, $iStn, $sPath, $bMustExist, $bReadOnly, $bDirectory); }

	public function vfsCloseFsHandle(int $iNet, int $iStn, ?int $iHandle): void
	{
		if($iHandle === null){
			return;
		}
		Vfs::closeFsHandle($iNet, $iStn, $iHandle);
	}

	public function vfsCloseAllFsHandles(int $iNet, int $iStn): void
	{ Vfs::closeAllFsHandles($iNet, $iStn); }

	public function vfsSaveFile(int $iNet, int $iStn, string $sPath, string $sData, ?int $iLoad, ?int $iExec): void
	{ Vfs::saveFile($iNet, $iStn, $sPath, $sData, $iLoad ?? 0, $iExec ?? 0); }

	public function vfsGetFile(int $iNet, int $iStn, string $sPath): string
	{ return (string) Vfs::getFile($iNet, $iStn, $sPath); }

	/**
	 * @return array<string,DirectoryEntry>
	*/
	public function vfsGetDirectoryListing(FileDescriptor $oFd): array
	{ return Vfs::getDirectoryListing($oFd); }

	public function vfsCreateDirectory(int $iNet, int $iStn, string $sPath): void
	{ Vfs::createDirectory($iNet, $iStn, $sPath); }

	public function vfsDeleteFile(int $iNet, int $iStn, string $sPath): void
	{ Vfs::deleteFile($iNet, $iStn, $sPath); }

	public function vfsMoveFile(int $iNet, int $iStn, string $sFrom, string $sTo): void
	{ Vfs::moveFile($iNet, $iStn, $sFrom, $sTo); }

	protected function vfsCreateFile(int $iNet, int $iStn, string $sPath, int $iSize, int $iLoad, int $iExec): void
	{ Vfs::createFile($iNet, $iStn, $sPath, $iSize, $iLoad, $iExec); }

	protected function vfsReplaceFsHandle(int $iNet, int $iStn, ?int $iOldId, int $iNewId): void
	{
		if($iOldId === null){
			throw new Exception("vfs: No handle supplied to replace");
		}
		Vfs::replaceFsHandle($iNet, $iStn, $iOldId, $iNewId);
	}

	protected function secIsLoggedIn(int $iNet, int $iStn): bool
	{ return Security::isLoggedIn($iNet, $iStn); }

	protected function secUpdateIdleTimer(int $iNet, int $iStn): void
	{ Security::updateIdleTimer($iNet, $iStn); }

	/**
	 * @return ?User
	*/
	public function secGetUser(int $iNet, int $iStn)
	{ return Security::getUser($iNet, $iStn); }

	protected function secLogin(int $iNet, int $iStn, string $sUser, string $sPass): bool
	{ return Security::login($iNet, $iStn, $sUser, $sPass); }

	protected function secLogout(int $iNet, int $iStn): void
	{ Security::logout($iNet, $iStn); }

	/**
	 * @return array<string,int>
	*/
	protected function secGetUsersStation(string $sUser): array
	{ return Security::getUsersStation($sUser); }

	/**
	 * @return array<int,array<int,SecuritySession>>
	*/
	public function secGetUsersOnline(): array
	{ return Security::getUsersOnline(); }

	public function secSetPassword(int $iNet, int $iStn, ?string $sOld, ?string $sNew): void
	{ Security::setConnectedUsersPassword($iNet, $iStn, $sOld, $sNew); }

	protected function secCreateUser(int $iNet, int $iStn, User $oUser): void
	{ Security::createUser($iNet, $iStn, $oUser); }

	protected function secRemoveUser(int $iNet, int $iStn, string $sUser): bool
	{ return (bool) Security::removeUser($iNet, $iStn, $sUser); }

	public function secSetPriv(int $iNet, int $iStn, string $sUser, string $sPriv): void
	{ Security::setPriv($iNet, $iStn, $sUser, $sPriv); }

	public function secSetOpt(int $iNet, int $iStn, string $sOpt): void
	{ Security::setOpt($iNet, $iStn, $sOpt); }

	/**
	 * @return array<int,array{plugin:string,user:User}>
	*/
	protected function secGetAllUsers(): array
	{ return Security::getAllUsers(); }

	public function secGetUserByName(string $sUsername): ?\HomeLan\FileStore\Authentication\User
	{ return Security::getUserByName($sUsername); }

	public function secSetUserQuota(int $iNet, int $iStn, string $sUsername, int $iQuota): void
	{ Security::setUserQuota($iNet, $iStn, $sUsername, $iQuota); }

	public function secSetAdminPassword(int $iNet, int $iStn, string $sUsername, string $sPassword): void
	{ Security::setAdminPassword($iNet, $iStn, $sUsername, $sPassword); }
}
