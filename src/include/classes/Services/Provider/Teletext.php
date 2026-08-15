<?php
/**
 * This file contains the Teletext service provider
 *
 * @author John Brown <john@home-lan.co.uk>
 * @package core
*/
namespace HomeLan\FileStore\Services\Provider;

use HomeLan\FileStore\Services\ProviderInterface;
use HomeLan\FileStore\Services\Provider\AdminInterface;
use HomeLan\FileStore\Services\Provider\Teletext\Storage;
use HomeLan\FileStore\Services\Provider\Teletext\Admin;
use HomeLan\FileStore\Services\ServiceDispatcher;
use HomeLan\FileStore\Messages\TeletextRequest;
use HomeLan\FileStore\Messages\TeletextReply;
use HomeLan\FileStore\Messages\EconetPacket;
use React\ChildProcess\Process;
use config;

/**
 * Implements the Econet teletext server protocol (see
 * docs/protocols/teletext.md) so that teletext client programs (e.g. the
 * BBC Micro `TELETEXT`) can discover this server, request pages by
 * channel/page number, and passively follow a "currently displayed page"
 * broadcast.
 *
 * Unlike the protocol's original hardware-receiver-backed servers, this
 * implementation always serves pages from local storage — the same
 * approach PiEconetBridge's teletext server uses — via Teletext\Storage,
 * which is native filesystem storage independent of the Vfs/Vfs\Plugin
 * layer and entirely read-only (this protocol has no operation that lets
 * a client save a page).
 *
 * @package core
*/
class Teletext implements ProviderInterface {

	// Ports (see docs/protocols/teletext.md)
	public const PORT_FIND_SERVER       = 0xB0;
	public const PORT_FIND_SERVER_REPLY = 0xB1;
	public const PORT_SERVER_REPLY      = 0xB2;
	public const PORT_CLIENT_REQUEST    = 0xB3;
	public const PORT_PAGE_DATA         = 0xB4;
	public const PORT_CURRENT_PAGE      = 0xB5;

	// Operation codes (control byte of a 0xB3 request)
	public const OP_READ_VERSION       = 0x80;
	public const OP_PAGE_REQUEST       = 0x81;
	public const OP_CANCEL_REQUEST     = 0x82;
	public const OP_READ_MAX_USERS     = 0x83;
	public const OP_READ_DATETIME      = 0x84;
	public const OP_LOGOFF             = 0x85;
	public const OP_PAGE_REQUEST_DELAY = 0x86;
	public const OP_READ_PORT_DATA     = 0x87;
	public const OP_VIEW_SCREEN        = 0x88;
	public const OP_DISC_PAGE          = 0x89;
	public const OP_TOGGLE_SERVICE     = 0x8A;
	public const OP_TOGGLE_HEADER      = 0x8B;

	// Error codes (leading status byte of a failed reply)
	public const ERROR_BAD_PAGE               = 1;
	public const ERROR_BAD_CHANNEL            = 2;
	public const ERROR_CHANNEL_BUSY           = 3;
	public const ERROR_TIME_UNAVAILABLE       = 4;
	public const ERROR_BAD_PORT               = 5;
	public const ERROR_NOT_FOUND              = 6;
	public const ERROR_SERVICE_SUSPENDED      = 7;
	public const ERROR_INSUFFICIENT_PRIVILEGE = 8;
	public const ERROR_NOT_SUPPORTED          = 9;
	public const ERROR_UNKNOWN_FUNCTION       = 10;
	public const ERROR_SERVER_ERROR           = 11;
	public const ERROR_WHO                    = 12;

	private const VERSION_BYTE   = 1;
	private const VERSION_STRING = '1.00';
	private const SERVER_TYPE    = 'TELETEXT';

	/** @var array<int,TeletextReply|EconetPacket> */
	protected array $aReplyBuffer = [];

	protected readonly Storage $oStorage;

