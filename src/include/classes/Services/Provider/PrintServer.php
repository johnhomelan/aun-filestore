<?php
/**
 * This file contains the printserver class
 *
 * @author John Brown <john@home-lan.co.uk>
 * @package core
*/
namespace HomeLan\FileStore\Services\Provider;

use HomeLan\FileStore\Services\ProviderInterface;
use HomeLan\FileStore\Services\Provider\AdminInterface;
use HomeLan\FileStore\Services\Provider\PrintServer\Admin;
use HomeLan\FileStore\Services\Provider\PrintServer\PrinterRegistry;
use HomeLan\FileStore\Services\ServiceDispatcher;
use HomeLan\FileStore\Authentication\Security;
use HomeLan\FileStore\Messages\PrintServerEnquiry;
use HomeLan\FileStore\Messages\PrintServerData;
use HomeLan\FileStore\Messages\EconetPacket;
use config;
use React\ChildProcess\Process;

/**
 * This class implements the econet printserver
 *
 * @package core
*/
class PrintServer implements ProviderInterface {

	protected $aReplyBuffer = [];

	protected $aPrintBuffer = [];

	/** [net][stn] => printer name last successfully enquired for by that station */
	protected array $aActivePrinters = [];

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
	 * Gets the admin interface Object for this service provider
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
		if ($oPacket->getPortName() === 'PrinterServerEnquiry') {
			$this->processEnquiry(new PrintServerEnquiry($oPacket, $this->oLogger));
		}
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
	 * Returns the printer registry for this server.
	 * Override in test subclasses to inject a registry from a string.
	*/
	protected function getPrinterRegistry(): PrinterRegistry
	{
		return new PrinterRegistry();
	}

	/**
	 * This method handles print enquiries.
	 *
	 * Looks up the requested printer in the registry.  Sends status 6 (offline)
	 * for a disabled printer, status 5 (not authorised) when the user is not in
	 * the printer's allowed_users list, status 0 (ready) otherwise.  Sends no
	 * reply at all for an unknown printer name.  On success, records the printer
	 * name as the active printer for the station so that subsequent data packets
	 * are routed to the correct queue.
	 */
	public function processEnquiry(PrintServerEnquiry $oEnquiry): void
	{
		$sPrinterName = strtoupper(rtrim(substr($oEnquiry->getString(1), 0, 6), "\x00 \t\n\r"));
		$iRequestCode = $oEnquiry->get16bitIntLittleEndian(7);
		$this->oLogger->debug("Printer enquiry for " . $sPrinterName . " code " . $iRequestCode);

		$oRegistry = $this->getPrinterRegistry();
		$oPrinter  = $oRegistry->getByName($sPrinterName);

		if ($oPrinter === null) {
			// Unknown printer — send no reply
			return;
		}

		$oReply = $oEnquiry->buildReply();

		if (!$oPrinter->isEnabled()) {
			// Status 6 = spooler going offline / operator has barred input
			$oReply->append16bitIntLittleEndian(6);
			$this->_addReplyToBuffer($oReply);
			return;
		}

		$iNet  = $oEnquiry->getSourceNetwork();
		$iStn  = $oEnquiry->getSourceStation();
		$oUser = $this->getUser($iNet, $iStn);

		if (!$oPrinter->isUserAllowed($oUser)) {
			// Status 5 = user not authorised to use printer
			$oReply->append16bitIntLittleEndian(5);
			$this->_addReplyToBuffer($oReply);
			return;
		}

		// Status 0 = ready
		$oReply->append16bitIntLittleEndian(0);
		$this->_addReplyToBuffer($oReply);

		if (!array_key_exists($iNet, $this->aActivePrinters)) {
			$this->aActivePrinters[$iNet] = [];
		}
		$this->aActivePrinters[$iNet][$iStn] = $sPrinterName;
	}

