<?php
/**
 * This file contains the fileserver CLI command handler
 *
 * @author John Brown <john@home-lan.co.uk>
 * @package core
*/
namespace HomeLan\FileStore\Services\Provider\FileServer;

use HomeLan\FileStore\Encapsulation\EncapsulationInterface;
use HomeLan\FileStore\Services\Provider\FileServer;
use HomeLan\FileStore\Services\Provider\PrintServer\PrinterRegistry;
use HomeLan\FileStore\Services\ServiceDispatcher;
use HomeLan\FileStore\Messages\EconetPacket;
use HomeLan\FileStore\Messages\FsRequest;

use config;
use Exception;

/**
 * Decodes and dispatches "*" CLI commands (EC_FS_FUNC_CLI) received by the
 * file server, delegating the actual work either to methods here (for CLI
 * commands with no function-code equivalent) or back onto the FileServer
 * provider (for commands also reachable via a raw FS function code).
 *
 * @package core
*/
class Cli {

	/** @var array<int,string> */
	protected array $aCommands = ['BYE', 'CAT', 'CDIR', 'DELETE', 'DIR', 'FSOPT', 'INFO', 'I AM', 'LIB', 'LOAD', 'LOGOFF', 'OPT', 'PASS', 'PRINTER', 'RENAME', 'SAVE', 'SDISC', 'NEWUSER', 'PRIV', 'REMUSER', 'SETPASS', 'i.' ,'i .', 'CHROOTOFF', 'CHROOT', 'BACKUP', 'COMPACT', 'VERIFY', 'MAP', 'COPY', 'TYPE', 'DUMP'];

	public function __construct(private readonly FileServer $oProvider)
	{
	}

	/**
	 * Decodes the cli request
	 *
	 * Once the decode is complete the decoded request is passedto the runCli method
	 *
	 * @param fsrequest $oFsRequest
	*/
	public function cliDecode(FsRequest $oFsRequest): void
	{
		$sCommand = null;
  		$sData = $oFsRequest->getData();
		$aDataAs8BitInts = unpack('C*',(string) $sData);
		if($aDataAs8BitInts === false){
			$aDataAs8BitInts = [];
		}
		$sDataAsString = "";
		foreach($aDataAs8BitInts as $iChar){
			$sDataAsString = $sDataAsString.chr(is_int($iChar) ? $iChar : 0);
		}

		$this->oProvider->getLogger()->debug("Command: ".$sDataAsString.".");

		foreach($this->aCommands as $sCommand){
			$iPos = stripos($sDataAsString,(string) $sCommand);
			if($iPos===0){
				//Found cli command found
				$iOptionsPos = $iPos+strlen((string) $sCommand);
				$sOptions = substr($sDataAsString,$iOptionsPos);
				$this->runCli($oFsRequest,$sCommand,trim($sOptions));
				return;
			}
		}
		$oReply = $oFsRequest->buildReply();
		$oReply->UnrecognisedOk();
		$oReply->appendString($sDataAsString);
		$this->oProvider->addReplyToBuffer($oReply->buildEconetpacket());
	}

