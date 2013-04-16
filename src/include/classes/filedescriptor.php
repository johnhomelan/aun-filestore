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

	protected $fLocalHandle = NULL;

	protected $oUser = NULL;

	protected $sFilePath = NULL;

	protected $sVfsPlugin = NULL;

	protected $iVfsHandle = NULL;

	protected $bFile = NULL;

	protected $bDir = NULL;

	public function __construct($sVfsPlugin,$oUser,$sUnixFilePath,$sEconetFilePath,$iEconetHandel,$iVfsHandle,$bFile=FALSE,$bDir=FALSE)
	{
		$this->sVfsPlugin = $sVfsPlugin;
		$this->oUser = $oUser;
		$this->sUnixFilePath = $sUnixFilePath;
		$this->sEconetFilePath = $sEconetFilePath;
		$this->iVfsHandle = $iVfsHandle;
		$this->bFile = $bFile;
		$this->bDir = $bDir;
	}


	public function getID()
	{
		return $this->iHandle;
	}

	public function fsFTell()
	{
		if(!is_null($this->fLocalHandle)){
			$sPlugin = $this->sVfsPlugin;
			return $sPlugin::fsFTell($this->oUser,$this->fLocalHandle);
		}
		
	}

	public function fsFStat()
	{
		if(!is_null($this->fLocalHandle)){
			$sPlugin = $this->sVfsPlugin;
			return $sPlugin::fsFStat($this->oUser,$this->fLocalHandle);
		}
	}

	public function close()
	{
		if(!is_null($this->fLocalHandle)){
			$sPlugin = $this->sVfsPlugin;
			return $sPlugin::close($this->oUser,$this->fLocalHandle);
		}
	}
}
?>
