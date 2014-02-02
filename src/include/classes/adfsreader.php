<?

class adfsreader {

	const TRACKS_PER_SIDE = 80;
	const SECTORS_PER_TRACK = 16;
	const SECTOR_SIZE = 256;
	const FILE_NAME_LEN = 10;	
	const METADATA_SIZE = 26;

	protected $sDiskImageRaw = NULL;

	protected $sImagePath = NULL;

	protected $sTitle = NULL;

	protected $aCatalogue = NULL;

	protected $bInterleaved = TRUE;

	public function __construct($sPath,$sDiskImage=NULL)
	{
		if(!is_null($sDiskImage)){
			$this->sDiskImageRaw = $sDiskImage;
		}
		$this->sImagePath = $sPath;
	}

	protected function _getSectorRaw($iSector)
	{
		if($this->bInterleaved){
			$iTrack = floor($iSector/self::SECTORS_PER_TRACK);
			if ($iTrack < (self::TRACKS_PER_SIDE-1)){
				$iStart = (2 * $iTrack * self::SECTORS_PER_TRACK * self::SECTOR_SIZE) + (($iSector - (self::SECTORS_PER_TRACK*$iTrack)) * self::SECTOR_SIZE) ;
			}else{
				$iStart = (self::SECTOR_SIZE * self::SECTORS_PER_TRACK) + (2 * ($iTrack - self::TRACKS_PER_SIDE) * self::SECTORS_PER_TRACK * self::SECTOR_SIZE) + (($iSector - (self::SECTORS_PER_TRACK*$iTrack)) * self::SECTOR_SIZE) ;
			}
		}else{
			$iStart = self::SECTOR_SIZE * $iSector;
		}

		if(!is_null($this->sDiskImageRaw)){
			return substr($this->sDiskImageRaw,$iStart,self::SECTOR_SIZE);
		}else{
			$iFileHandle = fopen($this->sImagePath,'r');
			fseek($iFileHandle,$iStart);
			$sBytes = fread($iFileHandle,self::SECTOR_SIZE);
			fclose($iFileHandle);
			return $sBytes;
		}
		
	}

	protected function _getSectorAsByteArray($iSector)
	{
		$iStart = ($iSector*self::SECTOR_SIZE);
		return unpack('C*',$this->_getSectorRaw($iSector));
	}

	protected function _getSectorsAsByteArray($iStartSector,$iCount)
	{
		$iStart = ($iStartSector*self::SECTOR_SIZE);
		$sBlock = "";
		for($i=0;$i<$iCount;$i++){
			$sBlock .= $this->_getSectorRaw($iStartSector+$i);
		}
		return unpack('C*',$sBlock);
	}

	protected function _getSectorsRaw($iStartSector,$iCount)
	{
		$iStart = ($iStartSector*self::SECTOR_SIZE);
		$sBlock = "";
		for($i=0;$i<$iCount;$i++){
			$sBlock .= $this->_getSectorRaw($iStartSector+$i);
		}
		return $sBlock;
	}

	protected function _decode7bit($iByte)
	{
		return $iByte & 127;
	}

	protected function _decode32bitAddr($iByte1,$iByte2,$iByte3,$iByte4)
	{
		return ($iByte4 << 24) + ($iByte3 << 16) + ($iByte2 << 8) + $iByte1;
	}

	protected function _decode24bitAddr($iByte1,$iByte2,$iByte3)
	{
		return ($iByte3 << 16) + ($iByte2 << 8) + $iByte1;
	}

	/**
	 * Reads block from the filing system 
	 * 
	 * Starts and the begining of a sector then reads a set number of bytes 
	 * @param int $iStartSector
	 * @param int $iLen
	 * @return string
	*/
	public function getBlocks($iStartSector,$iLen)
	{
		$iSectors = floor($iLen/self::SECTOR_SIZE);
		$iRemainder = $iLen - $iSectors*self::SECTOR_SIZE;
		$sBlock = $this->_getSectorsRaw($iStartSector,$iSectors);
		
		$sBlock .= substr($this->_getSectorRaw($iStartSector+$iSectors),0,$iRemainder);
		return $sBlock;
	}

