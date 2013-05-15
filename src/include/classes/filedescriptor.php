<?
/**
 * This file contains the file descriptor class
 *
 * @author John Brown <john@home-lan.co.uk>
 * @package corevfs
*/

/** 
 * Econet NetFs uses filedecriptor ids with its client for all file operations.  The file handles 
 * identitier is a single byte.  This class represents a single file description for the server 
 * and maps to a local file handle and remote user.
 *
 * @package corevfs
*/
class filedescriptor {

	protected $iHandle = NULL;

	protected $oUser = NULL;

	protected $sFilePath = NULL;

	protected $sVfsPlugin = NULL;

	protected $iVfsHandle = NULL;
	
	protected $sUnixFilePath = NULL;

	protected $sEconetFilePath = NULL;

	protected $bFile = NULL;

	protected $bDir = NULL;

	public function __construct($sVfsPlugin,$oUser,$sUnixFilePath,$sEconetFilePath,$iVfsHandle,$iEconetHandle,$bFile=FALSE,$bDir=FALSE)
	{
		$this->sVfsPlugin = $sVfsPlugin;
		$this->oUser = $oUser;
		$this->sUnixFilePath = $sUnixFilePath;
		$this->sEconetFilePath = $sEconetFilePath;
		$this->iVfsHandle = $iVfsHandle;
		$this->iHandle = $iEconetHandle;
		$this->bFile = $bFile;
		$this->bDir = $bDir;
	}

	public function getEconetPath()
	{
		return $this->sEconetFilePath;
	}

	public function getEconetDirName()
	{
		if(strpos($this->sEconetFilePath,'.')!==FALSE){
			$aParts = explode('.',$this->sEconetFilePath);
			return array_pop($aParts);
		}elseif(strlen($this->sEconetFilePath)>0){
			return $this->sEconetFilePath;
		}else{
			return '$';
		}
	}

	public function getEconetParentPath()
	{
		//Build the path with out the last dir
		$aPathParts = explode('.',$this->sEconetFilePath);
		$sParentPath = "";
		for($i=0;$i<(count($aPathParts)-1);$i++){
			$sParentPath = $sParentPath.$aPathParts[$i].".";
		}
		if(strlen($sParentPath)>0){
			//Our parent is not $ return the parent path but trim the trailing .
			return trim($sParentPath,'.');
		}
		return '$';
	}

	public function changeVfs()
	{
		$aPlugins = vfs::getVfsPlugins();
		$iIndex = array_search($this->sVfsPlugin,$aPlugins);
		$this->iVfsHandle = NULL;
		if($iIndex!==FALSE){
			$iIndex--;
			if(!array_key_exists($iIndex,$aPlugins)){
				throw new VfsException("No vfs pluings left to try",TRUE);
			}
			$sPlugin = $aPlugins[$iIndex];
			logger::log("filedescriptor: Changing vfsplugin to ".$sPlugin." due to softerror from ".$this->sVfsPlugin,LOG_DEBUG);

			$this->sVfsPlugin = $sPlugin;
			$sUnixPath = $sPlugin::_getUnixPathFromEconetPath($this->sEconetFilePath);
				
			if(strlen($sUnixFilePath)<1){
				//This vfs module can't process the econetpath try the next
				$this->changeVfs();
			}
			$this->sUnixFilePath=$sUnixPath;
			$this->iVfsHandle = $sPlugin::_getHandleFromEconetPath($this->sEconetFilePath);
			
		}
	}


	public function getID()
	{
		return $this->iHandle;
	}
	
	public function isDir()
	{
		return $this->bDir;
	}

	public function fsFTell()
	{
		if(!is_null($this->iVfsHandle)){
			$sPlugin = $this->sVfsPlugin;
			try {
				return $sPlugin::fsFTell($this->oUser,$this->iVfsHandle);
			}catch(VfsException $oVfsException){
				if($oVfsException->isHard()){
					throw $oVfsException;
				}
				$this->changeVfs();
				return $this->fsTell();
			}
		}
		
	}

	public function fsFStat()
	{
		if(!is_null($this->iVfsHandle)){
	//		try {
				$sPlugin = $this->sVfsPlugin;
				return $sPlugin::fsFStat($this->oUser,$this->iVfsHandle);
	//		}catch(VfsException $oVfsException){
	//			if($oVfsException->isHard()){
	//				throw $oVfsException;
	//			}
	//			$this->changeVfs();
	//			return $this->fsTell();
	//		}
		}
	}

	public function isEof()
	{
		if(!is_null($this->iVfsHandle)){
			try {
				$sPlugin = $this->sVfsPlugin;
				return $sPlugin::isEof($this->oUser,$this->iVfsHandle);
			}catch(VfsException $oVfsException){
				if($oVfsException->isHard()){
					throw $oVfsException;
				}
				$this->changeVfs();
				return $this->isEof();
			}
		}

	}

	public function setPos($iPos)
	{
		if(!is_null($this->iVfsHandle)){
			try {
				$sPlugin = $this->sVfsPlugin;
				return $sPlugin::setPos($this->oUser,$this->iVfsHandle,$iPos);
			}catch(VfsException $oVfsException){
				if($oVfsException->isHard()){
					throw $oVfsException;
				}
				$this->changeVfs();
				return $this->setPos($this->oUser,$this->iVfsHandle,$iPos);
			}
		}

	}

	public function read($iLength)
	{
		if(!is_null($this->iVfsHandle)){
			try {
				$sPlugin = $this->sVfsPlugin;
				return $sPlugin::read($this->oUser,$this->iVfsHandle,$iLength);
			}catch(VfsException $oVfsException){
				var_dump($oVfsException);
				if($oVfsException->isHard()){
					throw $oVfsException;
				}
				$this->changeVfs();
				return $this->read($this->oUser,$this->iVfsHandle,$iLength);
			}
		}

	}

	public function write($sData)
	{
		if(!is_null($this->iVfsHandle)){
			try {
				$sPlugin = $this->sVfsPlugin;
				return $sPlugin::write($this->oUser,$this->iVfsHandle,$sData);
			}catch(VfsException $oVfsException){
				var_dump($oVfsException);
				if($oVfsException->isHard()){
					throw $oVfsException;
				}
				$this->changeVfs();
				return $this->write($this->oUser,$this->iVfsHandle,$sData);
			}
		}

	}

	public function close()
	{
		if(!is_null($this->iVfsHandle)){
			$sPlugin = $this->sVfsPlugin;
			return $sPlugin::fsClose($this->oUser,$this->iVfsHandle);
		}
	}
}
?>
