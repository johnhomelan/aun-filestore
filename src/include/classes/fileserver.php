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

	protected $aCommands = array('BYE','CAT','CDIR','DELETE','DIR','FSOPT','INFO','I AM','LIB','LOAD','LOGOFF','PASS','RENAME','SAVE','SDISC');
	
	protected $aReplyBuffer = array();

	protected function _addReplyToBuffer($oReply)
	{
		$this->aReplyBuffer[]=$oReply;
	}

	public function getReplies()
	{
		$aReplies = $this->aReplyBuffer;
		$this->aReplyBuffer = array();
		return $aReplies;
	}

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
				break;
			case 'EC_FS_FUNC_GET_VERSION':
				break;
			case 'EC_FS_FUNC_GET_DISC_FREE':
				break;
			case 'EC_FS_FUNC_CDIRN':
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
				break;
			}			
		}
	}

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
			case 'CDIR':
			case 'DELETE':
			case 'DIR':
			case 'FSOPT':
			case 'INFO':
			case 'LIB':
			case 'LOAD':
			case 'RENAME':
			case 'SAVE':
			case 'SDISC':
				break;
			default:
				logger::log("Un-handled command ".$sCommand,LOG_DEBUG);
				break;
		}
	}

	public function login($oFsRequest,$sOptions)
	{
		logger::log("fileserver: Login called ".$sOptions,LOG_DEBUG);
		$aOptions = explode(" ",$sOptions);
		if(count($aOptions)>0){
			//Creditials supplied, decode username and password
			if(is_numeric($aOptions[0])){
				//station number supplied skip
				if(array_key_exists(1,$aOptions)){
					$sUser = $aOptions[1];
				}
				if(array_key_exists(2,$aOptions)){
					$sPass = trim($sOptions[2]);
					if(substr_count($sPass,"\r")>0){
						list($sPass) = explode("\r",$sPass);
					}
				}else{
					$sPass = "";
				}
			}else{
				$sUser = $aOptions[0];
				if(array_key_exists(1,$aOptions)){
					$sPass = trim($aOptions[1]);
					if(substr_count($sPass,"\r")>0){
						list($sPass) = explode("\r",$sPass);
					}
				}else{
					$sPass="";
				}
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
			$oUser = security::getUser($oFsRequest->getSourceNetwork(),$oFsRequest->getSourceStation());
			try {
				$oUrd = vfs::createFsHandle($oFsRequest->getSourceNetwork(),$oFsRequest->getSourceStation(),$oUser->getHomedir());
				$oCsd = vfs::createFsHandle($oFsRequest->getSourceNetwork(),$oFsRequest->getSourceStation(),$oUser->getHomedir());	
			}catch(Exception $oException){
				logger::log("fileserver: Login unable to open homedirectory for user ".$oUser->getUsername(),LOG_INFO);
				$oUrd = vfs::createFsHandle($oFsRequest->getSourceNetwork(),$oFsRequest->getSourceStation(),'');
				$oCsd = vfs::createFsHandle($oFsRequest->getSourceNetwork(),$oFsRequest->getSourceStation(),'');
			}
			try {
				$oLib = vfs::createFsHandle($oFsRequest->getSourceNetwork(),$oFsRequest->getSourceStation(),config::getValue('library_path'));
			}catch(Exception $oException){
				logger::log("fileserver: Login unable to open library dir setting library to $ for user ".$oUser->getUnsername(),LOG_INFO);
				$oLib = vfs::createFsHandle($oFsRequest->getSourceNetwork(),$oFsRequest->getSourceStation(),'');
			}
			$oReply = $oFsRequest->buildReply();
			logger::log("fileserver: Login ok urd:".$oUrd->getId()." csd:".$oCsd->getId()." lib:".$oLib->getId(),LOG_DEBUG);
			$oReply->loginRespone($oUrd->getId(),$oCsd->getId(),$oLib->getId(),$oUser->getBootOpt());
			$this->_addReplyToBuffer($oReply);
		}else{
			$oReply = $oFsRequest->buildReply();

			logger::log("Login Failed: For user ".$sUser." invalid password/no such user",LOG_INFO);
			//Send Wrong Password
			$oReply->setError(0xbb,"Incorrect password");
			$this->_addReplyToBuffer($oReply);
		}
			
	}

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

	public function getInfo($oFsRequest)
	{
		var_dump($oFsRequest->getByte(1));
		var_dump($oFsRequest->getString(2));
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
				$oReply = $oFsRequest->buildReply();
				$oReply->setError(0x8e,"Bad INFO argument");
				$this->_addReplyToBuffer($oReply);
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

	public function examine($oFsRequest)
	{
		$oReply = $oFsRequest->buildReply();
		$iArg = $oFsRequest->getByte(1);
		$iStart = $oFsRequest->getByte(2);
		$iCount = $oFsRequest->getByte(3);
		var_dump($iArg);
		var_dump($iStart);
		var_dump($iCount);
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
				//Number of entries 1 Byte
				$oFd = vfs::getFsHandle($oFsRequest->getSourceNetwork(),$oFsRequest->getSourceStation(),$oFsRequest->getCsd());
				$aDirEntries=vfs::getDirectoryListing($oFd);
				$oReply->appendByte(count($aDirEntries));
				//Undefined but riscos needs it 
				$oReply->appendByte(0);
				
				//Data
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
		var_dump($oLib->getEconetDirName());
		$oReply->appendString(str_pad(substr($oLib->getEconetDirName(),0,10),10,' '));

		$this->_addReplyToBuffer($oReply);
	}
}

?>