	protected bool $bServiceActive = true;

	protected bool $bHeaderOn = true;

	/**
	 * The last page successfully served to any client, broadcast
	 * periodically on PORT_CURRENT_PAGE — see broadcastCurrentPage().
	 *
	 * @var array{channel: string, page: string}|null
	*/
	protected ?array $aLastServedPage = null;

	/**
	 * The currently in-flight Teefax import background process, if any —
	 * see checkTeefaxRefresh(). Used purely as an "is one already running"
	 * guard so an overdue refresh never gets launched twice in a row.
	*/
	protected ?Process $oTeefaxProcess = null;

	public function __construct(protected readonly \Psr\Log\LoggerInterface $oLogger, ?Storage $oStorage = null)
	{
		$this->oStorage = $oStorage ?? new Storage(config::getValueAsString('teletext_store_dir'));
	}

	public function getName(): string
	{
		return "Teletext";
	}

	public function getAdminInterface(): ?AdminInterface
	{
		return new Admin($this);
	}

	/**
	 * @return array<int,int>
	*/
	public function getServicePorts(): array
	{
		return [
			self::PORT_FIND_SERVER,
			self::PORT_FIND_SERVER_REPLY,
			self::PORT_SERVER_REPLY,
			self::PORT_CLIENT_REQUEST,
			self::PORT_PAGE_DATA,
			self::PORT_CURRENT_PAGE,
		];
	}

	/**
	 * Registers a periodic timer (teletext_carousel_interval seconds,
	 * default 4) that broadcasts whatever page was last served — this
	 * server has no live carousel of its own to report on, so "currently
	 * displayed page" is taken to mean the most recently requested one.
	 * See docs/protocols/teletext.md.
	*/
	public function registerService(ServiceDispatcher $oServiceDispatcher): void
	{
		$_this = $this;
		$oLoop = $oServiceDispatcher->getLoop();
		if ($oLoop !== null) {
			$oLoop->addPeriodicTimer(config::getValueAsFloat('teletext_carousel_interval'), function () use ($_this, $oServiceDispatcher) {
				$_this->broadcastCurrentPage();
				$oServiceDispatcher->sendPackets($_this);
			});
		}

		$oServiceDispatcher->addHousingKeepingTask(function () use ($_this, $oLoop) {
			$_this->checkTeefaxRefresh($oLoop);
		});
	}

	public function broadcastPacketIn(EconetPacket $oPacket): void
	{
		if ($oPacket->getPort() === self::PORT_FIND_SERVER) {
			$this->handleDiscovery(new TeletextRequest($oPacket, $this->oLogger));
		}
	}

	public function unicastPacketIn(EconetPacket $oPacket): void
	{
		$oRequest = new TeletextRequest($oPacket, $this->oLogger);
		switch ($oPacket->getPort()) {
			case self::PORT_FIND_SERVER:
				// A client that already knows this server's station can
				// unicast discovery directly rather than broadcasting it —
				// handling is identical either way (see MaceMail's quick
				// command envelope for the same pattern).
				$this->handleDiscovery($oRequest);
				break;
			case self::PORT_CLIENT_REQUEST:
				$this->handleClientRequest($oRequest);
				break;
			default:
				$this->oLogger->debug("Teletext: no handler for port " . sprintf('0x%02X', $oPacket->getPort()));
				break;
		}
	}

	/**
	 * @return array<int,EconetPacket>
	*/
	public function getReplies(): array
	{
		$aReturn = [];
		foreach ($this->aReplyBuffer as $oReply) {
			$aReturn[] = ($oReply instanceof EconetPacket) ? $oReply : $oReply->buildEconetpacket();
		}
		$this->aReplyBuffer = [];
		return $aReturn;
	}

	/**
	 * @return array<int,array<string,mixed>>
	*/
	public function getJobs(): array
	{
		return [];
	}

