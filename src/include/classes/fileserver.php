<?
/**
 * This file contains the fileserver class
 *
 * @author John Brown <john@home-lan.co.uk>
 * @package core
*/

/**
 * This class implements the econet fileserver
 *
 * @package core
*/
class fileserver {

	protected $aCommands = array('BYE','CAT','CDIR','DELETE','DIR','FSOPT','INFO','I AM','LIB','LOAD','LOGOFF','PASS','RENAME','SAVE','SDISC','NEWUSER','PRIV');
	
	protected $aReplyBuffer = array();

	protected function _addReplyToBuffer($oReply)
	{
		$this->aReplyBuffer[]=$oReply;
	}

	/**
	 * Retreives all the reply objects built by the fileserver 
	 *
	 * This method removes the replies from the buffer 
	*/
	public function getReplies()
	{
		$aReplies = $this->aReplyBuffer;
		$this->aReplyBuffer = array();
		return $aReplies;
	}

	/**
	 * This is the main entry point to this class 
	 *
	 * The fsrequest object contains the request the fileserver must process 
	 * @param object fsrequest $oFsRequest
	*/
	public function processRequest($oFsRequest)
	{
		$sFunction = $oFsRequest->getFunction();
		logger::log("FS function ".$sFunction,LOG_DEBUG);

		//Function where you dont always need to be logged in
		switch($sFunction){
			case 'EC_FS_FUNC_CLI':
				$this->cliDecode($oFsRequest);
				return;
				break;

		}

		//Function where the user must be logged in
		if(!security::isLoggedIn($oFsRequest->getSourceNetwork(),$oFsRequest->getSourceStation())){
			$oReply = $oFsRequest->buildReply();
			$oReply->setError(0xbf,"Who are you?");
			$this->_addReplyToBuffer($oReply);
			return;
		}
	
		switch($sFunction){
			case 'EC_FS_FUNC_LOAD':
				break;
			case 'EC_FS_FUNC_SAVE':
				break;
			case 'EC_FS_FUNC_EXAMINE':
				$this->examine($oFsRequest);
				break;
			case 'EC_FS_FUNC_CAT_HEADER':
				break;
			case 'EC_FS_FUNC_LOAD_COMMAND':
				break;
			case 'EC_FS_FUNC_OPEN':
				break;
			case 'EC_FS_FUNC_CLOSE':
				break;
			case 'EC_FS_FUNC_GETBYTE':
				break;
			case 'EC_FS_FUNC_PUTBYTE':
				break;
			case 'EC_FS_FUNC_GETBYTES':
				break;
			case 'EC_FS_FUNC_PUTBYTES':
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
				break;
			case 'EC_FS_FUNC_GET_INFO':
				$this->getInfo($oFsRequest);
				break;
			case 'EC_FS_FUNC_SET_INFO':
				break;
			case 'EC_FS_FUNC_GET_UENV':
				$this->getUenv($oFsRequest);
				break;
			case 'EC_FS_FUNC_LOGOFF':
				$this->logout($oFsRequest);
				break;
			case 'EC_FS_FUNC_GET_USERS_ON':
				break;
			case 'EC_FS_FUNC_GET_USER':
				break;
			case 'EC_FS_FUNC_GET_TIME':
				break;
			case 'EC_FS_FUNC_SET_OPT4':
				break;
			case 'EC_FS_FUNC_DELETE':
				$sFile = $oFsRequest->getString(1);
				$this->deleteFile($oFsRequest,$sFile);
				break;
			case 'EC_FS_FUNC_GET_VERSION':
				break;
			case 'EC_FS_FUNC_GET_DISC_FREE':
				break;
			case 'EC_FS_FUNC_CDIRN':
				$sDir = $oFsRequest->getString(2);
				$this->createDirectory($oFsRequest,$sDir);
				break;
			case 'EC_FS_FUNC_CREATE':
				break;
			case 'EC_FS_FUNC_GET_USER_FREE':
				break;
			default:
				logger::log("Un-handled fs function ".$sFunction,LOG_DEBUG);
				break;
				
		}
	}

