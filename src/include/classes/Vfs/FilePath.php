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
		return $this->sDir.'.'.$this->sFile;
	}
}
