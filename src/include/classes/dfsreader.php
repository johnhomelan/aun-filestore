<?

class dfsreader {

	const SECTOR_SIZE = 256;
	const FILE_NAME_LEN = 7;
	const METADATA_SIZE = 8;

	protected $sDiskImageRaw = NULL;

	protected $sImagePath = NULL;

	protected $sTitle = NULL;

	protected $aCatalogue = NULL;

	public function __construct($sPath,$sDiskImage=NULL)
	{
		if(!is_null($sDiskImage)){
			$this->sDiskImageRaw = $sDiskImage;
		}
		$this->sImagePath = $sPath;
	}

	protected function _getBytesFromImage($iStart,$iLen)
	{
		if(!is_null($this->sDiskImageRaw)){
			return substr($this->sDiskImageRaw,$iStart,$iLen);
		}else{
			$iFileHandle = fopen($this->sImagePath,'r');
			fseek($iFileHandle,$iStart);
			$sBytes = fread($iFileHandle,$iLen);
			fclose($iFileHandle);
			return $sBytes;
		}
	}

	protected function _getSectorRaw($iSector)
	{
		$iStart = ($iSector*self::SECTOR_SIZE);
		return $this->_getBytesFromImage($iStart,self::SECTOR_SIZE);
	}

	protected function _getSectorAsByteArray($iSector)
	{
		$iStart = $iSector*self::SECTOR_SIZE;
		return unpack('C*',$this->_getBytesFromImage($iStart,self::SECTOR_SIZE));
	}


	protected function _decode18bitAddr($iLowByte,$iMidByte,$iHighByte,$iFirstBit,$iSecondBit)
	{
		$sLowByte = str_pad(decbin($iLowByte),8,"0",STR_PAD_LEFT);
		$sMidByte = str_pad(decbin($iMidByte),8,"0",STR_PAD_LEFT);
		$sHighByte = str_pad(decbin($iHighByte),8,"0",STR_PAD_LEFT);
		$sBin = substr($sHighByte,8-$iSecondBit,1).substr($sHighByte,8-$iFirstBit,1).$sMidByte.$sLowByte;
		return bindec($sBin);
	}

	protected function _decode10bitAddr($iLowByte,$iHighByte,$iFirstBit,$iSecondBit)
	{
		$sLowByte = str_pad(decbin($iLowByte),8,"0",STR_PAD_LEFT);
		$sHighByte = str_pad(decbin($iHighByte),8,"0",STR_PAD_LEFT);
		$sBin = substr($sHighByte,8-$iSecondBit,1).substr($sHighByte,8-$iFirstBit,1).$sLowByte;
		return bindec($sBin);
	}

	protected function _getRawData($iStartSector,$iLength)
	{
		return $this->_getBytesFromImage(($iStartSector*self::SECTOR_SIZE),$iLength);
	}

	/**
	 * A dfs disc has a title, this method grabs that title
	 *
	 * A dfs image is diveded up into sectors the disk title is split up accross the first 2 sectors (00,01)
	 * @return string
	*/
	public function getTitle()
	{
		if(is_null($this->sTitle)){
			//The title is stored in bytes &00 - &07 of sector 00, and bytes &00 to &03 of sector 01 and is right padded with byte 0 
			$aSector0 = $this->_getSectorAsByteArray(0);
			$aSector1 = $this->_getSectorAsByteArray(1);
			$sTitle = '';
			//Extract the first 8 bytes, a the array starts at 1 extract 1-8
			for($i=1;$i<9;$i++){
				if($aSector0[$i]>0){
					$sTitle .=chr($aSector0[$i]);
				}
			}
			//Extract the first 4 bytes, a the array starts at 1 extract 1-4
			for($i=1;$i<5;$i++){
				if($aSector1[$i]>1){
					$sTitle .=chr($aSector1[$i]);
				}
			}
			$this->sTitle = $sTitle;
		}
		return $this->sTitle;
	}

