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

	protected int $iAccess = 15;

	public function __construct(protected string $sEconetName,protected string $sUnixName,protected string $sVfsPlugin,protected ?int $iLoadAddr,protected ?int $iExecAddr,protected int $iSize,protected string $sEconetFullFilePath,protected int $iCTime,string $sMode, protected bool $bDir=FALSE)
	{
		$this->setAccessByStr($sMode);
	}

	public function getVfsPlugin(): string
	{
		return $this->sVfsPlugin;
	}

	public function getEconetName(): string
	{
		return $this->sEconetName;
	}

	public function getUnixName(): string
	{
		return $this->sUnixName;
	}

	public function setLoadAddr(?int $iLoadAddr): void
	{
		$this->iLoadAddr = $iLoadAddr;
	}

	public function getLoadAddr(): ?int
	{
		return $this->iLoadAddr;
	}

	public function setExecAddr(?int $iExecAddr): void
	{
		$this->iExecAddr = $iExecAddr;
	}

	public function getExecAddr(): ?int
	{
		return $this->iExecAddr;
	}

	public function getSize(): int
	{
		return $this->iSize;
	}

	public function setAccessByStr(string $sAccess): void
	{
		$iMode = 0;
		if(str_starts_with($sAccess, 'w')){
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

	public function setAccess(int $iAccess): void
	{
		$this->iAccess = $iAccess;
	}

	public function getAccess(): int
	{
		return $this->iAccess;
	}

	public function getCTime(): string
	{
		//Add current date
		$iDay = (int) date('j',$this->iCTime);
		$sDate = pack('C',$iDay);
		//The last byte is month and year, first 4 bits year, last 4 bits month
		$iYear = (int) date('y',$this->iCTime);
		$iMonthYear = ($iYear << 4) + (int) date('n',$this->iCTime);
		$sDate = $sDate.pack('C',$iMonthYear);
		return $sDate;
	}

	public function setCTime(int $iDataTime): void
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

	public function isDir(): bool
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
