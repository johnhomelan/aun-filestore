<?php

/*
 * @group unit-tests
 *
 * Tests for HomeLan\FileStore\Services\Provider\PrintServer\PrinterRegistry.
 * All tests pass INI content directly to the constructor — no file I/O.
 */

use PHPUnit\Framework\TestCase;
use HomeLan\FileStore\Services\Provider\PrintServer\PrinterRegistry;
use HomeLan\FileStore\Services\Provider\PrintServer\Printer;
use HomeLan\FileStore\Authentication\User;

include_once('include/system.inc.php');

// ---------------------------------------------------------------------------
// Minimal user stub
// ---------------------------------------------------------------------------
class RegistryFakeUser extends User
{
    public function __construct(string $sUsername)
    {
        $this->setUsername($sUsername);
    }
}

class PrinterRegistryTest extends TestCase
{
    // -----------------------------------------------------------------------
    // Default fallback (empty / missing content)
    // -----------------------------------------------------------------------

    public function testEmptyContentFallsBackToDefaultPrint(): void
    {
        $oReg = new PrinterRegistry('');
        $this->assertInstanceOf(Printer::class, $oReg->getByName('PRINT'));
    }

    public function testNullInvalidFileReturnsPrint(): void
    {
        // Can't pass null without a real file; use an empty string instead.
        $oReg = new PrinterRegistry('');
        $this->assertCount(1, $oReg->getEnabled());
        $this->assertSame('PRINT', $oReg->getEnabled()[0]->getName());
    }

    public function testDefaultPrinterIsEnabled(): void
    {
        $oReg = new PrinterRegistry('');
        $this->assertTrue($oReg->getByName('PRINT')->isEnabled());
    }

    public function testDefaultPrinterAllowsAllUsers(): void
    {
        $oReg = new PrinterRegistry('');
        $this->assertTrue($oReg->getByName('PRINT')->isUserAllowed(null));
        $this->assertTrue($oReg->getByName('PRINT')->isUserAllowed(new RegistryFakeUser('ANYONE')));
    }

    // -----------------------------------------------------------------------
    // Parsing a single printer
    // -----------------------------------------------------------------------

    public function testSinglePrinterParsedCorrectly(): void
    {
        $oReg = new PrinterRegistry("[LASER]\ndescription=Laser\nenabled=yes\nbehavior=script\nscript=/usr/bin/topdf %source% %destination%\nallowed_users=");
        $oPrinter = $oReg->getByName('LASER');
        $this->assertNotNull($oPrinter);
        $this->assertSame('LASER', $oPrinter->getName());
        $this->assertSame('Laser', $oPrinter->getDescription());
        $this->assertTrue($oPrinter->isEnabled());
        $this->assertSame('script', $oPrinter->getBehavior());
        $this->assertSame('/usr/bin/topdf %source% %destination%', $oPrinter->getScript());
    }

    public function testPrinterNameIsNormalisedToUpperCase(): void
    {
        $oReg = new PrinterRegistry("[laser]\nenabled=yes\nbehavior=spool\nscript=\nallowed_users=");
        $this->assertNotNull($oReg->getByName('LASER'));
        $this->assertNotNull($oReg->getByName('laser'));
    }

    public function testPrinterNameTruncatedToSixChars(): void
    {
        $oReg = new PrinterRegistry("[TOOLONG]\nenabled=yes\nbehavior=spool\nscript=\nallowed_users=");
        $this->assertNotNull($oReg->getByName('TOOLON'));
        $this->assertNull($oReg->getByName('TOOLONG'));
    }

    public function testGetByNameReturnsNullForUnknownPrinter(): void
    {
        $oReg = new PrinterRegistry("[PRINT]\nenabled=yes\nbehavior=spool\nscript=\nallowed_users=");
        $this->assertNull($oReg->getByName('FAKE'));
    }

    // -----------------------------------------------------------------------
    // Multiple printers
    // -----------------------------------------------------------------------

    private function multiIni(): string
    {
        return <<<INI
[PRINT]
description   = Default
enabled       = yes
behavior      = spool
script        =
allowed_users =

[LASER]
description   = Laser
enabled       = yes
behavior      = script
script        = /usr/bin/topdf %source% %destination%
allowed_users =

[NULL]
description   = Discard
enabled       = no
behavior      = discard
script        =
allowed_users =
INI;
    }

