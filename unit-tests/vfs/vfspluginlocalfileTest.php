<?

/*
 * @group unit-tests
*/

//Need to define this to stop the password file being written to
define('CONFIG_security_mode','singleuser');
define('CONFIG_vfs_plugin_localfile_root',__DIR__.DIRECTORY_SEPARATOR.'testing_root');
include_once('include/system.inc.php');

class authpluginfileTest extends PHPUnit_Framework_TestCase {
	protected $oUser = NULL;
	public function setup()
	{
		//Clean up any files stored in the testing root
		$sPath = config::getValue('vfs_plugin_localfile_root');
		if(file_exists($sPath)){
			system("rm -rf ".$sPath);
		}
		mkdir($sPath);
		$this->oUser = new user();
		$this->oUser->setUsername('createtest');
		$this->oUser->setHomedir('$');
		$this->oUser->setBootOpt(3);
		$this->oUser->setUnixUid(5000);
		$this->oUser->setPriv('u');
	}

	public function tearDown()
	{
		$sPath = config::getValue('vfs_plugin_localfile_root');
		if(file_exists($sPath)){
			system("rm -rf ".$sPath);
		}
	}

	public function buildAndCheckFile($sDir,$sFile,$sData,$iLoadAddr,$iExecAddr)
	{
		vfspluginlocalfile::saveFile($this->oUser,$sDir,$sFile,$sData,$iLoadAddr,$iExecAddr);

		//Checkt the file shows up in a directory listing
		$aDirectoryListing = array();

		//Absolute file
		if(strpos($sFile,'$')===0){
			$aPath = explode('.',$sFile);
			$sFile = array_pop($aPath);
			$sDir = join('.',$aPath);
		}

		try {
			$aDirectoryListing = vfspluginlocalfile::getDirectoryListing($sDir,$aDirectoryListing);	
		}catch(VfsException $oVfsException){	
			if($oVfsException->isHard()){
				throw $oVfsException;
			}
		}
		//Test the meta date was saved correctly
		$this->assertTrue(array_key_exists($sFile,$aDirectoryListing));
		$this->assertEquals($iLoadAddr,hexdec($aDirectoryListing[$sFile]->getLoadAddr()));
		$this->assertEquals($iExecAddr,hexdec($aDirectoryListing[$sFile]->getExecAddr()));
	
		//Check the files content is correct 
		$this->assertEquals($sData,vfspluginlocalfile::getFile($this->oUser,$sDir,$sFile));
	}

	public function buildAndCheckDir($sCsd,$sDir)
	{
		vfspluginlocalfile::createDirectory($this->oUser,$sCsd,$sDir);
		
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
		vfspluginlocalfile::deleteFile($this->oUser,$sDir,$sFile);
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

	public function testFileCreateAbsolutePath()
	{
		$sDir = 'testing';
		$sData = 'hello world';
		$sFile = 'testfile';
		$iLoadAddr = 0xff04;
		$iExecAddr = 0xff9c;
		$this->buildAndCheckDir('$',$sDir);
		$this->assertTrue(is_dir(config::getValue('vfs_plugin_localfile_root').DIRECTORY_SEPARATOR.$sDir));
		
		$this->buildAndCheckFile('$'.$sDir,'$.'.$sFile,$sData,$iLoadAddr,$iExecAddr);
		$this->assertTrue(file_exists(config::getValue('vfs_plugin_localfile_root').DIRECTORY_SEPARATOR.$sFile));
	}
	
	public function testFileCreateRelativePath()
	{
		$sDir = 'testing';
		$sData = 'hello world';
		$sFile = 'testfile';
		$iLoadAddr = 0xff04;
		$iExecAddr = 0xff9c;
		$this->buildAndCheckDir('$',$sDir);
		$this->assertTrue(is_dir(config::getValue('vfs_plugin_localfile_root').DIRECTORY_SEPARATOR.$sDir));
		
		$this->buildAndCheckFile('$',$sDir.'.'.$sFile,$sData,$iLoadAddr,$iExecAddr);
		$this->assertTrue(file_exists(config::getValue('vfs_plugin_localfile_root').DIRECTORY_SEPARATOR.$sDir.DIRECTORY_SEPARATOR.$sFile));
	}

}
?>
