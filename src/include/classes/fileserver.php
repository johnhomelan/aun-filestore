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
		switch($sFunction){
			case 'EC_FS_FUNC_CLI':
				$this->cliDecode($oFsRequest);
				break;
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
				break;
			case 'EC_FS_FUNC_SET_INFO':
				break;
			case 'EC_FS_FUNC_GET_UENV':
				break;
			case 'EC_FS_FUNC_LOGOFF':
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
					$sPass = $sOptions[2];
				}else{
					$sPass = "";
				}
			}else{
				$sUser = $aOptions[0];
				if(array_key_exists(1,$aOptions)){
					$sPass=$aOptions[1];
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
			$oUser = securtiy::getUser($oFsRequest->getSourceNetwork(),$oFsRequest->getSourceStation());
			$oUrd = vfs::createFsHandle($oFsRequest->getSourceNetwork(),$oFsRequest->getSourceStation(),$oUser->getValue('homedir'));
			$oCsd = vfs::createFsHandle($oFsRequest->getSourceNetwork(),$oFsRequest->getSourceStation(),$oUser->getValue('homedir'));
			$oLib = vfs::createFsHandle($oFsRequest->getSourceNetwork(),$oFsRequest->getSourceStation(),config::getValue('library_path'));
			$oReply = $oFsRequest->buildReply();
			$oReply->loginRespone($oUrd->getId(),$oCsd->getId(),$oLib->getId(),$oUser->getValue('opt'));
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
		
	}

	public function eof($oFsRequest)
	{
		if(!security::isLoggedIn($oFsRequest->getSourceNetwork(),$oFsRequest->getSourceStation())){
			$oReply = $oFsRequest->buildReply();
			$oReply->setError(0xbf,"Who are you?");
			
		}
		logger::log("Eof Called by ".$oFsRequest->getSourceNetwork().".".$oFsRequest->getSourceStation(),LOG_DEBUG);
		$oUser = securtiy::getUser($oFsRequest->getSourceNetwork(),$oFsRequest->getSourceStation());	
	}

	public function examine($oFsRequest)
	{
		if(!security::isLoggedIn($oFsRequest->getSourceNetwork(),$oFsRequest->getSourceStation())){
			$oReply = $oFsRequest->buildReply();
			$oReply->setError(0xbf,"Who are you?");
			
		}
		$iByte1 = $oFsRequest->getByte(1);
		switch($iByte1){
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
				break;
		}
	}

	public function getArgs($oFsRequest)
	{
		if(!security::isLoggedIn($oFsRequest->getSourceNetwork(),$oFsRequest->getSourceStation())){
			$oReply = $oFsRequest->buildReply();
			$oReply->setError(0xbf,"Who are you?");
			
		}
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
	}
}

?>
