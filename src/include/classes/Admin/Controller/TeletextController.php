<?php
namespace HomeLan\FileStore\Admin\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use HomeLan\FileStore\Admin\Service\Smarty;
use HomeLan\FileStore\Services\ServiceDispatcher;
use HomeLan\FileStore\Services\Provider\Teletext;

class TeletextController extends AbstractController
{
    public function teefaxRefresh(Smarty $oSmartyService, Request $oRequest): Response
    {
        $oProvider = $this->findProvider();
        if ($oProvider === null) {
            return new RedirectResponse('/?error=' . urlencode('Teletext service not found'));
        }

        if ($oRequest->getMethod() === 'POST') {
            $bStarted = $oProvider->triggerTeefaxImport();
            return new RedirectResponse('/service/teletext/teefax-refresh?msg=' . ($bStarted ? 'started' : 'not_started'));
        }

        return $this->renderTemplate($oSmartyService, 'teletext-teefax-refresh.tpl', [
            'sMessage' => (string) $oRequest->query->get('msg', ''),
        ]);
    }

    public function browse(Smarty $oSmartyService, Request $oRequest): Response
    {
        $oProvider = $this->findProvider();
        if ($oProvider === null) {
            return new RedirectResponse('/?error=' . urlencode('Teletext service not found'));
        }

        $sChannel = (string) $oRequest->query->get('channel', '');
        $sPage = (string) $oRequest->query->get('page', '');
        $mSubpageParam = $oRequest->query->get('subpage');

        $aPages = $sChannel !== '' ? $oProvider->getPages($sChannel) : [];
        $aSubpages = [];
        $iSubpage = 1;
        $sPageDataUrl = null;

        if ($sChannel !== '' && $sPage !== '') {
            $aSubpages = $oProvider->getSubpages($sChannel, $sPage);
            if ($aSubpages !== []) {
                $iSubpage = $mSubpageParam !== null ? (int) $mSubpageParam : $aSubpages[0];
                if (!in_array($iSubpage, $aSubpages, true)) {
                    $iSubpage = $aSubpages[0];
                }
                $sPageDataUrl = '/service/teletext/page-data?channel=' . urlencode($sChannel) . '&page=' . urlencode($sPage) . '&subpage=' . $iSubpage;
            }
        }

        return $this->renderTemplate($oSmartyService, 'teletext-browse.tpl', [
            'aChannels' => $oProvider->getChannelSummaries(),
            'sChannel' => $sChannel,
            'aPages' => $aPages,
            'sPage' => $sPage,
            'aSubpages' => $aSubpages,
            'iSubpage' => $iSubpage,
            'sPageDataUrl' => $sPageDataUrl,
        ]);
    }

    public function pageData(Request $oRequest): Response
    {
        $oProvider = $this->findProvider();
        if ($oProvider === null) {
            return new Response('Teletext service not found', 404);
        }

        $sChannel = (string) $oRequest->query->get('channel', '');
        $sPage = (string) $oRequest->query->get('page', '');
        if ($sChannel === '' || $sPage === '') {
            return new Response('Invalid channel/page', 400);
        }
        $iSubpage = (int) $oRequest->query->get('subpage', 1);

        $sData = $oProvider->getPageData($sChannel, $sPage, $iSubpage > 0 ? $iSubpage : 1);
        if ($sData === null) {
            return new Response('Page not found', 404);
        }

        $oResponse = new Response($sData);
        $oResponse->headers->set('Content-Type', 'application/octet-stream');
        return $oResponse;
    }

    public function renderScript(): Response
    {
        $sScript = file_get_contents(__DIR__ . '/../static/teletext-render.js');
        $oResponse = new Response($sScript === false ? '' : $sScript);
        $oResponse->headers->set('Content-Type', 'text/javascript');
        $oResponse->headers->set('Cache-Control', 'public, max-age=3600');
        return $oResponse;
    }

    // -------------------------------------------------------------------------
    // Overridden by TestableTeletextController so unit tests never touch the
    // real ServiceDispatcher singleton or a real Smarty template.
    // -------------------------------------------------------------------------

    protected function findProvider(): ?Teletext
    {
        foreach (ServiceDispatcher::create()->getServices() as $oService) {
            if ($oService instanceof Teletext) {
                return $oService;
            }
        }
        return null;
    }

    /** @param array<string,mixed> $aVars */
    protected function renderTemplate(Smarty $oSmartyService, string $sTemplate, array $aVars): Response
    {
        $oSmarty = $oSmartyService->getSmarty();
        foreach ($aVars as $sKey => $oValue) {
            $oSmarty->assign($sKey, $oValue);
        }
        return new Response($oSmarty->fetch($sTemplate));
    }
}
