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
    public bool $stubWeatherTriggerResult = true;
    public int  $capWeatherTriggerCalls   = 0;
    public bool $stubTvGuideTriggerResult = true;
    public int  $capTvGuideTriggerCalls   = 0;
    public bool $stubWebfaxTriggerResult = true;
    /** @var array<int, string> service keys passed to triggerWebfaxImport(), in call order */
    public array $capWebfaxTriggerCalls = [];

    /** @var array<int, array{channel:string,page_count:int}> */
    public array $stubChannelSummaries = [];
    /** @var array<int,string> */
    public array $stubPages = [];
    /** @var array<int,int> */
    public array $stubSubpages = [];
    public ?string $stubPageData = null;

    /** @var array<int,string> */
    public array $capGetPagesCalls = [];
    /** @var array<int,array{0:string,1:string}> */
    public array $capGetSubpagesCalls = [];
    /** @var array<int,array{0:string,1:string,2:int}> */
    public array $capGetPageDataCalls = [];

    public function triggerTeefaxImport(): bool
    {
        $this->capTriggerCalls++;
        return $this->stubTriggerResult;
    }

    public function triggerWeatherImport(): bool
    {
        $this->capWeatherTriggerCalls++;
        return $this->stubWeatherTriggerResult;
    }

    public function triggerTvGuideImport(): bool
    {
        $this->capTvGuideTriggerCalls++;
        return $this->stubTvGuideTriggerResult;
    }

    public function triggerWebfaxImport(string $sServiceKey): bool
    {
        $this->capWebfaxTriggerCalls[] = $sServiceKey;
        return $this->stubWebfaxTriggerResult;
    }

    public function getChannelSummaries(): array
    {
        return $this->stubChannelSummaries;
    }

    public function getPages(string $sChannel): array
    {
        $this->capGetPagesCalls[] = $sChannel;
        return $this->stubPages;
    }

    public function getSubpages(string $sChannel, string $sPage): array
    {
        $this->capGetSubpagesCalls[] = [$sChannel, $sPage];
        return $this->stubSubpages;
    }

    public function getPageData(string $sChannel, string $sPage, int $iSubpage = 1): ?string
    {
        $this->capGetPageDataCalls[] = [$sChannel, $sPage, $iSubpage];
        return $this->stubPageData;
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

    // -------------------------------------------------------------------------
    // weatherRefresh() — mirrors teefaxRefresh() above (single source, same
    // shape).
    // -------------------------------------------------------------------------

    public function testWeatherRefreshRedirectsWhenProviderNotFound(): void
    {
        $this->oController->stubProvider = null;
        $oRequest  = Request::create('/service/teletext/weather-refresh', 'GET');
        $oResponse = $this->oController->weatherRefresh($this->oSmarty, $oRequest);

        $this->assertInstanceOf(RedirectResponse::class, $oResponse);
    }

    public function testWeatherRefreshGetRendersConfirmationTemplate(): void
    {
        $oRequest = Request::create('/service/teletext/weather-refresh', 'GET');
        $this->oController->weatherRefresh($this->oSmarty, $oRequest);

        $this->assertSame('teletext-weather-refresh.tpl', $this->oController->lastTemplate);
        $this->assertSame(0, $this->oProvider->capWeatherTriggerCalls);
    }

    public function testWeatherRefreshGetPassesQueryMessageToTemplate(): void
    {
        $oRequest = Request::create('/service/teletext/weather-refresh?msg=started', 'GET');
        $this->oController->weatherRefresh($this->oSmarty, $oRequest);

        $this->assertSame('started', $this->oController->lastVars['sMessage']);
    }

    public function testWeatherRefreshPostCallsTriggerWeatherImport(): void
    {
        $oRequest = Request::create('/service/teletext/weather-refresh', 'POST');
        $this->oController->weatherRefresh($this->oSmarty, $oRequest);

        $this->assertSame(1, $this->oProvider->capWeatherTriggerCalls);
    }

    public function testWeatherRefreshPostRedirectsWithStartedMessageOnSuccess(): void
    {
        $this->oProvider->stubWeatherTriggerResult = true;
        $oRequest  = Request::create('/service/teletext/weather-refresh', 'POST');
        $oResponse = $this->oController->weatherRefresh($this->oSmarty, $oRequest);

        $this->assertInstanceOf(RedirectResponse::class, $oResponse);
        $this->assertStringContainsString('msg=started', $oResponse->getTargetUrl());
    }

    public function testWeatherRefreshPostRedirectsWithNotStartedMessageOnFailure(): void
    {
        $this->oProvider->stubWeatherTriggerResult = false;
        $oRequest  = Request::create('/service/teletext/weather-refresh', 'POST');
        $oResponse = $this->oController->weatherRefresh($this->oSmarty, $oRequest);

        $this->assertInstanceOf(RedirectResponse::class, $oResponse);
        $this->assertStringContainsString('msg=not_started', $oResponse->getTargetUrl());
    }

    // -------------------------------------------------------------------------
    // tvGuideRefresh() — mirrors weatherRefresh() above (single source, same
    // shape).
    // -------------------------------------------------------------------------

    public function testTvGuideRefreshRedirectsWhenProviderNotFound(): void
    {
        $this->oController->stubProvider = null;
        $oRequest  = Request::create('/service/teletext/tvguide-refresh', 'GET');
        $oResponse = $this->oController->tvGuideRefresh($this->oSmarty, $oRequest);

        $this->assertInstanceOf(RedirectResponse::class, $oResponse);
    }

    public function testTvGuideRefreshGetRendersConfirmationTemplate(): void
    {
        $oRequest = Request::create('/service/teletext/tvguide-refresh', 'GET');
        $this->oController->tvGuideRefresh($this->oSmarty, $oRequest);

        $this->assertSame('teletext-tvguide-refresh.tpl', $this->oController->lastTemplate);
        $this->assertSame(0, $this->oProvider->capTvGuideTriggerCalls);
    }

    public function testTvGuideRefreshGetPassesQueryMessageToTemplate(): void
    {
        $oRequest = Request::create('/service/teletext/tvguide-refresh?msg=started', 'GET');
        $this->oController->tvGuideRefresh($this->oSmarty, $oRequest);

        $this->assertSame('started', $this->oController->lastVars['sMessage']);
    }

    public function testTvGuideRefreshPostCallsTriggerTvGuideImport(): void
    {
        $oRequest = Request::create('/service/teletext/tvguide-refresh', 'POST');
        $this->oController->tvGuideRefresh($this->oSmarty, $oRequest);

        $this->assertSame(1, $this->oProvider->capTvGuideTriggerCalls);
    }

    public function testTvGuideRefreshPostRedirectsWithStartedMessageOnSuccess(): void
    {
        $this->oProvider->stubTvGuideTriggerResult = true;
        $oRequest  = Request::create('/service/teletext/tvguide-refresh', 'POST');
        $oResponse = $this->oController->tvGuideRefresh($this->oSmarty, $oRequest);

        $this->assertInstanceOf(RedirectResponse::class, $oResponse);
        $this->assertStringContainsString('msg=started', $oResponse->getTargetUrl());
    }

    public function testTvGuideRefreshPostRedirectsWithNotStartedMessageOnFailure(): void
    {
        $this->oProvider->stubTvGuideTriggerResult = false;
        $oRequest  = Request::create('/service/teletext/tvguide-refresh', 'POST');
        $oResponse = $this->oController->tvGuideRefresh($this->oSmarty, $oRequest);

        $this->assertInstanceOf(RedirectResponse::class, $oResponse);
        $this->assertStringContainsString('msg=not_started', $oResponse->getTargetUrl());
    }

    // -------------------------------------------------------------------------
    // webfaxRefresh() — mirrors weatherRefresh() above, plus an unknown
    // --service branch since (unlike Teefax/Weather) there are two
    // independent Webfax services to select between.
    // -------------------------------------------------------------------------

    public function testWebfaxRefreshRedirectsWhenProviderNotFound(): void
    {
        $this->oController->stubProvider = null;
        $oRequest  = Request::create('/service/teletext/webfax-refresh?service=webfax1', 'GET');
        $oResponse = $this->oController->webfaxRefresh($this->oSmarty, $oRequest);

        $this->assertInstanceOf(RedirectResponse::class, $oResponse);
    }

    public function testWebfaxRefreshRedirectsWhenServiceIsUnknown(): void
    {
        $oRequest  = Request::create('/service/teletext/webfax-refresh?service=nonsense', 'GET');
        $oResponse = $this->oController->webfaxRefresh($this->oSmarty, $oRequest);

        $this->assertInstanceOf(RedirectResponse::class, $oResponse);
        $this->assertSame(0, count($this->oProvider->capWebfaxTriggerCalls));
    }

    public function testWebfaxRefreshGetRendersConfirmationTemplate(): void
    {
        $oRequest = Request::create('/service/teletext/webfax-refresh?service=webfax1', 'GET');
        $this->oController->webfaxRefresh($this->oSmarty, $oRequest);

        $this->assertSame('teletext-webfax-refresh.tpl', $this->oController->lastTemplate);
        $this->assertSame('webfax1', $this->oController->lastVars['sServiceKey']);
        $this->assertSame('Webfax 1', $this->oController->lastVars['sLabel']);
        $this->assertSame([], $this->oProvider->capWebfaxTriggerCalls);
    }

    public function testWebfaxRefreshGetPassesQueryMessageToTemplate(): void
    {
        $oRequest = Request::create('/service/teletext/webfax-refresh?service=webfax1&msg=started', 'GET');
        $this->oController->webfaxRefresh($this->oSmarty, $oRequest);

        $this->assertSame('started', $this->oController->lastVars['sMessage']);
    }

    public function testWebfaxRefreshPostCallsTriggerWebfaxImportWithTheSelectedService(): void
    {
        $oRequest = Request::create('/service/teletext/webfax-refresh?service=webfax2', 'POST');
        $this->oController->webfaxRefresh($this->oSmarty, $oRequest);

        $this->assertSame(['webfax2'], $this->oProvider->capWebfaxTriggerCalls);
    }

    public function testWebfaxRefreshPostRedirectsWithStartedMessageOnSuccess(): void
    {
        $this->oProvider->stubWebfaxTriggerResult = true;
        $oRequest  = Request::create('/service/teletext/webfax-refresh?service=webfax1', 'POST');
        $oResponse = $this->oController->webfaxRefresh($this->oSmarty, $oRequest);

        $this->assertInstanceOf(RedirectResponse::class, $oResponse);
        $this->assertStringContainsString('msg=started', $oResponse->getTargetUrl());
    }

    public function testWebfaxRefreshPostRedirectsWithNotStartedMessageOnFailure(): void
    {
        $this->oProvider->stubWebfaxTriggerResult = false;
        $oRequest  = Request::create('/service/teletext/webfax-refresh?service=webfax1', 'POST');
        $oResponse = $this->oController->webfaxRefresh($this->oSmarty, $oRequest);

        $this->assertInstanceOf(RedirectResponse::class, $oResponse);
        $this->assertStringContainsString('msg=not_started', $oResponse->getTargetUrl());
    }

    // -------------------------------------------------------------------------
    // browse()
    // -------------------------------------------------------------------------

    public function testBrowseRedirectsWhenProviderNotFound(): void
    {
        $this->oController->stubProvider = null;
        $oRequest  = Request::create('/service/teletext/browse', 'GET');
        $oResponse = $this->oController->browse($this->oSmarty, $oRequest);

        $this->assertInstanceOf(RedirectResponse::class, $oResponse);
    }

    public function testBrowseWithNoChannelListsChannelsOnly(): void
    {
        $this->oProvider->stubChannelSummaries = [['channel' => '1', 'page_count' => 2]];
        $oRequest = Request::create('/service/teletext/browse', 'GET');
        $this->oController->browse($this->oSmarty, $oRequest);

        $this->assertSame('teletext-browse.tpl', $this->oController->lastTemplate);
        $this->assertSame([['channel' => '1', 'page_count' => 2]], $this->oController->lastVars['aChannels']);
        $this->assertSame([], $this->oController->lastVars['aPages']);
        $this->assertSame([], $this->oProvider->capGetPagesCalls);
    }

    public function testBrowseWithChannelListsPages(): void
    {
        $this->oProvider->stubPages = ['100', '101'];
        $oRequest = Request::create('/service/teletext/browse?channel=1', 'GET');
        $this->oController->browse($this->oSmarty, $oRequest);

        $this->assertSame(['1'], $this->oProvider->capGetPagesCalls);
        $this->assertSame(['100', '101'], $this->oController->lastVars['aPages']);
        $this->assertSame([], $this->oProvider->capGetSubpagesCalls);
    }

    public function testBrowseWithChannelAndPageDefaultsToLowestSubpage(): void
    {
        $this->oProvider->stubSubpages = [2, 3];
        $oRequest = Request::create('/service/teletext/browse?channel=1&page=100', 'GET');
        $this->oController->browse($this->oSmarty, $oRequest);

        $this->assertSame([['1', '100']], $this->oProvider->capGetSubpagesCalls);
        $this->assertSame(2, $this->oController->lastVars['iSubpage']);
        $this->assertSame(
            '/service/teletext/page-data?channel=1&page=100&subpage=2',
            $this->oController->lastVars['sPageDataUrl']
        );
    }

    public function testBrowseWithExplicitValidSubpageUsesIt(): void
    {
        $this->oProvider->stubSubpages = [1, 2, 3];
        $oRequest = Request::create('/service/teletext/browse?channel=1&page=100&subpage=3', 'GET');
        $this->oController->browse($this->oSmarty, $oRequest);

        $this->assertSame(3, $this->oController->lastVars['iSubpage']);
    }

    public function testBrowseWithInvalidSubpageFallsBackToLowest(): void
    {
        $this->oProvider->stubSubpages = [1, 2];
        $oRequest = Request::create('/service/teletext/browse?channel=1&page=100&subpage=99', 'GET');
        $this->oController->browse($this->oSmarty, $oRequest);

        $this->assertSame(1, $this->oController->lastVars['iSubpage']);
    }

    public function testBrowseWithPageThatDoesNotExistLeavesPageDataUrlNull(): void
    {
        $this->oProvider->stubSubpages = [];
        $oRequest = Request::create('/service/teletext/browse?channel=1&page=999', 'GET');
        $this->oController->browse($this->oSmarty, $oRequest);

        $this->assertNull($this->oController->lastVars['sPageDataUrl']);
    }

    // -------------------------------------------------------------------------
    // pageData()
    // -------------------------------------------------------------------------

    public function testPageDataReturns404WhenProviderNotFound(): void
    {
        $this->oController->stubProvider = null;
        $oRequest  = Request::create('/service/teletext/page-data?channel=1&page=100', 'GET');
        $oResponse = $this->oController->pageData($oRequest);

        $this->assertSame(404, $oResponse->getStatusCode());
    }

    public function testPageDataReturns400WhenChannelOrPageMissing(): void
    {
        $oRequest  = Request::create('/service/teletext/page-data?channel=1', 'GET');
        $oResponse = $this->oController->pageData($oRequest);

        $this->assertSame(400, $oResponse->getStatusCode());
    }

    public function testPageDataReturns404WhenPageNotFound(): void
    {
        $this->oProvider->stubPageData = null;
        $oRequest  = Request::create('/service/teletext/page-data?channel=1&page=999', 'GET');
        $oResponse = $this->oController->pageData($oRequest);

        $this->assertSame(404, $oResponse->getStatusCode());
    }

    public function testPageDataReturnsRawBytesWithOctetStreamContentType(): void
    {
        $this->oProvider->stubPageData = str_repeat('X', 1024);
        $oRequest  = Request::create('/service/teletext/page-data?channel=1&page=100&subpage=2', 'GET');
        $oResponse = $this->oController->pageData($oRequest);

        $this->assertSame(200, $oResponse->getStatusCode());
        $this->assertSame(str_repeat('X', 1024), $oResponse->getContent());
        $this->assertSame('application/octet-stream', $oResponse->headers->get('Content-Type'));
        $this->assertSame([['1', '100', 2]], $this->oProvider->capGetPageDataCalls);
    }

    public function testPageDataDefaultsToSubpage1WhenNotSpecified(): void
    {
        $this->oProvider->stubPageData = str_repeat('X', 1024);
        $oRequest = Request::create('/service/teletext/page-data?channel=1&page=100', 'GET');
        $this->oController->pageData($oRequest);

        $this->assertSame([['1', '100', 1]], $this->oProvider->capGetPageDataCalls);
    }

    // -------------------------------------------------------------------------
    // renderScript()
    // -------------------------------------------------------------------------

    public function testRenderScriptReturnsTheRealFileWithJavascriptContentType(): void
    {
        $oResponse = $this->oController->renderScript();

        $sExpected = file_get_contents(__DIR__ . '/../../src/include/classes/Admin/static/teletext-render.js');
        $this->assertSame($sExpected, $oResponse->getContent());
        $this->assertSame('text/javascript', $oResponse->headers->get('Content-Type'));
        $this->assertStringContainsString('max-age=3600', (string) $oResponse->headers->get('Cache-Control'));
    }
}
