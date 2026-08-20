<?php

namespace HomeLan\FileStore\Vfs; 

class FilePath
{
	public string $sFile;
	public string $sDir;

	public function __construct(string $sDir, string $sFile)
	{
		$this->sFile = $sFile;
		$this->sDir = $sDir;
	}

	public function getFilePath(): string
	{
		//Root (dir empty) and "the directory itself" (file empty) must not gain a
		//stray leading/trailing '.' — that produces malformed paths like ".$" or
		//"$.FOO." which then poison any later path built relative to them.
		if($this->sDir === ''){
			return $this->sFile;
		}
		if($this->sFile === ''){
			return $this->sDir;
		}
		return $this->sDir.'.'.$this->sFile;
	}
}
