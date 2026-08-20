<?php

/*
 * @group unit-tests
*/

//Need to define this to stop the password file being written to
if(!defined('CONFIG_security_mode')){
	define('CONFIG_security_mode','singleuser');
}
if(!defined('CONFIG_vfs_plugin_localfile_root')){
	define('CONFIG_vfs_plugin_localfile_root','/tmp/testing_root-'.uniqid());
}
include_once(__DIR__.'/../../src/include/system.inc.php');

use PHPUnit\Framework\TestCase;
use Monolog\Logger;
use Monolog\Handler\NullHandler;
use HomeLan\FileStore\Authentication\User as user;
use HomeLan\FileStore\Vfs\Plugin\LocalFile as vfspluginlocalfile;
use HomeLan\FileStore\Vfs\FilePath;
use HomeLan\FileStore\Vfs\Exception as VfsException;

class vfspluginlocalfileTest extends TestCase {
	protected $oUser = NULL;
	protected function setup(): void
	{
		$sPath = '/tmp/testing_root-'.uniqid();
		config::overrideValue('vfs_plugin_localfile_root',$sPath);

		//Clean up any files stored in the testing root
		if(file_exists($sPath)){
			system("rm -rf ".$sPath);
		}
		mkdir($sPath);
		$oLogger = new Logger("filestored-unittests");
		$oLogger->pushHandler(new NullHandler());
		vfspluginlocalfile::init($oLogger);
		$this->oUser = new user();
		$this->oUser->setUsername('createtest');
		$this->oUser->setHomedir('$');
		$this->oUser->setBootOpt(3);
		$this->oUser->setUnixUid(5000);
		$this->oUser->setPriv('u');
	}

	protected function tearDown(): void
	{
		$sPath = config::getValue('vfs_plugin_localfile_root');
		if(file_exists($sPath)){
			system("rm -rf ".$sPath);
		}
		config::resetValue('vfs_plugin_localfile_root');
	}

	public function buildAndCheckFile($sDir,$sFile,$sData,$iLoadAddr,$iExecAddr)
	{
		vfspluginlocalfile::saveFile($this->oUser,new FilePath($sDir,$sFile),$sData,$iLoadAddr,$iExecAddr);

		//Checkt the file shows up in a directory listing
		$aDirectoryListing = array();

		//Absolute file
		if(strpos($sFile,'$')===0){
			$aPath = explode('.',$sFile);
			$sFile = array_pop($aPath);
			$sDir = join('.',$aPath);
		}

		try {
			if(strpos($sFile,'.')!==FALSE){
				//Relative file scan the dir the file was created in
				$iLastDot = strrpos($sFile,'.');
				$sRelPath = substr($sFile,0,$iLastDot);
				$sFileName = substr($sFile,$iLastDot+1);
				$aDirectoryListing = vfspluginlocalfile::getDirectoryListing($sDir.'.'.$sRelPath,$aDirectoryListing);
			}else{
				//If the file is none relative check just the selected dir
				$sFileName = $sFile;
				$aDirectoryListing = vfspluginlocalfile::getDirectoryListing($sDir,$aDirectoryListing);	
			}
		}catch(VfsException $oVfsException){	
			if($oVfsException->isHard()){
				throw $oVfsException;
			}
		}
		//Test the meta date was saved correctly
		$this->assertTrue(array_key_exists($sFileName,$aDirectoryListing));
		$this->assertEquals($iLoadAddr,$aDirectoryListing[$sFileName]->getLoadAddr());
		$this->assertEquals($iExecAddr,$aDirectoryListing[$sFileName]->getExecAddr());
	
		//Check the files content is correct 
		$this->assertEquals($sData,vfspluginlocalfile::getFile($this->oUser,new FilePath($sDir,$sFile)));
	}

	public function buildAndCheckDir($sCsd,$sDir)
	{
		vfspluginlocalfile::createDirectory($this->oUser,new FilePath($sCsd,$sDir));
		
	}

	public function testBasicFileCreate()
	{
		$sDir = '$';
		$sData = 'hello world';
		$sFile = 'testfile';
		$iLoadAddr = 0xff04;
		$iExecAddr = 0xff9c;
		$this->buildAndCheckFile($sDir,$sFile,$sData,$iLoadAddr,$iExecAddr);

	}

	public function testFileDelete()
	{
		$sDir = '$';
		$sData = 'hello world';
		$sFile = 'testfile';
		$iLoadAddr = 0xff04;
		$iExecAddr = 0xff9c;
		$this->buildAndCheckFile($sDir,$sFile,$sData,$iLoadAddr,$iExecAddr);
		vfspluginlocalfile::deleteFile($this->oUser,new FilePath($sDir,$sFile));
		$aDirectoryListing = array();	
		try {
			$aDirectoryListing = vfspluginlocalfile::getDirectoryListing($sDir,$aDirectoryListing);	
		}catch(VfsException $oVfsException){	
			if($oVfsException->isHard()){
				throw $oVfsException;
			}
		}

		$this->assertFalse(array_key_exists($sFile,$aDirectoryListing));
	
	}

	public function testCreateDirectory()
	{
		$sDir = 'testing';
		$sData = 'hello world';
		$sFile = 'testfile';
		$iLoadAddr = 0xff04;
		$iExecAddr = 0xff9c;
		$this->buildAndCheckDir('$',$sDir);
		$this->assertTrue(is_dir(config::getValue('vfs_plugin_localfile_root').DIRECTORY_SEPARATOR.$sDir));

		$this->buildAndCheckFile('$.'.$sDir,$sFile,$sData,$iLoadAddr,$iExecAddr);
		$this->assertTrue(file_exists(config::getValue('vfs_plugin_localfile_root').DIRECTORY_SEPARATOR.$sDir.DIRECTORY_SEPARATOR.$sFile));
	}

