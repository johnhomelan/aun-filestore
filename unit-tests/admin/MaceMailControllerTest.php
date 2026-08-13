<?php

/*
 * Tests for HomeLan\FileStore\Admin\Controller\MaceMailController.
 *
 * A TestableMaceMailController subclass overrides findProvider() and
 * renderTemplate() so that:
 *   - The real ServiceDispatcher singleton is never touched
 *   - The Smarty template system is never invoked
 *   - Provider method calls are captured for assertion
 */

include_once(__DIR__ . '/../../src/include/system.inc.php');

use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\RedirectResponse;
use HomeLan\FileStore\Admin\Controller\MaceMailController;
use HomeLan\FileStore\Admin\Service\Smarty;
use HomeLan\FileStore\Services\Provider\MaceMail;

// ---------------------------------------------------------------------------
// Fake provider — a plain double exposing just the public admin API the
// controller calls, so the controller tests never touch a real MaceMail
// instance or its Storage dependency.
// ---------------------------------------------------------------------------
class FakeMaceMailForController extends MaceMail
{
    public array $aStubSlots  = [];
    public array $aStubOnline = [];

    public array $capAssignSlot   = [];
    public array $capUnassignSlot = [];
    public array $capForceLogoff  = [];
    public array $capBroadcast    = [];

    public ?\Exception $throwOnAssign    = null;
    public ?\Exception $throwOnUnassign  = null;
    public ?\Exception $throwOnLogoff    = null;
    public ?\Exception $throwOnBroadcast = null;

    public function getRegisteredSlots(): array { return $this->aStubSlots; }
    public function getOnlineMailUsers(): array { return $this->aStubOnline; }

    public function adminAssignSlot(int $iSlot, string $sUsername): void
    {
        if ($this->throwOnAssign) {
            throw $this->throwOnAssign;
        }
        $this->capAssignSlot[] = ['slot' => $iSlot, 'username' => $sUsername];
    }

    public function adminUnassignSlot(int $iSlot): void
    {
        if ($this->throwOnUnassign) {
            throw $this->throwOnUnassign;
        }
        $this->capUnassignSlot[] = $iSlot;
    }

    public function adminForceLogoff(string $sUsername): void
    {
        if ($this->throwOnLogoff) {
            throw $this->throwOnLogoff;
        }
        $this->capForceLogoff[] = $sUsername;
    }

    public function adminBroadcastMessage(int $iType): void
    {
        if ($this->throwOnBroadcast) {
            throw $this->throwOnBroadcast;
        }
        $this->capBroadcast[] = $iType;
    }
}

// ---------------------------------------------------------------------------
// Testable controller
// ---------------------------------------------------------------------------
class TestableMaceMailController extends MaceMailController
{
    public ?FakeMaceMailForController $stubProvider = null;
    public ?string $lastTemplate = null;
    public array   $lastVars     = [];

    protected function findProvider(): ?MaceMail
    {
        return $this->stubProvider;
    }

    protected function renderTemplate(Smarty $oSmartyService, string $sTemplate, array $aVars): Response
    {
        $this->lastTemplate = $sTemplate;
        $this->lastVars     = $aVars;
        return new Response('RENDERED:' . $sTemplate);
    }
}

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

function makeMaceMailSmartyStub(): Smarty
{
    return new class extends Smarty {
        public function getSmarty(): \Smarty\Smarty
        {
            return parent::getSmarty();
        }
    };
}

// ---------------------------------------------------------------------------
// Test class
// ---------------------------------------------------------------------------
class MaceMailControllerTest extends TestCase
{
    private TestableMaceMailController  $oController;
    private FakeMaceMailForController   $oProvider;
    private Smarty                      $oSmarty;

    protected function setUp(): void
    {
        $oLogger = new \Monolog\Logger('test');
        $oLogger->pushHandler(new \Monolog\Handler\NullHandler());

        $this->oProvider = new FakeMaceMailForController($oLogger, Mockery::mock(\HomeLan\FileStore\Services\Provider\MaceMail\Storage::class));

        $this->oController = new TestableMaceMailController();
        $this->oController->stubProvider = $this->oProvider;
        $this->oSmarty = makeMaceMailSmartyStub();
    }

    protected function tearDown(): void
    {
        Mockery::close();
    }

    // =========================================================================
    // Provider not found
    // =========================================================================

    public function testAssignSlotRedirectsWhenProviderNotFound(): void
    {
        $this->oController->stubProvider = null;
        $oRequest  = Request::create('/service/macemail/slots/assign', 'GET');
        $oResponse = $this->oController->assignSlot($this->oSmarty, $oRequest);

        $this->assertInstanceOf(RedirectResponse::class, $oResponse);
    }

    // =========================================================================
    // assignSlot()
    // =========================================================================

    public function testAssignSlotGetRendersForm(): void
    {
        $oRequest = Request::create('/service/macemail/slots/assign', 'GET');
        $this->oController->assignSlot($this->oSmarty, $oRequest);

        $this->assertSame('macemail-slots-assign.tpl', $this->oController->lastTemplate);
    }

    public function testAssignSlotPostCallsProviderWithCorrectArgs(): void
    {
        $oRequest = Request::create('/service/macemail/slots/assign', 'POST', [
            'slot' => '3', 'username' => 'jsmith',
        ]);
        $this->oController->assignSlot($this->oSmarty, $oRequest);

        $this->assertCount(1, $this->oProvider->capAssignSlot);
        $this->assertSame(3, $this->oProvider->capAssignSlot[0]['slot']);
        $this->assertSame('JSMITH', $this->oProvider->capAssignSlot[0]['username']);
    }

