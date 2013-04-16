<?
/**
 * This file contains the localfiles vfs plugin
 *
*/

/**
 * The vfspluginlocalfiles class acts as a vfs plugin to provide access to local files using the same on disk 
 * format as aund.
 *
 * @package corevfs
 * @authour John Brown <john@home-lan.co.uk>
*/
class vfspluginlocalfiles {

	protected  function _setUid($oUser)
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

	public static function fsFtell($oUser,$fLocalHandle)
	{
		vfspluginlocalfiles::_setUid($oUser);
		$mReturn =  ftell($fLocalHandle);
		vfspluginlocalfiles::_returnUid();
		return $mReturn;
	}

	public static function fsFStat($oUser,$fLocalHandle)
	{
		vfspluginlocalfiles::_setUid($oUser);
		$mReturn =  fstat($fLocalHandle);
		vfspluginlocalfiles::_returnUid();
		return $mReturn;
	}
	
	public static function close($oUser,$fLocalHandle)
	{
		vfspluginlocalfiles::_setUid($oUser);
		$mReturn = fclose($fLocalHandle);
		vfspluginlocalfiles::_returnUid();
		return $mReturn;
	}
}

?>