	/**
	 * This method runs the cli command, or delegate to an approriate method
	 *
	 * @param fsrequest $oFsRequest The fsrequest
	 * @param string $sCommand The command to run
	 * @param string $sOptions The command arguments
	*/
	public function runCli(FsRequest $oFsRequest,string $sCommand,string $sOptions): void
	{
		switch(strtoupper($sCommand)){
				case 'BYE':
			case 'LOGOFF':
				$this->oProvider->logout($oFsRequest);
				break;
			case 'I AM':
			case 'I .':
				$this->oProvider->login($oFsRequest,$sOptions);
				break;
			case 'PASS':
				$this->oProvider->getUserAdmin()->setPassword($oFsRequest,$sOptions);
				break;
			case 'CAT':
				$this->oProvider->getCatalog()->cat($oFsRequest,$sOptions);
				break;
			case 'CDIR':
				$this->oProvider->getCatalog()->createDirectory($oFsRequest,$sOptions);
				break;
			case 'DELETE':
				$this->oProvider->getCatalog()->deleteFile($oFsRequest,$sOptions);
				break;
			case 'DIR':
				$this->oProvider->getCatalog()->changeDirectory($oFsRequest,$sOptions);
				break;
			case 'FSOPT':
				$this->cliFsopt($oFsRequest, $sOptions);
				break;
			case 'INFO':
			case 'I.':
				$this->oProvider->getCatalog()->cmdInfo($oFsRequest,$sOptions);
				break;
			case 'LIB':
				$this->oProvider->getCatalog()->changeLibrary($oFsRequest,$sOptions);
				break;
			case 'LOAD':
				$this->cliLoad($oFsRequest,$sOptions);
				break;
			case 'OPT':
				$this->oProvider->getUserAdmin()->cliOpt($oFsRequest,$sOptions);
				break;
			case 'RENAME':
				$this->oProvider->getCatalog()->renameFile($oFsRequest,$sOptions);
				break;
			case 'SAVE':
				$oReply = $oFsRequest->buildReply();
				$oReply->setError(0x8f,"Use SAVE function code");
				$this->oProvider->addReplyToBuffer($oReply->buildEconetpacket());
				break;
			case 'SDISC':
				$this->oProvider->sDisc($oFsRequest,$sOptions);
				break;
			case 'PRIV':
				$this->oProvider->getUserAdmin()->privUser($oFsRequest,$sOptions);
				break;
			case 'NEWUSER':
				$this->oProvider->createUser($oFsRequest,$sOptions);
				break;
			case 'REMUSER':
				$this->oProvider->removeUser($oFsRequest,$sOptions);
				break;
			case 'SETPASS':
				$this->cliSetPass($oFsRequest,$sOptions);
				break;
			case 'CHROOT':
				$this->oProvider->chroot($oFsRequest,$sOptions);
				break;
			case 'CHROOTOFF':
				$this->oProvider->chrootoff($oFsRequest,$sOptions);
				break;
			case 'PRINTER':
				$this->cliPrinter($oFsRequest, $sOptions);
				break;
			case 'BACKUP':
			case 'COMPACT':
			case 'VERIFY':
			case 'MAP':
				$this->cliNotSupported($oFsRequest, strtoupper($sCommand));
				break;
			case 'COPY':
				$this->cliCopy($oFsRequest, $sOptions);
				break;
			case 'TYPE':
				$this->cliType($oFsRequest, $sOptions);
				break;
			case 'DUMP':
				$this->cliDump($oFsRequest, $sOptions);
				break;
			default:
				$this->oProvider->getLogger()->debug("Un-handled command ".$sCommand);
				$oReply = $oFsRequest->buildReply();
				$oReply->setError(0x99,"Un-implemented command");
				$this->oProvider->addReplyToBuffer($oReply->buildEconetpacket());
				break;
		}
	}

