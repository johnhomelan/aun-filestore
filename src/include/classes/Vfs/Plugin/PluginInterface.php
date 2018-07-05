<?php

namespace HomeLan\FileStore\Vfs\Plugin; 

/**
 * This file contains interface all vfs plugins must implement
 *
*/

/**
 * Any vfs plugin should implelement this interface 
 * @package corevfs
 * @authour John Brown <john@home-lan.co.uk>
*/
interface PluginInterface {

	/**
	 * Called when the plugin is first loaded
	 *
	 * The plugin can perform any setup operations needed in the init method
	*/
	public static function init();

	/**
	 * Called regually to perform any house keeping tasks
	 * 
	 * e.g. Clean up any file handler for users who have logged out
	*/
	public static function houseKeeping();

	public static function _buildFiledescriptorFromEconetPath($oUser,$sCsd,$sEconetPath,$bMustExist,$bReadOnly);

	public static function _getAccessMode($iGid,$iUid,$iMode);

	/**
	 * Takes an array of files and adds all the files from the econet file path
	 *
	 * The plugin could also remove files from the array
	 *
	 * @param string $sEconetPath The econet file path
	 * @param array $aDirectoryListing An array of file data
	*/
	public static function getDirectoryListing($sEconetPath,$aDirectoryListing);

	/**
	 * Creates a directory
	 *
	 * @param object user $oUser The user who is performing the create dir operation
	 * @param string $sCsd The current selected directory
	 * @parma string $sEconetPath The econet path for the directory that needs creating (this can be a relative path to the CSD)
	*/
	public static function createDirectory($oUser,$sCsd,$sEconetPath);

	/**
	 * Deletes a file
	 *
	 * @param object user $oUser The user who is deleting the file
	 * @param string $sCsd The current selected directory
	 * @param string $sEconetPath The path of the file to delete (this can be a relative path to the CSD)
	*/
	public static function deleteFile($oUser,$sCsd,$sEconetPath);

	/**
	 * Moves a file from one location to another
	 *
	 * @param object user $oUser The user who is moving the file
	 * @param string $sCsd The current selected directory
	 * @param string $sEconetPathFrom The file to move (this can be a relative path to the CSD)
	 * @param string $sEconetPathTo The path to move the file to (this can be a relative path to the CSD)
	*/
	public static function moveFile($oUser,$sCsd,$sEconetPathFrom,$sEconetPathTo);

	/**
	 * Saves a file 
	 *
	 * @param object user $oUser The user who is saving the file
	 * @param string $sCsd The current selected directory
	 * @param string $sEconetPath The file path to save the file to (this can be a relative path to the CSD)
	 * @param string $sData The data to save in the file (binary string)
	 * @param int $iLoadAddr The load address for the file
	 * @param int $iExecAddr The execute address for the file
	*/
	public static function saveFile($oUser,$sCsd,$sEconetPath,$sData,$iLoadAddr,$iExecAddr);

	/**
	 * Creates and empty file
	 *
	 * @param object user $oUser The user who is saving the file
	 * @param string $sCsd The current selected directory
	 * @param string $sEconetPath The path of the file to be created (this can be a relative path to the CSD)
	 * @param int $iSize The size of the file in bytes
	 * @param int $iLoadAddr The load address for the file
	 * @param int $iExecAddr The execute address for the file
	*/
	public static function createFile($oUser,$sCsd,$sEconetPath,$iSize,$iLoadAddr,$iExecAddr);

	/**
	 * Gets the data from a file
	 *
	 * @param object user $oUser The user who is saving the file
	 * @param string $sCsd The current selected directory
	 * @param string $sEconetPath The file path to read (this can be a relative path to the CSD)
	 * @return string Binary string containing the data stored in the file
	*/
	public static function getFile($oUser,$sCsd,$sEconetPath);

	/**
	 * Set the metadata for a given file
	 *
	 * @param string $sEconetPath The path to the file that is having its metadata set
	 * @param int $iLoadAddr The load address for the file
	 * @param int $iExecAddr The execute address for the file
	 * @param int $iAccess The access mode
	*/
	public static function setMeta($sEconetPath,$iLoad,$iExec,$iAccess);

	public static function fsFtell($oUser,$fLocalHandle);

	public static function fsFStat($oUser,$fLocalHandle);

	public static function isEof($oUser,$fLocalHandle);

	public static function setPos($oUser,$fLocalHandle,$iPos);
	
	public static function read($oUser,$fLocalHandle,$iLength);

	public static function write($oUser,$fLocalHandle,$sData);

	public static function fsClose($oUser,$fLocalHandle);
}
