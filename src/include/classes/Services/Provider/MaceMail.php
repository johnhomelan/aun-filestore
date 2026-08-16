<?php
/**
 * This file contains the MaceMail service provider
 *
 * @author John Brown <john@home-lan.co.uk>
 * @package core
*/
namespace HomeLan\FileStore\Services\Provider;

use HomeLan\FileStore\Services\ProviderInterface;
use HomeLan\FileStore\Services\Provider\AdminInterface;
use HomeLan\FileStore\Services\Provider\MaceMail\Storage;
use HomeLan\FileStore\Services\ServiceDispatcher;
use HomeLan\FileStore\Authentication\Security;
use HomeLan\FileStore\Authentication\User;
use HomeLan\FileStore\Messages\MaceMailRequest;
use HomeLan\FileStore\Messages\MaceMailReply;
use HomeLan\FileStore\Messages\EconetPacket;
use HomeLan\FileStore\Services\Provider\MaceMail\Admin;
use config;

/**
 * Implements the MaceMail electronic-mail server protocol (see
 * docs/protocols/macemail.md) so that unmodified 1985 MaceMail terminal
 * clients (TER) can log on, read, and send mail against this server.
 *
 * Authentication and user identity are delegated entirely to the existing
 * Authentication\Security system — MaceMail keeps no password store of its
 * own. The wire protocol addresses users by a compact numeric "slot"
 * (0–macemail_max_slots-1); Storage keeps the persistent slot-to-username
 * assignment (admin-provisioned only, see MaceMail\Admin), and every other
 * piece of identity/session state (login, idle timeout, admin rights) comes
 * from Security.
 *
 * All mail/store data lives under macemail_store_dir via MaceMail\Storage,
 * which is native filesystem storage independent of the Vfs/Vfs\Plugin
 * layer.
 *
 * @package core
*/
class MaceMail implements ProviderInterface {

	// Quick-command envelope (see docs/protocols/macemail.md)
	public const PORT_REQUEST             = 0x19;
	public const PORT_ACK                 = 0x1A;
	public const PORT_LOGON_REQUEST       = 0x1B;
	public const PORT_LOGON_REPLY         = 0x1C;
	public const PORT_PASSWORD_REQUEST    = 0x1E;
	public const PORT_DIRECTORY_REPLY     = 0x1F;
	public const PORT_STORE_RECALL_REPLY  = 0x21;
	public const PORT_STORE_SAVE_REQUEST  = 0x23;
	public const PORT_MAILCHECK_REPLY     = 0x25;
	public const PORT_MAIL_POST_REQUEST   = 0x26;
	public const PORT_MAIL_LIST_REPLY     = 0x27;
	public const PORT_MAIL_ITEM_REPLY     = 0x28;
	public const PORT_MAILBOX_SCAN_REPLY  = 0x29;
	public const PORT_LOOK_REQUEST        = 0x2A;
	public const PORT_LOOK_REPLY          = 0x2B;
	public const PORT_CHAT                = 0x31;
	public const PORT_NOTIFY              = 0x40;

	// Quick-command operation codes
	public const OP_NOOP              = 0;
	public const OP_LOGON             = 1;
	public const OP_LOGOFF            = 2;
	public const OP_CHANGE_PASSWORD   = 3;
	public const OP_DIRECTORY_ALL     = 4;
	public const OP_WHOAMI            = 5;
	public const OP_SAVE_MAIL         = 6;
	public const OP_GET_STORE         = 7;
	public const OP_SAVE_STORE        = 8;
	public const OP_DELETE_STORE      = 9;
	public const OP_MAIL_CHECK        = 10;
	public const OP_DIRECTORY_ONLINE  = 11;
	public const OP_GET_MAIL_NEW      = 12;
	public const OP_GET_MAIL_OLD      = 13;
	public const OP_INDIVIDUAL_MAIL   = 14;
	public const OP_CHAT_REQUEST      = 15;
	public const OP_DELETE_MAIL       = 16;
	public const OP_MAILBOX_SCAN      = 17;
	public const OP_LOOK              = 18;
	public const OP_NOTIFY_USER       = 19;
	public const OP_SET_AVAILABILITY  = 20;
	public const OP_NETWORK_NUMBER    = 21;

	// Logon error codes (returned as the sole byte of a failed 0x1C reply)
	private const int LOGON_ERROR_UNKNOWN_SLOT   = 0xFE;
	private const int LOGON_ERROR_ALREADY_ONLINE = 0xFD;
	private const int LOGON_ERROR_BAD_PASSWORD   = 0xFC;

	/**
	 * Directory replies are one count byte plus 30-byte records inside a
	 * 960-byte buffer (the vintage client's own allocation) — floor(959/30).
	*/
	private const int DIRECTORY_MAX_ENTRIES = 31;

	// PORT_NOTIFY (0x40) type bytes, matching SERV.bas's PROCCONT() call sites
	private const int NOTIFY_CHAT_INVITE  = 1;
	private const int NOTIFY_NEW_MAIL     = 7;
	private const int NOTIFY_FORCED_LOGOFF = 10;

	/**
	 * System-broadcast message types the vintage client renders as canned,
	 * hard-coded text (see TER.bas's PROCRC) — there is no room in the
	 * 4-byte notification payload for free text, so an admin picks one of
	 * these rather than typing a message.
	*/
	public const SYSTEM_MESSAGES = [
		2  => 'CLOSING DOWN IN 5 MINUTES',
		3  => 'ALL USERS PLEASE LOG OFF',
		4  => 'SYSTEM FAULTS ARE POSSIBLE',
		5  => 'CONTACT SYSTEM MANAGER',
		6  => '(clear system message)',
		11 => 'CONTACT SYSTEM MANAGER',
	];

	/** @var array<int,MaceMailReply> */
	protected array $aReplyBuffer = [];