	/**
	 * Implements *LOAD via the CLI broadcast path.
	 * Streams file data to the configured econet data port.
	*/
	public function cliLoad(FsRequest $oFsRequest, string $sOptions): void
	{
		$aParts = preg_split('/\s+/',trim($sOptions),2);
		if($aParts === false){
			$aParts = [];
		}
		$sPath  = $aParts[0] ?? '';

		$oReply = $oFsRequest->buildReply();
		if(strlen($sPath) === 0){
			$oReply->setError(0xff,"Syntax");
			$this->oProvider->addReplyToBuffer($oReply->buildEconetpacket());
			return;
		}

		$oUser = $this->oProvider->secGetUser($oFsRequest->getSourceNetwork(),$oFsRequest->getSourceStation());
		if(!is_object($oUser)){
			$oReply->setError(0xbf,"Who are you?");
			$this->oProvider->addReplyToBuffer($oReply->buildEconetpacket());
			return;
		}

		$iDataPort = config::getValueAsInt('econet_data_stream_port');

		try {
			$sFileData = $this->oProvider->vfsGetFile($oFsRequest->getSourceNetwork(),$oFsRequest->getSourceStation(),$sPath);
			$oMeta     = $this->oProvider->vfsGetMeta($oFsRequest->getSourceNetwork(),$oFsRequest->getSourceStation(),$sPath);
		}catch(Exception){
			$oReply->setError(0x99,"No such file");
			$this->oProvider->addReplyToBuffer($oReply->buildEconetpacket());
			return;
		}

		$oReply->DoneOk();
		$oReply->append32bitIntLittleEndian($oMeta->getLoadAddr() ?? 0);
		$oReply->append32bitIntLittleEndian($oMeta->getExecAddr() ?? 0);
		$oReply->append24bitIntLittleEndian($oMeta->getSize());
		$oReply->appendByte($oMeta->getAccess());
		$oReply->appendRaw($oMeta->getCTime());
		$oReplyEconetPacket = $oReply->buildEconetpacket();
		$this->oProvider->addReplyToBuffer($oReplyEconetPacket);

		$oServiceDispatcher = $this->oProvider->getServiceDispatcher();
		$_this = $this->oProvider;

		if($oServiceDispatcher === null){
			$this->oProvider->getLogger()->error("FileServer: no ServiceDispatcher registered — cannot stream file data");
			return;
		}

		$oServiceDispatcher->addAckEvent(
			$oFsRequest->getSourceNetwork(),
			$oFsRequest->getSourceStation(),
			$oReplyEconetPacket->getSequence(),
			function() use ($_this, $sFileData, $oFsRequest, $iDataPort, $oServiceDispatcher){
				$sBlock = substr((string) $sFileData,0,256);
				$sFileData = substr((string) $sFileData,256);

				$oEconetPacket = new EconetPacket();
				$oEconetPacket->setDestinationNetwork($oFsRequest->getSourceNetwork());
				$oEconetPacket->setDestinationStation($oFsRequest->getSourceStation());
				$oEconetPacket->setPort($iDataPort);
				$oEconetPacket->setFlags(0);
				$oEconetPacket->setData($sBlock);
				$_this->addReplyToBuffer($oEconetPacket);
				$iSentSeq = $oEconetPacket->getSequence();
				$oServiceDispatcher->sendPackets($_this);

				$cAckHandler = function(EncapsulationInterface $oAckPacket, FileServer $_this, FsRequest $oFsRequest, ServiceDispatcher $oServiceDispatcher, string $sFileData, int $iDataPort, \Closure &$cAckHandler): void {
					if(strlen($sFileData)>0){
						$sBlock = substr($sFileData,0,256);
						$sFileData = substr($sFileData,256);

						$oEconetPacket = new EconetPacket();
						$oEconetPacket->setDestinationNetwork($oFsRequest->getSourceNetwork());
						$oEconetPacket->setDestinationStation($oFsRequest->getSourceStation());
						$oEconetPacket->setPort($iDataPort);
						$oEconetPacket->setFlags(0);
						$oEconetPacket->setData($sBlock);
						$_this->addReplyToBuffer($oEconetPacket);
						$oServiceDispatcher->addAckEvent($oFsRequest->getSourceNetwork(),$oFsRequest->getSourceStation(),$oEconetPacket->getSequence(),function(EncapsulationInterface $oAckPacket) use ($_this, $oFsRequest, $oServiceDispatcher, $sFileData, $iDataPort, $cAckHandler){
							($cAckHandler)($oAckPacket, $_this, $oFsRequest, $oServiceDispatcher, $sFileData, $iDataPort, $cAckHandler);
						});
						$oServiceDispatcher->sendPackets($_this);
					}else{
						$oReply2 = $oFsRequest->buildReply();
						$oReply2->DoneOk();
						$_this->addReplyToBuffer($oReply2->buildEconetpacket());
						$oServiceDispatcher->sendPackets($_this);
					}
				};

				$oServiceDispatcher->addAckEvent($oFsRequest->getSourceNetwork(),$oFsRequest->getSourceStation(),$iSentSeq,function(EncapsulationInterface $oAckPacket) use ($cAckHandler, $_this, $oFsRequest, $oServiceDispatcher, $sFileData, $iDataPort){
					($cAckHandler)($oAckPacket, $_this, $oFsRequest, $oServiceDispatcher, $sFileData, $iDataPort, $cAckHandler);
				});
			}
		);
	}