	public function processData($oPrintData): void
	{
		$iNet = $oPrintData->getSourceNetwork();
		$iStn = $oPrintData->getSourceStation();
		$oReply = $oPrintData->buildReply();

		if ($oPrintData->getLen() == 1 && $oPrintData->getByte(1) == 0) {
			// SOH — start of a new print job
			$oReply->appendByte(0);
			$this->_addReplyToBuffer($oReply);
			$this->oLogger->info("Station " . $iNet . ":" . $iStn . " started a print job");

			// Determine which printer this job targets
			$sPrinterName = $this->aActivePrinters[$iNet][$iStn] ?? null;
			if ($sPrinterName === null) {
				$aEnabled     = $this->getPrinterRegistry()->getEnabled();
				$sPrinterName = !empty($aEnabled) ? $aEnabled[0]->getName() : 'PRINT';
			}

			if (!array_key_exists($iNet, $this->aPrintBuffer)) {
				$this->aPrintBuffer[$iNet] = [];
			}
			$this->aPrintBuffer[$iNet][$iStn] = ['data' => '', 'began' => time(), 'printer' => $sPrinterName];
		} else {
			// Mid-job data or end-of-job
			if (!array_key_exists($iNet, $this->aPrintBuffer)) {
				$this->aPrintBuffer[$iNet] = [];
			}
			if (!array_key_exists($iStn, $this->aPrintBuffer[$iNet])) {
				// No prior SOH — create buffer and determine printer
				$sPrinterName = $this->aActivePrinters[$iNet][$iStn] ?? null;
				if ($sPrinterName === null) {
					$aEnabled     = $this->getPrinterRegistry()->getEnabled();
					$sPrinterName = !empty($aEnabled) ? $aEnabled[0]->getName() : 'PRINT';
				}
				$this->aPrintBuffer[$iNet][$iStn] = ['data' => '', 'began' => time(), 'printer' => $sPrinterName];
			}

			$this->aPrintBuffer[$iNet][$iStn]['data'] .= $oPrintData->getString(1, $oPrintData->getLen());

			if ($oPrintData->getByte($oPrintData->getLen()) == 3) {
				// ETX — print job complete
				$this->oLogger->info("Station " . $iNet . ":" . $iStn . " print job completed");

				$sPrinterName = $this->aPrintBuffer[$iNet][$iStn]['printer'];
				$sData        = $this->aPrintBuffer[$iNet][$iStn]['data'];

				$oPrinter = $this->getPrinterRegistry()->getByName($sPrinterName);

				if ($oPrinter !== null && $oPrinter->getBehavior() === 'discard') {
					$this->oLogger->info("Discarding print job for printer " . $sPrinterName);
				} else {
					$sFilePath = $this->getSpoolPath($sPrinterName, $iNet, $iStn);
					if ($sFilePath !== null) {
						$sFile = $sFilePath . DIRECTORY_SEPARATOR . date('H-i-s-d-n-Y') . '.raw';
						$this->putFile($sFile, $sData);
						if ($oPrinter !== null && $oPrinter->getBehavior() === 'script') {
							$sScript = $oPrinter->getScript();
							$this->convertFile($sFile, $sScript !== '' ? $sScript : null);
						} elseif ($oPrinter === null) {
							// Unknown printer at job-end — fall back to global conversion script
							$this->convertFile($sFile, null);
						}
					}
				}

				unset($this->aPrintBuffer[$iNet][$iStn]);
				if (isset($this->aActivePrinters[$iNet][$iStn])) {
					unset($this->aActivePrinters[$iNet][$iStn]);
				}
			}

			$oReply->appendByte(0);
			$this->_addReplyToBuffer($oReply);
		}
	}

