<?php

namespace HomeLan\FileStore\Admin\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

use HomeLan\FileStore\Admin\Service\Smarty;
use HomeLan\FileStore\Aun\Admin as AunAdmin;
use HomeLan\FileStore\WebSocket\Admin as WebSocketAdmin;
use HomeLan\FileStore\Piconet\Admin as PiconetAdmin;
use HomeLan\FileStore\RemoteBridge\Admin as RemoteBridgeAdmin;

class EncapsulationController extends AbstractController
{
    /** @return array<string,\HomeLan\FileStore\Encapsulation\EncapsulationAdminInterface> */
    private function getAdmins(): array
    {
        return [
            'aun'          => new AunAdmin(),
            'websocket'    => new WebSocketAdmin(),
            'piconet'      => new PiconetAdmin(),
            'remotebridge' => new RemoteBridgeAdmin(),
        ];
    }

    public function index(Smarty $oSmartyService, Request $oRequest): Response
    {
        $oSmarty  = $oSmartyService->getSmarty();
        $sType    = (string) $oRequest->query->get('type', '');
        $aAdmins  = $this->getAdmins();

        if (!array_key_exists($sType, $aAdmins)) {
            $oSmarty->assign('error', "Unknown encapsulation type: " . htmlspecialchars($sType));
            return new Response($oSmarty->fetch('error.tpl'));
        }

        $oSmarty->assign('oAdmin', $aAdmins[$sType]);
        return new Response($oSmarty->fetch('encapsulation.tpl'));
    }
}
