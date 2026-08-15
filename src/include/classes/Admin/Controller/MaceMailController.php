<?php
namespace HomeLan\FileStore\Admin\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use HomeLan\FileStore\Admin\Service\Smarty;
use HomeLan\FileStore\Services\ServiceDispatcher;
use HomeLan\FileStore\Services\Provider\MaceMail;

class MaceMailController extends AbstractController
{
    public function assignSlot(Smarty $oSmartyService, Request $oRequest): Response
    {
        $oProvider = $this->findProvider();
        if ($oProvider === null) {
            return new RedirectResponse('/?error=' . urlencode('MaceMail service not found'));
        }

        $sError = null;

        if ($oRequest->getMethod() === 'POST') {
            try {
                $iSlot     = (int) $oRequest->request->get('slot', -1);
                $sUsername = strtoupper(trim((string) $oRequest->request->get('username', '')));
                $oProvider->adminAssignSlot($iSlot, $sUsername);
                return new RedirectResponse('/service/macemail/slots/assign?msg=assigned');
            } catch (\Exception $e) {
                $sError = $e->getMessage();
            }
        }

        return $this->renderTemplate($oSmartyService, 'macemail-slots-assign.tpl', [
            'aSlots'   => $oProvider->getRegisteredSlots(),
            'sError'   => $sError,
            'sMessage' => (string) $oRequest->query->get('msg', ''),
        ]);
    }

    public function unassignSlot(Smarty $oSmartyService, Request $oRequest): Response
    {
        $oProvider = $this->findProvider();
        if ($oProvider === null) {
            return new RedirectResponse('/?error=' . urlencode('MaceMail service not found'));
        }

        $sError = null;

        if ($oRequest->getMethod() === 'POST') {
            try {
                $iSlot = (int) $oRequest->request->get('slot', -1);
                $oProvider->adminUnassignSlot($iSlot);
                return new RedirectResponse('/service/macemail/slots/unassign?msg=unassigned');
            } catch (\Exception $e) {
                $sError = $e->getMessage();
            }
        }

        return $this->renderTemplate($oSmartyService, 'macemail-slots-unassign.tpl', [
            'aSlots'   => $oProvider->getRegisteredSlots(),
            'sError'   => $sError,
            'sMessage' => (string) $oRequest->query->get('msg', ''),
        ]);
    }

    public function forceLogoff(Smarty $oSmartyService, Request $oRequest): Response
    {
        $oProvider = $this->findProvider();
        if ($oProvider === null) {
            return new RedirectResponse('/?error=' . urlencode('MaceMail service not found'));
        }

        $sError = null;

        if ($oRequest->getMethod() === 'POST') {
            try {
                $sUsername = strtoupper(trim((string) $oRequest->request->get('username', '')));
                $oProvider->adminForceLogoff($sUsername);
                return new RedirectResponse('/service/macemail/logoff?msg=loggedoff');
            } catch (\Exception $e) {
                $sError = $e->getMessage();
            }
        }

        return $this->renderTemplate($oSmartyService, 'macemail-logoff.tpl', [
            'aOnline'  => $oProvider->getOnlineMailUsers(),
            'sError'   => $sError,
            'sMessage' => (string) $oRequest->query->get('msg', ''),
        ]);
    }

    public function broadcast(Smarty $oSmartyService, Request $oRequest): Response
    {
        $oProvider = $this->findProvider();
        if ($oProvider === null) {
            return new RedirectResponse('/?error=' . urlencode('MaceMail service not found'));
        }

        $sError = null;

        if ($oRequest->getMethod() === 'POST') {
            try {
                $iType = (int) $oRequest->request->get('type', 0);
                $oProvider->adminBroadcastMessage($iType);
                return new RedirectResponse('/service/macemail/broadcast?msg=sent');
            } catch (\Exception $e) {
                $sError = $e->getMessage();
            }
        }

        return $this->renderTemplate($oSmartyService, 'macemail-broadcast.tpl', [
            'aMessageTypes' => MaceMail::SYSTEM_MESSAGES,
            'sError'        => $sError,
            'sMessage'      => (string) $oRequest->query->get('msg', ''),
        ]);
    }

    // -------------------------------------------------------------------------
    // Overridden by TestableMaceMailController so unit tests never touch the
    // real ServiceDispatcher singleton or a real Smarty template.
    // -------------------------------------------------------------------------

    protected function findProvider(): ?MaceMail
    {
        foreach (ServiceDispatcher::create()->getServices() as $oService) {
            if ($oService instanceof MaceMail) {
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
