<?php

namespace HomeLan\FileStore\Services\Provider\MaceMail;

/**
 * Native, file-based storage for the MaceMail service provider.
 *
 * This is deliberately independent of the Vfs/Vfs\Plugin layer — MaceMail
 * owns a directory tree of its own (configured via macemail_store_dir) and
 * talks to it directly with plain PHP filesystem calls. It has no notion of
 * Econet paths or VFS plugins.
 *
 * Layout:
 *   {base}/slots.json                    — {"<slot>": "<USERNAME>", ...}
 *   {base}/users/{USERNAME}/meta.json    — registration/last-used dates, store bitmask
 *   {base}/users/{USERNAME}/mail/index.json   — mailbox header records
 *   {base}/users/{USERNAME}/mail/{id}.msg     — message bodies
 *   {base}/users/{USERNAME}/store/{0..7}.dat  — the 8 per-user store slots
 *
 * Every method here is the boundary that provider unit tests mock out
 * (`Mockery::mock(Storage::class)`) so that MaceMail's own tests never touch
 * a real filesystem. Storage's own test suite is the one place real
 * temporary directories are used, to verify the on-disk layout itself.
 *
 * @package core
 */
class Storage
{
	public function __construct(protected readonly string $sBaseDir)
	{
	}

	/** Decoded JSON gives back `mixed`; safely narrow to scalar. */
	private function _asInt(mixed $mValue): int
	{
		return is_scalar($mValue) ? (int) $mValue : 0;
	}

	private function _asString(mixed $mValue): string
	{
		return is_scalar($mValue) ? (string) $mValue : '';
	}

	/**
	 * Narrows a decoded mail-index entry to array<string,mixed>, or null if
	 * it isn't array-shaped (corrupt/foreign JSON).
	 *
	 * @return array<string,mixed>|null
	 */
	private function _asIndexEntry(mixed $mEntry): ?array
	{
		if (!is_array($mEntry)) {
			return null;
		}
		$aResult = [];
		foreach ($mEntry as $mKey => $mValue) {
			$aResult[(string) $mKey] = $mValue;
		}
		return $aResult;
	}

	/**
	 * Narrows a decoded [day,month,year] triple to array{int,int,int}.
	 *
	 * @return array{int,int,int}
	 */
	private function _asDateTriple(mixed $mValue): array
	{
		if (!is_array($mValue) || count($mValue) !== 3) {
			return [0, 0, 0];
		}
		$aValues = array_values($mValue);
		return [$this->_asInt($aValues[0]), $this->_asInt($aValues[1]), $this->_asInt($aValues[2])];
	}

	// -------------------------------------------------------------------------
	// Slot registry
	// -------------------------------------------------------------------------

	/**
	 * Looks up the username assigned to a MaceMail slot number (0–macemail_max_slots-1).
	 */
	public function getUsernameForSlot(int $iSlot): ?string
	{
		$aSlots = $this->_readSlots();
		return $aSlots[$iSlot] ?? null;
	}

	/**
	 * Looks up the slot number assigned to a username, if any.
	 */
	public function getSlotForUsername(string $sUsername): ?int
	{
		$sUsername = strtoupper($sUsername);
		foreach ($this->_readSlots() as $iSlot => $sSlotUser) {
			if (strtoupper($sSlotUser) === $sUsername) {
				return $iSlot;
			}
		}
		return null;
	}

	/**
	 * Returns the full slot => username table.
	 *
	 * @return array<int, string>
	 */
	public function getAllSlots(): array
	{
		return $this->_readSlots();
	}

	/**
	 * Assigns a slot number to a username, replacing any existing assignment
	 * for that slot. Does not verify the username exists in the auth system —
	 * that is the caller's responsibility.
	 */
	public function assignSlot(int $iSlot, string $sUsername): void
	{
		$aSlots = $this->_readSlots();
		$aSlots[$iSlot] = strtoupper($sUsername);
		$this->_writeSlots($aSlots);
	}

	/**
	 * Frees a slot number so it can be reassigned.
	 */
	public function unassignSlot(int $iSlot): void
	{
		$aSlots = $this->_readSlots();
		unset($aSlots[$iSlot]);
		$this->_writeSlots($aSlots);
	}

	/**
	 * @return array<int,string>
	*/
	protected function _readSlots(): array
	{
		$aSlots = $this->_readJson($this->sBaseDir . '/slots.json') ?? [];
		$aReturn = [];
		foreach ($aSlots as $sSlot => $sUsername) {
			$aReturn[(int) $sSlot] = $this->_asString($sUsername);
		}
		return $aReturn;
	}

	/**
	 * @param array<int,string> $aSlots
	*/
	protected function _writeSlots(array $aSlots): void
	{
		$this->_writeJson($this->sBaseDir . '/slots.json', $aSlots);
	}

	// -------------------------------------------------------------------------
	// Per-user metadata
	// -------------------------------------------------------------------------