	/**
	 * Unsolicited, server-initiated packets (currently just the new-mail
	 * push on PORT_NOTIFY) that are not built as a reply to any inbound
	 * request, so they cannot go through _addReplyToBuffer()/MaceMailReply.
	 *
	 * @var array<int, EconetPacket>
	*/
	protected array $aNotifyBuffer = [];

	protected readonly Storage $oStorage;

	/**
	 * Plaintext password captured at logon, keyed by "{network}.{station}".
	 * Held only for the lifetime of the session — needed because the wire
	 * protocol's change-password operation does not resend the current
	 * password (the vintage client already re-verified it locally), but
	 * AuthPluginInterface::setPassword() requires it. Cleared on logoff.
	 *
	 * @var array<string,string>
	*/
	protected array $aSessionPassword = [];

	/**
	 * Remembers which store slot a save-store quick command (op 8) targeted,
	 * keyed by "{network}.{station}", until the actual data arrives
	 * separately on PORT_STORE_SAVE_REQUEST — the original protocol splits
	 * "which slot" (in the quick command) from "the data" (a following
	 * transmission) the same way.
	 *
	 * @var array<string, array{username: string, slot: int}>
	*/
	protected array $aPendingStoreSave = [];

	/**
	 * Per-user "available for chat" flag (op 20), keyed by uppercase
	 * username. Absent means available — the original sets a user's status
	 * to available as part of logon itself, so that is the default here
	 * too, and the entry is cleared on logoff rather than persisted.
	 *
	 * @var array<string, bool>
	*/
	protected array $aAvailability = [];

	public function __construct(protected readonly \Psr\Log\LoggerInterface $oLogger, ?Storage $oStorage = null)
	{
		$this->oStorage = $oStorage ?? new Storage(config::getValueAsString('macemail_store_dir'));
	}

	/** Storage's mail-index/mail-item entries are typed `array<string,mixed>`. */
	private function _asInt(mixed $mValue): int
	{
		return is_scalar($mValue) ? (int) $mValue : 0;
	}

	private function _asString(mixed $mValue): string
	{
		return is_scalar($mValue) ? (string) $mValue : '';
	}

