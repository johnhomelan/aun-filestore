<?php
/**
 * This file contains the file directoryentry class
 *
 * @author John Brown <john@home-lan.co.uk>
 * @package corevfs
*/
namespace HomeLan\FileStore\Vfs; 

/** 
 * This class is used to prepresent a files entry in the directory catalogue
 *
 * @package corevfs
*/
class DirectoryEntry {

	protected $iAccess = 15;

	public function __construct(protected $sEconetName,protected $sUnixName,protected $sVfsPlugin,protected $iLoadAddr,protected $iExecAddr,protected $iSize,protected $sEconetFullFilePath,protected $iCTime,$sMode, protected $bDir=FALSE)
	{
		$this->setAccessByStr($sMode);
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

	public function setLoadAddr($iLoadAddr): void
	{
		$this->iLoadAddr = $iLoadAddr;
	}

	public function getLoadAddr()
	{
		return $this->iLoadAddr;
	}

	public function setExecAddr($iExecAddr): void
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

	public function setAccessByStr($sAccess): void
	{
		$iMode = 0;
		if(str_starts_with((string) $sAccess, 'w')){
			$iMode = $iMode+8;
		}else{
			//Mark unwriteable files as Locked
			$iMode = $iMode+16;
		}
		if(substr((string) $sAccess,1,1)=='r'){
			$iMode = $iMode+4;
		}
		if(substr((string) $sAccess,3,1)=='w'){
			$iMode = $iMode+2;
		}
		if(substr((string) $sAccess,4,1)=='r'){
			$iMode = $iMode+1;
		}
		if($this->isDir()){
			$iMode = $iMode+32;
		}
		$this->iAccess = $iMode;	
	}

	public function setAccess($iAccess): void
	{
		$this->iAccess = $iAccess;
	}

	public function getAccess()
	{
		return $this->iAccess;
	}

	public function getCTime(): string
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

	public function setCTime($iDataTime): void
	{
		$this->iCTime = $iDataTime;
	}

	public function getDay(): string
	{
		return date('j',$this->iCTime);
	}

	public function getMonth(): string
	{
		return date('n',$this->iCTime);
	}

	public function getYear(): string
	{
		return date('y',$this->iCTime);
	}

	public function isDir()
	{
		return $this->bDir;
	}

	public function getSin(): int
	{
		return Vfs::getSin($this->sEconetFullFilePath);
	}

	public function getEconetMode(): string
	{
		$sMode ="";
		if($this->isDir()){
			$sMode=$sMode."D";
		}
		$sMode .= (($this->iAccess & 16) ? 'L' : '');
		$sMode .= (($this->iAccess & 8) ? 'W' : '');
		$sMode .= (($this->iAccess & 4) ? 'R' : '');
		$sMode .= "/";
		$sMode .= (($this->iAccess & 2) ? 'W' : '');
		$sMode .= (($this->iAccess & 1) ? 'R' : '');
		return str_pad(substr($sMode,0,6),6,' ');
	}
}