	public function testFsLockSharedDoesNotBlock()
	{
		$sRoot = config::getValue('vfs_plugin_localfile_root');
		$sUnixPath = $sRoot.DIRECTORY_SEPARATOR.'locktest';
		file_put_contents($sUnixPath,'data');
		$fHandle = fopen($sUnixPath,'r');
		// Shared lock should succeed without blocking
		vfspluginlocalfile::fsLock($this->oUser,$fHandle,false);
		$this->assertTrue(is_resource($fHandle));
		vfspluginlocalfile::fsUnlock($this->oUser,$fHandle);
		fclose($fHandle);
	}

	public function testFsLockExclusiveDoesNotBlock()
	{
		$sRoot = config::getValue('vfs_plugin_localfile_root');
		$sUnixPath = $sRoot.DIRECTORY_SEPARATOR.'locktest2';
		file_put_contents($sUnixPath,'data');
		$fHandle = fopen($sUnixPath,'c+');
		// Exclusive lock should succeed when no other holder exists
		vfspluginlocalfile::fsLock($this->oUser,$fHandle,true);
		$this->assertTrue(is_resource($fHandle));
		vfspluginlocalfile::fsUnlock($this->oUser,$fHandle);
		fclose($fHandle);
	}

	public function testFsUnlockReleasesLock()
	{
		$sRoot = config::getValue('vfs_plugin_localfile_root');
		$sUnixPath = $sRoot.DIRECTORY_SEPARATOR.'locktest3';
		file_put_contents($sUnixPath,'data');
		$fHandle = fopen($sUnixPath,'c+');
		vfspluginlocalfile::fsLock($this->oUser,$fHandle,true);
		vfspluginlocalfile::fsUnlock($this->oUser,$fHandle);
		// After unlock a second handle should be able to acquire LOCK_EX immediately
		$fHandle2 = fopen($sUnixPath,'c+');
		$bGot = flock($fHandle2,LOCK_EX|LOCK_NB);
		if($bGot){ flock($fHandle2,LOCK_UN); }
		fclose($fHandle);
		fclose($fHandle2);
		$this->assertTrue($bGot);
	}

	public function testFsLockOnNullHandleIsNoOp()
	{
		// Passing a non-resource should not throw
		vfspluginlocalfile::fsLock($this->oUser,null,false);
		vfspluginlocalfile::fsUnlock($this->oUser,null);
		$this->assertTrue(true);
	}

	// -----------------------------------------------------------------------
	// _buildFiledescriptorFromEconetPath with $bDirectory=true
	//
	// CSD/URD/LIB handles must only ever resolve to a directory that already
	// exists on disk. Regression coverage for a bug where a missing homedir
	// was silently auto-created as an empty FILE (via fopen 'c+'), which then
	// broke login entirely because opening that same "file" twice (once for
	// URD, once for CSD) tripped the single-writer lock.
	// -----------------------------------------------------------------------

	public function testBuildFiledescriptorDirectoryTrueNeverCreatesAFile()
	{
		$sRoot = config::getValue('vfs_plugin_localfile_root');
		try {
			vfspluginlocalfile::_buildFiledescriptorFromEconetPath($this->oUser,new FilePath('$','MISSINGDIR'),false,false,true);
			$this->fail('Expected a VfsException for a missing directory');
		}catch(VfsException $oVfsException){
			$this->assertSame('No such directory',$oVfsException->getMessage());
		}
		$this->assertFalse(file_exists($sRoot.DIRECTORY_SEPARATOR.'MISSINGDIR'));
	}

	public function testBuildFiledescriptorDirectoryTrueOpensExistingDirectory()
	{
		$sRoot = config::getValue('vfs_plugin_localfile_root');
		mkdir($sRoot.DIRECTORY_SEPARATOR.'REALDIR');

		$oFd = vfspluginlocalfile::_buildFiledescriptorFromEconetPath($this->oUser,new FilePath('$','REALDIR'),false,false,true);
		$this->assertTrue($oFd->isDir());
		$this->assertFalse($oFd->isFile());
	}

	public function testBuildFiledescriptorDirectoryTrueOnExistingFileReportsNotADirectory()
	{
		$sRoot = config::getValue('vfs_plugin_localfile_root');
		file_put_contents($sRoot.DIRECTORY_SEPARATOR.'AFILE',"hello");

		$oFd = vfspluginlocalfile::_buildFiledescriptorFromEconetPath($this->oUser,new FilePath('$','AFILE'),false,false,true);
		$this->assertFalse($oFd->isDir());
		$this->assertTrue($oFd->isFile());
	}

	public function testBuildFiledescriptorDirectoryFalseStillAutoCreatesFile()
	{
		//Unaffected — OPEN-style file creation must keep working when a
		//directory handle was not requested.
		$sRoot = config::getValue('vfs_plugin_localfile_root');
		$oFd = vfspluginlocalfile::_buildFiledescriptorFromEconetPath($this->oUser,new FilePath('$','NEWFILE'),false,false,false);
		$this->assertTrue($oFd->isFile());
		$this->assertTrue(file_exists($sRoot.DIRECTORY_SEPARATOR.'NEWFILE'));
	}

}
