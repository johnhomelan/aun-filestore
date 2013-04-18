<?

class directoryentry {

	protected $sVfsPlugin = NULL;

	protected $sUnixName = NULL;

	protected $sEconetName = NULL;

	protected $iLoadAddr = NULL;

	protected $iExecAddr = NULL;

	protected $iSize = NULL;

	public function __construct($sEconetName,$sUnixName,$sVfsPlugin,$iLoadAddr,$iExecAddr,$iSize,$bDir=FALSE)
	{
		$this->sEconetName=$sEconetName;
		$this->sUnixName=$sUnixName;
		$this->sVfsPlugin=$sVfsPlugin;
		$this->iLoadAddr=$iLoadAddr;
		$this->iExecAddr=$iExecAddr;
		$this->iSize=$iSize;
		$this->bDir=$bDir;
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
