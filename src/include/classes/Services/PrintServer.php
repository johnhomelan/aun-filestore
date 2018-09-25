<?php
/**
 * This file contains the printserver class
 *
 * @author John Brown <john@home-lan.co.uk>
 * @package core
*/
namespace HomeLan\FileStore\Services; 

use HomeLan\FileStore\Authentication\Security; 

use config;
/**
/**
 * This class implements the econet printserver
 *
 * @package core
*/
class PrintServer {

	protected $oMainApp = NULL ;
	
	protected $aReplyBuffer = array();

	protected $aPrintBuffer = array();

	protected $oLogger;

	/**
	 * Initializes the service
	 *
	*/
	public function __construct(\Psr\Log\LoggerInterface $oLogger)
	{
		$this->oLogger = $oLogger;
	}


	protected function _addReplyToBuffer($oReply)
	{
		$this->aReplyBuffer[]=$oReply;
	}

	public function init(\HomeLan\FileStore\Command\Filestore $oMainApp)
	{
		$this->oMainApp = $oMainApp;
	}


	/**
	 * Retreives all the reply objects built by the fileserver 
	 *
	 * This method removes the replies from the buffer 
	*/
	public function getReplies()
	{
		$aReplies = $this->aReplyBuffer;
		$this->aReplyBuffer = array();
		return $aReplies;
	}

	/**
	 * This method handles print enquires
	 *
	 * @param object fsrequest $oEquiry
	*/
	public function processEnquiry($oEnquiry)
	{
		$sPrinterName = $oEnquiry->getString(1,6);
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

	public function processData($oPrintData)
	{
		$oReply = $oPrintData->buildReply();
		if($oPrintData->getLen()==1 AND $oPrintData->getByte(1)==0){
			$oReply->appendByte(0);
			$this->_addReplyToBuffer($oReply);
			//Spool started create buffer
			$this->oLogger->info("Station ".$oPrintData->getSourceNetwork().":".$oPrintData->getSourceStation()." started a print job");
			if(!array_key_exists($oPrintData->getSourceNetwork(),$this->aPrintBuffer)){
				$this->aPrintBuffer[$oPrintData->getSourceNetwork()]=array();
			}

			$this->aPrintBuffer[$oPrintData->getSourceNetwork()][$oPrintData->getSourceStation()]=array('data'=>'','began'=>time());
			
		}else{
			//Add bytes to print buffer
			if(!array_key_exists($oPrintData->getSourceNetwork(),$this->aPrintBuffer)){
				$this->aPrintBuffer[$oPrintData->getSourceNetwork()]=array();
			}
			if(!array_key_exists($oPrintData->getSourceStation(),$this->aPrintBuffer[$oPrintData->getSourceNetwork()])){
				$this->aPrintBuffer[$oPrintData->getSourceNetwork()][$oPrintData->getSourceStation()]=array('data'=>'','began'=>time());
			}
			$this->aPrintBuffer[$oPrintData->getSourceNetwork()][$oPrintData->getSourceStation()]['data'] .= $oPrintData->getString(1,$oPrintData->getLen());
			if($oPrintData->getByte($oPrintData->getLen())==3){
				//Print job has ended
				$this->oLogger->info("Station ".$oPrintData->getSourceNetwork().":".$oPrintData->getSourceStation()." print job completed");
				if(is_dir(config::getValue('print_server_spool_dir'))){
					$oUser = Security::getUser($oPrintData->getSourceNetwork(),$oPrintData->getSourceStation());
					if(is_object($oUser)){
						$sSpoolPath = config::getValue('print_server_spool_dir').DIRECTORY_SEPARATOR.$oUser->getUsername();
					}else{
						$sSpoolPath = config::getValue('print_server_spool_dir').DIRECTORY_SEPARATOR.'anon-'.$oPrintData->getSourceNetwork().'-'.$oPrintData->getSourceStation();
					}
					if(!is_dir($sSpoolPath)){
						mkdir($sSpoolPath);
					}
					file_put_contents($sSpoolPath.DIRECTORY_SEPARATOR.date('H-i-s-d-n-Y').'.raw',$this->aPrintBuffer[$oPrintData->getSourceNetwork()][$oPrintData->getSourceStation()]['data']);
				}else{
					$this->oLogger->info("Un-able to save print out as the spool directory does not exist (".config::getValue('print_server_spool_dir').")");
				}
				unset($this->aPrintBuffer[$oPrintData->getSourceNetwork()][$oPrintData->getSourceStation()]);
			}

			$oReply->appendByte(0);
			$this->_addReplyToBuffer($oReply);
		}
		
		
	}
}
