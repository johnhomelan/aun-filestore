<?php

/*
 * Tests for HomeLan\FileStore\Admin\Controller\IndexController.
 *
 * Only the two dependency-free action methods are exercised here:
 *   - kube()    returns 200 with empty body and content-type text/html
 *   - favicon() returns 200 with the contents of the favicon.ico file
 *
 * index() delegates entirely to ServiceDispatcher::create() and Smarty
 * template rendering — both require a full application bootstrap that is
 * out of scope for unit testing.
 */

include_once(__DIR__ . '/../../src/include/system.inc.php');

use PHPUnit\Framework\TestCase;
use HomeLan\FileStore\Admin\Controller\IndexController;
use Symfony\Component\HttpFoundation\Response;

class IndexControllerTest extends TestCase
{
    private IndexController $oController;

    protected function setUp(): void
    {
        $this->oController = new IndexController();
    }

    // =========================================================================
    // kube()
    // =========================================================================

    public function testKubeReturns200(): void
    {
        $oResponse = $this->oController->kube();
        $this->assertSame(Response::HTTP_OK, $oResponse->getStatusCode());
    }

    public function testKubeReturnsEmptyBody(): void
    {
        $oResponse = $this->oController->kube();
        $this->assertSame('', $oResponse->getContent());
    }

    public function testKubeReturnsResponseInstance(): void
    {
        $this->assertInstanceOf(Response::class, $this->oController->kube());
    }

    public function testKubeContentTypeIsTextHtml(): void
    {
        $oResponse = $this->oController->kube();
        $this->assertSame('text/html', $oResponse->headers->get('content-type'));
    }

    // =========================================================================
    // favicon()
    // =========================================================================

    public function testFaviconReturns200(): void
    {
        $oResponse = $this->oController->favicon();
        $this->assertSame(Response::HTTP_OK, $oResponse->getStatusCode());
    }

    public function testFaviconReturnsBinaryContent(): void
    {
        $oResponse = $this->oController->favicon();
        $this->assertNotEmpty($oResponse->getContent());
    }

    public function testFaviconContentMatchesFile(): void
    {
        $sExpected = file_get_contents(
            __DIR__ . '/../../src/include/classes/Admin/static/favicon.ico'
        );
        $oResponse = $this->oController->favicon();
        $this->assertSame($sExpected, $oResponse->getContent());
    }
}