	public function getName(): string
	{
		return "MaceMail";
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
			self::PORT_REQUEST,
			self::PORT_ACK,
			self::PORT_LOGON_REQUEST,
			self::PORT_LOGON_REPLY,
			self::PORT_PASSWORD_REQUEST,
			self::PORT_DIRECTORY_REPLY,
			self::PORT_STORE_RECALL_REPLY,
			self::PORT_STORE_SAVE_REQUEST,
			self::PORT_MAILCHECK_REPLY,
			self::PORT_MAIL_POST_REQUEST,
			self::PORT_MAIL_LIST_REPLY,
			self::PORT_MAIL_ITEM_REPLY,
			self::PORT_MAILBOX_SCAN_REPLY,
			self::PORT_LOOK_REQUEST,
			self::PORT_LOOK_REPLY,
			self::PORT_CHAT,
			self::PORT_NOTIFY,
		];
	}

	public function registerService(ServiceDispatcher $oServiceDispatcher): void
	{
	}

	/**
	 * The quick-command channel (port 0x19) is broadcast by the original
	 * vintage client, so it is handled here as well as in unicastPacketIn()
	 * — see the case for self::PORT_REQUEST there for the unicast side of
	 * the same handling.
	*/
	public function broadcastPacketIn(EconetPacket $oPacket): void
	{
		if ($oPacket->getSourceNetwork() === null || $oPacket->getSourceStation() === null) {
			$this->oLogger->warning("MaceMail: dropping broadcast packet with no resolvable source network/station");
			return;
		}
		if ($oPacket->getPort() === self::PORT_REQUEST) {
			$this->handleQuickCommand(new MaceMailRequest($oPacket, $this->oLogger));
		}
	}

	public function unicastPacketIn(EconetPacket $oPacket): void
	{
		if ($oPacket->getSourceNetwork() === null || $oPacket->getSourceStation() === null) {
			$this->oLogger->warning("MaceMail: dropping packet with no resolvable source network/station");
			return;
		}
		$oRequest = new MaceMailRequest($oPacket, $this->oLogger);
		switch ($oPacket->getPort()) {
			case self::PORT_REQUEST:
				// The original client always broadcasts the quick-command
				// envelope (see broadcastPacketIn()), but this server also
				// accepts it addressed directly to its own station — for
				// clients that already know the server's address and would
				// rather not broadcast discovery traffic. Handling is
				// identical either way: handleQuickCommand() replies to
				// whichever station/network the request actually came from
				// (via MaceMailReply::buildEconetpacket(), never back to a
				// broadcast address), so replies are always unicast to the
				// real requester regardless of how the request arrived.
				$this->handleQuickCommand($oRequest);
				break;
			case self::PORT_LOGON_REQUEST:
				$this->handleLogonRequest($oRequest);
				break;
			case self::PORT_MAIL_POST_REQUEST:
				$this->handleSaveMailRequest($oRequest);
				break;
			case self::PORT_STORE_SAVE_REQUEST:
				$this->handleStoreSaveData($oRequest);
				break;
			case self::PORT_LOOK_REQUEST:
				$this->handleLookRequest($oRequest);
				break;
			default:
				$this->oLogger->debug("MaceMail: no handler for port " . sprintf('0x%02X', $oPacket->getPort()));
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
			$aReturn[] = $oReply->buildEconetpacket();
		}
		$this->aReplyBuffer = [];

		foreach ($this->aNotifyBuffer as $oPacket) {
			$aReturn[] = $oPacket;
		}
		$this->aNotifyBuffer = [];

		return $aReturn;
	}

	/**
	 * @return array<int,array<string,mixed>>
	*/
	public function getJobs(): array
	{
		return [];
	}

	protected function _addReplyToBuffer(MaceMailReply $oReply): void
	{
		$this->aReplyBuffer[] = $oReply;
	}

	// -------------------------------------------------------------------------
	// Quick-command dispatch (port 0x19 -> ack on 0x1A, then per-operation)
	// -------------------------------------------------------------------------

	protected function handleQuickCommand(MaceMailRequest $oRequest): void
	{
		$iOp    = (int) $oRequest->getByte(6);
		$iSlot  = (int) $oRequest->getByte(7);
		$iParam = (int) $oRequest->getByte(8);

		$this->oLogger->debug(
			"MaceMail: quick command op=" . $iOp . " slot=" . $iSlot . " param=" . $iParam
			. " from " . $oRequest->getSourceNetwork() . "." . $oRequest->getSourceStation()
		);

		$oRequest->setReplyPort(self::PORT_ACK);
		$oAck = $oRequest->buildReply();
		$oAck->appendFixedString('MACE', 4);
		$oAck->appendByte(0);
		$oAck->appendFixedString($this->getUsergroup(), 4);
		$oAck->appendByte(0);
		$oAck->appendByte(0);
		$this->_addReplyToBuffer($oAck);

		switch ($iOp) {
			case self::OP_LOGOFF:
				$this->handleLogoffOp($oRequest, $iSlot);
				break;
			case self::OP_DIRECTORY_ALL:
				$this->handleDirectoryOp($oRequest, $iSlot, false);
				break;
			case self::OP_DIRECTORY_ONLINE:
				$this->handleDirectoryOp($oRequest, $iSlot, true);
				break;
			case self::OP_WHOAMI:
				// The client already has this information locally — nothing
				// to send beyond the universal ack above.
				$this->oLogger->debug("MaceMail: who-am-I request from slot " . $iSlot);
				break;
			case self::OP_MAIL_CHECK:
				$this->handleMailCheckOp($oRequest, $iSlot);
				break;
			case self::OP_GET_MAIL_NEW:
			case self::OP_GET_MAIL_OLD:
				// Both reply with the same combined new+old, express+normal
				// list — the client filters it locally per which op it sent,
				// matching the original server's own behaviour.
				$this->handleGetMailOp($oRequest, $iSlot);
				break;
			case self::OP_INDIVIDUAL_MAIL:
				$this->handleIndividualMailOp($oRequest, $iSlot, $iParam);
				break;
			case self::OP_DELETE_MAIL:
				$this->handleDeleteMailOp($oRequest, $iSlot, $iParam);
				break;
			case self::OP_GET_STORE:
				$this->handleGetStoreOp($oRequest, $iSlot, $iParam);
				break;
			case self::OP_SAVE_STORE:
				$this->handleSaveStoreQuickCommand($oRequest, $iSlot, $iParam);
				break;
			case self::OP_DELETE_STORE:
				$this->handleDeleteStoreOp($oRequest, $iSlot, $iParam);
				break;
			case self::OP_MAILBOX_SCAN:
				$this->handleMailboxScanOp($oRequest, $iSlot);
				break;
			case self::OP_CHAT_REQUEST:
				$this->handleChatOp($oRequest, $iSlot, $iParam);
				break;
			case self::OP_SET_AVAILABILITY:
				$this->handleSetAvailabilityOp($oRequest, $iSlot, $iParam);
				break;
			default:
				// Every other operation either needs no further action here
				// (a follow-up packet on a dedicated port drives it, e.g.
				// LOGON) or is implemented in a later phase.
				break;
		}
	}

	protected function handleLogoffOp(MaceMailRequest $oRequest, int $iSlot): void
	{
		$iNet = $oRequest->getSourceNetwork();
		$iStn = $oRequest->getSourceStation();
		if (!$this->secIsLoggedIn($iNet, $iStn)) {
			return;
		}
		$oUser = $this->secGetUser($iNet, $iStn);
		$this->secLogout($iNet, $iStn);
		unset($this->aSessionPassword[$iNet . '.' . $iStn]);
		if ($oUser instanceof User) {
			unset($this->aAvailability[strtoupper((string) $oUser->getUsername())]);
		}
	}

	// -------------------------------------------------------------------------
	// Directory (0x1F reply — all registered users, or logged-on users only)
	// -------------------------------------------------------------------------

	protected function handleDirectoryOp(MaceMailRequest $oRequest, int $iSlot, bool $bOnlineOnly): void
	{
		$sRequester = $this->resolveAuthenticatedUsername($oRequest, $iSlot);
		if ($sRequester === null) {
			return;
		}

		if ($bOnlineOnly) {
			$aEntries = [];
			foreach ($this->secGetUsersOnline() as $aStations) {
				foreach ($aStations as $aSession) {
					if (!($aSession['user'] instanceof User)) {
						continue;
					}
					$sUsername = (string) $aSession['user']->getUsername();
					if (strtoupper($sUsername) === strtoupper($sRequester)) {
						continue;
					}
					$iOnlineSlot = $this->oStorage->getSlotForUsername($sUsername);
					if ($iOnlineSlot !== null) {
						$aEntries[$iOnlineSlot] = $sUsername;
					}
				}
			}
		} else {
			$aEntries = $this->oStorage->getAllSlots();
		}
		ksort($aEntries);
		$aEntries = array_slice($aEntries, 0, self::DIRECTORY_MAX_ENTRIES, true);

		$oRequest->setReplyPort(self::PORT_DIRECTORY_REPLY);
		$oReply = $oRequest->buildReply();
		$oReply->appendByte(count($aEntries));
		foreach ($aEntries as $iEntrySlot => $sUsername) {
			// Each record is 30 bytes: a 29-byte CR-terminated, space-padded
			// name field followed by the 1-byte user (slot) number.
			$sName = substr($sUsername, 0, 28);
			$oReply->appendString($sName);
			$oReply->appendByte(0x0D);
			for ($i = 0, $iPad = 29 - strlen($sName) - 1; $i < $iPad; $i++) {
				$oReply->appendByte(32);
			}
			$oReply->appendByte($iEntrySlot);
		}
		$this->_addReplyToBuffer($oReply);
	}

	// -------------------------------------------------------------------------
	// Mail check (0x25 reply)
	// -------------------------------------------------------------------------

	protected function handleMailCheckOp(MaceMailRequest $oRequest, int $iSlot): void
	{
		$sUsername = $this->resolveAuthenticatedUsername($oRequest, $iSlot);
		if ($sUsername === null) {
			return;
		}

		$aCounts = $this->oStorage->getMailCounts($sUsername);
		$iTotal  = $aCounts['unread_express'] + $aCounts['read_express']
			+ $aCounts['unread_normal'] + $aCounts['read_normal'];

		$oRequest->setReplyPort(self::PORT_MAILCHECK_REPLY);
		$oReply = $oRequest->buildReply();
		$oReply->appendByte(min($iTotal, 255));
		$oReply->appendByte($aCounts['unread_express']);
		$oReply->appendByte($aCounts['read_express']);
		$oReply->appendByte($aCounts['unread_normal']);
		$oReply->appendByte($aCounts['read_normal']);
		$this->_addReplyToBuffer($oReply);
	}

	// -------------------------------------------------------------------------
	// Shared authorization check for operations driven entirely by the quick
	// command (no follow-up packet) — confirms the source station is really
	// logged in, and that the slot number it claims matches who Security
	// says is logged in there, before doing any work.
	// -------------------------------------------------------------------------

	protected function resolveAuthenticatedUsername(MaceMailRequest $oRequest, int $iSlot): ?string
	{
		$iNet = $oRequest->getSourceNetwork();
		$iStn = $oRequest->getSourceStation();
		if (!$this->secIsLoggedIn($iNet, $iStn)) {
			return null;
		}
		$oUser = $this->secGetUser($iNet, $iStn);
		if (!($oUser instanceof User)) {
			return null;
		}
		$sUsername = (string) $oUser->getUsername();
		if ($this->oStorage->getSlotForUsername($sUsername) !== $iSlot) {
			$this->oLogger->info("MaceMail: slot/session mismatch for " . $sUsername . " (claimed slot " . $iSlot . ")");
			return null;
		}
		$this->secUpdateIdleTimer($iNet, $iStn);
		return $sUsername;
	}

	// -------------------------------------------------------------------------
	// Logon (port 0x1B request / 0x1C reply)
	// -------------------------------------------------------------------------

	protected function handleLogonRequest(MaceMailRequest $oRequest): void
	{
		$iNet      = $oRequest->getSourceNetwork();
		$iStn      = $oRequest->getSourceStation();
		$sPassword = $oRequest->getFixedString(1, 5);
		$iSlot     = (int) $oRequest->getByte(6);

		$oRequest->setReplyPort(self::PORT_LOGON_REPLY);
		$oReply = $oRequest->buildReply();

		$sUsername = $this->oStorage->getUsernameForSlot($iSlot);
		if ($sUsername === null) {
			$this->oLogger->info("MaceMail: logon attempt for unassigned slot " . $iSlot);
			$oReply->appendByte(self::LOGON_ERROR_UNKNOWN_SLOT);
			$this->_addReplyToBuffer($oReply);
			return;
		}

		$aExisting = $this->secGetUsersStation($sUsername);
		if (!empty($aExisting) && !($aExisting['network'] === $iNet && $aExisting['station'] === $iStn)) {
			$this->oLogger->info("MaceMail: " . $sUsername . " is already logged on elsewhere");
			$oReply->appendByte(self::LOGON_ERROR_ALREADY_ONLINE);
			$this->_addReplyToBuffer($oReply);
			return;
		}

		if (!$this->secLogin($iNet, $iStn, $sUsername, $sPassword)) {
			$this->oLogger->info("MaceMail: bad password for " . $sUsername);
			$oReply->appendByte(self::LOGON_ERROR_BAD_PASSWORD);
			$this->_addReplyToBuffer($oReply);
			return;
		}

		$this->aSessionPassword[$iNet . '.' . $iStn] = $sPassword;

		[$iDay, $iMonth, $iYear] = $this->today();
		$this->oStorage->touchLastUsed($sUsername, $iDay, $iMonth, $iYear);

		$aMeta         = $this->oStorage->getUserMeta($sUsername);
		$aCounts       = $this->oStorage->getMailCounts($sUsername);
		$iRegistered   = count($this->oStorage->getAllSlots());
		$oUser         = $this->secGetUser($iNet, $iStn);
		$sDisplayName  = ($oUser instanceof User ? $oUser->getUsername() : null) ?? $sUsername;

		// Name field: up to 26 chars, CR-terminated, padded to exactly 27 bytes.
		$sNameTrunc = substr($sDisplayName, 0, 26);
		$oReply->appendString($sNameTrunc);
		$oReply->appendByte(0x0D);
		for ($i = 0, $iPad = 27 - strlen($sNameTrunc) - 1; $i < $iPad; $i++) {
			$oReply->appendByte(32);
		}

		$oReply->appendByte($iRegistered);              // 27 — registered user count
		$oReply->appendByte($aMeta['store_mask']);       // 28 — store-slot usage bitmask
		$oReply->appendByte($aMeta['registered'][0]);    // 29 — registered day
		$oReply->appendByte($aMeta['registered'][1]);    // 30 — registered month
		$oReply->appendByte($aMeta['registered'][2]);    // 31 — registered year
		$oReply->appendByte($iDay);                      // 32 — last-used day
		$oReply->appendByte($iMonth);                    // 33 — last-used month
		$oReply->appendByte($iYear);                     // 34 — last-used year
		for ($i = 35; $i <= 40; $i++) {
			$oReply->appendByte(0);                      // 35-40 — reserved
		}
		$oReply->appendByte($aCounts['unread_normal']);  // 41
		$oReply->appendByte($aCounts['read_normal']);    // 42
		$oReply->appendByte($aCounts['unread_express']); // 43
		$oReply->appendByte($aCounts['read_express']);   // 44

		$this->_addReplyToBuffer($oReply);
	}

	// -------------------------------------------------------------------------
	// Save mail (port 0x26 request, no reply — the client relies on the
	// universal ack sent by handleQuickCommand())
	// -------------------------------------------------------------------------

	protected function handleSaveMailRequest(MaceMailRequest $oRequest): void
	{
		$iNet = $oRequest->getSourceNetwork();
		$iStn = $oRequest->getSourceStation();
		if (!$this->secIsLoggedIn($iNet, $iStn)) {
			return;
		}
		$oUser = $this->secGetUser($iNet, $iStn);
		if (!($oUser instanceof User)) {
			return;
		}
		$iSenderSlot = $this->oStorage->getSlotForUsername((string) $oUser->getUsername());
		if ($iSenderSlot === null) {
			return;
		}
		$this->secUpdateIdleTimer($iNet, $iStn);

		$sBody     = $oRequest->getFixedString(1, 418);
		$sSubject  = $oRequest->getFixedString(441, 28);
		$iType     = (int) $oRequest->getByte(470);
		$bEveryone = ((int) $oRequest->getByte(471)) === 65;

		$aToSlots = [];
		for ($i = 0; $i < 6; $i++) {
			$iToSlot = (int) $oRequest->getByte(472 + $i);
			if ($iToSlot >= 0 && $iToSlot <= 63) {
				$aToSlots[] = $iToSlot;
			}
		}

		// The TO flag pair can carry ack-requested (1), express (3), or
		// reply-requested (4). CC recipients/flags (bytes 479-486) are
		// parsed by the original client but not exposed to the user in any
		// meaningful way we could confirm from TER.bas, so — as a
		// deliberate, documented simplification — CC delivery is not
		// implemented here; only the TO list is used.
		$iToFlag1        = (int) $oRequest->getByte(478);
		$iToFlag2        = (int) $oRequest->getByte(479);
		$bAckRequested   = ($iToFlag1 === 1 || $iToFlag2 === 1);
		$bExpress        = ($iToFlag1 === 3 || $iToFlag2 === 3);
		$bReplyRequested = ($iToFlag1 === 4 || $iToFlag2 === 4);

		$aRecipientSlots = $bEveryone ? array_keys($this->oStorage->getAllSlots()) : $aToSlots;

		$aHeader = [
			'sender_slot'     => $iSenderSlot,
			'subject'         => $sSubject,
			'type'            => $iType,
			'date'            => $this->today(),
			'express'         => $bExpress,
			'ack_requested'   => $bAckRequested,
			'reply_requested' => $bReplyRequested,
		];

		foreach (array_unique($aRecipientSlots) as $iRecipientSlot) {
			$sRecipientUsername = $this->oStorage->getUsernameForSlot($iRecipientSlot);
			if ($sRecipientUsername === null) {
				continue;
			}
			$this->oStorage->addMailItem($sRecipientUsername, $aHeader, $sBody);
			$this->notifyNewMail($sRecipientUsername);
		}
	}

	// -------------------------------------------------------------------------
	// Get mail list (0x27 reply) — used for both "new" (op12) and "old"
	// (op13) requests. Both send the same combined, per-message (id, flags)
	// list; the vintage client filters it locally for the flag pattern it
	// asked for, matching the original server's own behaviour.
	// -------------------------------------------------------------------------

	protected function handleGetMailOp(MaceMailRequest $oRequest, int $iSlot): void
	{
		$sUsername = $this->resolveAuthenticatedUsername($oRequest, $iSlot);
		if ($sUsername === null) {
			return;
		}

		$aCounts = $this->oStorage->getMailCounts($sUsername);
		$iTotal  = $aCounts['unread_express'] + $aCounts['read_express']
			+ $aCounts['unread_normal'] + $aCounts['read_normal'];

		$oRequest->setReplyPort(self::PORT_MAIL_LIST_REPLY);
		$oReply = $oRequest->buildReply();
		$oReply->appendByte(min($iTotal, 255));
		$oReply->appendByte($aCounts['unread_express']);
		$oReply->appendByte($aCounts['read_express']);
		$oReply->appendByte($aCounts['unread_normal']);
		$oReply->appendByte($aCounts['read_normal']);

		// 512-byte buffer, 5-byte header, 2 bytes (id, flags) per entry.
		$iMaxEntries = (int) floor((512 - 5) / 2);
		$aIndex      = array_slice($this->oStorage->getMailIndex($sUsername), 0, $iMaxEntries);
		foreach ($aIndex as $aEntry) {
			$oReply->appendByte($this->_asInt($aEntry['id'] ?? 0));
			$iFlags = 0;
			if (!empty($aEntry['read'])) {
				$iFlags |= 0x80;
			}
			if (!empty($aEntry['express'])) {
				$iFlags |= 0x40;
			}
			$oReply->appendByte($iFlags);
		}

		$this->_addReplyToBuffer($oReply);
	}

	// -------------------------------------------------------------------------
	// Individual mail item (0x28 reply) — full body + header for one message,
	// marking it read on successful fetch.
	// -------------------------------------------------------------------------

	protected function handleIndividualMailOp(MaceMailRequest $oRequest, int $iSlot, int $iMessageId): void
	{
		$sUsername = $this->resolveAuthenticatedUsername($oRequest, $iSlot);
		$aEntry    = $sUsername === null ? null : $this->oStorage->getMailItem($sUsername, $iMessageId);

		$oRequest->setReplyPort(self::PORT_MAIL_ITEM_REPLY);
		$oReply = $oRequest->buildReply();

		if ($aEntry === null || $sUsername === null) {
			// Matches the original's `!H%=-1` access-denied sentinel: a
			// short, 4-byte, all-0xFF reply instead of the full record.
			for ($i = 0; $i < 4; $i++) {
				$oReply->appendByte(0xFF);
			}
			$this->_addReplyToBuffer($oReply);
			return;
		}

		$sBody = $this->oStorage->getMailBody($sUsername, $iMessageId);

		// 0-417: body
		$oReply->appendString(str_pad(substr($sBody, 0, 418), 418, "\0"));
		// 418-439: padding
		for ($i = 0; $i < 22; $i++) {
			$oReply->appendByte(0);
		}
		// 440-467: subject
		$oReply->appendString(str_pad(substr($this->_asString($aEntry['subject'] ?? ''), 0, 28), 28, ' '));
		// 468: padding
		$oReply->appendByte(0);
		// 469: action-flags byte — matches SERV.bas's own DIR?469 write:
		// 254 (0xFE) when an ack was requested, 253 (0xFD) when a reply was
		// requested, both ORed together if both were requested, 0 otherwise.
		$iFlags = 0;
		if (!empty($aEntry['ack_requested'])) {
			$iFlags |= 254;
		}
		if (!empty($aEntry['reply_requested'])) {
			$iFlags |= 253;
		}
		$oReply->appendByte($iFlags);
		// 470: "everyone" broadcast flag — not meaningful once read back
		$oReply->appendByte(0);
		// 471-476: TO recipient slots — per-recipient tracking of a
		// multi-addressed message isn't kept once delivered, so this is
		// reported as empty (64) rather than reconstructed, a deliberate
		// simplification.
		for ($i = 0; $i < 6; $i++) {
			$oReply->appendByte(64);
		}
		// 477-478: TO flags — empty
		$oReply->appendByte(64);
		$oReply->appendByte(64);
		// 479-486: CC region — CC delivery isn't implemented (see
		// handleSaveMailRequest()), so this is always empty
		for ($i = 0; $i < 8; $i++) {
			$oReply->appendByte(64);
		}
		// 487-540: padding
		for ($i = 0; $i < 54; $i++) {
			$oReply->appendByte(0);
		}
		// The original locates the sender slot byte via a sector-derived
		// offset (541 + 35*(msgid mod 7)); only that one byte is needed
		// here; everything else in that region is unused padding.
		$iSenderPad = 35 * ($iMessageId % 7);
		for ($i = 0; $i < $iSenderPad; $i++) {
			$oReply->appendByte(0);
		}
		$oReply->appendByte($this->_asInt($aEntry['sender_slot'] ?? 64));
		for ($i = 0, $iRemaining = 226 - $iSenderPad; $i < $iRemaining; $i++) {
			$oReply->appendByte(0);
		}

		$this->_addReplyToBuffer($oReply);
		$this->oStorage->markMailRead($sUsername, $iMessageId);
	}

	// -------------------------------------------------------------------------
	// Delete mail (op16, quick-command only — no dedicated reply port)
	// -------------------------------------------------------------------------

	protected function handleDeleteMailOp(MaceMailRequest $oRequest, int $iSlot, int $iMessageId): void
	{
		$sUsername = $this->resolveAuthenticatedUsername($oRequest, $iSlot);
		if ($sUsername === null) {
			return;
		}
		$this->oStorage->deleteMailItem($sUsername, $iMessageId);
	}

	// -------------------------------------------------------------------------
	// New-mail push notification (port 0x40) — unsolicited, sent to a
	// recipient's station only if they are currently online, matching the
	// original's PROCCONT(7, station, 0, 0).
	// -------------------------------------------------------------------------

	protected function notifyNewMail(string $sUsername): void
	{
		$aStation = $this->secGetUsersStation($sUsername);
		if (empty($aStation)) {
			return;
		}
		$this->sendNotify((int) $aStation['network'], (int) $aStation['station'], self::NOTIFY_NEW_MAIL, 0, 0);
	}

	/**
	 * Builds and buffers a 4-byte async notification on PORT_NOTIFY, matching
	 * SERV.bas's PROCCONT(C,M,E1,E2) exactly: a type byte followed by two
	 * caller-supplied bytes and one always-zero trailing byte.
	*/
	protected function sendNotify(int $iNet, int $iStn, int $iType, int $iE1, int $iE2): void
	{
		$oPacket = new EconetPacket();
		$oPacket->setDestinationNetwork($iNet);
		$oPacket->setDestinationStation($iStn);
		$oPacket->setPort(self::PORT_NOTIFY);
		$oPacket->setFlags(0);
		$oPacket->setData(chr($iType & 0xFF) . chr($iE1 & 0xFF) . chr($iE2 & 0xFF) . chr(0));
		$this->aNotifyBuffer[] = $oPacket;
	}

	// -------------------------------------------------------------------------
	// Store slots (ops 7/8/9 -> ports 0x21/0x23) — 8 general-purpose,
	// 440-byte slots per user, saved/recalled verbatim; MaceMail itself
	// never inspects their contents.
	// -------------------------------------------------------------------------

	private const int STORE_SLOT_SIZE = 440;

	protected function handleGetStoreOp(MaceMailRequest $oRequest, int $iSlot, int $iStoreSlot): void
	{
		$sUsername = $this->resolveAuthenticatedUsername($oRequest, $iSlot);
		if ($sUsername === null) {
			return;
		}
		$sData = $this->oStorage->getStoreSlot($sUsername, $iStoreSlot);

		$oRequest->setReplyPort(self::PORT_STORE_RECALL_REPLY);
		$oReply = $oRequest->buildReply();
		$oReply->appendString(str_pad(substr($sData, 0, self::STORE_SLOT_SIZE), self::STORE_SLOT_SIZE, "\0"));
		$this->_addReplyToBuffer($oReply);
	}

	protected function handleSaveStoreQuickCommand(MaceMailRequest $oRequest, int $iSlot, int $iStoreSlot): void
	{
		$sUsername = $this->resolveAuthenticatedUsername($oRequest, $iSlot);
		if ($sUsername === null) {
			return;
		}
		$sKey = $oRequest->getSourceNetwork() . '.' . $oRequest->getSourceStation();
		$this->aPendingStoreSave[$sKey] = ['username' => $sUsername, 'slot' => $iStoreSlot];
	}

	protected function handleStoreSaveData(MaceMailRequest $oRequest): void
	{
		$sKey = $oRequest->getSourceNetwork() . '.' . $oRequest->getSourceStation();
		$aPending = $this->aPendingStoreSave[$sKey] ?? null;
		unset($this->aPendingStoreSave[$sKey]);
		if ($aPending === null) {
			// No matching op-8 quick command seen from this station — ignore
			// rather than guess which slot this data belongs to.
			return;
		}
		$sData = $oRequest->getRawBytes(1, self::STORE_SLOT_SIZE);
		$this->oStorage->setStoreSlot($aPending['username'], $aPending['slot'], $sData);
	}

	protected function handleDeleteStoreOp(MaceMailRequest $oRequest, int $iSlot, int $iNewMask): void
	{
		$sUsername = $this->resolveAuthenticatedUsername($oRequest, $iSlot);
		if ($sUsername === null) {
			return;
		}
		$this->oStorage->setStoreMask($sUsername, $iNewMask);
	}

	// -------------------------------------------------------------------------
	// Mailbox scan / Look (ops 17/18, ports 0x29/0x2A/0x2B) — a paged
	// mechanism for summarising mailbox contents: scan (0x29) hands back the
	// user's own message ids, then the client requests full summaries for up
	// to 6 of them at a time via a look request/reply (0x2A/0x2B), each
	// summary being a fixed 35-byte record (the same record size the
	// individual-mail-item reply's sender-slot addressing is built on — see
	// handleIndividualMailOp()).
	// -------------------------------------------------------------------------

	private const int LOOK_RECORD_SIZE  = 35;
	private const int LOOK_MAX_PER_BATCH = 6;

	protected function handleMailboxScanOp(MaceMailRequest $oRequest, int $iSlot): void
	{
		$sUsername = $this->resolveAuthenticatedUsername($oRequest, $iSlot);
		if ($sUsername === null) {
			return;
		}

		$aIds = array_map(fn($aEntry) => $this->_asInt($aEntry['id'] ?? 0), $this->oStorage->getMailIndex($sUsername));

		$oRequest->setReplyPort(self::PORT_MAILBOX_SCAN_REPLY);
		$oReply = $oRequest->buildReply();
		// 512-byte buffer, one id byte per message.
		foreach (array_slice($aIds, 0, 512) as $iId) {
			$oReply->appendByte($iId & 0xFF);
		}
		for ($i = count($aIds); $i < 512; $i++) {
			$oReply->appendByte(0);
		}
		$this->_addReplyToBuffer($oReply);
	}

	protected function handleLookRequest(MaceMailRequest $oRequest): void
	{
		$iNet = $oRequest->getSourceNetwork();
		$iStn = $oRequest->getSourceStation();
		if (!$this->secIsLoggedIn($iNet, $iStn)) {
			return;
		}
		$oUser = $this->secGetUser($iNet, $iStn);
		if (!($oUser instanceof User)) {
			return;
		}
		$sUsername = (string) $oUser->getUsername();
		$this->secUpdateIdleTimer($iNet, $iStn);

		$oRequest->setReplyPort(self::PORT_LOOK_REPLY);
		$oReply = $oRequest->buildReply();

		for ($i = 0; $i < self::LOOK_MAX_PER_BATCH; $i++) {
			$iId    = (int) $oRequest->getByte($i + 1);
			$aEntry = $iId > 0 ? $this->oStorage->getMailItem($sUsername, $iId) : null;
			if ($aEntry === null) {
				for ($j = 0; $j < self::LOOK_RECORD_SIZE; $j++) {
					$oReply->appendByte(0);
				}
				continue;
			}

			$oReply->appendByte($iId);
			$oReply->appendByte($this->_asInt($aEntry['sender_slot'] ?? 64));
			$iFlags = (!empty($aEntry['read']) ? 1 : 0)
				| (!empty($aEntry['express']) ? 2 : 0)
				| (!empty($aEntry['ack_requested']) ? 4 : 0)
				| (!empty($aEntry['reply_requested']) ? 8 : 0);
			$oReply->appendByte($iFlags);
			$aDate = is_array($aEntry['date'] ?? null) ? array_values($aEntry['date']) : [0, 0, 0];
			$oReply->appendByte($this->_asInt($aDate[0] ?? 0));
			$oReply->appendByte($this->_asInt($aDate[1] ?? 0));
			$oReply->appendByte($this->_asInt($aDate[2] ?? 0));
			$sSubject = $this->_asString($aEntry['subject'] ?? '');
			$oReply->appendString(str_pad(substr($sSubject, 0, self::LOOK_RECORD_SIZE - 6), self::LOOK_RECORD_SIZE - 6, ' '));
		}

		$this->_addReplyToBuffer($oReply);
	}

	// -------------------------------------------------------------------------
	// Chat (op 15 -> async invite on 0x40; the chat text itself is peer to
	// peer on port 0x31 and never touches the server, so there is nothing
	// else to implement here) and availability (op 20, no reply)
	// -------------------------------------------------------------------------

	protected function handleChatOp(MaceMailRequest $oRequest, int $iSlot, int $iTargetSlot): void
	{
		$sCallerUsername = $this->resolveAuthenticatedUsername($oRequest, $iSlot);
		if ($sCallerUsername === null) {
			return;
		}
		$sTargetUsername = $this->oStorage->getUsernameForSlot($iTargetSlot);
		if ($sTargetUsername === null) {
			return;
		}
		if (!($this->aAvailability[strtoupper($sTargetUsername)] ?? true)) {
			return;
		}
		$aTargetStation = $this->secGetUsersStation($sTargetUsername);
		if (empty($aTargetStation)) {
			return;
		}
		$this->sendNotify(
			(int) $aTargetStation['network'],
			(int) $aTargetStation['station'],
			self::NOTIFY_CHAT_INVITE,
			$iSlot,
			$oRequest->getSourceStation()
		);
	}

	protected function handleSetAvailabilityOp(MaceMailRequest $oRequest, int $iSlot, int $iAvailable): void
	{
		$sUsername = $this->resolveAuthenticatedUsername($oRequest, $iSlot);
		if ($sUsername === null) {
			return;
		}
		$this->aAvailability[strtoupper($sUsername)] = ($iAvailable === 1);
	}

	// -------------------------------------------------------------------------
	// Admin support — used by MaceMail\Admin and its controller. These are
	// the only entry points into MaceMail state that don't come off the
	// wire, so they go through the same sec*() wrappers as everything else
	// for testability.
	// -------------------------------------------------------------------------

	/**
	 * @return array<int, array{slot: int, username: string, online: bool, last_used: string, store_mask: int}>
	*/
	public function getRegisteredSlots(): array
	{
		$aSlots = $this->oStorage->getAllSlots();
		ksort($aSlots);
		$aReturn = [];
		foreach ($aSlots as $iSlot => $sUsername) {
			$aStation                = $this->secGetUsersStation($sUsername);
			$aMeta                   = $this->oStorage->getUserMeta($sUsername);
			[$iDay, $iMonth, $iYear] = $aMeta['last_used'];
			$aReturn[] = [
				'slot'       => $iSlot,
				'username'   => $sUsername,
				'online'     => !empty($aStation),
				'last_used'  => sprintf('%02d/%02d/%02d', $iDay, $iMonth, $iYear),
				'store_mask' => $aMeta['store_mask'],
			];
		}
		return $aReturn;
	}

	/**
	 * @return array<int, array{username: string, network: int, station: int}>
	*/
	public function getOnlineMailUsers(): array
	{
		$aReturn = [];
		foreach ($this->oStorage->getAllSlots() as $sUsername) {
			$aStation = $this->secGetUsersStation($sUsername);
			if (!empty($aStation)) {
				$aReturn[] = ['username' => $sUsername, 'network' => (int) $aStation['network'], 'station' => (int) $aStation['station']];
			}
		}
		return $aReturn;
	}

	/**
	 * Assigns a MaceMail slot number to an existing filestore user. Throws
	 * on an out-of-range slot or an unknown username — the admin UI is the
	 * only path that provisions slots, there is no self-service signup.
	*/
	public function adminAssignSlot(int $iSlot, string $sUsername): void
	{
		$iMaxSlots = config::getValueAsInt('macemail_max_slots');
		if ($iSlot < 0 || $iSlot >= $iMaxSlots) {
			throw new \InvalidArgumentException("Slot must be between 0 and " . ($iMaxSlots - 1) . ".");
		}
		if ($this->secGetUserByName($sUsername) === null) {
			throw new \InvalidArgumentException("No such filestore user: " . $sUsername);
		}
		$this->oStorage->assignSlot($iSlot, $sUsername);
	}

	public function adminUnassignSlot(int $iSlot): void
	{
		$this->oStorage->unassignSlot($iSlot);
	}

	/**
	 * Forces a logged-on MaceMail user off: notifies their client (matching
	 * the original system-manager "Clear User" action), logs them out of
	 * Security, and drops their session-scoped state. A no-op if the user
	 * isn't currently online.
	*/
	public function adminForceLogoff(string $sUsername): void
	{
		$aStation = $this->secGetUsersStation($sUsername);
		if (empty($aStation)) {
			return;
		}
		$iNet = (int) $aStation['network'];
		$iStn = (int) $aStation['station'];
		$this->sendNotify($iNet, $iStn, self::NOTIFY_FORCED_LOGOFF, 0, 0);
		$this->secLogout($iNet, $iStn);
		unset($this->aSessionPassword[$iNet . '.' . $iStn]);
		unset($this->aAvailability[strtoupper($sUsername)]);
	}

	/**
	 * Sends one of the canned system-broadcast messages (see
	 * self::SYSTEM_MESSAGES) to every currently online MaceMail user.
	*/
	public function adminBroadcastMessage(int $iType): void
	{
		if (!array_key_exists($iType, self::SYSTEM_MESSAGES)) {
			throw new \InvalidArgumentException("Unknown system message type: " . $iType);
		}
		foreach ($this->getOnlineMailUsers() as $aUser) {
			$this->sendNotify($aUser['network'], $aUser['station'], $iType, 0, 0);
		}
	}

	// -------------------------------------------------------------------------
	// Config wrappers
	// -------------------------------------------------------------------------

	protected function getUsergroup(): string
	{
		return config::getValueAsString('macemail_usergroup');
	}

	/**
	 * Returns today's date as [day, month, 2-digit year]. Overridable in
	 * tests for deterministic assertions.
	 *
	 * @return array{0:int,1:int,2:int}
	*/
	protected function today(): array
	{
		return [(int) date('j'), (int) date('n'), (int) date('y')];
	}

	// -------------------------------------------------------------------------
	// Security wrappers — kept thin and protected so tests can override them
	// without touching the real static Security class, matching the pattern
	// already used by FileServer.
	// -------------------------------------------------------------------------

	protected function secIsLoggedIn(int $iNet, int $iStn): bool
	{ return Security::isLoggedIn($iNet, $iStn); }

	protected function secLogin(int $iNet, int $iStn, string $sUser, string $sPass): bool
	{ return Security::login($iNet, $iStn, $sUser, $sPass); }

	protected function secLogout(int $iNet, int $iStn): void
	{ Security::logout($iNet, $iStn); }

	protected function secGetUser(int $iNet, int $iStn): ?User
	{ return Security::getUser($iNet, $iStn); }

	/**
	 * @return array<string,int>
	*/
	protected function secGetUsersStation(string $sUser): array
	{ return Security::getUsersStation($sUser); }

	/**
	 * @return array<int,array<int,array<mixed>>>
	*/
	protected function secGetUsersOnline(): array
	{ return Security::getUsersOnline(); }

	protected function secUpdateIdleTimer(int $iNet, int $iStn): void
	{ Security::updateIdleTimer($iNet, $iStn); }

	protected function secGetUserByName(string $sUsername): ?User
	{ return Security::getUserByName($sUsername); }
}