	/**
	 * Extracts the disc catalogue 
	 *
	 * A dfs image is diveded up into sectors the disk catalogue is stored in the first 2 sectors (00,01), the file names and directory names are all stored
	 * in sector 00, while the metadata for the file is stored in sector 01.
	 *
	 * @return array The array is in the format array('dir'=>array('filename'=>array('loadaddr'=>,'execaddr'=>,'size'=>,'startsector'=>)));
	*/
	public function getCatalogue()
	{
		if(is_null($this->aCatalogue)){
			$aCat = array();
			//A dfs catalogue can only hold 31 entries in total, not 31 per directory but 31 total.  
			//Directories in DFS have only a 1 byte name (e.g. A or P), the route directory is $

			//Sector 0 contains all the file names, the first filename starts 8 bytes in, each file name is 7 bytes long, followed by a 1 byte directory name
			//If a filename is less than 7 chars is space padded to the right.  Blank entries in the catalogue are marked with the 0 byte.
			$aSector0 = $this->_getSectorAsByteArray(0);

			//Sector 1 contains all the metadata for the files encoded using an 18bit addressing system
			$aSector1 = $this->_getSectorAsByteArray(1);

			//Step through each of the 31 slots building up the file list
			for($x=0;$x<31;$x++){
				//Starts 8 bytes in and each filename+dir is 8 bytes
				$iSector0Start=9+($x*(self::FILE_NAME_LEN+1));

				//Starts 8 bytes in 
				$iSector1Start=9+($x*(self::METADATA_SIZE));

				//Read the file name 
				$sFileName = '';
				for($i=0;$i<self::FILE_NAME_LEN;$i++){
					if($aSector0[$iSector0Start+$i]>0){
						$sFileName .= chr($aSector0[$iSector0Start+$i]);
					}
				}

				//Read the directory name, and if its vaild read all the other matadata and add the entry in to the cat array
				if($aSector0[$iSector0Start+self::FILE_NAME_LEN]>0){
		
					//Read load addr (stored in the 1st,2nd bytes and the 3rd,4th bits of the 7th byte)
					$iLoadAddr = $this->_decode18bitAddr($aSector1[$iSector1Start],$aSector1[$iSector1Start+1],$aSector1[$iSector1Start+6],3,4);

					//Read exec addr (stored in the 3rd,4th bytes and the 7th,8th bits of the 7th byte)
					$iExecAddr = $this->_decode18bitAddr($aSector1[$iSector1Start+2],$aSector1[$iSector1Start+3],$aSector1[$iSector1Start+6],7,8);
					
					//Read file size (stored in the 5rd,6th bytes and the 5th,6th bits of the 7th byte)
					$iLength = $this->_decode18bitAddr($aSector1[$iSector1Start+4],$aSector1[$iSector1Start+5],$aSector1[$iSector1Start+6],5,6);

					//Read start sector (stored in the 8th byte and he 1st,2nd bits of the 7th byte)
					$iStartSector = $this->_decode10bitAddr($aSector1[$iSector1Start+7],$aSector1[$iSector1Start+6],1,2);

					//Read the dirname
					$sDir = chr($aSector0[$iSector0Start+self::FILE_NAME_LEN]);
					if(!array_key_exists($sDir,$aCat)){
						$aCat[$sDir]=array();
					}
					
					$aCat[$sDir][trim($sFileName)]=array('loadaddr'=>$iLoadAddr,'execaddr'=>$iExecAddr,'size'=>$iLength,'startsector'=>$iStartSector);
				}
			}
			$this->aCatalogue = $aCat;
		}
		return $this->aCatalogue;
	}

	public function getFile($sFilePath){
		$aCat = $this->getCatalogue();
		$aParts = explode('.',$sFilePath);
		if(count($aParts)==2){
			$sDir = $aParts[0];
			$sFileName = $aParts[1];
		}else{
			$sDir = '$';	
			$sFileName = $sFilePath;
		}
		
		if(!array_key_exists($sDir,$aCat)){
			throw new Exception("No such dir ".$sDir);
		}
		if(!array_key_exists($sFileName,$aCat[$sDir])){
			throw new Exception("No such file ".$sFileName);
		}
		return $this->_getRawData($aCat[$sDir][$sFileName]['startsector'],$aCat[$sDir][$sFileName]['size']);
	}

	public function getStat($sFilePath)
	{
		$aCat = $this->getCatalogue();
		$aParts = explode('.',$sFilePath);
		if(count($aParts)==2){
			$sDir = $aParts[0];
			$sFileName = $aParts[1];
		}else{
			$sDir = '$';	
			$sFileName = $sFilePath;
		}
		
		if(!array_key_exists($sDir,$aCat)){
			throw new Exception("No such dir ".$sDir);
		}
		if(!array_key_exists($sFileName,$aCat[$sDir])){
			throw new Exception("No such file ".$sFileName);
		}
		return array('size'=>$aCat[$sDir][$sFile]['size'],'sector'=>$aCat[$sDir][$sFile]['startsector']);
	}

	public function isFile($sFilePath)
	{
		$aCat = $this->getCatalogue();
		$aParts = explode('.',$sFilePath);
		if(count($aParts)==2){
			$sDir = $aParts[0];
			$sFileName = $aParts[1];
			if(array_key_exists($sDir,$aCat) AND array_key_exists($sFileName,$aCat[$sDir])){
				return TRUE;
			}
		}else{
			$sDir = '$';	
			$sFileName = $sFilePath;
			if(array_key_exists($sDir,$aCat) AND array_key_exists($sFileName,$aCat[$sDir])){
				return TRUE;
			}
		}
		return FALSE;
	}

	public function isDir($sFilePath)
	{
		$aCat = $this->getCatalogue();
		$aParts = explode('.',$sFilePath);
		if(count($aParts)==2){
			return FALSE;
		}else{
			$sFileName = $sFilePath;
			if(array_key_exists($sFilePath,$aCat)){
				return TRUE;
			}
		}
		return FALSE;
	}


}
?>
