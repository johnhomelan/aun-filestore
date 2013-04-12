<?
/**
 * This file contains the file descriptor class
 *
 * @author John Brown <john@home-lan.co.uk>
 * @package corefs
*/

/** 
 * Econet NetFs uses filedecriptor ids with its client for all file operations.  The file handles 
 * identitier is a single byte.  This class represents a single file description for the server 
 * and maps to a local file handle and remote user.
 *
 * @package corefs
*/
class filedescriptor {

	protected $iHandle = NULL;

	protected $fLocalHandle = NULL;

	protected $oUser = NULL;

	protected $sFilePath = NULL;

	protected  function _setUid()
	{
		if(config::getValue('security_mode')=='multiuser'){
			posix_seteuid($this->oUser->getUnixUid());
		}
	}
	
	protected function _returnUid()
	{
		if(config::getValue('security_mode')=='multiuser'){
			 posix_seteuid(config::getValue('system_user_id'));
		}
	}

	public function fsFTell()
	{
		if(!is_null($this->fLocalHandle)){
			$this->_setUid();
			$mResult = ftell($this->fLocalHandle);
			$this->_returnUid();
			return $mResult;
		}
		
	}

	public function fsFStat()
	{
		if(!is_null($this->fLocalHandle)){
			$this->_setUid();
			$mResult = fstat($this->fLocalHandle);
			$this->_returnUid();
			return $mResult;
		}
	}
}
?>
