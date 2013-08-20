<?
/**
 * This file contains the file directoryentry class
 *
 * @author John Brown <john@home-lan.co.uk>
 * @package corevfs
*/

/** 
 * This class is used to prepresent a files entry in the directory catalogue
 *
 * @package corevfs
*/
class directoryentry {

	protected $sVfsPlugin = NULL;

	protected $sUnixName = NULL;

	protected $sEconetName = NULL;

	protected $iLoadAddr = NULL;

	protected $iExecAddr = NULL;

	protected $iSize = NULL;

	protected $iAccess = 15;

	protected $iCTime = NULL;

	public function __construct($sEconetName,$sUnixName,$sVfsPlugin,$iLoadAddr,$iExecAddr,$iSize,$bDir=FALSE)
	{
		$this->sEconetName=$sEconetName;
		$this->sUnixName=$sUnixName;
		$this->sVfsPlugin=$sVfsPlugin;
		$this->iLoadAddr=$iLoadAddr;
		$this->iExecAddr=$iExecAddr;
		$this->iSize=$iSize;
		$this->bDir=$bDir;
		$this->iCTime = time();
	}

	public function getVfsPlugin()
	{
		return $this->sVfsPlugin;
	}

	public function getEconetName()
	{
		return $this->sEconetName;
	}

	public function getUnixName()
	{
		return $this->sUnixName;
	}

	public function setLoadAddr($iLoadAddr)
	{
		$this->iLoadAddr = $iLoadAddr;
	}

	public function getLoadAddr()
	{
		return $this->iLoadAddr;
	}

	public function setExecAddr($iExecAddr)
	{
		$this->iExecAddr = $iExecAddr;
	}

	public function getExecAddr()
	{
		return $this->iExecAddr;
	}

	public function getSize()
	{
		return $this->iSize;
	}

	public function setAccess($iAccess)
	{
		$this->iAccess = $iAccess;
	}

	public function getAccess()
	{
		return $this->iAccess;
	}

	public function getCTime()
	{
		//Add current date
		$iDay = date('j',$this->iCTime);
		$sDate = pack('C',$iDay);
		//The last byte is month and year, first 4 bits year, last 4 bits month
		$iYear= date('y',time());
		$iYear << 4;
		$iYear = $iYear+date('n',$this->iCTime);
		$sDate = $sDate.pack('C',$iYear);
		return $sDate;
	}

	public function setCTime($iDataTime)
	{
		$this->iCTime = $iDataTime;
	}

	public function isDir()
	{
		return $this->bDir;
	}

	public function getEconetMode()
	{
		$sMode ="";
		if($this->isDir()){
			$sMode=$sMode."D";
		}
		$sMode=$sMode."WR/r";
		return str_pad(substr($sMode,0,6),6,' ');
	}
}

?>