	/**
	 * Returns metadata for a user, creating sensible defaults if none is
	 * stored yet (a user who has never logged on before).
	 *
	 * @return array{registered: array{int,int,int}, last_used: array{int,int,int}, store_mask: int}
	 */
	public function getUserMeta(string $sUsername): array
	{
		$aMeta = $this->_readJson($this->_userDir($sUsername) . '/meta.json');
		if ($aMeta === null) {
			$aToday = [(int) date('j'), (int) date('n'), (int) date('y')];
			return ['registered' => $aToday, 'last_used' => $aToday, 'store_mask' => 0];
		}
		return [
			'registered' => $this->_asDateTriple($aMeta['registered'] ?? null),
			'last_used'  => $this->_asDateTriple($aMeta['last_used'] ?? null),
			'store_mask' => $this->_asInt($aMeta['store_mask'] ?? 0),
		];
	}

	/**
	 * Records that a user has just logged on, updating their last-used date.
	 * Also stamps the registration date if this is their first ever logon.
	 */
	public function touchLastUsed(string $sUsername, int $iDay, int $iMonth, int $iYear): void
	{
		$sDir = $this->_userDir($sUsername);
		$this->_ensureDir($sDir);
		$aMeta = $this->_readJson($sDir . '/meta.json');
		if ($aMeta === null) {
			$aMeta = ['registered' => [$iDay, $iMonth, $iYear], 'store_mask' => 0];
		}
		$aMeta['last_used'] = [$iDay, $iMonth, $iYear];
		$this->_writeJson($sDir . '/meta.json', $aMeta);
	}

	protected function _userDir(string $sUsername): string
	{
		return $this->sBaseDir . '/users/' . strtoupper($sUsername);
	}

	// -------------------------------------------------------------------------
	// Mailbox counts
	// -------------------------------------------------------------------------

	/**
	 * Returns unread/read × normal/express message counts for a user's
	 * mailbox index. Returns all-zero counts for a user with no mail yet.
	 *
	 * @return array{unread_normal: int, unread_express: int, read_normal: int, read_express: int}
	 */
	public function getMailCounts(string $sUsername): array
	{
		$aIndex = $this->_readJson($this->_userDir($sUsername) . '/mail/index.json') ?? [];
		$aCounts = ['unread_normal' => 0, 'unread_express' => 0, 'read_normal' => 0, 'read_express' => 0];
		foreach ($aIndex as $mEntry) {
			$aEntry = $this->_asIndexEntry($mEntry);
			if ($aEntry === null) {
				continue;
			}
			$bExpress = !empty($aEntry['express']);
			$bRead    = !empty($aEntry['read']);
			$sKey     = ($bRead ? 'read' : 'unread') . '_' . ($bExpress ? 'express' : 'normal');
			$aCounts[$sKey]++;
		}
		return $aCounts;
	}

	// -------------------------------------------------------------------------
	// Mail items
	// -------------------------------------------------------------------------

	/**
	 * Appends a new mail item to a user's mailbox index and stores its body.
	 * Returns the new item's id (unique per-recipient, starting at 1).
	 *
	 * @param array{sender_slot:int,subject:string,type:int,date:array{int,int,int},express:bool,ack_requested:bool,reply_requested:bool} $aHeader
	 */
	public function addMailItem(string $sUsername, array $aHeader, string $sBody): int
	{
		$sDir   = $this->_userDir($sUsername) . '/mail';
		$aIndex = $this->_readJson($sDir . '/index.json') ?? [];

		$iId = 1;
		foreach ($aIndex as $mEntry) {
			$aEntry = $this->_asIndexEntry($mEntry);
			if ($aEntry === null) {
				continue;
			}
			$iId = max($iId, $this->_asInt($aEntry['id'] ?? 0) + 1);
		}

		$aHeader['id']   = $iId;
		$aHeader['read'] = false;
		$aIndex[]        = $aHeader;

		$this->_writeJson($sDir . '/index.json', $aIndex);
		$this->_ensureDir($sDir);
		$this->putFileContents($sDir . '/' . $iId . '.msg', $sBody);

		return $iId;
	}

	/**
	 * Returns every mailbox index entry (headers only, no body) for a user.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function getMailIndex(string $sUsername): array
	{
		$aIndex = $this->_readJson($this->_userDir($sUsername) . '/mail/index.json') ?? [];
		$aReturn = [];
		foreach ($aIndex as $mEntry) {
			$aEntry = $this->_asIndexEntry($mEntry);
			if ($aEntry !== null) {
				$aReturn[] = $aEntry;
			}
		}
		return $aReturn;
	}

	/**
	 * Returns a single mailbox index entry (header only), or null if the
	 * user has no message with that id.
	 *
	 * @return array<string,mixed>|null
	 */
	public function getMailItem(string $sUsername, int $iId): ?array
	{
		foreach ($this->getMailIndex($sUsername) as $aEntry) {
			if ($this->_asInt($aEntry['id'] ?? 0) === $iId) {
				return $aEntry;
			}
		}
		return null;
	}

