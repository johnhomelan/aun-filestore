<?php

/*
 * Tests for HomeLan\FileStore\Admin\Controller\EncapsulationController.
 *
 * index() receives an injected Smarty service — we mock that service (and the
 * underlying Smarty engine it returns) so no real template rendering occurs.
 * ServiceDispatcher is NOT used by this controller, so the full suite can run
 * without any application bootstrap.
 */

include_once(__DIR__ . '/../../src/include/system.inc.php');

use PHPUnit\Framework\TestCase;
use HomeLan\FileStore\Admin\Controller\EncapsulationController;
use HomeLan\FileStore\Admin\Service\Smarty as SmartyService;
use HomeLan\FileStore\Aun\Admin as AunAdmin;
use HomeLan\FileStore\WebSocket\Admin as WebSocketAdmin;
use HomeLan\FileStore\Piconet\Admin as PiconetAdmin;
use HomeLan\FileStore\RemoteBridge\Admin as RemoteBridgeAdmin;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

class EncapsulationControllerTest extends TestCase
{
    private EncapsulationController $oController;

    /** @var \Smarty\Smarty&\PHPUnit\Framework\MockObject\MockObject */
    private $oSmartyEngine;

    /** @var SmartyService&\PHPUnit\Framework\MockObject\MockObject */
    private $oSmartyService;