	/**
	 * Extracts the disc catalogue 
	 *
	 * A dfs image is diveded up into sectors the disk catalogue is stored in the first 2 sectors (00,01), the file names and directory names are all stored
	 * in sector 00, while the metadata for the file is stored in sector 01.
	 *
	 * @return array The array is in the format array('dir'=>array('filename'=>array('loadaddr'=>,'execaddr'=>,'size'=>,'startsector'=>)));
	*/
	public function getCatalogue($iStartSector=2,$bUseCache=TRUE)
	{
		if(is_null($this->aCatalogue) OR !$bUseCache){
			$aCat = array();
			
			$aSectors = $this->_getSectorsAsByteArray($iStartSector,5);
			for($i=0;$i<47;$i++){
				//The filemeta data start 5 bytes in
				$iStart = 5+($i*self::METADATA_SIZE);
				
				//Grab a block of file metadata
				$aMeta = array_slice($aSectors,$iStart,self::METADATA_SIZE);

				//Decode the file name
				$sFileName = '';
				if($aMeta[0]==0){
					//We hit the last entry 
					break;
				}
				for($x=0;$x<self::FILE_NAME_LEN;$x++){
					if($this->_decode7bit($aMeta[$x])!==13 ){
						$sFileName .=chr($this->_decode7bit($aMeta[$x]));
					}else{
						//File name are terminated with the ascii char cr (13)
						break;
					}
				}

				//The high bit of the 4th byte of the file name is used to denote if an entry is a directory
				if($aMeta[3] & 128){
					$sType = "dir";
				}else{
					$sType = "file";
				}

				//Decode Load Addr
				$iLoad = $this->_decode32bitAddr($aMeta[0x0a],$aMeta[0x0b],$aMeta[0x0c],$aMeta[0x0d]);

				//Decode Exec Addr
				$iExec = $this->_decode32bitAddr($aMeta[0x0e],$aMeta[0x0f],$aMeta[0x10],$aMeta[0x11]);

				//Decode Size
				$iSize = $this->_decode32bitAddr($aMeta[0x12],$aMeta[0x13],$aMeta[0x14],$aMeta[0x15]);

				//Decode Start Sector
				$iSector = $this->_decode24bitAddr($aMeta[0x16],$aMeta[0x17],$aMeta[0x18]);
				$aCat[$sFileName]=array('load'=>$iLoad,'exec'=>$iExec,'size'=>$iSize,'startsector'=>$iSector,'type'=>$sType);

				//Recurse the directory tree
				if($sType=='dir'){
					$aCat[$sFileName]['dir']=$this->getCatalogue($iSector,FALSE);
				}
			}
			if($bUseCache){
				$this->aCatalogue = $aCat;
			}else{
				return $aCat;
			}
		}
		return $this->aCatalogue;
	}

	/**
	 * Gets a file from the filing system
	 *
	 * The file name supplied can be read from the root dir of the image or a subdir, the filename supplied
	 * may be a full file path to access files in a subdir
	 * @param string $sFileName
	 * @return string
	*/
	public function getFile($sFileName){
		$aCat = $this->getCatalogue();
		$aParts = explode('.',$sFileName);
		foreach($aParts as $sPart){
			$aKeys = array_keys($aCat);
			$bFound = FALSE;
			foreach($aKeys as $sTestKey){
				if(strtolower($sTestKey)==strtolower($sPart)){
					$bFound=TRUE;
					break;
				}
			}
			if($bFound){
				if($aCat[$sTestKey]['type']=='file'){
					return $this->getBlocks($aCat[$sTestKey]['startsector'],$aCat[$sTestKey]['size']);
				}
				if($aCat[$sTestKey]['type']=='dir'){
					$aCat = $aCat[$sTestKey];
				}
			}
		}
	}

	public function getStat($sFileName)
	{
		$aCat = $this->getCatalogue();
		$aParts = explode('.',$sFileName);
		foreach($aParts as $sPart){
			$aKeys = array_keys($aCat);
			$bFound = FALSE;
			foreach($aKeys as $sTestKey){
				if(strtolower($sTestKey)==strtolower($sPart)){
					$bFound=TRUE;
					break;
				}
			}
			if($bFound){
				if($aCat[$sTestKey]['type']=='file'){
					return array('size'=>$aCat[$sTestKey]['size'],'sector'=>$aCat[$sTestKey]['startsector']);
				}
				if($aCat[$sTestKey]['type']=='dir'){
					$aCat = $aCat[$sTestKey];
				}
			}
		}
	}

	public function isFile($sFileName)
	{
		$aCat = $this->getCatalogue();
		$aParts = explode('.',$sFileName);
		$iParts = count($aParts);
		
		foreach($aParts as $iIndex => $sPart){
				$aKeys = array_keys($aCat);
				$bFound = FALSE;
				foreach($aKeys as $sTestKey){
					if(strtolower($sTestKey)==strtolower($sPart)){
						$bFound=TRUE;
						break;
					}
				}
				if($iIndex+1 == $iParts){
					//last entry 
				
					if($bFound){
						if($aCat[$sTestKey]['type']=='file'){
							return TRUE;
						}
					}
				}
				if($bFound){
					if($aCat[$sPart]['type']=='dir'){
						$aCat = $aCat[$sTestKey];
					}else{
						return FALSE;
					}
				}
		}
		return FALSE;
	}

	public function isDir($sFileName)
	{
		$aCat = $this->getCatalogue();
		$aParts = explode('.',$sFileName);
		$iParts = count($aParts);
		foreach($aParts as $iIndex => $sPart){
			$aKeys = array_keys($aCat);
			$bFound = FALSE;
			foreach($aKeys as $sTestKey){
				if(strtolower($sTestKey)==strtolower($sPart)){
					$bFound=TRUE;
					break;
				}
			}if($iIndex+1 == $iParts){
				//last entry 
				if($bFound){
					if($aCat[$sTestKey]['type']=='dir'){
						return TRUE;
					}
				}
			}
			if($bFound){
				if($aCat[$sPart]['type']=='dir'){
					$aCat = $aCat[$sTestKey];
				}else{
					return FALSE;
				}
			}
		}
		return FALSE;
	}
}

?>