	public function getMailBody(string $sUsername, int $iId): string
	{
		$sPath = $this->_userDir($sUsername) . '/mail/' . $iId . '.msg';
		if (!$this->fileExists($sPath)) {
			return '';
		}
		$sData = $this->getFileContents($sPath);
		return $sData === false ? '' : $sData;
	}

	/**
	 * Marks a mailbox item as read. No-op if the item does not exist.
	 */
	public function markMailRead(string $sUsername, int $iId): void
	{
		$sDir   = $this->_userDir($sUsername) . '/mail';
		$aIndex = $this->_readJson($sDir . '/index.json') ?? [];
		foreach ($aIndex as &$mEntry) {
			if (!is_array($mEntry)) {
				continue;
			}
			if ($this->_asInt($mEntry['id'] ?? 0) === $iId) {
				$mEntry['read'] = true;
			}
		}
		unset($mEntry);
		$this->_writeJson($sDir . '/index.json', $aIndex);
	}

	/**
	 * Removes a mailbox item and its stored body. No-op if it does not exist.
	 */
	public function deleteMailItem(string $sUsername, int $iId): void
	{
		$sDir   = $this->_userDir($sUsername) . '/mail';
		$aIndex = $this->_readJson($sDir . '/index.json') ?? [];
		$aIndex = array_values(array_filter($aIndex, fn($mEntry) => !is_array($mEntry) || $this->_asInt($mEntry['id'] ?? 0) !== $iId));
		$this->_writeJson($sDir . '/index.json', $aIndex);

		$sMsgPath = $sDir . '/' . $iId . '.msg';
		if ($this->fileExists($sMsgPath)) {
			$this->deleteFile($sMsgPath);
		}
	}

	// -------------------------------------------------------------------------
	// Store slots — 8 general-purpose slots per user (MaceMail never
	// inspects their contents, just saves/recalls them verbatim)
	// -------------------------------------------------------------------------

	/**
	 * Returns the raw contents of a store slot, or '' if it has never been
	 * saved to.
	 */
	public function getStoreSlot(string $sUsername, int $iSlot): string
	{
		$sPath = $this->_userDir($sUsername) . '/store/' . $iSlot . '.dat';
		if (!$this->fileExists($sPath)) {
			return '';
		}
		$sData = $this->getFileContents($sPath);
		return $sData === false ? '' : $sData;
	}

	/**
	 * Saves data to a store slot and sets its bit in the user's store-usage
	 * bitmask.
	 */
	public function setStoreSlot(string $sUsername, int $iSlot, string $sData): void
	{
		$sDir = $this->_userDir($sUsername) . '/store';
		$this->_ensureDir($sDir);
		$this->putFileContents($sDir . '/' . $iSlot . '.dat', $sData);

		$aMeta = $this->getUserMeta($sUsername);
		$aMeta['store_mask'] = $aMeta['store_mask'] | (1 << $iSlot);
		$this->_writeJson($this->_userDir($sUsername) . '/meta.json', $aMeta);
	}

	/**
	 * Overwrites the user's whole store-usage bitmask with a client-supplied
	 * value — matching the original protocol's "delete store" operation,
	 * which sends the already-locally-computed new mask rather than a
	 * single slot to clear. The underlying slot data is left untouched, the
	 * same as the original (a slot's bit being clear just means the client
	 * treats it as free to overwrite next time).
	 */
	public function setStoreMask(string $sUsername, int $iMask): void
	{
		$aMeta = $this->getUserMeta($sUsername);
		$aMeta['store_mask'] = $iMask;
		$this->_writeJson($this->_userDir($sUsername) . '/meta.json', $aMeta);
	}

	// -------------------------------------------------------------------------
	// Low-level filesystem access — the only methods that touch real disk
	// -------------------------------------------------------------------------

	/**
	 * @return array<int|string,mixed>|null
	*/
	protected function _readJson(string $sPath): ?array
	{
		if (!$this->fileExists($sPath)) {
			return null;
		}
		$sData = $this->getFileContents($sPath);
		if ($sData === false || $sData === '') {
			return null;
		}
		$aData = json_decode($sData, true);
		return is_array($aData) ? $aData : null;
	}

	/**
	 * @param array<int|string,mixed> $aData
	*/
	protected function _writeJson(string $sPath, array $aData): void
	{
		$this->_ensureDir(dirname($sPath));
		$this->putFileContents($sPath, json_encode($aData, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
	}

	protected function _ensureDir(string $sPath): void
	{
		if (!$this->isDir($sPath)) {
			$this->makeDir($sPath);
		}
	}

	protected function fileExists(string $sPath): bool
	{
		return file_exists($sPath);
	}

	protected function isDir(string $sPath): bool
	{
		return is_dir($sPath);
	}

	protected function makeDir(string $sPath): void
	{
		mkdir($sPath, 0755, true);
	}

	protected function getFileContents(string $sPath): string|false
	{
		return file_get_contents($sPath);
	}

	protected function putFileContents(string $sPath, string $sData): void
	{
		file_put_contents($sPath, $sData);
	}

	protected function deleteFile(string $sPath): void
	{
		unlink($sPath);
	}
}
