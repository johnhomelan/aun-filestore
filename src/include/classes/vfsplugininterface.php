<?
/**
 * This file contains interface all vfs plugins must implement
 *
*/

/**
 * Any vfs plugin should implelement this interface 
 * @package corevfs
 * @authour John Brown <john@home-lan.co.uk>
*/
interface vfsplugininterface {

	public static function init();

	public static function houseKeeping();

	public static function _buildFiledescriptorFromEconetPath($oUser,$sCsd,$sEconetPath,$bMustExist,$bReadOnly);

	public static function _getAccessMode($iGid,$iUid,$iMode);

	public static function getDirectoryListing($sEconetPath,$aDirectoryListing);

	public static function createDirectory($oUser,$sCsd,$sEconetPath);

	public static function deleteFile($oUser,$sCsd,$sEconetPath);

	public static function moveFile($oUser,$sCsd,$sEconetPathFrom,$sEconetPathTo);

	public static function saveFile($oUser,$sCsd,$sEconetPath,$sData,$iLoadAddr,$iExecAddr);

	public static function createFile($oUser,$sCsd,$sEconetPath,$iSize,$iLoadAddr,$iExecAddr);

	public static function getFile($oUser,$sCsd,$sEconetPath);

	public static function setMeta($sEconetPath,$iLoad,$iExec,$iAccess);

	public static function fsFtell($oUser,$fLocalHandle);

	public static function fsFStat($oUser,$fLocalHandle);

	public static function isEof($oUser,$fLocalHandle);

	public static function setPos($oUser,$fLocalHandle,$iPos);
	
	public static function read($oUser,$fLocalHandle,$iLength);

	public static function write($oUser,$fLocalHandle,$sData);

	public static function fsClose($oUser,$fLocalHandle);
}

?>