	/**
	 * CLI *SETPASS — sysop password reset for another user
	 *
	 * Syntax: *SETPASS USERNAME NEWPASSWORD
	*/
	public function cliSetPass(FsRequest $oFsRequest, string $sOptions): void
	{
		$oReply = $oFsRequest->buildReply();
		$oMyUser = $this->oProvider->secGetUser($oFsRequest->getSourceNetwork(),$oFsRequest->getSourceStation());
		if(!is_object($oMyUser)){
			$oReply->setError(0xbf,"Who are you?");
			$this->oProvider->addReplyToBuffer($oReply->buildEconetpacket());
			return;
		}
		if(!$oMyUser->isAdmin()){
			$oReply->setError(0xff,"Only user with priv S can use *SETPASS");
			$this->oProvider->addReplyToBuffer($oReply->buildEconetpacket());
			return;
		}
		$aOptions = explode(' ', trim($sOptions), 2);
		if(count($aOptions) !== 2 || strlen($aOptions[0]) < 1 || strlen($aOptions[1]) < 1){
			$oReply->setError(0xff,"Syntax: SETPASS USERNAME NEWPASSWORD");
			$this->oProvider->addReplyToBuffer($oReply->buildEconetpacket());
			return;
		}
		$sUsername   = trim($aOptions[0]);
		$sNewPassword = trim($aOptions[1]);
		try {
			$this->oProvider->secSetAdminPassword($oFsRequest->getSourceNetwork(),$oFsRequest->getSourceStation(),$sUsername,$sNewPassword);
			$oReply->DoneOk();
		}catch(Exception $oException){
			$oReply->setError(0xff,$oException->getMessage());
		}
		$this->oProvider->addReplyToBuffer($oReply->buildEconetpacket());
	}

	/**
	 * Replies "unrecognised" with a message for CLI commands that are
	 * recognised but not implemented on this server (*BACKUP, *COMPACT,
	 * *VERIFY, *MAP).
	*/
	private function cliNotSupported(FsRequest $oFsRequest, string $sCommand): void
	{
		$oReply = $oFsRequest->buildReply();
		if(!is_object($this->oProvider->secGetUser($oFsRequest->getSourceNetwork(), $oFsRequest->getSourceStation()))){
			$oReply->setError(0xbf, "Who are you?");
			$this->oProvider->addReplyToBuffer($oReply->buildEconetpacket());
			return;
		}
		$oReply->UnrecognisedOk();
		$oReply->appendString("\r*" . $sCommand . " is not supported on this server\r");
		$oReply->appendByte(0x80);
		$this->oProvider->addReplyToBuffer($oReply->buildEconetpacket());
	}

	/**
	 * Implements the *FSOPT CLI command. With no argument, or "INFO", replies
	 * with the disc name and the number of users currently online; any other
	 * argument replies with "not supported".
	*/
	public function cliFsopt(FsRequest $oFsRequest, string $sOptions): void
	{
		$oReply = $oFsRequest->buildReply();
		if(!is_object($this->oProvider->secGetUser($oFsRequest->getSourceNetwork(), $oFsRequest->getSourceStation()))){
			$oReply->setError(0xbf, "Who are you?");
			$this->oProvider->addReplyToBuffer($oReply->buildEconetpacket());
			return;
		}
		$sOpt = strtoupper(trim($sOptions));
		if($sOpt === '' || $sOpt === 'INFO'){
			$sDiscName    = config::getValueAsString('vfs_disc_name');
			$aUsersOnline = $this->oProvider->secGetUsersOnline();
			$iUsersOnline = count($aUsersOnline);
			$sText  = "\r";
			$sText .= "Server disc: " . $sDiscName . "\r";
			$sText .= "Users online: " . $iUsersOnline . "\r";
			$sText .= "\r";
			$oReply->UnrecognisedOk();
			$oReply->appendString($sText);
			$oReply->appendByte(0x80);
		} else {
			$oReply->UnrecognisedOk();
			$oReply->appendString("\rOption not supported on this server\r");
			$oReply->appendByte(0x80);
		}
		$this->oProvider->addReplyToBuffer($oReply->buildEconetpacket());
	}

