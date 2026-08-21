<?php
namespace HomeLan\FileStore\ShareFs\Admin\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;

use HomeLan\FileStore\ShareFs\Admin\Service\Smarty;
use HomeLan\FileStore\ShareFs\ShareAdmin;
use HomeLan\FileStore\ShareFs\FreewayAdmin;
use HomeLan\FileStore\ShareFs\AccessPlusAdmin;
use HomeLan\FileStore\ShareFs\ShareFsDataAdmin;

class IndexController extends AbstractController
{
    /** @return array<string,\HomeLan\FileStore\Encapsulation\EncapsulationAdminInterface> */
    public static function getComponents(): array
    {
        return [
            'shares'      => new ShareAdmin(),
            'freeway'     => new FreewayAdmin(),
            'accessplus'  => new AccessPlusAdmin(),
            'sharefsdata' => new ShareFsDataAdmin(),
        ];
    }

    public function index(Smarty $oSmartyService): Response
    {
        $oSmarty = $oSmartyService->getSmarty();
        $oSmarty->assign('aComponents', self::getComponents());
        return new Response($oSmarty->fetch('index.tpl'));
    }

    public function kube(): Response
    {
        return new Response('', Response::HTTP_OK, ['content-type' => 'text/html']);
    }
}