	protected function _addReplyToBuffer(TeletextReply|EconetPacket $oReply): void
	{
		$this->aReplyBuffer[] = $oReply;
	}

	// -------------------------------------------------------------------------
	// Discovery (0xB0 request -> 0xB1 reply)
	// -------------------------------------------------------------------------

	protected function handleDiscovery(TeletextRequest $oRequest): void
	{
		$sFilter = strtoupper(trim($oRequest->getDataString(0, 8)));
		if ($sFilter !== '' && $sFilter !== self::SERVER_TYPE) {
			return;
		}

		$oRequest->setReplyPort(self::PORT_FIND_SERVER_REPLY);
		$oReply = $oRequest->buildReply();
		$oReply->appendStatus(0);
		$oReply->appendByte(self::PORT_SERVER_REPLY);
		$oReply->appendByte(self::VERSION_BYTE);
		$oReply->appendFixedString(self::SERVER_TYPE, 8);
		$sName = config::getValueAsString('teletext_server_name');
		$oReply->appendByte(strlen($sName));
		if ($sName !== '') {
			$oReply->appendString($sName);
		}
		$this->_addReplyToBuffer($oReply);
	}

	// -------------------------------------------------------------------------
	// Operation dispatch (0xB3 request -> 0xB2 reply, plus 0xB4 for page data)
	// -------------------------------------------------------------------------

	protected function handleClientRequest(TeletextRequest $oRequest): void
	{
		$iOp = $oRequest->getControlByte();
		$this->oLogger->debug(
			"Teletext: op=" . sprintf('0x%02X', $iOp)
			. " from " . $oRequest->getSourceNetwork() . "." . $oRequest->getSourceStation()
		);

		switch ($iOp) {
			case self::OP_READ_VERSION:
				$this->handleReadVersion($oRequest);
				break;
			case self::OP_PAGE_REQUEST:
			case self::OP_PAGE_REQUEST_DELAY:
			case self::OP_DISC_PAGE:
				$this->handlePageRequest($oRequest);
				break;
			case self::OP_CANCEL_REQUEST:
				$this->handleCancelRequest($oRequest);
				break;
			case self::OP_READ_MAX_USERS:
				$this->handleReadMaxUsers($oRequest);
				break;
			case self::OP_READ_DATETIME:
				$this->handleReadDateTime($oRequest);
				break;
			case self::OP_LOGOFF:
				$this->handleLogoff($oRequest);
				break;
			case self::OP_READ_PORT_DATA:
				$this->handleReadPortData($oRequest);
				break;
			case self::OP_VIEW_SCREEN:
				$this->handleViewScreen($oRequest);
				break;
			case self::OP_TOGGLE_SERVICE:
				$this->handleToggleService($oRequest);
				break;
			case self::OP_TOGGLE_HEADER:
				$this->handleToggleHeader($oRequest);
				break;
			default:
				$this->_sendError($oRequest, self::ERROR_UNKNOWN_FUNCTION, "Unknown function");
				break;
		}
	}

	protected function handleReadVersion(TeletextRequest $oRequest): void
	{
		$oReply = $this->_buildOpReply($oRequest);
		$oReply->appendStatus(0);
		$oReply->appendCrString(self::VERSION_STRING);
		$this->_addReplyToBuffer($oReply);
	}

	// -------------------------------------------------------------------------
	// Page request (0x81/0x86/0x89 -> 0xB2 ack, then 0xB4 page data)
	// -------------------------------------------------------------------------