	/**
	 * Decodes the cli request
	 *
	 * Once the decode is complete the decoded request is passedto the runCli method
	 *
	 * @param object $oFsRequest
	*/
	public function cliDecode($oFsRequest)
	{
		$sData = $oFsRequest->getData();
		$aDataAs8BitInts = unpack('C*',$sData);
		$sDataAsString = "";
		foreach($aDataAs8BitInts as $iChar){
			$sDataAsString = $sDataAsString.chr($iChar);
		}

		logger::log("Command: ".$sDataAsString.".",LOG_DEBUG);

		foreach($this->aCommands as $sCommand){
			$iPos = stripos($sDataAsString,$sCommand);
			if($iPos!==FALSE){
				//Found cli command found
				$iOptionsPos = $iPos+strlen($sCommand)+1;
				$sOptions = substr($sDataAsString,$iOptionsPos);
				$this->runCli($oFsRequest,$sCommand,trim($sOptions));
				return;
				break;
			}			
		}
		$oReply = $oFsRequest->buildReply();
		$oReply->setError(0x99,"No such command");
		$this->_addReplyToBuffer($oReply);
	}

	/**
	 * This method runs the cli command, or delegate to an approriate method
	 *
	 * @param object fsrequest $oFsRequest The fsrequest
	 * @param string $sCommand The command to run
	 * @param string $sOptions The command arguments
	*/
	public function runCli($oFsRequest,$sCommand,$sOptions)
	{
		switch($sCommand){
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
				$this->delete($oFsRequest,$sOptions);
				break;
			case 'DIR':
				$this->changeDirectory($oFsRequest,$sOptions);
				break;
			case 'FSOPT':
				break;
			case 'INFO':
				break;
			case 'LIB':
				$this->changeLibrary($oFsRequest,$sOptions);
				break;
			case 'LOAD':
				break;
			case 'RENAME':
				break;
			case 'SAVE':
				break;
			case 'SDISC':
				break;
			case 'PRIV':
				$this->privUser($oFsRequest,$sOptions);
				break;
			case 'NEWUSER':
				$this->createUser($oFsRequest,$sOptions);
				break;
			default:
				logger::log("Un-handled command ".$sCommand,LOG_DEBUG);
				$oReply = $oFsRequest->buildReply();
				$oReply->setError(0x99,"Un-implemented command");
				$this->_addReplyToBuffer($oReply);
				break;
		}
	}

	/**
	 * Handles login requests (*I AM)
	 *
	 * @param object fsrequest $oFsRequest
	 * @param string $sOptions The arguments passed to *I AM (e.g. username password)
	*/
	public function login($oFsRequest,$sOptions)
	{
		logger::log("fileserver: Login called ".$sOptions,LOG_DEBUG);
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
			logger::log("Login Failed: *I AM send but with no username or password",LOG_INFO);
			//Send Fail Notice
			$oReply = $oFsRequest->buildReply();

			//Send Wrong Password
			$oReply->setError(0xbb,"Incorrect password");
			$this->_addReplyToBuffer($oReply);
			return;
		}

