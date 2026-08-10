<?php

/*
 * @group unit-tests
 *
 * Tests for HomeLan\FileStore\Admin\SessionCookie.
 *
 * Covers:
 *   - getSubscribedEvents(): correct Symfony event mapping
 *   - start(): returns early for sub-requests
 *   - start(): does not redirect when session cookie already present
 *   - start(): sets redirect response with cookie when no session cookie present
 */

include_once(__DIR__ . '/../../src/include/system.inc.php');

use PHPUnit\Framework\TestCase;
use HomeLan\FileStore\Admin\SessionCookie;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

class SessionCookieTest extends TestCase
{
    // -----------------------------------------------------------------------
    // getSubscribedEvents()
    // -----------------------------------------------------------------------

    public function testGetSubscribedEventsReturnsArrayWithRequestEvent(): void
    {
        $aEvents = SessionCookie::getSubscribedEvents();
        $this->assertArrayHasKey(KernelEvents::REQUEST, $aEvents);
    }

    public function testGetSubscribedEventsListensToStartMethod(): void
    {
        $aEvents  = SessionCookie::getSubscribedEvents();
        $aHandler = $aEvents[KernelEvents::REQUEST];
        // handler is either ['start', $priority] or 'start'
        $sMethod  = is_array($aHandler) ? $aHandler[0] : $aHandler;
        $this->assertSame('start', $sMethod);
    }

    public function testGetSubscribedEventsHasNumericPriority(): void
    {
        $aEvents  = SessionCookie::getSubscribedEvents();
        $aHandler = $aEvents[KernelEvents::REQUEST];
        if (is_array($aHandler)) {
            $this->assertIsInt($aHandler[1]);
        } else {
            // single string listener — priority defaults to 0, test passes
            $this->assertTrue(true);
        }
    }

    // -----------------------------------------------------------------------
    // start() — sub-request (isMainRequest() = false)
    // -----------------------------------------------------------------------

    public function testStartReturnsEarlyForSubRequest(): void
    {
        $oEvent = $this->createMock(RequestEvent::class);
        $oEvent->method('isMainRequest')->willReturn(false);
        $oEvent->expects($this->never())->method('getRequest');
        $oEvent->expects($this->never())->method('setResponse');

        $oCookie = new SessionCookie();
        $oCookie->start($oEvent);
    }

    // -----------------------------------------------------------------------
    // start() — main request WITH session cookie present
    // -----------------------------------------------------------------------

    public function testStartDoesNotSetResponseWhenSessionCookiePresent(): void
    {
        $sSessionName = session_name() ?: 'PHPSESSID';
        $oRequest     = Request::create('/test', 'GET', [], [$sSessionName => 'abc123']);

        $oEvent = $this->createMock(RequestEvent::class);
        $oEvent->method('isMainRequest')->willReturn(true);
        $oEvent->method('getRequest')->willReturn($oRequest);
        $oEvent->expects($this->never())->method('setResponse');

        $oCookie = new SessionCookie();
        $oCookie->start($oEvent);
    }

    // -----------------------------------------------------------------------
    // start() — main request WITHOUT session cookie
    // -----------------------------------------------------------------------

    public function testStartSetsRedirectResponseWhenNoCookiePresent(): void
    {
        $oRequest = Request::create('/test');
        // no cookies set → cookies bag is empty

        $oEvent = $this->createMock(RequestEvent::class);
        $oEvent->method('isMainRequest')->willReturn(true);
        $oEvent->method('getRequest')->willReturn($oRequest);
        $oEvent->expects($this->once())->method('setResponse');

        $oCookie = new SessionCookie();
        $oCookie->start($oEvent);
    }

    public function testStartSetsRedirectToSamePath(): void
    {
        $oRequest = Request::create('/admin/dashboard');

        $oResponse = null;
        $oEvent    = $this->createMock(RequestEvent::class);
        $oEvent->method('isMainRequest')->willReturn(true);
        $oEvent->method('getRequest')->willReturn($oRequest);
        $oEvent->method('setResponse')->willReturnCallback(function ($r) use (&$oResponse) {
            $oResponse = $r;
        });

        $oCookie = new SessionCookie();
        $oCookie->start($oEvent);

        $this->assertInstanceOf(RedirectResponse::class, $oResponse);
        $this->assertStringEndsWith('/admin/dashboard', $oResponse->getTargetUrl());
    }

    public function testStartSetsSessionCookieOnRedirectResponse(): void
    {
        $sSessionName = session_name() ?: 'PHPSESSID';
        $oRequest     = Request::create('/any');

        $oResponse = null;
        $oEvent    = $this->createMock(RequestEvent::class);
        $oEvent->method('isMainRequest')->willReturn(true);
        $oEvent->method('getRequest')->willReturn($oRequest);
        $oEvent->method('setResponse')->willReturnCallback(function ($r) use (&$oResponse) {
            $oResponse = $r;
        });

        $oCookie = new SessionCookie();
        $oCookie->start($oEvent);

        $this->assertInstanceOf(RedirectResponse::class, $oResponse);
        $sCookieHeader = $oResponse->headers->get('Set-Cookie');
        $this->assertStringContainsString($sSessionName, $sCookieHeader);
    }
}