	protected function handlePageRequest(TeletextRequest $oRequest): void
	{
		if (!$this->bServiceActive) {
			$this->_sendError($oRequest, self::ERROR_SERVICE_SUSPENDED, "Service suspended");
			return;
		}

		$sChannel = $oRequest->getDataString(0, 1);
		// Uppercased before validation/lookup: page numbers are normally
		// decimal, but the second and third digits may also be hex (A-F) —
		// some page sources (e.g. the Teefax archive, via the "TFSHEX"
		// convention) use the extra capacity that gives. Storage filenames
		// are always uppercase hex for consistency.
		$sPage = strtoupper($oRequest->getDataString(1, 3));

		if (!preg_match('/^[0-9]$/', $sChannel)) {
			$this->_sendError($oRequest, self::ERROR_BAD_CHANNEL, "Bad channel");
			return;
		}
		if (!preg_match('/^[1-8][0-9A-F]{2}$/', $sPage)) {
			$this->_sendError($oRequest, self::ERROR_BAD_PAGE, "Bad page number");
			return;
		}

		// The subpage field is optional — omitted (or all-zero) means "the
		// default subpage", i.e. subpage 1.
		$sSubpageField = trim($oRequest->getDataString(4, 4));
		$iSubpage = 1;
		if ($sSubpageField !== '') {
			if (!preg_match('/^[0-9]{1,4}$/', $sSubpageField)) {
				$this->_sendError($oRequest, self::ERROR_BAD_PAGE, "Bad subpage number");
				return;
			}
			$iParsedSubpage = (int) $sSubpageField;
			if ($iParsedSubpage >= 1) {
				$iSubpage = $iParsedSubpage;
			}
		}

		$sData = $this->oStorage->getPage($sChannel, $sPage, $iSubpage);
		if ($sData === null) {
			$this->_sendError($oRequest, self::ERROR_NOT_FOUND, "Not found");
			return;
		}

		// Ack: request accepted, queue position 0 — pages are served
		// synchronously from local storage, there is never a real queue.
		$oReply = $this->_buildOpReply($oRequest);
		$oReply->appendStatus(0);
		$oReply->appendByte(0);
		$this->_addReplyToBuffer($oReply);

		$this->aLastServedPage = ['channel' => $sChannel, 'page' => $sPage];

		$oRequest->setReplyPort(self::PORT_PAGE_DATA);
		$oPageReply = $oRequest->buildReply();
		$oPageReply->appendByte(0x80);
		$sPageData = str_pad(substr($sData, 0, Storage::PAGE_SIZE), Storage::PAGE_SIZE, "\0");
		[$iSubpageHigh, $iSubpageLow] = $this->_toBcd($iSubpage);
		$sPageData[0x3FE] = chr($iSubpageHigh);
		$sPageData[0x3FF] = chr($iSubpageLow);
		$oPageReply->appendRaw($sPageData);
		$this->_addReplyToBuffer($oPageReply);
	}

	/**
	 * Encodes a 0-9999 subpage number as the two-byte BCD pair the page
	 * data's offset 0x3FE/0x3FF hold — each nibble is one decimal digit.
	 *
	 * @return array{0: int, 1: int}
	*/
	protected function _toBcd(int $iSubpage): array
	{
		$iSubpage = max(0, min(9999, $iSubpage));
		$iThousands = intdiv($iSubpage, 1000) % 10;
		$iHundreds  = intdiv($iSubpage, 100) % 10;
		$iTens      = intdiv($iSubpage, 10) % 10;
		$iUnits     = $iSubpage % 10;
		return [
			($iThousands << 4) | $iHundreds,
			($iTens << 4) | $iUnits,
		];
	}

	protected function handleCancelRequest(TeletextRequest $oRequest): void
	{
		$oReply = $this->_buildOpReply($oRequest);
		$oReply->appendStatus(0);
		$this->_addReplyToBuffer($oReply);
	}

	protected function handleReadMaxUsers(TeletextRequest $oRequest): void
	{
		$oReply = $this->_buildOpReply($oRequest);
		$oReply->appendStatus(0);
		$oReply->appendByte(config::getValueAsInt('teletext_max_users'));
		$this->_addReplyToBuffer($oReply);
	}

