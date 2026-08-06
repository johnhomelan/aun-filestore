<?php
/**
 * This file contains the printserver class
 *
 * @author John Brown <john@home-lan.co.uk>
 * @package core
*/
namespace HomeLan\FileStore\Services\Provider; 

use HomeLan\FileStore\Services\ProviderInterface;
use HomeLan\FileStore\Services\Provider\PrintServer\Admin;
use HomeLan\FileStore\Services\ServiceDispatcher;
use HomeLan\FileStore\Authentication\Security; 
use HomeLan\FileStore\Messages\PrintServerEnquiry; 
use HomeLan\FileStore\Messages\PrintServerData; 
use HomeLan\FileStore\Messages\EconetPacket; 
use config;
use React\ChildProcess\Process;
/**
/**
 * This class implements the econet printserver
 *
 * @package core
*/
class PrintServer implements ProviderInterface {

	protected $aReplyBuffer = [];

	protected $aPrintBuffer = [];

	protected $oLogger;

	protected $oLoop;

	/**
	 * Initializes the service
	 *
	*/
	public function __construct(\Psr\Log\LoggerInterface $oLogger)
	{
		$this->oLogger = $oLogger;
	}

	public function getName(): string
	{
		return "Print Server";
	}
	/** 
	 * Gets the admin interface Object for this serivce provider 
	 *
	*/
	public function getAdminInterface(): ?AdminInterface
	{
		return new Admin($this);
	}

	protected function _addReplyToBuffer($oReply): void
	{
		$this->aReplyBuffer[]=$oReply;
	}

	/**
	 * Gets the ports this service uses 
	 * 
	 * @return array of int
	*/
	public function getServicePorts(): array
	{
		return [0x9F, 0xD1];
	}

	/** 
	 * All inbound bridge messages come in via broadcast 
	 *
	*/
	public function broadcastPacketIn(EconetPacket $oPacket): void
	{

	}

	/** 
	 * All inbound bridge messages come in via broadcast, so unicast should ignore them
	 *
	*/
	public function unicastPacketIn(EconetPacket $oPacket): void
	{
		$sPort = $oPacket->getPortName();
		switch($sPort){
			case 'PrinterServerEnquiry':
				$this->processEnquiry(new PrintServerEnquiry($oPacket, $this->oLogger));
				break;
			case 'PrinterServerData':
				$this->processData(new PrintServerData($oPacket));
				break;
		}
	}


	public function registerService(ServiceDispatcher $oServiceDispatcher): void
	{
		$this->oLoop = $oServiceDispatcher->getLoop();	
	}

	/**
	 * Retreives all the reply objects built by the fileserver 
	 *
	 * This method removes the replies from the buffer 
	*/
	public function getReplies(): array
	{
		$aReturn = [];
		foreach($this->aReplyBuffer as $oReply){
			$aReturn[] = $oReply->buildEconetpacket();
		}
		$this->aReplyBuffer = [];
		return $aReturn;
	}

	/**
	 * This method handles print enquires
	 */
	public function processEnquiry(PrintServerEnquiry $oEnquiry): void
	{
		$sPrinterName = substr($oEnquiry->getString(1),0,6);
		$iRequestCode = $oEnquiry->get16bitIntLittleEndian(7);
		$this->oLogger->debug("Printer enquiry for ".$sPrinterName." code ".$iRequestCode);

		$oReply = $oEnquiry->buildReply();

		/*
		Bits 0-2 of the status byte give the status of the client's input to the
		printer via the network. Bits 3-4 give the status of the output from the
		print server to the printer. Bits 5-7 are reserved for future use and
		currently return zero. Currently defined status values are:

		Input
		 0 - Ready
		 1 - Busy
		 2 - Jammed (general software problem)
		 3 - Jammed, due to printer offline (general hardware problem)
		 4 - Jammed, due to disc full, directory full or similar
		 5 - User not authorised to use printer
		 6 - Spooler going offline / operator has barred input
		 7 - Reserved

		Output
		 0 - ready
		 1 - Printer offline
		 2 - Printer jammed (ie has not accepted data for a long time)
	
		So we allways send 0 as the fake printer is always ready 
		*/
		$oReply->append16bitIntLittleEndian(0);
		$this->_addReplyToBuffer($oReply);
	}