    protected function setUp(): void
    {
        $this->oController = new EncapsulationController();

        $this->oSmartyEngine  = $this->createMock(\Smarty\Smarty::class);
        $this->oSmartyService = $this->createMock(SmartyService::class);
        $this->oSmartyService->method('getSmarty')->willReturn($this->oSmartyEngine);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function makeRequest(string $sType = ''): Request
    {
        return Request::create('/', 'GET', ['type' => $sType]);
    }

    // =========================================================================
    // index() — unknown type
    // =========================================================================

    public function testIndexWithUnknownTypeReturnsResponse(): void
    {
        $this->oSmartyEngine->method('assign');
        $this->oSmartyEngine->method('fetch')->willReturn('<html>error</html>');

        $oResponse = $this->oController->index($this->oSmartyService, $this->makeRequest('bogus'));
        $this->assertInstanceOf(Response::class, $oResponse);
    }

    public function testIndexWithUnknownTypeAssignsErrorMessage(): void
    {
        $this->oSmartyEngine->expects($this->once())
            ->method('assign')
            ->with('error', $this->stringContains('Unknown encapsulation type'));
        $this->oSmartyEngine->method('fetch')->willReturn('');

        $this->oController->index($this->oSmartyService, $this->makeRequest('bogus'));
    }

    public function testIndexWithUnknownTypeFetchesErrorTemplate(): void
    {
        $this->oSmartyEngine->method('assign');
        $this->oSmartyEngine->expects($this->once())
            ->method('fetch')
            ->with('error.tpl')
            ->willReturn('<html>error</html>');

        $this->oController->index($this->oSmartyService, $this->makeRequest('bogus'));
    }

    public function testIndexWithEmptyTypeIsUnknown(): void
    {
        $this->oSmartyEngine->expects($this->once())
            ->method('assign')
            ->with('error', $this->stringContains('Unknown'));
        $this->oSmartyEngine->method('fetch')->willReturn('');

        $this->oController->index($this->oSmartyService, $this->makeRequest(''));
    }

    public function testIndexWithUnknownTypeHtmlEncodesTypeName(): void
    {
        $captured = null;
        $this->oSmartyEngine->method('assign')
            ->willReturnCallback(function ($sKey, $sVal) use (&$captured) {
                if ($sKey === 'error') {
                    $captured = $sVal;
                }
            });
        $this->oSmartyEngine->method('fetch')->willReturn('');

        $this->oController->index($this->oSmartyService, $this->makeRequest('<script>'));

        $this->assertStringNotContainsString('<script>', $captured ?? '');
        $this->assertStringContainsString('&lt;script&gt;', $captured ?? '');
    }

    // =========================================================================
    // index() — known types
    // =========================================================================

    public function testIndexWithAunTypeAssignsAunAdminObject(): void
    {
        $oCaptured = null;
        $this->oSmartyEngine->method('assign')
            ->willReturnCallback(function ($sKey, $oVal) use (&$oCaptured) {
                if ($sKey === 'oAdmin') {
                    $oCaptured = $oVal;
                }
            });
        $this->oSmartyEngine->method('fetch')->willReturn('');

        $this->oController->index($this->oSmartyService, $this->makeRequest('aun'));
        $this->assertInstanceOf(AunAdmin::class, $oCaptured);
    }

    public function testIndexWithWebSocketTypeAssignsWebSocketAdmin(): void
    {
        $oCaptured = null;
        $this->oSmartyEngine->method('assign')
            ->willReturnCallback(function ($sKey, $oVal) use (&$oCaptured) {
                if ($sKey === 'oAdmin') {
                    $oCaptured = $oVal;
                }
            });
        $this->oSmartyEngine->method('fetch')->willReturn('');

        $this->oController->index($this->oSmartyService, $this->makeRequest('websocket'));
        $this->assertInstanceOf(WebSocketAdmin::class, $oCaptured);
    }

    public function testIndexWithPiconetTypeAssignsPiconetAdmin(): void
    {
        $oCaptured = null;
        $this->oSmartyEngine->method('assign')
            ->willReturnCallback(function ($sKey, $oVal) use (&$oCaptured) {
                if ($sKey === 'oAdmin') {
                    $oCaptured = $oVal;
                }
            });
        $this->oSmartyEngine->method('fetch')->willReturn('');

        $this->oController->index($this->oSmartyService, $this->makeRequest('piconet'));
        $this->assertInstanceOf(PiconetAdmin::class, $oCaptured);
    }

    public function testIndexWithRemoteBridgeTypeAssignsRemoteBridgeAdmin(): void
    {
        $oCaptured = null;
        $this->oSmartyEngine->method('assign')
            ->willReturnCallback(function ($sKey, $oVal) use (&$oCaptured) {
                if ($sKey === 'oAdmin') {
                    $oCaptured = $oVal;
                }
            });
        $this->oSmartyEngine->method('fetch')->willReturn('');

        $this->oController->index($this->oSmartyService, $this->makeRequest('remotebridge'));
        $this->assertInstanceOf(RemoteBridgeAdmin::class, $oCaptured);
    }

    public function testIndexWithKnownTypeFetchesEncapsulationTemplate(): void
    {
        $this->oSmartyEngine->method('assign');
        $this->oSmartyEngine->expects($this->once())
            ->method('fetch')
            ->with('encapsulation.tpl')
            ->willReturn('<html>ok</html>');

        $this->oController->index($this->oSmartyService, $this->makeRequest('aun'));
    }

    public function testIndexWithKnownTypeReturnsResponseWithTemplateOutput(): void
    {
        $this->oSmartyEngine->method('assign');
        $this->oSmartyEngine->method('fetch')->willReturn('<html>rendered</html>');

        $oResponse = $this->oController->index($this->oSmartyService, $this->makeRequest('aun'));
        $this->assertSame('<html>rendered</html>', $oResponse->getContent());
    }

    public function testIndexAllFourKnownTypesAreRecognised(): void
    {
        foreach (['aun', 'websocket', 'piconet', 'remotebridge'] as $sType) {
            $this->oSmartyEngine->method('assign');
            $this->oSmartyEngine->method('fetch')->willReturn('');

            // If the type were unknown the engine would receive 'error' as the key;
            // verify it receives 'oAdmin' (meaning the type was matched).
            $bGotAdmin = false;
            $oEngine = $this->createMock(\Smarty\Smarty::class);
            $oEngine->method('assign')
                ->willReturnCallback(function ($sKey) use (&$bGotAdmin) {
                    if ($sKey === 'oAdmin') {
                        $bGotAdmin = true;
                    }
                });
            $oEngine->method('fetch')->willReturn('');

            $oService = $this->createMock(SmartyService::class);
            $oService->method('getSmarty')->willReturn($oEngine);

            $this->oController->index($oService, $this->makeRequest($sType));
            $this->assertTrue($bGotAdmin, "Type '{$sType}' was not recognised");
        }
    }
}