	protected function handleReadDateTime(TeletextRequest $oRequest): void
	{
		$oReply = $this->_buildOpReply($oRequest);
		$oReply->appendStatus(0);
		$oReply->appendString($this->now()->format('H:i:sd/m/Y'));
		$this->_addReplyToBuffer($oReply);
	}

	protected function handleLogoff(TeletextRequest $oRequest): void
	{
		$oReply = $this->_buildOpReply($oRequest);
		$oReply->appendStatus(0);
		$this->_addReplyToBuffer($oReply);
	}

	protected function handleReadPortData(TeletextRequest $oRequest): void
	{
		// Describes reading status from a physical tuner/decoder port on
		// real receiver hardware — no equivalent here.
		$this->_sendError($oRequest, self::ERROR_NOT_SUPPORTED, "Not supported");
	}

	protected function handleViewScreen(TeletextRequest $oRequest): void
	{
		$oReply = $this->_buildOpReply($oRequest);
		$oReply->appendStatus(0);
		$this->_addReplyToBuffer($oReply);
	}

	protected function handleToggleService(TeletextRequest $oRequest): void
	{
		$this->bServiceActive = !$this->bServiceActive;
		$oReply = $this->_buildOpReply($oRequest);
		$oReply->appendStatus(0);
		$oReply->appendByte($this->bServiceActive ? 1 : 0);
		$this->_addReplyToBuffer($oReply);
	}

	protected function handleToggleHeader(TeletextRequest $oRequest): void
	{
		$this->bHeaderOn = !$this->bHeaderOn;
		$oReply = $this->_buildOpReply($oRequest);
		$oReply->appendStatus(0);
		$oReply->appendByte($this->bHeaderOn ? 1 : 0);
		$this->_addReplyToBuffer($oReply);
	}

	protected function _buildOpReply(TeletextRequest $oRequest): TeletextReply
	{
		$oRequest->setReplyPort(self::PORT_SERVER_REPLY);
		return $oRequest->buildReply();
	}

	protected function _sendError(TeletextRequest $oRequest, int $iError, string $sMessage): void
	{
		$oReply = $this->_buildOpReply($oRequest);
		$oReply->appendStatus($iError);
		$oReply->appendCrString($sMessage);
		$this->_addReplyToBuffer($oReply);
	}

	// -------------------------------------------------------------------------
	// Current page broadcast (0xB5) — see registerService()
	// -------------------------------------------------------------------------

	public function broadcastCurrentPage(): void
	{
		if ($this->aLastServedPage === null) {
			return;
		}
		$oPacket = new EconetPacket();
		$oPacket->setPort(self::PORT_CURRENT_PAGE);
		$oPacket->setFlags(0x80);
		$oPacket->setDestinationNetwork(0);
		$oPacket->setDestinationStation(255);
		$oPacket->setData($this->aLastServedPage['channel'] . $this->aLastServedPage['page']);
		$this->_addReplyToBuffer($oPacket);
	}

	// -------------------------------------------------------------------------
	// Teefax refresh (housekeeping-driven background import — see
	// registerService() and src/include/classes/Command/TeefaxImport.php)
	// -------------------------------------------------------------------------

	/**
	 * Runs on every housekeeping tick. A no-op unless a Teefax channel is
	 * configured, no import for it is already running, and the last
	 * import (recorded by TeefaxImport itself, in the channel's own
	 * `.imported` marker file) is older than teletext_teefax_refresh_interval
	 * — in which case this spawns `src/util/teefax-import` as a detached
	 * background process via ReactPHP, exactly the way BeebTerm spawns its
	 * own sessions, so the event loop is never blocked by the download or
	 * the .tti conversion work.
	*/
	public function checkTeefaxRefresh(?\React\EventLoop\LoopInterface $oLoop = null): void
	{
		$sChannel = config::getValueAsString('teletext_teefax_channel');
		if (!preg_match('/^[0-9]$/', $sChannel)) {
			return;
		}
		if (!$this->isTeefaxRefreshDue($sChannel)) {
			return;
		}
		$this->_startTeefaxImport($sChannel, $oLoop);
	}

