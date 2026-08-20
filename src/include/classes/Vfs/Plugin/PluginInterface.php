<?php

namespace HomeLan\FileStore\Vfs\Plugin;

/**
 * This file contains interface all vfs plugins must implement
 *
*/
use HomeLan\FileStore\Vfs\FilePath;
use HomeLan\FileStore\Vfs\FileDescriptor;
use HomeLan\FileStore\Authentication\User;

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
	public static function init(\Psr\Log\LoggerInterface $oLogger, bool $bMultiuser = false): void;

	/**
	 * Called regually to perform any house keeping tasks
	 *
	 * e.g. Clean up any file handler for users who have logged out
	*/
	public static function houseKeeping(): void;

	public static function _buildFiledescriptorFromEconetPath(User $oUser,FilePath $oEconetPath,bool $bMustExist,bool $bReadOnly,bool $bDirectory=false): ?FileDescriptor;

	public static function _getAccessMode(int $iGid,int $iUid,int $iMode): string;

	/**
	 * Takes an array of files and adds all the files from the econet file path
	 *
	 * The plugin could also remove files from the array
	 *
	 * @param string $sEconetPath The econet file path
	 * @param array<string,\HomeLan\FileStore\Vfs\DirectoryEntry> $aDirectoryListing An array of file data
	 * @return array<string,\HomeLan\FileStore\Vfs\DirectoryEntry>
	*/
	public static function getDirectoryListing(string $sEconetPath,array $aDirectoryListing): array;

	/**
	 * Creates a directory
	 *
	 * @param User $oUser The user who is performing the create dir operation
	 * @param FilePath $oPath
	*/
	public static function createDirectory(User $oUser,FilePath $oPath): bool;

	/**
	 * Deletes a file
	 *
	 * @param User $oUser The user who is deleting the file
	 * @param FilePath $oEconetPath The path of the file to delete
	*/
	public static function deleteFile(User $oUser,FilePath $oEconetPath): bool;

	/**
	 * Moves a file from one location to another
	 *
	 * @param User $oUser The user who is moving the file
	 * @param FilePath $oEconetPathFrom The file to move
	 * @param FilePath $oEconetPathTo The path to move the file to
	*/
	public static function moveFile(User $oUser,FilePath $oEconetPathFrom,FilePath $oEconetPathTo): bool;

	/**
	 * Saves a file
	 *
	 * @param User $oUser The user who is saving the file
	 * @param FilePath $oEconetPath The file path to save the file to
	 * @param string $sData The data to save in the file (binary string)
	 * @param int $iLoadAddr The load address for the file
	 * @param int $iExecAddr The execute address for the file
	 * @return bool|void Plugins that support writing return a bool success flag; read-only plugins that cannot support saving return void (falsy to callers).
	*/
	public static function saveFile(User $oUser,FilePath $oEconetPath,string $sData,int $iLoadAddr,int $iExecAddr);

	/**
	 * Creates and empty file
	 *
	 * @param User $oUser The user who is saving the file
	 * @param FilePath $oEconetPath The path of the file to be created
	 * @param int $iSize The size of the file in bytes
	 * @param int $iLoadAddr The load address for the file
	 * @param int $iExecAddr The execute address for the file
	 * @return bool|void Plugins that support writing return a bool success flag; read-only plugins that cannot support creating return void (falsy to callers).
	*/
	public static function createFile(User $oUser,FilePath $oEconetPath,int $iSize,int $iLoadAddr,int $iExecAddr);

	/**
	 * Gets the data from a file
	 *
	 * @param User $oUser The user who is saving the file
	 * @param FilePath $oEconetPath The file path to read
	 * @return string Binary string containing the data stored in the file
	*/
	public static function getFile(User $oUser,FilePath $oEconetPath): string;

	/**
	 * Set the metadata for a given file
	 *
	 * @param string $sEconetPath The path to the file that is having its metadata set
	 * @param ?int $iLoad The load address for the file, null leaves it unchanged
	 * @param ?int $iExec  The execute address for the file, null leaves it unchanged
	 * @param int $iAccess The access mode
	*/
	public static function setMeta(string $sEconetPath,?int $iLoad,?int $iExec,?int $iAccess): void;

	/**
	 * @param mixed $fLocalHandle The plugin-local handle (int for image/array-backed plugins, resource for stream-backed plugins)
	 * @return int|false Current position in the file, or false on error
	*/
	public static function fsFtell(User $oUser,mixed $fLocalHandle);

	/**
	 * @param mixed $fLocalHandle The plugin-local handle (int for image/array-backed plugins, resource for stream-backed plugins)
	 * @return array<mixed>|false
	*/
	public static function fsFStat(User $oUser,mixed $fLocalHandle);

	public static function isEof(User $oUser,mixed $fLocalHandle): bool;

	/**
	 * @param mixed $fLocalHandle The plugin-local handle (int for image/array-backed plugins, resource for stream-backed plugins)
	 * @return bool|int Return value is not consumed by any caller; plugins may use either convention.
	*/
	public static function setPos(User $oUser,mixed $fLocalHandle,int $iPos);

	public static function setExt(User $oUser,mixed $fLocalHandle,int $iExt): void;

	/**
	 * @param mixed $fLocalHandle The plugin-local handle (int for image/array-backed plugins, resource for stream-backed plugins)
	 * @return string|false
	*/
	public static function read(User $oUser,mixed $fLocalHandle,int $iLength);

	/**
	 * @param mixed $fLocalHandle The plugin-local handle (int for image/array-backed plugins, resource for stream-backed plugins)
	 * @return int|false|void Return value is not consumed by any caller; plugins may return bytes written, nothing, or throw for unsupported writes.
	*/
	public static function write(User $oUser,mixed $fLocalHandle,string $sData);

	/**
	 * Acquire a file-level advisory lock on an open handle.
	 * $bExclusive=true for write (exclusive), false for read (shared).
	 * Plugins that do not support native locking should provide a no-op.
	 */
	public static function fsLock(User $oUser,mixed $fLocalHandle,bool $bExclusive): void;

	/**
	 * Release a file-level advisory lock previously acquired with fsLock.
	 * Plugins that do not support native locking should provide a no-op.
	 */
	public static function fsUnlock(User $oUser,mixed $fLocalHandle): void;

	/**
	 * @param mixed $fLocalHandle The plugin-local handle (int for image/array-backed plugins, resource for stream-backed plugins)
	 * @return bool|void
	*/
	public static function fsClose(User $oUser,mixed $fLocalHandle);
}
