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

}
