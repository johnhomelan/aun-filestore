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
