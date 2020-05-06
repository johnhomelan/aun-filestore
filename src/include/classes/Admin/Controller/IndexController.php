<?php
namespace HomeLan\FileStore\Admin\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Request;

use HomeLan\FileStore\Admin\Service\Smarty;
use HomeLan\FileStore\Services\ServiceDispatcher;

class IndexController extends AbstractController 
{
	public function index(Smarty $oSmartyService): \Symfony\Component\HttpFoundation\Response
	{
		$oServices = ServiceDispatcher::create();
		$oSmarty = $oSmartyService->getSmarty();
		$oSmarty->assign('aServices',$oServices->getServices());
		return new Response($oSmarty->fetch('index.tpl'));
	}
}
