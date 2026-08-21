<?php

namespace HomeLan\FileStore\ShareFs\Admin\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

use HomeLan\FileStore\ShareFs\Admin\Service\Smarty;

class ComponentController extends AbstractController
{
    public function index(Smarty $oSmartyService, Request $oRequest): Response
    {
        $oSmarty = $oSmartyService->getSmarty();
        $sType = (string) $oRequest->query->get('type', '');
        $aComponents = IndexController::getComponents();

        if (!array_key_exists($sType, $aComponents)) {
            $oSmarty->assign('error', 'Unknown component: ' . htmlspecialchars($sType));
            return new Response($oSmarty->fetch('error.tpl'));
        }

        $oSmarty->assign('oAdmin', $aComponents[$sType]);
        return new Response($oSmarty->fetch('component.tpl'));
    }
}