	/**
	 * Implements the *COPY CLI command: reads a source file and writes it
	 * back out under the destination path, preserving its load/exec
	 * addresses. Syntax: COPY <source> <dest>.
	*/
	public function cliCopy(FsRequest $oFsRequest, string $sOptions): void
	{
		$oReply = $oFsRequest->buildReply();
		if(!is_object($this->oProvider->secGetUser($oFsRequest->getSourceNetwork(), $oFsRequest->getSourceStation()))){
			$oReply->setError(0xbf, "Who are you?");
			$this->oProvider->addReplyToBuffer($oReply->buildEconetpacket());
			return;
		}
		$aParts = preg_split('/\s+/', trim($sOptions), 2);
		if($aParts === false){
			$oReply->setError(0xff, "Syntax: COPY <source> <dest>");
			$this->oProvider->addReplyToBuffer($oReply->buildEconetpacket());
			return;
		}
		if(count($aParts) !== 2 || strlen($aParts[0]) === 0 || strlen($aParts[1]) === 0){
			$oReply->setError(0xff, "Syntax: COPY <source> <dest>");
			$this->oProvider->addReplyToBuffer($oReply->buildEconetpacket());
			return;
		}
		$sSrc = trim($aParts[0]);
		$sDst = trim($aParts[1]);
		try {
			$sData = $this->oProvider->vfsGetFile($oFsRequest->getSourceNetwork(), $oFsRequest->getSourceStation(), $sSrc);
			$oMeta = $this->oProvider->vfsGetMeta($oFsRequest->getSourceNetwork(), $oFsRequest->getSourceStation(), $sSrc);
			$this->oProvider->vfsSaveFile($oFsRequest->getSourceNetwork(), $oFsRequest->getSourceStation(), $sDst, $sData, $oMeta->getLoadAddr(), $oMeta->getExecAddr());
			$oReply->DoneOk();
		}catch(\Exception $oException){
			$oReply->setError(0xff, $oException->getMessage());
		}
		$this->oProvider->addReplyToBuffer($oReply->buildEconetpacket());
	}

	/**
	 * Implements the *TYPE CLI command: replies with the raw contents of a
	 * file as text. Syntax: TYPE <filename>.
	*/
	public function cliType(FsRequest $oFsRequest, string $sOptions): void
	{
		$oReply = $oFsRequest->buildReply();
		if(!is_object($this->oProvider->secGetUser($oFsRequest->getSourceNetwork(), $oFsRequest->getSourceStation()))){
			$oReply->setError(0xbf, "Who are you?");
			$this->oProvider->addReplyToBuffer($oReply->buildEconetpacket());
			return;
		}
		$sPath = trim($sOptions);
		if(strlen($sPath) === 0){
			$oReply->setError(0xff, "Syntax: TYPE <filename>");
			$this->oProvider->addReplyToBuffer($oReply->buildEconetpacket());
			return;
		}
		try {
			$sData = $this->oProvider->vfsGetFile($oFsRequest->getSourceNetwork(), $oFsRequest->getSourceStation(), $sPath);
			$oReply->UnrecognisedOk();
			$oReply->appendString("\r" . $sData);
			if(strlen($sData) === 0 || $sData[-1] !== "\r"){
				$oReply->appendString("\r");
			}
			$oReply->appendByte(0x80);
		}catch(\Exception){
			$oReply->setError(0xff, "No such file");
		}
		$this->oProvider->addReplyToBuffer($oReply->buildEconetpacket());
	}