		//Do login
		if(security::login($oFsRequest->getSourceNetwork(),$oFsRequest->getSourceStation(),$sUser,$sPass)){
			//Login success 

			//Create the handles for the csd urd and lib
			$oUser = security::getUser($oFsRequest->getSourceNetwork(),$oFsRequest->getSourceStation());
			try {
				$oUrd = vfs::createFsHandle($oFsRequest->getSourceNetwork(),$oFsRequest->getSourceStation(),$oUser->getHomedir());
				$oCsd = vfs::createFsHandle($oFsRequest->getSourceNetwork(),$oFsRequest->getSourceStation(),$oUser->getHomedir());	
			}catch(Exception $oException){
				logger::log("fileserver: Login unable to open homedirectory for user ".$oUser->getUsername(),LOG_INFO);
				$oUrd = vfs::createFsHandle($oFsRequest->getSourceNetwork(),$oFsRequest->getSourceStation(),'$');
				$oCsd = vfs::createFsHandle($oFsRequest->getSourceNetwork(),$oFsRequest->getSourceStation(),'$');
			}
			try {
				$oLib = vfs::createFsHandle($oFsRequest->getSourceNetwork(),$oFsRequest->getSourceStation(),config::getValue('library_path'));
			}catch(Exception $oException){
				logger::log("fileserver: Login unable to open library dir setting library to $ for user ".$oUser->getUsername(),LOG_INFO);
				$oLib = vfs::createFsHandle($oFsRequest->getSourceNetwork(),$oFsRequest->getSourceStation(),'');
			}
			//Handles are now build send the reply 
			$oReply = $oFsRequest->buildReply();
			logger::log("fileserver: Login ok urd:".$oUrd->getId()." csd:".$oCsd->getId()." lib:".$oLib->getId(),LOG_DEBUG);
			$oReply->loginRespone($oUrd->getId(),$oCsd->getId(),$oLib->getId(),$oUser->getBootOpt());
			$this->_addReplyToBuffer($oReply);
		}else{
			//Login failed
			$oReply = $oFsRequest->buildReply();

			//Send Wrong Password
			logger::log("Login Failed: For user ".$sUser." invalid password/no such user",LOG_INFO);
			$oReply->setError(0xbb,"Incorrect password");
			$this->_addReplyToBuffer($oReply);
		}
			
	}

	/**
	 * Handle logouts (*bye)
	 *
	 * We can be called as a cli command (*bye) and by its own function call
	 * @param object fsrequest $oFsRequest
	*/
	public function logout($oFsRequest)
	{
		try{
			security::logout($oFsRequest->getSourceNetwork(),$oFsRequest->getSourceStation());
			$oReply = $oFsRequest->buildReply();	
			$oReply->DoneOk();
		}catch(Exception $oException){
			$oReply = $oFsRequest->buildReply();
			$oReply->setError(0xbf,"Who are you?");
		}
		$this->_addReplyToBuffer($oReply);
	}

	/**
	 * Handles requests for information on directories and files
	 *
	 * This method is called when the client uses *. to produce the directory header
	 * @param objects fsrequest $oFsRequest
	*/
	public function getInfo($oFsRequest)
	{
		$sDir = $oFsRequest->getString(2);
		switch($oFsRequest->getByte(1)){
			case 4:
				//EC_FS_GET_INFO_ACCESS
				break;
			case 5:
				//EC_FS_GET_INFO_ALL
				break;
			case 1:
				//EC_FS_GET_INFO_CTIME
				break;
			case 2:
				//EC_FS_GET_INFO_META
				break;
			case 3:
				//EC_FS_GET_INFO_SIZE
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
						$oFd = vfs::getFsHandle($oFsRequest->getSourceNetwork(),$oFsRequest->getSourceStation(),$oFsRequest->getCsd());
						$oReply->appendString(str_pad(substr($oFd->getEconetDirName(),0,10),10,' '));
					}else{
						$oReply->appendString(str_pad(substr($sDir,0,10),10,' '));
					}

					//FS_DIR_ACCESS_PUBLIC
					$oReply->appendByte(0xff);

					//Cyle  always 0 probably should not be 
					$oReply->appendByte(0);

					$this->_addReplyToBuffer($oReply);

				}catch(Exception $oException){
					$oReply = $oFsRequest->buildReply();
					$oReply->setError(0x8e,"Bad INFO argument");
					$this->_addReplyToBuffer($oReply);
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
		$this->_addReplyToBuffer($oReply);
	}

	public function eof($oFsRequest)
	{
		logger::log("Eof Called by ".$oFsRequest->getSourceNetwork().".".$oFsRequest->getSourceStation(),LOG_DEBUG);
		$oUser = securtiy::getUser($oFsRequest->getSourceNetwork(),$oFsRequest->getSourceStation());	
	}

	/**
	 * Gets the details of a director/file 
	 * 
 	 * This method produces the directory listing for *.
	 * @param object fsrequest $oFsRequest
	*/
	public function examine($oFsRequest)
	{
		$oReply = $oFsRequest->buildReply();
		$iArg = $oFsRequest->getByte(1);
		$iStart = $oFsRequest->getByte(2);
		$iCount = $oFsRequest->getByte(3);
		switch($iArg){
			case 0:
				//EXAMINE_ALL
				break;
			case 1:
				//EXAMINE_LONGTXT
				break;
			case 2:
				//EXAMINE_NAME
				break;
			case 3:
				//EXAMINE_SHORTTXT

				$oReply->DoneOk();

				//Get the directory listing
				$oFd = vfs::getFsHandle($oFsRequest->getSourceNetwork(),$oFsRequest->getSourceStation(),$oFsRequest->getCsd());
				$aDirEntries=vfs::getDirectoryListing($oFd);
				logger::log("There are ".count($aDirEntries)." entries in dir ".$oFd->getEconetPath(),LOG_DEBUG);

				//Return only the entries the client requested (works like sql limit and offset)
				$aDirEntries = array_slice($aDirEntries,$iStart,$iCount);

				//Number of entries 1 Byte
				$oReply->appendByte(count($aDirEntries));
				//Undefined but riscos needs it 
				$oReply->appendByte(0);

				foreach($aDirEntries as $oFile){
					//Append the file name (limit 10 chars)
					$oReply->appendString(str_pad(substr($oFile->getEconetName(),0,10),10,' '));
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
		$this->_addReplyToBuffer($oReply);
	}

	public function getArgs($oFsRequest)
	{
		$iHandle = $oFsRequest->getByte(1);
		$iArg = $oFsRequest->getByte(2);
		switch($iArg){
			case 0:
				//EC_FS_ARG_PTR
				$oReply = $oFsRequest->buildReply();
				$oFd = vfs::getFsHandle($oFsRequest->getSourceNetwork(),$oFsRequest->getSourceStation(),$iHandle);
				$iPos = $oFd->fsFTell();
				$oReply->DoneOk();
				break;
			case 1:
				//EC_FS_ARG_EXT
				$oReply = $oFsRequest->buildReply();
				$oFd = vfs::getFsHandle($oFsRequest->getSourceNetwork(),$oFsRequest->getSourceStation(),$iHandle);
				$aStat = $oFd->fsFStat();
				$iSize = $aStat['size'];
				$oReply->DoneOk();
				break;
			case 2:
				//EC_FS_ARG_SIZE
				$oReply = $oFsRequest->buildReply();
				$oFd = vfs::getFsHandle($oFsRequest->getSourceNetwork(),$oFsRequest->getSourceStation(),$iHandle);
				$aStat = $oFd->fsFStat();
				$iSize = $aStat['blocks'];
				$oReply->DoneOk();
				break;
			default:
				$oReply = $oFsRequest->buildReply();
				$oReply->setError(0x8f,"Bad RDARGS argumen");
				$oReply->DoneOk();
				break;
		}
		$this->_addReplyToBuffer($oReply);
	}

	/**
	 * Gets the current user enviroment
	 *
	 * Sends a reply with the name of the disc the csd is on the name of the csd and library
	*/
	public function getUenv($oFsRequest)
	{
		$oReply = $oFsRequest->buildReply();
		$oReply->DoneOk();
		
		//Discname Max Length <16
		$oReply->appendByte(16);

		//csd Disc name String 16 bytes
		$oReply->appendString(str_pad(substr(config::getValue('vfs_disc_name'),0,16),16,' '));
		
		//csd Leaf name String 10 bytes
		$oCsd = vfs::getFsHandle($oFsRequest->getSourceNetwork(),$oFsRequest->getSourceStation(),$oFsRequest->getCsd());	
		$oReply->appendString(str_pad(substr($oCsd->getEconetDirName(),0,10),10,' '));

		//lib leaf name String 10 bytes
		$oLib = vfs::getFsHandle($oFsRequest->getSourceNetwork(),$oFsRequest->getSourceStation(),$oFsRequest->getLib());	
		$oReply->appendString(str_pad(substr($oLib->getEconetDirName(),0,10),10,' '));

		$this->_addReplyToBuffer($oReply);
	}

	public function changeDirectory($oFsRequest,$sOptions)
	{
		$oReply = $oFsRequest->buildReply();
		$oUser = security::getUser($oFsRequest->getSourceNetwork(),$oFsRequest->getSourceStation());
		
		if(strlen($sOptions)>0){
			try {
				if($sOptions=="^"){
					//Change to parent dir
					$oCsd = vfs::getFsHandle($oFsRequest->getSourceNetwork(),$oFsRequest->getSourceStation(),$oFsRequest->getCsd());
					$sParentPath = $oCsd->getEconetParentPath();
					$oNewCsd = vfs::createFsHandle($oFsRequest->getSourceNetwork(),$oFsRequest->getSourceStation(),$sParentPath);
				}else{
					$oNewCsd = vfs::createFsHandle($oFsRequest->getSourceNetwork(),$oFsRequest->getSourceStation(),$sOptions);	
					if(!$oNewCsd->isDir()){
						logger::log("User tryed to change to directory ".$oNewCsd->getEconetDirName()." however its not a directory.",LOG_DEBUG);
						$oReply->setError(0xbe,"Not a directory");
						$this->_addReplyToBuffer($oReply);
						return;
					}
				}
				vfs::closeFsHandle($oFsRequest->getSourceNetwork(),$oFsRequest->getSourceStation(),$oFsRequest->getCsd());
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
				$oNewCsd = vfs::createFsHandle($oFsRequest->getSourceNetwork(),$oFsRequest->getSourceStation(),$oUser->getHomedir());
				vfs::closeFsHandle($oFsRequest->getSourceNetwork(),$oFsRequest->getSourceStation(),$oFsRequest->getCsd());
				$oReply->DirOk();
				$oReply->appendByte($oNewCsd->getID());
				$oUser->setCsd($oNewCsd->getEconetPath());
			}catch(Exception $oException){
				$oReply->setError(0xff,"No such directory.");	
			}
		}
		$this->_addReplyToBuffer($oReply);


	}

	public function changeLibrary($oFsRequest,$sOptions)
	{
		$oReply = $oFsRequest->buildReply();
		
		if(strlen($sOptions)>0){
			try {
				$oNewLib = vfs::createFsHandle($oFsRequest->getSourceNetwork(),$oFsRequest->getSourceStation(),$sOptions);	
				if(!$oNewLib->isDir()){
					logger::log("User tryed to change the library to ".$oNewLib->getEconetDirName()." however its not a directory.",LOG_DEBUG);
					$oReply->setError(0xbe,"Not a directory");
					$this->_addReplyToBuffer($oReply);
					return;
				}
				vfs::closeFsHandle($oFsRequest->getSourceNetwork(),$oFsRequest->getSourceStation(),$oFsRequest->getLib());
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
		$this->_addReplyToBuffer($oReply);


	}

	public function createDirectory($oFsRequest,$sOptions)
	{
		$oReply = $oFsRequest->buildReply();
		if(strlen($sOptions)<1){
			$oReply->setError(0xff,"Syntax");
			$this->_addReplyToBuffer($oReply);
			return;
		}
		if(strlen($sOptions)>10){
			$oReply->setError(0xff,"Maximum directory name length is 10");
			$this->_addReplyToBuffer($oReply);
			return;
		}

		try {
			vfs::createDirectory($oFsRequest->getSourceNetwork(),$oFsRequest->getSourceStation(),$sOptions);
			$oReply->DoneOk();
		}catch(Exception $oException){
			$oReply->setError(0xff,"Unable to create directory");
		}
		$this->_addReplyToBuffer($oReply);
	}

	public function deleteFile($oFsRequest,$sOptions)
	{
		$oReply = $oFsRequest->buildReply();
		if(strlen($sOptions)<1){
			$oReply->setError(0xff,"Syntax");
		}else{
			try{
				vfs::deleteFile($oFsRequest->getSourceNetwork(),$oFsRequest->getSourceStation(),$sOptions);
				$oReply->DoneOk();
			}catch(Exception $oException){
				$oReply->setError(0xff,"Unable to delete");
			}
		}
		$this->_addReplyToBuffer($oReply);
	}

	public function createUser($oFsRequest,$sOptions)
	{
		$oReply = $oFsRequest->buildReply();
		if(strlen($sOptions)<1){
			$oReply->setError(0xff,"Syntax");
		}else{
			$aOptions = explode(' ',$sOptions);
			if(strlen($aOptions[0])>3 AND strlen($aOptions[0])<11 AND ctype_upper($aOptions[0]) AND ctype_alpha($aOptions[0])){
				$oUser = new user();
				$oUser->setUsername($aOptions[0]);
				if(!is_null(config::getValue('vfs_home_dir_path'))){
					$oUser->setHomedir(config::getValue('vfs_home_dir_path').'.'.$aOptions[0]);
					vfs::createDirectory($oFsRequest->getSourceNetwork(),$oFsRequest->getSourceStation(),config::getValue('vfs_home_dir_path').'.'.$aOptions[0]);
				}else{
					$oUser->setHomedir('$');
				}
				$oUser->setUnixUid(config::getValue('security_default_unix_uid'));
				$oUser->setPriv('U');
				try{
					security::createUser($oFsRequest->getSourceNetwork(),$oFsRequest->getSourceStation(),$oUser);
					$oReply->DoneOk();
				}catch(Exception $oException){
					$oReply->setError(0xff,$oException->getMessage());
				}
			}else{
				$oReply->setError(0xff,"Username must be between 3-10 chars and only contain the chars A-Z");
			}
		
		}
		$this->_addReplyToBuffer($oReply);
	}

	public function privUser($oFsRequest,$sOptions)
	{
		$aOptions = explode(' ',$sOptions);
		$oReply = $oFsRequest->buildReply();
		if(count($aOptions)!=2){
			$oReply->setError(0xff,"Syntax");
		}else{
			$oMyUser = security::getUser($oFsRequest->getSourceNetwork(),$oFsRequest->getSourceStation());
			if($oMyUser->isAdmin()){
				if($aOptions[1]!='S' AND $aOptions[1]!='U'){
					$oReply->setError(0xff,"The only valid priv is S or U");
				}else{
					security::setPriv($oFsRequest->getSourceNetwork(),$oFsRequest->getSourceStation(),$aOptions[0],$aOptions[1]);
					$oReply->DoneOk();
				}
			}else{
				$oReply->setError(0xff,"Only user with priv S can use *PRIV");
			}
			
		}
		$this->_addReplyToBuffer($oReply);
	}
}

?>
