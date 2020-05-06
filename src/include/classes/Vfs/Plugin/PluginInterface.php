<?php

namespace HomeLan\FileStore\Vfs\Plugin; 

/**
 * This file contains interface all vfs plugins must implement
 *
*/
use HomeLan\FileStore\Vfs\FilePath;

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
	 *
	 * @param boolean $bMultiuser
	*/
	public static function init(\Psr\Log\LoggerInterface $oLogger, bool $bMultiuser = false);

	/**
	 * Called regually to perform any house keeping tasks
	 * 
	 * e.g. Clean up any file handler for users who have logged out
	*/
	public static function houseKeeping();

	public static function _buildFiledescriptorFromEconetPath($oUser,FilePath $oEconetPath,$bMustExist,$bReadOnly);

	public static function _getAccessMode($iGid,$iUid,$iMode);

	/**
	 * Takes an array of files and adds all the files from the econet file path
	 *
	 * The plugin could also remove files from the array
	 *
	 * @param string $sEconetPath The econet file path
	 * @param array $aDirectoryListing An array of file data
	*/
	public static function getDirectoryListing(string $sEconetPath,array $aDirectoryListing);

	/**
	 * Creates a directory
	 *
	 * @param object user $oUser The user who is performing the create dir operation
	 * @param object FilePath
	*/
	public static function createDirectory($oUser,FilePath $oPath);

	/**
	 * Deletes a file
	 *
	 * @param object user $oUser The user who is deleting the file
	 * @param object FilePath $oEconetPath The path of the file to delete 
	*/
	public static function deleteFile($oUser,FilePath $oEconetPath);

	/**
	 * Moves a file from one location to another
	 *
	 * @param object user $oUser The user who is moving the file
	 * @param object FilePath $oEconetPathFrom The file to move 
	 * @param object FilePath $oEconetPathTo The path to move the file to
	*/
	public static function moveFile($oUser,FilePath $oEconetPathFrom,FilePath $oEconetPathTo);

	/**
	 * Saves a file 
	 *
	 * @param object user $oUser The user who is saving the file
	 * @param object FilePath $oEconetPath The file path to save the file to 
	 * @param string $sData The data to save in the file (binary string)
	 * @param int $iLoadAddr The load address for the file
	 * @param int $iExecAddr The execute address for the file
	*/
	public static function saveFile($oUser,FilePath $oEconetPath,string $sData,int $iLoadAddr,int $iExecAddr);

	/**
	 * Creates and empty file
	 *
	 * @param object user $oUser The user who is saving the file
	 * @param object FilePath $sEconetPath The path of the file to be created
	 * @param int $iSize The size of the file in bytes
	 * @param int $iLoadAddr The load address for the file
	 * @param int $iExecAddr The execute address for the file
	*/
	public static function createFile($oUser,FilePath $oEconetPath,int $iSize,int $iLoadAddr,int $iExecAddr);

	/**
	 * Gets the data from a file
	 *
	 * @param object user $oUser The user who is saving the file
	 * @param object FilePath $oEconetPath The file path to read 
	 * @return string Binary string containing the data stored in the file
	*/
	public static function getFile($oUser,FilePath $oEconetPath): string;

	/**
	 * Set the metadata for a given file
	 *
	 * @param string $sEconetPath The path to the file that is having its metadata set
	 * @param int $iLoadAddr The load address for the file
	 * @param int $iExecAddr The execute address for the file
	 * @param int $iAccess The access mode
	*/
	public static function setMeta(string $sEconetPath,$iLoad,$iExec,int $iAccess);

	public static function fsFtell($oUser,$fLocalHandle);

	public static function fsFStat($oUser,$fLocalHandle);

	public static function isEof($oUser,$fLocalHandle);

	public static function setPos($oUser,$fLocalHandle,$iPos);
	
	public static function read($oUser,$fLocalHandle,$iLength);

	public static function write($oUser,$fLocalHandle,$sData);

	public static function fsClose($oUser,$fLocalHandle);
}
