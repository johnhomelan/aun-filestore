<?php

/*
 * Tests for HomeLan\FileStore\Admin\Controller\TeletextController.
 *
 * A TestableTeletextController subclass overrides findProvider() and
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
use HomeLan\FileStore\Admin\Controller\TeletextController;
use HomeLan\FileStore\Admin\Service\Smarty;
use HomeLan\FileStore\Services\Provider\Teletext;

// ---------------------------------------------------------------------------
// Fake provider — exposes just the public admin API the controller calls.
// ---------------------------------------------------------------------------
class FakeTeletextForController extends Teletext
{
    public bool $stubTriggerResult = true;
    public int  $capTriggerCalls   = 0;

    public function triggerTeefaxImport(): bool
    {
        $this->capTriggerCalls++;
        return $this->stubTriggerResult;
    }
}

// ---------------------------------------------------------------------------
// Testable controller
// ---------------------------------------------------------------------------
class TestableTeletextController extends TeletextController
{
    public ?FakeTeletextForController $stubProvider = null;
    public ?string $lastTemplate = null;
    public array   $lastVars     = [];

    protected function findProvider(): ?Teletext
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

function makeTeletextSmartyStub(): Smarty
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
class TeletextControllerTest extends TestCase
{
    private TestableTeletextController $oController;
    private FakeTeletextForController  $oProvider;
    private Smarty                     $oSmarty;

    protected function setUp(): void
    {
        $oLogger = new \Monolog\Logger('test');
        $oLogger->pushHandler(new \Monolog\Handler\NullHandler());

        $this->oProvider = new FakeTeletextForController($oLogger, Mockery::mock(\HomeLan\FileStore\Services\Provider\Teletext\Storage::class));

        $this->oController = new TestableTeletextController();
        $this->oController->stubProvider = $this->oProvider;
        $this->oSmarty = makeTeletextSmartyStub();
    }

    protected function tearDown(): void
    {
        Mockery::close();
    }

    public function testRedirectsWhenProviderNotFound(): void
    {
        $this->oController->stubProvider = null;
        $oRequest  = Request::create('/service/teletext/teefax-refresh', 'GET');
        $oResponse = $this->oController->teefaxRefresh($this->oSmarty, $oRequest);

        $this->assertInstanceOf(RedirectResponse::class, $oResponse);
    }

    public function testGetRendersConfirmationTemplate(): void
    {
        $oRequest = Request::create('/service/teletext/teefax-refresh', 'GET');
        $this->oController->teefaxRefresh($this->oSmarty, $oRequest);

        $this->assertSame('teletext-teefax-refresh.tpl', $this->oController->lastTemplate);
        $this->assertSame(0, $this->oProvider->capTriggerCalls);
    }

    public function testGetPassesQueryMessageToTemplate(): void
    {
        $oRequest = Request::create('/service/teletext/teefax-refresh?msg=started', 'GET');
        $this->oController->teefaxRefresh($this->oSmarty, $oRequest);

        $this->assertSame('started', $this->oController->lastVars['sMessage']);
    }

    public function testPostCallsTriggerTeefaxImport(): void
    {
        $oRequest = Request::create('/service/teletext/teefax-refresh', 'POST');
        $this->oController->teefaxRefresh($this->oSmarty, $oRequest);

        $this->assertSame(1, $this->oProvider->capTriggerCalls);
    }

    public function testPostRedirectsWithStartedMessageOnSuccess(): void
    {
        $this->oProvider->stubTriggerResult = true;
        $oRequest  = Request::create('/service/teletext/teefax-refresh', 'POST');
        $oResponse = $this->oController->teefaxRefresh($this->oSmarty, $oRequest);

        $this->assertInstanceOf(RedirectResponse::class, $oResponse);
        $this->assertStringContainsString('msg=started', $oResponse->getTargetUrl());
    }

    public function testPostRedirectsWithNotStartedMessageOnFailure(): void
    {
        $this->oProvider->stubTriggerResult = false;
        $oRequest  = Request::create('/service/teletext/teefax-refresh', 'POST');
        $oResponse = $this->oController->teefaxRefresh($this->oSmarty, $oRequest);

        $this->assertInstanceOf(RedirectResponse::class, $oResponse);
        $this->assertStringContainsString('msg=not_started', $oResponse->getTargetUrl());
    }
}