	/**
	 * Computes the user-specific spool subdirectory for a print job, creating
	 * the printer and user subdirectories if they do not already exist.
	 *
	 * Returns null when the base spool directory does not exist.
	 *
	 * Path structure: {spool_dir}/{printer_name}/{username_or_anon}/
	*/
	protected function getSpoolPath(string $sPrinterName, int $iNet, int $iStn): ?string
	{
		$sBase = $this->getSpoolDir();
		if (!$this->isDir($sBase)) {
			$this->oLogger->info("Unable to save print job — spool directory does not exist (" . $sBase . ")");
			return null;
		}
		$sPrinterDir = $sBase . DIRECTORY_SEPARATOR . $sPrinterName;
		if (!$this->isDir($sPrinterDir)) {
			$this->makeDir($sPrinterDir);
		}
		$oUser = $this->getUser($iNet, $iStn);
		if (is_object($oUser)) {
			$sUserDir = $sPrinterDir . DIRECTORY_SEPARATOR . $oUser->getUsername();
		} else {
			$sUserDir = $sPrinterDir . DIRECTORY_SEPARATOR . 'anon-' . $iNet . '-' . $iStn;
		}
		if (!$this->isDir($sUserDir)) {
			$this->makeDir($sUserDir);
		}
		return $sUserDir;
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
				$aJobs[] = [
					'network' => $iNetwork,
					'station' => $iStation,
					'began'   => $aBufferInfo['began'],
					'size'    => strlen((string) $aBufferInfo['data']),
					'printer' => $aBufferInfo['printer'] ?? '',
				];
			}
		}
		return $aJobs;
	}

	/**
	 * Returns the configured virtual printers as plain arrays suitable for display.
	*/
	public function getConfiguredPrinters(): array
	{
		$aPrinters = [];
		foreach ($this->getPrinterRegistry()->getAll() as $oPrinter) {
			$aPrinters[] = [
				'name'          => $oPrinter->getName(),
				'description'   => $oPrinter->getDescription(),
				'enabled'       => $oPrinter->isEnabled(),
				'behavior'      => $oPrinter->getBehavior(),
				'allowed_users' => $oPrinter->getAllowedUsersDisplay(),
			];
		}
		return $aPrinters;
	}

	/**
	 * Returns a list of all spooled files.
	 *
	 * Directory structure: {spool_base}/{printer}/{user}/{filename}
	*/
	public function getSpooledFiles(): array
	{
		$aFiles    = [];
		$sSpoolBase = $this->getSpoolDir();
		if (!$this->isDir($sSpoolBase)) {
			return $aFiles;
		}
		$aPrinterDirs = glob($sSpoolBase . DIRECTORY_SEPARATOR . '*', GLOB_ONLYDIR);
		if ($aPrinterDirs === false) {
			return $aFiles;
		}
		foreach ($aPrinterDirs as $sPrinterDir) {
			$sPrinterName = basename($sPrinterDir);
			$aUserDirs    = glob($sPrinterDir . DIRECTORY_SEPARATOR . '*', GLOB_ONLYDIR);
			if ($aUserDirs === false) {
				continue;
			}
			foreach ($aUserDirs as $sUserDir) {
				$sUser    = basename($sUserDir);
				$aFileList = glob($sUserDir . DIRECTORY_SEPARATOR . '*');
				if ($aFileList === false) {
					continue;
				}
				foreach ($aFileList as $sFile) {
					if (!is_file($sFile)) {
						continue;
					}
					$iSize     = filesize($sFile);
					$iModified = filemtime($sFile);
					$aFiles[]  = [
						'printer'  => $sPrinterName,
						'user'     => $sUser,
						'filename' => basename($sFile),
						'size'     => $iSize !== false ? $iSize : 0,
						'modified' => $iModified !== false ? $iModified : 0,
						'path'     => $sPrinterName . DIRECTORY_SEPARATOR . $sUser . DIRECTORY_SEPARATOR . basename($sFile),
					];
				}
			}
		}
		return $aFiles;
	}

	public function getSpooledFilePath(string $sRelPath): ?string
	{
		$sSpoolBase = $this->getSpoolDir();
		$sRealBase  = realpath($sSpoolBase);
		if ($sRealBase === false) {
			return null;
		}
		$sRelPath = str_replace(["\0", '..'], '', $sRelPath);
		$sFullPath = $sRealBase . DIRECTORY_SEPARATOR . $sRelPath;
		$sRealFull = realpath($sFullPath);
		if ($sRealFull === false || !is_file($sRealFull)) {
			return null;
		}
		if (!str_starts_with($sRealFull, $sRealBase . DIRECTORY_SEPARATOR)) {
			return null;
		}
		return $sRealFull;
	}

	/**
	 * Creates a child process to convert print jobs.
	 *
	 * The process is always started on the React event loop and runs
	 * asynchronously — this method returns immediately.  The server remains
	 * free to handle other Econet packets while the script executes.
	 *
	 * @param string      $sPath          Full path to the raw spool file.
	 * @param string|null $sPrinterScript Per-printer script override; null falls
	 *                                    back to the global print_server_conversion_script.
	*/
	protected function convertFile(string $sPath, ?string $sPrinterScript = null): void
	{
		$sDst = str_replace('.raw', '.ps', $sPath);

		$sCli = $sPrinterScript ?? $this->getConvertorScript();
		if (is_null($sCli) || $sCli === '') {
			return;
		}
		$sCli = str_replace('%source%',      $sPath, $sCli);
		$sCli = str_replace('%destination%', $sDst,  $sCli);

		$oLogger  = $this->oLogger;
		$oProcess = new Process($sCli);
		$oProcess->on('exit', function() use ($oLogger, $sDst) {
			$oLogger->info("Converted print job " . $sDst);
		});
		$oProcess->on('error', function(\Exception $oException) use ($oLogger, $sDst) {
			$oLogger->info("Failed to convert print job " . $sDst . " with error " . $oException->getMessage());
		});
		$oProcess->start($this->oLoop);
	}
}
