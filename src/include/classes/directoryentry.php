<?php
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
	
	protected $sEconetFullFilePath = NULL;

	public function __construct($sEconetName,$sUnixName,$sVfsPlugin,$iLoadAddr,$iExecAddr,$iSize,$bDir=FALSE,$sEconetFullFilePath,$iCTime,$sMode)
	{
		$this->sEconetName=$sEconetName;
		$this->sUnixName=$sUnixName;
		$this->sVfsPlugin=$sVfsPlugin;
		$this->iLoadAddr=$iLoadAddr;
		$this->iExecAddr=$iExecAddr;
		$this->iSize=$iSize;
		$this->bDir=$bDir;
		$this->iCTime = $iCTime;
		$this->sEconetFullFilePath = $sEconetFullFilePath;
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

	public function setAccessByStr($sAccess)
	{
		$iMode = 0;
		if(substr($sAccess,0,1)=='w'){
			$iMode = $iMode+8;
		}else{
			//Mark unwriteable files as Locked
			$iMode = $iMode+16;
		}
		if(substr($sAccess,1,1)=='r'){
			$iMode = $iMode+4;
		}
		if(substr($sAccess,3,1)=='w'){
			$iMode = $iMode+2;
		}
		if(substr($sAccess,4,1)=='r'){
			$iMode = $iMode+1;
		}
		if($this->isDir()){
			$iMode = $iMode+32;
		}
		$this->iAccess = $iMode;	
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

	public function getDay()
	{
		return date('j',$this->iCTime);
	}

	public function getMonth()
	{
		return date('n',$this->iCTime);
	}

	public function getYear()
	{
		return date('y',$this->iCTime);
	}

	public function isDir()
	{
		return $this->bDir;
	}

	public function getSin()
	{
		return vfs::getSin($this->sEconetFullFilePath);
	}

	public function getEconetMode()
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
