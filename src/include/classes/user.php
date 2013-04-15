<?

/**
 * File containing the user class
 *
 * @package coreauth
*/

/**
 * This class represents the user 
 * 
 * @package coreauth
 * @author John Brown <john@home-lan.co.uk>
*/
class user {

	protected $sUsername = NULL;
	
	protected $iUnixUid = NULL;

	protected $sHomedir = NULL;

	protected $iOpt = NULL;

	public function setUsername($sUsername)
	{
		$this->sUsername=$sUsername;
	}

	public function getUsername()
	{
		return $this->sUsername;
	}

	public function setUnixUid($iUid)
	{
		$this->iUnixUid = $iUid;
	}

	public function getUnixUid()
	{
		return $this->iUnixUid;
	}

	public function setHomedir($sDir)
	{
		$this->sHomedir = $sDir;
	}

	public function getHomedir()
	{
		return $this->sHomedir;
	}

	public function setBootOpt($iOpt)
	{
		$this->iOpt = $iOpt;
	}

	public function getBootOpt()
	{
		return $this->iOpt;
	}
}
?>