	public function processData($oPrintData): void
	{
		$oReply = $oPrintData->buildReply();
		if($oPrintData->getLen()==1 AND $oPrintData->getByte(1)==0){
			$oReply->appendByte(0);
			$this->_addReplyToBuffer($oReply);
			//Spool started create buffer
			$this->oLogger->info("Station ".$oPrintData->getSourceNetwork().":".$oPrintData->getSourceStation()." started a print job");
			if(!array_key_exists($oPrintData->getSourceNetwork(),$this->aPrintBuffer)){
				$this->aPrintBuffer[$oPrintData->getSourceNetwork()]=[];
			}

			$this->aPrintBuffer[$oPrintData->getSourceNetwork()][$oPrintData->getSourceStation()]=['data'=>'', 'began'=>time()];
			
		}else{
			//Add bytes to print buffer
			if(!array_key_exists($oPrintData->getSourceNetwork(),$this->aPrintBuffer)){
				$this->aPrintBuffer[$oPrintData->getSourceNetwork()]=[];
			}
			if(!array_key_exists($oPrintData->getSourceStation(),$this->aPrintBuffer[$oPrintData->getSourceNetwork()])){
				$this->aPrintBuffer[$oPrintData->getSourceNetwork()][$oPrintData->getSourceStation()]=['data'=>'', 'began'=>time()];
			}
			$this->aPrintBuffer[$oPrintData->getSourceNetwork()][$oPrintData->getSourceStation()]['data'] .= $oPrintData->getString(1,$oPrintData->getLen());
			if($oPrintData->getByte($oPrintData->getLen())==3){
				//Print job has ended
				$this->oLogger->info("Station ".$oPrintData->getSourceNetwork().":".$oPrintData->getSourceStation()." print job completed");
				$sSpoolBase = $this->getSpoolDir();
				if($this->isDir($sSpoolBase)){
					$oUser = $this->getUser($oPrintData->getSourceNetwork(),$oPrintData->getSourceStation());
					if(is_object($oUser)){
						$sSpoolPath = $sSpoolBase.DIRECTORY_SEPARATOR.$oUser->getUsername();
					}else{
						$sSpoolPath = $sSpoolBase.DIRECTORY_SEPARATOR.'anon-'.$oPrintData->getSourceNetwork().'-'.$oPrintData->getSourceStation();
					}
					if(!$this->isDir($sSpoolPath)){
						$this->makeDir($sSpoolPath);
					}
					$this->putFile($sSpoolPath.DIRECTORY_SEPARATOR.date('H-i-s-d-n-Y').'.raw',$this->aPrintBuffer[$oPrintData->getSourceNetwork()][$oPrintData->getSourceStation()]['data']);
					$this->convertFile($sSpoolPath.DIRECTORY_SEPARATOR.date('H-i-s-d-n-Y').'.raw');
				}else{
					$this->oLogger->info("Un-able to save print out as the spool directory does not exist (".$sSpoolBase.")");
				}
				unset($this->aPrintBuffer[$oPrintData->getSourceNetwork()][$oPrintData->getSourceStation()]);
			}

			$oReply->appendByte(0);
			$this->_addReplyToBuffer($oReply);
		}
		
		
	}

	protected function getSpoolDir(): string
	{
		return config::getValue('print_server_spool_dir');
	}

	protected function getConvertorScript(): ?string
	{
		return config::getValue('print_server_conversion_script');
	}

	protected function isDir(string $sPath): bool
	{
		return is_dir($sPath);
	}

	protected function getUser(int $iNet, int $iStn)
	{
		return Security::getUser($iNet, $iStn);
	}

	protected function makeDir(string $sPath): void
	{
		mkdir($sPath);
	}

	protected function putFile(string $sPath, string $sData): void
	{
		file_put_contents($sPath, $sData);
	}

	public function getJobs(): array
	{
		$aJobs = [];
		foreach($this->aPrintBuffer as $iNetwork=>$aData){
			foreach($aData as $iStation=>$aBufferInfo){
				$aJobs[] = ['network'=>$iNetwork, 'station'=>$iStation, 'began'=>$aBufferInfo['began'], 'size'=>strlen((string) $aBufferInfo['data'])];
			}
		}
		return $aJobs;
	}

	public function getSpooledFiles(): array
	{
		$aFiles = [];
		$sSpoolBase = $this->getSpoolDir();
		if (!$this->isDir($sSpoolBase)) {
			return $aFiles;
		}
		$aUserDirs = glob($sSpoolBase . DIRECTORY_SEPARATOR . '*', GLOB_ONLYDIR);
		if ($aUserDirs === false) {
			return $aFiles;
		}
		foreach ($aUserDirs as $sUserDir) {
			$sUser = basename($sUserDir);
			$aFileList = glob($sUserDir . DIRECTORY_SEPARATOR . '*');
			if ($aFileList === false) {
				continue;
			}
			foreach ($aFileList as $sFile) {
				if (!is_file($sFile)) {
					continue;
				}
				$iSize = filesize($sFile);
				$iModified = filemtime($sFile);
				$aFiles[] = [
					'user'     => $sUser,
					'filename' => basename($sFile),
					'size'     => $iSize !== false ? $iSize : 0,
					'modified' => $iModified !== false ? $iModified : 0,
					'path'     => $sUser . DIRECTORY_SEPARATOR . basename($sFile),
				];
			}
		}
		return $aFiles;
	}

	public function getSpooledFilePath(string $sRelPath): ?string
	{
		$sSpoolBase = $this->getSpoolDir();
		$sRealBase = realpath($sSpoolBase);
		if ($sRealBase === false) {
			return null;
		}
		// Strip path-traversal sequences before resolving
		$sRelPath = str_replace(["\0", '..'], '', $sRelPath);
		$sFullPath = $sRealBase . DIRECTORY_SEPARATOR . $sRelPath;
		$sRealFull = realpath($sFullPath);
		if ($sRealFull === false || !is_file($sRealFull)) {
			return null;
		}
		// Ensure the resolved path is still within the spool directory
		if (!str_starts_with($sRealFull, $sRealBase . DIRECTORY_SEPARATOR)) {
			return null;
		}
		return $sRealFull;
	}

	/**
 	 * Creates a child process to convert print jobs
 	 * 
 	*/		
	protected function convertFile(string $sPath): void
	{
		//Compute the output file name
		$sDst = str_replace(".raw",",ps",$sPath);

		//Compute the cli to run
		$sCli =  $this->getConvertorScript();
		if(is_null($sCli)){
			return;
		}
		$sCli = str_replace("%soruce%",$sPath,$sCli);
		$sCli = str_replace("%destination%",$sDst,$sCli);

		$oLogger = $this->oLogger;
		//Create the background process to run async (don't hold up the code)
		$oProcess = new Process($sCli);
		$oProcess->on("exit", function() use ($oLogger,$sDst){
			$oLogger->info("Converted print job ".$sDst);
		});
		$oProcess->on("error", function(\Exception $oException) use ($oLogger,$sDst){
			$oLogger->info("Failed to convert print job ".$sDst." with error ".$oException->getMessage());
		});
	}
}
