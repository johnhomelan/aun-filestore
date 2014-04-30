<?
/**
 * Read a dfs disk image
 *
*/
class dfsreader {

	const SECTOR_SIZE = 256;
	const FILE_NAME_LEN = 7;
	const METADATA_SIZE = 8;

	protected $sDiskImageRaw = NULL;

	protected $sImagePath = NULL;

	protected $sTitle = NULL;

	protected $aCatalogue = NULL;

	/**
	 * Creates a new instance of the reader
	 *
	 * @param string $sPath The path to the disk image to read
	 * @param string $sDiskImage A binary string of the disk image (don't supplied this and the path a the same time)
	*/
	public function __construct($sPath,$sDiskImage=NULL)
	{
		if(!is_null($sDiskImage)){
			$this->sDiskImageRaw = $sDiskImage;
		}
		$this->sImagePath = $sPath;
	}

	/**
	 * Read abitary byte from the disk image
	 *
	 * @param int $iStart The offset to start reading from
	 * @param it $iLen The number of bytes to read
	*/
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

	/**
	 * Gets a given sector from the image
	 *
	 * @parma int $iSector
	 * @return string
	*/
	protected function _getSectorRaw($iSector)
	{
		$iStart = ($iSector*self::SECTOR_SIZE);
		return $this->_getBytesFromImage($iStart,self::SECTOR_SIZE);
	}

	/**
	 * Gets a given sector from the image as a byte array
	 *
	 * @parma int $iSector
	 * @return array
	*/
	protected function _getSectorAsByteArray($iSector)
	{
		$iStart = $iSector*self::SECTOR_SIZE;
		return unpack('C*',$this->_getBytesFromImage($iStart,self::SECTOR_SIZE));
	}

	/**
	 * Decodes 18bit Address format used by dfs
	 *
	 * DFS stores 3 18bit addrs, and 1 10bit addr, in 7bytes this method helps to decode that data
	 * @param int $iLowByte
	 * @param int $iMidByte
	 * @param int $iHighByte
	 * @param int $iFirstBit
	 * @param int $iSecondBit
	 * @return int
	*/
	protected function _decode18bitAddr($iLowByte,$iMidByte,$iHighByte,$iFirstBit,$iSecondBit)
	{
		switch($iFirstBit){
			case 1:
				$iHighByte = $iHighByte & 3;
				break;
			case 3:
				$iHighByte = $iHighByte & 12;
				$iHighByte = $iHighByte >> 2;
				break;
			case 5:
				$iHighByte = $iHighByte & 48;
				$iHighByte = $iHighByte >> 4;
				break;
			case 7:
				$iHighByte = $iHighByte & 192;
				$iHighByte = $iHighByte >> 6;
				break;
		}

		return ($iHighByte << 16) + ($iMidByte << 8) + $iLowByte;
	}

	/**
	 * Decodes 10bit format used by dfs
	 *
	 * DFS stores 3 18bit addrs, and 1 10bit addr, in 7bytes this method helps to decode that data
	 * @param int $iLowByte
	 * @param int $iHighByte
	 * @param int $iFirstBit
	 * @param int $iSecondBit
	 * @return int
	*/
	protected function _decode10bitAddr($iLowByte,$iHighByte,$iFirstBit,$iSecondBit)
	{
		switch($iFirstBit){
			case 1:
				$iHighByte = $iHighByte & 3;
				break;
			case 3:
				$iHighByte = $iHighByte & 12;
				$iHighByte = $iHighByte >> 2;
				break;
			case 5:
				$iHighByte = $iHighByte & 48;
				$iHighByte = $iHighByte >> 4;
				break;
			case 7:
				$iHighByte = $iHighByte & 192;
				$iHighByte = $iHighByte >> 6;
				break;
		}

		return ($iHighByte << 8) + $iLowByte;
	}

	/**
	 * Gets the raw data from a number of sectors 
	 *
	 * @param int $iStartSector
	 * @param int $iLength
	*/
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

	/**
	 * Gets the raw data from a file
	 *
	 * @param string $sFilePath
	 * @return string
	*/
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
		return array('size'=>$aCat[$sDir][$sFileName]['size'],'sector'=>$aCat[$sDir][$sFileName]['startsector']);
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