    public function testGetAllReturnsAllPrinters(): void
    {
        $oReg = new PrinterRegistry($this->multiIni());
        $this->assertCount(3, $oReg->getAll());
    }

    public function testGetEnabledExcludesDisabledPrinters(): void
    {
        $oReg = new PrinterRegistry($this->multiIni());
        $aEnabled = $oReg->getEnabled();
        $this->assertCount(2, $aEnabled);
        foreach ($aEnabled as $oPrinter) {
            $this->assertTrue($oPrinter->isEnabled());
        }
    }

    public function testGetAllPlacesEnabledBeforeDisabled(): void
    {
        $oReg = new PrinterRegistry($this->multiIni());
        $aAll = $oReg->getAll();
        // NULL (disabled) should be last
        $this->assertFalse($aAll[count($aAll) - 1]->isEnabled());
    }

    public function testDisabledPrinterIsNotEnabled(): void
    {
        $oReg = new PrinterRegistry($this->multiIni());
        $this->assertFalse($oReg->getByName('NULL')->isEnabled());
    }

    // -----------------------------------------------------------------------
    // allowed_users
    // -----------------------------------------------------------------------

    public function testEmptyAllowedUsersPermitsAllUsers(): void
    {
        $oReg = new PrinterRegistry("[OPEN]\nenabled=yes\nbehavior=spool\nscript=\nallowed_users=");
        $oPrinter = $oReg->getByName('OPEN');
        $this->assertTrue($oPrinter->isUserAllowed(null));
        $this->assertTrue($oPrinter->isUserAllowed(new RegistryFakeUser('ANYONE')));
    }

    public function testAllowedUsersRestrictsAccess(): void
    {
        $oReg = new PrinterRegistry("[PRIV]\nenabled=yes\nbehavior=spool\nscript=\nallowed_users=SYSOP,ADMIN");
        $oPrinter = $oReg->getByName('PRIV');
        $this->assertTrue($oPrinter->isUserAllowed(new RegistryFakeUser('SYSOP')));
        $this->assertTrue($oPrinter->isUserAllowed(new RegistryFakeUser('ADMIN')));
        $this->assertFalse($oPrinter->isUserAllowed(new RegistryFakeUser('GUEST')));
    }

    public function testAllowedUsersIsCaseInsensitive(): void
    {
        $oReg = new PrinterRegistry("[PRIV]\nenabled=yes\nbehavior=spool\nscript=\nallowed_users=SYSOP");
        $oPrinter = $oReg->getByName('PRIV');
        $this->assertTrue($oPrinter->isUserAllowed(new RegistryFakeUser('sysop')));
        $this->assertTrue($oPrinter->isUserAllowed(new RegistryFakeUser('Sysop')));
    }

    public function testRestrictedPrinterDeniesNullUser(): void
    {
        $oReg = new PrinterRegistry("[PRIV]\nenabled=yes\nbehavior=spool\nscript=\nallowed_users=SYSOP");
        $oPrinter = $oReg->getByName('PRIV');
        $this->assertFalse($oPrinter->isUserAllowed(null));
    }

    // -----------------------------------------------------------------------
    // Behavior values
    // -----------------------------------------------------------------------

    public function testSpoolBehaviorParsed(): void
    {
        $oReg = new PrinterRegistry("[P]\nenabled=yes\nbehavior=spool\nscript=\nallowed_users=");
        $this->assertSame('spool', $oReg->getByName('P')->getBehavior());
    }

    public function testScriptBehaviorParsed(): void
    {
        $oReg = new PrinterRegistry("[P]\nenabled=yes\nbehavior=script\nscript=/bin/x\nallowed_users=");
        $this->assertSame('script', $oReg->getByName('P')->getBehavior());
    }

    public function testDiscardBehaviorParsed(): void
    {
        $oReg = new PrinterRegistry("[P]\nenabled=yes\nbehavior=discard\nscript=\nallowed_users=");
        $this->assertSame('discard', $oReg->getByName('P')->getBehavior());
    }
}