    public function testAssignSlotPostRedirectsOnSuccess(): void
    {
        $oRequest  = Request::create('/service/macemail/slots/assign', 'POST', [
            'slot' => '3', 'username' => 'JSMITH',
        ]);
        $oResponse = $this->oController->assignSlot($this->oSmarty, $oRequest);

        $this->assertInstanceOf(RedirectResponse::class, $oResponse);
        $this->assertStringContainsString('msg=assigned', $oResponse->getTargetUrl());
    }

    public function testAssignSlotPostShowsErrorWhenProviderThrows(): void
    {
        $this->oProvider->throwOnAssign = new \Exception('Slot out of range');
        $oRequest  = Request::create('/service/macemail/slots/assign', 'POST', [
            'slot' => '99', 'username' => 'JSMITH',
        ]);
        $oResponse = $this->oController->assignSlot($this->oSmarty, $oRequest);

        $this->assertNotInstanceOf(RedirectResponse::class, $oResponse);
        $this->assertStringContainsString('Slot out of range', $this->oController->lastVars['sError']);
    }

    // =========================================================================
    // unassignSlot()
    // =========================================================================

    public function testUnassignSlotGetRendersForm(): void
    {
        $oRequest = Request::create('/service/macemail/slots/unassign', 'GET');
        $this->oController->unassignSlot($this->oSmarty, $oRequest);

        $this->assertSame('macemail-slots-unassign.tpl', $this->oController->lastTemplate);
    }

    public function testUnassignSlotPostCallsProvider(): void
    {
        $oRequest = Request::create('/service/macemail/slots/unassign', 'POST', ['slot' => '3']);
        $this->oController->unassignSlot($this->oSmarty, $oRequest);

        $this->assertSame([3], $this->oProvider->capUnassignSlot);
    }

    public function testUnassignSlotPostRedirectsOnSuccess(): void
    {
        $oRequest  = Request::create('/service/macemail/slots/unassign', 'POST', ['slot' => '3']);
        $oResponse = $this->oController->unassignSlot($this->oSmarty, $oRequest);

        $this->assertInstanceOf(RedirectResponse::class, $oResponse);
        $this->assertStringContainsString('msg=unassigned', $oResponse->getTargetUrl());
    }

    public function testUnassignSlotPostShowsErrorWhenProviderThrows(): void
    {
        $this->oProvider->throwOnUnassign = new \Exception('Storage failure');
        $oRequest  = Request::create('/service/macemail/slots/unassign', 'POST', ['slot' => '3']);
        $oResponse = $this->oController->unassignSlot($this->oSmarty, $oRequest);

        $this->assertNotInstanceOf(RedirectResponse::class, $oResponse);
        $this->assertStringContainsString('Storage failure', $this->oController->lastVars['sError']);
    }

    // =========================================================================
    // forceLogoff()
    // =========================================================================

    public function testForceLogoffGetRendersForm(): void
    {
        $oRequest = Request::create('/service/macemail/logoff', 'GET');
        $this->oController->forceLogoff($this->oSmarty, $oRequest);

        $this->assertSame('macemail-logoff.tpl', $this->oController->lastTemplate);
    }

    public function testForceLogoffPostCallsProviderWithUppercasedUsername(): void
    {
        $oRequest = Request::create('/service/macemail/logoff', 'POST', ['username' => 'jsmith']);
        $this->oController->forceLogoff($this->oSmarty, $oRequest);

        $this->assertSame(['JSMITH'], $this->oProvider->capForceLogoff);
    }

    public function testForceLogoffPostRedirectsOnSuccess(): void
    {
        $oRequest  = Request::create('/service/macemail/logoff', 'POST', ['username' => 'JSMITH']);
        $oResponse = $this->oController->forceLogoff($this->oSmarty, $oRequest);

        $this->assertInstanceOf(RedirectResponse::class, $oResponse);
        $this->assertStringContainsString('msg=loggedoff', $oResponse->getTargetUrl());
    }

    // =========================================================================
    // broadcast()
    // =========================================================================

    public function testBroadcastGetRendersFormWithMessageTypes(): void
    {
        $oRequest = Request::create('/service/macemail/broadcast', 'GET');
        $this->oController->broadcast($this->oSmarty, $oRequest);

        $this->assertSame('macemail-broadcast.tpl', $this->oController->lastTemplate);
        $this->assertSame(MaceMail::SYSTEM_MESSAGES, $this->oController->lastVars['aMessageTypes']);
    }

    public function testBroadcastPostCallsProviderWithSelectedType(): void
    {
        $oRequest = Request::create('/service/macemail/broadcast', 'POST', ['type' => '3']);
        $this->oController->broadcast($this->oSmarty, $oRequest);

        $this->assertSame([3], $this->oProvider->capBroadcast);
    }

    public function testBroadcastPostRedirectsOnSuccess(): void
    {
        $oRequest  = Request::create('/service/macemail/broadcast', 'POST', ['type' => '3']);
        $oResponse = $this->oController->broadcast($this->oSmarty, $oRequest);

        $this->assertInstanceOf(RedirectResponse::class, $oResponse);
        $this->assertStringContainsString('msg=sent', $oResponse->getTargetUrl());
    }

    public function testBroadcastPostShowsErrorWhenProviderThrows(): void
    {
        $this->oProvider->throwOnBroadcast = new \Exception('Unknown system message type');
        $oRequest  = Request::create('/service/macemail/broadcast', 'POST', ['type' => '99']);
        $oResponse = $this->oController->broadcast($this->oSmarty, $oRequest);

        $this->assertNotInstanceOf(RedirectResponse::class, $oResponse);
        $this->assertStringContainsString('Unknown system message type', $this->oController->lastVars['sError']);
    }
}