	/**
	 * Implements the *DUMP CLI command: replies with a hex/ASCII dump of a
	 * file's contents, 16 bytes per line with an offset column. Syntax:
	 * DUMP <filename>.
	*/
	public function cliDump(FsRequest $oFsRequest, string $sOptions): void
	{
		$oReply = $oFsRequest->buildReply();
		if(!is_object($this->oProvider->secGetUser($oFsRequest->getSourceNetwork(), $oFsRequest->getSourceStation()))){
			$oReply->setError(0xbf, "Who are you?");
			$this->oProvider->addReplyToBuffer($oReply->buildEconetpacket());
			return;
		}
		$sPath = trim($sOptions);
		if(strlen($sPath) === 0){
			$oReply->setError(0xff, "Syntax: DUMP <filename>");
			$this->oProvider->addReplyToBuffer($oReply->buildEconetpacket());
			return;
		}
		try {
			$sData   = $this->oProvider->vfsGetFile($oFsRequest->getSourceNetwork(), $oFsRequest->getSourceStation(), $sPath);
			$iLen    = strlen($sData);
			$sOutput = "\r";
			for($iOffset = 0; $iOffset < $iLen; $iOffset += 16){
				$sLine  = sprintf('%06X  ', $iOffset);
				$sAscii = '';
				for($i = 0; $i < 16; $i++){
					if($iOffset + $i < $iLen){
						$iByte   = ord($sData[$iOffset + $i]);
						$sLine  .= sprintf('%02X ', $iByte);
						$sAscii .= ($iByte >= 0x20 && $iByte < 0x7f) ? chr($iByte) : '.';
					} else {
						$sLine  .= '   ';
						$sAscii .= ' ';
					}
					if($i === 7){
						$sLine .= ' ';
					}
				}
				$sOutput .= $sLine . $sAscii . "\r";
			}
			$oReply->UnrecognisedOk();
			$oReply->appendString($sOutput);
			$oReply->appendByte(0x80);
		}catch(\Exception){
			$oReply->setError(0xff, "No such file");
		}
		$this->oProvider->addReplyToBuffer($oReply->buildEconetpacket());
	}

	/**
	 * Implements the *PRINTER CLI command. With no argument, lists the
	 * enabled printers; with a printer name, replies whether that printer
	 * exists, is enabled, and the caller is allowed to use it.
	*/
	public function cliPrinter(FsRequest $oFsRequest, string $sOptions): void
	{
		$oReply = $oFsRequest->buildReply();
		if (!is_object($this->oProvider->secGetUser($oFsRequest->getSourceNetwork(), $oFsRequest->getSourceStation()))) {
			$oReply->setError(0xbf, "Who are you?");
			$this->oProvider->addReplyToBuffer($oReply->buildEconetpacket());
			return;
		}

		$sPrinterName = strtoupper(trim($sOptions));
		$oRegistry    = $this->oProvider->getFileServerPrinterRegistry();

		if ($sPrinterName === '') {
			// List all enabled printers
			$aEnabled = $oRegistry->getEnabled();
			$sOutput  = "\r";
			foreach ($aEnabled as $oPrinter) {
				$sOutput .= sprintf("%-6s  %s\r", $oPrinter->getName(), $oPrinter->getDescription());
			}
			$oReply->UnrecognisedOk();
			$oReply->appendString($sOutput);
			$oReply->appendByte(0x80);
			$this->oProvider->addReplyToBuffer($oReply->buildEconetpacket());
			return;
		}

		$oPrinter = $oRegistry->getByName($sPrinterName);
		if ($oPrinter === null) {
			$oReply->setError(0xff, "Unknown printer " . $sPrinterName);
			$this->oProvider->addReplyToBuffer($oReply->buildEconetpacket());
			return;
		}
		if (!$oPrinter->isEnabled()) {
			$oReply->setError(0xff, "Printer " . $sPrinterName . " is not available");
			$this->oProvider->addReplyToBuffer($oReply->buildEconetpacket());
			return;
		}
		$oUser = $this->oProvider->secGetUser($oFsRequest->getSourceNetwork(), $oFsRequest->getSourceStation());
		if (!$oPrinter->isUserAllowed($oUser)) {
			$oReply->setError(0xff, "You are not authorised to use printer " . $sPrinterName);
			$this->oProvider->addReplyToBuffer($oReply->buildEconetpacket());
			return;
		}
		$oReply->UnrecognisedOk();
		$oReply->appendString("\r" . $sPrinterName . " is available\r");
		$oReply->appendByte(0x80);
		$this->oProvider->addReplyToBuffer($oReply->buildEconetpacket());
	}

}