	/**
	 * Starts a Teefax import right now regardless of whether one is due,
	 * for the admin web front end's "refresh now" action. Returns false
	 * (without starting anything) if no channel is configured or an
	 * import is already running.
	*/
	public function triggerTeefaxImport(): bool
	{
		$sChannel = config::getValueAsString('teletext_teefax_channel');
		if (!preg_match('/^[0-9]$/', $sChannel)) {
			return false;
		}
		return $this->_startTeefaxImport($sChannel, ServiceDispatcher::create()->getLoop());
	}

	/**
	 * Shared by checkTeefaxRefresh() and triggerTeefaxImport(): spawns the
	 * background import, guarding against launching a second one while one
	 * is already running.
	*/
	protected function _startTeefaxImport(string $sChannel, ?\React\EventLoop\LoopInterface $oLoop): bool
	{
		if ($this->oTeefaxProcess !== null && $this->oTeefaxProcess->isRunning()) {
			return false;
		}

		$this->oLogger->info("Teletext: starting background Teefax import for channel " . $sChannel);
		$this->oTeefaxProcess = $this->_spawnTeefaxImport($sChannel);
		if ($oLoop !== null) {
			$this->oTeefaxProcess->start($oLoop);
		}
		return true;
	}

	/**
	 * True if the configured channel has never been imported, or its last
	 * import is older than the configured refresh interval.
	*/
	public function isTeefaxRefreshDue(string $sChannel): bool
	{
		$iLastImported = $this->_readTeefaxImportedMarker($sChannel);
		if ($iLastImported === null) {
			return true;
		}
		$iInterval = config::getValueAsInt('teletext_teefax_refresh_interval');
		return ($this->_now() - $iLastImported) >= $iInterval;
	}

	protected function _readTeefaxImportedMarker(string $sChannel): ?int
	{
		$sPath = (config::getValueAsString('teletext_store_dir')) . '/' . $sChannel . '/.imported';
		if (!file_exists($sPath)) {
			return null;
		}
		$sContent = file_get_contents($sPath);
		return $sContent === false ? null : (int) trim($sContent);
	}

	/**
	 * Builds (but does not start) the background import process, passing
	 * through the currently-active config directory if one was set, so the
	 * spawned process reads the same configuration as this one.
	*/
	protected function _spawnTeefaxImport(string $sChannel): Process
	{
		$sBinary = dirname(__DIR__, 4) . '/util/teefax-import';
		$sCommand = escapeshellarg($sBinary) . ' --channel=' . escapeshellarg($sChannel);
		if (defined('CONFIG_CONF_FILE_PATH') && is_scalar(CONFIG_CONF_FILE_PATH)) {
			$sCommand .= ' --config=' . escapeshellarg((string) CONFIG_CONF_FILE_PATH);
		}
		return new Process($sCommand);
	}

	protected function _now(): int
	{
		return time();
	}

	// -------------------------------------------------------------------------
	// Admin support
	// -------------------------------------------------------------------------

	public function isServiceActive(): bool
	{
		return $this->bServiceActive;
	}

	public function isHeaderOn(): bool
	{
		return $this->bHeaderOn;
	}

	/**
	 * @return array<int, array{channel: string, page_count: int}>
	*/
	public function getChannelSummaries(): array
	{
		$aReturn = [];
		foreach ($this->oStorage->getChannels() as $sChannel) {
			$aReturn[] = [
				'channel'    => $sChannel,
				'page_count' => count($this->oStorage->getPages($sChannel)),
			];
		}
		return $aReturn;
	}

	// -------------------------------------------------------------------------
	// Time wrapper — overridable in tests for deterministic assertions
	// -------------------------------------------------------------------------

	protected function now(): \DateTimeImmutable
	{
		return new \DateTimeImmutable();
	}
}
