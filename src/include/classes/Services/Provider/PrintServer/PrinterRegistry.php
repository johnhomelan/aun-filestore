<?php
/**
 * This file contains the PrinterRegistry class
 *
 * @author John Brown <john@home-lan.co.uk>
 * @package core
*/
namespace HomeLan\FileStore\Services\Provider\PrintServer;

use config;

/**
 * Loads and exposes the set of configured virtual printers from printers.cfg.
 *
 * The constructor accepts an optional string containing INI content.  When null,
 * it reads the file pointed to by the config key 'print_server_printers_file'.
 * Passing a string directly is used in tests to avoid any file-system access.
 *
 * If the config file is absent or unparseable the registry falls back to a single
 * default PRINT printer using 'script' behaviour, which preserves the same
 * semantics as the previous single-queue implementation (raw save + optional
 * conversion script).
 *
 * @package core
*/
class PrinterRegistry
{
	/** @var Printer[] keyed by uppercase printer name */
	private array $aPrinters = [];

	private static function _asString(mixed $mValue): string
	{
		return is_scalar($mValue) ? (string) $mValue : '';
	}

	public function __construct(?string $sContent = null)
	{
		if (is_null($sContent)) {
			$sFile = config::getValueAsString('print_server_printers_file');
			if (!file_exists($sFile)) {
				$this->addDefaultPrinter();
				return;
			}
			$sContent = file_get_contents($sFile);
		}

		if (!is_string($sContent) || trim($sContent) === '') {
			$this->addDefaultPrinter();
			return;
		}

		$aSections = parse_ini_string($sContent, true, INI_SCANNER_RAW);
		if ($aSections === false || empty($aSections)) {
			$this->addDefaultPrinter();
			return;
		}

		foreach ($aSections as $sName => $aFields) {
			if (!is_array($aFields)) {
				continue;
			}
			$sName        = strtoupper(substr(trim((string) $sName), 0, 6));
			$bEnabled     = strtolower(self::_asString($aFields['enabled'] ?? 'yes'))   === 'yes';
			$sBehavior    = strtolower(self::_asString($aFields['behavior'] ?? 'spool'));
			$sScript      = trim(self::_asString($aFields['script'] ?? ''));
			$sDescription = trim(self::_asString($aFields['description'] ?? ''));
			$sAllowed     = trim(self::_asString($aFields['allowed_users'] ?? ''));
			$aAllowed     = $sAllowed !== '' ? array_map('trim', explode(',', $sAllowed)) : [];
			$this->aPrinters[$sName] = new Printer(
				$sName, $sDescription, $bEnabled, $sBehavior, $sScript, $aAllowed
			);
		}

		if (empty($this->aPrinters)) {
			$this->addDefaultPrinter();
		}
	}

	private function addDefaultPrinter(): void
	{
		// 'script' behaviour matches the pre-multi-printer behaviour: save raw and
		// invoke the global print_server_conversion_script if one is configured.
		$this->aPrinters['PRINT'] = new Printer('PRINT', 'Default printer', true, 'script', '', []);
	}

	public function getByName(string $sName): ?Printer
	{
		return $this->aPrinters[strtoupper($sName)] ?? null;
	}

	/**
	 * All printers, enabled first then disabled.
	 *
	 * @return array<int,Printer>
	*/
	public function getAll(): array
	{
		$aEnabled  = array_values(array_filter($this->aPrinters, fn($p) => $p->isEnabled()));
		$aDisabled = array_values(array_filter($this->aPrinters, fn($p) => !$p->isEnabled()));
		return array_merge($aEnabled, $aDisabled);
	}

	/**
	 * Enabled printers only.
	 *
	 * @return array<int,Printer>
	*/
	public function getEnabled(): array
	{
		return array_values(array_filter($this->aPrinters, fn($p) => $p->isEnabled()));
	}
}
